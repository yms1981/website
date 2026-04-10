<?php

declare(strict_types=1);

/**
 * Sincronización programable (cada ~10 min vía cron / Task Scheduler): catálogo, clientes y usersList desde FullVendor.
 *
 * Variables .env (todas opcionales salvo las de FullVendor/DB):
 * - PERIODIC_SYNC_ENABLED=1 (0 desactiva el script sin error)
 * - PERIODIC_SYNC_CATALOG=0 (catálogo se lee siempre del API; 1 = opcional volcado a tablas catalog_*)
 * - PERIODIC_SYNC_CUSTOMERS=1
 * - PERIODIC_SYNC_USERSLIST=1
 * - PERIODIC_SYNC_CUSTOMER_GROUPS=1 (grupos de cliente → tabla local `customer_groups`; api o BD según FULLVENDOR_DATA_SOURCE)
 */
final class PeriodicFullVendorSync
{
    private static function trace(string $message, string $level = 'INFO'): void
    {
        error_log('[PeriodicFullVendorSync] ' . $message);
        if (class_exists('AppLog', false)) {
            AppLog::sync('[periodic] ' . $message, $level);
        }
    }

    public static function enabled(): bool
    {
        return config('PERIODIC_SYNC_ENABLED', '1') !== '0';
    }

    public static function includeCatalog(): bool
    {
        return config('PERIODIC_SYNC_CATALOG', '0') !== '0';
    }

    public static function includeCustomers(): bool
    {
        return config('PERIODIC_SYNC_CUSTOMERS', '1') !== '0';
    }

    public static function includeUsersList(): bool
    {
        return config('PERIODIC_SYNC_USERSLIST', '1') !== '0';
    }

    public static function includeCustomerGroups(): bool
    {
        return config('PERIODIC_SYNC_CUSTOMER_GROUPS', '1') !== '0';
    }

    /**
     * Ejecuta los bloques activados en secuencia.
     *
     * @return array{
     *   started_at:string,
     *   finished_at:string,
     *   catalog:array<string, mixed>|null,
     *   customers:array<string, mixed>|null,
     *   usersList:array<string, mixed>|null,
     *   customer_groups:array<string, mixed>|null,
     *   fatal_errors:list<string>
     * }
     */
    public static function run(): array
    {
        $started = date('c');
        $fatal = [];

        $out = [
            'started_at' => $started,
            'finished_at' => '',
            'catalog' => null,
            'customers' => null,
            'usersList' => null,
            'customer_groups' => null,
            'fatal_errors' => [],
        ];

        if (!self::enabled()) {
            $out['fatal_errors'][] = 'PERIODIC_SYNC_ENABLED=0';
            $out['finished_at'] = date('c');

            return $out;
        }

        if (!class_exists('Db', false) || !Db::enabled()) {
            $fatal[] = 'Base de datos no configurada';
            $out['fatal_errors'] = $fatal;
            $out['finished_at'] = date('c');

            return $out;
        }

        $ds = function_exists('fullvendor_data_source') ? fullvendor_data_source() : 'api';
        $apiOk = function_exists('fullvendor_api_configured') && fullvendor_api_configured();
        $needApi = self::includeCustomers() || self::includeUsersList();
        if ($ds !== 'db' && self::includeCatalog()) {
            $needApi = true;
        }
        if ($ds !== 'db' && self::includeCustomerGroups()) {
            $needApi = true;
        }
        if ($needApi && !$apiOk) {
            $fatal[] = 'FullVendor API no configurada (.env: URL, token, company_id). Necesaria para clientes, usersList y/o catálogo o grupos en modo api.';
            $out['fatal_errors'] = $fatal;
            $out['finished_at'] = date('c');

            return $out;
        }

        require_once __DIR__ . '/CatalogSync.php';
        require_once __DIR__ . '/CustomerSync.php';
        require_once __DIR__ . '/UsersListSync.php';
        require_once __DIR__ . '/CustomerGroupsSync.php';

        ignore_user_abort(true);
        @set_time_limit(0);

        if (self::includeCatalog()) {
            try {
                $cat = CatalogSync::syncFromFullVendor();
                $out['catalog'] = $cat;
                CatalogSync::recordForegroundSyncResult($cat);
                self::trace(
                    'Catálogo: categorías=' . (int) ($cat['categories_upserted'] ?? 0)
                    . ' productos=' . (int) ($cat['products_upserted'] ?? 0)
                    . ' errores=' . count($cat['errors'] ?? [])
                );
            } catch (Throwable $e) {
                $fatal[] = 'Catálogo: ' . $e->getMessage();
                self::trace('Catálogo EX: ' . $e->getMessage(), 'ERROR');
                if (class_exists('AppLog', false)) {
                    AppLog::syncException($e, 'PeriodicFullVendorSync catalog');
                }
            }
        }

        // Clientes y usersList son independientes del catálogo: un fallo de catálogo no debe cancelar sellers/customers.
        if (self::includeCustomers()) {
            try {
                $cust = CustomerSync::syncFromFullVendor();
                $out['customers'] = $cust;
                self::trace(
                    'Clientes: upserted=' . (int) ($cust['upserted'] ?? 0)
                    . ' errores=' . count($cust['errors'] ?? [])
                );
            } catch (Throwable $e) {
                $fatal[] = 'Clientes: ' . $e->getMessage();
                self::trace('Clientes EX: ' . $e->getMessage(), 'ERROR');
                if (class_exists('AppLog', false)) {
                    AppLog::syncException($e, 'PeriodicFullVendorSync customers');
                }
            }
        }

        if (self::includeUsersList()) {
            try {
                $ul = UsersListSync::syncFromFullVendor();
                $out['usersList'] = $ul;
                self::trace(
                    'usersList: filas=' . (int) ($ul['userslist_upserted'] ?? 0)
                    . ' errores=' . count($ul['errors'] ?? [])
                );
            } catch (Throwable $e) {
                $fatal[] = 'usersList: ' . $e->getMessage();
                self::trace('usersList EX: ' . $e->getMessage(), 'ERROR');
                if (class_exists('AppLog', false)) {
                    AppLog::syncException($e, 'PeriodicFullVendorSync usersList');
                }
            }
        }

        if (self::includeCustomerGroups()) {
            try {
                $cg = CustomerGroupsSync::syncFromFullVendor();
                $out['customer_groups'] = $cg;
                self::trace(
                    'customer_groups: upserted=' . (int) ($cg['upserted'] ?? 0)
                    . ' source=' . (string) ($cg['source'] ?? '')
                    . ' errores=' . count($cg['errors'] ?? [])
                );
            } catch (Throwable $e) {
                $fatal[] = 'customer_groups: ' . $e->getMessage();
                self::trace('customer_groups EX: ' . $e->getMessage(), 'ERROR');
                if (class_exists('AppLog', false)) {
                    AppLog::syncException($e, 'PeriodicFullVendorSync customer_groups');
                }
            }
        }

        $out['fatal_errors'] = $fatal;
        $out['finished_at'] = date('c');

        if ($fatal === []) {
            self::trace('Ciclo completado OK ' . $out['finished_at']);
        }

        return $out;
    }

    /**
     * Resumen corto para JSON HTTP o logs.
     *
     * @param array<string, mixed> $run
     * @return array<string, mixed>
     */
    public static function summarize(array $run): array
    {
        $cat = $run['catalog'] ?? null;
        $cust = $run['customers'] ?? null;
        $ul = $run['usersList'] ?? null;
        $cg = $run['customer_groups'] ?? null;

        return [
            'started_at' => $run['started_at'] ?? '',
            'finished_at' => $run['finished_at'] ?? '',
            'fatal_errors' => $run['fatal_errors'] ?? [],
            'catalog' => is_array($cat) ? [
                'categories_upserted' => (int) ($cat['categories_upserted'] ?? 0),
                'products_upserted' => (int) ($cat['products_upserted'] ?? 0),
                'error_count' => count($cat['errors'] ?? []),
            ] : null,
            'customers' => is_array($cust) ? [
                'upserted' => (int) ($cust['upserted'] ?? 0),
                'error_count' => count($cust['errors'] ?? []),
            ] : null,
            'usersList' => is_array($ul) ? [
                'userslist_upserted' => (int) ($ul['userslist_upserted'] ?? 0),
                'error_count' => count($ul['errors'] ?? []),
            ] : null,
            'customer_groups' => is_array($cg) ? [
                'upserted' => (int) ($cg['upserted'] ?? 0),
                'source' => (string) ($cg['source'] ?? ''),
                'error_count' => count($cg['errors'] ?? []),
            ] : null,
        ];
    }

    /**
     * true si hubo excepciones o errores declarados en algún bloque ejecutado.
     *
     * @param array<string, mixed> $run
     */
    public static function hadProblems(array $run): bool
    {
        if (($run['fatal_errors'] ?? []) !== []) {
            return true;
        }
        foreach (['catalog', 'customers', 'usersList', 'customer_groups'] as $k) {
            $block = $run[$k] ?? null;
            if (!is_array($block)) {
                continue;
            }
            if (($block['errors'] ?? []) !== []) {
                return true;
            }
        }

        return false;
    }
}
