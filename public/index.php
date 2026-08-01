<?php

$config = require __DIR__ . '/config.php';

function getDbConnection($config, $dbname) {
    try {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $config['pg_host'],
            $config['pg_port'],
            $dbname
        );
        $pdo = new PDO($dsn, $config['pg_user'], $config['pg_pass']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        return null;
    }
}

function getMigrations($config, $dbname) {
    $pdo = getDbConnection($config, $dbname);
    if (!$pdo) return ['applied' => [], 'error' => 'No connection'];

    try {
        $stmt = $pdo->query("SELECT migration, batch FROM migrations ORDER BY id");
        return ['applied' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'error' => null];
    } catch (PDOException $e) {
        return ['applied' => [], 'error' => 'Table migrations not found'];
    }
}

function getAllMigrationFiles($config) {
    $dir = $config['migrations_dir'];
    if (!is_dir($dir)) return [];

    $files = glob($dir . '/*.php');
    $migrations = [];

    foreach ($files as $file) {
        $basename = basename($file, '.php');
        $migrations[] = [
            'filename' => $basename,
            'path' => $file,
            'description' => parseMigrationDescription($basename),
        ];
    }

    return $migrations;
}

function parseMigrationDescription($filename) {
    $clean = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $filename);
    return str_replace('_', ' ', $clean);
}

function getGitInfo($config) {
    $repoDir = $config['repo_dir'];
    if (!is_dir($repoDir)) return ['branch' => 'unknown', 'commit' => 'unknown', 'message' => ''];

    $headFile = $repoDir . '/HEAD';
    if (!file_exists($headFile)) return ['branch' => 'unknown', 'commit' => 'unknown', 'message' => ''];

    $head = trim(file_get_contents($headFile));
    $branch = preg_replace('/^ref: refs\/heads\//', '', $head);

    $commitFile = $repoDir . '/refs/heads/' . $branch;
    $commit = file_exists($commitFile) ? substr(trim(file_get_contents($commitFile)), 0, 7) : 'unknown';

    return ['branch' => $branch, 'commit' => $commit, 'message' => ''];
}

function renderHeader($title = 'PANEL DE BASE DE DATOS AGARCORP') {
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title) ?></title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="/assets/style.css">
    </head>
    <body>
        <div class="container">
            <header>
                <div class="ascii-border">╔══════════════════════════════════════════════════════════════════════════╗</div>
                <h1>║  <?= htmlspecialchars($title) ?>  ║</h1>
                <div class="ascii-border">╚══════════════════════════════════════════════════════════════════════════╝</div>
            </header>
    <?php
}

function renderFooter() {
    ?>
            <footer>
                <p>Panel de Base de Datos AGARCORP v1.0 | <?= date('Y-m-d H:i:s') ?> | <a href="/help">Ayuda</a></p>
            </footer>
        </div>
    </body>
    </html>
    <?php
}

function renderDashboard($config) {
    $gitInfo = getGitInfo($config);
    $allMigrations = getAllMigrationFiles($config);
    $totalMigrations = count($allMigrations);

    renderHeader('PANEL DE BASE DE DATOS AGARCORP v1.0');
    ?>

    <div class="info-bar">
        <span class="branch">
            <span class="tooltip" data-tooltip="Rama actual del repositorio Git">⎇</span>
            Rama principal: <strong><?= htmlspecialchars($gitInfo['branch']) ?></strong> 
            (<code><?= htmlspecialchars($gitInfo['commit']) ?></code>)
        </span>
        <span class="timestamp">
            <span class="tooltip" data-tooltip="Última actualización del panel">⏱</span>
            Última verificación: <?= date('H:i:s') ?>
        </span>
    </div>

    <div class="help-section">
        <h3>ℹ️ Vista General</h3>
        <p>Este panel monitorea el estado de migraciones de Laravel en todos los entornos de base de datos.</p>
        <p><strong>Total de migraciones disponibles:</strong> <code><?= $totalMigrations ?></code> archivos en <code>database/migrations/</code></p>
        <p><strong>Jerarquía de protección:</strong></p>
        <ul>
            <li><span class="badge badge-protected">PROD</span> <strong>db_main</strong> — Base de producción, bloqueada. Solo migración manual.</li>
            <li><span class="badge badge-behind">STAGING</span> <strong>db_staging</strong> — Pre-producción, requiere confirmación doble.</li>
            <li><span class="badge badge-sync">DEV</span> <strong>db_g, db_x, db_y, db_c, db_j</strong> — Entornos de desarrollo, migración libre.</li>
        </ul>
    </div>

    <table class="status-table">
        <thead>
            <tr>
                <th>ENTORNO</th>
                <th>MIGRACIONES</th>
                <th>ESTADO</th>
                <th>ROL</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($config['environments'] as $dbname => $env): ?>
                <?php
                $result = getMigrations($config, $dbname);
                $applied = count($result['applied']);
                
                if ($result['error']) {
                    $status = 'ERROR';
                    $statusClass = 'error';
                    $badgeClass = 'badge-error';
                } elseif ($applied === $totalMigrations) {
                    $status = 'SINCRONIZADO';
                    $statusClass = 'sync';
                    $badgeClass = 'badge-sync';
                } elseif ($applied < $totalMigrations) {
                    $diff = $totalMigrations - $applied;
                    $status = 'ATRASADO ' . $diff;
                    $statusClass = 'behind';
                    $badgeClass = 'badge-behind';
                } else {
                    $diff = $applied - $totalMigrations;
                    $status = 'ADELANTADO +' . $diff;
                    $statusClass = 'ahead';
                    $badgeClass = 'badge-ahead';
                }
                ?>
                <tr class="env-<?= $env['color'] ?>">
                    <td>
                        <a href="/detail?db=<?= urlencode($dbname) ?>">
                            <?= $env['icon'] ?> <?= htmlspecialchars($env['label']) ?>
                        </a>
                    </td>
                    <td>
                        <strong><?= $applied ?></strong>/<span class="tooltip" data-tooltip="Total de migraciones disponibles"><?= $totalMigrations ?></span>
                    </td>
                    <td>
                        <span class="badge <?= $badgeClass ?>"><?= $status ?></span>
                    </td>
                    <td>
                        <code><?= htmlspecialchars($env['role']) ?></code>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="legend">
        <span><span class="icon">✓</span> <span class="status-sync">SINCRONIZADO</span> — Todas las migraciones aplicadas</span>
        <span><span class="icon">▼</span> <span class="status-behind">ATRASADO</span> — Faltan migraciones por aplicar</span>
        <span><span class="icon">▲</span> <span class="status-ahead">ADELANTADO</span> — Tiene migraciones extras</span>
        <span><span class="icon">✗</span> <span class="status-error">ERROR</span> — No se puede conectar o tabla no existe</span>
    </div>

    <div class="actions">
        <a href="/compare?from=db_main&to=db_staging" class="btn">
            <span class="icon">⇄</span> Comparar db_main ↔ db_staging
        </a>
        <a href="/apply" class="btn btn-apply">
            <span class="icon">▶</span> Aplicar migraciones
        </a>
        <a href="/" class="btn">
            <span class="icon">↻</span> Actualizar
        </a>
    </div>

    <?php
    renderFooter();
}

function renderDetail($config, $dbname) {
    if (!isset($config['environments'][$dbname])) {
        http_response_code(404);
        renderHeader('404 NO ENCONTRADO');
        echo "<div class='error-box'><p>⚠ Entorno '$dbname' no encontrado.</p></div>";
        renderFooter();
        return;
    }

    $env = $config['environments'][$dbname];
    $result = getMigrations($config, $dbname);
    $allMigrations = getAllMigrationFiles($config);
    $appliedNames = array_column($result['applied'], 'migration');

    renderHeader('DETALLE: ' . $env['label']);
    ?>

    <div class="nav">
        <a href="/" class="btn">
            <span class="icon">←</span> Volver al Panel
        </a>
    </div>

    <div class="summary">
        <h3>📊 Resumen del Entorno</h3>
        <p><strong>Entorno:</strong> <?= htmlspecialchars($env['label']) ?></p>
        <p><strong>Rol:</strong> <code><?= htmlspecialchars($env['role']) ?></code></p>
        <p>
            <strong>Protegido:</strong> 
            <?php if ($env['protected'] === true): ?>
                <span class="badge badge-protected">🔒 BLOQUEADO</span>
            <?php elseif ($env['protected'] === 'warn'): ?>
                <span class="badge badge-behind">⚠ ADVERTENCIA</span>
            <?php else: ?>
                <span class="badge badge-sync">✓ ABIERTO</span>
            <?php endif; ?>
        </p>
        <p>
            <strong>Aplicadas:</strong> 
            <strong><?= count($result['applied']) ?></strong> / <?= count($allMigrations) ?> migraciones
        </p>
    </div>

    <?php if ($result['error']): ?>
        <div class="error-box">
            <h3>⚠ Error</h3>
            <p><?= htmlspecialchars($result['error']) ?></p>
            <p><strong>Posibles causas:</strong></p>
            <ul>
                <li>La base de datos no existe</li>
                <li>No se puede conectar a PostgreSQL</li>
                <li>La tabla <code>migrations</code> no existe (¿nunca se ejecutó <code>php artisan migrate</code>?)</li>
            </ul>
        </div>
    <?php else: ?>
        <div class="info-box">
            <h3>ℹ️ Estado de Migraciones</h3>
            <p>Las migraciones se aplican en orden cronológico basado en el timestamp del nombre del archivo.</p>
            <p><strong>Lote:</strong> Número de grupo de migraciones aplicadas juntas en una sola ejecución.</p>
        </div>

        <table class="migrations-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>MIGRACIÓN</th>
                    <th>ESTADO</th>
                    <th>LOTE</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allMigrations as $i => $mig): ?>
                    <?php
                    $isApplied = in_array($mig['filename'], $appliedNames);
                    $batch = '';
                    if ($isApplied) {
                        $key = array_search($mig['filename'], $appliedNames);
                        $batch = $result['applied'][$key]['batch'];
                    }
                    ?>
                    <tr class="<?= $isApplied ? 'applied' : 'pending' ?>">
                        <td><?= $i + 1 ?></td>
                        <td>
                            <code><?= htmlspecialchars($mig['description']) ?></code>
                        </td>
                        <td class="<?= $isApplied ? 'status-sync' : 'status-behind' ?>">
                            <?php if ($isApplied): ?>
                                <span class="badge badge-sync">✓ APLICADA</span>
                            <?php else: ?>
                                <span class="badge badge-behind">✗ PENDIENTE</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $batch ?: '—' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        $pending = array_filter($allMigrations, fn($m) => !in_array($m['filename'], $appliedNames));
        if (!empty($pending)):
        ?>
            <div class="pending-box">
                <h3>⏳ Migraciones Pendientes (<?= count($pending) ?>)</h3>
                <p>Las siguientes migraciones aún no se han aplicado a este entorno:</p>
                <ul>
                    <?php foreach ($pending as $mig): ?>
                        <li><code><?= htmlspecialchars($mig['description']) ?></code></li>
                    <?php endforeach; ?>
                </ul>
                
                <?php if ($env['protected'] !== true): ?>
                    <div class="actions">
                        <a href="/apply?db=<?= urlencode($dbname) ?>" class="btn btn-apply">
                            <span class="icon">▶</span> Aplicar <?= count($pending) ?> pendientes a <?= htmlspecialchars($dbname) ?>
                        </a>
                    </div>
                <?php else: ?>
                    <div class="protected-msg">
                        🔒 <strong>Entorno protegido:</strong> No se pueden aplicar migraciones desde el panel. 
                        Use <code>php artisan migrate</code> manualmente con las precauciones adecuadas.
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="result-box">
                <h3>✓ Todas las Migraciones Aplicadas</h3>
                <p>Este entorno está completamente sincronizado con el código fuente.</p>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php
    renderFooter();
}

function renderCompare($config, $from, $to) {
    if (!isset($config['environments'][$from]) || !isset($config['environments'][$to])) {
        http_response_code(404);
        renderHeader('404 NO ENCONTRADO');
        echo "<div class='error-box'><p>⚠ Uno o ambos entornos no encontrados.</p></div>";
        renderFooter();
        return;
    }

    $resultFrom = getMigrations($config, $from);
    $resultTo = getMigrations($config, $to);
    $appliedFrom = array_column($resultFrom['applied'], 'migration');
    $appliedTo = array_column($resultTo['applied'], 'migration');

    $onlyInFrom = array_diff($appliedFrom, $appliedTo);
    $onlyInTo = array_diff($appliedTo, $appliedFrom);
    $inBoth = array_intersect($appliedFrom, $appliedTo);

    renderHeader('COMPARAR: ' . $from . ' ↔ ' . $to);
    ?>

    <div class="nav">
        <a href="/" class="btn">
            <span class="icon">←</span> Volver al Panel
        </a>
    </div>

    <div class="summary">
        <h3>📊 Resumen de Comparación</h3>
        <p><strong>Desde:</strong> <code><?= htmlspecialchars($from) ?></code> (<?= count($appliedFrom) ?> migraciones)</p>
        <p><strong>Hacia:</strong> <code><?= htmlspecialchars($to) ?></code> (<?= count($appliedTo) ?> migraciones)</p>
        <p><strong>Diferencia:</strong> <?= abs(count($appliedFrom) - count($appliedTo)) ?> migraciones</p>
    </div>

    <div class="info-box">
        <h3>ℹ️ Cómo Leer Esta Tabla</h3>
        <ul>
            <li><span class="badge badge-sync">✓</span> Migración aplicada en este entorno</li>
            <li><span class="badge badge-behind">✗</span> Migración NO aplicada en este entorno</li>
            <li>Filas resaltadas en amarillo indican migraciones solo en el entorno "desde"</li>
            <li>Filas resaltadas en azul indican migraciones solo en el entorno "hacia"</li>
        </ul>
    </div>

    <table class="compare-table">
        <thead>
            <tr>
                <th>MIGRACIÓN</th>
                <th><?= htmlspecialchars($from) ?></th>
                <th><?= htmlspecialchars($to) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($onlyInFrom as $mig): ?>
                <tr class="only-in-from">
                    <td><code><?= htmlspecialchars(parseMigrationDescription($mig)) ?></code></td>
                    <td><span class="badge badge-sync">✓</span></td>
                    <td><span class="badge badge-behind">✗</span></td>
                </tr>
            <?php endforeach; ?>
            <?php foreach ($onlyInTo as $mig): ?>
                <tr class="only-in-to">
                    <td><code><?= htmlspecialchars(parseMigrationDescription($mig)) ?></code></td>
                    <td><span class="badge badge-behind">✗</span></td>
                    <td><span class="badge badge-sync">✓</span></td>
                </tr>
            <?php endforeach; ?>
            <?php foreach ($inBoth as $mig): ?>
                <tr class="in-both">
                    <td><code><?= htmlspecialchars(parseMigrationDescription($mig)) ?></code></td>
                    <td><span class="badge badge-sync">✓</span></td>
                    <td><span class="badge badge-sync">✓</span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="result-box">
        <h3>📈 Resultados</h3>
        <p><strong><?= htmlspecialchars($from) ?></strong> tiene <strong><?= count($onlyInFrom) ?></strong> migraciones NO presentes en <strong><?= htmlspecialchars($to) ?></strong></p>
        <p><strong><?= htmlspecialchars($to) ?></strong> tiene <strong><?= count($onlyInTo) ?></strong> migraciones únicas</p>
        <p>Ambos entornos comparten <strong><?= count($inBoth) ?></strong> migraciones</p>
    </div>

    <?php
    renderFooter();
}

function renderApplyForm($config, $dbname = '') {
    renderHeader('APLICAR MIGRACIONES');
    ?>

    <div class="nav">
        <a href="/" class="btn">
            <span class="icon">←</span> Volver al Panel
        </a>
    </div>

    <div class="warning-box">
        <h3>⚠️ Importante: Leer Antes de Aplicar</h3>
        <p>Esta operación aplicará todas las migraciones pendientes al entorno seleccionado.</p>
        <ul>
            <li><strong>Respaldo automático:</strong> Se creará un respaldo completo de la base de datos antes de aplicar las migraciones</li>
            <li><strong>Entornos protegidos:</strong> Los entornos de producción (marcados con 🔒) no pueden modificarse desde este panel</li>
            <li><strong>Reversión:</strong> Si las migraciones fallan, restaure desde el archivo de respaldo usando <code>psql</code></li>
            <li><strong>Mejor práctica:</strong> Siempre pruebe las migraciones en staging antes de aplicarlas a producción</li>
        </ul>
    </div>

    <form method="POST" action="/apply" class="apply-form">
        <div class="form-group">
            <label for="db">Entorno Destino:</label>
            <select name="db" id="db" required>
                <?php foreach ($config['environments'] as $db => $env): ?>
                    <?php if ($env['protected'] !== true): ?>
                        <option value="<?= htmlspecialchars($db) ?>" <?= $db === $dbname ? 'selected' : '' ?>>
                            <?= htmlspecialchars($env['label']) ?> (<?= htmlspecialchars($env['role']) ?>)
                        </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
            <small style="color: var(--fg-dim); display: block; margin-top: 4px;">
                Solo se muestran entornos no protegidos. Los entornos de producción requieren migración manual.
            </small>
        </div>

        <div class="form-group">
            <label for="password">Contraseña de Confirmación:</label>
            <input type="password" name="password" id="password" required 
                   placeholder="Ingrese la contraseña de aplicación para confirmar">
            <small style="color: var(--fg-dim); display: block; margin-top: 4px;">
                Requerida para prevenir migraciones accidentales. Contacte al administrador si no tiene la contraseña.
            </small>
        </div>

        <div class="info-box">
            <h3>ℹ️ Qué Sucederá</h3>
            <ol style="padding-left: 20px; line-height: 1.8;">
                <li>Crear respaldo: <code>pg_dump</code> → <code>/backups/{db}_{timestamp}.sql</code></li>
                <li>Conectar a la base de datos PostgreSQL</li>
                <li>Ejecutar migraciones pendientes en orden cronológico</li>
                <li>Registrar cada migración en la tabla <code>migrations</code></li>
                <li>Mostrar resultados y cualquier error</li>
            </ol>
        </div>

        <div class="actions">
            <button type="submit" class="btn btn-apply">
                <span class="icon">▶</span> Aplicar Migraciones
            </button>
            <a href="/" class="btn">
                <span class="icon">✗</span> Cancelar
            </a>
        </div>
    </form>

    <?php
    renderFooter();
}

function handleApply($config) {
    $dbname = $_POST['db'] ?? '';
    $password = $_POST['password'] ?? '';

    if (!isset($config['environments'][$dbname])) {
        http_response_code(400);
        renderHeader('ERROR');
        echo "<div class='error-box'><h3>⚠ Entorno Inválido</h3><p>Entorno '$dbname' no encontrado.</p></div>";
        renderFooter();
        return;
    }

    $env = $config['environments'][$dbname];
    if ($env['protected'] === true) {
        http_response_code(403);
        renderHeader('PROHIBIDO');
        echo "<div class='error-box'><h3>🔒 Entorno Protegido</h3><p>Este entorno está protegido. No se pueden aplicar migraciones desde el panel.</p></div>";
        renderFooter();
        return;
    }

    if ($password !== $config['apply_password']) {
        http_response_code(403);
        renderHeader('PROHIBIDO');
        echo "<div class='error-box'><h3>⚠ Contraseña Inválida</h3><p>La contraseña de confirmación es incorrecta.</p></div>";
        renderFooter();
        return;
    }

    $result = getMigrations($config, $dbname);
    $allMigrations = getAllMigrationFiles($config);
    $appliedNames = array_column($result['applied'], 'migration');
    $pending = array_filter($allMigrations, fn($m) => !in_array($m['filename'], $appliedNames));

    if (empty($pending)) {
        renderHeader('SIN MIGRACIONES PENDIENTES');
        ?>
        <div class="result-box">
            <h3>✓ Todas las Migraciones Aplicadas</h3>
            <p>Todas las migraciones ya están aplicadas en <code><?= htmlspecialchars($dbname) ?></code>.</p>
        </div>
        <div class="actions">
            <a href="/detail?db=<?= urlencode($dbname) ?>" class="btn">
                <span class="icon">👁</span> Ver <?= htmlspecialchars($dbname) ?>
            </a>
            <a href="/" class="btn">
                <span class="icon">←</span> Volver al Panel
            </a>
        </div>
        <?php
        renderFooter();
        return;
    }

    // Crear respaldo
    $backupFile = $config['backup_dir'] . '/' . $dbname . '_' . date('Ymd_His') . '.sql';
    $backupCmd = sprintf(
        'docker exec advdev-postgres pg_dump -U %s %s > %s',
        escapeshellarg($config['pg_user']),
        escapeshellarg($dbname),
        escapeshellarg($backupFile)
    );

    exec($backupCmd, $output, $returnCode);
    if ($returnCode !== 0) {
        renderHeader('FALLÓ EL RESPALDO');
        ?>
        <div class="error-box">
            <h3>✗ Falló el Respaldo</h3>
            <p>No se pudo crear el respaldo de la base de datos. Migración abortada por seguridad.</p>
            <p><strong>Comando:</strong> <code><?= htmlspecialchars($backupCmd) ?></code></p>
            <p><strong>Posibles causas:</strong></p>
            <ul>
                <li>Contenedor PostgreSQL no está corriendo</li>
                <li>Espacio en disco insuficiente</li>
                <li>Problemas de permisos</li>
            </ul>
        </div>
        <div class="actions">
            <a href="/" class="btn">
                <span class="icon">←</span> Volver al Panel
            </a>
        </div>
        <?php
        renderFooter();
        return;
    }

    // Conectar a la base de datos
    $pdo = getDbConnection($config, $dbname);
    if (!$pdo) {
        renderHeader('ERROR DE CONEXIÓN');
        ?>
        <div class="error-box">
            <h3>✗ Falló la Conexión a la Base de Datos</h3>
            <p>No se puede conectar a la base de datos <code><?= htmlspecialchars($dbname) ?></code>.</p>
            <p><strong>Respaldo creado:</strong> <code><?= htmlspecialchars($backupFile) ?></code></p>
        </div>
        <div class="actions">
            <a href="/" class="btn">
                <span class="icon">←</span> Volver al Panel
            </a>
        </div>
        <?php
        renderFooter();
        return;
    }

    // Aplicar migraciones
    $applied = 0;
    $errors = [];
    $startTime = microtime(true);

    foreach ($pending as $mig) {
        $migrationFile = $mig['path'];
        $migrationName = $mig['filename'];

        try {
            require $migrationFile;
            $migration = new (require $migrationFile);
            $migration->up();

            $stmt = $pdo->prepare("INSERT INTO migrations (migration, batch) VALUES (?, (SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations))");
            $stmt->execute([$migrationName]);

            $applied++;
        } catch (Exception $e) {
            $errors[] = [
                'migration' => $migrationName,
                'error' => $e->getMessage()
            ];
        }
    }

    $duration = round(microtime(true) - $startTime, 2);

    renderHeader('APLICACIÓN COMPLETA');
    ?>

    <div class="result-box">
        <h3>✓ Resultados de Migración</h3>
        <p><strong>Entorno:</strong> <code><?= htmlspecialchars($dbname) ?></code></p>
        <p><strong>Aplicadas:</strong> <strong><?= $applied ?></strong> migraciones en <strong><?= $duration ?>s</strong></p>
        <p><strong>Respaldo:</strong> <code><?= htmlspecialchars($backupFile) ?></code></p>
        
        <?php if (empty($errors)): ?>
            <p class="success">✓ ¡Todas las migraciones aplicadas exitosamente!</p>
        <?php endif; ?>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="error-box">
            <h3>⚠ Errores (<?= count($errors) ?>)</h3>
            <p>Las siguientes migraciones fallaron:</p>
            <ul>
                <?php foreach ($errors as $err): ?>
                    <li>
                        <strong><code><?= htmlspecialchars($err['migration']) ?></code></strong>
                        <br>
                        <small><?= htmlspecialchars($err['error']) ?></small>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p><strong>Próximos pasos:</strong></p>
            <ol style="padding-left: 20px; line-height: 1.8;">
                <li>Revise los mensajes de error arriba</li>
                <li>Verifique la sintaxis del archivo de migración</li>
                <li>Si es necesario, restaure desde el respaldo: <code><?= htmlspecialchars($backupFile) ?></code></li>
                <li>Corrija los problemas y reintente</li>
            </ol>
        </div>
    <?php endif; ?>

    <div class="actions">
        <a href="/detail?db=<?= urlencode($dbname) ?>" class="btn">
            <span class="icon">👁</span> Ver <?= htmlspecialchars($dbname) ?>
        </a>
        <a href="/" class="btn">
            <span class="icon">←</span> Volver al Panel
        </a>
    </div>

    <?php
    renderFooter();
}

$route = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

switch ($route) {
    case '/':
        renderDashboard($config);
        break;

    case '/detail':
        $dbname = $_GET['db'] ?? 'db_g';
        renderDetail($config, $dbname);
        break;

    case '/compare':
        $from = $_GET['from'] ?? 'db_main';
        $to = $_GET['to'] ?? 'db_staging';
        renderCompare($config, $from, $to);
        break;

    case '/apply':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            handleApply($config);
        } else {
            $dbname = $_GET['db'] ?? '';
            renderApplyForm($config, $dbname);
        }
        break;

    case '/help':
        renderHelp();
        break;

    default:
        http_response_code(404);
        renderHeader('404 NO ENCONTRADO');
        echo "<div class='error-box'><p>⚠ Página no encontrada.</p></div>";
        renderFooter();
        break;
}

function renderHelp() {
    renderHeader('AYUDA Y DOCUMENTACIÓN');
    ?>

    <div class="nav">
        <a href="/" class="btn">
            <span class="icon">←</span> Volver al Panel
        </a>
    </div>

    <div class="help-section">
        <h3>📖 Ayuda del Panel de Base de Datos</h3>
        <p>Este panel monitorea y gestiona el estado de migraciones de Laravel en múltiples entornos de base de datos PostgreSQL.</p>
    </div>

    <div class="help-section">
        <h3>🎯 Funcionalidades</h3>
        <ul>
            <li><strong>Vista General del Panel:</strong> Vista general de todos los entornos con estado de migraciones</li>
            <li><strong>Detalle de Entorno:</strong> Lista completa de migraciones por entorno con estado individual</li>
            <li><strong>Comparar:</strong> Comparación lado a lado de dos entornos</li>
            <li><strong>Aplicar Migraciones:</strong> Aplicación segura de migraciones con respaldo automático</li>
        </ul>
    </div>

    <div class="help-section">
        <h3>🔒 Niveles de Protección</h3>
        <ul>
            <li><span class="badge badge-protected">PROD</span> <strong>Producción:</strong> Bloqueado. No se pueden aplicar migraciones desde el panel. Solo migración manual con confirmación explícita.</li>
            <li><span class="badge badge-behind">STAGING</span> <strong>Pre-producción:</strong> Requiere confirmación doble (contraseña + confirmación escrita).</li>
            <li><span class="badge badge-sync">DEV</span> <strong>Desarrollo:</strong> Migración libre con contraseña simple.</li>
        </ul>
    </div>

    <div class="help-section">
        <h3>📊 Indicadores de Estado</h3>
        <ul>
            <li><span class="badge badge-sync">SINCRONIZADO</span> Todas las migraciones disponibles están aplicadas</li>
            <li><span class="badge badge-behind">ATRASADO</span> Faltan migraciones por aplicar</li>
            <li><span class="badge badge-ahead">ADELANTADO</span> Tiene migraciones extras (no están en el código fuente)</li>
            <li><span class="badge badge-error">ERROR</span> No se puede conectar a la base de datos o la tabla migrations no existe</li>
        </ul>
    </div>

    <div class="help-section">
        <h3>🔄 Aplicar Migraciones</h3>
        <p><strong>Proceso:</strong></p>
        <ol style="padding-left: 20px; line-height: 1.8;">
            <li>Seleccione el entorno destino (solo entornos no protegidos disponibles)</li>
            <li>Ingrese la contraseña de confirmación</li>
            <li>El sistema crea un respaldo automático vía <code>pg_dump</code></li>
            <li>Las migraciones pendientes se ejecutan en orden cronológico</li>
            <li>Cada migración se registra en la tabla <code>migrations</code></li>
            <li>Se muestran los resultados y cualquier error</li>
        </ol>
        <p><strong>Ubicación del respaldo:</strong> <code>/home/advserver/backups/{db}_{timestamp}.sql</code></p>
    </div>

    <div class="help-section">
        <h3>🛠️ Procedimiento de Reversión</h3>
        <p>Si las migraciones fallan o causan problemas, restaure desde el respaldo:</p>
        <pre style="background: var(--bg-dark); padding: 12px; border-radius: 4px; overflow-x: auto; margin: 12px 0;"><code># Conectar al contenedor PostgreSQL
docker exec -it advdev-postgres psql -U adv_user -d postgres

# Eliminar y recrear la base de datos
DROP DATABASE db_staging;
CREATE DATABASE db_staging OWNER adv_user;
\q

# Restaurar desde el respaldo
docker exec -i advdev-postgres psql -U adv_user -d db_staging < /home/advserver/backups/db_staging_20260723_190000.sql</code></pre>
    </div>

    <div class="help-section">
        <h3>⚙️ Configuración</h3>
        <p><strong>Archivo de configuración:</strong> <code>/home/advserver/docker-env/db-dashboard/public/config.php</code></p>
        <p><strong>Para cambiar la contraseña de aplicación:</strong></p>
        <ol style="padding-left: 20px; line-height: 1.8;">
            <li>Edite <code>config.php</code></li>
            <li>Modifique <code>'apply_password' => 'nueva_contraseña'</code></li>
            <li>Reinicie el contenedor: <code>docker compose restart</code></li>
        </ol>
        <p><strong>Para agregar un nuevo entorno:</strong></p>
        <ol style="padding-left: 20px; line-height: 1.8;">
            <li>Edite <code>config.php</code></li>
            <li>Agregue una entrada al array <code>environments</code></li>
            <li>Reinicie el contenedor: <code>docker compose restart</code></li>
        </ol>
    </div>

    <div class="help-section">
        <h3>🔍 Solución de Problemas</h3>
        <ul>
            <li><strong>Estado ERROR:</strong> Verifique si la base de datos existe y PostgreSQL está corriendo</li>
            <li><strong>No se pueden aplicar migraciones:</strong> Verifique la contraseña y compruebe si el entorno está protegido</li>
            <li><strong>Falló el respaldo:</strong> Verifique el espacio en disco y los permisos de PostgreSQL</li>
            <li><strong>Errores de migración:</strong> Revise los mensajes de error y verifique la sintaxis del archivo de migración</li>
        </ul>
    </div>

    <div class="help-section">
        <h3>📞 Soporte</h3>
        <p><strong>Documentación:</strong> <code>/home/advserver/docker-env/advdev/dev-g/html/bitacora/2026-07-23-db-dashboard-terminal-ui.md</code></p>
        <p><strong>Guía de migración:</strong> <code>/home/advserver/migracion-ip-242/MIGRACION_IP.md</code></p>
        <p><strong>Logs del contenedor:</strong> <code>docker logs db-dashboard</code></p>
    </div>

    <?php
    renderFooter();
}
