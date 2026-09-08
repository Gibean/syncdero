<?php

declare(strict_types=1);

namespace Syncdero\Drivers;

use Syncdero\Core\SqlSplitter;

abstract class AbstractDriver implements DriverInterface
{
    protected ?\PDO $pdo = null;
    protected array $config = [];
    protected string $metaTable = '_syncdero_meta';
    private bool $metaColumnsChecked = false;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->metaTable = $config['meta_table'] ?? '_syncdero_meta';
    }

    public function testConnection(): bool
    {
        try {
            $this->getPdo();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function getNextBatchNumber(): int
    {
        $pdo = $this->getPdo();
        $this->ensureMetadataTable();

        $stmt = $pdo->query("SELECT COALESCE(MAX(batch), 0) + 1 AS next_batch FROM {$this->metaTable}");
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return (int)($row['next_batch'] ?? 1);
    }

    public function recordMigration(
        string $name,
        string $hash,
        int $batch,
        int $durationMs,
        string $appliedBy = 'system',
        ?string $content = null,
        ?string $gitCommit = null,
        ?string $gitBranch = null,
        ?string $note = null,
    ): void {
        $pdo = $this->getPdo();
        $this->ensureMetadataTable();

        $stmt = $pdo->prepare("
            INSERT INTO {$this->metaTable}
            (version_hash, migration_name, batch, status, applied_by, git_commit, git_branch,
             execution_time_ms, applied_at, migration_content, note)
            VALUES (?, ?, ?, 'applied', ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $hash,
            $name,
            $batch,
            $appliedBy,
            $gitCommit,
            $gitBranch,
            $durationMs,
            gmdate('Y-m-d H:i:s'),
            $content,
            $note,
        ]);
    }

    public function removeMigrationRecord(string $name): void
    {
        $pdo = $this->getPdo();
        $this->ensureMetadataTable();

        $stmt = $pdo->prepare("DELETE FROM {$this->metaTable} WHERE migration_name = ?");
        $stmt->execute([$name]);
    }

    public function getAppliedMigrations(): array
    {
        $pdo = $this->getPdo();
        $this->ensureMetadataTable();

        $stmt = $pdo->query("
            SELECT migration_name AS name, version_hash AS hash, batch, applied_at, status,
                   execution_time_ms, migration_content, git_commit, git_branch, note
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
                'execution_time_ms' => (int)($row['execution_time_ms'] ?? 0),
                'content' => $row['migration_content'] ?? null,
                'git_commit' => $row['git_commit'] ?? null,
                'git_branch' => $row['git_branch'] ?? null,
                'note' => $row['note'] ?? null,
            ];
        }

        return $migrations;
    }

    /**
     * Ejecuta un script SQL (posiblemente multi-sentencia) dentro de una
     * transacción cuando el motor lo soporta.
     *
     * Nota: en MySQL/MariaDB el DDL auto-commita implícitamente, por lo que la
     * transacción no cubre sentencias de estructura en ese motor.
     */
    public function executeSql(string $sql): void
    {
        $pdo = $this->getPdo();
        $statements = SqlSplitter::split($sql);

        if ($statements === []) {
            return;
        }

        $inTransaction = false;
        try {
            $pdo->beginTransaction();
            $inTransaction = true;
        } catch (\PDOException) {
            // El motor o el driver no permiten transacciones aquí: continuar directo.
            $inTransaction = false;
        }

        try {
            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }
            if ($inTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($inTransaction) {
                try {
                    $pdo->rollBack();
                } catch (\Throwable) {
                    // Ignorar fallos de rollback (conexión ya perdida).
                }
            }
            throw $e;
        }
    }

    /**
     * Ejecuta el contenido de un dump SQL sentencia a sentencia, sin transacción
     * (los dumps contienen DDL y directivas SET/LOCK que no son transaccionables).
     */
    protected function applySqlDump(string $dumpContent): void
    {
        $pdo = $this->getPdo();
        $statements = SqlSplitter::split($dumpContent);

        if ($statements === []) {
            throw new \RuntimeException('El archivo de respaldo no contiene sentencias ejecutables.');
        }

        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
    }

    /**
     * Evolución del schema de la tabla de metadatos para bases creadas con
     * versiones anteriores de SYNCDERO. Los ALTER fallidos (columna ya
     * existente) se ignoran silenciosamente.
     */
    protected function ensureMetaColumns(): void
    {
        if ($this->metaColumnsChecked) {
            return;
        }
        $this->metaColumnsChecked = true;

        $pdo = $this->getPdo();
        foreach ($this->metaColumnDefinitions() as $columnSql) {
            try {
                $pdo->exec("ALTER TABLE {$this->metaTable} ADD COLUMN {$columnSql}");
            } catch (\Throwable) {
                // Columna ya existente o motor que no soporta ADD COLUMN aquí.
            }
        }
    }

    /**
     * Definiciones de columnas nuevas de _syncdero_meta (tipo adaptado por motor).
     *
     * @return array<int, string>
     */
    protected function metaColumnDefinitions(): array
    {
        return [
            'migration_content TEXT NULL',
            'note VARCHAR(500) NULL',
        ];
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
