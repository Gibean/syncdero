<?php

declare(strict_types=1);

namespace Syncdero\Core;

use Syncdero\Drivers\DriverFactory;

class Config
{
    protected array $data = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function loadEnv(string $envPath): void
    {
        if (!file_exists($envPath) || !is_readable($envPath)) {
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (strpos($line, '=') === false) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if (preg_match('/^"(.*)"$/', $value, $m) || preg_match("/^'(.*)'$/", $value, $m)) {
                $value = $m[1];
            }

            if (getenv($key) === false) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
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
        $envs = $this->data['environments'] ?? [];
        $servers = $this->data['servers'] ?? [];

        foreach ($servers as $serverKey => $serverConfig) {
            if (!empty($serverConfig['auto_discover'])) {
                try {
                    $driver = \Syncdero\Drivers\DriverFactory::create($serverConfig);
                    if ($driver->testConnection()) {
                        $databases = $driver->discoverDatabases();
                        foreach ($databases as $dbName) {
                            $dynKey = "{$serverKey}__{$dbName}";
                            $envs[$dynKey] = array_merge($serverConfig, [
                                'label' => ($serverConfig['label'] ?? $serverKey) . ": {$dbName}",
                                'database' => $dbName,
                                'is_discovered' => true,
                                'server_key' => $serverKey,
                            ]);
                        }
                    }
                } catch (\Throwable $e) {
                    // Silencioso si el servidor remoto no responde
                }
            }
        }

        return $envs;
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

    public function getServers(): array
    {
        return $this->data['servers'] ?? [];
    }

    public function getApplyPassword(): string
    {
        return $this->data['apply_password'] ?? '';
    }

    public function isTeamMode(): bool
    {
        $envVal = getenv('SYNCDERO_TEAM_MODE');
        if ($envVal !== false) {
            return filter_var($envVal, FILTER_VALIDATE_BOOLEAN);
        }
        return (bool)($this->data['team_mode'] ?? false);
    }

    public function getReferenceEnv(): string
    {
        return getenv('SYNCDERO_REFERENCE_ENV') ?: ($this->data['reference_env'] ?? 'pg_main');
    }

    public function getAppKey(): string
    {
        return getenv('SYNCDERO_APP_KEY') ?: ($this->data['app_key'] ?? '32_caracteres_aleatorios_clave!!');
    }

    public function saveServer(string $serverKey, array $serverConfig, string $savePath): void
    {
        $this->data['servers'][$serverKey] = $serverConfig;
        $this->persist($savePath);
    }

    public function deleteServer(string $serverKey, string $savePath): void
    {
        unset($this->data['servers'][$serverKey]);
        $this->persist($savePath);
    }

    public function addEnvironment(string $envKey, array $envConfig, string $savePath): void
    {
        // Nunca persistir claves derivadas del auto-discovery
        unset($envConfig['is_discovered'], $envConfig['server_key']);
        $this->data['environments'][$envKey] = $envConfig;
        $this->persist($savePath);
    }

    protected function persist(string $filePath): void
    {
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($this->data, true) . ";\n";
        file_put_contents($filePath, $content, LOCK_EX);
    }
}

