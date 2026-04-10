<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

$token = (string) ($_GET['token'] ?? '');
$action = (string) ($_GET['action'] ?? '');
$site = rtrim(config('SITE_URL', base_url()), '/');

if ($token === '' || !in_array($action, ['approve', 'reject'], true)) {
    header('Location: ' . $site . '/en');
    exit;
}

$id = ApprovalToken::verify($token, require_env('JWT_SECRET'));
if ($id === null) {
    header('Location: ' . $site . '/en?approval=invalid');
    exit;
}

if (!Db::enabled()) {
    header('Location: ' . $site . '/en?approval=error');
    exit;
}

try {
    $st = Db::pdo()->prepare('SELECT * FROM pending_registrations WHERE id = ? AND status = ? LIMIT 1');
    $st->execute([$id, 'pending']);
    $registration = $st->fetch();
    if (!$registration) {
        header('Location: ' . $site . '/en?approval=gone');
        exit;
    }

    if ($action === 'reject') {
        $up = Db::pdo()->prepare('UPDATE pending_registrations SET status = ? WHERE id = ?');
        $up->execute(['rejected', $id]);
        EmailService::sendRejectionEmail($registration['email'], $registration['contact_name']);
        header('Location: ' . $site . '/en?approval=rejected');
        exit;
    }

    $fv = FullVendor::createCustomer([
        'user_id' => 1,
        'language_id' => '1',
        'business_name' => $registration['company_name'],
        'name' => $registration['contact_name'],
        'tax_id' => $registration['tax_id'] ?? '',
        'email' => $registration['email'],
        'phone' => $registration['phone'] ?? '',
        'cell_phone' => $registration['mobile'] ?? '',
        'term_id' => 1,
        'group_id' => 1,
        'commercial_address' => $registration['address'] ?? '',
    ]);
    if (($fv['status'] ?? '') !== '1') {
        header('Location: ' . $site . '/en?approval=fv_error');
        exit;
    }
    $info = $fv['info'] ?? [];
    $customerId = is_array($info) ? (int) ($info['customer_id'] ?? 0) : 0;
    $up = Db::pdo()->prepare('UPDATE pending_registrations SET status = ?, fullvendor_customer_id = ? WHERE id = ?');
    $up->execute(['approved', $customerId ?: null, $id]);
    EmailService::sendApprovalEmail($registration['email'], $registration['contact_name']);
    header('Location: ' . $site . '/en?approval=ok');
} catch (Throwable $e) {
    error_log('[approve] ' . $e->getMessage());
    if (class_exists('AppLog', false)) {
        AppLog::appException($e, 'approve_registration');
    }
    header('Location: ' . $site . '/en?approval=error');
}
exit;
