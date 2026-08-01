<?php

declare(strict_types=1);

namespace Syncdero\Drivers;

class DriverFactory
{
    /**
     * Instancia el driver adecuado según el tipo de base de datos.
     *
     * @param array $config
     * @return DriverInterface
     * @throws \InvalidArgumentException
     */
    public static function create(array $config): DriverInterface
    {
        $driver = strtolower($config['driver'] ?? 'mysql');

        return match ($driver) {
            'mysql', 'mariadb' => new MySqlDriver($config),
            'pgsql', 'postgres', 'postgresql' => new PostgresDriver($config),
            'sqlite' => new SqliteDriver($config),
            default => throw new \InvalidArgumentException("Driver de base de datos no soportado: '{$driver}'. Drivers soportados: mysql, mariadb, pgsql, sqlite."),
        };
    }
}
