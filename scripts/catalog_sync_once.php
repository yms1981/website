<?php

declare(strict_types=1);

/**
 * Worker en CLI: sincroniza catálogo FullVendor → catalog_categories / catalog_products.
 * Lanzado por CatalogSync::spawnPhpCli() tras POST /api/catalog/sync (segundo plano).
 */

$root = dirname(__DIR__);
chdir($root);
require_once $root . '/bootstrap.php';
require_once $root . '/lib/CatalogSync.php';

if (!Db::enabled()) {
    exit(1);
}

CatalogSync::runWorkerJob();
exit(0);
