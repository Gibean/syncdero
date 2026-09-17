<?php

declare(strict_types=1);

namespace Syncdero\Drivers;

use Syncdero\Core\SqlSplitter;

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
            applied_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            migration_content TEXT NULL,
            note TEXT NULL
        );";

        $pdo->exec($sql);
        $this->ensureMetaColumns();
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

    public function restoreBackup(string $backupFile): array
    {
        $srcPath = $this->config['database'] ?? '';
        if ($srcPath === ':memory:') {
            return [
                'success' => false,
                'message' => 'No se puede restaurar sobre una base de datos en memoria.',
                'rewound' => [],
            ];
        }
        if (!file_exists($backupFile)) {
            return [
                'success' => false,
                'message' => "El archivo de respaldo no existe: {$backupFile}",
                'rewound' => [],
            ];
        }

        // Registros actuales (para calcular las migraciones rebobinadas)
        $before = $this->testConnection() ? $this->getAppliedMigrations() : [];

        // Cerrar la conexión activa antes de reemplazar el archivo
        $this->pdo = null;

        if (!copy($backupFile, $srcPath)) {
            return [
                'success' => false,
                'message' => 'Error al reemplazar el archivo SQLite con el respaldo.',
                'rewound' => [],
            ];
        }

        return [
            'success' => true,
            'message' => 'Base de datos SQLite restaurada desde el snapshot.',
            'rewound' => $this->computeRewound($before),
        ];
    }

    public function exportSchema(bool $withData = false): string
    {
        $pdo = $this->getPdo();
        $dump = "-- Snapshot de esquema SQLite generado por SYNCDERO\n-- " . gmdate('Y-m-d H:i:s') . " UTC\n\n";

        $stmt = $pdo->query("
            SELECT name, type, sql
            FROM sqlite_master
            WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%'
            ORDER BY type DESC, name ASC
        ");

        $tables = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $dump .= $row['sql'] . ";\n\n";
            if ($row['type'] === 'table') {
                $tables[] = $row['name'];
            }
        }

        if ($withData && !empty($tables)) {
            foreach ($tables as $table) {
                $select = $pdo->query("SELECT * FROM \"{$table}\"");
                while ($row = $select->fetch(\PDO::FETCH_ASSOC)) {
                    $cols = array_keys($row);
                    $vals = array_map(
                        static fn ($v): string => ($v === null) ? 'NULL' : $pdo->quote((string) $v),
                        array_values($row)
                    );
                    $dump .= "INSERT INTO \"{$table}\" (\""
                        . implode('", "', $cols) . "\") VALUES ("
                        . implode(', ', $vals) . ");\n";
                }
                $dump .= "\n";
            }
        }

        return $dump;
    }

    public function createDatabase(string $databaseName): void
    {
        // En SQLite una "base de datos" es un archivo: la ruta destino define la rama.
        $dir = dirname($this->config['database'] ?? '');
        if ($dir === '' || $dir === '.') {
            throw new \RuntimeException(
                'La base de datos SQLite activa no tiene una ruta de archivo válida para crear ramas.'
            );
        }
        $targetPath = $dir . DIRECTORY_SEPARATOR . $databaseName . '.sqlite';

        $pdo = new \PDO("sqlite:{$targetPath}", null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('SELECT 1');
    }

    public function copySchemaTo(string $targetDatabase, bool $withData = false): void
    {
        $srcPath = $this->config['database'] ?? '';
        if (!file_exists($srcPath)) {
            throw new \RuntimeException("La base de datos SQLite de origen no existe: {$srcPath}");
        }

        $dir = dirname($srcPath);
        $targetPath = str_ends_with($targetDatabase, '.sqlite')
            ? $dir . DIRECTORY_SEPARATOR . $targetDatabase
            : $dir . DIRECTORY_SEPARATOR . $targetDatabase . '.sqlite';

        if (file_exists($targetPath)) {
            throw new \RuntimeException("La rama destino ya existe: {$targetPath}");
        }

        if ($withData) {
            // Copia completa del archivo (esquema + datos)
            if (!copy($srcPath, $targetPath)) {
                throw new \RuntimeException("Error copiando la base SQLite hacia {$targetPath}");
            }
            return;
        }

        // Solo esquema: aplicar DDL en un archivo nuevo
        $ddl = $this->exportSchema();
        $targetPdo = new \PDO("sqlite:{$targetPath}", null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        foreach (\Syncdero\Core\SqlSplitter::split($ddl) as $statement) {
            $targetPdo->exec($statement);
        }
    }
}
