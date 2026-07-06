<?php
// ============================================================
// includes/helpers.php - Funciones utilitarias
// ============================================================

require_once __DIR__ . '/../config/database.php';

// ── Sesión ───────────────────────────────────────────────────
function iniciarSesion(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
        session_start();
    }
}

function usuarioLogueado(): bool {
    iniciarSesion();
    if (empty($_SESSION['usuario_id'])) return false;
    if (time() - ($_SESSION['last_activity'] ?? 0) > SESSION_TIMEOUT) {
        session_destroy();
        return false;
    }
    $_SESSION['last_activity'] = time();
    return true;
}

function requireLogin(): void {
    if (!usuarioLogueado()) {
        header('Location: ' . APP_URL . '/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
}

function requireRol(array $roles): void {
    requireLogin();
    if (!in_array($_SESSION['rol'] ?? '', $roles)) {
        http_response_code(403);
        die(renderError(403, 'No tiene permisos para acceder a esta sección.'));
    }
}

function sesionUsuario(): array {
    return [
        'id'     => $_SESSION['usuario_id'] ?? null,
        'nombre' => $_SESSION['nombre'] ?? '',
        'rol'    => $_SESSION['rol'] ?? '',
        'email'  => $_SESSION['email'] ?? '',
    ];
}

// ── Formato Colombia ─────────────────────────────────────────
function formatoPeso(float $valor): string {
    return '$ ' . number_format($valor, 0, ',', '.');
}

function formatoFecha(string $fecha, string $formato = 'd/m/Y'): string {
    if (empty($fecha)) return '-';
    $dt = new DateTime($fecha);
    $meses = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    if ($formato === 'largo') {
        return $dt->format('d') . ' de ' . $meses[(int)$dt->format('m') - 1] . ' de ' . $dt->format('Y');
    }
    return $dt->format($formato);
}

function calcularValorSMMLV(float $porcentaje): float {
    return round((SMMLV_VIGENTE * $porcentaje) / 100, -3); // Redondea a miles
}

function diasVencimiento(string $fecha_vencimiento): int {
    $hoy = new DateTime();
    $vence = new DateTime($fecha_vencimiento);
    return (int) $hoy->diff($vence)->format('%r%a');
}

function estadoBadge(string $estado): string {
    $map = [
        'pendiente'  => ['warning',  'Pendiente'],
        'parcial'    => ['info',     'Parcial'],
        'pagada'     => ['success',  'Pagada'],
        'vencida'    => ['danger',   'Vencida'],
        'anulada'    => ['secondary','Anulada'],
        'activo'     => ['success',  'Activo'],
        'inactivo'   => ['secondary','Inactivo'],
        'graduado'   => ['primary',  'Graduado'],
        'retirado'   => ['danger',   'Retirado'],
        'suspendido' => ['warning',  'Suspendido'],
        'aplicado'   => ['success',  'Aplicado'],
        'reversado'  => ['danger',   'Reversado'],
        'vigente'    => ['success',  'Vigente'],
        'cumplido'   => ['info',     'Cumplido'],
        'incumplido' => ['danger',   'Incumplido'],
    ];
    [$color, $label] = $map[$estado] ?? ['secondary', ucfirst($estado)];
    return "<span class=\"badge badge-{$color}\">{$label}</span>";
}

// ── Seguridad ─────────────────────────────────────────────────
function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

function validarCSRF(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrfToken(), $token)) {
        http_response_code(403);
        die(json_encode(['error' => 'Token CSRF inválido']));
    }
}

// ── Respuestas JSON ───────────────────────────────────────────
function jsonOk(array $data = [], string $mensaje = 'Operación exitosa'): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'mensaje' => $mensaje, 'data' => $data]);
    exit;
}

function jsonError(string $mensaje, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'mensaje' => $mensaje]);
    exit;
}

// ── Auditoría ─────────────────────────────────────────────────
function registrarAuditoria(string $tabla, string $accion, int $registro_id, array $anterior = [], array $nuevo = []): void {
    try {
        $db = Database::getInstance();
        $usuario = sesionUsuario();
        $db->query(
            "INSERT INTO auditoria (usuario_id, tabla, accion, registro_id, datos_anteriores, datos_nuevos, ip_address)
             VALUES (?, ?, ?, ?, ?::jsonb, ?::jsonb, ?::inet)",
            [
                $usuario['id'],
                $tabla,
                $accion,
                $registro_id,
                $anterior ? json_encode($anterior) : null,
                $nuevo    ? json_encode($nuevo)    : null,
                $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            ]
        );
    } catch (Exception $e) {
        error_log("Auditoria error: " . $e->getMessage());
    }
}

// ── Número de recibo ──────────────────────────────────────────
function generarNumeroRecibo(): string {
    $db = Database::getInstance();
    $ultimo = $db->fetchValue("SELECT MAX(CAST(SUBSTRING(numero_recibo FROM 5) AS BIGINT)) FROM pagos WHERE numero_recibo LIKE 'REC-%'");
    $consecutivo = ($ultimo ?? 0) + 1;
    return 'REC-' . str_pad($consecutivo, 8, '0', STR_PAD_LEFT);
}

function renderError(int $code, string $msg): string {
    return "<!DOCTYPE html><html><body><h1>Error $code</h1><p>$msg</p></body></html>";
}

// ── Paginación ────────────────────────────────────────────────
function paginar(int $total, int $pagina, int $porPagina = REGISTROS_POR_PAGINA): array {
    $totalPaginas = (int) ceil($total / $porPagina);
    $offset = ($pagina - 1) * $porPagina;
    return [
        'total'        => $total,
        'pagina'       => max(1, $pagina),
        'porPagina'    => $porPagina,
        'totalPaginas' => $totalPaginas,
        'offset'       => max(0, $offset),
        'hayAnterior'  => $pagina > 1,
        'haySiguiente' => $pagina < $totalPaginas,
    ];
}
