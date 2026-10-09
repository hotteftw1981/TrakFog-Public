<?php

declare(strict_types=1);

final class Crypto
{
    private static function key(array $config): string
    {
        $value = (string)($config['app']['app_key'] ?? '');
        if (!str_starts_with($value, 'base64:')) {
            throw new RuntimeException('Invalid app key.');
        }
        $raw = base64_decode(substr($value, 7), true);
        if ($raw === false || strlen($raw) < 32) {
            throw new RuntimeException('Invalid app key length.');
        }
        return substr($raw, 0, 32);
    }

    public static function encrypt(string $plain, array $config): string
    {
        $key = self::key($config);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Encryption failed.');
        }
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(?string $encoded, array $config): ?string
    {
        if (!$encoded) {
            return null;
        }
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key($config), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? null : $plain;
    }
}
