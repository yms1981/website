<?php

declare(strict_types=1);

final class RateLimit
{
    public static function allow(string $key, int $max, int $windowSeconds): bool
    {
        $dir = __DIR__ . '/../data/cache';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $file = $dir . '/rl_' . md5($key) . '.json';
        $now = time();
        $count = 0;
        $reset = $now + $windowSeconds;
        if (is_readable($file)) {
            $j = json_decode(file_get_contents($file) ?: '{}', true);
            if (is_array($j) && isset($j['reset'], $j['count'])) {
                if ($now > (int) $j['reset']) {
                    $count = 0;
                    $reset = $now + $windowSeconds;
                } else {
                    $count = (int) $j['count'];
                    $reset = (int) $j['reset'];
                }
            }
        }
        if ($count >= $max) {
            return false;
        }
        file_put_contents($file, json_encode(['count' => $count + 1, 'reset' => $reset], JSON_THROW_ON_ERROR), LOCK_EX);

        return true;
    }
}
