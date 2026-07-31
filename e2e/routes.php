<?php

declare(strict_types=1);

/**
 * E2E-only routes for the Docker real-flow harness.
 * Loaded outside the web middleware group (no CSRF).
 */

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use OrcaRail\Cashier\Cashier;
use OrcaRail\OrcaRailObject;

Route::get('/e2e/state', function () {
    $user = User::query()->where('email', 'cashier-e2e@example.com')->firstOrFail();
    $subscriptions = $user->subscriptions()->orderByDesc('id')->get()->map(static function ($sub) {
        return [
            'id' => $sub->id,
            'type' => $sub->type,
            'orcarail_id' => $sub->orcarail_id,
            'orcarail_status' => $sub->orcarail_status,
            'orcarail_price_id' => $sub->orcarail_price_id,
            'trial_ends_at' => optional($sub->trial_ends_at)?->toIso8601String(),
            'ends_at' => optional($sub->ends_at)?->toIso8601String(),
            'valid' => $sub->valid(),
            'on_trial' => $sub->onTrial(),
        ];
    })->values();

    return response()->json([
        'user_id' => $user->id,
        'email' => $user->email,
        'subscribed' => $user->subscribed(),
        'on_trial' => $user->onTrial(),
        'subscriptions' => $subscriptions,
    ]);
});

Route::post('/e2e/subscribe', function (Request $request) {
    $user = User::query()->where('email', 'cashier-e2e@example.com')->firstOrFail();

    $tokenId = (string) ($request->input('token_id') ?: env('ORCARAIL_TOKEN_ID', ''));
    $networkId = (string) ($request->input('network_id') ?: env('ORCARAIL_NETWORK_ID', ''));
    $amount = (string) ($request->input('amount') ?: '10.00');
    $currency = (string) ($request->input('currency') ?: env('ORCARAIL_CURRENCY', 'usd'));
    $interval = (string) ($request->input('interval') ?: 'month');

    if ($tokenId === '' || $networkId === '') {
        return response()->json([
            'error' => 'token_id and network_id are required (env ORCARAIL_TOKEN_ID / ORCARAIL_NETWORK_ID)',
        ], 422);
    }

    $checkout = $user->newSubscription('default')
        ->withAmount([
            'amount' => $amount,
            'currency' => $currency,
            'token_id' => $tokenId,
            'network_id' => $networkId,
            'interval' => $interval,
        ])
        ->create([
            'payer_email' => $user->email,
            'collection_method' => 'send_payment_link',
            'description' => 'Cashier E2E subscription',
            'return_url' => (string) ($request->input('return_url') ?: rtrim((string) env('APP_URL'), '/') . '/e2e/state'),
            'cancel_url' => (string) ($request->input('cancel_url') ?: rtrim((string) env('APP_URL'), '/') . '/e2e/state'),
        ]);

    $subscription = $checkout->subscription;
    if ($subscription === null) {
        return response()->json(['error' => 'subscription missing from checkout'], 500);
    }

    // Create response may omit latest_payment_link — retrieve for slug/url.
    $remote = Cashier::api()->subscriptions->retrieve($subscription->orcarail_id);
    $link = $remote->latest_payment_link;
    $payUrl = null;
    $paySlug = null;
    if ($link instanceof OrcaRailObject) {
        $payUrl = is_string($link->link) ? $link->link : null;
        $paySlug = is_string($link->unique_slug) ? $link->unique_slug : null;
    } elseif (is_array($link)) {
        $payUrl = isset($link['link']) && is_string($link['link']) ? $link['link'] : null;
        $paySlug = isset($link['unique_slug']) && is_string($link['unique_slug']) ? $link['unique_slug'] : null;
    }

    if ($payUrl === null) {
        $payUrl = $checkout->url();
    }

    return response()->json([
        'subscription_id' => $subscription->id,
        'orcarail_id' => $subscription->orcarail_id,
        'orcarail_status' => $subscription->orcarail_status,
        'pay_url' => $payUrl,
        'pay_slug' => $paySlug,
        'checkout_url' => $checkout->url(),
    ]);
});

Route::post('/e2e/checkout', function (Request $request) {
    $user = User::query()->where('email', 'cashier-e2e@example.com')->firstOrFail();

    $tokenId = (string) ($request->input('tokenId') ?: $request->input('token_id') ?: env('ORCARAIL_TOKEN_ID', ''));
    $networkId = (string) ($request->input('networkId') ?: $request->input('network_id') ?: env('ORCARAIL_NETWORK_ID', ''));
    $amount = (string) ($request->input('amount') ?: '10.00');
    $currency = (string) ($request->input('currency') ?: env('ORCARAIL_CURRENCY', 'usd'));

    if ($tokenId === '' || $networkId === '') {
        return response()->json([
            'error' => 'tokenId and networkId are required',
        ], 422);
    }

    $returnUrl = (string) ($request->input('return_url') ?: rtrim((string) env('APP_URL'), '/') . '/e2e/state');

    $checkout = $user->checkout([
        'amount' => $amount,
        'currency' => $currency,
        'tokenId' => $tokenId,
        'networkId' => $networkId,
        'return_url' => $returnUrl,
        'cancel_url' => $returnUrl,
    ], [
        'return_url' => $returnUrl,
    ]);

    $payment = $checkout->payment;
    $url = $checkout->url();
    $paySlug = null;
    if (is_string($url) && $url !== '') {
        if (preg_match('~/pay/([^/?]+)~', $url, $m) === 1) {
            $paySlug = $m[1];
        } elseif (preg_match('~https?://[^/]+/([A-Za-z0-9_-]+)(?:\?|$)~', $url, $m) === 1) {
            $paySlug = $m[1];
        }
    }

    $paymentLink = $payment?->asOrcaRailObject()->paymentLink
        ?? $payment?->asOrcaRailObject()->payment_link
        ?? null;
    if ($paySlug === null && $paymentLink instanceof OrcaRailObject && is_string($paymentLink->unique_slug)) {
        $paySlug = $paymentLink->unique_slug;
    } elseif ($paySlug === null && is_array($paymentLink) && isset($paymentLink['unique_slug'])) {
        $paySlug = (string) $paymentLink['unique_slug'];
    }

    return response()->json([
        'intent_id' => $payment?->id(),
        'status' => $payment?->status(),
        'pay_url' => $url,
        'pay_slug' => $paySlug,
    ]);
});
