<?php

declare(strict_types=1);

final class RuntimeConfig
{
    public static function fromEnvironment(): ?array
    {
        $docker = self::env('TRAKFOG_DOCKER');
        $dbHost = self::env('TRAKFOG_DB_HOST');

        if ($docker !== '1' && $dbHost === '') {
            return null;
        }

        $required = [
            'TRAKFOG_DB_HOST',
            'TRAKFOG_DB_NAME',
            'TRAKFOG_DB_USER',
            'TRAKFOG_DB_PASSWORD',
            'TRAKFOG_APP_KEY',
        ];

        foreach ($required as $name) {
            if (self::env($name) === '') {
                throw new RuntimeException('Docker-Konfiguration unvollständig: ' . $name . ' fehlt.');
            }
        }

        return [
            'app' => [
                'name' => 'TrakFog',
                'version' => self::version(),
                'timezone' => self::env('TRAKFOG_TIMEZONE', 'Europe/Berlin'),
                'base_url' => rtrim(self::env('TRAKFOG_BASE_URL'), '/'),
                'app_key' => self::env('TRAKFOG_APP_KEY'),
                'runtime' => 'docker',
            ],
            'db' => [
                'host' => self::env('TRAKFOG_DB_HOST'),
                'port' => (int)self::env('TRAKFOG_DB_PORT', '3306'),
                'name' => self::env('TRAKFOG_DB_NAME'),
                'user' => self::env('TRAKFOG_DB_USER'),
                'pass' => self::env('TRAKFOG_DB_PASSWORD'),
                'charset' => 'utf8mb4',
            ],
        ];
    }

    public static function docker(): bool
    {
        return self::env('TRAKFOG_DOCKER') === '1';
    }

    private static function env(string $name, string $default = ''): string
    {
        $value = getenv($name);
        return $value === false ? $default : trim((string)$value);
    }

    private static function version(): string
    {
        $value = trim((string)@file_get_contents(dirname(__DIR__) . '/VERSION'));
        return $value !== '' ? $value : 'dev';
    }
}
