<?php
// login.php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

iniciarSesion();

if (usuarioLogueado()) {
    header('Location: ' . APP_URL . '/dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        try {
            $db = Database::getInstance();
            $usuario = $db->fetchOne(
                "SELECT id, username, password_hash, nombre, apellido, email, rol, activo
                 FROM usuarios WHERE username = ? OR email = ?",
                [$username, $username]
            );

            if ($usuario && $usuario['activo'] && password_verify($password, $usuario['password_hash'])) {
                $_SESSION['usuario_id']    = $usuario['id'];
                $_SESSION['nombre']        = $usuario['nombre'];
                $_SESSION['apellido']      = $usuario['apellido'];
                $_SESSION['email']         = $usuario['email'];
                $_SESSION['rol']           = $usuario['rol'];
                $_SESSION['last_activity'] = time();

                $db->query("UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?", [$usuario['id']]);
                registrarAuditoria('usuarios', 'LOGIN', $usuario['id'], [], []);

                $redirect = $_GET['redirect'] ?? APP_URL . '/dashboard.php';
                header('Location: ' . $redirect);
                exit;
            } else {
                $error = 'Usuario o contraseña incorrectos.';
            }
        } catch (Exception $e) {
            $error = 'Error del sistema. Intente nuevamente.';
        }
    } else {
        $error = 'Ingrese usuario y contraseña.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión · <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css">
    <style>
        body {
            background: linear-gradient(135deg, #1a3a5c 0%, #0f2338 50%, #1a3a5c 100%);
            display: flex; align-items: center; justify-content: center;
            min-height: 100vh; font-family: var(--font-body);
        }
        .login-wrapper {
            display: grid;
            grid-template-columns: 1fr 420px;
            min-height: 100vh;
            width: 100%;
        }
        .login-hero {
            display: flex; flex-direction: column;
            justify-content: center; align-items: center;
            padding: 3rem;
            background: rgba(255,255,255,.04);
        }
        .login-hero-icon {
            width: 90px; height: 90px;
            background: var(--col-accent);
            border-radius: 24px;
            display: grid; place-items: center;
            font-size: 2.5rem; color: #fff;
            margin-bottom: 1.5rem;
            box-shadow: 0 8px 32px rgba(200,146,42,.3);
        }
        .login-hero h1 {
            font-family: var(--font-display);
            font-size: 2.8rem;
            color: #fff;
            line-height: 1.1;
            text-align: center;
            margin-bottom: .8rem;
        }
        .login-hero p { color: rgba(255,255,255,.55); text-align: center; max-width: 360px; line-height: 1.7; }
        .login-hero .features { margin-top: 2.5rem; display: flex; flex-direction: column; gap: .9rem; }
        .feature-item {
            display: flex; align-items: center; gap: .9rem;
            color: rgba(255,255,255,.72); font-size: .9rem;
        }
        .feature-item i { color: var(--col-accent-l); width: 20px; }

        .login-panel {
            background: #fff;
            display: flex; flex-direction: column;
            justify-content: center; padding: 3rem;
        }
        .login-panel h2 {
            font-family: var(--font-display);
            font-size: 1.8rem;
            color: var(--col-primary);
            margin-bottom: .4rem;
        }
        .login-panel p.subtitle { color: var(--col-muted); font-size: .9rem; margin-bottom: 2rem; }

        .login-form .form-group { margin-bottom: 1.2rem; }
        .login-form label { font-weight: 500; font-size: .83rem; }
        .login-form .form-control {
            padding: .75rem 1rem; font-size: .92rem;
        }
        .input-icon-wrap { position: relative; }
        .input-icon-wrap i {
            position: absolute; left: .9rem; top: 50%; transform: translateY(-50%);
            color: var(--col-muted); font-size: .9rem;
        }
        .input-icon-wrap .form-control { padding-left: 2.4rem; }

        .btn-login {
            width: 100%; padding: .85rem;
            background: var(--col-primary);
            color: #fff; border: none; border-radius: var(--radius-sm);
            font-family: var(--font-body); font-size: 1rem; font-weight: 600;
            cursor: pointer; transition: background var(--transition);
            margin-top: .5rem;
        }
        .btn-login:hover { background: var(--col-primary-l); }

        .login-footer { margin-top: 2rem; text-align: center; color: var(--col-muted); font-size: .8rem; }

        @media (max-width: 768px) {
            .login-wrapper { grid-template-columns: 1fr; }
            .login-hero { display: none; }
            .login-panel { min-height: 100vh; }
        }
    </style>
</head>
<body>
<div class="login-wrapper">
    <div class="login-hero">
        <div class="login-hero-icon"><i class="fas fa-university"></i></div>
        <h1>Sistema de<br>Cartera</h1>
        <p>Gestión integral de liquidaciones, pagos y cartera universitaria en Colombia.</p>
        <div class="features">
            <div class="feature-item"><i class="fas fa-file-invoice-dollar"></i> Liquidación automática de matrículas</div>
            <div class="feature-item"><i class="fas fa-money-bill-wave"></i> Registro de pagos en tiempo real</div>
            <div class="feature-item"><i class="fas fa-chart-line"></i> Reportes y análisis de cartera</div>
            <div class="feature-item"><i class="fas fa-shield-alt"></i> Control de acceso por roles</div>
        </div>
    </div>

    <div class="login-panel">
        <h2>Bienvenido</h2>
        <p class="subtitle">Ingrese sus credenciales para acceder al sistema</p>

        <?php if ($error): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" class="login-form">
            <?= csrfField() ?>
            <div class="form-group">
                <label for="username">Usuario o correo electrónico</label>
                <div class="input-icon-wrap">
                    <i class="fas fa-user"></i>
                    <input type="text" name="username" id="username" class="form-control"
                           placeholder="usuario@universidad.edu.co"
                           value="<?= e($_POST['username'] ?? '') ?>" required autofocus>
                </div>
            </div>
            <div class="form-group">
                <label for="password">Contraseña</label>
                <div class="input-icon-wrap">
                    <i class="fas fa-lock"></i>
                    <input type="password" name="password" id="password" class="form-control"
                           placeholder="••••••••" required>
                </div>
            </div>
            <button type="submit" class="btn-login">
                <i class="fas fa-sign-in-alt"></i> Iniciar Sesión
            </button>
        </form>

        <div class="login-footer">
            <strong><?= APP_NAME ?></strong> v<?= APP_VERSION ?><br>
            &copy; <?= date('Y') ?> · Todos los derechos reservados<br>
            <small style="color:#aaa">Usuarios demo: admin / financiero1 / cajero1 · Contraseña: password</small>
        </div>
    </div>
</div>
</body>
</html>
