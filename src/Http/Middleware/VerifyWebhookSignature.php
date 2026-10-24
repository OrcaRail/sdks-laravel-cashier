<?php

declare(strict_types=1);

namespace OrcaRail\Cashier\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the timestamped (v2) signature: HMAC-SHA256 of "<timestamp>.<body>". Deliveries
 * older than five minutes are rejected so a captured webhook cannot be replayed. The
 * legacy body-only signature is not accepted because it has no replay protection.
 */
final class VerifyWebhookSignature
{
    private const TOLERANCE_SECONDS = 300;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('orcarail-cashier.webhook.secret', '');
        $timestamp = (string) $request->headers->get('X-Webhook-Timestamp');
        $signature = strtolower((string) $request->headers->get('X-Webhook-Signature-V2'));

        $valid = $secret !== ''
            && preg_match('/^\d+$/', $timestamp) === 1
            && preg_match('/^[a-f0-9]{64}$/', $signature) === 1
            && abs(time() - (int) $timestamp) <= self::TOLERANCE_SECONDS
            && hash_equals(hash_hmac('sha256', $timestamp . '.' . $request->getContent(), $secret), $signature);

        if (!$valid) {
            abort(403, 'Invalid OrcaRail webhook signature.');
        }

        return $next($request);
    }
}
