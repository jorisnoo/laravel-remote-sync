<?php

namespace Noo\LaravelRemoteSync\Remotes;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class Connection
{
    public const SSH_OPTIONS = ['-o', 'StrictHostKeyChecking=yes', '-o', 'BatchMode=yes', '-o', 'UpdateHostKeys=no'];

    public function __construct(public readonly Remote $remote) {}

    public static function shell(): string
    {
        return 'ssh '.implode(' ', self::SSH_OPTIONS);
    }

    public function run(string $command, ?int $timeout = null): ProcessResult
    {
        return Process::timeout($timeout ?? $this->remoteTimeout())->run([
            'ssh',
            ...self::SSH_OPTIONS,
            '--',
            $this->remote->host,
            $command,
        ]);
    }

    public function artisan(RemoteInfo $info, string $arguments, ?int $timeout = null): ProcessResult
    {
        $php = escapeshellarg($info->phpBinary ?? 'php');
        $path = escapeshellarg($info->workingPath);

        return $this->run("cd {$path} && {$php} artisan {$arguments}", $timeout);
    }

    public function remoteTimeout(): int
    {
        return (int) config('remote-sync.timeouts.remote', 300);
    }

    public function transferTimeout(): int
    {
        return (int) config('remote-sync.timeouts.transfer', 1800);
    }

    public function checkHostKey(): void
    {
        $result = Process::timeout(10)->run([
            'ssh',
            ...self::SSH_OPTIONS,
            '-o', 'ConnectTimeout=5',
            '--',
            $this->remote->host,
            'exit',
        ]);

        if ($result->successful()) {
            return;
        }

        $error = trim($result->errorOutput());

        if (str_contains($error, 'REMOTE HOST IDENTIFICATION HAS CHANGED')) {
            throw new RuntimeException(__('remote-sync::messages.errors.host_key_changed', ['host' => $this->remote->hostname()]));
        }

        if (str_contains($error, 'Host key verification failed')) {
            throw new RuntimeException(__('remote-sync::messages.errors.host_key_unknown', ['host' => $this->remote->host]));
        }

        throw new RuntimeException("SSH connection to [{$this->remote->host}] failed: ".($error ?: "exit status {$result->exitCode()}"));
    }
}
