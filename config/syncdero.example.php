<?php

declare(strict_types=1);

/**
 * SYNCDERO - Plantilla de configuración.
 *
 * Copie este archivo como config/syncdero.php y ajuste credenciales:
 *   cp config/syncdero.example.php config/syncdero.php
 *
 * config/syncdero.php está en .gitignore: nunca se versiona porque el
 * dashboard persiste ahí los servidores guardados (con contraseñas).
 * Prefiera variables de entorno para cualquier credencial.
 */

return [
    // Directorios del sistema
    'migrations_dir' => getenv('SYNCDERO_MIGRATIONS_DIR') ?: (__DIR__ . '/../migrations'),
    'backup_dir'     => getenv('SYNCDERO_BACKUP_DIR') ?: (__DIR__ . '/../backups'),
    'repo_dir'       => getenv('SYNCDERO_REPO_DIR') ?: (__DIR__ . '/../.git'),

    // Contraseña para aplicar cambios en entornos protegidos.
    // Vía variable de entorno; vacío = sin protección activa.
    'apply_password' => getenv('SYNCDERO_APPLY_PASSWORD') ?: '',

    // Instancias de servidores con Auto-Discovery (estilo HeidiSQL).
    // El dashboard agrega/elimina servidores aquí al guardarlos desde la UI.
    'servers' => [
        // 'mariadb_host' => [
        //     'label'         => 'MariaDB Host',
        //     'driver'        => 'mysql',
        //     'host'          => getenv('MARIADB_HOST') ?: '127.0.0.1',
        //     'port'          => (int) (getenv('MARIADB_PORT') ?: 3306),
        //     'user'          => getenv('MARIADB_USER') ?: '',
        //     'password'      => getenv('MARIADB_PASSWORD') ?: '',
        //     'auto_discover' => true, // Detectar y listar bases de datos del host
        //     'protected'     => false,
        //     'role'          => 'dev',
        // ],
    ],

    // Entornos fijos y específicos (branches/remotes de su proyecto).
    'environments' => [
        // Entorno SQLite local (pruebas sin instalar ningún servidor de BD)
        'sqlite_demo' => [
            'label'     => 'SQLite Demo Local',
            'driver'    => 'sqlite',
            'database'  => __DIR__ . '/../backups/demo.sqlite',
            'protected' => false,
            'role'      => 'dev',
            'color'     => '#3b82f6', // azul
        ],

        // Ejemplo PostgreSQL (complete credenciales vía variables de entorno)
        // 'pg_main' => [
        //     'label'     => 'Postgres Producción',
        //     'driver'    => 'pgsql',
        //     'host'      => getenv('PG_HOST') ?: '127.0.0.1',
        //     'port'      => (int) (getenv('PG_PORT') ?: 5432),
        //     'database'  => getenv('PG_DATABASE') ?: 'app_db',
        //     'user'      => getenv('PG_USER') ?: '',
        //     'password'  => getenv('PG_PASSWORD') ?: '',
        //     'protected' => true,
        //     'role'      => 'prod',
        //     'color'     => '#ef4444', // rojo
        // ],
    ],
];
