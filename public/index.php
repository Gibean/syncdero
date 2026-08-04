<?php

declare(strict_types=1);

/**
 * SYNCDERO - Web Dashboard & API Router
 * Git-like UI for Database Version Control
 */

// Cargar el autoloader de SYNCDERO
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../src/autoload.php',
];

foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

use Syncdero\Core\Config;
use Syncdero\Core\StateEngine;
use Syncdero\Drivers\DriverFactory;

$configPath = getenv('SYNCDERO_CONFIG') ?: (__DIR__ . '/../config/syncdero.php');
if (!file_exists($configPath)) {
    $configPath = __DIR__ . '/config.php';
}

$config = Config::load($configPath);
$stateEngine = new StateEngine($config->getMigrationsDir(), $config->getRepoDir());
$gitInfo = $stateEngine->getGitInfo();
$environments = $config->getEnvironments();

// Entorno seleccionado actualmente (por defecto el primero configurado)
$selectedEnvKey = $_GET['env'] ?? array_key_first($environments) ?? '';
$selectedEnv = $environments[$selectedEnvKey] ?? null;

// Manejo de Acciones POST (Sync, Backup)
$action = $_POST['action'] ?? null;
$message = null;
$messageType = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedEnv) {
    $driver = DriverFactory::create($selectedEnv);

    if ($action === 'sync') {
        $password = $_POST['password'] ?? '';
        $requiresPass = ($selectedEnv['protected'] === true);

        if ($requiresPass && $password !== $config->getApplyPassword()) {
            $message = 'Contraseña incorrecta para ambiente protegido.';
            $messageType = 'danger';
        } else {
            // Inspeccionar pendientes
            $inspection = $stateEngine->inspectEnvironment($driver);
            if ($inspection['has_divergence']) {
                $message = 'No se puede sincronizar: existen migraciones con divergencia de hash.';
                $messageType = 'danger';
            } elseif (empty($inspection['pending'])) {
                $message = 'La base de datos ya está sincronizada al 100%.';
                $messageType = 'info';
            } else {
                // Snapshot previo de seguridad
                $backupRes = $driver->createBackup($config->getBackupDir());
                $syncRes = $stateEngine->syncPending($driver, $inspection['pending'], 'web_user');

                if ($syncRes['success']) {
                    $message = "¡Sincronización exitosa! Se aplicaron {$syncRes['applied_count']} migraciones en {$syncRes['duration_ms']}ms.";
                    $messageType = 'success';
                } else {
                    $message = "Error durante la migración: " . json_encode($syncRes['errors']);
                    $messageType = 'danger';
                }
            }
        }
    } elseif ($action === 'backup') {
        $backupRes = $driver->createBackup($config->getBackupDir());
        if ($backupRes['success']) {
            $message = "Respaldo creado con éxito: " . basename($backupRes['path']);
            $messageType = 'success';
        } else {
            $message = "Error creando respaldo: " . $backupRes['message'];
            $messageType = 'danger';
        }
    }
}

// Inspeccionar estado de todos los ambientes para la sidebar
$sidebarSummary = [];
foreach ($environments as $key => $envConfig) {
    try {
        $d = DriverFactory::create($envConfig);
        if ($d->testConnection()) {
            $insp = $stateEngine->inspectEnvironment($d);
            $sidebarSummary[$key] = [
                'status' => $insp['has_divergence'] ? 'conflict' : ($insp['is_synced'] ? 'synced' : 'behind'),
                'pending' => count($insp['pending']),
                'applied' => $insp['total_applied'],
                'total' => $insp['total_disk'],
                'error' => null,
            ];
        } else {
            $sidebarSummary[$key] = ['status' => 'offline', 'error' => 'No conectado'];
        }
    } catch (\Throwable $e) {
        $sidebarSummary[$key] = ['status' => 'offline', 'error' => $e->getMessage()];
    }
}

// Inspección detallada del ambiente activo
$activeInspection = null;
$batches = [];
$activeDriver = null;

if ($selectedEnv) {
    try {
        $activeDriver = DriverFactory::create($selectedEnv);
        if ($activeDriver->testConnection()) {
            $activeInspection = $stateEngine->inspectEnvironment($activeDriver);
            // Agrupar aplicadas por batch para el commit graph
            foreach ($activeInspection['applied'] as $name => $item) {
                $batchNum = $item['db']['batch'];
                $batches[$batchNum][] = $item;
            }
            krsort($batches); // Batches más recientes arriba
        }
    } catch (\Throwable $e) {
        // Silencioso, manejado en la UI
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYNCDERO - Database Version Control</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>

    <!-- Header Superior -->
    <header class="app-header">
        <div class="logo-group">
            <span class="logo-badge">SYNCDERO</span>
            <span class="logo-title">Database Version Control</span>
        </div>

        <div class="header-status">
            <div class="git-badge">
                <span>⎇</span>
                <span>Branch: <strong><?= htmlspecialchars($gitInfo['branch']) ?></strong></span>
                <span style="opacity: 0.5;">(<?= htmlspecialchars($gitInfo['commit']) ?>)</span>
            </div>
            <div style="color: var(--fg-subtle);">
                ⏱ <?= date('H:i:s') ?> UTC
            </div>
        </div>
    </header>

    <!-- Notificación emergente -->
    <?php if ($message): ?>
        <div style="background: <?= $messageType === 'success' ? 'var(--status-sync-bg)' : ($messageType === 'danger' ? 'var(--status-danger-bg)' : 'var(--status-info-bg)') ?>; 
                    color: <?= $messageType === 'success' ? 'var(--status-sync)' : ($messageType === 'danger' ? 'var(--status-danger)' : 'var(--status-info)') ?>; 
                    border-bottom: 1px solid var(--border); padding: 8px 16px; font-weight: 600; font-size: 12px; display: flex; justify-content: space-between;">
            <span><?= htmlspecialchars($message) ?></span>
            <button onclick="this.parentElement.remove()" style="background:none; border:none; color:inherit; cursor:pointer;">✕</button>
        </div>
    <?php endif; ?>

    <!-- Workspace Git Client de 3 Columnas -->
    <main class="app-workspace">

        <!-- 1. Barra Lateral: Ambientes / Remotes -->
        <aside class="sidebar-left">
            <div class="panel-header">
                <span>Ambientes / Bases de Datos</span>
                <span><?= count($environments) ?></span>
            </div>

            <ul class="env-list">
                <?php foreach ($environments as $envKey => $env): ?>
                    <?php 
                        $summary = $sidebarSummary[$envKey] ?? null;
                        $isActive = ($envKey === $selectedEnvKey);
                    ?>
                    <a href="?env=<?= urlencode($envKey) ?>" class="env-item <?= $isActive ? 'active' : '' ?>">
                        <div class="env-title-row">
                            <span class="env-name">
                                <span><?= $env['icon'] ?? '●' ?></span>
                                <?= htmlspecialchars($env['label'] ?? $envKey) ?>
                            </span>
                            <?php if ($summary && !isset($summary['error'])): ?>
                                <?php if ($summary['status'] === 'synced'): ?>
                                    <span class="badge badge-sync">SYNC</span>
                                <?php elseif ($summary['status'] === 'behind'): ?>
                                    <span class="badge badge-warn">-<?= $summary['pending'] ?></span>
                                <?php else: ?>
                                    <span class="badge badge-danger">CONFLICT</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge" style="background: rgba(148,163,184,0.1); color: var(--fg-subtle);">OFFLINE</span>
                            <?php endif; ?>
                        </div>
                        <div class="env-meta-row">
                            <span><?= strtoupper($env['driver'] ?? 'mysql') ?></span>
                            <span><?= $summary['applied'] ?? 0 ?> / <?= $summary['total'] ?? 0 ?> scripts</span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </ul>
        </aside>

        <!-- 2. Panel Central: Commit Timeline & Graph -->
        <section class="main-center">
            <div class="toolbar">
                <div class="toolbar-info">
                    <h2 style="font-size: 14px; font-weight: 600;">
                        <?= htmlspecialchars($selectedEnv['label'] ?? $selectedEnvKey) ?>
                    </h2>
                    <span class="badge" style="background: var(--bg-overlay); color: var(--fg-muted);">
                        <?= strtoupper($selectedEnv['role'] ?? 'dev') ?>
                    </span>
                </div>

                <div class="actions-group">
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="backup">
                        <button type="submit" class="btn btn-secondary" title="Generar respaldo de seguridad">
                            💾 Snapshot
                        </button>
                    </form>

                    <button type="button" class="btn btn-primary" onclick="openSyncModal()" <?= empty($activeInspection['pending'] ?? []) ? 'disabled' : '' ?>>
                        ⬇ Sincronizar (Push)
                    </button>
                </div>
            </div>

            <div class="timeline-container">
                <?php if (!$activeDriver || !$activeDriver->testConnection()): ?>
                    <div style="padding: 40px; text-align: center; color: var(--fg-subtle);">
                        <p style="font-size: 16px; margin-bottom: 8px;">🔌 No se puede conectar a esta base de datos</p>
                        <p style="font-size: 12px;">Verifique que el servicio esté activo y las credenciales en <code>config/syncdero.php</code> sean correctas.</p>
                    </div>
                <?php elseif (empty($batches)): ?>
                    <div style="padding: 40px; text-align: center; color: var(--fg-subtle);">
                        <p style="font-size: 16px; margin-bottom: 8px;">📭 No hay migraciones aplicadas aún en este entorno</p>
                        <p style="font-size: 12px;">Utiliza el botón <strong>Sincronizar (Push)</strong> para desplegar las migraciones pendientes.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($batches as $batchNum => $items): ?>
                        <div class="batch-card">
                            <div class="batch-header">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span class="batch-tag">Batch #<?= $batchNum ?></span>
                                    <span style="color: var(--fg-muted);"><?= count($items) ?> migración(es)</span>
                                </div>
                                <div style="color: var(--fg-subtle);">
                                    <?= htmlspecialchars($items[0]['db']['applied_at'] ?? '') ?>
                                </div>
                            </div>
                            <ul class="batch-migrations">
                                <?php foreach ($items as $item): ?>
                                    <li class="migration-row">
                                        <div class="migration-name">
                                            <span style="color: var(--status-sync);">✓</span>
                                            <span><?= htmlspecialchars($item['file']->basename) ?></span>
                                            <span class="badge" style="font-size: 9px; opacity: 0.7;"><?= strtoupper($item['file']->type) ?></span>
                                        </div>
                                        <div class="migration-hash">
                                            SHA-256: <code><?= substr($item['file']->hash, 0, 8) ?>...</code>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <!-- 3. Panel Derecho: Staged (Pendientes) & Conflicts (Divergencias) -->
        <aside class="sidebar-right">
            <div class="panel-header">
                <span>Inspección de Cambios</span>
            </div>

            <!-- Sección de Divergencias / Conflictos -->
            <?php $divergences = $activeInspection['divergent'] ?? []; ?>
            <?php if (!empty($divergences)): ?>
                <div class="stage-section" style="background: rgba(244, 63, 94, 0.05);">
                    <div class="stage-title" style="color: var(--status-danger);">
                        <span>⚠ Divergencias de Hash</span>
                        <span class="badge badge-danger"><?= count($divergences) ?></span>
                    </div>
                    <?php foreach ($divergences as $name => $div): ?>
                        <div class="file-item conflict">
                            <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= htmlspecialchars($name) ?></span>
                            <span title="El hash del archivo en disco no coincide con el registrado en la base de datos">DRIFT</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Sección Staged / Pendientes de Aplicar -->
            <?php $pending = $activeInspection['pending'] ?? []; ?>
            <div class="stage-section">
                <div class="stage-title">
                    <span>Pendientes (Unstaged)</span>
                    <span class="badge <?= empty($pending) ? 'badge-sync' : 'badge-warn' ?>"><?= count($pending) ?></span>
                </div>
                <?php if (empty($pending)): ?>
                    <p style="color: var(--fg-subtle); font-size: 11px; padding: 4px 0;">No hay migraciones pendientes. Todo al día.</p>
                <?php else: ?>
                    <?php foreach ($pending as $name => $item): ?>
                        <div class="file-item">
                            <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= htmlspecialchars($name) ?></span>
                            <span style="color: var(--fg-subtle); font-size: 10px;"><?= strtoupper($item['file']->type) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Resumen de Integridad -->
            <div class="stage-section" style="margin-top: auto;">
                <div class="stage-title">
                    <span>Integridad de Esquema</span>
                </div>
                <div style="font-size: 11px; color: var(--fg-muted); display: flex; flex-direction: column; gap: 4px;">
                    <div>Archivos en disco: <strong><?= $activeInspection['total_disk'] ?? 0 ?></strong></div>
                    <div>Aplicados en BD: <strong><?= $activeInspection['total_applied'] ?? 0 ?></strong></div>
                    <div>Motor activo: <strong><?= strtoupper($selectedEnv['driver'] ?? 'N/A') ?></strong></div>
                </div>
            </div>
        </aside>

    </main>

    <!-- Modal de Confirmación de Sincronización -->
    <div id="syncModal" class="modal-backdrop">
        <div class="modal-card">
            <div class="modal-header">
                <h3 style="font-size: 14px; font-weight: 600;">Confirmar Sincronización (Push)</h3>
                <button type="button" onclick="closeSyncModal()" style="background:none; border:none; color:var(--fg-muted); cursor:pointer;">✕</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="sync">
                    <p style="margin-bottom: 12px;">
                        ¿Deseas aplicar las <strong><?= count($pending) ?></strong> migraciones pendientes en 
                        <code><?= htmlspecialchars($selectedEnv['label'] ?? $selectedEnvKey) ?></code>?
                    </p>
                    <p style="font-size: 11px; color: var(--fg-subtle); margin-bottom: 12px;">
                        ℹ Se generará automáticamente un snapshot de seguridad en <code>backups/</code> antes de aplicar los cambios.
                    </p>

                    <?php if (($selectedEnv['protected'] ?? false) === true): ?>
                        <div style="margin-top: 14px;">
                            <label style="font-size: 11px; font-weight: 600; color: var(--status-danger);">
                                🔒 Ambiente Protegido. Ingrese la contraseña de despliegue:
                            </label>
                            <input type="password" name="password" placeholder="Contraseña..." required autofocus>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeSyncModal()">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Aplicar Cambios</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openSyncModal() {
            document.getElementById('syncModal').classList.add('open');
        }
        function closeSyncModal() {
            document.getElementById('syncModal').classList.remove('open');
        }
    </script>

</body>
</html>
