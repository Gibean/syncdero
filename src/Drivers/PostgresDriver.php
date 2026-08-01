<?php

declare(strict_types=1);

namespace Syncdero\Drivers;

class PostgresDriver extends AbstractDriver
{
    public function getName(): string
    {
        return 'pgsql';
    }

    public function getPdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $host = $this->config['host'] ?? '127.0.0.1';
        $port = $this->config['port'] ?? 5432;
        $dbname = $this->config['database'] ?? '';
        $user = $this->config['user'] ?? 'postgres';
        $password = $this->config['password'] ?? '';

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];

        $this->pdo = new \PDO($dsn, $user, $password, $options);
        return $this->pdo;
    }

    public function ensureMetadataTable(): void
    {
        $pdo = $this->getPdo();
        $sql = "CREATE TABLE IF NOT EXISTS {$this->metaTable} (
            id SERIAL PRIMARY KEY,
            version_hash VARCHAR(64) NOT NULL,
            migration_name VARCHAR(255) NOT NULL UNIQUE,
            batch INT NOT NULL,
            status VARCHAR(20) DEFAULT 'applied',
            applied_by VARCHAR(100) DEFAULT 'system',
            git_commit VARCHAR(40) NULL,
            git_branch VARCHAR(100) NULL,
            execution_time_ms INT DEFAULT 0,
            applied_at TIMESTAMP WITHOUT TIME ZONE DEFAULT (NOW() AT TIME ZONE 'utc')
        );";

        $pdo->exec($sql);

        // Retrocompatibilidad con tabla 'migrations' de Laravel
        $this->syncLaravelMigrationsIfPresent();
    }

    protected function syncLaravelMigrationsIfPresent(): void
    {
        $pdo = $this->getPdo();
        try {
            $stmt = $pdo->query("SELECT to_regclass('public.migrations')");
            $exists = $stmt->fetchColumn();
            if ($exists) {
                // Insertar solo las que no existan en _syncdero_meta
                $pdo->exec("
                    INSERT INTO {$this->metaTable} (version_hash, migration_name, batch, status, applied_by, applied_at)
                    SELECT 'imported_legacy', migration, batch, 'applied', 'laravel_legacy', NOW()
                    FROM migrations
                    ON CONFLICT (migration_name) DO NOTHING
                ");
            }
        } catch (\Throwable $e) {
            // Silencioso
        }
    }

    public function getAppliedMigrations(): array
    {
        $pdo = $this->getPdo();
        $this->ensureMetadataTable();

        $stmt = $pdo->query("
            SELECT migration_name AS name, version_hash AS hash, batch, applied_at, status, execution_time_ms
            FROM {$this->metaTable}
            ORDER BY batch ASC, id ASC
        ");

        $migrations = [];
        while ($row = $stmt->fetch()) {
            $migrations[$row['name']] = [
                'name' => $row['name'],
                'hash' => $row['hash'],
                'batch' => (int)$row['batch'],
                'applied_at' => $row['applied_at'],
                'status' => $row['status'],
                'execution_time_ms' => (int)$row['execution_time_ms'],
            ];
        }

        return $migrations;
    }

    public function createBackup(string $targetDirectory): array
    {
        if (!is_dir($targetDirectory)) {
            @mkdir($targetDirectory, 0755, true);
        }

        $dbname = $this->config['database'] ?? 'db';
        $timestamp = date('Ymd_His');
        $filename = "backup_{$dbname}_{$timestamp}.sql";
        $targetPath = rtrim($targetDirectory, '/\\') . DIRECTORY_SEPARATOR . $filename;

        $host = $this->config['host'] ?? '127.0.0.1';
        $port = $this->config['port'] ?? 5432;
        $user = $this->config['user'] ?? 'postgres';
        $pass = $this->config['password'] ?? '';

        $cmd = "PGPASSWORD=\"{$pass}\" pg_dump -h {$host} -p {$port} -U {$user} -F p -b -v -f \"{$targetPath}\" {$dbname} 2>&1";
        @exec($cmd, $output, $code);

        if ($code === 0 && file_exists($targetPath) && filesize($targetPath) > 0) {
            return [
                'success' => true,
                'path' => $targetPath,
                'message' => 'Respaldo generado con éxito vía pg_dump',
            ];
        }

        return [
            'success' => false,
            'path' => '',
            'message' => 'No se pudo generar respaldo con pg_dump o falló la ejecución.',
        ];
    }
}
