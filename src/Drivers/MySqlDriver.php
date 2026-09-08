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
        $timeout = (int)($this->config['timeout'] ?? 5);

        $dsn = "mysql:host={$host};port={$port};charset={$charset}";
        if ($dbname !== '') {
            $dsn .= ";dbname={$dbname}";
        }

        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_TIMEOUT => $timeout,
        ];

        $this->pdo = new \PDO($dsn, $user, $password, $options);
        return $this->pdo;
    }

    public function discoverDatabases(): array
    {
        try {
            $pdo = $this->getPdo();
            $stmt = $pdo->query("
                SHOW DATABASES
                WHERE `Database` NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys')
            ");

            $databases = [];
            while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
                $databases[] = $row[0];
            }
            sort($databases);
            return $databases;
        } catch (\Throwable) {
            return [];
        }
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
            migration_content MEDIUMTEXT NULL,
            note VARCHAR(500) NULL,
            INDEX idx_syncdero_batch (batch)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $pdo->exec($sql);

        // Evolución de schema para tablas creadas por versiones anteriores
        $this->ensureMetaColumns();

        // Retrocompatibilidad con tabla 'migrations' de Laravel si existe
        $this->syncLaravelMigrationsIfPresent();
    }

    protected function metaColumnDefinitions(): array
    {
        return [
            'migration_content MEDIUMTEXT NULL',
            'note VARCHAR(500) NULL',
        ];
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
        } catch (\Throwable) {
            // Silencioso si no hay tabla migrations
        }
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

        // Intentar usar mysqldump o mariadb-dump del sistema si existe (vía proc_open, sin shell)
        $dumpCmd = $this->findBinary(['mysqldump', 'mariadb-dump']);
        if ($dumpCmd !== null) {
            $args = ['-h', (string) $host, '-P', (string) $port, '-u', (string) $user];
            if ($pass !== '') {
                $args[] = "-p{$pass}";
            }
            $args[] = (string) $dbname;

            $result = $this->runProcess($dumpCmd, $args, $targetPath);
            if ($result === 0 && file_exists($targetPath) && filesize($targetPath) > 0) {
                return [
                    'success' => true,
                    'path' => $targetPath,
                    'message' => 'Respaldo generado con éxito vía mysqldump',
                ];
            }
        }

        // Fallback ligero en PHP (exportar schema + datos)
        try {
            $dumpContent = $this->exportSchema(true);
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

    public function restoreBackup(string $backupFile): array
    {
        if (!file_exists($backupFile)) {
            return [
                'success' => false,
                'message' => "El archivo de respaldo no existe: {$backupFile}",
                'rewound' => [],
            ];
        }

        $before = $this->testConnection() ? $this->getAppliedMigrations() : [];

        try {
            $dump = (string) file_get_contents($backupFile);
            $this->applySqlDump($dump);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Error restaurando el respaldo: ' . $e->getMessage(),
                'rewound' => [],
            ];
        }

        return [
            'success' => true,
            'message' => 'Base de datos restaurada desde el snapshot.',
            'rewound' => $this->computeRewound($before),
        ];
    }

    public function exportSchema(bool $withData = false): string
    {
        $pdo = $this->getPdo();
        $dump = "-- Snapshot MySQL/MariaDB generado por SYNCDERO\n-- " . gmdate('Y-m-d H:i:s') . " UTC\n\n";

        $tablesStmt = $pdo->query("SHOW FULL TABLES WHERE Table_Type = 'BASE TABLE'");
        while ($row = $tablesStmt->fetch(\PDO::FETCH_NUM)) {
            $table = $row[0];
            $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
            $createRow = $createStmt->fetch(\PDO::FETCH_NUM);
            $dump .= "DROP TABLE IF EXISTS `{$table}`;\n" . $createRow[1] . ";\n\n";

            if ($withData) {
                $dump .= $this->exportTableData($table);
            }
        }

        return $dump;
    }

    /**
     * Genera las sentencias INSERT de una tabla (para respaldos con datos).
     */
    protected function exportTableData(string $table): string
    {
        $pdo = $this->getPdo();
        $inserts = '';

        $select = $pdo->query("SELECT * FROM `{$table}`");
        while ($row = $select->fetch(\PDO::FETCH_ASSOC)) {
            $columns = array_keys($row);
            $values = array_map(
                static fn ($v): string => ($v === null) ? 'NULL' : $pdo->quote((string) $v),
                array_values($row)
            );
            $inserts .= "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $values) . ");\n";
        }

        return $inserts === '' ? '' : $inserts . "\n";
    }

    public function createDatabase(string $databaseName): void
    {
        $pdo = $this->getPdo();
        $safeName = $this->sanitizeIdentifier($databaseName);
        $pdo->exec("CREATE DATABASE `{$safeName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    public function copySchemaTo(string $targetDatabase, bool $withData = false): void
    {
        $pdo = $this->getPdo();
        $sourceDb = $this->config['database'] ?? '';
        if ($sourceDb === '') {
            throw new \RuntimeException('El entorno MySQL de origen no tiene base de datos seleccionada.');
        }

        $safeTarget = $this->sanitizeIdentifier($targetDatabase);
        $tablesStmt = $pdo->query("SHOW FULL TABLES WHERE Table_Type = 'BASE TABLE'");

        while ($row = $tablesStmt->fetch(\PDO::FETCH_NUM)) {
            $table = $row[0];
            // CREATE TABLE ... LIKE replica estructura e índices sin datos
            $pdo->exec("CREATE TABLE `{$safeTarget}`.`{$table}` LIKE `{$sourceDb}`.`{$table}`");
            if ($withData) {
                $pdo->exec("INSERT INTO `{$safeTarget}`.`{$table}` SELECT * FROM `{$sourceDb}`.`{$table}`");
            }
        }
    }

    /**
     * Sanitiza un identificador para evitar inyección SQL en nombres de BD/tabla.
     */
    protected function sanitizeIdentifier(string $name): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
        return ($clean === '' || $clean === null) ? 'branch_' . time() : $clean;
    }

    /**
     * Localiza un ejecutable en el PATH del sistema.
     *
     * @param array<int, string> $candidates
     */
    protected function findBinary(array $candidates): ?string
    {
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $paths = explode(PATH_SEPARATOR, (string) (getenv('PATH') ?: ''));

        foreach ($candidates as $candidate) {
            foreach ($paths as $dir) {
                if (!is_string($dir) || $dir === '') {
                    continue;
                }
                $exe = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $candidate . ($isWindows ? '.exe' : '');
                if (file_exists($exe)) {
                    return $exe;
                }
            }
        }
        return null;
    }

    /**
     * Ejecuta un proceso redirigiendo su salida estándar a un archivo, sin
     * interpolación de shell (evita inyección de comandos vía credenciales).
     *
     * @param array<int, string> $args
     * @return int Código de salida del proceso.
     */
    protected function runProcess(string $command, array $args, string $stdoutFile): int
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutFile, 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open([$command, ...$args], $descriptors, $pipes);
        if (!is_resource($process)) {
            return -1;
        }

        fclose($pipes[0]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $code = (int) proc_close($process);
        if ($code !== 0 && $stderr !== '') {
            error_log("[SYNCDERO] {$command} stderr: " . trim($stderr));
        }
        return $code;
    }
}
