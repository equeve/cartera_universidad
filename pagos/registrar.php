<?php
// modules/pagos/registrar.php
$tituloPagina    = 'Registrar Pago';
$subtituloPagina = 'Aplicar pago a liquidación';
require_once __DIR__ . '/../../includes/header.php';
requireRol(['admin','financiero','cajero']);

$db = Database::getInstance();

$factura_id = (int)($_GET['factura_id'] ?? 0);
$factura    = null;

if ($factura_id) {
    $factura = $db->fetchOne(
        "SELECT f.*, p.nombre AS periodo_nombre,
                e.primer_nombre || ' ' || e.primer_apellido AS estudiante_nombre,
                e.codigo AS estudiante_codigo, e.email AS estudiante_email,
                pr.nombre AS programa
         FROM facturas f
         JOIN periodos p ON p.id = f.periodo_id
         JOIN estudiantes e ON e.id = f.estudiante_id
         JOIN programas pr ON pr.id = e.programa_id
         WHERE f.id = ? AND f.estado NOT IN ('anulada','pagada')",
        [$factura_id]
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarCSRF();

    $facId      = (int)$_POST['factura_id'];
    $medioId    = (int)$_POST['medio_pago_id'];
    $valor      = (float)str_replace(['.', ','], ['', '.'], $_POST['valor'] ?? '0');
    $ref        = trim($_POST['referencia_bancaria'] ?? '');
    $banco      = trim($_POST['banco'] ?? '');
    $obs        = trim($_POST['observaciones'] ?? '');
    $fechaPago  = $_POST['fecha_pago'] ?? date('Y-m-d\TH:i');

    if (!$facId || !$medioId || $valor <= 0) {
        $_SESSION['flash_error'] = 'Datos incompletos o valor inválido.';
    } else {
        $facActual = $db->fetchOne("SELECT * FROM facturas WHERE id = ? AND estado NOT IN ('anulada','pagada')", [$facId]);
        if (!$facActual) {
            $_SESSION['flash_error'] = 'Factura no encontrada o ya se encuentra pagada/anulada.';
        } elseif ($valor > $facActual['saldo']) {
            $_SESSION['flash_error'] = 'El valor ingresado supera el saldo pendiente de ' . formatoPeso($facActual['saldo']);
        } else {
            try {
                $db->beginTransaction();
                $numRecibo = generarNumeroRecibo();

                $db->query(
                    "INSERT INTO pagos (factura_id, medio_pago_id, numero_recibo, fecha_pago, valor, referencia_bancaria, banco, observaciones, estado, registrado_por)
                     VALUES (?, ?, ?, ?::timestamp, ?, ?, ?, ?, 'aplicado', ?)",
                    [$facId, $medioId, $numRecibo, $fechaPago, $valor, $ref ?: null, $banco ?: null, $obs ?: null, $usuario['id']]
                );
                $pagoId = (int) $db->fetchValue("SELECT currval('pagos_id_seq')");

                registrarAuditoria('pagos', 'INSERT', $pagoId, [], ['recibo' => $numRecibo, 'valor' => $valor]);
                $db->commit();

                $_SESSION['flash_success'] = "Pago $numRecibo aplicado exitosamente por " . formatoPeso($valor);
                header('Location: ' . APP_URL . '/modules/pagos/recibo.php?id=' . $pagoId);
                exit;

            } catch (Exception $e) {
                $db->rollback();
                $_SESSION['flash_error'] = 'Error al registrar el pago: ' . $e->getMessage();
            }
        }
    }
}

$mediosPago = $db->fetchAll("SELECT * FROM medios_pago WHERE activo = TRUE ORDER BY nombre");

// Búsqueda de facturas si no viene una específica
$facturasBusqueda = [];
$busqFac = trim($_GET['buscar'] ?? '');
if ($busqFac && !$factura) {
    $facturasBusqueda = $db->fetchAll(
        "SELECT f.id, f.numero_factura, f.total, f.saldo, f.estado, f.fecha_vencimiento,
                e.primer_nombre || ' ' || e.primer_apellido AS estudiante,
                e.codigo, p.nombre AS periodo
         FROM facturas f
         JOIN estudiantes e ON e.id = f.estudiante_id
         JOIN periodos p ON p.id = f.periodo_id
         WHERE f.estado NOT IN ('anulada','pagada')
           AND (f.numero_factura ILIKE ? OR e.codigo ILIKE ? OR e.numero_documento ILIKE ?
                OR e.primer_nombre ILIKE ? OR e.primer_apellido ILIKE ?)
         ORDER BY f.fecha_vencimiento
         LIMIT 15",
        array_fill(0, 5, "%$busqFac%")
    );
}
?>

<div style="display:grid;grid-template-columns:1fr 360px;gap:1.2rem">

<div>
<!-- Búsqueda de factura -->
<?php if (!$factura): ?>
<div class="card mb-3">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-search" style="color:var(--col-accent)"></i> Buscar Liquidación</h3></div>
    <div class="card-body">
        <form method="GET">
            <div class="flex gap-2">
                <input type="text" name="buscar" class="form-control" placeholder="Buscar por número de factura, código o nombre del estudiante..."
                       value="<?= e($busqFac) ?>">
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Buscar</button>
            </div>
        </form>

        <?php if ($facturasBusqueda): ?>
        <div style="margin-top:1rem">
            <table>
                <thead>
                    <tr><th>Factura</th><th>Estudiante</th><th>Período</th><th class="text-right">Saldo</th><th>Vence</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($facturasBusqueda as $fb): ?>
                    <tr>
                        <td><strong><?= e($fb['numero_factura']) ?></strong></td>
                        <td><?= e($fb['estudiante']) ?><br><small style="color:var(--col-muted)"><?= e($fb['codigo']) ?></small></td>
                        <td><small><?= e($fb['periodo']) ?></small></td>
                        <td class="text-right font-bold" style="color:var(--col-danger)"><?= formatoPeso($fb['saldo']) ?></td>
                        <td>
                            <?php $dias = diasVencimiento($fb['fecha_vencimiento']); ?>
                            <small <?= $dias < 0 ? 'style="color:var(--col-danger)"' : '' ?>><?= formatoFecha($fb['fecha_vencimiento']) ?></small>
                        </td>
                        <td>
                            <a href="?factura_id=<?= $fb['id'] ?>" class="btn btn-accent btn-sm">
                                <i class="fas fa-hand-holding-dollar"></i> Pagar
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php elseif ($busqFac): ?>
        <p class="text-muted" style="margin-top:1rem;text-align:center">No se encontraron liquidaciones pendientes</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Formulario de pago -->
<?php if ($factura): ?>
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-money-bill-wave" style="color:var(--col-success)"></i> Registrar Pago</h3>
        <span><?= estadoBadge($factura['estado']) ?></span>
    </div>
    <div class="card-body">
        <!-- Resumen factura -->
        <div style="background:rgba(26,58,92,.05);border-radius:var(--radius-md);padding:1rem;margin-bottom:1.4rem">
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.5rem;font-size:.85rem">
                <div><span style="color:var(--col-muted)">Factura</span><br><strong><?= e($factura['numero_factura']) ?></strong></div>
                <div><span style="color:var(--col-muted)">Estudiante</span><br><strong><?= e($factura['estudiante_nombre']) ?></strong></div>
                <div><span style="color:var(--col-muted)">Período</span><br><strong><?= e($factura['periodo_nombre']) ?></strong></div>
                <div><span style="color:var(--col-muted)">Total Factura</span><br><strong><?= formatoPeso($factura['total']) ?></strong></div>
                <div><span style="color:var(--col-muted)">Pagado</span><br><strong style="color:var(--col-success)"><?= formatoPeso($factura['total'] - $factura['saldo']) ?></strong></div>
                <div><span style="color:var(--col-muted)">Saldo Pendiente</span><br><strong style="color:var(--col-danger);font-size:1.1rem"><?= formatoPeso($factura['saldo']) ?></strong></div>
            </div>
        </div>

        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="factura_id" value="<?= $factura['id'] ?>">

            <div class="form-row cols-2">
                <div class="form-group">
                    <label>Valor del Pago *</label>
                    <input type="text" name="valor" class="form-control input-currency" required
                           placeholder="0"
                           data-max="<?= $factura['saldo'] ?>"
                           id="valorPago">
                    <small style="color:var(--col-muted)">Saldo máximo: <?= formatoPeso($factura['saldo']) ?></small>
                </div>
                <div class="form-group">
                    <label>Fecha y Hora del Pago *</label>
                    <input type="datetime-local" name="fecha_pago" class="form-control" required
                           value="<?= date('Y-m-d\TH:i') ?>">
                </div>
            </div>

            <div class="form-row cols-2">
                <div class="form-group">
                    <label>Medio de Pago *</label>
                    <select name="medio_pago_id" class="form-select" required id="selectMedio">
                        <option value="">Seleccione...</option>
                        <?php foreach ($mediosPago as $mp): ?>
                        <option value="<?= $mp['id'] ?>" data-ref="<?= $mp['requiere_referencia'] ? '1' : '0' ?>">
                            <?= e($mp['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="grupoReferencia">
                    <label>Referencia Bancaria</label>
                    <input type="text" name="referencia_bancaria" class="form-control" placeholder="N° de transacción / referencia">
                </div>
            </div>

            <div class="form-row cols-2">
                <div class="form-group" id="grupoBanco">
                    <label>Banco</label>
                    <select name="banco" class="form-select">
                        <option value="">Seleccione banco...</option>
                        <?php foreach (['Bancolombia','Davivienda','BBVA','Banco de Bogotá','Banco Popular','Banco Agrario','Nequi','Daviplata','Otro'] as $b): ?>
                        <option value="<?= $b ?>"><?= $b ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Observaciones</label>
                    <input type="text" name="observaciones" class="form-control" placeholder="Observaciones del pago">
                </div>
            </div>

            <!-- Botón pago total -->
            <div style="margin-bottom:1rem">
                <button type="button" class="btn btn-outline btn-sm" onclick="
                    document.getElementById('valorPago').dataset.value = <?= $factura['saldo'] ?>;
                    document.getElementById('valorPago').value = new Intl.NumberFormat('es-CO').format(<?= $factura['saldo'] ?>);
                ">
                    <i class="fas fa-check-double"></i> Aplicar pago total (<?= formatoPeso($factura['saldo']) ?>)
                </button>
            </div>

            <div class="flex gap-2">
                <button type="submit" class="btn btn-success btn-lg">
                    <i class="fas fa-check-circle"></i> Aplicar Pago
                </button>
                <a href="<?= APP_URL ?>/modules/facturas/ver.php?id=<?= $factura['id'] ?>" class="btn btn-outline btn-lg">Cancelar</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
</div>

<!-- Sidebar ayuda -->
<div>
    <div class="card">
        <div class="card-header"><h3 class="card-title">Medios de Pago</h3></div>
        <div class="card-body" style="font-size:.83rem">
            <?php foreach ($mediosPago as $mp): ?>
            <div style="padding:.4rem 0;border-bottom:1px solid var(--col-border);display:flex;align-items:center;gap:.5rem">
                <i class="fas fa-<?= $mp['codigo'] === 'EFECTIVO' ? 'money-bill' : ($mp['codigo'] === 'PSE' ? 'globe' : 'university') ?>" style="color:var(--col-accent);width:18px"></i>
                <?= e($mp['nombre']) ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
</div>

<script>
document.getElementById('selectMedio')?.addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    const req = opt.dataset.ref === '1';
    document.getElementById('grupoReferencia').style.display = req ? '' : 'none';
    document.getElementById('grupoBanco').style.display = req ? '' : 'none';
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
