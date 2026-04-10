<?php

declare(strict_types=1);

final class JwtHs256
{
    public static function encode(array $payload, string $secret): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $h = self::b64url((string) json_encode($header, JSON_THROW_ON_ERROR));
        $p = self::b64url((string) json_encode($payload, JSON_THROW_ON_ERROR));
        $signing = $h . '.' . $p;
        $sig = self::b64url(hash_hmac('sha256', $signing, $secret, true));

        return $signing . '.' . $sig;
    }

    /** @return array<string, mixed>|null */
    public static function decode(string $jwt, string $secret): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;
        $signing = $h . '.' . $p;
        $expected = self::b64url(hash_hmac('sha256', $signing, $secret, true));
        if (!hash_equals($expected, $s)) {
            return null;
        }
        $raw = self::b64urlDecode($p);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        if (isset($data['exp']) && time() > (int) $data['exp']) {
            return null;
        }

        return $data;
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $data): string
    {
        $pad = strlen($data) % 4;
        if ($pad > 0) {
            $data .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode(strtr($data, '-_', '+/'), true);

        return $out === false ? '' : $out;
    }
}
