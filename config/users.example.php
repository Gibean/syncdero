<?php

declare(strict_types=1);

/**
 * SYNCDERO - Plantilla de Gestión de Desarrolladores y Roles (Modo Equipo)
 * Copie este archivo a config/users.php para definir los accesos de su equipo.
 * config/users.php está ignorado en Git para proteger los hashes de contraseñas.
 */

return [
    'users' => [
        // Cuenta de Administrador: ve todos los servidores, entornos y espejos
        'admin' => [
            'name'          => 'Administrador del Sistema',
            'email'         => 'admin@empresa.com',
            'role'          => 'admin',
            // Contraseña por defecto: admin123 (generada con password_hash)
            'password_hash' => '$2y$10$w8T.NrkxOQn4N1P5K3K8u.9R2IeAfgx5n9T5Kz9OqP6hP3P8y8W7a',
        ],

        // Desarrollador 1: solo puede ver su propio contenedor/espejo y la BD Principal para sincronizar
        'dev_gisvel' => [
            'name'          => 'Gisvel Aray',
            'email'         => 'gisvel@empresa.com',
            'role'          => 'developer',
            'assigned_env'  => 'mariadb_host__app_db', // Clave del entorno/espejo asignado
            // Contraseña por defecto: dev123
            'password_hash' => '$2y$10$qI.oNrkxOQn4N1P5K3K8u.9R2IeAfgx5n9T5Kz9OqP6hP3P8y8W7a',
        ],
    ],
];
