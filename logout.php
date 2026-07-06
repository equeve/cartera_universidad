<?php
// logout.php
require_once __DIR__ . '/includes/helpers.php';
iniciarSesion();
if (isset($_SESSION['usuario_id'])) {
    registrarAuditoria('usuarios', 'LOGOUT', $_SESSION['usuario_id'], [], []);
}
session_destroy();
header('Location: ' . APP_URL . '/login.php');
exit;
