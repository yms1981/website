<?php

declare(strict_types=1);

final class ApprovalToken
{
    public static function generate(int $registrationId, string $secret): string
    {
        $expiry = (int) (microtime(true) * 1000) + 86400000;
        $payload = $registrationId . ':' . $expiry;
        $signature = hash_hmac('sha256', $payload, $secret);
        $raw = $payload . ':' . $signature;

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function verify(string $token, string $secret): ?int
    {
        $pad = strlen($token) % 4;
        if ($pad > 0) {
            $token .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }
        $parts = explode(':', $decoded);
        if (count($parts) !== 3) {
            return null;
        }
        [$idStr, $expiryStr, $signature] = $parts;
        $payload = $idStr . ':' . $expiryStr;
        $expected = hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($expected, $signature)) {
            return null;
        }
        $expiry = (int) $expiryStr;
        $id = (int) $idStr;
        if ((int) (microtime(true) * 1000) > $expiry) {
            return null;
        }

        return $id;
    }
}
