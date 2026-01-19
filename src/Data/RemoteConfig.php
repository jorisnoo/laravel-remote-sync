<?php

namespace Noo\LaravelRemoteSync\Data;

readonly class RemoteConfig
{
    public function __construct(
        public string $name,
        public string $host,
        public string $path,
        public bool $pushAllowed = false,
    ) {}

    public function storagePath(): string
    {
        return "{$this->path}/storage";
    }

    public function currentPath(): string
    {
        return "{$this->path}/current";
    }
}
