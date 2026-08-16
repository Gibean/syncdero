<?php

declare(strict_types=1);

namespace Syncdero\Drivers;

class SqliteDriver extends AbstractDriver
{
    public function getName(): string
    {
        return 'sqlite';
    }

    public function getPdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $path = $this->config['database'] ?? ':memory:';
        if ($path !== ':memory:') {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }

        $dsn = "sqlite:{$path}";
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];

        $this->pdo = new \PDO($dsn, null, null, $options);
        return $this->pdo;
    }

    public function discoverDatabases(): array
    {
        $db = $this->config['database'] ?? '';
        return ($db !== '' && $db !== ':memory:') ? [basename($db)] : [];
    }


    public function ensureMetadataTable(): void
    {
        $pdo = $this->getPdo();
        $sql = "CREATE TABLE IF NOT EXISTS {$this->metaTable} (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            version_hash TEXT NOT NULL,
            migration_name TEXT NOT NULL UNIQUE,
            batch INTEGER NOT NULL,
            status TEXT DEFAULT 'applied',
            applied_by TEXT DEFAULT 'system',
            git_commit TEXT NULL,
            git_branch TEXT NULL,
            execution_time_ms INTEGER DEFAULT 0,
            applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );";

        $pdo->exec($sql);
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

        $srcPath = $this->config['database'] ?? '';
        if ($srcPath === ':memory:' || !file_exists($srcPath)) {
            return [
                'success' => false,
                'path' => '',
                'message' => 'No se puede respaldar una base de datos SQLite en memoria o inexistente',
            ];
        }

        $timestamp = date('Ymd_His');
        $filename = 'backup_sqlite_' . basename($srcPath) . "_{$timestamp}.sqlite";
        $targetPath = rtrim($targetDirectory, '/\\') . DIRECTORY_SEPARATOR . $filename;

        if (copy($srcPath, $targetPath)) {
            return [
                'success' => true,
                'path' => $targetPath,
                'message' => 'Copia atómica SQLite creada con éxito',
            ];
        }

        return [
            'success' => false,
            'path' => '',
            'message' => 'Error al copiar archivo SQLite',
        ];
    }
}
