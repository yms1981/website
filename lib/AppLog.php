<?php

declare(strict_types=1);

/**
 * Registro en archivos bajo /log:
 * - app-YYYY-MM-DD.log  → aplicación web, API (errores), esquema, aprobaciones, fatals
 * - sync-YYYY-MM-DD.log → sincronización de clientes (softcustomerList / CustomerSync)
 */
final class AppLog
{
    private static bool $shutdownRegistered = false;

    public static function dir(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'log';
    }

    private static function path(string $channel): string
    {
        return self::dir() . DIRECTORY_SEPARATOR . $channel . '-' . date('Y-m-d') . '.log';
    }

    public static function ensureDir(): void
    {
        $d = self::dir();
        if (!is_dir($d)) {
            @mkdir($d, 0755, true);
        }
    }

    /**
     * @param 'app'|'sync' $channel
     */
    public static function write(string $channel, string $level, string $message): void
    {
        self::ensureDir();
        $file = self::path($channel);
        $ts = date('Y-m-d H:i:s');
        if (!str_contains($message, "\n")) {
            $bytes = @file_put_contents(
                $file,
                "{$ts} [{$level}] {$message}\n",
                FILE_APPEND | LOCK_EX
            );
            if ($bytes === false) {
                error_log("[AppLog] No se pudo escribir {$file}. Revisa permisos de la carpeta log/");
            }

            return;
        }

        $bytes = @file_put_contents(
            $file,
            "{$ts} [{$level}]\n" . rtrim($message) . "\n---\n",
            FILE_APPEND | LOCK_EX
        );
        if ($bytes === false) {
            error_log("[AppLog] No se pudo escribir {$file}. Revisa permisos de la carpeta log/");
        }
    }

    public static function app(string $message, string $level = 'INFO'): void
    {
        self::write('app', $level, $message);
    }

    public static function sync(string $message, string $level = 'INFO'): void
    {
        self::write('sync', $level, $message);
    }

    public static function appException(Throwable $e, string $context = ''): void
    {
        $head = $context !== '' ? "{$context}: " : '';
        $head .= $e->getMessage() . ' @ ' . $e->getFile() . ':' . (string) $e->getLine();
        self::write('app', 'ERROR', $head . "\n" . $e->getTraceAsString());
    }

    public static function syncException(Throwable $e, string $context = ''): void
    {
        $head = $context !== '' ? "{$context}: " : '';
        $head .= $e->getMessage() . ' @ ' . $e->getFile() . ':' . (string) $e->getLine();
        self::write('sync', 'ERROR', $head . "\n" . $e->getTraceAsString());
    }

    public static function registerShutdownHandler(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;

        register_shutdown_function(static function (): void {
            $last = error_get_last();
            if ($last === null) {
                return;
            }
            $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
            if (!in_array((int) $last['type'], $fatal, true)) {
                return;
            }
            $msg = (string) ($last['message'] ?? '') . ' @ '
                . (string) ($last['file'] ?? '') . ':' . (string) ($last['line'] ?? '');
            if (class_exists('AppLog', false)) {
                AppLog::app('Shutdown fatal: ' . $msg, 'FATAL');
            }
        });
    }
}
