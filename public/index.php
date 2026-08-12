<?php

declare(strict_types=1);

/**
 * SYNCDERO - Web Interface
 * Notion / AI-Flow Inspired Clean Canvas & Draggable Version Control
 */

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

// Entorno seleccionado
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
            $message = 'Contraseña no válida para el entorno protegido.';
            $messageType = 'danger';
        } else {
            $inspection = $stateEngine->inspectEnvironment($driver);
            if ($inspection['has_divergence']) {
                $message = 'Sincronización abortada: se detectaron divergencias de hash en migraciones aplicadas.';
                $messageType = 'danger';
            } elseif (empty($inspection['pending'])) {
                $message = 'El entorno ya se encuentra completamente al día.';
                $messageType = 'info';
            } else {
                $driver->createBackup($config->getBackupDir());
                $syncRes = $stateEngine->syncPending($driver, $inspection['pending'], 'web_client');

                if ($syncRes['success']) {
                    $message = "Sincronización completada: {$syncRes['applied_count']} migración(es) aplicada(s) en {$syncRes['duration_ms']}ms (Batch #{$syncRes['batch']}).";
                    $messageType = 'success';
                } else {
                    $message = "Error en la ejecución: " . json_encode($syncRes['errors']);
                    $messageType = 'danger';
                }
            }
        }
    } elseif ($action === 'backup') {
        $backupRes = $driver->createBackup($config->getBackupDir());
        if ($backupRes['success']) {
            $message = "Snapshot generado: " . basename($backupRes['path']);
            $messageType = 'success';
        } else {
            $message = "Fallo al crear snapshot: " . $backupRes['message'];
            $messageType = 'danger';
        }
    }
}

// Resumen de entornos para la barra lateral
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
            ];
        } else {
            $sidebarSummary[$key] = ['status' => 'offline', 'pending' => 0, 'applied' => 0, 'total' => 0];
        }
    } catch (\Throwable $e) {
        $sidebarSummary[$key] = ['status' => 'offline', 'pending' => 0, 'applied' => 0, 'total' => 0];
    }
}

// Datos detallados del entorno activo
$activeInspection = null;
$batches = [];
$activeDriver = null;

if ($selectedEnv) {
    try {
        $activeDriver = DriverFactory::create($selectedEnv);
        if ($activeDriver->testConnection()) {
            $activeInspection = $stateEngine->inspectEnvironment($activeDriver);
            foreach ($activeInspection['applied'] as $name => $item) {
                $batchNum = $item['db']['batch'];
                $batches[$batchNum][] = $item;
            }
            ksort($batches);
        }
    } catch (\Throwable $e) {
        // Manejado en la interfaz
    }
}

$pendingList = $activeInspection['pending'] ?? [];
$divergentList = $activeInspection['divergent'] ?? [];
?>
<!DOCTYPE html>
<html lang="es" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYNCDERO — Database Schema Version Flow</title>
    <link rel="stylesheet" href="/assets/icons.css">
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>

    <!-- SVG Icon Definitions (Lucide SVG sprite clean & lightweight) -->
    <svg xmlns="http://www.w3.org/2000/svg" style="display: none;">
        <symbol id="icon-layers" viewBox="0 0 24 24">
            <polygon points="12 2 2 7 12 12 22 7 12 2"></polygon>
            <polyline points="2 17 12 22 22 17"></polyline>
            <polyline points="2 12 12 17 22 12"></polyline>
        </symbol>
        <symbol id="icon-database" viewBox="0 0 24 24">
            <ellipse cx="12" cy="5" rx="9" ry="3"></ellipse>
            <path d="M3 5V19A9 3 0 0 0 21 19V5"></path>
            <path d="M3 12A9 3 0 0 0 21 12"></path>
        </symbol>
        <symbol id="icon-git-branch" viewBox="0 0 24 24">
            <line x1="6" y1="3" x2="6" y2="15"></line>
            <circle cx="18" cy="6" r="3"></circle>
            <circle cx="6" cy="18" r="3"></circle>
            <path d="M18 9a9 9 0 0 1-9 9"></path>
        </symbol>
        <symbol id="icon-git-commit" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="3"></circle>
            <line x1="3" y1="12" x2="9" y2="12"></line>
            <line x1="15" y1="12" x2="21" y2="12"></line>
        </symbol>
        <symbol id="icon-play" viewBox="0 0 24 24">
            <polygon points="5 3 19 12 5 21 5 3"></polygon>
        </symbol>
        <symbol id="icon-save" viewBox="0 0 24 24">
            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
            <polyline points="17 21 17 13 7 13 7 21"></polyline>
            <polyline points="7 3 7 8 15 8"></polyline>
        </symbol>
        <symbol id="icon-moon" viewBox="0 0 24 24">
            <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"></path>
        </symbol>
        <symbol id="icon-sun" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="4"></circle>
            <path d="M12 2v2"></path>
            <path d="M12 20v2"></path>
            <path d="m4.93 4.93 1.41 1.41"></path>
            <path d="m17.66 17.66 1.41 1.41"></path>
            <path d="M2 12h2"></path>
            <path d="M20 12h2"></path>
            <path d="m6.34 17.66-1.41 1.41"></path>
            <path d="m19.07 4.93-1.41 1.41"></path>
        </symbol>
        <symbol id="icon-alert" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="8" x2="12" y2="12"></line>
            <line x1="12" y1="16" x2="12.01" y2="16"></line>
        </symbol>
        <symbol id="icon-check" viewBox="0 0 24 24">
            <polyline points="20 6 9 17 4 12"></polyline>
        </symbol>
    </svg>

    <!-- Barra de Navegación Superior -->
    <header class="topbar">
        <div class="brand-section">
            <a href="/" class="brand-logo">
                <span class="brand-icon-box">
                    <svg class="icon"><use href="#icon-layers"></use></svg>
                </span>
                <span>SYNCDERO</span>
            </a>
            <span class="breadcrumb-divider">/</span>
            <div class="env-selector-pill">
                <svg class="icon"><use href="#icon-database"></use></svg>
                <span><?= htmlspecialchars($selectedEnv['label'] ?? $selectedEnvKey) ?></span>
            </div>
        </div>

        <div class="topbar-center">
            <div class="git-ref-tag">
                <svg class="icon"><use href="#icon-git-branch"></use></svg>
                <span><?= htmlspecialchars($gitInfo['branch']) ?></span>
                <span style="color: var(--text-muted);">(<?= htmlspecialchars($gitInfo['commit']) ?>)</span>
            </div>
        </div>

        <div class="topbar-actions">
            <button id="themeToggleBtn" class="btn btn-icon" title="Alternar Modo Oscuro / Claro">
                <svg id="iconMoon" class="icon"><use href="#icon-moon"></use></svg>
                <svg id="iconSun" class="icon" style="display:none;"><use href="#icon-sun"></use></svg>
            </button>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="backup">
                <button type="submit" class="btn" title="Generar Snapshot manual">
                    <svg class="icon"><use href="#icon-save"></use></svg>
                    <span>Snapshot</span>
                </button>
            </form>
            <button type="button" class="btn btn-primary" onclick="openSyncModal()" <?= empty($pendingList) ? 'disabled' : '' ?>>
                <svg class="icon" style="stroke: #ffffff;"><use href="#icon-play"></use></svg>
                <span>Sincronizar (<?= count($pendingList) ?>)</span>
            </button>
        </div>
    </header>

    <!-- Notificación de estado -->
    <?php if ($message): ?>
        <div style="background: <?= $messageType === 'success' ? 'var(--state-sync-bg)' : ($messageType === 'danger' ? 'var(--state-danger-bg)' : 'var(--state-info-bg)') ?>;
                    color: <?= $messageType === 'success' ? 'var(--state-sync-text)' : ($messageType === 'danger' ? 'var(--state-danger-text)' : 'var(--state-info-text)') ?>;
                    border-bottom: 1px solid var(--border-color); padding: 8px 16px; font-size: 12px; font-weight: 500; display: flex; justify-content: space-between;">
            <span><?= htmlspecialchars($message) ?></span>
            <span onclick="this.parentElement.remove()" style="cursor:pointer; font-weight: 700;">✕</span>
        </div>
    <?php endif; ?>

    <!-- Contenedor Workspace -->
    <div class="workspace-container">

        <!-- 1. Barra Lateral: Entornos / Remotes -->
        <aside class="sidebar-left">
            <div class="section-label">
                <span>Entornos de BD</span>
                <span><?= count($environments) ?></span>
            </div>
            <ul class="env-nav-list">
                <?php foreach ($environments as $envKey => $env): ?>
                    <?php 
                        $summary = $sidebarSummary[$envKey] ?? null;
                        $isActive = ($envKey === $selectedEnvKey);
                    ?>
                    <li>
                        <a href="?env=<?= urlencode($envKey) ?>" class="env-nav-item <?= $isActive ? 'active' : '' ?>">
                            <div class="env-item-meta">
                                <svg class="icon" style="opacity: 0.6;"><use href="#icon-database"></use></svg>
                                <span><?= htmlspecialchars($env['label'] ?? $envKey) ?></span>
                            </div>
                            <?php if ($summary['status'] === 'synced'): ?>
                                <span class="status-badge badge-sync">SYNC</span>
                            <?php elseif ($summary['status'] === 'behind'): ?>
                                <span class="status-badge badge-warn">-<?= $summary['pending'] ?></span>
                            <?php elseif ($summary['status'] === 'conflict'): ?>
                                <span class="status-badge badge-danger">DRIFT</span>
                            <?php else: ?>
                                <span class="status-badge badge-muted">OFF</span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="section-label" style="margin-top: 16px;">
                <span>Detalles de Conexión</span>
            </div>
            <div style="padding: 0 14px; font-size: 11px; color: var(--text-secondary); display: flex; flex-direction: column; gap: 6px;">
                <div>Motor: <strong><?= strtoupper($selectedEnv['driver'] ?? 'N/A') ?></strong></div>
                <div>Rol: <strong><?= strtoupper($selectedEnv['role'] ?? 'dev') ?></strong></div>
                <div>Host: <code style="font-family: var(--font-mono);"><?= htmlspecialchars($selectedEnv['host'] ?? 'local') ?></code></div>
                <div>Base de datos: <code style="font-family: var(--font-mono);"><?= htmlspecialchars(basename($selectedEnv['database'] ?? 'db')) ?></code></div>
            </div>
        </aside>

        <!-- 2. Canvas Central: Dot Grid & Draggable Flow Cards -->
        <main class="canvas-area">
            
            <div class="flow-viewport" id="flowViewport">
                <!-- SVG Wire Connections -->
                <svg id="flowSvgWires" class="flow-connections-svg"></svg>

                <?php 
                    $posX = 40;
                    $posY = 60;
                    $nodeIndex = 0;
                    $colors = ['header-green', 'header-blue', 'header-purple', 'header-gray'];
                ?>

                <!-- Nodos de Batches Aplicados (Committed) -->
                <?php foreach ($batches as $batchNum => $items): ?>
                    <?php 
                        $colorClass = $colors[$nodeIndex % count($colors)];
                        $nodeIndex++;
                    ?>
                    <div class="flow-node" data-status="applied" style="left: <?= $posX ?>px; top: <?= $posY ?>px;">
                        <span class="node-connector-dot left"></span>
                        <div class="node-header <?= $colorClass ?>">
                            <div class="node-header-title">
                                <svg class="icon"><use href="#icon-git-commit"></use></svg>
                                <span>Batch #<?= $batchNum ?></span>
                            </div>
                            <span class="status-badge badge-sync">Aplicado</span>
                        </div>
                        <div class="node-body">
                            <div class="node-prop-row">
                                <span class="node-prop-label">Scripts en lote (<?= count($items) ?>)</span>
                                <?php foreach ($items as $item): ?>
                                    <div class="node-prop-value" title="<?= htmlspecialchars($item['file']->basename) ?>">
                                        <?= htmlspecialchars($item['file']->basename) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="node-prop-row">
                                <span class="node-prop-label">Hash SHA-256</span>
                                <div class="node-prop-value" style="color: var(--text-muted);">
                                    <?= substr($items[0]['file']->hash, 0, 16) ?>...
                                </div>
                            </div>
                            <div style="font-size: 10px; color: var(--text-muted); display: flex; justify-content: space-between;">
                                <span><?= htmlspecialchars($items[0]['db']['applied_at'] ?? '') ?></span>
                                <span><?= $items[0]['db']['execution_time_ms'] ?? 0 ?>ms</span>
                            </div>
                        </div>
                        <span class="node-connector-dot right"></span>
                    </div>

                    <?php 
                        $posX += 340;
                        if ($nodeIndex % 2 === 0) {
                            $posY += 40;
                        } else {
                            $posY -= 20;
                        }
                    ?>
                <?php endforeach; ?>

                <!-- Nodo de Pendientes (Unstaged) -->
                <?php if (!empty($pendingList)): ?>
                    <div class="flow-node" data-status="pending" style="left: <?= $posX ?>px; top: <?= $posY ?>px;">
                        <span class="node-connector-dot left"></span>
                        <div class="node-header header-coral">
                            <div class="node-header-title">
                                <svg class="icon"><use href="#icon-play"></use></svg>
                                <span>Próximo Lote (Pendiente)</span>
                            </div>
                            <span class="status-badge badge-warn">Unstaged</span>
                        </div>
                        <div class="node-body">
                            <div class="node-prop-row">
                                <span class="node-prop-label">Por aplicar (<?= count($pendingList) ?>)</span>
                                <?php foreach ($pendingList as $item): ?>
                                    <div class="node-prop-value" style="background: var(--state-warn-bg); border-color: var(--state-warn-border);">
                                        <?= htmlspecialchars($item['file']->basename) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div style="font-size: 11px; color: var(--text-secondary); margin-top: 4px;">
                                Listo para sincronizar mediante Push.
                            </div>
                        </div>
                        <span class="node-connector-dot right"></span>
                    </div>
                <?php endif; ?>

            </div>
        </main>

        <!-- 3. Barra Lateral Derecha: Inspector & Staged Files -->
        <aside class="sidebar-right">
            <div class="sidebar-right-header">
                <span>Inspector de Cambios</span>
                <span style="font-family: var(--font-mono); color: var(--text-muted); font-size: 11px;">
                    <?= $activeInspection['total_applied'] ?? 0 ?>/<?= $activeInspection['total_disk'] ?? 0 ?>
                </span>
            </div>

            <!-- Alertas de Conflicto / Drift -->
            <?php if (!empty($divergentList)): ?>
                <div class="inspector-section" style="background: var(--state-danger-bg);">
                    <div style="font-size: 11px; font-weight: 700; color: var(--state-danger-text); margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                        <svg class="icon"><use href="#icon-alert"></use></svg>
                        <span>Divergencias Detectadas (<?= count($divergentList) ?>)</span>
                    </div>
                    <?php foreach ($divergentList as $name => $item): ?>
                        <div class="file-row drift">
                            <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= htmlspecialchars($name) ?></span>
                            <span style="font-weight: 700;">DRIFT</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Archivos Pendientes (Unstaged) -->
            <div class="inspector-section">
                <div style="font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; margin-bottom: 8px;">
                    Pendientes de Ejecución
                </div>
                <?php if (empty($pendingList)): ?>
                    <div style="color: var(--text-subtle); font-size: 12px; padding: 6px 0;">
                        No hay migraciones pendientes en disco.
                    </div>
                <?php else: ?>
                    <?php foreach ($pendingList as $item): ?>
                        <div class="file-row">
                            <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= htmlspecialchars($item['file']->basename) ?></span>
                            <span style="color: var(--text-muted);"><?= strtoupper($item['file']->type) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Resumen de Métricas -->
            <div class="inspector-section" style="margin-top: auto;">
                <div style="font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; margin-bottom: 8px;">
                    Estado del Repositorio
                </div>
                <div style="font-size: 11px; color: var(--text-secondary); display: flex; flex-direction: column; gap: 4px;">
                    <div style="display:flex; justify-content: space-between;">
                        <span>Integridad de Hashes:</span>
                        <strong style="color: <?= empty($divergentList) ? 'var(--state-sync-text)' : 'var(--state-danger-text)' ?>;">
                            <?= empty($divergentList) ? 'Válida' : 'Comprometida' ?>
                        </strong>
                    </div>
                    <div style="display:flex; justify-content: space-between;">
                        <span>Carpeta de Backups:</span>
                        <code>backups/</code>
                    </div>
                </div>
            </div>
        </aside>

    </div>

    <!-- Modal de Confirmación de Sincronización -->
    <div id="syncModal" class="modal-layer">
        <div class="modal-window">
            <div class="modal-title-bar">
                <span>Confirmar Sincronización</span>
                <span onclick="closeSyncModal()" style="cursor: pointer; color: var(--text-muted);">✕</span>
            </div>
            <form method="POST">
                <div class="modal-content">
                    <input type="hidden" name="action" value="sync">
                    <p style="margin-bottom: 8px; font-weight: 500;">
                        ¿Deseas aplicar las <?= count($pendingList) ?> migraciones pendientes al entorno <strong><?= htmlspecialchars($selectedEnv['label'] ?? $selectedEnvKey) ?></strong>?
                    </p>
                    <p style="font-size: 11px; color: var(--text-muted); margin-bottom: 12px;">
                        Se creará un respaldo atómico automático en <code>backups/</code> antes de ejecutar los cambios.
                    </p>

                    <?php if (($selectedEnv['protected'] ?? false) === true): ?>
                        <div style="margin-top: 12px;">
                            <label style="display: block; font-size: 11px; font-weight: 600; margin-bottom: 4px; color: var(--state-danger-text);">
                                Entorno protegido. Ingresa la contraseña de autorización:
                            </label>
                            <input type="password" name="password" placeholder="Contraseña..." required autofocus>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-bottom">
                    <button type="button" class="btn" onclick="closeSyncModal()">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Aplicar Cambios</button>
                </div>
            </form>
        </div>
    </div>

    <script src="/assets/app.js"></script>
</body>
</html>
