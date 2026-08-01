<?php

declare(strict_types=1);

namespace Syncdero\Drivers;

interface DriverInterface
{
    /**
     * Retorna el identificador del driver (ej: pgsql, mysql, sqlite).
     */
    public function getName(): string;

    /**
     * Prueba la conexión a la base de datos.
     */
    public function testConnection(): bool;

    /**
     * Obtiene la instancia activa de PDO.
     */
    public function getPdo(): \PDO;

    /**
     * Asegura que exista la tabla interna de metadatos de SYNCDERO.
     */
    public function ensureMetadataTable(): void;

    /**
     * Obtiene el listado de migraciones registradas en la base de datos con sus metadatos (hash, batch, fecha, etc.).
     * @return array<string, array{name: string, hash: string, batch: int, applied_at: string, status: string}>
     */
    public function getAppliedMigrations(): array;

    /**
     * Registra una migración ejecutada en la tabla de metadatos.
     */
    public function recordMigration(string $name, string $hash, int $batch, int $durationMs, string $appliedBy = 'system'): void;

    /**
     * Elimina el registro de una migración revertida.
     */
    public function removeMigrationRecord(string $name): void;

    /**
     * Obtiene el número del siguiente batch atómico.
     */
    public function getNextBatchNumber(): int;

    /**
     * Ejecuta una migración en formato SQL plano dentro de una transacción cuando el motor lo soporte.
     */
    public function executeSql(string $sql): void;

    /**
     * Crea un snapshot / dump de respaldo de la base de datos en una ruta específica.
     * @return array{success: bool, path: string, message: string}
     */
    public function createBackup(string $targetDirectory): array;
}
