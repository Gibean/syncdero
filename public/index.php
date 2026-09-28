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

use Illuminate\Http\Request;
use Syncdero\Auth\AuthManager;
use Syncdero\Core\Config;
use Syncdero\Core\StateEngine;
use Syncdero\Drivers\DriverFactory;

$request = Request::capture();

Config::loadEnv(__DIR__ . '/../.env');

$configPath = getenv('SYNCDERO_CONFIG') ?: (__DIR__ . '/../config/syncdero.php');
if (!file_exists($configPath)) {
    $configPath = __DIR__ . '/config.php';
}

$config = Config::load($configPath);
$authManager = new AuthManager($config);

// Manejo de Logout
if ($request->query('action') === 'logout') {
    $authManager->logout();
    header('Location: /');
    exit;
}

// Manejo de Impersonación (Cambio rápido de rol/usuario)
if ($request->query('action') === 'switch_user') {
    $target = (string)$request->query('user');
    if ($authManager->getCurrentUser()->isAdmin() || $authManager->isImpersonating()) {
        $authManager->switchUser($target);
    }
    header('Location: /');
    exit;
}

if ($request->query('action') === 'stop_impersonating') {
    $authManager->stopImpersonating();
    header('Location: /');
    exit;
}

// En Modo Equipo, exigir autenticación perimetral
if ($authManager->isTeamMode() && !$authManager->isAuthenticated()) {
    $loginError = null;
    if ($request->isMethod('POST') && $request->input('action') === 'login') {
        $username = trim((string)$request->input('username'));
        $password = (string)$request->input('password');
        if ($authManager->login($username, $password)) {
            header('Location: /');
            exit;
        } else {
            $loginError = 'Credenciales no válidas. Verifique usuario y contraseña.';
        }
    }
    require __DIR__ . '/login.php';
    exit;
}

$currentUser = $authManager->getCurrentUser();
$csrfToken = $authManager->getCsrfToken();

// 1. Manejo del Proyecto Activo (Multi-Proyecto Open Source)
$allProjects = $config->getProjects();
$selectedProjectKey = (string)($request->query('project') ?: ($_SESSION['syncdero_active_project'] ?? array_key_first($allProjects) ?? 'default'));
if (!isset($allProjects[$selectedProjectKey])) {
    $selectedProjectKey = (string)(array_key_first($allProjects) ?? 'default');
}
$_SESSION['syncdero_active_project'] = $selectedProjectKey;
$config->setActiveProjectKey($selectedProjectKey);
$activeProject = $config->getActiveProject();

// 2. Inicializar StateEngine con el directorio de migraciones y repo Git del proyecto activo
$stateEngine = new StateEngine($config->getMigrationsDir(), $config->getRepoDir());
$gitInfo = $stateEngine->getGitInfo();
$gitAuthor = $stateEngine->getGitAuthor();

// 3. Filtrado de entornos según rol del usuario para el proyecto activo
$allEnvironments = $config->getEnvironments();
$environments = $authManager->filterEnvironments($allEnvironments);

// Entorno seleccionado
$selectedEnvKey = (string)($request->query('env') ?: (array_key_first($environments) ?? ''));
if ($selectedEnvKey !== '' && !isset($environments[$selectedEnvKey])) {
    $selectedEnvKey = (string)(array_key_first($environments) ?? '');
}
$selectedEnv = $environments[$selectedEnvKey] ?? null;
$autoOpenSyncModal = (bool)$request->query('auto_sync');

// Manejo de Acciones POST
$action = (string)$request->input('action');
$message = null;
$messageType = null;

if ($request->isMethod('POST')) {
    $submittedCsrf = (string)$request->input('_csrf');
    if (!$authManager->validateCsrfToken($submittedCsrf)) {
        $message = 'Error de seguridad: Token CSRF no válido o sesión expirada.';
        $messageType = 'danger';
        $action = ''; // Cancelar acción si CSRF falla
    }
}

if ($request->isMethod('POST') && $selectedEnv && $action !== '') {
    // Restricción de mutación según rol de usuario
    if (in_array($action, ['sync', 'rollback', 'restore', 'sync_from_mirror'], true) && !$currentUser->canMutateEnvironment($selectedEnvKey)) {
        $message = 'Acción bloqueada: Como desarrollador sólo tienes autorización para sincronizar o revertir tu propio espejo asignado.';
        $messageType = 'danger';
        $action = '';
    }

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
    } elseif ($action === 'sync_from_mirror') {
        $refEnvKey = $config->getReferenceEnv();
        $refEnvConfig = $allEnvironments[$refEnvKey] ?? null;
        if (!$refEnvConfig) {
            $message = "No se encontró el entorno de referencia principal ({$refEnvKey}).";
            $messageType = 'danger';
        } else {
            try {
                $refDriver = DriverFactory::create($refEnvConfig);
                $comp = $stateEngine->compareMirrors($refDriver, $driver);
                if (empty($comp['missing_in_target'])) {
                    $message = 'Tu espejo ya se encuentra 100% al día con la Base de Datos Principal.';
                    $messageType = 'info';
                } else {
                    $driver->createBackup($config->getBackupDir());
                    $appliedBy = $currentUser->getSignature() ?: ($gitAuthor['signature'] ?? 'developer');
                    $notes = "Nivelación dinámica desde {$refEnvKey}";
                    $migrationFiles = $stateEngine->getMigrationFiles();
                    $toApply = [];
                    foreach ($comp['missing_in_target'] as $missingRow) {
                        $mName = $missingRow['migration'];
                        if (isset($migrationFiles[$mName])) {
                            $toApply[] = $migrationFiles[$mName];
                        }
                    }
                    if (empty($toApply)) {
                        $message = 'No se encontraron en disco los archivos para las migraciones faltantes en tu espejo.';
                        $messageType = 'danger';
                    } else {
                        $syncRes = $stateEngine->syncPending($driver, $toApply, $appliedBy, $notes);
                        if ($syncRes['success']) {
                            $message = "¡Espejo nivelado exitosamente! Se aplicaron {$syncRes['applied_count']} migración(es) desde la BD Principal.";
                            $messageType = 'success';
                        } else {
                            $message = 'Error nivelando espejo: ' . json_encode($syncRes['errors']);
                            $messageType = 'danger';
                        }
                    }
                }
            } catch (\Throwable $e) {
                $message = 'Error al nivelar espejo desde Principal: ' . $e->getMessage();
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
                    $errDetail = method_exists($testDriver, 'getLastConnectionError') ? $testDriver->getLastConnectionError() : '';
                    $message = "No se pudo conectar al servidor: " . ($errDetail ?: 'credenciales o host incorrectos.');
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
    } elseif ($action === 'delete_backup') {
        $backupName = basename(trim($_POST['backup_file'] ?? ''));
        $targetFile = $config->getBackupDir() . DIRECTORY_SEPARATOR . $backupName;
        if ($backupName !== '' && file_exists($targetFile)) {
            @unlink($targetFile);
            $message = "Snapshot '{$backupName}' eliminado correctamente.";
            $messageType = 'info';
        } else {
            $message = 'No se pudo encontrar el snapshot a eliminar.';
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

// Evaluación de modo: BD Única vs Ecosistema Multi-Entorno / Espejos
$hasMultipleEnvs = $config->hasMultipleEnvironments();
$hasMirrors = $config->hasMirrors();
$referenceEnvKey = $config->getReferenceEnv();
$environmentOwners = $config->getEnvironmentOwners();

// Matriz General para la vista inicial del Administrador o pestaña "General"
$mirrorsOverview = null;
if ($hasMultipleEnvs) {
    try {
        $mirrorsOverview = $stateEngine->getMirrorsOverview($environments, $referenceEnvKey, $environmentOwners);
    } catch (\Throwable) {
        $mirrorsOverview = null;
    }
}

// Resumen ligero de entornos para la barra lateral (Lazy / Sin bloquear la página)
$sidebarSummary = [];
foreach ($environments as $key => $envConfig) {
    // Si ya tenemos datos de la matriz general, los reflejamos en el sidebar
    $mState = $mirrorsOverview['environments'][$key] ?? null;
    $sidebarSummary[$key] = [
        'status' => $mState ? $mState['status'] : (($key === $selectedEnvKey) ? 'checking' : 'ready'),
        'pending' => $mState ? ($mState['status'] === 'ATRASADO' ? $mState['diff_count'] : 0) : 0,
        'applied' => $mState ? $mState['applied'] : 0,
        'total' => $mState ? $mState['total'] : 0,
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
    <link rel="stylesheet" href="/assets/icons.css?v=<?= @filemtime(__DIR__ . '/assets/icons.css') ?: time() ?>">
    <link rel="stylesheet" href="/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: time() ?>">
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
        <symbol id="icon-log-out" viewBox="0 0 24 24">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
            <polyline points="16 17 21 12 16 7"></polyline>
            <line x1="21" y1="12" x2="9" y2="12"></line>
        </symbol>
        <symbol id="icon-refresh-cw" viewBox="0 0 24 24">
            <polyline points="23 4 23 10 17 10"></polyline>
            <polyline points="1 20 1 14 7 14"></polyline>
            <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
        </symbol>
        <symbol id="icon-more-horizontal" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="1.5"></circle>
            <circle cx="19" cy="12" r="1.5"></circle>
            <circle cx="5" cy="12" r="1.5"></circle>
        </symbol>
        <symbol id="icon-folder" viewBox="0 0 24 24">
            <path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"></path>
        </symbol>
        <symbol id="icon-chevron-down" viewBox="0 0 24 24">
            <polyline points="6 9 12 15 18 9"></polyline>
        </symbol>
        <symbol id="icon-grid" viewBox="0 0 24 24">
            <rect width="7" height="7" x="3" y="3" rx="1"></rect>
            <rect width="7" height="7" x="14" y="3" rx="1"></rect>
            <rect width="7" height="7" x="14" y="14" rx="1"></rect>
            <rect width="7" height="7" x="3" y="14" rx="1"></rect>
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
            <!-- Selector de Proyecto Activo (Open Source Multi-Proyecto) -->
            <div class="project-selector-container">
                <button type="button" class="project-selector-pill" onclick="toggleProjectDropdown(event)" title="Proyecto activo: <?= htmlspecialchars($activeProject['name'] ?? $selectedProjectKey) ?>">
                    <svg class="icon" style="width:12px; height:12px; color: var(--brand-blue);"><use href="#icon-folder"></use></svg>
                    <span><?= htmlspecialchars($activeProject['name'] ?? $selectedProjectKey) ?></span>
                    <svg class="icon" style="width:10px; height:10px; opacity:0.6;"><use href="#icon-chevron-down"></use></svg>
                </button>
                <div class="project-dropdown-menu" id="projectDropdownMenu">
                    <div style="padding: 6px 12px; font-size: 10px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">
                        Proyectos Configurados:
                    </div>
                    <?php foreach ($allProjects as $pKey => $pData): ?>
                        <?php 
                            $envCount = count($pData['environments'] ?? []);
                            $srvCount = count($pData['servers'] ?? []);
                            $descText = $srvCount > 0 ? "{$srvCount} servidor(es)" : "{$envCount} entorno(s)";
                        ?>
                        <a href="?project=<?= urlencode($pKey) ?>" class="project-item-link <?= $pKey === $selectedProjectKey ? 'active' : '' ?>">
                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                <strong style="font-size:12px;"><?= htmlspecialchars($pData['name'] ?? $pKey) ?></strong>
                                <?php if ($pKey === $selectedProjectKey): ?>
                                    <span class="badge-role-pill dev" style="font-size:8px;">ACTIVO</span>
                                <?php endif; ?>
                            </div>
                            <small style="font-family:var(--font-mono); font-size:10px; color:var(--text-muted); margin-top:2px;">
                                <?= htmlspecialchars(basename($pData['repo_dir'] ?? './.git')) ?> · <?= $descText ?>
                            </small>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <span class="breadcrumb-divider">/</span>
            <div class="env-selector-pill" onclick="toggleMobileSidebar()" title="Entorno Activo">
                <span class="status-pulse-dot" title="Conexión Activa"></span>
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

            <!-- Selector de Vista: General (Matriz de Espejos) vs Tabla / Historial vs Lienzo Visual -->
            <div class="view-mode-toggle" id="viewModeToggle">
                <?php if ($hasMultipleEnvs): ?>
                    <button type="button" class="view-btn" id="viewBtnOverview" onclick="switchViewMode('overview')" title="Vista General: Matriz de Espejos y Estado de Entornos">
                        <svg class="icon" style="width:13px; height:13px;"><use href="#icon-grid"></use></svg>
                        <span>General</span>
                    </button>
                <?php endif; ?>
                <button type="button" class="view-btn active" id="viewBtnTable" onclick="switchViewMode('table')" title="Vista Tabla / Historial Estructurado de la BD">
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
            <!-- Menú de Usuario / Perfil / Impersonación -->
            <div class="user-profile-menu-container" style="position: relative;">
                <button type="button" class="btn dev-profile-btn" onclick="toggleUserDropdown(event)" title="Cuenta activa: <?= htmlspecialchars($currentUser->name) ?> (<?= htmlspecialchars($currentUser->role) ?>)">
                    <div class="dev-avatar-circle" id="topbarAvatarLetter" style="background: <?= $currentUser->isAdmin() ? 'var(--brand-blue)' : '#059669' ?>;">
                        <?= strtoupper(substr($currentUser->name ?: $currentUser->username, 0, 1)) ?>
                    </div>
                    <span id="topbarDevName" class="hide-on-laptop">
                        <?= htmlspecialchars($currentUser->name ?: $currentUser->username) ?>
                        <?php if ($authManager->isImpersonating()): ?>
                            <small style="color: #f59e0b; font-weight:700;">(Simulando)</small>
                        <?php endif; ?>
                    </span>
                    <svg class="icon" style="width:10px; height:10px; margin-left:2px; opacity:0.6;"><use href="#icon-more-horizontal"></use></svg>
                </button>

                <!-- Menú Desplegable de Usuario y Cambio Rápido de Rol -->
                <div class="user-dropdown-menu" id="userDropdownMenu">
                    <div style="padding: 8px 12px; border-bottom: 1px solid var(--border-color); font-size: 11px;">
                        <div style="font-weight: 700; color: var(--text-primary);"><?= htmlspecialchars($currentUser->name ?: $currentUser->username) ?></div>
                        <div style="color: var(--text-muted); font-size: 10px;">Rol: <strong><?= strtoupper($currentUser->role) ?></strong> · <?= htmlspecialchars($currentUser->email ?: 'Local') ?></div>
                        <?php if ($currentUser->assignedEnv): ?>
                            <div style="color: #10b981; font-size: 10px; margin-top:2px;">Espejo: <?= htmlspecialchars($currentUser->assignedEnv) ?></div>
                        <?php endif; ?>
                    </div>

                    <?php if ($authManager->isImpersonating()): ?>
                        <a href="?action=stop_impersonating" class="user-dropdown-item" style="color: #f59e0b; font-weight:600;">
                            <svg class="icon"><use href="#icon-undo"></use></svg>
                            <span>Volver a Administrador</span>
                        </a>
                    <?php endif; ?>

                    <?php if ($currentUser->isAdmin() || $authManager->isImpersonating()): ?>
                        <div style="padding: 6px 12px 2px; font-size: 10px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">
                            Simular Vista de Desarrollador:
                        </div>
                        <?php foreach ($authManager->getUsers() as $uName => $uObj): ?>
                            <?php if ($uName !== $currentUser->username): ?>
                                <a href="?action=switch_user&user=<?= urlencode($uName) ?>" class="user-dropdown-item">
                                    <span class="user-dot" style="width:6px; height:6px; border-radius:50%; background: <?= $uObj->isAdmin() ? '#3b82f6' : '#10b981' ?>;"></span>
                                    <span><?= htmlspecialchars($uObj->name) ?></span>
                                    <small style="margin-left:auto; color:var(--text-muted); font-size:9px;"><?= $uObj->role ?></small>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <div style="height:1px; background:var(--border-color); margin: 4px 0;"></div>
                    <?php endif; ?>

                    <button type="button" class="user-dropdown-item" onclick="openProfileModal(); closeUserDropdown();">
                        <svg class="icon"><use href="#icon-user"></use></svg>
                        <span>Credenciales de Sesión</span>
                    </button>

                    <?php if ($authManager->isTeamMode()): ?>
                        <a href="?action=logout" class="user-dropdown-item text-danger">
                            <svg class="icon"><use href="#icon-log-out"></use></svg>
                            <span>Cerrar Sesión</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Acciones Secundarias (visibles en pantallas amplias, dentro de menú en medianas/pequeñas) -->
            <div class="topbar-actions-secondary">
                <button type="button" class="btn btn-icon" onclick="openHelpModal()" title="Manual de Uso Técnico">
                    <svg class="icon"><use href="#icon-help"></use></svg>
                </button>
                <button type="button" class="btn btn-action-text" onclick="openServerModal()" title="Configurar Servidores y Motores (Estilo HeidiSQL)">
                    <svg class="icon"><use href="#icon-database"></use></svg>
                    <span>Servidores</span>
                </button>
                <?php if (!empty($selectedEnv['is_my_mirror'])): ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('¿Deseas nivelar tu espejo aplicando las migraciones pendientes desde la BD Principal?');">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="sync_from_mirror">
                        <button type="submit" class="btn btn-action-text" style="border-color: #3b82f6; color: #3b82f6;" title="Nivelar mi contenedor/espejo desde la BD Principal">
                            <svg class="icon"><use href="#icon-refresh-cw"></use></svg>
                            <span>Nivelar Espejo</span>
                        </button>
                    </form>
                <?php endif; ?>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="backup">
                    <button type="submit" class="btn btn-action-text" title="Generar Snapshot manual">
                        <svg class="icon"><use href="#icon-save"></use></svg>
                        <span>Snapshot</span>
                    </button>
                </form>
                <button type="button" class="btn btn-action-text" onclick="openRollbackModal()" title="Revertir el último lote de migraciones aplicado" <?= empty($lastBatchMigrations) ? 'disabled' : '' ?>>
                    <svg class="icon"><use href="#icon-undo"></use></svg>
                    <span>Rollback</span>
                </button>
            </div>

            <!-- Botón de Modo Oscuro / Claro (siempre visible como icono) -->
            <button id="themeToggleBtn" class="btn btn-icon" title="Alternar Modo Oscuro / Claro">
                <svg id="iconMoon" class="icon"><use href="#icon-moon"></use></svg>
                <svg id="iconSun" class="icon" style="display:none;"><use href="#icon-sun"></use></svg>
            </button>

            <!-- Menú Desplegable 'Más Acciones' (visible en tablets, laptops compactas y móviles) -->
            <div class="topbar-overflow-container" id="topbarOverflowContainer">
                <button type="button" class="btn btn-icon overflow-trigger-btn" id="topbarOverflowBtn" onclick="toggleTopbarOverflow(event)" title="Más opciones">
                    <svg class="icon"><use href="#icon-more-horizontal"></use></svg>
                </button>
                <div class="topbar-overflow-menu" id="topbarOverflowMenu">
                    <button type="button" class="overflow-item" onclick="openServerModal(); closeTopbarOverflow();">
                        <svg class="icon"><use href="#icon-database"></use></svg>
                        <span>Servidores</span>
                    </button>
                    <form method="POST" style="margin: 0;">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="backup">
                        <button type="submit" class="overflow-item" onclick="closeTopbarOverflow();">
                            <svg class="icon"><use href="#icon-save"></use></svg>
                            <span>Generar Snapshot</span>
                        </button>
                    </form>
                    <button type="button" class="overflow-item" onclick="openRollbackModal(); closeTopbarOverflow();" <?= empty($lastBatchMigrations) ? 'disabled' : '' ?>>
                        <svg class="icon"><use href="#icon-undo"></use></svg>
                        <span>Rollback Migración</span>
                    </button>
                    <?php if (!empty($selectedEnv['is_my_mirror'])): ?>
                        <form method="POST" style="margin: 0;" onsubmit="return confirm('¿Deseas nivelar tu espejo aplicando las migraciones pendientes desde la BD Principal?');">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="sync_from_mirror">
                            <button type="submit" class="overflow-item" style="color: #3b82f6;" onclick="closeTopbarOverflow();">
                                <svg class="icon"><use href="#icon-refresh-cw"></use></svg>
                                <span>Nivelar Espejo</span>
                            </button>
                        </form>
                    <?php endif; ?>
                    <button type="button" class="overflow-item" onclick="openHelpModal(); closeTopbarOverflow();">
                        <svg class="icon"><use href="#icon-help"></use></svg>
                        <span>Ayuda Técnica</span>
                    </button>
                    <?php if ($authManager->isTeamMode()): ?>
                        <a href="?action=logout" class="overflow-item text-danger">
                            <svg class="icon"><use href="#icon-log-out"></use></svg>
                            <span>Cerrar Sesión</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($authManager->isTeamMode()): ?>
                <a href="?action=logout" class="btn btn-icon hide-on-compact" title="Cerrar Sesión (<?= htmlspecialchars($currentUser->username) ?>)">
                    <svg class="icon"><use href="#icon-log-out"></use></svg>
                </a>
            <?php endif; ?>

            <!-- Botón Principal de Sincronización (siempre visible y destacado) -->
            <button type="button" class="btn btn-primary topbar-sync-btn" onclick="openSyncModal()" <?= (empty($pendingList) || !empty($divergentList)) ? 'disabled' : '' ?>>
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
                            <?php if ($summary && $summary['status'] === 'SINCRONIZADO'): ?>
                                <span class="status-badge badge-sync" id="badge-<?= htmlspecialchars($envKey) ?>" style="font-size: 10px; font-weight: 600;">AL DÍA</span>
                            <?php elseif ($summary && $summary['status'] === 'ATRASADO'): ?>
                                <span class="status-badge badge-warn" id="badge-<?= htmlspecialchars($envKey) ?>" style="font-size: 10px; font-weight: 600;">-<?= $summary['pending'] ?></span>
                            <?php else: ?>
                                <span class="status-badge badge-muted" id="badge-<?= htmlspecialchars($envKey) ?>" style="font-size: 10px; font-weight: 500;">
                                    <?= $isActive ? 'ACTIVO' : '' ?>
                                </span>
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

        <!-- 2. Canvas Central: Dot Grid & Draggable Flow Cards / Vista Tabla Lineal -->
        <main class="canvas-area" id="mainWorkspaceArea">
            
            <!-- Watermark Desktop Background (Low opacity centered + slight blur) -->
            <img src="/assets/logo/imagotipo-modo-claro-oscuro.webp" alt="SYNCDERO Watermark" class="canvas-watermark logo-light">
            <img src="/assets/logo/imagotipo-modo-oscuro-claro.webp" alt="SYNCDERO Watermark" class="canvas-watermark logo-dark" style="display: none;">

            <!-- A. VISTA 0: Matriz General de Espejos (Solo disponible si hay múltiples entornos) -->
            <?php if ($hasMultipleEnvs && $mirrorsOverview): ?>
                <div class="mirrors-matrix-view" id="mirrorsOverviewView" style="display: none;">
                    <div class="matrix-header">
                        <div class="matrix-title-row">
                            <div>
                                <h2 style="font-size: 16px; font-weight: 700; letter-spacing: -0.2px; display: flex; align-items: center; gap: 8px;">
                                    <svg class="icon" style="color: var(--brand-blue);"><use href="#icon-grid"></use></svg>
                                    <span>Matriz General de Bases de Datos y Espejos</span>
                                </h2>
                                <p style="font-size: 12px; color: var(--text-muted); margin-top: 3px;">
                                    Ecosistema: <strong><?= count($mirrorsOverview['environments']) ?> entornos</strong> · 
                                    Migraciones en disco: <strong><?= $mirrorsOverview['total_disk_migrations'] ?> scripts</strong>
                                    <?php if ($referenceEnvKey): ?>
                                        · BD Principal de Referencia: <strong style="color: var(--brand-blue);"><?= htmlspecialchars($referenceEnvKey) ?> (<?= $mirrorsOverview['reference_applied'] ?> migraciones)</strong>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div style="display: flex; gap: 8px; align-items: center;">
                                <a href="?env=<?= urlencode($selectedEnvKey) ?>" class="btn btn-sm" title="Recargar estado actual">
                                    <svg class="icon"><use href="#icon-refresh-cw"></use></svg>
                                    <span>Actualizar</span>
                                </a>
                            </div>
                        </div>

                        <!-- Tarjetas de métricas superiores (Bento Grid) -->
                        <div class="matrix-kpi-grid">
                            <div class="matrix-kpi-card" title="Entorno de referencia: <?= htmlspecialchars($referenceEnvKey ?: 'db_main') ?>">
                                <span class="matrix-kpi-label">BD Principal (Referencia)</span>
                                <div class="matrix-kpi-value" style="color: var(--brand-blue);">
                                    <?= htmlspecialchars($environments[$referenceEnvKey]['label'] ?? ($referenceEnvKey ?: 'db_main')) ?>
                                </div>
                                <span class="matrix-kpi-sub"><?= $mirrorsOverview['reference_applied'] ?> migraciones ejecutadas</span>
                            </div>
                            <div class="matrix-kpi-card">
                                <span class="matrix-kpi-label">Total en Disco</span>
                                <div class="matrix-kpi-value">
                                    <?= $mirrorsOverview['total_disk_migrations'] ?>
                                </div>
                                <span class="matrix-kpi-sub">Scripts versionados en /migrations</span>
                            </div>
                            <?php 
                                $syncedCount = 0;
                                $behindCount = 0;
                                foreach ($mirrorsOverview['environments'] as $mEnv) {
                                    if ($mEnv['status'] === 'SINCRONIZADO') $syncedCount++;
                                    if ($mEnv['status'] === 'ATRASADO') $behindCount++;
                                }
                            ?>
                            <div class="matrix-kpi-card">
                                <span class="matrix-kpi-label">Espejos al Día</span>
                                <div class="matrix-kpi-value" style="color: #10b981;">
                                    <?= $syncedCount ?> / <?= count($mirrorsOverview['environments']) ?>
                                </div>
                                <span class="matrix-kpi-sub">En sincronía total</span>
                            </div>
                            <div class="matrix-kpi-card">
                                <span class="matrix-kpi-label">Requieren Sincronización</span>
                                <div class="matrix-kpi-value" style="color: <?= $behindCount > 0 ? '#f59e0b' : 'var(--text-muted)' ?>;">
                                    <?= $behindCount ?>
                                </div>
                                <span class="matrix-kpi-sub"><?= $behindCount > 0 ? 'Con cambios pendientes' : 'Ningún atraso detectado' ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Tabla Comparativa de Entornos -->
                    <div class="table-card" style="margin-top: 14px;">
                        <table class="data-table matrix-table">
                            <thead>
                                <tr>
                                    <th>Entorno / Base de Datos</th>
                                    <th>Rol</th>
                                    <th>Desarrollador / Propietario</th>
                                    <th style="text-align: center;">Progreso</th>
                                    <th>Estado de Sincronía</th>
                                    <th style="text-align: right;">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($mirrorsOverview['environments'] as $envKey => $mEnv): ?>
                                    <?php 
                                        $isSelected = ($envKey === $selectedEnvKey);
                                        $isRef = $mEnv['is_reference'];
                                        $pct = $mEnv['total'] > 0 ? round(($mEnv['applied'] / $mEnv['total']) * 100) : 0;
                                    ?>
                                    <tr class="<?= $isSelected ? 'matrix-row-selected' : '' ?>">
                                        <td>
                                            <div style="display: flex; align-items: flex-start; gap: 8px;">
                                                <svg class="icon" style="color: <?= $isRef ? 'var(--brand-blue)' : 'var(--text-muted)' ?>; margin-top: 2px;"><use href="#icon-database"></use></svg>
                                                <div>
                                                    <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                                        <a href="?env=<?= urlencode($envKey) ?>" style="font-weight: 600; color: var(--text-primary); text-decoration: none;" title="Cambiar a este entorno">
                                                            <?= htmlspecialchars($mEnv['label']) ?>
                                                        </a>
                                                        <?php if ($isRef): ?>
                                                            <span class="badge-role-pill ref">REFERENCIA</span>
                                                        <?php endif; ?>
                                                        <?php if ($isSelected): ?>
                                                            <span class="badge-role-pill active">ACTIVO</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div style="font-size: 11px; color: var(--text-muted); margin-top: 1px;">
                                                        <code><?= htmlspecialchars($envKey) ?></code> · <?= strtoupper($mEnv['driver']) ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge-role-pill <?= strtolower($mEnv['role']) ?>">
                                                <?= strtoupper($mEnv['role']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($mEnv['owner_label'])): ?>
                                                <div style="display: flex; align-items: center; gap: 6px;">
                                                    <div class="user-avatar-tiny"><?= strtoupper(substr($mEnv['owner_label'], 0, 1)) ?></div>
                                                    <span style="font-size: 12px; font-weight: 500;"><?= htmlspecialchars($mEnv['owner_label']) ?></span>
                                                </div>
                                            <?php else: ?>
                                                <span style="font-size: 11px; color: var(--text-muted);">Compartido / General</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center; min-width: 120px;">
                                            <div style="display: flex; align-items: center; gap: 8px; justify-content: center;">
                                                <div class="matrix-progress-bar">
                                                    <?php 
                                                        $fillClass = 'sync';
                                                        if ($mEnv['status'] === 'ATRASADO') $fillClass = 'warn';
                                                        elseif ($mEnv['status'] === 'ADELANTADO') $fillClass = 'info';
                                                        elseif ($mEnv['status'] === 'ERROR') $fillClass = 'danger';
                                                        $barPct = min(100, $pct);
                                                    ?>
                                                    <div class="matrix-progress-fill <?= $fillClass ?>" style="width: <?= $barPct ?>%;"></div>
                                                </div>
                                                <span style="font-size: 11px; font-weight: 500; color: var(--text-secondary);">
                                                    <?= $mEnv['applied'] ?>/<?= $mEnv['total'] ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($mEnv['status'] === 'SINCRONIZADO'): ?>
                                                <span class="status-badge badge-sync">
                                                    <span class="status-dot dot-sync"></span>
                                                    <span>Al día</span>
                                                </span>
                                            <?php elseif ($mEnv['status'] === 'ATRASADO'): ?>
                                                <span class="status-badge badge-warn" title="Faltan <?= $mEnv['diff_count'] ?> migraciones para alcanzar a la BD Principal">
                                                    <span class="status-dot dot-warn"></span>
                                                    <span>Atrasado (-<?= $mEnv['diff_count'] ?>)</span>
                                                </span>
                                            <?php elseif ($mEnv['status'] === 'ADELANTADO'): ?>
                                                <span class="status-badge badge-role-pill dev">
                                                    <span class="status-dot dot-info"></span>
                                                    <span>Adelantado (+<?= $mEnv['diff_count'] ?>)</span>
                                                </span>
                                            <?php else: ?>
                                                <span class="status-badge badge-danger">
                                                    <span class="status-dot dot-danger"></span>
                                                    <span>Sin conexión</span>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right; white-space: nowrap;">
                                            <a href="?env=<?= urlencode($envKey) ?>" class="btn btn-sm" title="Inspeccionar historial detallado de <?= htmlspecialchars($envKey) ?>">
                                                <span>Historial</span>
                                            </a>
                                            <?php if ($mEnv['status'] === 'ATRASADO'): ?>
                                                <a href="?env=<?= urlencode($envKey) ?>&auto_sync=1" class="btn btn-sm btn-primary" title="Abrir y nivelar este espejo">
                                                    <svg class="icon" style="stroke: #ffffff; width:10px; height:10px;"><use href="#icon-refresh-cw"></use></svg>
                                                    <span>Nivelar</span>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- B. VISTA 1: Tabla / Línea de Tiempo Estructurada -->
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
                        <div class="timeline-title-actions" style="display: flex; gap: 8px; align-items: center;">
                            <!-- Selector de Vista Visible en Móvil cuando la barra superior lo oculta -->
                            <div class="view-mode-toggle mobile-view-toggle mobile-only">
                                <?php if ($hasMultipleEnvs): ?>
                                    <button type="button" class="view-btn" id="mobileViewBtnOverview" onclick="switchViewMode('overview')" title="General">
                                        <svg class="icon" style="width:13px; height:13px;"><use href="#icon-grid"></use></svg>
                                        <span>General</span>
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="view-btn active" id="mobileViewBtnTable" onclick="switchViewMode('table')" title="Historial">
                                    <svg class="icon" style="width:13px; height:13px;"><use href="#icon-table"></use></svg>
                                    <span>Historial</span>
                                </button>
                                <button type="button" class="view-btn" id="mobileViewBtnCanvas" onclick="switchViewMode('canvas')" title="Lienzo">
                                    <svg class="icon" style="width:13px; height:13px;"><use href="#icon-layout"></use></svg>
                                    <span>Lienzo</span>
                                </button>
                            </div>

                            <?php if (!empty($pendingList)): ?>
                                <button type="button" class="btn btn-primary" onclick="openSyncModal()">
                                    <svg class="icon" style="stroke: #ffffff;"><use href="#icon-play"></use></svg>
                                    <span>Sincronizar (<?= count($pendingList) ?>)</span>
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
                                    <td>
                                        <span class="status-badge badge-danger">
                                            <span class="status-dot dot-danger"></span>
                                            <span>Divergente</span>
                                        </span>
                                    </td>
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
                                    <td>
                                        <span class="status-badge badge-warn">
                                            <span class="status-dot dot-warn"></span>
                                            <span>Pendiente</span>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600;"><?= htmlspecialchars($item['file']->basename) ?></div>
                                        <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($item['file']->description) ?></div>
                                    </td>
                                    <td><span style="color: var(--text-muted); font-size: 11px;">—</span></td>
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
                                        <td>
                                            <span class="status-badge badge-sync">
                                                <span class="status-dot dot-sync"></span>
                                                <span>Aplicada</span>
                                            </span>
                                        </td>
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

            <!-- B. VISTA 2: Lienzo Topológico de Arquitectura & Nodos de Flujo -->
            <div class="flow-viewport" id="flowViewport" style="display: none;">
                <!-- Barra de control del Lienzo -->
                <div class="canvas-floating-toolbar" style="margin-bottom: 24px; position: sticky; top: 12px; z-index: 50;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px;">Vista del Lienzo:</span>
                        <div class="view-mode-toggle" style="padding: 2px;">
                            <button type="button" class="view-btn active" id="canvasSubViewTopologyBtn" onclick="switchCanvasSubView('topology')">
                                <svg class="icon" style="width:12px; height:12px;"><use href="#icon-grid"></use></svg>
                                <span>Topología del Proyecto</span>
                            </button>
                            <button type="button" class="view-btn" id="canvasSubViewBatchesBtn" onclick="switchCanvasSubView('batches')">
                                <svg class="icon" style="width:12px; height:12px;"><use href="#icon-layers"></use></svg>
                                <span>Lotes Ejecutados (<?= count($batches) ?>)</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- SVG Wire Connections -->
                <svg id="flowSvgWires" class="flow-connections-svg"></svg>

                <?php if ($connectionError): ?>
                    <div style="position: absolute; top: 70px; left: 40px; max-width: 420px; background: var(--state-danger-bg); color: var(--state-danger-text); border: 1px solid var(--state-danger-border); border-radius: 4px; padding: 14px 16px; font-size: 12px; display: flex; gap: 10px; align-items: flex-start; z-index: 60;">
                        <svg class="icon"><use href="#icon-alert"></use></svg>
                        <div>
                            <strong>No se puede inspeccionar el entorno</strong><br>
                            <?= htmlspecialchars($connectionError) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- SUB-VISTA 1: Topología de Arquitectura (Git -> Prod -> Staging -> Espejos) -->
                <div id="canvasTopologySubView" style="position: relative; min-height: 700px; width: 100%;">
                    <?php 
                        // Identificar entornos por roles
                        $prodEnvKey = null;
                        $stagingEnvKey = null;
                        $devEnvs = [];

                        foreach ($environments as $eKey => $eConfig) {
                            $r = $eConfig['role'] ?? 'dev';
                            if ($r === 'prod' || $eKey === $referenceEnvKey) {
                                if (!$prodEnvKey) $prodEnvKey = $eKey;
                            } elseif ($r === 'staging') {
                                if (!$stagingEnvKey) $stagingEnvKey = $eKey;
                            } else {
                                $devEnvs[$eKey] = $eConfig;
                            }
                        }
                        if (!$prodEnvKey && !empty($environments)) {
                            $prodEnvKey = array_key_first($environments);
                        }
                    ?>

                    <!-- NODO 1: Origen de Código / Repositorio Git -->
                    <div class="flow-node topology-node" id="nodeGitSource" data-node-type="git" style="left: 40px; top: 60px;">
                        <span class="node-connector-dot right"></span>
                        <div class="node-header header-blue">
                            <div class="node-header-title">
                                <svg class="icon"><use href="#icon-git-branch"></use></svg>
                                <span>Código / Repositorio Git</span>
                            </div>
                            <span class="badge-role-pill dev">ORIGEN</span>
                        </div>
                        <div class="node-body">
                            <div class="node-prop-row">
                                <span class="node-prop-label">Proyecto</span>
                                <div class="node-prop-value" style="font-weight: 700; color: var(--brand-blue);">
                                    <?= htmlspecialchars($activeProject['name'] ?? $selectedProjectKey) ?>
                                </div>
                            </div>
                            <div class="node-prop-row">
                                <span class="node-prop-label">Rama Activa / Commit</span>
                                <div class="node-prop-value" style="font-family: var(--font-mono); font-size: 11px;">
                                    <?= htmlspecialchars($gitInfo['branch']) ?> (<?= htmlspecialchars($gitInfo['commit']) ?>)
                                </div>
                            </div>
                            <div class="node-prop-row">
                                <span class="node-prop-label">Scripts en Disco</span>
                                <div class="node-prop-value">
                                    <strong><?= count($stateEngine->getMigrationFiles()) ?></strong> migraciones versionadas
                                </div>
                            </div>
                            <div style="font-size: 10px; color: var(--text-muted); font-family: var(--font-mono); word-break: break-all; margin-top: 4px;">
                                <?= htmlspecialchars($config->getMigrationsDir()) ?>
                            </div>
                        </div>
                    </div>

                    <!-- NODO 2: Base de Datos Principal (Producción / Reference) -->
                    <?php if ($prodEnvKey && isset($environments[$prodEnvKey])): 
                        $prodEnv = $environments[$prodEnvKey];
                        $prodMeta = $mirrorsOverview['environments'][$prodEnvKey] ?? null;
                    ?>
                        <div class="flow-node topology-node" id="nodeProdDb" data-node-type="prod" style="left: 420px; top: 60px;">
                            <span class="node-connector-dot left"></span>
                            <span class="node-connector-dot right"></span>
                            <div class="node-header header-green">
                                <div class="node-header-title">
                                    <svg class="icon"><use href="#icon-database"></use></svg>
                                    <span><?= htmlspecialchars($prodEnv['label'] ?? $prodEnvKey) ?></span>
                                </div>
                                <span class="badge-role-pill prod">PROD / REF</span>
                            </div>
                            <div class="node-body">
                                <div class="node-prop-row">
                                    <span class="node-prop-label">Motor & BD</span>
                                    <div class="node-prop-value" style="font-family: var(--font-mono);">
                                        <?= strtoupper($prodEnv['driver'] ?? 'pgsql') ?> · <?= htmlspecialchars(basename($prodEnv['database'] ?? 'db')) ?>
                                    </div>
                                </div>
                                <div class="node-prop-row">
                                    <span class="node-prop-label">Migraciones Aplicadas</span>
                                    <div class="node-prop-value" style="color: #10b981; font-weight: 700;">
                                        <?= $prodMeta ? $prodMeta['applied'] : ($activeInspection['total_applied'] ?? 0) ?> / <?= count($stateEngine->getMigrationFiles()) ?>
                                    </div>
                                </div>
                                <div style="display: flex; gap: 6px; margin-top: 8px;">
                                    <a href="?env=<?= urlencode($prodEnvKey) ?>" class="btn btn-sm" style="flex: 1; text-align: center; justify-content: center;">
                                        <span>Seleccionar</span>
                                    </a>
                                    <form method="POST" style="margin: 0;">
                                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="action" value="backup">
                                        <button type="submit" class="btn btn-sm" title="Generar Snapshot">
                                            <svg class="icon"><use href="#icon-save"></use></svg>
                                            <span>Snapshot</span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- NODO 3: Base de Datos Staging (Pre-producción si existe) -->
                    <?php if ($stagingEnvKey && isset($environments[$stagingEnvKey])): 
                        $stagEnv = $environments[$stagingEnvKey];
                        $stagMeta = $mirrorsOverview['environments'][$stagingEnvKey] ?? null;
                    ?>
                        <div class="flow-node topology-node" id="nodeStagingDb" data-node-type="staging" style="left: 420px; top: 320px;">
                            <span class="node-connector-dot left"></span>
                            <div class="node-header header-purple">
                                <div class="node-header-title">
                                    <svg class="icon"><use href="#icon-database"></use></svg>
                                    <span><?= htmlspecialchars($stagEnv['label'] ?? $stagingEnvKey) ?></span>
                                </div>
                                <span class="badge-role-pill staging">STAGING</span>
                            </div>
                            <div class="node-body">
                                <div class="node-prop-row">
                                    <span class="node-prop-label">Estado frente a Prod</span>
                                    <div class="node-prop-value">
                                        <?php if ($stagMeta && $stagMeta['status'] === 'SINCRONIZADO'): ?>
                                            <span class="status-badge badge-sync">AL DÍA</span>
                                        <?php elseif ($stagMeta && $stagMeta['status'] === 'ATRASADO'): ?>
                                            <span class="status-badge badge-warn">-<?= $stagMeta['diff_count'] ?> pendientes</span>
                                        <?php else: ?>
                                            <span class="status-badge badge-muted">Activo</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div style="display: flex; gap: 6px; margin-top: 8px;">
                                    <a href="?env=<?= urlencode($stagingEnvKey) ?>" class="btn btn-sm" style="flex: 1; text-align: center; justify-content: center;">
                                        <span>Seleccionar</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- NODOS 4+: Espejos de Desarrolladores (Docker / Local) -->
                    <?php 
                        $mirrorPosY = 60;
                        $mirrorIndex = 0;
                        foreach ($devEnvs as $dKey => $dConfig): 
                            if ($mirrorIndex >= 4) break; // Mostrar hasta 4 espejos principales en el lienzo inicial
                            $dMeta = $mirrorsOverview['environments'][$dKey] ?? null;
                            $nodeId = 'nodeDevMirror_' . $mirrorIndex;
                    ?>
                        <div class="flow-node topology-node" id="<?= $nodeId ?>" data-node-type="mirror" style="left: 800px; top: <?= $mirrorPosY ?>px;">
                            <span class="node-connector-dot left"></span>
                            <div class="node-header header-gray">
                                <div class="node-header-title">
                                    <svg class="icon"><use href="#icon-database"></use></svg>
                                    <span><?= htmlspecialchars($dConfig['label'] ?? $dKey) ?></span>
                                </div>
                                <span class="badge-role-pill dev"><?= strtoupper($dConfig['role'] ?? 'DEV') ?></span>
                            </div>
                            <div class="node-body">
                                <div class="node-prop-row">
                                    <span class="node-prop-label">Propietario / Rol</span>
                                    <div class="node-prop-value" style="font-size: 11px;">
                                        <?= htmlspecialchars($dMeta['owner_label'] ?? 'Compartido') ?>
                                    </div>
                                </div>
                                <div class="node-prop-row">
                                    <span class="node-prop-label">Sincronía vs BD Principal</span>
                                    <div class="node-prop-value">
                                        <?php if ($dMeta && $dMeta['status'] === 'SINCRONIZADO'): ?>
                                            <span class="status-badge badge-sync">100% AL DÍA</span>
                                        <?php elseif ($dMeta && $dMeta['status'] === 'ATRASADO'): ?>
                                            <span class="status-badge badge-warn">-<?= $dMeta['diff_count'] ?> atraso</span>
                                        <?php else: ?>
                                            <span class="status-badge badge-muted">Conectado</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div style="display: flex; gap: 6px; margin-top: 8px;">
                                    <a href="?env=<?= urlencode($dKey) ?>" class="btn btn-sm" style="flex: 1; text-align: center; justify-content: center;">
                                        <span>Historial</span>
                                    </a>
                                    <?php if ($dMeta && $dMeta['status'] === 'ATRASADO'): ?>
                                        <a href="?env=<?= urlencode($dKey) ?>&auto_sync=1" class="btn btn-sm btn-primary" title="Nivelar espejo con BD Principal">
                                            <svg class="icon" style="stroke:#ffffff; width:10px; height:10px;"><use href="#icon-refresh-cw"></use></svg>
                                            <span>Nivelar</span>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php 
                            $mirrorPosY += 220;
                            $mirrorIndex++;
                        endforeach; 
                    ?>
                </div>

                <!-- SUB-VISTA 2: Lotes de Migraciones (Batches Ejecutados) -->
                <div id="canvasBatchesSubView" style="display: none; position: relative; min-height: 700px; width: 100%;">
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
                    <div class="flow-node batch-flow-node" data-status="applied" style="left: <?= $posX ?>px; top: <?= $posY ?>px;">
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
                    <div class="flow-node batch-flow-node" data-status="pending" style="left: <?= $posX ?>px; top: <?= $posY ?>px;">
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
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="restore">
                            <input type="hidden" name="backup_file" value="<?= htmlspecialchars($bk['name']) ?>">
                            <div style="font-family: var(--font-mono); font-size:10px; color: var(--text-primary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= htmlspecialchars($bk['name']) ?>">
                                <?= htmlspecialchars($bk['name']) ?>
                            </div>
                            <div style="display:flex; justify-content:space-between; align-items:center; gap:6px;">
                                <span style="font-size:10px; color:var(--text-muted);"><?= date('Y-m-d H:i', $bk['modified']) ?> · <?= number_format($bk['size'] / 1024, 1) ?> KB</span>
                                <div style="display:flex; gap:4px; align-items:center;">
                                    <button type="submit" class="btn" style="padding:2px 6px; font-size:10px;" title="Restaurar este snapshot en el entorno activo">Restaurar</button>
                                </div>
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
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
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
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
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
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
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
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
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

    <script src="/assets/app.js?v=<?= @filemtime(__DIR__ . '/assets/app.js') ?: time() ?>"></script>
    <?php if (!empty($autoOpenSyncModal)): ?>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                switchViewMode('table');
                setTimeout(() => openSyncModal(), 120);
            });
        </script>
    <?php endif; ?>
</body>
</html>

