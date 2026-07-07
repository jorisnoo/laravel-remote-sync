<?php

namespace Noo\LaravelRemoteSync\Remotes;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

class Connection
{
    public function __construct(public readonly Remote $remote) {}

    public function run(string $command, ?int $timeout = null): ProcessResult
    {
        return Process::timeout($timeout ?? $this->remoteTimeout())->run([
            'ssh',
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

    /**
     * @return string 'ok' if known/not an SSH key issue, 'unknown' if not in known_hosts, 'changed' if key mismatch
     */
    public function checkHostKey(): string
    {
        $result = Process::timeout(10)->run([
            'ssh',
            '-o', 'BatchMode=yes',
            '-o', 'ConnectTimeout=5',
            $this->remote->host,
            'exit',
        ]);

        $error = $result->errorOutput();

        if (str_contains($error, 'REMOTE HOST IDENTIFICATION HAS CHANGED')) {
            return 'changed';
        }

        if (str_contains($error, 'Host key verification failed')) {
            return 'unknown';
        }

        return 'ok';
    }

    public function hostFingerprints(): ?string
    {
        $hostname = $this->remote->hostname();

        $result = Process::timeout(10)->run(
            'ssh-keyscan -t ed25519,rsa,ecdsa '.escapeshellarg($hostname).' 2>/dev/null | ssh-keygen -lf -'
        );

        if ($result->successful() && trim($result->output()) !== '') {
            return trim($result->output());
        }

        return null;
    }

    public function acceptHostKey(): bool
    {
        $result = Process::timeout(15)->run([
            'ssh',
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'ConnectTimeout=10',
            $this->remote->host,
            'exit',
        ]);

        return ! str_contains($result->errorOutput(), 'Host key verification failed');
    }
}
