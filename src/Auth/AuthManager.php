<?php

declare(strict_types=1);

namespace Syncdero\Auth;

use Syncdero\Core\Config;

class AuthManager
{
    protected Config $config;
    protected array $users = [];
    protected ?User $currentUser = null;
    protected string $usersFilePath;

    public function __construct(Config $config, ?string $usersFilePath = null)
    {
        $this->config = $config;
        $this->usersFilePath = $usersFilePath ?? (__DIR__ . '/../../config/users.php');
        $this->loadUsers();
        $this->initSession();
    }

    public function isTeamMode(): bool
    {
        return $this->config->isTeamMode();
    }

    protected function initSession(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            // Configurar cookies de sesión seguras
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            session_set_cookie_params([
                'lifetime' => 86400 * 7, // 7 días
                'path' => '/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }

        // Generar token CSRF si no existe
        if (empty($_SESSION['_syncdero_csrf'])) {
            $_SESSION['_syncdero_csrf'] = bin2hex(random_bytes(32));
        }
    }

    protected function loadUsers(): void
    {
        if (file_exists($this->usersFilePath)) {
            $data = require $this->usersFilePath;
            if (is_array($data) && isset($data['users']) && is_array($data['users'])) {
                foreach ($data['users'] as $username => $u) {
                    $this->users[$username] = new User(
                        id: (string)($u['id'] ?? $username),
                        username: (string)$username,
                        name: (string)($u['name'] ?? $username),
                        email: (string)($u['email'] ?? ''),
                        role: (string)($u['role'] ?? 'developer'),
                        assignedEnv: $u['assigned_env'] ?? null,
                        passwordHash: $u['password_hash'] ?? null
                    );
                }
            }
        }

        // Si no hay usuarios definidos en archivo, garantizar cuenta admin por defecto vía env
        if (empty($this->users)) {
            $adminPass = $this->config->getApplyPassword() ?: 'admin';
            $this->users['admin'] = new User(
                id: 'admin',
                username: 'admin',
                name: 'Administrador SYNCDERO',
                email: 'admin@local',
                role: 'admin',
                assignedEnv: null,
                passwordHash: password_hash($adminPass, PASSWORD_BCRYPT)
            );
        }
    }

    public function getCurrentUser(): User
    {
        // En Modo Standalone (desarrollador individual), el usuario siempre es Admin local de fricción cero
        if (!$this->isTeamMode()) {
            return new User(
                id: 'local_dev',
                username: 'standalone_dev',
                name: 'Desarrollador Individual',
                email: '',
                role: 'admin',
                assignedEnv: null
            );
        }

        if ($this->currentUser !== null) {
            return $this->currentUser;
        }

        $sessionUser = $_SESSION['syncdero_user'] ?? null;
        if ($sessionUser && isset($this->users[$sessionUser])) {
            $this->currentUser = $this->users[$sessionUser];
            return $this->currentUser;
        }

        // Retorna usuario anónimo no autenticado
        return new User(
            id: 'guest',
            username: 'guest',
            name: 'Invitado',
            email: '',
            role: 'guest',
            assignedEnv: null
        );
    }

    public function isAuthenticated(): bool
    {
        if (!$this->isTeamMode()) {
            return true;
        }

        $user = $this->getCurrentUser();
        return $user->role !== 'guest';
    }

    public function login(string $username, string $password): bool
    {
        $user = $this->users[$username] ?? null;
        if (!$user || !$user->passwordHash) {
            return false;
        }

        if (password_verify($password, $user->passwordHash)) {
            $_SESSION['syncdero_user'] = $username;
            $this->currentUser = $user;
            return true;
        }

        return false;
    }

    public function logout(): void
    {
        unset($_SESSION['syncdero_user']);
        $this->currentUser = null;
    }

    public function getCsrfToken(): string
    {
        return $_SESSION['_syncdero_csrf'] ?? '';
    }

    public function validateCsrfToken(?string $token): bool
    {
        if (empty($token) || empty($_SESSION['_syncdero_csrf'])) {
            return false;
        }
        return hash_equals($_SESSION['_syncdero_csrf'], $token);
    }

    /**
     * Filtra los entornos visibles según los permisos del usuario activo.
     */
    public function filterEnvironments(array $allEnvironments): array
    {
        $user = $this->getCurrentUser();
        if ($user->isAdmin()) {
            return $allEnvironments;
        }

        $referenceEnv = $this->config->getReferenceEnv();
        $filtered = [];

        foreach ($allEnvironments as $key => $envConfig) {
            if ($user->canAccessEnvironment($key, $referenceEnv)) {
                // Marcar si es el espejo propio o el entorno de referencia
                if ($key === $user->assignedEnv) {
                    $envConfig['is_my_mirror'] = true;
                    $envConfig['label'] = '★ (Mi Espejo) ' . ($envConfig['label'] ?? $key);
                } elseif ($key === $referenceEnv) {
                    $envConfig['is_reference'] = true;
                    $envConfig['label'] = '🌐 (Principal Master) ' . ($envConfig['label'] ?? $key);
                    $envConfig['protected'] = true; // Proteger la principal para que el dev no haga mutaciones directas
                }
                $filtered[$key] = $envConfig;
            }
        }

        return $filtered;
    }
}
