<?php

declare(strict_types=1);

/**
 * Activa el registro en /log para todas las peticiones que pasan por la API.
 * Incluir después de bootstrap.php y antes de hv_api_dispatch().
 */
if (!defined('HV_API_REQUEST')) {
    define('HV_API_REQUEST', true);
}

require_once dirname(__DIR__) . '/lib/ApiLogger.php';
ApiLogger::init();
ApiLogger::requestIncoming();

register_shutdown_function(static function (): void {
    if (!defined('HV_API_REQUEST') || !HV_API_REQUEST) {
        return;
    }
    ApiLogger::shutdownCheck();
});
