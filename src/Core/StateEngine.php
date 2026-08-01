<?php

declare(strict_types=1);

namespace Syncdero\Core;

use Syncdero\Drivers\DriverInterface;

class StateEngine
{
    protected string $migrationsDir;
    protected string $repoDir;

    public function __construct(string $migrationsDir, string $repoDir = '')
    {
        $this->migrationsDir = rtrim($migrationsDir, '/\\');
        $this->repoDir = rtrim($repoDir, '/\\');
    }

    /**
     * Lee y analiza todos los archivos de migración del directorio (.sql y .php) calculando sus hashes SHA-256.
     * @return array<string, MigrationFile>
     */
    public function getMigrationFiles(): array
    {
        if (!is_dir($this->migrationsDir)) {
            return [];
        }

        $files = [];
        $entries = scandir($this->migrationsDir) ?: [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $this->migrationsDir . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($path)) {
                continue;
            }

            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (!in_array($ext, ['sql', 'php'], true)) {
                continue;
            }

            $basename = pathinfo($entry, PATHINFO_FILENAME);
            $content = file_get_contents($path);
            $hash = hash('sha256', $content);

            $cleanDesc = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $basename);
            $cleanDesc = preg_replace('/^V\d+__/', '', $cleanDesc);
            $description = ucwords(str_replace(['_', '-'], ' ', $cleanDesc));

            $files[$basename] = new MigrationFile(
                filename: $entry,
                basename: $basename,
                path: $path,
                type: $ext,
                hash: $hash,
                description: $description
            );
        }

        ksort($files);
        return $files;
    }

    /**
     * Compara el estado del código fuente vs la base de datos objetivo.
     * 
     * Retorna una estructura con:
     * - applied: Migraciones aplicadas con hash íntegro
     * - pending: Migraciones en disco no corridas aún (Staged/Pending)
     * - divergent: Migraciones corridas cuyo archivo en disco fue modificado (Drift/Conflict)
     * - orphan: Migraciones registradas en la base de datos cuyo archivo fue borrado del disco
     * 
     * @return array{
     *     applied: array,
     *     pending: array,
     *     divergent: array,
     *     orphan: array,
     *     is_synced: bool,
     *     has_divergence: bool
     * }
     */
    public function inspectEnvironment(DriverInterface $driver): array
    {
        $diskFiles = $this->getMigrationFiles();
        $dbMigrations = $driver->getAppliedMigrations();

        $applied = [];
        $pending = [];
        $divergent = [];
        $orphan = [];

        // 1. Revisar cada archivo en disco contra la BD
        foreach ($diskFiles as $basename => $file) {
            if (isset($dbMigrations[$basename])) {
                $dbRecord = $dbMigrations[$basename];
                $dbHash = $dbRecord['hash'];

                // Si viene de migración legacy de Laravel ('imported_legacy'), se asume compatible a menos que se fuerce recalcular
                if ($dbHash === 'imported_legacy' || $dbHash === $file->hash) {
                    $applied[$basename] = [
                        'file' => $file,
                        'db' => $dbRecord,
                        'status' => 'synced',
                    ];
                } else {
                    $divergent[$basename] = [
                        'file' => $file,
                        'db' => $dbRecord,
                        'disk_hash' => $file->hash,
                        'db_hash' => $dbHash,
                        'status' => 'divergent',
                    ];
                }
            } else {
                $pending[$basename] = [
                    'file' => $file,
                    'status' => 'pending',
                ];
            }
        }

        // 2. Detectar huérfanas (están en la BD pero el archivo fue borrado del disco)
        foreach ($dbMigrations as $name => $dbRecord) {
            if (!isset($diskFiles[$name])) {
                $orphan[$name] = [
                    'db' => $dbRecord,
                    'status' => 'orphan',
                ];
            }
        }

        $hasDivergence = count($divergent) > 0;
        $isSynced = count($pending) === 0 && !$hasDivergence && count($orphan) === 0;

        return [
            'applied' => $applied,
            'pending' => $pending,
            'divergent' => $divergent,
            'orphan' => $orphan,
            'is_synced' => $isSynced,
            'has_divergence' => $hasDivergence,
            'total_disk' => count($diskFiles),
            'total_applied' => count($applied) + count($divergent),
        ];
    }

    /**
     * Aplica las migraciones pendientes en el driver proporcionado.
     * 
     * @return array{success: bool, applied_count: int, errors: array, duration_ms: int}
     */
    public function syncPending(DriverInterface $driver, array $pending, string $appliedBy = 'system'): array
    {
        $startTime = microtime(true);
        $nextBatch = $driver->getNextBatchNumber();
        $appliedCount = 0;
        $errors = [];

        foreach ($pending as $item) {
            /** @var MigrationFile $file */
            $file = $item['file'];
            $fileStart = microtime(true);

            try {
                if ($file->type === 'sql') {
                    // Ejecución SQL nativa
                    $driver->executeSql($file->getContent());
                } elseif ($file->type === 'php') {
                    // Adaptador de migración Laravel
                    $this->executeLaravelMigration($driver, $file->path);
                }

                $durationMs = (int)round((microtime(true) - $fileStart) * 1000);
                $driver->recordMigration(
                    name: $file->basename,
                    hash: $file->hash,
                    batch: $nextBatch,
                    durationMs: $durationMs,
                    appliedBy: $appliedBy
                );

                $appliedCount++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'migration' => $file->basename,
                    'error' => $e->getMessage(),
                ];
                // Parar ante el primer error para evitar estado inconsistente
                break;
            }
        }

        $totalDurationMs = (int)round((microtime(true) - $startTime) * 1000);

        return [
            'success' => empty($errors),
            'applied_count' => $appliedCount,
            'errors' => $errors,
            'duration_ms' => $totalDurationMs,
            'batch' => $nextBatch,
        ];
    }

    /**
     * Ejecuta una clase anónima o con nombre de migración de Laravel.
     */
    protected function executeLaravelMigration(DriverInterface $driver, string $filePath): void
    {
        // Proveer conexión PDO activa si la migración de Laravel la necesita
        require_once $filePath;
        $instance = require $filePath;

        if (is_object($instance) && method_exists($instance, 'up')) {
            $instance->up();
            return;
        }

        // Si no retorna una clase anónima, buscar la clase declarada
        $className = $this->extractClassNameFromFile($filePath);
        if ($className && class_exists($className)) {
            $obj = new $className();
            if (method_exists($obj, 'up')) {
                $obj->up();
                return;
            }
        }

        throw new \RuntimeException("No se pudo instanciar el método up() en la migración: {$filePath}");
    }

    protected function extractClassNameFromFile(string $file): ?string
    {
        $content = file_get_contents($file);
        if (preg_match('/class\s+([A-Za-z0-9_]+)\s+extends/', $content, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Lee metadatos del repositorio Git (branch, commit) si está disponible.
     */
    public function getGitInfo(): array
    {
        if (!is_dir($this->repoDir)) {
            return ['branch' => 'unknown', 'commit' => 'unknown'];
        }

        $headFile = $this->repoDir . DIRECTORY_SEPARATOR . 'HEAD';
        if (!file_exists($headFile)) {
            return ['branch' => 'unknown', 'commit' => 'unknown'];
        }

        $head = trim(file_get_contents($headFile));
        $branch = preg_replace('/^ref: refs\/heads\//', '', $head);

        $commitFile = $this->repoDir . DIRECTORY_SEPARATOR . 'refs' . DIRECTORY_SEPARATOR . 'heads' . DIRECTORY_SEPARATOR . $branch;
        $commit = file_exists($commitFile) ? substr(trim(file_get_contents($commitFile)), 0, 7) : 'unknown';

        return ['branch' => $branch, 'commit' => $commit];
    }
}
