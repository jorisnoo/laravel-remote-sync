<?php

namespace Noo\LaravelRemoteSync\Support;

class StoragePath
{
    /**
     * Validate a storage-relative sync path. Returns an error message or
     * null when the path is safe. The allowlist keeps paths contained in
     * storage/ and free of shell metacharacters, since they end up inside
     * remote rsync specs.
     */
    public static function validate(string $path): ?string
    {
        if (str_contains($path, '..')) {
            return "Invalid path '{$path}': must stay within the storage directory.";
        }

        if (! preg_match('#^[A-Za-z0-9](?:[A-Za-z0-9._-]|/(?!/))*$#', $path)) {
            return "Invalid path '{$path}': only letters, numbers, dots, dashes, underscores and single slashes are allowed.";
        }

        return null;
    }

    public static function normalize(string $path): string
    {
        return trim($path, '/');
    }
}
