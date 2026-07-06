<?php
// modules/facturas/ver.php
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();

$db = Database::getInstance();
$id = (int)($_GET['id'] ?? 0);

$factura = $db->fetchOne(
    "SELECT f.*, p.nombre AS periodo_nombre, p.codigo AS periodo_codigo, p.fecha_vencimiento_pago,
            e.primer_nombre || ' ' || e.primer_apellido AS estudiante_nombre,
            e.codigo AS estudiante_codigo, e.numero_documento, e.tipo_documento,
            e.email, e.celular, e.estrato,
            pr.nombre AS programa, pr.facultad, pr.nivel
     FROM facturas f
     JOIN periodos p ON p.id = f.periodo_id
     JOIN estudiantes e ON e.id = f.estudiante_id
     JOIN programas pr ON pr.id = e.programa_id
     WHERE f.id = ?",
    [$id]
);

if (!$factura) {
    $_SESSION['flash_error'] = 'Liquidación no encontrada.';
    header('Location: ' . APP_URL . '/modules/facturas/lista.php');
    exit;
}

$tituloPagina    = 'Liquidación ' . $factura['numero_factura'];
$subtituloPagina = $factura['estudiante_nombre'] . ' · ' . $factura['periodo_nombre'];

$items  = $db->fetchAll("SELECT fi.*, cc.codigo AS concepto_codigo FROM factura_items fi JOIN conceptos_cobro cc ON cc.id = fi.concepto_id WHERE fi.factura_id = ? ORDER BY fi.es_descuento, fi.id", [$id]);
$pagos  = $db->fetchAll("SELECT pg.*, mp.nombre AS medio_pago, u.nombre || ' ' || u.apellido AS cajero FROM pagos pg JOIN medios_pago mp ON mp.id = pg.medio_pago_id JOIN usuarios u ON u.id = pg.registrado_por WHERE pg.factura_id = ? ORDER BY pg.fecha_pago", [$id]);

// Anulación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['anular'])) {
    requireRol(['admin','financiero']);
    validarCSRF();
    $motivo = trim($_POST['motivo_anulacion'] ?? '');
    if (!$motivo) {
        $_SESSION['flash_error'] = 'Debe indicar el motivo de anulación.';
    } elseif ($factura['estado'] === 'anulada') {
        $_SESSION['flash_error'] = 'Esta factura ya está anulada.';
    } elseif ($factura['saldo'] < $factura['total']) {
        $_SESSION['flash_error'] = 'No se puede anular una factura con pagos aplicados.';
    } else {
        $db->query("UPDATE facturas SET estado='anulada', anulada_por=?, fecha_anulacion=NOW(), motivo_anulacion=? WHERE id=?",
            [$usuario['id'], $motivo, $id]);
        registrarAuditoria('facturas','UPDATE',$id,['estado'=>$factura['estado']],['estado'=>'anulada','motivo'=>$motivo]);
        $_SESSION['flash_success'] = 'Liquidación anulada correctamente.';
        header('Location: ' . APP_URL . '/modules/facturas/ver.php?id=' . $id);
        exit;
    }
}

require_once __DIR__ . '/../../includes/header.php';
$imprimir = isset($_GET['imprimir']);
?>

<?php if (!$imprimir): ?>
<div class="flex gap-2 mb-4">
    <a href="<?= APP_URL ?>/modules/facturas/lista.php" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Volver</a>
    <a href="?id=<?= $id ?>&imprimir=1" target="_blank" class="btn btn-outline btn-sm"><i class="fas fa-print"></i> Imprimir</a>
    <?php if (!in_array($factura['estado'], ['anulada','pagada']) && in_array($usuario['rol'], ['admin','financiero','cajero'])): ?>
    <a href="<?= APP_URL ?>/modules/pagos/registrar.php?factura_id=<?= $id ?>" class="btn btn-accent btn-sm">
        <i class="fas fa-dollar-sign"></i> Registrar Pago
    </a>
    <?php endif; ?>
    <?php if (!in_array($factura['estado'], ['anulada','pagada']) && in_array($usuario['rol'], ['admin','financiero'])): ?>
    <button data-modal="modalAnular" class="btn btn-danger btn-sm"><i class="fas fa-ban"></i> Anular</button>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Factura imprimible -->
<div class="card" id="facturaDoc">
    <div class="card-body" style="padding:2rem">

        <!-- Encabezado -->
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1.5rem;padding-bottom:1rem;border-bottom:2px solid var(--col-primary)">
            <div>
                <div style="font-family:var(--font-display);font-size:1.6rem;color:var(--col-primary)">Universidad</div>
                <div style="font-size:.85rem;color:var(--col-muted)">NIT: 890.000.000-1 · www.universidad.edu.co</div>
                <div style="font-size:.8rem;color:var(--col-muted)">Departamento de Gestión Financiera</div>
            </div>
            <div style="text-align:right">
                <div style="font-size:.75rem;color:var(--col-muted);text-transform:uppercase;letter-spacing:.05em">Liquidación de Matrícula</div>
                <div style="font-family:var(--font-display);font-size:1.4rem;color:var(--col-primary)"><?= e($factura['numero_factura']) ?></div>
                <div style="margin-top:.3rem"><?= estadoBadge($factura['estado']) ?></div>
            </div>
        </div>

        <!-- Datos estudiante y factura -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:2rem;margin-bottom:1.5rem">
            <div>
                <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:var(--col-muted);margin-bottom:.5rem">Datos del Estudiante</div>
                <table style="font-size:.85rem;width:100%">
                    <tr><td style="color:var(--col-muted);padding:.2rem .5rem .2rem 0;width:40%">Nombre</td><td><strong><?= e($factura['estudiante_nombre']) ?></strong></td></tr>
                    <tr><td style="color:var(--col-muted);padding:.2rem .5rem .2rem 0">Código</td><td><?= e($factura['estudiante_codigo']) ?></td></tr>
                    <tr><td style="color:var(--col-muted);padding:.2rem .5rem .2rem 0">Documento</td><td><?= e($factura['tipo_documento'] . ' ' . $factura['numero_documento']) ?></td></tr>
                    <tr><td style="color:var(--col-muted);padding:.2rem .5rem .2rem 0">Programa</td><td><?= e($factura['programa']) ?></td></tr>
                    <tr><td style="color:var(--col-muted);padding:.2rem .5rem .2rem 0">Facultad</td><td><?= e($factura['facultad']) ?></td></tr>
                    <tr><td style="color:var(--col-muted);padding:.2rem .5rem .2rem 0">Estrato</td><td><?= $factura['estrato'] ?? '–' ?></td></tr>
                </table>
            </div>
            <div>
                <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:var(--col-muted);margin-bottom:.5rem">Datos de la Liquidación</div>
                <table style="font-size:.85rem;width:100%">
                    <tr><td style="color:var(--col-muted);padding:.2rem .5rem .2rem 0;width:45%">Período</td><td><strong><?= e($factura['periodo_nombre']) ?></strong></td></tr>
                    <tr><td style="color:var(--col-muted);padding:.2rem .5rem .2rem 0">Fecha Emisión</td><td><?= formatoFecha($factura['fecha_emision'], 'largo') ?></td></tr>
                    <tr><td style="color:var(--col-muted);padding:.2rem .5rem .2rem 0">Fecha Vencimiento</td><td style="color:var(--col-warning);font-weight:600"><?= formatoFecha($factura['fecha_vencimiento'], 'largo') ?></td></tr>
                    <tr><td style="color:var(--col-muted);padding:.2rem .5rem .2rem 0">SMMLV Vigente</td><td><?= formatoPeso(SMMLV_VIGENTE) ?></td></tr>
                </table>
            </div>
        </div>

        <!-- Ítems -->
        <div style="margin-bottom:1.5rem">
            <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:var(--col-muted);margin-bottom:.5rem">Detalle de Conceptos</div>
            <table style="width:100%;border-collapse:collapse">
                <thead>
                    <tr style="background:rgba(26,58,92,.05)">
                        <th style="padding:.6rem .8rem;text-align:left;font-size:.78rem;font-weight:600;color:var(--col-primary);border-bottom:1.5px solid var(--col-primary)">Concepto</th>
                        <th style="padding:.6rem .8rem;text-align:center;font-size:.78rem;font-weight:600;color:var(--col-primary);border-bottom:1.5px solid var(--col-primary)">Tipo</th>
                        <th style="padding:.6rem .8rem;text-align:right;font-size:.78rem;font-weight:600;color:var(--col-primary);border-bottom:1.5px solid var(--col-primary)">Valor</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td style="padding:.5rem .8rem;border-bottom:1px solid #eee;font-size:.87rem"><?= e($item['descripcion']) ?></td>
                        <td style="padding:.5rem .8rem;border-bottom:1px solid #eee;text-align:center">
                            <?php if ($item['es_descuento']): ?>
                            <span style="color:var(--col-success);font-size:.75rem;font-weight:600">DESCUENTO</span>
                            <?php else: ?>
                            <span style="color:var(--col-muted);font-size:.75rem">COBRO</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:.5rem .8rem;border-bottom:1px solid #eee;text-align:right;font-weight:600;color:<?= $item['es_descuento'] ? 'var(--col-success)' : 'var(--col-text)' ?>">
                            <?= $item['es_descuento'] ? '–' : '' ?><?= formatoPeso(abs($item['valor_total'])) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2" style="padding:.5rem .8rem;text-align:right;font-size:.85rem;color:var(--col-muted)">Subtotal:</td>
                        <td style="padding:.5rem .8rem;text-align:right;font-weight:600"><?= formatoPeso($factura['subtotal']) ?></td>
                    </tr>
                    <?php if ($factura['descuentos'] > 0): ?>
                    <tr>
                        <td colspan="2" style="padding:.5rem .8rem;text-align:right;font-size:.85rem;color:var(--col-success)">Descuentos:</td>
                        <td style="padding:.5rem .8rem;text-align:right;font-weight:600;color:var(--col-success)">–<?= formatoPeso($factura['descuentos']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr style="background:rgba(26,58,92,.05)">
                        <td colspan="2" style="padding:.8rem;text-align:right;font-family:var(--font-display);font-size:1rem;color:var(--col-primary)">TOTAL A PAGAR:</td>
                        <td style="padding:.8rem;text-align:right;font-family:var(--font-display);font-size:1.2rem;color:var(--col-primary);font-weight:700"><?= formatoPeso($factura['total']) ?></td>
                    </tr>
                    <?php if ($factura['saldo'] > 0 && $factura['saldo'] < $factura['total']): ?>
                    <tr>
                        <td colspan="2" style="padding:.4rem .8rem;text-align:right;font-size:.85rem;color:var(--col-success)">Pagado:</td>
                        <td style="padding:.4rem .8rem;text-align:right;font-weight:600;color:var(--col-success)"><?= formatoPeso($factura['total'] - $factura['saldo']) ?></td>
                    </tr>
                    <tr style="background:rgba(220,38,38,.05)">
                        <td colspan="2" style="padding:.5rem .8rem;text-align:right;font-weight:700;color:var(--col-danger)">SALDO PENDIENTE:</td>
                        <td style="padding:.5rem .8rem;text-align:right;font-family:var(--font-display);font-size:1.1rem;font-weight:700;color:var(--col-danger)"><?= formatoPeso($factura['saldo']) ?></td>
                    </tr>
                    <?php endif; ?>
                </tfoot>
            </table>
        </div>

        <!-- Pagos aplicados -->
        <?php if ($pagos): ?>
        <div style="margin-bottom:1.5rem">
            <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:var(--col-muted);margin-bottom:.5rem">Pagos Registrados</div>
            <table style="width:100%;border-collapse:collapse">
                <thead>
                    <tr style="background:rgba(22,163,74,.05)">
                        <th style="padding:.5rem .8rem;text-align:left;font-size:.78rem;font-weight:600;color:var(--col-success);border-bottom:1.5px solid var(--col-success)">Recibo</th>
                        <th style="padding:.5rem .8rem;text-align:left;font-size:.78rem;font-weight:600;color:var(--col-success);border-bottom:1.5px solid var(--col-success)">Fecha</th>
                        <th style="padding:.5rem .8rem;text-align:left;font-size:.78rem;font-weight:600;color:var(--col-success);border-bottom:1.5px solid var(--col-success)">Medio</th>
                        <th style="padding:.5rem .8rem;text-align:left;font-size:.78rem;font-weight:600;color:var(--col-success);border-bottom:1.5px solid var(--col-success)">Referencia</th>
                        <th style="padding:.5rem .8rem;text-align:right;font-size:.78rem;font-weight:600;color:var(--col-success);border-bottom:1.5px solid var(--col-success)">Valor</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pagos as $pg): ?>
                    <tr>
                        <td style="padding:.4rem .8rem;font-size:.83rem;border-bottom:1px solid #eee"><?= e($pg['numero_recibo']) ?></td>
                        <td style="padding:.4rem .8rem;font-size:.83rem;border-bottom:1px solid #eee"><?= formatoFecha($pg['fecha_pago'], 'd/m/Y H:i') ?></td>
                        <td style="padding:.4rem .8rem;font-size:.83rem;border-bottom:1px solid #eee"><?= e($pg['medio_pago']) ?></td>
                        <td style="padding:.4rem .8rem;font-size:.83rem;border-bottom:1px solid #eee"><?= e($pg['referencia_bancaria'] ?? '–') ?></td>
                        <td style="padding:.4rem .8rem;font-size:.83rem;border-bottom:1px solid #eee;text-align:right;color:var(--col-success);font-weight:600"><?= formatoPeso($pg['valor']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Observaciones y pie -->
        <?php if ($factura['observaciones']): ?>
        <div style="margin-bottom:1rem;font-size:.83rem;color:var(--col-muted)">
            <strong>Observaciones:</strong> <?= e($factura['observaciones']) ?>
        </div>
        <?php endif; ?>

        <div style="border-top:1px dashed var(--col-border);padding-top:1rem;font-size:.75rem;color:var(--col-muted);text-align:center">
            Este documento es una liquidación de matrícula con validez para pago. · <?= APP_NAME ?> · Generado: <?= date('d/m/Y H:i') ?>
        </div>
    </div>
</div>

<!-- Modal anular -->
<?php if (in_array($usuario['rol'], ['admin','financiero'])): ?>
<div class="modal-overlay" id="modalAnular">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-ban" style="color:var(--col-danger)"></i> Anular Liquidación</h3>
            <button class="modal-close"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="anular" value="1">
            <div class="modal-body">
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    Esta acción <strong>no se puede deshacer</strong>. Solo es posible anular facturas sin pagos aplicados.
                </div>
                <div class="form-group">
                    <label>Motivo de Anulación *</label>
                    <textarea name="motivo_anulacion" class="form-control" rows="3" required placeholder="Describa el motivo..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline modal-close">Cancelar</button>
                <button type="submit" class="btn btn-danger"><i class="fas fa-ban"></i> Confirmar Anulación</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($imprimir): ?>
<script>window.onload = () => window.print();</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
