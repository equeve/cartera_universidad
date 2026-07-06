<?php
// modules/pagos/reversar.php
require_once __DIR__ . '/../../includes/helpers.php';
requireRol(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/modules/pagos/lista.php');
    exit;
}

validarCSRF();

$db      = Database::getInstance();
$pagoId  = (int)($_POST['pago_id'] ?? 0);
$motivo  = trim($_POST['motivo'] ?? '');

if (!$pagoId || !$motivo) {
    $_SESSION['flash_error'] = 'Datos incompletos para el reverso.';
    header('Location: ' . APP_URL . '/modules/pagos/lista.php');
    exit;
}

$pago = $db->fetchOne("SELECT * FROM pagos WHERE id = ? AND estado = 'aplicado'", [$pagoId]);

if (!$pago) {
    $_SESSION['flash_error'] = 'Pago no encontrado o ya reversado.';
    header('Location: ' . APP_URL . '/modules/pagos/lista.php');
    exit;
}

try {
    $db->query(
        "UPDATE pagos SET estado='reversado', reversado_por=?, fecha_reverso=NOW(), motivo_reverso=? WHERE id=?",
        [$usuario['id'], $motivo, $pagoId]
    );
    registrarAuditoria('pagos', 'UPDATE', $pagoId, ['estado'=>'aplicado'], ['estado'=>'reversado','motivo'=>$motivo]);
    $_SESSION['flash_success'] = 'Pago ' . $pago['numero_recibo'] . ' reversado correctamente.';
} catch (Exception $e) {
    $_SESSION['flash_error'] = 'Error al reversar el pago: ' . $e->getMessage();
}

header('Location: ' . APP_URL . '/modules/pagos/lista.php');
exit;
