<?php

declare(strict_types=1);

namespace Syncdero\Drivers;

interface DriverInterface
{
    /**
     * Retorna el identificador del driver (ej: pgsql, mysql, sqlite, sqlsrv).
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
     * Obtiene el listado de migraciones registradas en la base de datos con sus
     * metadatos (hash, batch, fecha, contenido aplicado, enlace git, etc.).
     *
     * @return array<string, array{name: string, hash: string, batch: int, applied_at: string, status: string, execution_time_ms: int, content: ?string, git_commit: ?string, git_branch: ?string, note: ?string}>
     */
    public function getAppliedMigrations(): array;

    /**
     * Registra una migración ejecutada en la tabla de metadatos, junto con su
     * contenido, nota y enlace al commit/rama git del proyecto si aplica.
     */
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
    ): void;

    /**
     * Elimina el registro de una migración revertida.
     */
    public function removeMigrationRecord(string $name): void;

    /**
     * Obtiene el número del siguiente batch atómico.
     */
    public function getNextBatchNumber(): int;

    /**
     * Ejecuta una migración en formato SQL plano dentro de una transacción
     * cuando el motor lo soporte (división segura multi-sentencia).
     */
    public function executeSql(string $sql): void;

    /**
     * Crea un snapshot / dump de respaldo de la base de datos en una ruta específica.
     *
     * @return array{success: bool, path: string, message: string}
     */
    public function createBackup(string $targetDirectory): array;

    /**
     * Restaura un snapshot de respaldo en la base de datos del entorno.
     * La restauración rebobina también la tabla de metadatos (_syncdero_meta),
     * devolviendo el estado de versionado al momento del snapshot.
     *
     * @param string $backupFile Ruta absoluta del archivo de respaldo.
     * @return array{success: bool, message: string, rewound: array<int, string>}
     */
    public function restoreBackup(string $backupFile): array;

    /**
     * Exporta el DDL del esquema actual (CREATE TABLE, índices, etc.) en SQL plano,
     * opcionalmente incluyendo los datos (INSERTs). Usado para versionar el estado
     * vivo de una BD como archivo de migración.
     */
    public function exportSchema(bool $withData = false): string;

    /**
     * Descubre las bases de datos disponibles en el servidor conectado (estilo HeidiSQL).
     *
     * @return array<int, string>
     */
    public function discoverDatabases(): array;

    /**
     * Crea una nueva base de datos (rama de BD) en el servidor conectado.
     * Usado por el comando branch para bifurcar un entorno.
     */
    public function createDatabase(string $databaseName): void;

    /**
     * Copia el esquema (y datos si $withData) desde la BD activa hacia otra BD del mismo servidor.
     * Usado por el comando branch para crear ramas de bases de datos.
     */
    public function copySchemaTo(string $targetDatabase, bool $withData = false): void;
}
