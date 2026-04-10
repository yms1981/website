<?php

declare(strict_types=1);

/**
 * Ejecutar cada 10 minutos (cron / Programador de tareas de Windows).
 *
 * Linux/macOS (crontab, cada 10 min): php /ruta/proyecto/scripts/periodic_fullvendor_sync.php
 * Redirige salida a un log si quieres.
 *
 * Windows: run_periodic_sync.bat o Programador de tareas con php.exe y esta ruta.
 */

$root = dirname(__DIR__);
chdir($root);

require_once $root . '/bootstrap.php';
require_once $root . '/lib/PeriodicFullVendorSync.php';

$result = PeriodicFullVendorSync::run();
$summary = PeriodicFullVendorSync::summarize($result);
echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

$bad = PeriodicFullVendorSync::hadProblems($result);
exit($bad ? 1 : 0);
