<?php

declare(strict_types=1);

namespace Syncdero\Core;

class Config
{
    protected array $data = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function load(string $configPath): self
    {
        if (!file_exists($configPath)) {
            throw new \RuntimeException("Archivo de configuración no encontrado: {$configPath}");
        }

        $config = require $configPath;
        if (!is_array($config)) {
            throw new \RuntimeException("El archivo de configuración debe retornar un array PHP");
        }

        return new self($config);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function getEnvironments(): array
    {
        return $this->data['environments'] ?? [];
    }

    public function getEnvironment(string $name): ?array
    {
        $envs = $this->getEnvironments();
        return $envs[$name] ?? null;
    }

    public function getMigrationsDir(): string
    {
        return $this->data['migrations_dir'] ?? './migrations';
    }

    public function getBackupDir(): string
    {
        return $this->data['backup_dir'] ?? './backups';
    }

    public function getRepoDir(): string
    {
        return $this->data['repo_dir'] ?? './.git';
    }

    public function getApplyPassword(): string
    {
        return $this->data['apply_password'] ?? '';
    }
}
