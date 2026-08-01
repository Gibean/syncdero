<?php

declare(strict_types=1);

/**
 * SYNCDERO Configuration
 * Soporte multimotor agnóstico (MySQL, MariaDB, PostgreSQL, SQLite).
 * Configurable mediante variables de entorno o este archivo directamente.
 */

return [
    // Directorios del sistema
    'migrations_dir' => getenv('SYNCDERO_MIGRATIONS_DIR') ?: (__DIR__ . '/../migrations'),
    'backup_dir'     => getenv('SYNCDERO_BACKUP_DIR') ?: (__DIR__ . '/../backups'),
    'repo_dir'       => getenv('SYNCDERO_REPO_DIR') ?: (__DIR__ . '/../.git'),

    // Seguridad para ambientes protegidos
    'apply_password' => getenv('SYNCDERO_APPLY_PASSWORD') ?: '',

    // Ambientes gestionados (Bases de datos tipo Git Remotes/Branches)
    'environments' => [
        // Ejemplo MariaDB / MySQL local (para correr standalone en Windows o Docker)
        'mariadb_local' => [
            'label'     => 'MariaDB Local',
            'driver'    => 'mysql',
            'host'      => getenv('MARIADB_HOST') ?: '127.0.0.1',
            'port'      => (int)(getenv('MARIADB_PORT') ?: 3306),
            'database'  => getenv('MARIADB_DATABASE') ?: 'syncdero_demo',
            'user'      => getenv('MARIADB_USER') ?: 'root',
            'password'  => getenv('MARIADB_PASSWORD') ?: '',
            'protected' => false,
            'role'      => 'dev',
            'color'     => '#10b981', // verde
            'icon'      => '●',
        ],

        // Ejemplo PostgreSQL (compatible con tu contenedor advdev-postgres actual)
        'pg_main' => [
            'label'     => 'Postgres Producción',
            'driver'    => 'pgsql',
            'host'      => getenv('PG_HOST') ?: 'advdev-postgres',
            'port'      => (int)(getenv('PG_PORT') ?: 5432),
            'database'  => getenv('PG_DATABASE') ?: 'app_db',
            'user'      => getenv('PG_USER') ?: 'adv_user',
            'password'  => getenv('PG_PASSWORD') ?: '',
            'protected' => true,
            'role'      => 'prod',
            'color'     => '#ef4444', // rojo
            'icon'      => '🔒',
        ],

        'pg_staging' => [
            'label'     => 'Postgres Pre-producción',
            'driver'    => 'pgsql',
            'host'      => getenv('PG_HOST') ?: 'advdev-postgres',
            'port'      => (int)(getenv('PG_PORT') ?: 5432),
            'database'  => 'app_staging',
            'user'      => getenv('PG_USER') ?: 'adv_user',
            'password'  => getenv('PG_PASSWORD') ?: '',
            'protected' => 'warn',
            'role'      => 'staging',
            'color'     => '#f59e0b', // amarillo
            'icon'      => '⚠',
        ],

        // Ejemplo SQLite local (para pruebas instantáneas sin instalar ningún servidor de BD)
        'sqlite_demo' => [
            'label'     => 'SQLite Demo Local',
            'driver'    => 'sqlite',
            'database'  => __DIR__ . '/../backups/demo.sqlite',
            'protected' => false,
            'role'      => 'dev',
            'color'     => '#3b82f6', // azul
            'icon'      => '●',
        ],
    ],
];
