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
$gitAuthor = $stateEngine->getGitAuthor();
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
        $notes = trim($_POST['notes'] ?? '') ?: null;
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
                $authorName = trim($_POST['author_name'] ?? '');
                $authorEmail = trim($_POST['author_email'] ?? '');
                $appliedBy = ($authorName !== '')
                    ? ($authorEmail !== '' ? "{$authorName} <{$authorEmail}>" : $authorName)
                    : ($gitAuthor['signature'] ?? 'developer');

                $syncRes = $stateEngine->syncPending($driver, $inspection['pending'], $appliedBy, $notes);

                if ($syncRes['success']) {
                    $noteMsg = $notes ? " [Nota: {$notes}]" : "";
                    $message = "Sincronización completada: {$syncRes['applied_count']} migración(es) aplicada(s) por {$appliedBy} en {$syncRes['duration_ms']}ms (Batch #{$syncRes['batch']}){$noteMsg}.";
                    $messageType = 'success';
                } else {
                    $message = "Error en la ejecución: " . json_encode($syncRes['errors']);
                    $messageType = 'danger';
                }
            }
        }
    } elseif ($action === 'rollback') {
        $password = $_POST['password'] ?? '';
        $requiresPass = ($selectedEnv['protected'] === true);

        if ($requiresPass && $password !== $config->getApplyPassword()) {
            $message = 'Contraseña no válida para revertir cambios en entorno protegido.';
            $messageType = 'danger';
        } else {
            try {
                $rbResult = $stateEngine->rollbackToLatestBackup($driver, $config->getBackupDir(), (string) ($selectedEnv['database'] ?? ''));
                if ($rbResult['success']) {
                    $rewoundInfo = count($rbResult['rewound']) > 0
                        ? ' Migraciones rebobinadas: ' . count($rbResult['rewound']) . '.'
                        : ' El estado de versionado ya coincidía con el snapshot.';
                    $safetyInfo = $rbResult['safety_path']
                        ? ' Respaldo de seguridad previo: ' . basename($rbResult['safety_path']) . '.'
                        : '';
                    $message = "Rollback ejecutado (snapshot restaurado: " . basename((string) $rbResult['restored_from']) . "): " . $rbResult['message'] . "{$rewoundInfo}{$safetyInfo}";
                    $messageType = 'success';
                } else {
                    $message = $rbResult['message'];
                    $messageType = 'danger';
                }
            } catch (\Throwable $e) {
                $message = "Error en el Rollback: " . $e->getMessage();
                $messageType = 'danger';
            }
        }
    } elseif ($action === 'restore') {
        $password = $_POST['password'] ?? '';
        $requiresPass = ($selectedEnv['protected'] === true);

        if ($requiresPass && $password !== $config->getApplyPassword()) {
            $message = 'Contraseña no válida para restaurar en entorno protegido.';
            $messageType = 'danger';
        } else {
            try {
                // Resolución segura del snapshot: solo nombre de archivo dentro de backups/
                $backupName = basename(trim($_POST['backup_file'] ?? ''));
                $backupPath = ($backupName !== '') ? $config->getBackupDir() . DIRECTORY_SEPARATOR . $backupName : null;

                if ($backupPath === null || !file_exists($backupPath)) {
                    $message = 'No se encontró el snapshot de respaldo indicado.';
                    $messageType = 'danger';
                } else {
                    // Respaldo de seguridad antes de sobrescribir el estado actual
                    $safety = $driver->createBackup($config->getBackupDir());
                    $res = $driver->restoreBackup($backupPath);

                    if ($res['success']) {
                        $rewoundInfo = count($res['rewound']) > 0
                            ? ' Migraciones rebobinadas: ' . count($res['rewound']) . '.'
                            : ' El estado de versionado ya coincidía con el snapshot.';
                        $safetyInfo = $safety['success']
                            ? ' Respaldo de seguridad previo: ' . basename($safety['path']) . '.'
                            : '';
                        $message = "Snapshot restaurado ({$backupName}): " . $res['message'] . "{$rewoundInfo}{$safetyInfo}";
                        $messageType = 'success';
                    } else {
                        $message = "No se pudo restaurar el snapshot: " . $res['message'];
                        $messageType = 'danger';
                    }
                }
            } catch (\Throwable $e) {
                $message = 'Error al restaurar: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    } elseif ($action === 'save_server') {
        $serverKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower(trim($_POST['server_key'] ?? 'server_' . time())));
        $serverDriver = trim($_POST['server_driver'] ?? 'mysql');

        $serverConfig = [
            'label'         => trim($_POST['server_label'] ?? $serverKey),
            'driver'        => $serverDriver,
            'host'          => trim($_POST['server_host'] ?? '127.0.0.1'),
            'port'          => (int)($_POST['server_port'] ?? 3306),
            'user'          => trim($_POST['server_user'] ?? 'root'),
            'password'      => $_POST['server_password'] ?? '',
            'auto_discover' => isset($_POST['server_autodiscover']),
            'protected'     => isset($_POST['server_protected']),
            'role'          => in_array($_POST['server_role'] ?? 'dev', ['dev', 'staging', 'prod'], true) ? $_POST['server_role'] : 'dev',
        ];

        // SQLite usa una ruta de archivo como "base de datos"; el resto, nombre de BD
        if ($serverDriver === 'sqlite') {
            $serverConfig['database'] = trim($_POST['server_database'] ?? '');
        } else {
            $serverConfig['database'] = trim($_POST['server_database'] ?? '');
            // Sin base de datos definida, el auto-discovery es obligatorio para poder listar entornos
            if ($serverConfig['database'] === '' && !$serverConfig['auto_discover']) {
                $message = 'Indica una base de datos o activa el Auto-Discovery para este servidor.';
                $messageType = 'danger';
                $serverConfig = null;
            }
        }

        if ($serverConfig !== null) {
            // Probar conexión antes de guardar
            try {
                $testDriver = DriverFactory::create($serverConfig);
                if ($testDriver->testConnection()) {
                    $config->saveServer($serverKey, $serverConfig, $configPath);
                    $message = "Servidor '{$serverConfig['label']}' guardado y conectado exitosamente.";
                    $messageType = 'success';
                    // Recargar entornos
                    $environments = $config->getEnvironments();
                } else {
                    $message = "No se pudo conectar al servidor con las credenciales proporcionadas.";
                    $messageType = 'danger';
                }
            } catch (\Throwable $e) {
                $message = "Error probando conexión: " . $e->getMessage();
                $messageType = 'danger';
            }
        }
    } elseif ($action === 'delete_server') {
        $delKey = $_POST['server_key'] ?? '';
        if ($delKey) {
            $config->deleteServer($delKey, $configPath);
            $message = "Servidor eliminado.";
            $messageType = 'info';
            $environments = $config->getEnvironments();
        }
    } elseif ($action === 'backup') {
        try {
            if (!$driver->testConnection()) {
                $message = 'No se pudo conectar a la base de datos para generar el snapshot.';
                $messageType = 'danger';
            } else {
                $backupRes = $driver->createBackup($config->getBackupDir());
                if ($backupRes['success']) {
                    $message = "Snapshot generado correctamente: " . basename($backupRes['path']);
                    $messageType = 'success';
                } else {
                    $message = 'No se pudo generar el snapshot: ' . $backupRes['message'];
                    $messageType = 'danger';
                }
            }
        } catch (\Throwable $e) {
            $message = 'Error generando snapshot: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}


// Endpoint API JSON liviano para chequear un entorno bajo demanda sin congelar la UI
if (isset($_GET['api_status'])) {
    header('Content-Type: application/json');
    $checkKey = $_GET['api_status'];
    $envConfig = $environments[$checkKey] ?? null;
    if (!$envConfig) {
        echo json_encode(['status' => 'offline', 'error' => 'Entorno no configurado']);
        exit;
    }
    try {
        $d = DriverFactory::create($envConfig);
        if ($d->testConnection()) {
            $insp = $stateEngine->inspectEnvironment($d);
            echo json_encode([
                'status' => $insp['has_divergence'] ? 'conflict' : ($insp['is_synced'] ? 'synced' : 'behind'),
                'pending' => count($insp['pending']),
                'applied' => $insp['total_applied'],
                'total' => $insp['total_disk'],
            ]);
        } else {
            echo json_encode(['status' => 'offline', 'error' => 'No conectado']);
        }
    } catch (\Throwable $e) {
        echo json_encode(['status' => 'offline', 'error' => $e->getMessage()]);
    }
    exit;
}

// Endpoint API JSON para previsualizar código de migración y diff
if (isset($_GET['api_preview'])) {
    header('Content-Type: application/json');
    $param = basename(trim($_GET['api_preview']));
    $migrationsDir = $config->getMigrationsDir();
    $filePath = null;
    $filename = null;

    // Buscar coincidencia exacta por nombre de archivo o por basename (sin extensión)
    if (file_exists($migrationsDir . DIRECTORY_SEPARATOR . $param) && is_file($migrationsDir . DIRECTORY_SEPARATOR . $param)) {
        $filePath = $migrationsDir . DIRECTORY_SEPARATOR . $param;
        $filename = $param;
    } else {
        foreach (scandir($migrationsDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (pathinfo($entry, PATHINFO_FILENAME) === $param || $entry === $param) {
                $filePath = $migrationsDir . DIRECTORY_SEPARATOR . $entry;
                $filename = $entry;
                break;
            }
        }
    }

    if ($filePath === null || !file_exists($filePath)) {
        echo json_encode(['success' => false, 'error' => 'Archivo no encontrado: ' . $param]);
        exit;
    }

    $basename = pathinfo($filename, PATHINFO_FILENAME);
    $content = file_get_contents($filePath);
    $normalizedContent = str_replace("\r\n", "\n", $content);
    $diskHash = hash('sha256', $normalizedContent);

    // Si hay un entorno seleccionado y driver conectado, ver hash en BD
    $dbHash = null;
    $appliedAt = null;
    $batch = null;
    $gitCommit = null;
    $gitBranch = null;
    $notes = null;
    $dbContent = null;

    if ($selectedEnv) {
        try {
            $d = DriverFactory::create($selectedEnv);
            if ($d->testConnection()) {
                $applied = $d->getAppliedMigrations();
                $rec = $applied[$basename] ?? ($applied[$filename] ?? null);
                if ($rec !== null) {
                    $dbHash = $rec['hash'];
                    $appliedAt = $rec['applied_at'];
                    $batch = $rec['batch'];
                    $gitCommit = $rec['git_commit'] ?? null;
                    $gitBranch = $rec['git_branch'] ?? null;
                    $notes = $rec['note'] ?? null;
                    $dbContent = $rec['content'] ?? null;
                }
            }
        } catch (\Throwable $e) {
            // Silencioso
        }
    }

    echo json_encode([
        'success' => true,
        'filename' => $filename,
        'type' => pathinfo($filename, PATHINFO_EXTENSION),
        'disk_hash' => $diskHash,
        'db_hash' => $dbHash,
        'has_drift' => ($dbHash !== null && $dbHash !== $diskHash),
        'batch' => $batch,
        'applied_at' => $appliedAt,
        'git_commit' => $gitCommit,
        'git_branch' => $gitBranch,
        'notes' => $notes,
        'content' => $content,
        'db_content' => $dbContent,
    ]);
    exit;
}

// Endpoint API para servir el Manual Técnico
if (isset($_GET['api_manual'])) {
    header('Content-Type: text/plain; charset=utf-8');
    $manualPath = __DIR__ . '/../docs/manual-tecnico.md';
    if (file_exists($manualPath)) {
        echo file_get_contents($manualPath);
    } else {
        echo "# Manual de Uso Técnico\n\nNo se encontró el archivo de manual en `docs/manual-tecnico.md`.";
    }
    exit;
}

// Resumen ligero de entornos para la barra lateral (Lazy / Sin bloquear la página)
$sidebarSummary = [];
foreach ($environments as $key => $envConfig) {
    // Solo el entorno actualmente activo se inspecciona síncronamente, los demás cargan al instante
    $sidebarSummary[$key] = [
        'status' => ($key === $selectedEnvKey) ? 'checking' : 'ready',
        'pending' => 0,
        'applied' => 0,
        'total' => 0,
    ];
}


// Datos detallados del entorno activo
$activeInspection = null;
$batches = [];
$activeDriver = null;
$connectionError = null;

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
        } else {
            $connectionError = 'No se pudo conectar a la base de datos del entorno activo. Verifica credenciales y disponibilidad del servidor.';
        }
    } catch (\Throwable $e) {
        $connectionError = 'Error de conexión: ' . $e->getMessage();
    }
}

$pendingList = $activeInspection['pending'] ?? [];
$divergentList = $activeInspection['divergent'] ?? [];
$gitBranches = $stateEngine->getGitBranches();
if (empty($gitBranches) && !empty($gitInfo['branch'])) {
    $gitBranches = [$gitInfo['branch']];
}

// Migraciones del último lote aplicado (para el modal de rollback), derivadas
// de la inspección ya cargada: el batch más alto presente en la tabla de metadatos.
$lastBatchMigrations = [];
if (!empty($batches)) {
    $lastBatchNum = array_key_last($batches);
    foreach ($batches[$lastBatchNum] as $item) {
        $lastBatchMigrations[] = [
            'name' => $item['db']['name'],
            'batch' => $item['db']['batch'],
        ];
    }
}

// Snapshots de respaldo del entorno activo (para restauración puntual)
$envBackups = $selectedEnv
    ? $stateEngine->findBackups($config->getBackupDir(), (string) ($selectedEnv['database'] ?? ''), 8)
    : [];
?>
<!DOCTYPE html>
<html lang="es" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYNCDERO — Database Schema Version Flow</title>
    <link rel="icon" type="image/x-icon" href="/assets/logo/isotipo-modo-claro-oscuro.webp">
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
        <symbol id="icon-help" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="10"></circle>
            <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
            <line x1="12" y1="17" x2="12.01" y2="17"></line>
        </symbol>
        <symbol id="icon-undo" viewBox="0 0 24 24">
            <polyline points="1 4 1 10 7 10"></polyline>
            <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
        </symbol>
        <symbol id="icon-user" viewBox="0 0 24 24">
            <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"></path>
            <circle cx="12" cy="7" r="4"></circle>
        </symbol>
        <symbol id="icon-table" viewBox="0 0 24 24">
            <rect width="18" height="18" x="3" y="3" rx="2"></rect>
            <path d="M3 9h18"></path>
            <path d="M3 15h18"></path>
            <path d="M9 3v18"></path>
        </symbol>
        <symbol id="icon-layout" viewBox="0 0 24 24">
            <rect width="18" height="18" x="3" y="3" rx="2"></rect>
            <path d="M3 9h18"></path>
            <path d="M9 21V9"></path>
        </symbol>
        <symbol id="icon-menu" viewBox="0 0 24 24">
            <line x1="4" x2="20" y1="12" y2="12"></line>
            <line x1="4" x2="20" y1="6" y2="6"></line>
            <line x1="4" x2="20" y1="18" y2="18"></line>
        </symbol>
        <symbol id="icon-x" viewBox="0 0 24 24">
            <path d="M18 6 6 18"></path>
            <path d="m6 6 12 12"></path>
        </symbol>
    </svg>

    <!-- Barra de Navegación Superior -->
    <header class="topbar">
        <div class="brand-section">
            <button type="button" class="btn btn-icon mobile-only" id="mobileMenuBtn" onclick="toggleMobileSidebar()" title="Ver Entornos de BD">
                <svg class="icon"><use href="#icon-menu"></use></svg>
            </button>
            <a href="/" class="brand-logo" title="SYNCDERO Home">
                <!-- Modo Claro: Isotipo Claro-Oscuro + Logo Texto Modo Claro -->
                <img src="/assets/logo/isotipo-modo-claro-oscuro.webp" alt="SYNCDERO Isotipo" class="brand-isotipo-img logo-light">
                <img src="/assets/logo/logo-texto-modo-claro.webp" alt="SYNCDERO" class="brand-texto-img logo-light">

                <!-- Modo Oscuro: Isotipo Oscuro-Claro + Logo Texto Modo Oscuro -->
                <img src="/assets/logo/isotipo-modo-oscuro-claro.webp" alt="SYNCDERO Isotipo" class="brand-isotipo-img logo-dark" style="display: none;">
                <img src="/assets/logo/logo-texto-modo-oscuro.webp" alt="SYNCDERO" class="brand-texto-img logo-dark" style="display: none;">
            </a>
            <span class="breadcrumb-divider">/</span>
            <div class="env-selector-pill" onclick="toggleMobileSidebar()">
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

            <!-- Selector de Vista: Tabla / Línea de Tiempo vs Lienzo Visual -->
            <div class="view-mode-toggle" id="viewModeToggle">
                <button type="button" class="view-btn active" id="viewBtnTable" onclick="switchViewMode('table')" title="Vista Tabla / Historial Estructurado">
                    <svg class="icon" style="width:13px; height:13px;"><use href="#icon-table"></use></svg>
                    <span>Historial</span>
                </button>
                <button type="button" class="view-btn" id="viewBtnCanvas" onclick="switchViewMode('canvas')" title="Vista Lienzo Visual / Diagrama de Flujo">
                    <svg class="icon" style="width:13px; height:13px;"><use href="#icon-layout"></use></svg>
                    <span>Lienzo</span>
                </button>
            </div>
        </div>

        <div class="topbar-actions">
            <!-- Perfil de Desarrollador Activo (Firma y Credenciales) -->
            <button type="button" class="btn dev-profile-btn" onclick="openProfileModal()" title="Perfil del Desarrollador y Credenciales de Sesión">
                <div class="dev-avatar-circle" id="topbarAvatarLetter">
                    <?= strtoupper(substr($gitAuthor['name'] ?? 'D', 0, 1)) ?>
                </div>
                <span id="topbarDevName" style="max-width: 110px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                    <?= htmlspecialchars($gitAuthor['name'] ?? 'Developer') ?>
                </span>
            </button>

            <button type="button" class="btn btn-icon" onclick="openHelpModal()" title="Manual de Uso Técnico">
                <svg class="icon"><use href="#icon-help"></use></svg>
            </button>
            <button id="themeToggleBtn" class="btn btn-icon" title="Alternar Modo Oscuro / Claro">
                <svg id="iconMoon" class="icon"><use href="#icon-moon"></use></svg>
                <svg id="iconSun" class="icon" style="display:none;"><use href="#icon-sun"></use></svg>
            </button>
            <button type="button" class="btn" onclick="openServerModal()" title="Configurar Servidores y Motores (Estilo HeidiSQL)">
                <svg class="icon"><use href="#icon-database"></use></svg>
                <span>Servidores</span>
            </button>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="backup">
                <button type="submit" class="btn" title="Generar Snapshot manual">
                    <svg class="icon"><use href="#icon-save"></use></svg>
                    <span>Snapshot</span>
                </button>
            </form>
            <button type="button" class="btn" onclick="openRollbackModal()" title="Revertir el último lote de migraciones aplicado" <?= empty($lastBatchMigrations) ? 'disabled' : '' ?>>
                <svg class="icon"><use href="#icon-undo"></use></svg>
                <span>Rollback</span>
            </button>

            <button type="button" class="btn btn-primary" onclick="openSyncModal()" <?= (empty($pendingList) || !empty($divergentList)) ? 'disabled' : '' ?>>
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
        <aside class="sidebar-left" id="sidebarLeft">
            <div class="section-label" style="display: flex; justify-content: space-between; align-items: center;">
                <span>Entornos de BD (<?= count($environments) ?>)</span>
                <button type="button" class="btn btn-icon mobile-only" onclick="toggleMobileSidebar()" style="width:22px; height:22px; border:none; background:transparent;" title="Cerrar">
                    <svg class="icon" style="width:14px; height:14px;"><use href="#icon-x"></use></svg>
                </button>
            </div>
            <ul class="env-nav-list">
                <?php foreach ($environments as $envKey => $env): ?>
                    <?php 
                        $summary = $sidebarSummary[$envKey] ?? null;
                        $isActive = ($envKey === $selectedEnvKey);
                    ?>
                    <li>
                        <a href="?env=<?= urlencode($envKey) ?>" class="env-nav-item <?= $isActive ? 'active' : '' ?>" data-env-key="<?= htmlspecialchars($envKey) ?>">
                            <div class="env-item-meta">
                                <svg class="icon" style="opacity: 0.6;"><use href="#icon-database"></use></svg>
                                <span><?= htmlspecialchars($env['label'] ?? $envKey) ?></span>
                            </div>
                            <span class="status-badge badge-muted env-status-pill" id="badge-<?= htmlspecialchars($envKey) ?>">
                                <?= $isActive ? 'ACTIVO' : 'CONECTAR' ?>
                            </span>
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

        <!-- 2. Canvas Central: Dot Grid & Draggable Flow Cards / Vista Tabla Lineal -->
        <main class="canvas-area" id="mainWorkspaceArea">
            
            <!-- Watermark Desktop Background (Low opacity centered + slight blur) -->
            <img src="/assets/logo/imagotipo-modo-claro-oscuro.webp" alt="SYNCDERO Watermark" class="canvas-watermark logo-light">
            <img src="/assets/logo/imagotipo-modo-oscuro-claro.webp" alt="SYNCDERO Watermark" class="canvas-watermark logo-dark" style="display: none;">

            <!-- A. VISTA 1 (POR DEFECTO): Tabla / Línea de Tiempo Estructurada -->
            <div class="timeline-table-view" id="timelineTableView">
                <div class="timeline-table-header">
                    <div class="timeline-title-row">
                        <div>
                            <h2 style="font-size: 15px; font-weight: 700; letter-spacing: -0.2px;">Control de Versiones de Base de Datos</h2>
                            <p style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                                Entorno: <strong><?= htmlspecialchars($selectedEnv['label'] ?? $selectedEnvKey) ?></strong> · 
                                Motor: <strong><?= strtoupper($selectedEnv['driver'] ?? 'N/A') ?></strong> · 
                                Sincronizados: <strong><?= $activeInspection['total_applied'] ?? 0 ?> / <?= $activeInspection['total_disk'] ?? 0 ?></strong>
                            </p>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <?php if (!empty($pendingList)): ?>
                                <button type="button" class="btn btn-primary" onclick="openSyncModal()">
                                    <svg class="icon" style="stroke: #ffffff;"><use href="#icon-play"></use></svg>
                                    <span>Sincronizar Pendientes (<?= count($pendingList) ?>)</span>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="table-card">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width: 130px;">Estado</th>
                                <th>Migración / Script</th>
                                <th style="width: 80px;">Lote</th>
                                <th style="width: 140px;">Aplicado Por</th>
                                <th style="width: 130px;">Fecha</th>
                                <th style="width: 110px;">Hash SHA</th>
                                <th style="width: 80px; text-align: right;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Divergencias primero si las hay -->
                            <?php foreach ($divergentList as $name => $divItem): ?>
                                <tr class="row-drift" onclick="openDiffModal('<?= htmlspecialchars($name, ENT_QUOTES) ?>')">
                                    <td><span class="status-badge badge-danger">DIVERGENTE</span></td>
                                    <td>
                                        <div style="font-weight: 600;"><?= htmlspecialchars($divItem['file']->basename) ?></div>
                                        <div style="font-size: 10px; color: var(--state-danger-text);">El script en disco no coincide con el hash en la BD</div>
                                    </td>
                                    <td><span style="font-family: var(--font-mono);">#<?= $divItem['db']['batch'] ?></span></td>
                                    <td><?= htmlspecialchars($divItem['db']['applied_by'] ?? 'sistema') ?></td>
                                    <td><?= htmlspecialchars($divItem['db']['applied_at'] ?? 'N/A') ?></td>
                                    <td><code style="font-size: 10px;"><?= substr($divItem['file']->hash, 0, 8) ?>...</code></td>
                                    <td style="text-align: right;">
                                        <button type="button" class="btn btn-sm btn-danger" style="font-size: 10px; padding: 2px 6px;">Ver Diff</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <!-- Migraciones Pendientes -->
                            <?php foreach ($pendingList as $item): ?>
                                <tr class="row-pending" onclick="openDiffModal('<?= htmlspecialchars($item['file']->basename, ENT_QUOTES) ?>')">
                                    <td><span class="status-badge badge-warn">PENDIENTE</span></td>
                                    <td>
                                        <div style="font-weight: 600;"><?= htmlspecialchars($item['file']->basename) ?></div>
                                        <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($item['file']->description) ?></div>
                                    </td>
                                    <td><span style="color: var(--text-muted); font-size: 11px;">Próximo</span></td>
                                    <td><span style="color: var(--text-muted); font-size: 11px;">En espera</span></td>
                                    <td><span style="color: var(--text-muted); font-size: 11px;">Sin aplicar</span></td>
                                    <td><code style="font-size: 10px;"><?= substr($item['file']->hash, 0, 8) ?>...</code></td>
                                    <td style="text-align: right;">
                                        <button type="button" class="btn btn-sm" style="font-size: 10px; padding: 2px 6px;">Ver SQL</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <!-- Migraciones Aplicadas en orden cronológico inverso -->
                            <?php 
                                $appliedRows = [];
                                foreach ($batches as $bNum => $items) {
                                    foreach ($items as $it) {
                                        $appliedRows[] = array_merge($it, ['batch_num' => $bNum]);
                                    }
                                }
                                $appliedRows = array_reverse($appliedRows);
                            ?>
                            <?php if (empty($appliedRows) && empty($pendingList) && empty($divergentList)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 32px;">
                                        No hay migraciones registradas para este entorno.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($appliedRows as $row): ?>
                                    <tr onclick="openDiffModal('<?= htmlspecialchars($row['file']->basename, ENT_QUOTES) ?>')">
                                        <td><span class="status-badge badge-sync">SINCRONIZADO</span></td>
                                        <td>
                                            <div style="font-weight: 500;"><?= htmlspecialchars($row['file']->basename) ?></div>
                                            <?php if (!empty($row['db']['note'])): ?>
                                                <div style="font-size: 10px; color: var(--text-muted); font-style: italic;">"<?= htmlspecialchars($row['db']['note']) ?>"</div>
                                            <?php endif; ?>
                                        </td>
                                        <td><span style="font-family: var(--font-mono); font-weight: 600;">#<?= $row['batch_num'] ?></span></td>
                                        <td>
                                            <div style="font-size: 11px;"><?= htmlspecialchars($row['db']['applied_by'] ?? 'sistema') ?></div>
                                            <?php if (!empty($row['db']['git_commit'])): ?>
                                                <div style="font-family: var(--font-mono); font-size: 10px; color: var(--text-muted);"><?= htmlspecialchars($row['db']['git_commit']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size: 11px;"><?= htmlspecialchars($row['db']['applied_at'] ?? 'N/A') ?></td>
                                        <td><code style="font-size: 10px;"><?= substr($row['file']->hash, 0, 8) ?>...</code></td>
                                        <td style="text-align: right;">
                                            <button type="button" class="btn btn-sm" style="font-size: 10px; padding: 2px 6px;">Detalle</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- B. VISTA 2 (OPCIONAL): Diagrama / Lienzo de Nodos Flow -->
            <div class="flow-viewport" id="flowViewport" style="display: none;">
                <!-- SVG Wire Connections -->
                <svg id="flowSvgWires" class="flow-connections-svg"></svg>

                <?php if ($connectionError): ?>
                    <div style="position: absolute; top: 40px; left: 40px; max-width: 420px; background: var(--state-danger-bg); color: var(--state-danger-text); border: 1px solid var(--state-danger-border); border-radius: 4px; padding: 14px 16px; font-size: 12px; display: flex; gap: 10px; align-items: flex-start;">
                        <svg class="icon"><use href="#icon-alert"></use></svg>
                        <div>
                            <strong>No se puede inspeccionar el entorno</strong><br>
                            <?= htmlspecialchars($connectionError) ?>
                        </div>
                    </div>
                <?php elseif ($activeInspection === null && $selectedEnv === null): ?>
                    <div style="position: absolute; top: 40px; left: 40px; color: var(--text-subtle); font-size: 13px;">
                        No hay entornos configurados. Abre <strong>Servidores</strong> para añadir una conexión.
                    </div>
                <?php endif; ?>


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

            <!-- Botones flotantes móviles para abrir paneles laterales -->
            <div class="mobile-floating-actions mobile-only">
                <button type="button" class="btn btn-sm" onclick="toggleMobileSidebar()">
                    <svg class="icon"><use href="#icon-database"></use></svg>
                    <span>Entornos</span>
                </button>
                <button type="button" class="btn btn-sm btn-brand" onclick="toggleMobileInspector()">
                    <svg class="icon"><use href="#icon-layers"></use></svg>
                    <span>Inspector (<?= ($activeInspection['total_disk'] ?? 0) - ($activeInspection['total_applied'] ?? 0) ?>)</span>
                </button>
            </div>
        </main>

        <!-- 3. Barra Lateral Derecha: Inspector & Staged Files -->
        <aside class="sidebar-right" id="sidebarRight">
            <div class="sidebar-right-header">
                <span>Inspector de Cambios</span>
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="font-family: var(--font-mono); color: var(--text-muted); font-size: 11px;">
                        <?= $activeInspection['total_applied'] ?? 0 ?>/<?= $activeInspection['total_disk'] ?? 0 ?>
                    </span>
                    <button type="button" class="btn btn-icon mobile-only" onclick="toggleMobileInspector()" style="width:20px; height:20px; border:none; background:transparent;" title="Cerrar">
                        <svg class="icon" style="width:14px; height:14px;"><use href="#icon-x"></use></svg>
                    </button>
                </div>
            </div>

            <!-- Alertas de Conflicto / Drift -->
            <?php if (!empty($divergentList)): ?>
                <div class="inspector-section" style="background: var(--state-danger-bg);">
                    <div style="font-size: 11px; font-weight: 700; color: var(--state-danger-text); margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                        <svg class="icon"><use href="#icon-alert"></use></svg>
                        <span>Divergencias Detectadas (<?= count($divergentList) ?>)</span>
                    </div>
                    <?php foreach ($divergentList as $name => $item): ?>
                        <div class="file-row drift" onclick="openDiffModal('<?= htmlspecialchars($name, ENT_QUOTES) ?>')" title="Ver divergencia y diff" style="cursor: pointer;">
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
                        <div class="file-row" onclick="openDiffModal('<?= htmlspecialchars($item['file']->basename, ENT_QUOTES) ?>')" title="Ver código SQL y hash" style="cursor: pointer;">
                            <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= htmlspecialchars($item['file']->basename) ?></span>
                            <span style="color: var(--text-muted);"><?= strtoupper($item['file']->type) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Snapshots de Respaldo (Restauración Puntual) -->
            <div class="inspector-section">
                <div style="font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; margin-bottom: 8px;">
                    Snapshots de Respaldo
                </div>
                <?php if (empty($envBackups)): ?>
                    <div style="color: var(--text-subtle); font-size: 12px; padding: 6px 0;">
                        No hay snapshots de este entorno. Genera uno con el botón <strong>Snapshot</strong>.
                    </div>
                <?php else: ?>
                    <?php foreach ($envBackups as $bk): ?>
                        <form method="POST" style="display:flex; flex-direction:column; gap:4px; padding:6px 8px; border:1px solid var(--border-color); border-radius:4px; margin-bottom:6px;">
                            <input type="hidden" name="action" value="restore">
                            <input type="hidden" name="backup_file" value="<?= htmlspecialchars($bk['name']) ?>">
                            <div style="font-family: var(--font-mono); font-size:10px; color: var(--text-primary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= htmlspecialchars($bk['name']) ?>">
                                <?= htmlspecialchars($bk['name']) ?>
                            </div>
                            <div style="display:flex; justify-content:space-between; align-items:center; gap:6px;">
                                <span style="font-size:10px; color:var(--text-muted);"><?= date('Y-m-d H:i', $bk['modified']) ?> · <?= number_format($bk['size'] / 1024, 1) ?> KB</span>
                                <button type="submit" class="btn" style="padding:2px 8px; font-size:10px;" title="Restaurar este snapshot en el entorno activo">Restaurar</button>
                            </div>
                            <?php if (($selectedEnv['protected'] ?? false) === true): ?>
                                <input type="password" name="password" placeholder="Contraseña de autorización..." required style="font-size:10px;">
                            <?php endif; ?>
                        </form>
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
                    
                    <!-- Imagotipo en Ventana de Autorización / Confirmación -->
                    <img src="/assets/logo/imagotipo-modo-claro-oscuro.webp" alt="SYNCDERO" class="modal-watermark-logo logo-light">
                    <img src="/assets/logo/imagotipo-modo-oscuro-claro.webp" alt="SYNCDERO" class="modal-watermark-logo logo-dark" style="display: none;">

                    <p style="margin-bottom: 8px; font-weight: 500; text-align: center;">
                        ¿Deseas aplicar las <?= count($pendingList) ?> migraciones pendientes al entorno <strong><?= htmlspecialchars($selectedEnv['label'] ?? $selectedEnvKey) ?></strong>?
                    </p>
                    <p style="font-size: 11px; color: var(--text-muted); margin-bottom: 12px; text-align: center;">
                        Se creará un respaldo atómico automático en <code>backups/</code> antes de ejecutar los cambios.
                    </p>

                    <div style="background: var(--bg-subtle); border: 1px solid var(--border-color); border-radius: 4px; padding: 10px; margin-bottom: 12px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-size: 11px;">
                            <span style="color: var(--text-secondary); display: flex; align-items: center; gap: 4px;">
                                <svg class="icon" style="width:12px; height:12px;"><use href="#icon-git-branch"></use></svg>
                                <strong>Rama Git vinculada:</strong>
                            </span>
                            <span style="font-family: var(--font-mono); font-weight: 600; color: var(--text-primary); background: var(--bg-surface); padding: 2px 6px; border: 1px solid var(--border-color); border-radius: 3px;">
                                <?= htmlspecialchars($gitInfo['branch']) ?> (<?= htmlspecialchars($gitInfo['commit']) ?>)
                            </span>
                        </div>

                        <div>
                            <label style="display: block; font-size: 11px; font-weight: 500; color: var(--text-secondary); margin-bottom: 4px;">
                                Nota descriptiva del lote (opcional):
                            </label>
                            <input type="text" name="notes" placeholder="Ej: Fix tabla clientes, sprint 4..." style="width: 100%;">
                        </div>

                        <div style="margin-top: 8px; font-size: 10px; color: var(--text-muted); display: flex; align-items: center; gap: 4px;">
                            <svg class="icon" style="width:11px; height:11px;"><use href="#icon-user"></use></svg>
                            <span>Firma de autor: <strong id="syncModalAuthorPreview" style="color: var(--text-primary);"><?= htmlspecialchars($gitAuthor['signature']) ?></strong></span>
                        </div>
                    </div>

                    <input type="hidden" name="author_name" id="syncInputAuthorName" value="<?= htmlspecialchars($gitAuthor['name']) ?>">
                    <input type="hidden" name="author_email" id="syncInputAuthorEmail" value="<?= htmlspecialchars($gitAuthor['email']) ?>">

                    <?php if (($selectedEnv['protected'] ?? false) === true): ?>
                        <div style="margin-top: 12px;">
                            <label style="display: block; font-size: 11px; font-weight: 600; margin-bottom: 4px; color: var(--state-danger-text);">
                                Entorno protegido. Ingresa la contraseña de autorización:
                            </label>
                            <input type="password" name="password" placeholder="Contraseña..." required autofocus>
                        </div>
                    <?php elseif (($selectedEnv['protected'] ?? false) === 'warn'): ?>
                        <div style="margin-top: 12px; padding: 8px 10px; background: var(--state-warn-bg, rgba(245,158,11,.12)); border: 1px solid var(--state-warn-border, rgba(245,158,11,.35)); border-radius: 3px; font-size: 11px; color: var(--state-warn-text, #b45309);">
                            <strong>Entorno de pre-producción:</strong> verifica con tu equipo que estos cambios estén aprobados antes de aplicarlos.
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

    <!-- Modal de Configuración de Servidores (Estilo HeidiSQL) -->
    <div id="serverModal" class="modal-layer">
        <div class="modal-window" style="max-width: 580px;">
            <div class="modal-title-bar">
                <span>Gestión de Servidores y Conexiones (HeidiSQL Style)</span>
                <span onclick="closeServerModal()" style="cursor: pointer; color: var(--text-muted);">✕</span>
            </div>
            
            <div style="padding: 12px 16px; background: var(--bg-subtle); border-bottom: 1px solid var(--border-color); font-size: 11px;">
                <strong>Servidores Guardados:</strong>
                <div style="display: flex; gap: 8px; margin-top: 6px; flex-wrap: wrap;">
                    <?php $allServers = $config->getServers(); ?>
                    <?php if (empty($allServers)): ?>
                        <span style="color: var(--text-muted);">No hay servidores configurados manualmente.</span>
                    <?php else: ?>
                        <?php foreach ($allServers as $sKey => $sCfg): ?>
                            <form method="POST" style="display:inline-flex; align-items:center; background:var(--bg-surface); padding:3px 8px; border:1px solid var(--border-color); border-radius:3px;">
                                <input type="hidden" name="action" value="delete_server">
                                <input type="hidden" name="server_key" value="<?= htmlspecialchars($sKey) ?>">
                                <span style="font-weight:600; font-size:11px; margin-right:6px;"><?= htmlspecialchars($sCfg['label'] ?? $sKey) ?></span>
                                <span style="font-size:10px; color:var(--text-muted); margin-right:6px;">(<?= strtoupper($sCfg['driver']) ?>:<?= $sCfg['port'] ?>)</span>
                                <button type="submit" style="background:none; border:none; color:var(--state-danger-text); cursor:pointer; font-weight:700;" title="Eliminar servidor">✕</button>
                            </form>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <form method="POST">
                <div class="modal-content">
                    <input type="hidden" name="action" value="save_server">
                    <div style="font-weight: 600; font-size: 12px; margin-bottom: 10px;">
                        Nueva Conexión de Servidor
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 8px;">
                        <div>
                            <label style="font-size: 11px; font-weight: 500; color: var(--text-secondary);">Identificador / Etiqueta:</label>
                            <input type="text" name="server_label" placeholder="Ej: MariaDB Local" required>
                        </div>
                        <div>
                            <label style="font-size: 11px; font-weight: 500; color: var(--text-secondary);">Motor de Base de Datos:</label>
                            <select name="server_driver" style="width:100%; padding:6px; border-radius:2px; border:1px solid var(--border-color); background:var(--bg-surface); color:var(--text-primary); font-size:12px; margin-top:2px;">
                                <option value="mysql">MariaDB / MySQL</option>
                                <option value="pgsql">PostgreSQL</option>
                                <option value="sqlite">SQLite</option>
                            </select>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 10px; margin-bottom: 8px;">
                        <div>
                            <label style="font-size: 11px; font-weight: 500; color: var(--text-secondary);">Host / IP:</label>
                            <input type="text" name="server_host" value="127.0.0.1" required>
                        </div>
                        <div>
                            <label style="font-size: 11px; font-weight: 500; color: var(--text-secondary);">Puerto:</label>
                            <input type="number" name="server_port" value="3306" required>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 10px; margin-bottom: 8px;">
                        <div>
                            <label style="font-size: 11px; font-weight: 500; color: var(--text-secondary);">Base de datos (vacío + Auto-Discovery = todas):</label>
                            <input type="text" name="server_database" placeholder="Ej: app_db">
                        </div>
                        <div>
                            <label style="font-size: 11px; font-weight: 500; color: var(--text-secondary);">Rol del entorno:</label>
                            <select name="server_role" style="width:100%; padding:6px; border-radius:2px; border:1px solid var(--border-color); background:var(--bg-surface); color:var(--text-primary); font-size:12px; margin-top:2px;">
                                <option value="dev">Dev</option>
                                <option value="staging">Staging</option>
                                <option value="prod">Prod</option>
                            </select>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px;">
                        <div>
                            <label style="font-size: 11px; font-weight: 500; color: var(--text-secondary);">Usuario:</label>
                            <input type="text" name="server_user" value="root">
                        </div>
                        <div>
                            <label style="font-size: 11px; font-weight: 500; color: var(--text-secondary);">Contraseña:</label>
                            <input type="password" name="server_password" placeholder="Contraseña...">
                        </div>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 6px; padding: 8px; background: var(--bg-subtle); border: 1px solid var(--border-color); border-radius: 3px;">
                        <label style="font-size: 11px; display: flex; align-items: center; gap: 6px; cursor: pointer;">
                            <input type="checkbox" name="server_autodiscover" checked>
                            <span><strong>Auto-Discovery activo:</strong> Detectar y listar automáticamente todas las bases de datos de este host (<code>SHOW DATABASES</code>).</span>
                        </label>
                        <label style="font-size: 11px; display: flex; align-items: center; gap: 6px; cursor: pointer;">
                            <input type="checkbox" name="server_protected">
                            <span>Marcar como Entorno Protegido (requiere contraseña para sincronizar).</span>
                        </label>
                    </div>
                </div>

                <div class="modal-bottom">
                    <button type="button" class="btn" onclick="closeServerModal()">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Probar y Guardar Conexión</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal de Visor de Diff y Previsualización de Código -->
    <div id="diffModal" class="modal-layer">
        <div class="modal-window" style="max-width: 720px; width: 95%;">
            <div class="modal-title-bar">
                <span id="diffModalTitle">Inspección de Migración</span>
                <span onclick="closeDiffModal()" style="cursor: pointer; color: var(--text-muted);">✕</span>
            </div>
            <div class="modal-content" style="max-height: 75vh; overflow-y: auto;">
                <div id="diffMetadataBox" style="background: var(--bg-subtle); border: 1px solid var(--border-color); border-radius: 4px; padding: 10px; margin-bottom: 12px; font-size: 11px;">
                    <!-- Renderizado dinámico en app.js -->
                </div>
                <div style="font-size: 11px; font-weight: 600; color: var(--text-secondary); margin-bottom: 6px;">
                    Contenido del Script:
                </div>
                <div id="diffCodeArea"></div>
            </div>
            <div class="modal-bottom">
                <button type="button" class="btn" onclick="closeDiffModal()">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- Modal de Reversión / Rollback -->
    <div id="rollbackModal" class="modal-layer">
        <div class="modal-window">
            <div class="modal-title-bar">
                <span>Confirmar Reversión (Rollback)</span>
                <span onclick="closeRollbackModal()" style="cursor: pointer; color: var(--text-muted);">✕</span>
            </div>
            <form method="POST">
                <div class="modal-content">
                    <input type="hidden" name="action" value="rollback">
                    
                    <img src="/assets/logo/imagotipo-modo-claro-oscuro.webp" alt="SYNCDERO" class="modal-watermark-logo logo-light">
                    <img src="/assets/logo/imagotipo-modo-oscuro-claro.webp" alt="SYNCDERO" class="modal-watermark-logo logo-dark" style="display: none;">

                    <p style="margin-bottom: 8px; font-weight: 500; text-align: center;">
                        ¿Deseas revertir los cambios recientes de <strong><?= htmlspecialchars($selectedEnv['label'] ?? $selectedEnvKey) ?></strong> restaurando el snapshot más reciente?
                    </p>
                    <p style="font-size: 11px; color: var(--text-muted); margin-bottom: 12px; text-align: center;">
                        Se generará un respaldo de seguridad del estado actual antes de restaurar el snapshot.
                        La base de datos y su tabla de metadatos volverán al estado del snapshot (migraciones posteriores quedarán rebobinadas).
                    </p>

                    <?php if (!empty($lastBatchMigrations)): ?>
                        <div style="background: var(--bg-subtle); border: 1px solid var(--border-color); border-radius: 4px; padding: 8px 12px; margin-bottom: 12px; font-size: 11px;">
                            <strong>Migraciones en el último lote (Batch #<?= htmlspecialchars((string)($lastBatchMigrations[0]['batch'] ?? '')) ?>):</strong>
                            <ul style="margin: 6px 0 0 16px; color: var(--text-secondary);">
                                <?php foreach ($lastBatchMigrations as $lbm): ?>
                                    <li><?= htmlspecialchars($lbm['name']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

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
                    <button type="button" class="btn" onclick="closeRollbackModal()">Cancelar</button>
                    <button type="submit" class="btn btn-primary" style="background: var(--state-danger-text); border-color: var(--state-danger-text);">Revertir Lote</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal de Manual de Uso Técnico -->
    <div id="helpModal" class="modal-layer">
        <div class="modal-window" style="max-width: 760px; width: 95%;">
            <div class="modal-title-bar">
                <span>Manual de Uso Técnico — SYNCDERO</span>
                <span onclick="closeHelpModal()" style="cursor: pointer; color: var(--text-muted);">✕</span>
            </div>
            <div class="modal-content" style="max-height: 75vh; overflow-y: auto;">
                <div id="helpModalContent" style="font-size: 12px; line-height: 1.6; color: var(--text-primary);">
                    Cargando manual técnico...
                </div>
            </div>
            <div class="modal-bottom">
                <button type="button" class="btn" onclick="closeHelpModal()">Entendido</button>
            </div>
        </div>
    </div>

    <!-- Modal de Perfil de Desarrollador y Credenciales de Sesión -->
    <div id="profileModal" class="modal-layer">
        <div class="modal-window" style="max-width: 480px;">
            <div class="modal-title-bar">
                <div style="display:flex; align-items:center; gap:8px;">
                    <svg class="icon"><use href="#icon-user"></use></svg>
                    <span>Perfil de Desarrollador & Credenciales</span>
                </div>
                <span onclick="closeProfileModal()" style="cursor: pointer; color: var(--text-muted);">✕</span>
            </div>
            <div class="modal-content">
                <p style="font-size: 12px; color: var(--text-secondary); margin-bottom: 14px;">
                    Configura tu firma personal para auditar qué desarrollador aplicó cada migración en la base de datos y guarda tu contraseña de entornos protegidos en la sesión del navegador.
                </p>

                <div style="margin-bottom: 12px;">
                    <label style="display: block; font-size: 11px; font-weight: 600; margin-bottom: 4px;">Nombre del Desarrollador:</label>
                    <input type="text" id="devProfileName" value="<?= htmlspecialchars($gitAuthor['name']) ?>" placeholder="Ej: Gisvel Aray">
                </div>

                <div style="margin-bottom: 12px;">
                    <label style="display: block; font-size: 11px; font-weight: 600; margin-bottom: 4px;">Email de Autoría:</label>
                    <input type="text" id="devProfileEmail" value="<?= htmlspecialchars($gitAuthor['email']) ?>" placeholder="Ej: gisvel@empresa.com">
                </div>

                <div style="margin-bottom: 14px;">
                    <label style="display: block; font-size: 11px; font-weight: 600; margin-bottom: 4px; color: var(--brand-primary);">
                        Contraseña de Entornos Protegidos (Sesión):
                    </label>
                    <input type="password" id="devProfilePassword" placeholder="Guardar clave en memoria temporal...">
                    <div style="font-size: 10px; color: var(--text-muted); margin-top: 4px;">
                        Se almacena únicamente en <code>sessionStorage</code> del navegador. Se autorrellenará automáticamente al sincronizar entornos protegidos (como Producción) sin guardarse en archivos.
                    </div>
                </div>

                <div style="background: var(--bg-subtle); border: 1px solid var(--border-color); border-radius: 4px; padding: 10px; font-size: 11px;">
                    <strong>Firma Resultante en la BD:</strong>
                    <div id="devProfileSignaturePreview" style="font-family: var(--font-mono); color: var(--text-primary); margin-top: 4px;">
                        <?= htmlspecialchars($gitAuthor['signature']) ?>
                    </div>
                </div>
            </div>
            <div class="modal-bottom">
                <button type="button" class="btn" onclick="closeProfileModal()">Cerrar</button>
                <button type="button" class="btn btn-primary" onclick="saveDeveloperProfile()">Guardar en Sesión</button>
            </div>
        </div>
    </div>

    <!-- Telón de fondo móvil para cerrar paneles deslizantes al pulsar fuera -->
    <div id="sidebarMobileBackdrop" class="sidebar-mobile-backdrop" onclick="closeMobileSidebars()"></div>

    <script src="/assets/app.js"></script>
</body>
</html>

