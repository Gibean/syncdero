<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión — SYNCDERO</title>
    <link rel="stylesheet" href="/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: time() ?>">
    <link rel="stylesheet" href="/assets/icons.css?v=<?= @filemtime(__DIR__ . '/assets/icons.css') ?: time() ?>">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <style>
        .login-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: var(--bg-canvas, #0f172a);
        }
        .login-card {
            background: var(--bg-surface, #1e293b);
            border: 1px solid var(--border-color, #334155);
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 400px;
            padding: 32px;
        }
        .login-brand {
            text-align: center;
            margin-bottom: 24px;
        }
        .login-brand img {
            max-width: 220px;
            height: auto;
            margin-bottom: 8px;
        }
        .login-subtitle {
            font-size: 13px;
            color: var(--text-muted, #94a3b8);
        }
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary, #cbd5e1);
            margin-bottom: 6px;
        }
        .form-group input {
            width: 100%;
            padding: 10px 12px;
            background: var(--bg-input, #0f172a);
            border: 1px solid var(--border-color, #334155);
            border-radius: 6px;
            color: var(--text-primary, #f8fafc);
            font-size: 14px;
            box-sizing: border-box;
        }
        .form-group input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
        }
        .alert-error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.4);
            color: #fca5a5;
            padding: 10px 12px;
            border-radius: 6px;
            font-size: 12px;
            margin-bottom: 18px;
        }
    </style>
</head>
<body class="theme-dark">
    <div class="login-wrapper">
        <div class="login-card">
            <div class="login-brand">
                <img src="/assets/logo/imagotipo-modo-oscuro-claro.webp" alt="SYNCDERO Logo">
                <div class="login-subtitle">Modo Equipo — Gestión de Espejos y Esquemas</div>
            </div>

            <?php if (!empty($loginError)): ?>
                <div class="alert-error">
                    <?= htmlspecialchars($loginError) ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($authManager->getCsrfToken()) ?>">

                <div class="form-group">
                    <label for="username">Usuario o Desarrollador:</label>
                    <input type="text" id="username" name="username" required autofocus placeholder="Ej: dev_gisvel o admin">
                </div>

                <div class="form-group">
                    <label for="password">Contraseña:</label>
                    <input type="password" id="password" name="password" required placeholder="••••••••">
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 10px; margin-top: 8px;">
                    Ingresar al Panel
                </button>
            </form>
        </div>
    </div>
</body>
</html>
