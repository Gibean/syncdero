<?php

declare(strict_types=1);

/**
 * SYNCDERO - Plantilla de configuración Multi-Proyecto.
 *
 * Copie este archivo como config/syncdero.php y ajuste credenciales:
 *   cp config/syncdero.example.php config/syncdero.php
 *
 * config/syncdero.php está en .gitignore: nunca se versiona porque el
 * dashboard persiste ahí los proyectos y servidores guardados (con contraseñas).
 * Prefiera variables de entorno para cualquier credencial.
 */

return [
    'project_name'   => 'Proyecto Predeterminado',
    'migrations_dir' => getenv('SYNCDERO_MIGRATIONS_DIR') ?: dirname(__DIR__) . '/migrations',
    'backup_dir'     => getenv('SYNCDERO_BACKUP_DIR') ?: dirname(__DIR__) . '/backups',
    'repo_dir'       => getenv('SYNCDERO_REPO_DIR') ?: dirname(__DIR__) . '/.git',
    'reference_env'  => '',
    'apply_password' => getenv('SYNCDERO_APPLY_PASSWORD') ?: '',

    // Proyectos independientes (Arquitectura Multi-Proyecto y Open Source)
    'projects' => [
        'demo_sqlite' => [
            'name'           => 'Proyecto Demo Local (SQLite)',
            'repo_dir'       => dirname(__DIR__) . '/.git',
            'migrations_dir' => dirname(__DIR__) . '/migrations',
            'backup_dir'     => dirname(__DIR__) . '/backups',
            'reference_env'  => 'sqlite_demo',
            'servers'        => [],
            'environments'   => [
                'sqlite_demo' => [
                    'label'     => 'SQLite Demo Local',
                    'driver'    => 'sqlite',
                    'database'  => dirname(__DIR__) . '/backups/demo.sqlite',
                    'protected' => false,
                    'role'      => 'dev',
                    'color'     => '#3b82f6',
                ],
            ],
        ],

        // Ejemplo de Proyecto con servidor PostgreSQL o MySQL:
        // 'mi_proyecto_erp' => [
        //     'name'           => 'Mi Proyecto ERP',
        //     'repo_dir'       => '/ruta/al/proyecto/.git',
        //     'migrations_dir' => '/ruta/al/proyecto/migrations',
        //     'backup_dir'     => '/ruta/al/proyecto/backups',
        //     'reference_env'  => 'servidor_pg__db_main',
        //     'servers'        => [
        //         'servidor_pg' => [
        //             'label'         => 'PostgreSQL Server',
        //             'driver'        => 'pgsql',
        //             'host'          => getenv('PG_HOST') ?: '127.0.0.1',
        //             'port'          => (int) (getenv('PG_PORT') ?: 5432),
        //             'user'          => getenv('PG_USER') ?: 'postgres',
        //             'password'      => getenv('PG_PASSWORD') ?: '',
        //             'auto_discover' => true,
        //             'protected'     => true,
        //             'role'          => 'dev',
        //         ],
        //     ],
        //     'environments'   => [],
        // ],
    ],

    // Servidores globales (opcional)
    'servers' => [],

    // Entornos fijos globales (opcional)
    'environments' => [
        'sqlite_demo' => [
            'label'     => 'SQLite Demo Local',
            'driver'    => 'sqlite',
            'database'  => dirname(__DIR__) . '/backups/demo.sqlite',
            'protected' => false,
            'role'      => 'dev',
            'color'     => '#3b82f6',
        ],
    ],
];
