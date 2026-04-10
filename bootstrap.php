<?php

declare(strict_types=1);

$root = __DIR__;

require_once $root . '/lib/env_loader.php';
load_dotenv($root);

require_once $root . '/lib/config.php';
require_once $root . '/lib/AppLog.php';
AppLog::registerShutdownHandler();
require_once $root . '/lib/Auth.php';
if (PHP_SAPI !== 'cli') {
    Auth::ensureSession();
}
require_once $root . '/lib/helpers.php';
require_once $root . '/lib/FullVendor.php';
require_once $root . '/lib/CatalogCache.php';
require_once $root . '/lib/Birdview.php';
require_once $root . '/lib/Db.php';
require_once $root . '/lib/UsersSchema.php';
UsersSchema::ensure();
require_once $root . '/lib/EmailService.php';
require_once $root . '/lib/ApprovalToken.php';
require_once $root . '/lib/RateLimit.php';
