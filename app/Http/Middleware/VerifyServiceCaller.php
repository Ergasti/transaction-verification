<?php

namespace Modules\TransactionVerification\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * HMAC service authentication, the same wire protocol as the affiliate internal API, so one signer serves both.
 * Self-contained, so the package needs no other module.
 *   canonical = METHOD \n PATH?SORTED_QUERY \n sha256hex(rawBody) \n TIMESTAMP \n NONCE
 *   signature = lowercase hex HMAC-SHA256(canonical, secret)
 */
class VerifyServiceCaller
{
    private const TIMESTAMP_WINDOW = 300;

    // Twice the timestamp window: a nonce outlives every request that could still carry it.
    private const NONCE_TTL = 600;

    public function handle(Request $request, Closure $next): Response
    {
        // A config flip, no deploy, if the secret leaks.
        if (! config('transaction-verification.api.enabled')) {
            return $this->deny('transaction_verification_unavailable', 'The transaction verification API is switched off.', 503);
        }

        $keyId = (string) $request->header('X-Service-Key-Id', '');
        $timestamp = (string) $request->header('X-Service-Timestamp', '');
        $nonce = (string) $request->header('X-Service-Nonce', '');
        $signature = (string) $request->header('X-Service-Signature', '');

        if ($keyId === '' || $timestamp === '' || $nonce === '' || $signature === '') {
            return $this->deny('service_auth', 'Missing service authentication headers.', 401);
        }

        // Interpolated into a config path below.
        if (preg_match('/^[a-z0-9_-]{1,32}$/', $keyId) !== 1) {
            return $this->deny('service_auth', 'Service key id is malformed.', 401);
        }

        if (! ctype_digit($timestamp) || abs(now()->getTimestamp() - (int) $timestamp) > self::TIMESTAMP_WINDOW) {
            return $this->deny('service_auth_expired', 'Service request timestamp is outside the accepted window.', 401);
        }

        // Checked before it can reach the replay cache.
        if (preg_match('/^[0-9a-f]{32}$/', $nonce) !== 1) {
            return $this->deny('service_auth', 'Service request nonce is malformed.', 401);
        }

        // The signed string sorts the raw pairs, but PHP keeps the last of two keys that decode alike: reordering them
        // would change a value without changing the signature.
        if ($this->ambiguousQuery((string) $request->server->get('QUERY_STRING', ''))) {
            return $this->deny('service_auth', 'Service request query names a key twice or one PHP cannot read.', 401);
        }

        // Before any state write: an unauthenticated caller never touches the replay cache.
        $consumer = (array) config("transaction-verification.api.consumers.{$keyId}", []);

        if (! $this->signatureMatches($this->canonicalString($request), $signature, (array) ($consumer['secrets'] ?? []))) {
            return $this->deny('service_auth', 'Invalid service signature.', 401);
        }

        // Only as good as TRUSTED_PROXIES: under '*' X-Forwarded-For is spoofable. The HMAC is the real control.
        $allowedIps = (array) ($consumer['allowed_ips'] ?? []);

        if ($allowedIps !== [] && ! in_array($request->ip(), $allowedIps, true)) {
            return $this->deny('service_auth_ip', 'Service caller IP is not allowed.', 403);
        }

        // Last, so only a fully authenticated request writes the cache.
        if (! Cache::add("tv-svc:nonce:{$keyId}:{$nonce}", 1, self::NONCE_TTL)) {
            return $this->deny('service_auth_replay', 'Service request nonce has already been used.', 401);
        }

        return $next($request);
    }

    private function canonicalString(Request $request): string
    {
        $query = $this->sortedQuery((string) $request->server->get('QUERY_STRING', ''));

        return implode("\n", [
            strtoupper($request->getMethod()),
            $request->getPathInfo().($query === '' ? '' : "?{$query}"),
            hash('sha256', $request->getContent()),
            (string) $request->header('X-Service-Timestamp'),
            (string) $request->header('X-Service-Nonce'),
        ]);
    }

    /** Pairs sorted by key, values exactly as received (no decode and re-encode). */
    private function sortedQuery(string $rawQuery): string
    {
        if ($rawQuery === '') {
            return '';
        }

        $pairs = explode('&', $rawQuery);
        usort($pairs, fn (string $a, string $b): int => strcmp(explode('=', $a, 2)[0], explode('=', $b, 2)[0]));

        return implode('&', $pairs);
    }

    /** Each key as PHP itself reads it (its own parser: decoding, NUL, spaces, brackets); an array root counts once. */
    private function ambiguousQuery(string $rawQuery): bool
    {
        $seen = [];

        foreach (explode('&', $rawQuery) as $pair) {
            // Silenced: a key nested too deep warns, which Laravel would turn into a 500 before authentication.
            @parse_str($pair, $parsed);
            $key = array_key_first($parsed);

            // No name at all is ignored; a name PHP drops (too deep, only NUL or spaces) can erase another key.
            if ($key === null) {
                if (explode('=', $pair, 2)[0] === '') {
                    continue;
                }

                return true;
            }

            if (isset($seen[$key])) {
                return true;
            }

            $seen[$key] = true;
        }

        return false;
    }

    /** @param  array<int, mixed>  $secrets */
    private function signatureMatches(string $canonical, string $signature, array $secrets): bool
    {
        foreach ($secrets as $secret) {
            if (is_string($secret) && $secret !== '' && hash_equals(hash_hmac('sha256', $canonical, $secret), $signature)) {
                return true;
            }
        }

        return false;
    }

    private function deny(string $error, string $message, int $status): Response
    {
        return response()->json(['success' => false, 'error' => $error, 'message' => $message], $status);
    }
}
