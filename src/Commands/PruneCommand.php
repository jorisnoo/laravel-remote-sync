<?php

namespace Noo\LaravelRemoteSync\Commands;

use Illuminate\Console\Command;
use Noo\LaravelRemoteSync\Commands\Concerns\InteractsWithRemote;
use Noo\LaravelRemoteSync\Database\Dumps;
use Noo\LaravelRemoteSync\Database\Inspector;
use Noo\LaravelRemoteSync\Sync\Endpoint;
use Noo\LaravelRemoteSync\Sync\LocalEndpoint;

class PruneCommand extends Command
{
    use InteractsWithRemote;

    protected $signature = 'remote-sync:prune
        {remote? : The remote to prune}
        {--local : Only prune local dumps}
        {--remote : Only prune dumps on the remote}
        {--keep=5 : How many of each kind (pre-pull, pre-push, remote-sync) to keep}
        {--dry-run : Show what would be deleted}
        {--f|force : Answer the confirmation with yes}';

    protected $description = 'Delete old dumps from storage/remote-sync, locally and/or on a remote';

    public function handle(Inspector $inspector): int
    {
        $keep = max(0, (int) $this->option('keep'));
        $endpoints = [];

        if ($this->option('local') || ! $this->option('remote')) {
            $endpoints[] = new LocalEndpoint($inspector);
        }

        if ($this->option('remote') || ! $this->option('local')) {
            $remote = $this->resolveRemote();
            $endpoint = $remote ? $this->connect($remote) : null;

            if ($endpoint === null) {
                return self::FAILURE;
            }

            $endpoints[] = $endpoint;
        }

        $victims = array_filter(array_map(fn (Endpoint $endpoint) => self::prunable($endpoint->dumps(), $keep), $endpoints));

        if ($victims === []) {
            $this->components->info("Nothing to prune (keeping the {$keep} newest of each kind).");

            return self::SUCCESS;
        }

        foreach ($victims as $index => $dumps) {
            $this->components->info("On {$endpoints[$index]->label()}:");

            foreach ($dumps as $dump) {
                $this->components->twoColumnDetail($dump['name'], date('Y-m-d H:i', $dump['mtime']));
            }
        }

        $count = array_sum(array_map('count', $victims));

        if ($this->option('dry-run')) {
            $this->components->info('Dry run: nothing was deleted.');

            return self::SUCCESS;
        }

        if (! $this->confirmed("Delete {$count} dumps?")) {
            return $this->isInteractive() ? self::SUCCESS : self::FAILURE;
        }

        $failed = 0;

        foreach ($victims as $index => $dumps) {
            foreach ($dumps as $dump) {
                if (! $endpoints[$index]->remove($endpoints[$index]->dumpPath($dump['name']))) {
                    $this->components->warn("Could not delete {$dump['name']} on {$endpoints[$index]->label()}.");
                    $failed++;
                }
            }
        }

        $this->components->info('Deleted '.($count - $failed).' dumps.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Everything but the newest $keep dumps of each kind.
     *
     * @param  list<array{name: string, mtime: int}>  $dumps  newest first
     * @return list<array{name: string, mtime: int}>
     */
    public static function prunable(array $dumps, int $keep): array
    {
        return collect($dumps)
            ->groupBy(fn (array $dump) => Dumps::kind($dump['name']))
            ->flatMap(fn ($group) => $group->slice($keep))
            ->values()
            ->all();
    }
}
