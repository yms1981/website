<?php

declare(strict_types=1);

final class Birdview
{
    private const BASE = 'http://telecom.birdviewmall.com/api';
    private const TIMEOUT = 10;

    private static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return $digits;
        }
        if (strlen($digits) === 10) {
            return '1' . $digits;
        }

        return $digits;
    }

    /** @param list<string> $to */
    public static function sendEmail(array $to, string $subject, string $body, bool $isHtml = false): bool
    {
        $url = self::BASE . '/Email/SendEmail';
        $payload = json_encode([
            'Destinatary' => $to,
            'Subject' => $subject,
            'Cuerpo' => $body,
            'isHTML' => $isHtml,
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $raw !== false && $code >= 200 && $code < 300;
    }

    public static function sendWhatsApp(string $to, string $message): bool
    {
        $url = self::BASE . '/WhatsApp/Send';
        $payload = json_encode([
            'to' => self::normalizePhone($to),
            'message' => $message,
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $raw !== false && $code >= 200 && $code < 300;
    }
}
