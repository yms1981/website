<?php

declare(strict_types=1);

final class Db
{
    private static ?\PDO $pdo = null;

    public static function pdo(): \PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $host = config('DB_HOST', '127.0.0.1');
        $name = config('DB_NAME', 'homevalue');
        $user = config('DB_USER', 'homevalue');
        $pass = config('DB_PASS', 'rClLbgCpppLh9AkmNelDnv8jb');
        $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
        self::$pdo = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        return self::$pdo;
    }

    public static function enabled(): bool
    {
        return config('DB_NAME', '') !== '';
    }
}
