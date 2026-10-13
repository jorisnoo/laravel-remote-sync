<?php

use Noo\LaravelRemoteSync\Database\Credentials;
use Noo\LaravelRemoteSync\Database\Dumper;
use Spatie\DbDumper\Databases\MySql;
use Spatie\DbDumper\Databases\PostgreSql;
use Spatie\DbDumper\DbDumper;

function credentials(string $driver): Credentials
{
    return new Credentials($driver, 'db.internal', null, '', 'acme', 'acme_user', 'secret');
}

function dumpCommand(DbDumper $dumper): string
{
    return $dumper instanceof MySql
        ? $dumper->getDumpCommand('/tmp/dump.sql.gz', '/tmp/credentials')
        : $dumper->getDumpCommand('/tmp/dump.sql.gz');
}

describe('Dumper', function () {
    it('dumps MySQL in one transaction, leaving out excluded tables', function () {
        $passes = (new Dumper)->passes(credentials('mysql'), exclude: ['cache'], excludeData: []);

        expect($passes)->toHaveCount(1)
            ->and(dumpCommand($passes[0]))
            ->toContain('--single-transaction')
            ->toContain('--ignore-table=acme.cache')
            ->toContain('| gzip >');
    });

    it('adds a structure-only MySQL pass for tables without data', function () {
        [$data, $structure] = (new Dumper)->passes(credentials('mysql'), exclude: [], excludeData: ['cache', 'jobs']);

        expect(dumpCommand($data))->toContain('--ignore-table=acme.cache --ignore-table=acme.jobs')
            ->and(dumpCommand($structure))->toContain('--no-data')->toContain('--tables cache jobs');
    });

    it('dumps PostgreSQL in one pass that replaces tables on load', function () {
        $passes = (new Dumper)->passes(credentials('pgsql'), exclude: ['sessions'], excludeData: ['cache']);

        expect($passes)->toHaveCount(1)->and($passes[0])->toBeInstanceOf(PostgreSql::class)
            ->and(dumpCommand($passes[0]))
            ->toContain('--clean --if-exists --no-owner --no-privileges')
            ->toContain("'--exclude-table-data=cache'")
            ->toContain('-T sessions');
    });

    it('rejects table names that are not plain identifiers', function () {
        expect(fn () => (new Dumper)->passes(credentials('mysql'), exclude: ['cache; rm -rf /'], excludeData: []))
            ->toThrow(InvalidArgumentException::class, "Invalid table name 'cache; rm -rf /'");
    });

    it('refuses drivers it cannot dump', function () {
        expect(fn () => (new Dumper)->passes(credentials('sqlite'), [], []))
            ->toThrow(RuntimeException::class, 'only mysql and pgsql are supported');
    });
});
