<?php

namespace Noo\LaravelRemoteSync\Remotes;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

class Connection
{
    /**
     * Never wait at an invisible password or passphrase prompt: keys must be
     * usable without input (ssh-agent, or no passphrase).
     */
    public const SSH_OPTIONS = ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10'];

    public function __construct(public readonly Remote $remote) {}

    public function run(string $command, ?int $timeout = null): ProcessResult
    {
        return Process::timeout($timeout ?? self::remoteTimeout())
            ->run(['ssh', ...self::SSH_OPTIONS, $this->remote->host, $command]);
    }

    /**
     * The ssh command rsync should use, with the same options.
     */
    public static function shell(): string
    {
        return implode(' ', ['ssh', ...self::SSH_OPTIONS]);
    }

    public static function remoteTimeout(): int
    {
        return (int) config('remote-sync.timeouts.remote', 300);
    }

    public static function transferTimeout(): int
    {
        return (int) config('remote-sync.timeouts.transfer', 1800);
    }

    public function checkHostKey(): HostKey
    {
        $error = Process::timeout(15)
            ->run(['ssh', ...self::SSH_OPTIONS, $this->remote->host, 'exit'])
            ->errorOutput();

        return match (true) {
            str_contains($error, 'REMOTE HOST IDENTIFICATION HAS CHANGED') => HostKey::Changed,
            str_contains($error, 'Host key verification failed') => HostKey::Unknown,
            str_contains($error, 'Permission denied') => HostKey::AuthFailed,
            default => HostKey::Trusted,
        };
    }

    public function hostFingerprints(): ?string
    {
        $result = Process::timeout(10)->run(
            'ssh-keyscan -t ed25519,rsa,ecdsa '.escapeshellarg($this->remote->hostname()).' 2>/dev/null | ssh-keygen -lf -'
        );

        return $result->successful() && trim($result->output()) !== '' ? trim($result->output()) : null;
    }

    public function acceptHostKey(): bool
    {
        $result = Process::timeout(15)->run([
            'ssh', '-o', 'StrictHostKeyChecking=accept-new', ...self::SSH_OPTIONS, $this->remote->host, 'exit',
        ]);

        return ! str_contains($result->errorOutput(), 'Host key verification failed');
    }
}
