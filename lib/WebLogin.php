<?php

declare(strict_types=1);

/**
 * Login unificado: formulario POST (index) y API JSON (api_dispatch).
 *
 * @return array{success:true, pending:bool, name:string}|array{success:false, error:string}
 */
final class WebLogin
{
    public static function attempt(string $emailRaw, string $password, string $lang): array
    {
        $email = filter_var(trim($emailRaw), FILTER_VALIDATE_EMAIL);
        if (!$email || $password === '') {
            return ['success' => false, 'error' => 'invalid'];
        }

        $localPayload = Auth::tryLocalCredentials((string) $email, $password);
        if ($localPayload !== null) {
            Auth::createSession($localPayload);

            return [
                'success' => true,
                'pending' => false,
                'name' => (string) ($localPayload['name'] ?? ''),
            ];
        }

        if (!fullvendor_api_configured()) {
            return ['success' => false, 'error' => 'invalid'];
        }

        $registration = null;
        if (Db::enabled()) {
            try {
                $st = Db::pdo()->prepare('SELECT * FROM pending_registrations WHERE email = ? LIMIT 1');
                $st->execute([$email]);
                $registration = $st->fetch();
            } catch (Throwable) {
                $registration = null;
            }
        }
        try {
            $res = FullVendor::login((string) $email, $password);
        } catch (Throwable) {
            return ['success' => false, 'error' => 'invalid'];
        }
        if (($res['status'] ?? '') !== '1' || empty($res['info'])) {
            return ['success' => false, 'error' => 'invalid'];
        }
        $user = $res['info'];
        $customerId = 0;
        $isApproved = true;
        if (is_array($registration)) {
            if (($registration['status'] ?? '') === 'approved' && !empty($registration['fullvendor_customer_id'])) {
                $customerId = (int) $registration['fullvendor_customer_id'];
            } elseif (($registration['status'] ?? '') === 'pending') {
                $isApproved = false;
            }
        }
        $emailFv = strtolower(trim((string) ($user['email'] ?? '')));
        $rolIdFv = 3;
        if (Db::enabled() && $emailFv !== '') {
            $stRol = Db::pdo()->prepare(
                'SELECT `rolId` FROM `users` WHERE LOWER(TRIM(`username`)) = ? LIMIT 1'
            );
            $stRol->execute([$emailFv]);
            $rolRow = $stRol->fetch(PDO::FETCH_ASSOC);
            if (is_array($rolRow) && (int) ($rolRow['rolId'] ?? 0) > 0) {
                $rolIdFv = (int) $rolRow['rolId'];
            }
        }
        $localUserIdFv = 0;
        if (Db::enabled() && $emailFv !== '') {
            $stLoc = Db::pdo()->prepare('SELECT `userId` FROM `users` WHERE LOWER(TRIM(`username`)) = ? LIMIT 1');
            $stLoc->execute([$emailFv]);
            $locRow = $stLoc->fetch(PDO::FETCH_ASSOC);
            if (is_array($locRow)) {
                $localUserIdFv = (int) ($locRow['userId'] ?? 0);
            }
        }
        Auth::createSession([
            'userId' => (int) $user['user_id'],
            'localUserId' => $localUserIdFv,
            'companyId' => (int) $user['company_id'],
            'customerId' => $customerId,
            'email' => (string) $user['email'],
            'name' => trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? '')),
            'approved' => $isApproved,
            'rolId' => $rolIdFv,
        ]);
        $name = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));

        return [
            'success' => true,
            'pending' => !$isApproved,
            'name' => $name,
        ];
    }
}
