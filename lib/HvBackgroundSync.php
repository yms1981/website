<?php

declare(strict_types=1);

/**
 * Tras GET /api/products o /api/categorylist, programa (shutdown) un refresco ligero de
 * clientes + usersList (sellers) respecto a FullVendor, como mucho cada N segundos.
 * No bloquea la respuesta JSON al navegador. Desactivar con BACKGROUND_CUSTOMER_USERS_SYNC_SEC=0.
 */
final class HvBackgroundSync
{
    private static bool $scheduled = false;

    public static function scheduleThrottledCustomerUsersRefresh(): void
    {
        if (self::$scheduled) {
            return;
        }
        self::$scheduled = true;

        $interval = (int) config('BACKGROUND_CUSTOMER_USERS_SYNC_SEC', '600');
        if ($interval <= 0) {
            return;
        }
        if ($interval < 120) {
            $interval = 120;
        }

        register_shutdown_function(static function () use ($interval): void {
            if (!function_exists('fullvendor_api_configured') || !fullvendor_api_configured()) {
                return;
            }
            if (!class_exists('Db', false) || !Db::enabled()) {
                return;
            }
            $root = dirname(__DIR__);
            $dir = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'cache';
            if (!is_dir($dir) || !is_writable($dir)) {
                return;
            }
            $lock = $dir . DIRECTORY_SEPARATOR . '.hv-customer-users-refresh.lock';
            if (is_file($lock) && (time() - (int) filemtime($lock)) < $interval) {
                return;
            }
            if (!@touch($lock)) {
                return;
            }
            try {
                require_once $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'CustomerSync.php';
                require_once $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'UsersListSync.php';
                CustomerSync::refreshCustomersDataDump();
                UsersListSync::refreshUsersListDataDump();
            } catch (Throwable $e) {
                error_log('[HvBackgroundSync] ' . $e->getMessage());
            }
        });
    }
}
