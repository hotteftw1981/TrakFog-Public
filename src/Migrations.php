<?php

declare(strict_types=1);

final class Migrations
{
    public static function statements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn(string $s): bool => $s !== ''));
    }

    public static function runFile(PDO $pdo, string $file): void
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException('Could not read migration file: ' . $file);
        }
        foreach (self::statements($sql) as $statement) {
            $pdo->exec($statement);
        }
    }

    public static function applied(PDO $pdo): array
    {
        try {
            return $pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable) {
            return [];
        }
    }
}
