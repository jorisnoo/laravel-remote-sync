<?php

namespace Noo\LaravelRemoteSync\Database;

use InvalidArgumentException;

/**
 * The package's own dump directory, storage/remote-sync, on whichever side
 * the code runs. Every file in it was written by this package.
 */
class Dumps
{
    public const DIRECTORY = 'remote-sync';

    public static function transferName(): string
    {
        return 'remote-sync-'.date('Y-m-d-His').'-'.bin2hex(random_bytes(3));
    }

    public static function backupName(string $direction): string
    {
        return "pre-{$direction}-".date('Y-m-d-His');
    }

    /**
     * The name without its timestamp: remote-sync, pre-pull or pre-push.
     */
    public static function kind(string $name): string
    {
        return preg_replace('/-\d{4}-\d{2}-\d{2}-.*$/', '', $name) ?? $name;
    }

    /**
     * Path of a dump relative to the storage directory.
     */
    public static function relativePath(string $name): string
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name)) {
            throw new InvalidArgumentException("Invalid dump name '{$name}'.");
        }

        return self::DIRECTORY."/{$name}.sql.gz";
    }

    public static function path(string $name): string
    {
        return storage_path(self::relativePath($name));
    }

    /**
     * Create the directory with a .gitignore, so dumps are never committed:
     * a stock Laravel app does not ignore new folders directly in storage/.
     */
    public static function ensureDirectory(): string
    {
        $directory = storage_path(self::DIRECTORY);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
            file_put_contents("{$directory}/.gitignore", "*\n!.gitignore\n");
        }

        return $directory;
    }

    /**
     * @return list<array{name: string, mtime: int}> newest first
     */
    public static function list(): array
    {
        $dumps = array_map(fn (string $file) => [
            'name' => basename($file, '.sql.gz'),
            'mtime' => (int) filemtime($file),
        ], glob(storage_path(self::DIRECTORY.'/*.sql.gz')) ?: []);

        usort($dumps, fn (array $a, array $b) => $b['mtime'] <=> $a['mtime']);

        return $dumps;
    }
}
