<?php

declare(strict_types=1);

/**
 * Opcional: vuelca categorías y productos desde FullVendor a tablas `catalog_*` (admin POST /api/catalog/sync).
 * La web lista catálogo siempre desde el API vía CatalogCache; estas tablas no se usan para la vista pública salvo que vuelvas a activar lectura BD.
 */
final class CatalogSync
{
    /** @var list<string> */
    private const LANGUAGE_IDS = ['1', '2'];

    private static function trace(string $message, string $level = 'INFO'): void
    {
        error_log('[CatalogSync] ' . $message);
        if (class_exists('AppLog', false)) {
            AppLog::sync('[catalog] ' . $message, $level);
        }
    }

    /**
     * @return array{
     *   categories_upserted:int,
     *   products_upserted:int,
     *   languages:list<string>,
     *   errors:list<string>
     * }
     */
    public static function syncFromFullVendor(): array
    {
        $out = [
            'categories_upserted' => 0,
            'products_upserted' => 0,
            'languages' => [],
            'errors' => [],
        ];

        if (!Db::enabled()) {
            $out['errors'][] = 'Database not configured';

            return $out;
        }

        $pdo = Db::pdo();

        foreach (self::LANGUAGE_IDS as $lid) {
            $out['languages'][] = $lid;
            try {
                $pdo->beginTransaction();

                $cats = FullVendor::getCategories($lid);
                foreach ($cats as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $cid = (string) ($row['category_id'] ?? $row['cat_id'] ?? '');
                    if ($cid === '') {
                        continue;
                    }
                    $json = self::encodeRow($row);
                    self::upsertCategory($pdo, $lid, $cid, $json);
                    $out['categories_upserted']++;
                }

                $prods = FullVendor::getProducts(null, null, $lid);
                foreach ($prods as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $pid = (string) ($row['product_id'] ?? '');
                    if ($pid === '') {
                        continue;
                    }
                    $json = self::encodeRow($row);
                    self::upsertProduct($pdo, $lid, $pid, $json);
                    $out['products_upserted']++;
                }

                $pdo->commit();
                self::trace("Idioma {$lid}: categorías=" . count($cats) . ' productos=' . count($prods));
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                self::trace('Error idioma ' . $lid . ': ' . $e->getMessage(), 'ERROR');
                if (class_exists('AppLog', false)) {
                    AppLog::syncException($e, 'CatalogSync lang ' . $lid);
                }
                $out['errors'][] = "language_id {$lid}: " . $e->getMessage();
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    private static function encodeRow(array $row): string
    {
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

        return $json;
    }

    private static function upsertCategory(\PDO $pdo, string $languageId, string $categoryId, string $json): void
    {
        $st = $pdo->prepare(
            'INSERT INTO `catalog_categories` (`language_id`, `category_id`, `json_payload`)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `json_payload` = VALUES(`json_payload`), `synced_at` = CURRENT_TIMESTAMP'
        );
        $st->execute([$languageId, $categoryId, $json]);
    }

    private static function upsertProduct(\PDO $pdo, string $languageId, string $productId, string $json): void
    {
        $st = $pdo->prepare(
            'INSERT INTO `catalog_products` (`language_id`, `product_id`, `json_payload`)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `json_payload` = VALUES(`json_payload`), `synced_at` = CURRENT_TIMESTAMP'
        );
        $st->execute([$languageId, $productId, $json]);
    }

    // —— Estado y sincronización en segundo plano (tabla catalog_sync_state + scripts/catalog_sync_once.php) ——

    private static function staleRunningSeconds(): int
    {
        $v = (int) config('CATALOG_SYNC_STALE_SECONDS', '2700');

        return max(300, $v);
    }

    private static function lockFilePath(): string
    {
        $dir = dirname(__DIR__) . '/data/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir . '/catalog_sync_worker.lock';
    }

    /** Ruta al ejecutable PHP CLI (Apache suele reportar httpd.exe en PHP_BINARY). */
    public static function phpCliBinary(): string
    {
        $custom = trim(config('PHP_CLI_PATH', ''));
        if ($custom !== '' && @is_executable($custom)) {
            return $custom;
        }
        $bindir = PHP_BINDIR;
        $isWin = defined('PHP_WINDOWS_VERSION_BUILD')
            || (defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Windows')
            || strncasecmp(PHP_OS, 'WIN', 3) === 0;
        $candidate = $isWin ? $bindir . DIRECTORY_SEPARATOR . 'php.exe' : $bindir . DIRECTORY_SEPARATOR . 'php';
        if (@is_executable($candidate)) {
            return $candidate;
        }
        $bin = PHP_BINARY;
        if ($bin !== '' && stripos($bin, 'php') !== false && @is_executable($bin)) {
            return $bin;
        }

        return '';
    }

    public static function workerScriptPath(): string
    {
        return dirname(__DIR__) . '/scripts/catalog_sync_once.php';
    }

    /**
     * @return bool true si se lanzó un proceso (no espera a que termine)
     */
    public static function trySpawnWorkerCli(): bool
    {
        $php = self::phpCliBinary();
        $script = self::workerScriptPath();
        if ($php === '' || !is_readable($script)) {
            return false;
        }
        $isWin = defined('PHP_WINDOWS_VERSION_BUILD')
            || (defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Windows')
            || strncasecmp(PHP_OS, 'WIN', 3) === 0;
        if ($isWin) {
            $cmd = 'start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($script);
            $h = @popen($cmd, 'r');
            if ($h !== false) {
                pclose($h);

                return true;
            }

            return false;
        }
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 &';
        @exec($cmd);

        return true;
    }

    /** Marca jobs `running` colgados como error para permitir uno nuevo. */
    public static function clearStaleRunningState(\PDO $pdo): void
    {
        $sec = self::staleRunningSeconds();
        $st = $pdo->prepare(
            'UPDATE `catalog_sync_state`
             SET `status` = \'error\',
                 `last_error` = \'Sincronización anterior sin finalizar (tiempo máximo)\',
                 `finished_at` = NOW()
             WHERE `id` = 1 AND `status` = \'running\'
               AND `started_at` IS NOT NULL
               AND `started_at` < DATE_SUB(NOW(), INTERVAL ? SECOND)'
        );
        $st->execute([$sec]);
    }

    /**
     * Reserva el job en BD. Devuelve false si ya hay sync en curso (no obsoleto).
     *
     * @return array{claimed:bool, state:array<string, mixed>}
     */
    public static function claimBackgroundSyncOrBusy(): array
    {
        $pdo = Db::pdo();
        self::clearStaleRunningState($pdo);
        $st = $pdo->prepare(
            'UPDATE `catalog_sync_state`
             SET `status` = \'running\',
                 `started_at` = NOW(),
                 `finished_at` = NULL,
                 `last_error` = NULL,
                 `result_json` = NULL
             WHERE `id` = 1 AND `status` IN (\'idle\', \'done\', \'error\')'
        );
        $st->execute();
        $state = self::getSyncState($pdo);

        return [
            'claimed' => $st->rowCount() === 1,
            'state' => $state,
        ];
    }

    public static function releaseClaimAfterSpawnFailure(\PDO $pdo, string $message): void
    {
        $st = $pdo->prepare(
            'UPDATE `catalog_sync_state`
             SET `status` = \'error\',
                 `finished_at` = NOW(),
                 `last_error` = ?
             WHERE `id` = 1 AND `status` = \'running\''
        );
        $st->execute([$message]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getSyncState(?\PDO $pdo = null): array
    {
        try {
            $pdo = $pdo ?? Db::pdo();
            $st = $pdo->query('SELECT `status`, `started_at`, `finished_at`, `last_error`, `result_json` FROM `catalog_sync_state` WHERE `id` = 1 LIMIT 1');
            $row = $st !== false ? $st->fetch(\PDO::FETCH_ASSOC) : false;
            if (!is_array($row)) {
                return ['status' => 'unknown', 'started_at' => null, 'finished_at' => null, 'last_error' => null, 'result' => null];
            }
            $result = null;
            if (!empty($row['result_json'])) {
                $decoded = json_decode((string) $row['result_json'], true);
                $result = is_array($decoded) ? $decoded : null;
            }

            return [
                'status' => (string) ($row['status'] ?? 'idle'),
                'started_at' => $row['started_at'] ?? null,
                'finished_at' => $row['finished_at'] ?? null,
                'last_error' => $row['last_error'] ?? null,
                'result' => $result,
            ];
        } catch (Throwable $e) {
            return ['status' => 'error', 'started_at' => null, 'finished_at' => null, 'last_error' => $e->getMessage(), 'result' => null];
        }
    }

    private static function persistSyncSuccess(\PDO $pdo, array $stats): void
    {
        $json = json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $st = $pdo->prepare(
            'UPDATE `catalog_sync_state`
             SET `status` = \'done\',
                 `finished_at` = NOW(),
                 `last_error` = NULL,
                 `result_json` = ?
             WHERE `id` = 1'
        );
        $st->execute([$json !== false ? $json : '{}']);
    }

    private static function persistSyncFailure(\PDO $pdo, string $message): void
    {
        $st = $pdo->prepare(
            'UPDATE `catalog_sync_state`
             SET `status` = \'error\',
                 `finished_at` = NOW(),
                 `last_error` = ?,
                 `result_json` = NULL
             WHERE `id` = 1'
        );
        $st->execute([$message]);
    }

    /** Tras POST sync con foreground=true: actualiza catalog_sync_state sin worker. */
    public static function recordForegroundSyncResult(array $stats): void
    {
        $pdo = Db::pdo();
        if ($stats['errors'] !== []) {
            self::persistSyncFailure($pdo, implode('; ', $stats['errors']));
        } else {
            self::persistSyncSuccess($pdo, $stats);
        }
    }

    /**
     * Ejecutado por scripts/catalog_sync_once.php o tras fastcgi_finish_request (mismo proceso).
     */
    public static function runWorkerJob(): void
    {
        ignore_user_abort(true);
        @set_time_limit(0);
        $lockPath = self::lockFilePath();
        $fp = @fopen($lockPath, 'c+');
        if ($fp === false) {
            try {
                self::persistSyncFailure(Db::pdo(), 'No se pudo abrir el archivo de bloqueo del worker');
            } catch (Throwable) {
            }

            return;
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);

            return;
        }
        try {
            $pdo = Db::pdo();
            $out = self::syncFromFullVendor();
            if ($out['errors'] !== []) {
                self::persistSyncFailure($pdo, implode('; ', $out['errors']));
            } else {
                self::persistSyncSuccess($pdo, $out);
            }
        } catch (Throwable $e) {
            self::trace('runWorkerJob: ' . $e->getMessage(), 'ERROR');
            try {
                self::persistSyncFailure(Db::pdo(), $e->getMessage());
            } catch (Throwable) {
            }
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
