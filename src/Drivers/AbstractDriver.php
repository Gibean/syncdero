<?php

declare(strict_types=1);

namespace Syncdero\Drivers;

abstract class AbstractDriver implements DriverInterface
{
    protected ?\PDO $pdo = null;
    protected array $config = [];
    protected string $metaTable = '_syncdero_meta';

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
        } catch (\Throwable $e) {
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

    public function recordMigration(string $name, string $hash, int $batch, int $durationMs, string $appliedBy = 'system'): void
    {
        $pdo = $this->getPdo();
        $this->ensureMetadataTable();

        $stmt = $pdo->prepare("
            INSERT INTO {$this->metaTable} 
            (version_hash, migration_name, batch, status, applied_by, execution_time_ms, applied_at)
            VALUES (?, ?, ?, 'applied', ?, ?, ?)
        ");

        $stmt->execute([
            $hash,
            $name,
            $batch,
            $appliedBy,
            $durationMs,
            gmdate('Y-m-d H:i:s')
        ]);
    }

    public function removeMigrationRecord(string $name): void
    {
        $pdo = $this->getPdo();
        $this->ensureMetadataTable();

        $stmt = $pdo->prepare("DELETE FROM {$this->metaTable} WHERE migration_name = ?");
        $stmt->execute([$name]);
    }

    public function executeSql(string $sql): void
    {
        $pdo = $this->getPdo();
        $pdo->exec($sql);
    }
}
