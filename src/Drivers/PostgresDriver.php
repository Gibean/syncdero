<?php

declare(strict_types=1);

namespace Syncdero\Drivers;

use Syncdero\Core\SqlSplitter;

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
        $dbname = $this->config['database'] ?: 'postgres';
        $user = $this->config['user'] ?? 'postgres';
        $password = $this->config['password'] ?? '';
        $timeout = (int)($this->config['timeout'] ?? 5);

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};connect_timeout={$timeout}";
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
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
                SELECT datname
                FROM pg_database
                WHERE datistemplate = false AND datname NOT IN ('postgres')
                ORDER BY datname ASC
            ");

            $databases = [];
            while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
                $databases[] = $row[0];
            }
            return $databases;
        } catch (\Throwable) {
            return [];
        }
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
            applied_at TIMESTAMP WITHOUT TIME ZONE DEFAULT (NOW() AT TIME ZONE 'utc'),
            migration_content TEXT NULL,
            note VARCHAR(500) NULL
        );";

        $pdo->exec($sql);
        $this->ensureMetaColumns();

        // Retrocompatibilidad con tabla 'migrations' de Laravel
        $this->syncLaravelMigrationsIfPresent();
    }

    protected function metaColumnDefinitions(): array
    {
        return [
            'migration_content TEXT NULL',
            'note VARCHAR(500) NULL',
        ];
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
        } catch (\Throwable) {
            // Silencioso
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
        $port = $this->config['port'] ?? 5432;
        $user = $this->config['user'] ?? 'postgres';
        $pass = $this->config['password'] ?? '';

        $pgDump = $this->findBinary(['pg_dump']);
        if ($pgDump !== null) {
            // --inserts genera INSERTs en lugar de bloques COPY (restaurable vía PDO)
            $args = ['-h', (string) $host, '-p', (string) $port, '-U', (string) $user, '--inserts', '--no-owner', '--no-privileges', (string) $dbname];
            $env = $pass !== '' ? ['PGPASSWORD' => $pass] : [];

            $result = $this->runProcess($pgDump, $args, $targetPath, $env);
            if ($result === 0 && file_exists($targetPath) && filesize($targetPath) > 0) {
                return [
                    'success' => true,
                    'path' => $targetPath,
                    'message' => 'Respaldo generado con éxito vía pg_dump',
                ];
            }
        }

        // Fallback PHP: exportar schema + datos vía information_schema y PDO
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
                'message' => 'No se pudo generar respaldo: ' . $e->getMessage(),
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

            // Los dumps pg_dump comienzan vaciando objetos: intentar drop de tablas
            // existentes antes de aplicar para evitar conflictos de CREATE.
            $this->dropAllTables();
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
        // Intentar pg_dump nativo (DDL completo y fiel al motor)
        $host = $this->config['host'] ?? '127.0.0.1';
        $port = $this->config['port'] ?? 5432;
        $user = $this->config['user'] ?? 'postgres';
        $pass = $this->config['password'] ?? '';
        $dbname = $this->config['database'] ?? 'db';

        $pgDump = $this->findBinary(['pg_dump']);
        if ($pgDump !== null) {
            $tmpFile = tempnam(sys_get_temp_dir(), 'syncdero_schema_');
            if ($tmpFile !== false) {
                $args = ['-h', (string) $host, '-p', (string) $port, '-U', (string) $user, '--schema-only', '--no-owner', '--no-privileges', (string) $dbname];
                $env = $pass !== '' ? ['PGPASSWORD' => $pass] : [];

                $code = $this->runProcess($pgDump, $args, $tmpFile, $env);
                $schema = ($code === 0) ? (string) file_get_contents($tmpFile) : '';
                @unlink($tmpFile);

                if ($schema !== '') {
                    if ($withData) {
                        $schema .= "\n\n" . $this->exportTableData();
                    }
                    return $schema;
                }
            }
        }

        // Fallback PHP vía information_schema (mejor esfuerzo)
        $dump = "-- Snapshot PostgreSQL generado por SYNCDERO (fallback information_schema)\n-- " . gmdate('Y-m-d H:i:s') . " UTC\n\n";
        $dump .= $this->buildCreateTablesFromInformationSchema();
        if ($withData) {
            $dump .= $this->exportTableData();
        }
        return $dump;
    }

    /**
     * Genera sentencias CREATE TABLE a partir de information_schema
     * (fallback sin pg_dump; cubre columnas, NOT NULL, defaults y PK simples).
     */
    protected function buildCreateTablesFromInformationSchema(): string
    {
        $pdo = $this->getPdo();
        $dump = '';

        $tables = $pdo->query("
            SELECT table_name
            FROM information_schema.tables
            WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
            ORDER BY table_name ASC
        ");

        $tableNames = [];
        while ($row = $tables->fetch(\PDO::FETCH_NUM)) {
            $tableNames[] = $row[0];
        }

        foreach ($tableNames as $table) {
            $columnsSql = [];
            $pkColumns = [];

            $colsStmt = $pdo->prepare("
                SELECT column_name, data_type, character_maximum_length, is_nullable, column_default
                FROM information_schema.columns
                WHERE table_schema = 'public' AND table_name = ?
                ORDER BY ordinal_position ASC
            ");
            $colsStmt->execute([$table]);

            while ($col = $colsStmt->fetch(\PDO::FETCH_ASSOC)) {
                $type = strtolower($col['data_type']);
                if ($type === 'character varying') {
                    $type = 'varchar(' . ((int) $col['character_maximum_length'] ?: 255) . ')';
                } elseif ($type === 'integer' && str_contains((string) $col['column_default'], 'nextval')) {
                    $type = 'serial';
                } elseif ($type === 'timestamp without time zone') {
                    $type = 'timestamp';
                } elseif ($type === 'character') {
                    $type = 'char(' . ((int) $col['character_maximum_length'] ?: 1) . ')';
                }

                $definition = '"' . $col['column_name'] . '" ' . $type;
                if (($col['is_nullable'] ?? 'YES') === 'NO') {
                    $definition .= ' NOT NULL';
                }
                if (!empty($col['column_default']) && !str_contains((string) $col['column_default'], 'nextval')) {
                    $definition .= ' DEFAULT ' . $col['column_default'];
                }
                $columnsSql[] = $definition;
            }

            // Clave primaria
            $pkStmt = $pdo->prepare("
                SELECT a.attname
                FROM pg_index i
                JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
                JOIN pg_class c ON c.oid = i.indrelid
                WHERE c.relname = ? AND i.indisprimary
            ");
            $pkStmt->execute([$table]);
            while ($pk = $pkStmt->fetch(\PDO::FETCH_NUM)) {
                $pkColumns[] = '"' . $pk[0] . '"';
            }
            if ($pkColumns !== []) {
                $columnsSql[] = 'PRIMARY KEY (' . implode(', ', $pkColumns) . ')';
            }

            $dump .= 'DROP TABLE IF EXISTS "' . $table . '" CASCADE;' . "\n";
            $dump .= 'CREATE TABLE "' . $table . '" (' . "\n    " . implode(",\n    ", $columnsSql) . "\n);\n\n";
        }

        return $dump;
    }

    /**
     * Genera INSERTs de todas las tablas públicas (respaldo con datos vía PDO).
     */
    protected function exportTableData(): string
    {
        $pdo = $this->getPdo();
        $inserts = '';

        $tables = $pdo->query("
            SELECT table_name
            FROM information_schema.tables
            WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
            ORDER BY table_name ASC
        ");

        while ($tableRow = $tables->fetch(\PDO::FETCH_NUM)) {
            $table = $tableRow[0];
            $select = $pdo->query('SELECT * FROM "' . $table . '"');
            while ($row = $select->fetch(\PDO::FETCH_ASSOC)) {
                $columns = array_keys($row);
                $values = array_map(
                    static fn ($v): string => ($v === null) ? 'NULL' : $pdo->quote((string) $v),
                    array_values($row)
                );
                $inserts .= 'INSERT INTO "' . $table . '" ("' . implode('", "', $columns) . '") VALUES (' . implode(', ', $values) . ");\n";
            }
        }

        return $inserts === '' ? '' : "\n" . $inserts;
    }

    public function createDatabase(string $databaseName): void
    {
        $pdo = $this->getPdo();
        $safeName = $this->sanitizeIdentifier($databaseName);
        $pdo->exec("CREATE DATABASE {$safeName}");
    }

    public function copySchemaTo(string $targetDatabase, bool $withData = false): void
    {
        $sourceDb = $this->config['database'] ?? '';
        if ($sourceDb === '') {
            throw new \RuntimeException('El entorno PostgreSQL de origen no tiene base de datos seleccionada.');
        }

        $safeTarget = $this->sanitizeIdentifier($targetDatabase);

        // Conectar a la BD destino (misma configuración de servidor, otra BD)
        $targetConfig = $this->config;
        $targetConfig['database'] = $safeTarget;
        $targetDriver = new self($targetConfig);

        // Aplicar DDL de origen en destino
        $ddl = $this->exportSchema();
        foreach (\Syncdero\Core\SqlSplitter::split($ddl) as $statement) {
            // Ignorar directivas no-DDL de los dumps pg_dump
            if (preg_match('/^(SET|COMMENT|SELECT pg_catalog|\\\\)/i', $statement)) {
                continue;
            }
            $targetDriver->getPdo()->exec($statement);
        }

        if ($withData) {
            $pdo = $this->getPdo();
            $tables = $pdo->query("
                SELECT table_name
                FROM information_schema.tables
                WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
            ");
            while ($tableRow = $tables->fetch(\PDO::FETCH_NUM)) {
                $table = $tableRow[0];
                $insert = $pdo->prepare('INSERT INTO "' . $table . '" SELECT * FROM "' . $table . '"');
                // Copiar fila a fila a través de PHP (sin dblink)
                $rows = $pdo->query('SELECT * FROM "' . $table . '"')->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $columns = array_keys($row);
                    $values = array_map(
                        static fn ($v): string => ($v === null) ? 'NULL' : $pdo->quote((string) $v),
                        array_values($row)
                    );
                    $targetDriver->getPdo()->exec(
                        'INSERT INTO "' . $table . '" ("' . implode('", "', $columns) . '") VALUES (' . implode(', ', $values) . ')'
                    );
                }
                unset($insert, $rows);
            }
        }
    }

    /**
     * Elimina todas las tablas del schema público (pre-restore).
     */
    protected function dropAllTables(): void
    {
        $pdo = $this->getPdo();
        $pdo->exec("
            DO \$\$ DECLARE r RECORD;
            BEGIN
                FOR r IN (SELECT tablename FROM pg_tables WHERE schemaname = 'public') LOOP
                    EXECUTE 'DROP TABLE IF EXISTS public.\"' || r.tablename || '\" CASCADE';
                END LOOP;
            END \$\$;
        ");
    }
}
