<?php

namespace Noo\LaravelRemoteSync\Remotes;

readonly class Remote
{
    public function __construct(
        public string $name,
        public string $host,
        public string $path,
        public bool $push = false,
        public ?string $phpBinary = null,
    ) {}

    public function hostname(): string
    {
        if (str_contains($this->host, '@')) {
            return explode('@', $this->host, 2)[1];
        }

        return $this->host;
    }
}
