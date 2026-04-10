<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/api/_api_log_init.php';

header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/lib/api_dispatch.php';
hv_api_dispatch();
