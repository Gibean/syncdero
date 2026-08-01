<?php

declare(strict_types=1);

namespace Syncdero\Core;

class MigrationFile
{
    public function __construct(
        public readonly string $filename,
        public readonly string $basename,
        public readonly string $path,
        public readonly string $type, // 'sql' o 'php'
        public readonly string $hash, // SHA-256
        public readonly string $description,
    ) {}

    public function getContent(): string
    {
        return file_exists($this->path) ? file_get_contents($this->path) : '';
    }
}
