<?php

declare(strict_types=1);

namespace Syncdero\Auth;

class User
{
    public function __construct(
        public readonly string $id,
        public readonly string $username,
        public readonly string $name,
        public readonly string $email,
        public readonly string $role, // 'admin' | 'developer'
        public readonly ?string $assignedEnv = null,
        public readonly ?string $passwordHash = null
    ) {
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isDeveloper(): bool
    {
        return $this->role === 'developer';
    }

    /**
     * Valida si el usuario tiene permiso para visualizar u operar un entorno específico.
     * Admin: acceso irrestricto a todos los entornos.
     * Developer: acceso exclusivamente a su espejo personal asignado y al entorno maestro de referencia (lectura).
     */
    public function canAccessEnvironment(string $envKey, string $referenceEnvKey = ''): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        if ($this->assignedEnv !== null && $envKey === $this->assignedEnv) {
            return true;
        }

        if ($referenceEnvKey !== '' && $envKey === $referenceEnvKey) {
            return true;
        }

        return false;
    }

    /**
     * Valida si el usuario puede realizar operaciones destructivas (sincronizar / revertir) en el entorno.
     */
    public function canMutateEnvironment(string $envKey): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        // Un desarrollador solo puede mutar su propio espejo personal, nunca la BD principal ni otros espejos
        return ($this->assignedEnv !== null && $envKey === $this->assignedEnv);
    }

    public function getSignature(): string
    {
        if ($this->email !== '') {
            return "{$this->name} <{$this->email}>";
        }
        return $this->name !== '' ? $this->name : $this->username;
    }
}
