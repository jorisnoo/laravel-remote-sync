<?php

namespace Noo\LaravelRemoteSync\Sync;

use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Symfony\Component\Console\Formatter\OutputFormatter;

class PlanRenderer
{
    protected const LIST_CAP = 20;

    protected Factory $components;

    public function __construct(protected OutputStyle $output)
    {
        $this->components = new Factory($output);
    }

    public function render(Plan $plan, Endpoint $source, Endpoint $target, string $remote): void
    {
        $this->output->newLine();
        $this->components->info(($plan->direction === Direction::Pull ? 'Pull from ' : 'Push to ').$remote);

        if ($plan->database) {
            $this->renderDatabase($plan->database, $source, $target);
        }

        if ($plan->files) {
            $this->renderFiles($plan->files, $target);
        }

        foreach ($plan->warnings() as $warning) {
            $this->components->warn($warning);
        }

        $this->components->warn("The {$target->label()} {$plan->scope()} listed above will be replaced.");
    }

    protected function renderDatabase(DatabasePlan $database, Endpoint $source, Endpoint $target): void
    {
        $this->output->writeln("  <options=bold>Database</>  ({$source->info()->driver}, {$source->label()} -> {$target->label()})");

        $this->tables('Replace', $database->replace);
        $this->tables('Recreate empty', $database->empty);
        $this->tables('Keep as is', $database->preserve);
        $this->tables('Drop', $database->drop);
        $this->detail('Backup first', $database->backup ?? 'skipped (--no-backup)');
        $this->detail('Afterwards', 'migrate, cache:clear');

        $this->output->newLine();
    }

    protected function renderFiles(FilesPlan $files, Endpoint $target): void
    {
        $paths = implode(', ', array_map(fn (string $path) => "storage/{$path}", $files->paths()));
        $this->output->writeln("  <options=bold>Files</>  ({$paths})");

        $this->detail('Transfer', count($files->transfers()).' files');
        $this->list($files->transfers());

        $deletions = $files->deletions();

        if ($files->delete) {
            $this->detail('Delete', count($deletions).' files');
            $this->list($deletions);
        } elseif ($deletions !== []) {
            $this->detail('Delete', 'none (--delete would remove '.count($deletions)." files only on {$target->label()})");
        }

        $this->output->newLine();
    }

    /**
     * @param  list<string>  $tables
     */
    protected function tables(string $label, array $tables): void
    {
        if ($tables !== []) {
            $this->detail($label, count($tables).' tables');
            $this->list($tables);
        }
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

        $line = implode(', ', array_slice($items, 0, self::LIST_CAP));

        if (count($items) > self::LIST_CAP) {
            $line .= ' (+'.(count($items) - self::LIST_CAP).' more)';
        }

        $this->output->writeln('    <fg=gray>'.OutputFormatter::escape($line).'</>');
    }
}
