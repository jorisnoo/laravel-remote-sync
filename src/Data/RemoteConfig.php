<?php

namespace Noo\LaravelRemoteSync\Data;

readonly class RemoteConfig
{
    public function __construct(
        public string $name,
        public string $host,
        public string $path,
        public bool $pushAllowed = false,
        public ?bool $isAtomic = null,
    ) {}

    public function workingPath(): string
    {
        if ($this->isAtomic === true && ! str_ends_with($this->path, '/current')) {
            return "{$this->path}/current";
        }

        return $this->path;
    }

    public function storagePath(): string
    {
        return "{$this->workingPath()}/storage";
    }

    public function withAtomicDetection(bool $isAtomic): self
    {
        return new self(
            $this->name,
            $this->host,
            $this->path,
            $this->pushAllowed,
            $isAtomic,
        );
    }
}
