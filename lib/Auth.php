<?php

declare(strict_types=1);

final class Auth
{
    private const SESSION_KEY = 'hv_auth';

    /** Cookie JWT antigua: se borra al crear/destruir sesión para no mezclar con $_SESSION. */
    private const LEGACY_JWT_COOKIE = 'hv-session';

    private const COOKIE_LOGGED_IN = 'hv-logged-in';
    private const MAX_AGE = 604800;

    private static function cookieSecure(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    }

    /**
     * Inicia la sesión PHP (cookie de sesión HttpOnly). Llamar en bootstrap para peticiones web.
     */
    public static function ensureSession(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (session_status() === PHP_SESSION_DISABLED) {
            return;
        }
        $secure = self::cookieSecure();
        session_set_cookie_params([
            'lifetime' => self::MAX_AGE,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /**
     * Login contra la tabla `users` (password con password_hash).
     * userId y customerId en la sesión usan los IDs de FullVendor cuando existen en `customers` (user_id, customeridfullvendor).
     * localUserId es siempre el PK de `users` y se usa para el carrito portal (clientes rol 3) cuando userId FV viene vacío.
     *
     * @return array{userId:int,localUserId:int,companyId:int,customerId:int,email:string,name:string,approved:bool,rolId:int}|null
     */
    public static function tryLocalCredentials(string $email, string $password): ?array
    {
        if (!Db::enabled() || $password === '') {
            return null;
        }
        $emailNorm = strtolower(trim($email));
        if ($emailNorm === '') {
            return null;
        }

        $st = Db::pdo()->prepare(
            'SELECT userId, username, password, rolId, customerId FROM users WHERE LOWER(TRIM(username)) = ? LIMIT 1'
        );
        $st->execute([$emailNorm]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $hash = (string) ($row['password'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            return null;
        }

        $rolId = (int) ($row['rolId'] ?? 0);
        $localUserId = (int) ($row['userId'] ?? 0);
        $linkId = (int) ($row['customerId'] ?? 0);

        $name = $emailNorm;
        $fvUserId = 0;
        $fvCustomerId = 0;

        if ($rolId === 1) {
            $fvUserId = $localUserId > 0 ? $localUserId : 0;
        } elseif ($rolId === 2 && $linkId > 0) {
            $sl = Db::pdo()->prepare(
                'SELECT `first_name`, `last_name` FROM `usersList` WHERE `user_id` = ? LIMIT 1'
            );
            $sl->execute([$linkId]);
            $srow = $sl->fetch(\PDO::FETCH_ASSOC);
            if (is_array($srow)) {
                $fn = trim((string) ($srow['first_name'] ?? ''));
                $ln = trim((string) ($srow['last_name'] ?? ''));
                $full = trim($fn . ' ' . $ln);
                if ($full !== '') {
                    $name = $full;
                }
            }
            $fvUserId = $linkId;
        } elseif ($rolId === 3 && $linkId > 0) {
            $cst = Db::pdo()->prepare(
                'SELECT `name`, `business_name`, `user_id`, `customeridfullvendor` FROM `customers`
                 WHERE `customeridfullvendor` = ? OR `customer_id` = ? LIMIT 1'
            );
            $cst->execute([$linkId, $linkId]);
            $c = $cst->fetch(\PDO::FETCH_ASSOC);
            if (is_array($c)) {
                $bn = trim((string) ($c['business_name'] ?? ''));
                $nm = trim((string) ($c['name'] ?? ''));
                $name = $bn !== '' ? $bn : ($nm !== '' ? $nm : $name);
                $fvCustomerId = (int) ($c['customeridfullvendor'] ?? 0);
                $uidRaw = trim((string) ($c['user_id'] ?? ''));
                if ($uidRaw !== '') {
                    $firstFv = trim(explode(',', $uidRaw)[0]);
                    if ($firstFv !== '' && ctype_digit($firstFv)) {
                        $fvUserId = (int) $firstFv;
                    }
                }
            }
        }

        $companyId = (int) config('FULLVENDOR_COMPANY_ID', '0');
        $approved = $rolId === 1 || $rolId === 2 || $rolId === 3 || $fvCustomerId > 0;

        return [
            'userId' => $fvUserId,
            'localUserId' => $localUserId,
            'companyId' => $companyId,
            'customerId' => $fvCustomerId,
            'email' => $emailNorm,
            'name' => $name,
            'approved' => $approved,
            'rolId' => $rolId,
        ];
    }

    /** @return array{userId:int,localUserId:int,companyId:int,customerId:int,email:string,name:string,approved:bool,rolId:int}|null */
    public static function getSession(): ?array
    {
        if (PHP_SAPI === 'cli') {
            return null;
        }
        self::ensureSession();
        $raw = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($raw)) {
            return null;
        }

        return [
            'userId' => (int) ($raw['userId'] ?? 0),
            'localUserId' => (int) ($raw['localUserId'] ?? 0),
            'companyId' => (int) ($raw['companyId'] ?? 0),
            'customerId' => (int) ($raw['customerId'] ?? 0),
            'email' => (string) ($raw['email'] ?? ''),
            'name' => (string) ($raw['name'] ?? ''),
            'approved' => !empty($raw['approved']),
            'rolId' => (int) ($raw['rolId'] ?? 0),
        ];
    }

    /** @param array{userId:int,localUserId?:int,companyId:int,customerId:int,email:string,name:string,approved:bool,rolId?:int} $payload */
    public static function createSession(array $payload): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        self::ensureSession();
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = [
            'userId' => (int) $payload['userId'],
            'localUserId' => (int) ($payload['localUserId'] ?? 0),
            'companyId' => (int) $payload['companyId'],
            'customerId' => (int) $payload['customerId'],
            'email' => (string) $payload['email'],
            'name' => (string) $payload['name'],
            'approved' => (bool) $payload['approved'],
            'rolId' => (int) ($payload['rolId'] ?? 0),
        ];
        $now = time();
        $secure = self::cookieSecure();
        setcookie(self::COOKIE_LOGGED_IN, '1', [
            'expires' => $now + self::MAX_AGE,
            'path' => '/',
            'secure' => $secure,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        setcookie(self::LEGACY_JWT_COOKIE, '', [
            'expires' => $now - 3600,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function destroySession(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        self::ensureSession();
        $_SESSION = [];
        $p = session_get_cookie_params();
        $past = time() - 3600;
        setcookie(session_name(), '', [
            'expires' => $past,
            'path' => $p['path'] !== '' ? $p['path'] : '/',
            'domain' => $p['domain'] ?? '',
            'secure' => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
        session_destroy();

        $secure = self::cookieSecure();
        $opts = static function (bool $httponly) use ($past, $secure): array {
            return [
                'expires' => $past,
                'path' => '/',
                'secure' => $secure,
                'httponly' => $httponly,
                'samesite' => 'Lax',
            ];
        };
        setcookie(self::LEGACY_JWT_COOKIE, '', $opts(true));
        setcookie(self::COOKIE_LOGGED_IN, '', $opts(false));
    }

    /**
     * Si hay sesión aprobada pero falta la cookie visible `hv-logged-in=1`, la reenvía (nav JS).
     */
    public static function syncLoggedInIndicatorCookie(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        $s = self::getSession();
        if ($s === null || empty($s['approved'])) {
            return;
        }
        if (($_COOKIE[self::COOKIE_LOGGED_IN] ?? '') === '1') {
            return;
        }
        $now = time();
        $secure = self::cookieSecure();
        setcookie(self::COOKIE_LOGGED_IN, '1', [
            'expires' => $now + self::MAX_AGE,
            'path' => '/',
            'secure' => $secure,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }
}
