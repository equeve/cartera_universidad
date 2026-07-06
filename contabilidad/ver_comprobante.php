<?php
// modules/contabilidad/ver_comprobante.php
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();

$db = Database::getInstance();
$id = (int)($_GET['id'] ?? 0);

$comp = $db->fetchOne(
    "SELECT c.*,
            u1.nombre||' '||u1.apellido AS elaborado_nombre,
            u1.email AS elaborado_email,
            u2.nombre||' '||u2.apellido AS aprobado_nombre,
            u3.nombre||' '||u3.apellido AS anulado_nombre
     FROM comprobantes c
     LEFT JOIN usuarios u1 ON u1.id = c.elaborado_por
     LEFT JOIN usuarios u2 ON u2.id = c.aprobado_por
     LEFT JOIN usuarios u3 ON u3.id = c.anulado_por
     WHERE c.id = ?",
    [$id]
);

if (!$comp) {
    $_SESSION['flash_error'] = 'Comprobante no encontrado.';
    header('Location: ' . APP_URL . '/modules/contabilidad/comprobantes.php');
    exit;
}

$tituloPagina    = $comp['numero'];
$subtituloPagina = 'Comprobante contable — ' . $comp['periodo_contable'];
require_once __DIR__ . '/../../includes/header.php';

$movimientos = $db->fetchAll(
    "SELECT m.*, c.nombre AS cuenta_nombre, c.naturaleza, c.clase,
            COALESCE(t.razon_social, t.nombres||' '||COALESCE(t.apellidos,'')) AS tercero_nombre,
            t.numero_documento AS tercero_doc,
            cc.nombre AS centro_nombre, cc.codigo AS centro_codigo
     FROM movimientos_contables m
     JOIN puc_cuentas c ON c.codigo = m.cuenta_codigo
     LEFT JOIN terceros t ON t.id = m.tercero_id
     LEFT JOIN centros_costo cc ON cc.id = m.centro_costo_id
     WHERE m.comprobante_id = ?
     ORDER BY m.linea",
    [$id]
);

$cuadra = abs($comp['total_debitos'] - $comp['total_creditos']) < 0.01;

$tiposLabel = [
    'comprobante_ingreso' => 'Comprobante de Ingreso',
    'comprobante_egreso'  => 'Comprobante de Egreso',
    'nota_contable'       => 'Nota Contable',
    'causacion'           => 'Causación',
    'ajuste'              => 'Ajuste',
    'apertura'            => 'Apertura',
    'cierre'              => 'Cierre',
];

// ── Acciones POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRol(['admin', 'financiero']);
    validarCSRF();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'contabilizar' && $comp['estado'] === 'borrador') {
        if (!$cuadra) {
            $_SESSION['flash_error'] = 'No se puede contabilizar: la partida no cuadra.';
        } else {
            $db->query(
                "UPDATE comprobantes SET estado='contabilizado', aprobado_por=?, fecha_aprobacion=NOW() WHERE id=?",
                [$usuario['id'], $id]
            );
            registrarAuditoria('comprobantes', 'UPDATE', $id, ['estado'=>'borrador'], ['estado'=>'contabilizado']);
            $_SESSION['flash_success'] = "Comprobante {$comp['numero']} contabilizado.";
        }
        header('Location: ' . APP_URL . '/modules/contabilidad/ver_comprobante.php?id=' . $id);
        exit;
    }

    if ($accion === 'anular' && $comp['estado'] !== 'anulado') {
        requireRol(['admin']);
        $motivo = trim($_POST['motivo_anulacion'] ?? '');
        if (!$motivo) {
            $_SESSION['flash_error'] = 'Debe indicar el motivo de anulación.';
        } else {
            $db->query(
                "UPDATE comprobantes SET estado='anulado', anulado_por=?, fecha_anulacion=NOW(), motivo_anulacion=? WHERE id=?",
                [$usuario['id'], $motivo, $id]
            );
            registrarAuditoria('comprobantes', 'UPDATE', $id, ['estado'=>$comp['estado']], ['estado'=>'anulado','motivo'=>$motivo]);
            $_SESSION['flash_success'] = "Comprobante {$comp['numero']} anulado.";
        }
        header('Location: ' . APP_URL . '/modules/contabilidad/ver_comprobante.php?id=' . $id);
        exit;
    }
}

$imprimir = isset($_GET['imprimir']);
?>

<?php if (!$imprimir): ?>
<div class="flex gap-2 mb-4 no-print">
    <a href="<?= APP_URL ?>/modules/contabilidad/comprobantes.php" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Volver</a>
    <a href="?id=<?= $id ?>&imprimir=1" target="_blank" class="btn btn-outline btn-sm"><i class="fas fa-print"></i> Imprimir</a>
    <?php if ($comp['estado'] === 'borrador'): ?>
        <a href="<?= APP_URL ?>/modules/contabilidad/editar_comprobante.php?id=<?= $id ?>" class="btn btn-outline btn-sm"><i class="fas fa-edit"></i> Editar</a>
        <?php if (in_array($usuario['rol'], ['admin','financiero'])): ?>
        <form method="POST" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="accion" value="contabilizar">
            <button type="submit" class="btn btn-primary btn-sm"
                    <?= !$cuadra ? 'disabled title="La partida no cuadra"' : '' ?>>
                <i class="fas fa-check-double"></i> Contabilizar
            </button>
        </form>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($comp['estado'] !== 'anulado' && $usuario['rol'] === 'admin'): ?>
    <button data-modal="modalAnular" class="btn btn-danger btn-sm"><i class="fas fa-ban"></i> Anular</button>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$cuadra && $comp['estado'] !== 'anulado'): ?>
<div class="alert alert-danger no-print"><i class="fas fa-exclamation-triangle"></i> <strong>¡Partida no cuadra!</strong> Diferencia: <?= formatoPeso(abs($comp['total_debitos'] - $comp['total_creditos'])) ?>. Este comprobante no puede contabilizarse.</div>
<?php endif; ?>

<!-- ── Documento comprobante ───────────────────────────────── -->
<div class="card" id="docComprobante">
    <div class="card-body" style="padding:2rem">

        <!-- Encabezado -->
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1.5rem;padding-bottom:1rem;border-bottom:2px solid var(--col-primary)">
            <div>
                <div style="font-family:var(--font-display);font-size:1.5rem;color:var(--col-primary)">Universidad</div>
                <div style="font-size:.8rem;color:var(--col-muted)">Departamento Financiero y Contable</div>
                <div style="font-size:.78rem;color:var(--col-muted)">PUC — Decreto 2650/1993</div>
            </div>
            <div style="text-align:right">
                <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:var(--col-muted)"><?= $tiposLabel[$comp['tipo']] ?? $comp['tipo'] ?></div>
                <div style="font-family:var(--font-display);font-size:1.6rem;color:var(--col-primary)"><?= e($comp['numero']) ?></div>
                <div style="margin-top:.3rem"><?= estadoBadge($comp['estado']) ?></div>
                <?php if (!$cuadra): ?><div style="color:var(--col-danger);font-size:.75rem;margin-top:.2rem">⚠ No cuadra</div><?php endif; ?>
            </div>
        </div>

        <!-- Metadatos -->
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-bottom:1.5rem">
            <?php
            $meta = [
                ['Fecha',            formatoFecha($comp['fecha'], 'largo')],
                ['Período Contable', $comp['periodo_contable']],
                ['Tipo',             $tiposLabel[$comp['tipo']] ?? $comp['tipo']],
                ['Elaboró',          $comp['elaborado_nombre']  ?? '–'],
                ['Aprobó/Contabilizó', $comp['aprobado_nombre'] ? $comp['aprobado_nombre'] . ' · ' . formatoFecha($comp['fecha_aprobacion'] ?? '', 'd/m/Y H:i') : '–'],
                ['Estado',           ucfirst($comp['estado'])],
            ];
            foreach ($meta as [$label, $val]): ?>
            <div style="font-size:.83rem">
                <div style="color:var(--col-muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.2rem"><?= $label ?></div>
                <div style="font-weight:500"><?= $val ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Descripción -->
        <div style="background:rgba(26,58,92,.05);border-radius:var(--radius-md);padding:.8rem 1rem;margin-bottom:1.5rem;font-size:.88rem">
            <strong>Descripción:</strong> <?= e($comp['descripcion']) ?>
            <?php if ($comp['observaciones']): ?>
            <br><span style="color:var(--col-muted)"><strong>Observaciones:</strong> <?= e($comp['observaciones']) ?></span>
            <?php endif; ?>
            <?php if ($comp['estado'] === 'anulado'): ?>
            <br><span style="color:var(--col-danger)"><strong>Motivo anulación:</strong> <?= e($comp['motivo_anulacion']) ?></span>
            <?php endif; ?>
        </div>

        <!-- Tabla de movimientos -->
        <div style="margin-bottom:1.5rem">
            <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:var(--col-muted);margin-bottom:.5rem">Detalle de Movimientos</div>
            <table style="width:100%;border-collapse:collapse">
                <thead>
                    <tr style="background:rgba(26,58,92,.06)">
                        <th style="padding:.55rem .7rem;text-align:center;font-size:.72rem;font-weight:700;color:var(--col-primary);border-bottom:2px solid var(--col-primary);width:36px">#</th>
                        <th style="padding:.55rem .7rem;text-align:left;font-size:.72rem;font-weight:700;color:var(--col-primary);border-bottom:2px solid var(--col-primary);width:110px">Código</th>
                        <th style="padding:.55rem .7rem;text-align:left;font-size:.72rem;font-weight:700;color:var(--col-primary);border-bottom:2px solid var(--col-primary)">Nombre Cuenta</th>
                        <th style="padding:.55rem .7rem;text-align:left;font-size:.72nm;font-weight:700;color:var(--col-primary);border-bottom:2px solid var(--col-primary)">Descripción</th>
                        <th style="padding:.55rem .7rem;text-align:left;font-size:.72rem;font-weight:700;color:var(--col-primary);border-bottom:2px solid var(--col-primary)">Tercero</th>
                        <th style="padding:.55rem .7rem;text-align:left;font-size:.72rem;font-weight:700;color:var(--col-primary);border-bottom:2px solid var(--col-primary)">C. Costo</th>
                        <th style="padding:.55rem .7rem;text-align:right;font-size:.72rem;font-weight:700;color:var(--col-info);border-bottom:2px solid var(--col-primary);width:140px">DÉBITO</th>
                        <th style="padding:.55rem .7rem;text-align:right;font-size:.72rem;font-weight:700;color:var(--col-success);border-bottom:2px solid var(--col-primary);width:140px">CRÉDITO</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($movimientos as $mov):
                        $claseColors = [
                            '1'=>'rgba(26,58,92,.04)','2'=>'rgba(220,38,38,.03)',
                            '3'=>'rgba(2,132,199,.03)','4'=>'rgba(22,163,74,.03)',
                            '5'=>'rgba(217,119,6,.03)','6'=>'rgba(200,146,42,.03)',
                        ];
                        $bgMov = $claseColors[$mov['clase']] ?? 'transparent';
                    ?>
                    <tr style="border-bottom:1px solid #f0f0f0;background:<?= $bgMov ?>">
                        <td style="padding:.45rem .7rem;text-align:center;font-size:.8rem;color:var(--col-muted)"><?= $mov['linea'] ?></td>
                        <td style="padding:.45rem .7rem">
                            <code style="font-size:.88rem;font-weight:700;letter-spacing:.05em;color:var(--col-primary)"><?= e($mov['cuenta_codigo']) ?></code>
                        </td>
                        <td style="padding:.45rem .7rem;font-size:.85rem;font-weight:500"><?= e($mov['cuenta_nombre']) ?></td>
                        <td style="padding:.45rem .7rem;font-size:.83rem"><?= e($mov['descripcion']) ?></td>
                        <td style="padding:.45rem .7rem;font-size:.78rem;color:var(--col-muted)">
                            <?= $mov['tercero_nombre'] ? e($mov['tercero_nombre']) . '<br><small>' . e($mov['tercero_doc'] ?? '') . '</small>' : '–' ?>
                        </td>
                        <td style="padding:.45rem .7rem;font-size:.78rem;color:var(--col-muted)"><?= $mov['centro_nombre'] ? e($mov['centro_codigo'] . ' – ' . $mov['centro_nombre']) : '–' ?></td>
                        <td style="padding:.45rem .7rem;text-align:right;font-weight:700;font-size:.9rem;color:<?= $mov['debito'] > 0 ? 'var(--col-info)' : 'var(--col-border)' ?>">
                            <?= $mov['debito'] > 0 ? formatoPeso($mov['debito']) : '' ?>
                        </td>
                        <td style="padding:.45rem .7rem;text-align:right;font-weight:700;font-size:.9rem;color:<?= $mov['credito'] > 0 ? 'var(--col-success)' : 'var(--col-border)' ?>">
                            <?= $mov['credito'] > 0 ? formatoPeso($mov['credito']) : '' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:rgba(26,58,92,.07);border-top:2px solid var(--col-primary)">
                        <td colspan="6" style="padding:.65rem .7rem;font-family:var(--font-display);font-size:.95rem;font-weight:700;color:var(--col-primary)">TOTALES</td>
                        <td style="padding:.65rem .7rem;text-align:right;font-family:var(--font-display);font-size:1.05rem;font-weight:700;color:var(--col-info)"><?= formatoPeso($comp['total_debitos']) ?></td>
                        <td style="padding:.65rem .7rem;text-align:right;font-family:var(--font-display);font-size:1.05rem;font-weight:700;color:var(--col-success)"><?= formatoPeso($comp['total_creditos']) ?></td>
                    </tr>
                    <?php if ($cuadra): ?>
                    <tr>
                        <td colspan="8" style="padding:.4rem .7rem;text-align:center;font-size:.78rem;color:var(--col-success)">
                            <i class="fas fa-check-circle"></i> La partida cuadra correctamente — Débitos = Créditos = <?= formatoPeso($comp['total_debitos']) ?>
                        </td>
                    </tr>
                    <?php else: ?>
                    <tr>
                        <td colspan="8" style="padding:.4rem .7rem;text-align:center;font-size:.78rem;color:var(--col-danger)">
                            <i class="fas fa-exclamation-triangle"></i> Diferencia: <?= formatoPeso(abs($comp['total_debitos'] - $comp['total_creditos'])) ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tfoot>
            </table>
        </div>

        <!-- Firmas -->
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:2rem;margin-top:2.5rem;padding-top:1rem;border-top:1px dashed var(--col-border)">
            <?php foreach ([
                ['Elaboró', $comp['elaborado_nombre'] ?? ''],
                ['Revisó / Aprobó', $comp['aprobado_nombre'] ?? ''],
                ['Contador / Revisor Fiscal', ''],
            ] as [$rol, $nombre]): ?>
            <div style="text-align:center;font-size:.78rem">
                <div style="border-top:1px solid #aaa;width:120px;margin:2rem auto .3rem"></div>
                <strong><?= $rol ?></strong><br>
                <span style="color:var(--col-muted)"><?= $nombre ?></span>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Pie -->
        <div style="text-align:center;margin-top:1.5rem;font-size:.72rem;color:var(--col-muted);border-top:1px solid var(--col-border);padding-top:.8rem">
            <?= APP_NAME ?> · PUC Decreto 2650/1993 · Generado: <?= date('d/m/Y H:i:s') ?>
        </div>
    </div>
</div>

<!-- Modal anular -->
<?php if ($comp['estado'] !== 'anulado' && $usuario['rol'] === 'admin'): ?>
<div class="modal-overlay" id="modalAnular">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-ban" style="color:var(--col-danger)"></i> Anular Comprobante</h3>
            <button class="modal-close"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="accion" value="anular">
            <div class="modal-body">
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> Esta acción <strong>anulará</strong> el comprobante <?= e($comp['numero']) ?>. Los saldos contables se revertirán.</div>
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
<style>.no-print,.sidebar,.topbar{display:none!important}.main-content{margin:0!important}.page-content{padding:0!important}</style>
<script>window.onload=()=>window.print();</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
