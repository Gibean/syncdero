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

    protected ?string $activeProjectKey = null;

    /**
     * Obtiene todos los proyectos configurados. Si no existen 'projects',
     * genera un proyecto por defecto retrocompatible ('default').
     *
     * @return array<string, array>
     */
    public function getProjects(): array
    {
        if (isset($this->data['projects']) && is_array($this->data['projects']) && !empty($this->data['projects'])) {
            return $this->data['projects'];
        }

        // Proyecto por defecto encapsulado
        return [
            'default' => [
                'name' => $this->data['project_name'] ?? 'Proyecto Principal',
                'repo_dir' => $this->data['repo_dir'] ?? './.git',
                'migrations_dir' => $this->data['migrations_dir'] ?? './migrations',
                'backup_dir' => $this->data['backup_dir'] ?? './backups',
                'reference_env' => $this->data['reference_env'] ?? 'advdev_postgres__db_main',
                'environments' => $this->data['environments'] ?? [],
                'servers' => $this->data['servers'] ?? [],
            ],
        ];
    }

    public function getActiveProjectKey(): string
    {
        if ($this->activeProjectKey !== null) {
            return $this->activeProjectKey;
        }

        $projects = $this->getProjects();
        $this->activeProjectKey = array_key_first($projects) ?? 'default';
        return $this->activeProjectKey;
    }

    public function setActiveProjectKey(string $key): void
    {
        $projects = $this->getProjects();
        if (isset($projects[$key])) {
            $this->activeProjectKey = $key;
            $this->resolvedEnvironments = null; // invalidar caché de entornos
        }
    }

    public function getActiveProject(): array
    {
        $projects = $this->getProjects();
        $key = $this->getActiveProjectKey();
        return $projects[$key] ?? reset($projects) ?: [];
    }

    protected ?array $resolvedEnvironments = null;

    public function getEnvironments(): array
    {
        if ($this->resolvedEnvironments !== null) {
            return $this->resolvedEnvironments;
        }

        $activeProj = $this->getActiveProject();
        // Si el proyecto activo declara 'environments', usar solo las de su proyecto
        $envs = array_key_exists('environments', $activeProj)
            ? $activeProj['environments']
            : ($this->data['environments'] ?? []);

        // Si el proyecto activo declara 'servers', usar solo los de su proyecto
        $servers = array_key_exists('servers', $activeProj)
            ? $activeProj['servers']
            : ($this->data['servers'] ?? []);

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

        $this->resolvedEnvironments = $envs;
        return $envs;
    }

    public function getEnvironment(string $name): ?array
    {
        $envs = $this->getEnvironments();
        return $envs[$name] ?? null;
    }

    public function getMigrationsDir(): string
    {
        $activeProj = $this->getActiveProject();
        return $activeProj['migrations_dir'] ?? ($this->data['migrations_dir'] ?? './migrations');
    }

    public function getBackupDir(): string
    {
        $activeProj = $this->getActiveProject();
        return $activeProj['backup_dir'] ?? ($this->data['backup_dir'] ?? './backups');
    }

    public function getRepoDir(): string
    {
        $activeProj = $this->getActiveProject();
        return $activeProj['repo_dir'] ?? ($this->data['repo_dir'] ?? './.git');
    }

    public function getServers(): array
    {
        $activeProj = $this->getActiveProject();
        return $activeProj['servers'] ?? ($this->data['servers'] ?? []);
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
        $activeProj = $this->getActiveProject();
        return getenv('SYNCDERO_REFERENCE_ENV') ?: ($activeProj['reference_env'] ?? ($this->data['reference_env'] ?? 'advdev_postgres__db_main'));
    }

    public function getAppKey(): string
    {
        return getenv('SYNCDERO_APP_KEY') ?: ($this->data['app_key'] ?? '32_caracteres_aleatorios_clave!!');
    }

    /**
     * Determina si el proyecto tiene múltiples entornos configurados.
     */
    public function hasMultipleEnvironments(): bool
    {
        return count($this->getEnvironments()) > 1;
    }

    /**
     * Determina si el proyecto opera con espejos/réplicas o con una base de datos principal de referencia.
     */
    public function hasMirrors(): bool
    {
        $envs = $this->getEnvironments();
        if (count($envs) <= 1) {
            return false;
        }

        // Si hay entornos descubiertos o marcados como espejos o un reference_env existente
        $ref = $this->getReferenceEnv();
        if (isset($envs[$ref])) {
            return true;
        }

        foreach ($envs as $e) {
            if (!empty($e['is_discovered']) || !empty($e['is_my_mirror']) || !empty($e['assigned_user'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Obtiene los metadatos o dueños configurados para los entornos.
     */
    public function getEnvironmentOwners(): array
    {
        return $this->data['environment_owners'] ?? [
            'advdev_postgres__db_g' => ['user' => 'dev_gisvel', 'label' => 'db_g (Gisvel)', 'role' => 'dev'],
            'advdev_postgres__db_c' => ['user' => 'dev_c', 'label' => 'db_c (Carlos)', 'role' => 'dev'],
            'advdev_postgres__db_j' => ['user' => 'dev_j', 'label' => 'db_j (Juan)', 'role' => 'dev'],
            'advdev_postgres__db_x' => ['user' => 'dev_x', 'label' => 'db_x (Template)', 'role' => 'dev'],
            'advdev_postgres__db_y' => ['user' => 'dev_y', 'label' => 'db_y', 'role' => 'dev'],
            'advdev_postgres__db_main' => ['label' => 'db_main (Producción)', 'role' => 'prod', 'protected' => true],
            'advdev_postgres__db_staging' => ['label' => 'db_staging (Pre-prod)', 'role' => 'staging', 'protected' => 'warn'],
        ];
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

    public function saveProject(string $projectKey, array $projectData, string $savePath): void
    {
        if (!isset($this->data['projects'])) {
            $this->data['projects'] = $this->getProjects();
        }
        $this->data['projects'][$projectKey] = $projectData;
        $this->persist($savePath);
    }

    public function deleteProject(string $projectKey, string $savePath): void
    {
        if (isset($this->data['projects'][$projectKey])) {
            unset($this->data['projects'][$projectKey]);
            $this->persist($savePath);
        }
    }

    protected function persist(string $filePath): void
    {
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($this->data, true) . ";\n";
        file_put_contents($filePath, $content, LOCK_EX);
    }
}

