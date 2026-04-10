<?php

declare(strict_types=1);

function config(string $key, ?string $default = null): string
{
    $v = $_ENV[$key] ?? getenv($key);
    if ($v === false || $v === null || $v === '') {
        return $default ?? '';
    }

    return (string) $v;
}

function require_env(string $key): string
{
    $v = config($key);
    if ($v === '') {
        throw new RuntimeException("Missing environment variable: {$key}");
    }

    return $v;
}

/** api = HTTP FullVendor; db = catálogo desde MySQL (FULLVENDOR_DB_*). Login/carrito/sync siguen usando API si está definida. */
function fullvendor_data_source(): string
{
    $v = strtolower(trim(config('FULLVENDOR_DATA_SOURCE', 'api')));

    return $v === 'db' ? 'db' : 'api';
}

/** Credenciales MySQL del servidor donde está la BD de FullVendor (solo modo db). */
function fullvendor_db_configured(): bool
{
    return config('FULLVENDOR_DB_HOST', '') !== ''
        && config('FULLVENDOR_DB_NAME', '') !== ''
        && config('FULLVENDOR_DB_USER', '') !== '';
}

/** HTTP API (URL + token + compañía): obligatoria para login, carrito, pedidos, sync clientes/usersList. */
function fullvendor_api_configured(): bool
{
    return config('FULLVENDOR_BASE_URL') !== ''
        && config('FULLVENDOR_TOKEN') !== ''
        && config('FULLVENDOR_COMPANY_ID') !== '';
}

/**
 * Sitio puede mostrar catálogo (home / API products): API completa o solo BD + company_id.
 */
function fullvendor_configured(): bool
{
    if (config('FULLVENDOR_COMPANY_ID', '') === '') {
        return false;
    }
    if (fullvendor_data_source() === 'db') {
        return fullvendor_db_configured();
    }

    return fullvendor_api_configured();
}

function app_debug(): bool
{
    return config('APP_DEBUG', '0') === '1';
}

/** Log detallado de sincronización softcustomerList → customers (también si APP_DEBUG=1). */
function customer_sync_log_verbose(): bool
{
    return config('CUSTOMER_SYNC_LOG', '0') === '1' || app_debug();
}

/** Termina la petición con 503 si la API HTTP de FullVendor no está configurada (operaciones que no leen solo BD local). */
function api_require_fullvendor(): void
{
    if (!fullvendor_api_configured()) {
        json_response([
            'error' => 'FullVendor API no configurada: defina FULLVENDOR_BASE_URL, FULLVENDOR_TOKEN y FULLVENDOR_COMPANY_ID (necesario para esta operación). En modo FULLVENDOR_DATA_SOURCE=db el catálogo lee MySQL pero login, carrito y sync siguen usando la API.',
        ], 503);
    }
}

/**
 * Prefijo URL de la instalación (ej. /website), igual para index.php y para scripts bajo api/ (cualquier ruta).
 * dirname(SCRIPT_NAME) en api/products/index.php sería .../api/products; aquí se usa el tramo antes de /api/.
 */
function hv_app_web_path(): string
{
    if (PHP_SAPI === 'cli') {
        return '';
    }
    $sn = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($sn === '' || $sn === '/') {
        return '';
    }
    if (str_contains($sn, '/api/')) {
        $before = strstr($sn, '/api/', true);
        if ($before === false) {
            $before = '';
        }
        if ($before === '' || $before === '/') {
            return '';
        }

        return rtrim($before, '/');
    }
    if (preg_match('#^(.+)/api\.php$#', $sn, $m)) {
        $p = $m[1];
        if ($p === '' || $p === '/') {
            return '';
        }

        return rtrim($p, '/');
    }
    $dir = dirname($sn);
    $dir = str_replace('\\', '/', (string) $dir);
    if ($dir === '/' || $dir === '.' || $dir === '') {
        return '';
    }

    return rtrim($dir, '/');
}

function base_path(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $cached = hv_app_web_path();

    return $cached;
}

function base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host . base_path();
}

function fullvendor_base_url(): string
{
    $url = require_env('FULLVENDOR_BASE_URL');

    return rtrim($url, '/') . '/';
}

function whatsapp_business_number(): string
{
    return config('WHATSAPP_BUSINESS_NUMBER', '17736812440');
}
