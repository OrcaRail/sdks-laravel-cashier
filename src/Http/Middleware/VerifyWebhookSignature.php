<?php

declare(strict_types=1);

namespace OrcaRail\Cashier\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use OrcaRail\Exception\SignatureVerificationException;
use OrcaRail\WebhookSignature;
use Symfony\Component\HttpFoundation\Response;

final class VerifyWebhookSignature
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('orcarail-cashier.webhook.secret', '');
        $header = $request->header('X-Webhook-Signature')
            ?? $request->header('x-webhook-signature')
            ?? '';
        $signature = is_array($header) ? (string) ($header[0] ?? '') : (string) $header;

        try {
            WebhookSignature::assertValid($request->getContent(), $signature, $secret);
        } catch (SignatureVerificationException) {
            abort(403, 'Invalid OrcaRail webhook signature.');
        }

        return $next($request);
    }
}
