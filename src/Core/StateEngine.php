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
            // Normalizar saltos de línea (CRLF -> LF) para evitar falsos drifts por checkout de Git
            $normalizedContent = str_replace("\r\n", "\n", $content);
            $hash = hash('sha256', $normalizedContent);

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
    public function syncPending(DriverInterface $driver, array $pending, string $appliedBy = 'system', ?string $notes = null): array
    {
        $startTime = microtime(true);
        $nextBatch = $driver->getNextBatchNumber();
        $appliedCount = 0;
        $errors = [];
        $gitInfo = $this->getGitInfo();

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
                    appliedBy: $appliedBy,
                    content: $file->getContent(),
                    gitCommit: $gitInfo['commit'],
                    gitBranch: $gitInfo['branch'],
                    note: $notes
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
            'git_commit' => $gitInfo['commit'],
            'git_branch' => $gitInfo['branch'],
        ];
    }

    /**
     * Revierte el entorno restaurando el snapshot de respaldo más reciente.
     * El rollback en SYNCDERO es una restauración atómica del último backup
     * disponible del entorno, lo que rebobina tanto el esquema como la tabla
     * de metadatos al estado del snapshot.
     */
    public function rollbackToLatestBackup(DriverInterface $driver, string $backupDir, string $database): array
    {
        $latest = $this->findLatestBackup($backupDir, $database);
        if ($latest === null) {
            return [
                'success' => false,
                'message' => 'No hay snapshots de respaldo para este entorno. Genere un backup primero.',
                'restored_from' => null,
                'safety_path' => null,
                'rewound' => [],
            ];
        }

        $safety = $driver->createBackup($backupDir);
        $result = $driver->restoreBackup($latest);

        return [
            'success' => $result['success'],
            'message' => $result['message'],
            'restored_from' => $latest,
            'safety_path' => $safety['success'] ? $safety['path'] : null,
            'rewound' => $result['rewound'],
        ];
    }

    /**
     * Retorna la ruta del snapshot más reciente del entorno (o null si no hay).
     */
    public function findLatestBackup(string $backupDir, string $database): ?string
    {
        $backups = $this->findBackups($backupDir, $database, 1);
        return $backups[0]['path'] ?? null;
    }

    /**
     * Lista los snapshots de respaldo de una base de datos, del más reciente
     * al más antiguo. Coincide con el patrón de nombres de createBackup():
     * backup_{db}_{timestamp}.sql y backup_sqlite_{archivo}_{timestamp}.sqlite.
     *
     * @return array<int, array{name: string, path: string, size: int, modified: int}>
     */
    public function findBackups(string $backupDir, string $database, int $limit = 0): array
    {
        if (!is_dir($backupDir)) {
            return [];
        }

        $base = basename($database);
        if ($base === '' || $base === '.') {
            return [];
        }

        $pattern = '/^backup_(sqlite_)?' . preg_quote($base, '/') . '_/';
        $matches = [];

        foreach (scandir($backupDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $backupDir . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($path) || !preg_match($pattern, $entry)) {
                continue;
            }

            $matches[] = [
                'name' => $entry,
                'path' => $path,
                'size' => (int) filesize($path),
                'modified' => (int) filemtime($path),
            ];
        }

        usort($matches, static fn(array $a, array $b): int => $b['modified'] <=> $a['modified']);

        return $limit > 0 ? array_slice($matches, 0, $limit) : $matches;
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
        $default = ['branch' => 'unknown', 'commit' => 'unknown'];
        if (!is_dir($this->repoDir)) {
            return $default;
        }

        $headFile = $this->repoDir . DIRECTORY_SEPARATOR . 'HEAD';
        if (!file_exists($headFile)) {
            return $default;
        }

        $head = trim((string) file_get_contents($headFile));

        if (!str_starts_with($head, 'ref:')) {
            // HEAD desacoplado (detached): el propio HEAD contiene el SHA.
            return ['branch' => '(detached)', 'commit' => substr($head, 0, 7) ?: 'unknown'];
        }

        $branch = preg_replace('/^ref: refs\/heads\//', '', $head) ?? '';
        if ($branch === '') {
            return ['branch' => 'unknown', 'commit' => 'unknown'];
        }

        $commitFile = $this->repoDir . DIRECTORY_SEPARATOR . 'refs' . DIRECTORY_SEPARATOR . 'heads' . DIRECTORY_SEPARATOR . $branch;
        $commit = 'unknown';

        if (file_exists($commitFile)) {
            $commit = substr(trim((string) file_get_contents($commitFile)), 0, 7);
        } else {
            // Ramas empaquetadas (packed-refs), típico tras clone/fetch/gc
            $packedFile = $this->repoDir . DIRECTORY_SEPARATOR . 'packed-refs';
            if (file_exists($packedFile)) {
                $needle = ' refs/heads/' . $branch;
                foreach (file($packedFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                    if (str_ends_with($line, $needle) && !str_starts_with($line, '#')) {
                        $commit = substr(trim(explode(' ', $line)[0]), 0, 7);
                        break;
                    }
                }
            }
        }

        return ['branch' => $branch, 'commit' => $commit];
    }

    /**
     * Obtiene el listado de ramas de Git disponibles en el repositorio.
     * @return array<string>
     */
    public function getGitBranches(): array
    {
        if (!is_dir($this->repoDir)) {
            return [];
        }

        $headsDir = $this->repoDir . DIRECTORY_SEPARATOR . 'refs' . DIRECTORY_SEPARATOR . 'heads';
        if (!is_dir($headsDir)) {
            return [];
        }

        $branches = [];
        $files = scandir($headsDir) ?: [];
        foreach ($files as $f) {
            if ($f !== '.' && $f !== '..' && is_file($headsDir . DIRECTORY_SEPARATOR . $f)) {
                $branches[] = $f;
            }
        }
        return $branches;
    }

    /**
     * Obtiene el autor actual de Git (nombre e email) leyendo la configuración o el entorno.
     * @return array{name: string, email: string, signature: string}
     */
    public function getGitAuthor(): array
    {
        $defaultName = getenv('SYNCDERO_AUTHOR_NAME') ?: (getenv('USERNAME') ?: 'Developer');
        $defaultEmail = getenv('SYNCDERO_AUTHOR_EMAIL') ?: 'dev@local';

        $configFile = $this->repoDir . DIRECTORY_SEPARATOR . 'config';
        if (file_exists($configFile)) {
            $content = (string) file_get_contents($configFile);
            if (preg_match('/^\s*name\s*=\s*(.+)$/m', $content, $m)) {
                $defaultName = trim($m[1]);
            }
            if (preg_match('/^\s*email\s*=\s*(.+)$/m', $content, $m)) {
                $defaultEmail = trim($m[1]);
            }
        }

        // Si no se encuentra en el repo local, chequear .gitconfig del usuario si existe
        if ($defaultName === 'Developer' || $defaultEmail === 'dev@local') {
            $userProfile = getenv('USERPROFILE') ?: getenv('HOME');
            if ($userProfile) {
                $globalGit = $userProfile . DIRECTORY_SEPARATOR . '.gitconfig';
                if (file_exists($globalGit)) {
                    $content = (string) file_get_contents($globalGit);
                    if (preg_match('/^\s*name\s*=\s*(.+)$/m', $content, $m)) {
                        $defaultName = trim($m[1]);
                    }
                    if (preg_match('/^\s*email\s*=\s*(.+)$/m', $content, $m)) {
                        $defaultEmail = trim($m[1]);
                    }
                }
            }
        }

        return [
            'name' => $defaultName,
            'email' => $defaultEmail,
            'signature' => "{$defaultName} <{$defaultEmail}>",
        ];
    }
}

