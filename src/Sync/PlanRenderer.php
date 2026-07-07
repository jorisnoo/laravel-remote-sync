<?php

namespace Noo\LaravelRemoteSync\Sync;

use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Noo\LaravelRemoteSync\Remotes\Remote;
use Noo\LaravelRemoteSync\Remotes\RemoteInfo;
use Noo\LaravelRemoteSync\Snapshots\Importer;

class PlanRenderer
{
    protected const LIST_CAP = 20;

    protected Factory $components;

    public function __construct(protected OutputStyle $output)
    {
        $this->components = new Factory($output);
    }

    public function render(Remote $remote, RemoteInfo $info, SyncPlan $plan): void
    {
        $verb = $plan->direction === Direction::Pull ? 'Pull from' : 'Push to';

        $this->output->newLine();
        $this->components->info("{$verb} {$remote->name} ({$remote->host}:{$info->workingPath})");

        if ($plan->database) {
            $this->renderDatabase($info, $plan);
        }

        if ($plan->files) {
            $this->renderFiles($remote, $plan);
        }

        $target = $plan->direction === Direction::Pull ? 'Local' : "Remote [{$remote->name}]";
        $this->components->warn("{$target} {$plan->scopeSummary()} listed above will be replaced.");
    }

    protected function renderDatabase(RemoteInfo $info, SyncPlan $plan): void
    {
        $localDriver = Importer::localDriver();
        $remoteDriver = $info->driver ?? 'unknown';

        [$from, $to] = $plan->direction === Direction::Pull
            ? [$remoteDriver, $localDriver]
            : [$localDriver, $remoteDriver];

        $this->output->writeln("  <options=bold>Database</>  ({$from} -> {$to})");

        $syncLabel = $plan->full ? 'Import (full)' : 'Import';
        $this->detail($syncLabel, count($plan->tablesToSync).' tables');
        $this->list($plan->tablesToSync);

        if ($plan->tablesToTruncate !== []) {
            $truncateLabel = $plan->direction === Direction::Pull
                ? 'Truncate after import'
                : 'Preserved on remote';
            $this->detail($truncateLabel, count($plan->tablesToTruncate).' tables');
            $this->list($plan->tablesToTruncate);
        }

        if ($plan->filterUsers !== null) {
            $this->components->warn(
                'Users filter: keep '.implode(', ', $plan->filterUsers).' - all other users are deleted after import.'
            );
        }

        if ($plan->direction === Direction::Pull) {
            $this->detail('Local backup', $plan->backup ? $plan->backupName : 'skipped (--no-backup)');
            $this->detail('After import', 'migrate --force, optimize:clear');

            if ($plan->full) {
                $this->components->warn('All local tables will be DROPPED before import.');
            }

            if ($plan->full && ! $plan->backup) {
                $this->components->warn('Running --full without a backup: a failed import cannot be undone.');
            }
        } else {
            $this->detail('Remote backup', $plan->backup ? $plan->backupName : 'skipped (--no-backup)');
            $this->detail('After load', 'migrate --force on the remote');
        }

        $this->output->newLine();
    }

    protected function renderFiles(Remote $remote, SyncPlan $plan): void
    {
        $paths = implode(', ', array_map(fn (string $path) => "storage/{$path}", $plan->paths));

        $this->output->writeln("  <options=bold>Files</>  ({$paths})");

        $transfers = [];

        foreach ($plan->fileChanges as $changes) {
            $transfers = [...$transfers, ...$changes->transfers];
        }

        $this->detail('Transfer', count($transfers).' files');
        $this->list($transfers);

        $deletions = $plan->totalDeletions();
        $deleteTarget = $plan->direction === Direction::Pull ? 'locally' : "on [{$remote->name}]";

        if ($plan->delete && $deletions > 0) {
            $this->components->warn("Delete {$deleteTarget}: {$deletions} files");

            $deletionList = [];

            foreach ($plan->fileChanges as $changes) {
                $deletionList = [...$deletionList, ...$changes->deletions];
            }

            $this->list($deletionList);
        } elseif ($plan->delete) {
            $this->detail("Delete {$deleteTarget}", '0 files');
        } elseif ($deletions > 0) {
            $only = $plan->direction === Direction::Pull ? 'local-only' : 'remote-only';
            $this->detail("Delete {$deleteTarget}", "0 (pass --delete to remove {$deletions} {$only} files)");
        } else {
            $this->detail("Delete {$deleteTarget}", '0 files');
        }

        $this->output->newLine();
    }

    protected function detail(string $label, string $value): void
    {
        $this->components->twoColumnDetail("    {$label}", $value);
    }

    /**
     * @param  list<string>  $items
     */
    protected function list(array $items): void
    {
        if ($items === []) {
            return;
        }

        $shown = array_slice($items, 0, self::LIST_CAP);
        $line = implode(', ', $shown);

        if (count($items) > self::LIST_CAP) {
            $line .= ' (+'.(count($items) - self::LIST_CAP).' more)';
        }

        $this->output->writeln("    <fg=gray>{$line}</>");
    }
}
