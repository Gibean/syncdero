<?php

declare(strict_types=1);

/**
 * SYNCDERO Local Development Configuration
 * Este archivo usa por defecto config/syncdero.php para el nuevo motor desacoplado.
 */

$newConfig = __DIR__ . '/../config/syncdero.php';
if (file_exists($newConfig)) {
    return require $newConfig;
}

return [
    'environments' => [],
    'migrations_dir' => __DIR__ . '/../migrations',
    'backup_dir' => __DIR__ . '/../backups',
    'repo_dir' => __DIR__ . '/../.git',
];
