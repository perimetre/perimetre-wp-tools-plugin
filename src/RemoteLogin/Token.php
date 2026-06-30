<?php

declare(strict_types=1);

namespace Perimetre\WpTools\RemoteLogin;

/**
 * Pure HMAC-SHA256 helpers for the compact-JWT-style tokens issued by the
 * Helm portal. Mirrors src/server/services/tokens.ts in the portal repo.
 */
final class Token
{
    /**
     * Verifies signature and expiry. Returns the payload array on success
     * or null on any failure. Callers must treat null uniformly (no error
     * leak to the public endpoint).
     *
     * @return array{jti:string,sub:string,exp:int}|null
     */
    public static function verify(string $token, string $key): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$header_b64, $payload_b64, $sig_b64] = $parts;

        $expected = hash_hmac('sha256', $header_b64 . '.' . $payload_b64, $key, true);
        $provided = self::b64url_decode($sig_b64);
        if ($provided === null || ! hash_equals($expected, $provided)) {
            return null;
        }

        $payload_json = self::b64url_decode($payload_b64);
        if ($payload_json === null) {
            return null;
        }
        $payload = json_decode($payload_json, true);
        if (! is_array($payload)) {
            return null;
        }
        if (
            ! isset($payload['jti'], $payload['sub'], $payload['exp'])
            || ! is_string($payload['jti'])
            || ! is_string($payload['sub'])
            || ! is_int($payload['exp'])
        ) {
            return null;
        }
        if ($payload['exp'] < time()) {
            return null;
        }
        /** @var array{jti:string,sub:string,exp:int} $payload */
        return $payload;
    }

    private static function b64url_decode(string $input): ?string
    {
        $remainder = strlen($input) % 4;
        if ($remainder > 0) {
            $input .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($input, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}
