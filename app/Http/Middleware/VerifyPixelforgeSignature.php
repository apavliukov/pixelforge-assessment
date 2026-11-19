<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets through only requests signed by Pixelforge: `Pixelforge-Signature: t=<unix time>,v1=<hex>`,
 * where v1 is the HMAC-SHA256 of `<t>.<raw body>` under the webhook secret. The raw body is
 * used before any JSON decoding, and old timestamps are rejected so a captured request cannot be
 * replayed.
 */
final class VerifyPixelforgeSignature
{
    public const int TOLERANCE_SECONDS = 300;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isSignedByPixelforge($request)) {
            abort(400, 'Invalid Pixelforge signature.');
        }

        return $next($request);
    }

    private function isSignedByPixelforge(Request $request): bool
    {
        $secret = config('services.pixelforge.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $parts = [];

        foreach (explode(',', (string) $request->header('Pixelforge-Signature')) as $part) {
            [$key, $value] = array_pad(explode('=', mb_trim($part), 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';

        if (! ctype_digit($timestamp) || $signature === '') {
            return false;
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', "$timestamp.{$request->getContent()}", $secret);

        return hash_equals($expected, $signature);
    }
}
