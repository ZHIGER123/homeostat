<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
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
        if (! $subscription?->provider_subscription_id) return $subscription;
        Http::asForm()->withBasicAuth(config('services.stripe.secret'), '')->post('https://api.stripe.com/v1/subscriptions/'.$subscription->provider_subscription_id, ['cancel_at_period_end' => 'true'])->throw();
        $subscription->update(['cancel_at_period_end' => true]);
        return $subscription->refresh();
    }

    public function resume(User $user): ?Subscription
    {
        $subscription = $user->subscription;
        if (! $subscription?->provider_subscription_id) return $subscription;
        Http::asForm()->withBasicAuth(config('services.stripe.secret'), '')->post('https://api.stripe.com/v1/subscriptions/'.$subscription->provider_subscription_id, ['cancel_at_period_end' => 'false'])->throw();
        $subscription->update(['cancel_at_period_end' => false]);
        return $subscription->refresh();
    }

    public function webhook(string $payload, string $signature): void
    {
        $this->verifySignature($payload, $signature);
        $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (! Cache::add('stripe:event:'.$event['id'], true, now()->addDay())) return;
        $object = $event['data']['object'] ?? [];
        match ($event['type']) {
            'checkout.session.completed' => $this->syncCheckout($object),
            'customer.subscription.updated', 'customer.subscription.deleted' => $this->syncSubscription($object),
            default => null,
        };
    }

    private function syncCheckout(array $session): void
    {
        $user = User::find($session['metadata']['user_id'] ?? null);
        if (! $user || empty($session['subscription'])) return;
        $this->upsert($user, ['provider_customer_id' => $session['customer'] ?? null, 'provider_subscription_id' => $session['subscription'], 'plan' => $session['metadata']['plan'] ?? 'artist', 'status' => 'active']);
    }

    private function syncSubscription(array $subscription): void
    {
        $local = Subscription::where('provider_subscription_id', $subscription['id'] ?? null)->first();
        $user = $local?->user ?: User::find($subscription['metadata']['user_id'] ?? null);
        if (! $user) return;
        $this->upsert($user, ['provider_customer_id' => $subscription['customer'] ?? null, 'provider_subscription_id' => $subscription['id'] ?? null, 'plan' => $subscription['metadata']['plan'] ?? ($local->plan ?? 'artist'), 'status' => $subscription['status'] ?? 'active', 'current_period_start' => isset($subscription['current_period_start']) ? now()->setTimestamp($subscription['current_period_start']) : null, 'current_period_end' => isset($subscription['current_period_end']) ? now()->setTimestamp($subscription['current_period_end']) : null, 'cancel_at_period_end' => (bool) ($subscription['cancel_at_period_end'] ?? false)]);
    }

    private function upsert(User $user, array $data): Subscription { return $user->subscription()->updateOrCreate([], ['provider' => 'stripe', ...$data]); }
    private function assertPlan(string $plan, string $cycle): void { $plans = config('plans', []); abort_unless(array_key_exists($plan, $plans) && $plan !== 'free' && in_array($cycle, ['monthly', 'annual'], true), 422, 'Недопустимый тариф.'); }
    private function verifySignature(string $payload, string $signature): void { $secret = config('services.stripe.webhook_secret'); abort_unless($secret && $signature, 400, 'Webhook signature отсутствует.'); $timestamp = null; $signatures = []; foreach (explode(',', $signature) as $part) { [$key, $value] = array_pad(explode('=', $part, 2), 2, null); if ($key === 't') $timestamp = $value; if ($key === 'v1') $signatures[] = $value; } abort_unless($timestamp && abs(time() - (int) $timestamp) <= 300, 400, 'Webhook signature устарела.'); $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret); abort_unless(collect($signatures)->contains(fn ($value) => hash_equals($expected, $value)), 400, 'Webhook signature неверна.'); }
    private function frontendUrl(): string { return rtrim((string) env('CLIENT_URL', config('app.url')), '/'); }
}
