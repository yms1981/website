<?php

declare(strict_types=1);

$session = require_account_session($lang, true);
require_once dirname(__DIR__, 2) . '/lib/Messaging.php';
Messaging::requireMessagingRole($session);
if (!Db::enabled()) {
    http_response_code(503);
    exit;
}
Messaging::streamAttachment(Db::pdo(), $session, (string) ($_GET['t'] ?? ''));
