<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class BillingService
{
    public function plans(): array
    {
        return collect(config('plans', []))->map(fn (array $plan, string $key) => ['id' => $key, 'name' => $plan['name'], 'label' => $plan['label'], 'monthly' => $plan['monthly'], 'annual' => $plan['annual'], 'currency' => $plan['currency'], 'upload_seconds' => $plan['upload_seconds'], 'max_pending' => $plan['max_pending'], 'features' => $plan['features']])->values()->all();
    }

    public function checkout(User $user, string $plan, string $cycle = 'monthly'): array
    {
        $this->assertPlan($plan, $cycle);
        if ($user->subscription?->active() && $user->subscription?->provider_subscription_id) abort(409, 'У вас уже есть активная подписка. Для изменения откройте управление оплатой, чтобы избежать повторного списания.');
        $price = config("plans.{$plan}.price_id_{$cycle}");
        if (! config('services.stripe.secret') || ! $price) throw new ServiceUnavailableHttpException(null, 'Платёжный провайдер ещё не настроен.');

        $response = Http::asForm()->withBasicAuth(config('services.stripe.secret'), '')->post('https://api.stripe.com/v1/checkout/sessions', [
            'mode' => 'subscription', 'line_items[0][price]' => $price, 'line_items[0][quantity]' => 1,
            'success_url' => $this->frontendUrl().'/?billing=success&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $this->frontendUrl().'/?billing=cancelled', 'customer_email' => $user->email,
            'allow_promotion_codes' => 'true', 'billing_address_collection' => 'auto',
            'metadata[user_id]' => (string) $user->id, 'metadata[plan]' => $plan,
            'subscription_data[metadata][user_id]' => (string) $user->id, 'subscription_data[metadata][plan]' => $plan,
        ])->throw()->json();

        return ['checkout_url' => $response['url'], 'session_id' => $response['id'], 'plan' => $plan, 'cycle' => $cycle];
    }

    public function portal(User $user): array
    {
        $customer = $user->subscription?->provider_customer_id;
        if (! $customer || ! config('services.stripe.secret')) throw new ServiceUnavailableHttpException(null, 'Платёжный профиль ещё не создан.');
        $response = Http::asForm()->withBasicAuth(config('services.stripe.secret'), '')->post('https://api.stripe.com/v1/billing_portal/sessions', ['customer' => $customer, 'return_url' => $this->frontendUrl().'/#billing'])->throw()->json();
        return ['url' => $response['url']];
    }

    public function cancel(User $user): ?Subscription
    {
        $subscription = $user->subscription;
        if (! $subscription?->provider_subscription_id || ! $subscription->active()) return $subscription;
        if (! config('services.stripe.secret')) throw new ServiceUnavailableHttpException(null, 'Stripe не настроен.');
        // Do not change local access until Stripe confirms immediate cancellation.
        $result = Http::timeout(15)->withBasicAuth(config('services.stripe.secret'), '')->delete('https://api.stripe.com/v1/subscriptions/'.rawurlencode($subscription->provider_subscription_id))->throw()->json();
        abort_unless(($result['id'] ?? null) === $subscription->provider_subscription_id && ($result['status'] ?? null) === 'canceled', 502, 'Stripe не подтвердил отмену. Проверьте подписку в разделе оплаты.');
        $cancelled = DB::transaction(function () use ($user, $subscription) {
            $owner = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $current = $owner->subscription()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->provider_subscription_id === $subscription->provider_subscription_id, 409, 'Подписка изменилась. Обновите страницу.');
            $current->update(['status' => 'canceled', 'cancel_at_period_end' => false]);
            $this->restoreFreeFeatures($owner);
            return $current->refresh();
        });
        $user->unsetRelation('subscription');
        return $cancelled;
    }

    public function resume(User $user): ?Subscription
    {
        $subscription = $user->subscription;
        if (! $subscription?->provider_subscription_id || ! $subscription->active()) abort(409, 'Отменённую подписку нельзя возобновить. Выберите тариф заново.');
        if (! config('services.stripe.secret')) throw new ServiceUnavailableHttpException(null, 'Stripe не настроен.');
        Http::asForm()->withBasicAuth(config('services.stripe.secret'), '')->post('https://api.stripe.com/v1/subscriptions/'.$subscription->provider_subscription_id, ['cancel_at_period_end' => 'false'])->throw();
        $subscription->update(['cancel_at_period_end' => false]);
        return $subscription->refresh();
    }

    private function restoreFreeFeatures(User $user): void
    {
        // Never delete the user's music, likes or comments on downgrade.
        $user->tracks()->update(['is_boosted' => false, 'comments_enabled' => true]);
        $keep = $user->tracks()->published()->where('is_spotlight', true)->orderByDesc('created_at')->orderByDesc('id')->value('id');
        $user->tracks()->where('is_spotlight', true)->when($keep, fn ($q) => $q->where('id', '!=', $keep))->update(['is_spotlight' => false]);
    }

    public function webhook(string $payload, string $signature): void
    {
        $this->verifySignature($payload, $signature);
        $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (Cache::has('stripe:event:'.$event['id'])) return;
        $object = $event['data']['object'] ?? [];
        match ($event['type']) {
            'checkout.session.completed' => $this->syncCheckout($object),
            'customer.subscription.updated', 'customer.subscription.deleted' => $this->syncSubscription($object),
            default => null,
        };
        Cache::put('stripe:event:'.$event['id'], true, now()->addDay());
    }

    /** Verify with Stripe, never trust a browser redirect as proof of payment. */
    public function syncForUser(User $user, ?string $sessionId = null): void
    {
        $secret = config('services.stripe.secret');
        if (! $secret) throw new ServiceUnavailableHttpException(null, 'Stripe не настроен.');
        if ($sessionId) {
            $session = Http::timeout(15)->withBasicAuth($secret, '')->get('https://api.stripe.com/v1/checkout/sessions/'.rawurlencode($sessionId))->throw()->json();
            $this->syncCheckout($session, $user);
            return;
        }
        // First reconcile the known subscription, including cancellation when its webhook was missed.
        $local = $user->subscription()->first(); $checked = false;
        if ($local?->provider_subscription_id) {
            $current = $this->fetchSubscription($local->provider_subscription_id);
            $this->applySubscription($current, $user); $checked = true;
            if (in_array($current['status'] ?? '', ['active', 'trialing'], true)) return;
        }
        // Recovery for earlier successful payments when the webhook was not delivered.
        // Read at most 300 recent sessions, and use only records belonging to this user.
        $startingAfter = null;
        for ($page = 0; $page < 3; $page++) {
            $query = ['limit' => 100]; if ($startingAfter) $query['starting_after'] = $startingAfter;
            $result = Http::timeout(15)->withBasicAuth($secret, '')->get('https://api.stripe.com/v1/checkout/sessions', $query)->throw()->json();
            foreach ($result['data'] ?? [] as $session) {
                if ((string) ($session['metadata']['user_id'] ?? '') !== (string) $user->id || ($session['mode'] ?? '') !== 'subscription' || ($session['status'] ?? '') !== 'complete' || ! in_array($session['payment_status'] ?? '', ['paid', 'no_payment_required'], true)) continue;
                $subscription = $this->fetchSubscription($session['subscription'] ?? '');
                if (! in_array($subscription['status'] ?? '', ['active', 'trialing'], true)) continue;
                $this->applySubscription($subscription, $user, $session);
                return;
            }
            if (empty($result['has_more']) || empty($result['data'])) break;
            $startingAfter = end($result['data'])['id'];
        }
        if ($checked) return;
        abort(404, 'Активная оплата не найдена среди последних сессий Stripe. Проверьте тестовый аккаунт Stripe и webhook.');
    }

    private function fetchSubscription(mixed $id): array
    {
        if (! is_string($id) || ! preg_match('/^sub_[a-zA-Z0-9]+$/', $id)) abort(422, 'Stripe не вернул действительную подписку.');
        $secret = config('services.stripe.secret');
        if (! $secret) throw new ServiceUnavailableHttpException(null, 'Stripe не настроен.');
        return Http::timeout(15)->withBasicAuth($secret, '')->get('https://api.stripe.com/v1/subscriptions/'.rawurlencode($id))->throw()->json();
    }

    private function applySubscription(array $subscription, User $user, ?array $session = null): void
    {
        $id = $subscription['id'] ?? '';
        $customer = $subscription['customer'] ?? '';
        abort_unless(is_string($id) && preg_match('/^sub_[a-zA-Z0-9]+$/', $id), 422, 'Некорректная подписка Stripe.');
        abort_unless((string) ($subscription['metadata']['user_id'] ?? '') === (string) $user->id, 403, 'Подписка принадлежит другому пользователю.');
        if ($session !== null) {
            abort_unless((string) ($session['metadata']['user_id'] ?? '') === (string) $user->id && ($session['subscription'] ?? null) === $id && ($session['customer'] ?? null) === $customer, 403, 'Сессия оплаты не принадлежит этому аккаунту.');
        }
        $price = $subscription['items']['data'][0]['price']['id'] ?? null; $plan = null;
        foreach (['artist', 'artist_pro'] as $candidate) foreach (['monthly', 'annual'] as $cycle) {
            $configured = config("plans.{$candidate}.price_id_{$cycle}");
            if ($configured && $price === $configured) $plan = $candidate;
        }
        abort_unless($plan !== null, 422, 'Цена подписки не совпадает с настроенными Price ID. Проверьте .env.');
        $item = $subscription['items']['data'][0] ?? [];
        $start = $subscription['current_period_start'] ?? ($item['current_period_start'] ?? null);
        $end = $subscription['current_period_end'] ?? ($item['current_period_end'] ?? null);
        DB::transaction(function () use ($user, $id, $customer, $subscription, $plan, $start, $end) {
            $owner = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = $owner->subscription()->lockForUpdate()->first();
            if ($existing && $existing->active() && $existing->provider_subscription_id && $existing->provider_subscription_id !== $id) abort(409, 'Найдены две разные подписки. Проверьте их в Stripe и не оформляйте оплату повторно.');
            $saved = $owner->subscription()->updateOrCreate([], ['provider' => 'stripe', 'provider_customer_id' => $customer, 'provider_subscription_id' => $id, 'plan' => $plan, 'status' => $subscription['status'] ?? 'incomplete', 'current_period_start' => $start ? now()->setTimestamp($start) : null, 'current_period_end' => $end ? now()->setTimestamp($end) : null, 'cancel_at_period_end' => (bool) ($subscription['cancel_at_period_end'] ?? false)]);
            if (! $saved->active()) $this->restoreFreeFeatures($owner);
        });
        $user->unsetRelation('subscription');
    }

    private function syncCheckout(array $session, ?User $expectedUser = null): void
    {
        $user = $expectedUser ?: User::find($session['metadata']['user_id'] ?? null);
        abort_unless($user && (string) ($session['metadata']['user_id'] ?? '') === (string) $user->id, 403, 'Сессия оплаты не принадлежит этому аккаунту.');
        abort_unless(($session['mode'] ?? '') === 'subscription' && ($session['status'] ?? '') === 'complete' && in_array($session['payment_status'] ?? '', ['paid', 'no_payment_required'], true), 409, 'Stripe ещё не подтвердил оплату.');
        $subscription = $this->fetchSubscription($session['subscription'] ?? '');
        abort_unless(in_array($subscription['status'] ?? '', ['active', 'trialing'], true), 409, 'Подписка ещё не активна в Stripe.');
        $this->applySubscription($subscription, $user, $session);
    }

    private function syncSubscription(array $eventSubscription): void
    {
        // Retrieve current state to avoid stale or out-of-order events restoring access.
        $subscription = $this->fetchSubscription($eventSubscription['id'] ?? '');
        $local = Subscription::where('provider_subscription_id', $subscription['id'] ?? null)->first();
        $user = $local?->user ?: User::find($subscription['metadata']['user_id'] ?? null);
        if (! $user) return;
        $this->applySubscription($subscription, $user);
    }
    private function assertPlan(string $plan, string $cycle): void { $plans = config('plans', []); abort_unless(array_key_exists($plan, $plans) && $plan !== 'free' && in_array($cycle, ['monthly', 'annual'], true), 422, 'Недопустимый тариф.'); }
    private function verifySignature(string $payload, string $signature): void { $secret = config('services.stripe.webhook_secret'); abort_unless($secret && $signature, 400, 'Webhook signature отсутствует.'); $timestamp = null; $signatures = []; foreach (explode(',', $signature) as $part) { [$key, $value] = array_pad(explode('=', $part, 2), 2, null); if ($key === 't') $timestamp = $value; if ($key === 'v1') $signatures[] = $value; } abort_unless($timestamp && abs(time() - (int) $timestamp) <= 300, 400, 'Webhook signature устарела.'); $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret); abort_unless(collect($signatures)->contains(fn ($value) => hash_equals($expected, $value)), 400, 'Webhook signature неверна.'); }
    private function frontendUrl(): string { return rtrim((string) env('CLIENT_URL', config('app.url')), '/'); }
}
