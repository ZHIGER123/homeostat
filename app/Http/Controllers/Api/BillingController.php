<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BillingService;
use App\Services\UploadQuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function plans(BillingService $billing): JsonResponse { return response()->json(['data' => $billing->plans()]); }
    public function me(Request $request, UploadQuotaService $quota): JsonResponse
    {
        $user = $request->user()->load('subscription'); $subscription = $user->subscription;
        return response()->json(['data' => ['plan' => $user->currentPlan(), 'status' => $subscription?->status ?? 'free', 'current_period_end' => $subscription?->current_period_end, 'cancel_at_period_end' => (bool) ($subscription?->cancel_at_period_end ?? false), 'usage' => $quota->usage($user)]]);
    }
    public function checkout(Request $request, BillingService $billing): JsonResponse { $data = $request->validate(['plan' => ['required', 'string'], 'cycle' => ['sometimes', 'in:monthly,annual']]); return response()->json(['data' => $billing->checkout($request->user(), $data['plan'], $data['cycle'] ?? 'monthly')]); }
    public function sync(Request $request, BillingService $billing): JsonResponse { $data = $request->validate(['session_id' => ['nullable', 'string', 'regex:/^cs_(test|live)_[a-zA-Z0-9]+$/', 'max:255']]); $billing->syncForUser($request->user(), $data['session_id'] ?? null); return $this->me($request, app(UploadQuotaService::class)); }
    public function portal(Request $request, BillingService $billing): JsonResponse { return response()->json(['data' => $billing->portal($request->user())]); }
    public function cancel(Request $request, BillingService $billing): JsonResponse { return response()->json(['data' => $billing->cancel($request->user())]); }
    public function resume(Request $request, BillingService $billing): JsonResponse { return response()->json(['data' => $billing->resume($request->user())]); }
    public function webhook(Request $request, BillingService $billing): JsonResponse { $billing->webhook($request->getContent(), (string) $request->header('Stripe-Signature')); return response()->json(['received' => true]); }
}
