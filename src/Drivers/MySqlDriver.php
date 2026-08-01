<?php

declare(strict_types=1);

namespace Syncdero\Drivers;

class MySqlDriver extends AbstractDriver
{
    public function getName(): string
    {
        return 'mysql';
    }

    public function getPdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $host = $this->config['host'] ?? '127.0.0.1';
        $port = $this->config['port'] ?? 3306;
        $dbname = $this->config['database'] ?? '';
        $charset = $this->config['charset'] ?? 'utf8mb4';
        $user = $this->config['user'] ?? 'root';
        $password = $this->config['password'] ?? '';

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $this->pdo = new \PDO($dsn, $user, $password, $options);
        return $this->pdo;
    }

    public function ensureMetadataTable(): void
    {
        $pdo = $this->getPdo();
        $sql = "CREATE TABLE IF NOT EXISTS {$this->metaTable} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            version_hash VARCHAR(64) NOT NULL,
            migration_name VARCHAR(255) NOT NULL UNIQUE,
            batch INT NOT NULL,
            status VARCHAR(20) DEFAULT 'applied',
            applied_by VARCHAR(100) DEFAULT 'system',
            git_commit VARCHAR(40) NULL,
            git_branch VARCHAR(100) NULL,
            execution_time_ms INT DEFAULT 0,
            applied_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_syncdero_batch (batch)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $pdo->exec($sql);

        // Retrocompatibilidad con tabla 'migrations' de Laravel si existe
        $this->syncLaravelMigrationsIfPresent();
    }

    protected function syncLaravelMigrationsIfPresent(): void
    {
        $pdo = $this->getPdo();
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'migrations'");
            if ($stmt->rowCount() > 0) {
                // Si la tabla migrations existe, importamos registros que no estén en _syncdero_meta
                $pdo->exec("
                    INSERT IGNORE INTO {$this->metaTable} (version_hash, migration_name, batch, status, applied_by, applied_at)
                    SELECT 'imported_legacy', migration, batch, 'applied', 'laravel_legacy', NOW()
                    FROM migrations
                ");
            }
        } catch (\Throwable $e) {
            // Silencioso si no hay tabla migrations
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
        $port = $this->config['port'] ?? 3306;
        $user = $this->config['user'] ?? 'root';
        $pass = $this->config['password'] ?? '';

        // Intentar usar mysqldump o mariadb-dump del sistema si existe
        $dumpCmd = "mysqldump -h {$host} -P {$port} -u {$user} " . ($pass !== '' ? "-p\"{$pass}\"" : '') . " {$dbname} > \"{$targetPath}\" 2>&1";
        @exec($dumpCmd, $output, $code);

        if ($code === 0 && file_exists($targetPath) && filesize($targetPath) > 0) {
            return [
                'success' => true,
                'path' => $targetPath,
                'message' => 'Respaldo generado con éxito vía mysqldump',
            ];
        }

        // Fallback ligero en PHP (exportar schema básico)
        try {
            $pdo = $this->getPdo();
            $dumpContent = "-- SYNCDERO MySQL/MariaDB Snapshot: {$dbname}\n-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
            $tablesStmt = $pdo->query("SHOW FULL TABLES WHERE Table_Type = 'BASE TABLE'");
            while ($row = $tablesStmt->fetch(\PDO::FETCH_NUM)) {
                $table = $row[0];
                $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
                $createRow = $createStmt->fetch(\PDO::FETCH_NUM);
                $dumpContent .= "DROP TABLE IF EXISTS `{$table}`;\n" . $createRow[1] . ";\n\n";
            }
            file_put_contents($targetPath, $dumpContent);
            return [
                'success' => true,
                'path' => $targetPath,
                'message' => 'Respaldo de esquema generado con éxito (PHP driver fallback)',
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'path' => '',
                'message' => 'Error al generar respaldo: ' . $e->getMessage(),
            ];
        }
    }
}
