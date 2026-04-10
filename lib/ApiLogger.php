<?php

declare(strict_types=1);

/**
 * Registro de peticiones y respuestas de la API en /log/api-YYYY-MM-DD.log
 */
final class ApiLogger
{
    private static bool $bodyLogged = false;

    private static function dir(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'log';
    }

    private static function file(): string
    {
        return self::dir() . DIRECTORY_SEPARATOR . 'api-' . date('Y-m-d') . '.log';
    }

    public static function init(): void
    {
        $d = self::dir();
        if (!is_dir($d)) {
            @mkdir($d, 0755, true);
        }
    }

    /** @param list<string>|string $lines */
    public static function write(string|array $lines): void
    {
        self::init();
        $text = is_array($lines) ? implode("\n", $lines) . "\n" : $lines . "\n";
        @file_put_contents(self::file(), $text, FILE_APPEND | LOCK_EX);
    }

    public static function requestIncoming(): void
    {
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = (string) ($_GET['__path'] ?? '');
        $ip = self::clientIp();
        $ua = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400);
        $cl = (string) ($_SERVER['CONTENT_LENGTH'] ?? '');
        $ct = (string) ($_SERVER['CONTENT_TYPE'] ?? '');

        $block = [
            str_repeat('=', 72),
            date('Y-m-d H:i:s') . '  ← ENTRADA API',
            'Método: ' . $method,
            'URI: ' . $uri,
            '__path (ruta lógica): ' . ($path !== '' ? $path : '(vacío)'),
            'IP: ' . $ip,
            'User-Agent: ' . $ua,
            'Content-Type: ' . $ct,
            'Content-Length: ' . $cl,
        ];

        $q = $_GET;
        unset($q['__path']);
        if ($q !== []) {
            $block[] = 'Query (sin __path): ' . self::jsonPretty(self::redactArray($q));
        }

        if (!empty($_POST) && is_array($_POST)) {
            $block[] = 'POST (form): ' . self::jsonPretty(self::redactArray($_POST));
        }

        $block[] = '(Cuerpo JSON, si aplica, se registra al leerlo con read_json_body)';
        $block[] = str_repeat('-', 72);

        self::write($block);
    }

    public static function logJsonBody(string $raw): void
    {
        if (self::$bodyLogged) {
            return;
        }
        self::$bodyLogged = true;

        if ($raw === '') {
            self::write(['  [sin cuerpo JSON]']);

            return;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            self::write([
                '  Cuerpo (no JSON, truncado): ' . mb_substr($raw, 0, 4000)
                . (strlen($raw) > 4000 ? '…' : ''),
            ]);

            return;
        }

        $masked = self::redactArray($decoded);
        $json = self::jsonPretty($masked);
        if (strlen($json) > 12000) {
            $json = mb_substr($json, 0, 12000) . "\n…(truncado, " . strlen($json) . ' bytes)';
        }
        self::write(['  JSON recibido:', $json]);
    }

    /** @param array<string, mixed> $data */
    public static function logResponse(int $code, array $data): void
    {
        $summary = self::summarizeResponse($data);
        $json = self::jsonPretty($summary);
        if (strlen($json) > 16000) {
            $json = mb_substr($json, 0, 16000) . "\n…(truncado)";
        }

        self::write([
            date('Y-m-d H:i:s') . '  → RESPUESTA HTTP ' . $code,
            $json,
            str_repeat('=', 72),
            '',
        ]);
    }

    public static function logException(Throwable $e): void
    {
        self::write([
            date('Y-m-d H:i:s') . '  !! EXCEPCIÓN',
            $e->getMessage(),
            $e->getFile() . ':' . (string) $e->getLine(),
            $e->getTraceAsString(),
            str_repeat('-', 72),
        ]);
    }

    public static function shutdownCheck(): void
    {
        $last = error_get_last();
        if ($last === null) {
            return;
        }
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
        if (!in_array((int) $last['type'], $fatal, true)) {
            return;
        }

        self::write([
            date('Y-m-d H:i:s') . '  !! ERROR FATAL (shutdown)',
            (string) ($last['message'] ?? ''),
            (string) ($last['file'] ?? '') . ':' . (string) ($last['line'] ?? ''),
            str_repeat('-', 72),
        ]);
    }

    private static function clientIp(): string
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $first = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);

            return $first !== '' ? $first : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        }

        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function redactArray(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $key = strtolower((string) $k);
            if (str_contains($key, 'password')
                || str_contains($key, 'token')
                || str_contains($key, 'secret')
                || str_contains($key, 'api_key')
                || $key === 'authorization'
            ) {
                $out[$k] = '***';

                continue;
            }
            if (is_array($v)) {
                $out[$k] = self::redactArray($v);
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    private static function jsonPretty(mixed $v): string
    {
        $s = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);

        return $s !== false ? $s : '{}';
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function summarizeResponse(array $data): array
    {
        $copy = $data;
        foreach (['products', 'categories', 'list', 'registrations', 'items', 'orders'] as $k) {
            if (isset($copy[$k]) && is_array($copy[$k])) {
                $copy[$k] = '[' . count($copy[$k]) . ' elementos]';
            }
        }
        if (isset($copy['product']) && is_array($copy['product'])) {
            $pid = $copy['product']['product_id'] ?? $copy['product']['id'] ?? '?';
            $copy['product'] = '[producto #' . $pid . ']';
        }

        return $copy;
    }
}
