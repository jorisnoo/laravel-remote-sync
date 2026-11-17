<?php

namespace Noo\LaravelRemoteSync\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Applies the filter_users config after a pull: only users whose email
 * matches one of the patterns (exact, or with * wildcards) are kept.
 */
class UserFilter
{
    /**
     * @return list<string>|null null when filtering is off
     */
    public static function patterns(): ?array
    {
        $patterns = config('remote-sync.filter_users', false);

        return is_array($patterns) && $patterns !== [] ? array_values($patterns) : null;
    }

    /**
     * Deletes non-matching users in a transaction, and refuses to act when
     * no user would remain.
     *
     * @return array{kept: int, deleted: int, skipped: bool}|null null when filtering is off
     */
    public function apply(): ?array
    {
        $patterns = self::patterns();
        $table = config('auth.providers.users.table', 'users');

        if ($patterns === null || ! Schema::hasTable($table)) {
            return null;
        }

        return DB::transaction(function () use ($patterns, $table) {
            $doomed = DB::table($table)->where(function ($query) use ($patterns) {
                foreach ($patterns as $pattern) {
                    str_contains($pattern, '*')
                        ? $query->where('email', 'not like', str_replace('*', '%', $pattern))
                        : $query->where('email', '!=', $pattern);
                }
            });

            $deleted = $doomed->count();
            $kept = DB::table($table)->count() - $deleted;

            if ($kept === 0 && $deleted > 0) {
                return ['kept' => 0, 'deleted' => 0, 'skipped' => true];
            }

            $doomed->delete();

            return ['kept' => $kept, 'deleted' => $deleted, 'skipped' => false];
        });
    }
}
