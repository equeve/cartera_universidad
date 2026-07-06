<?php
// modules/facturas/nueva.php
$tituloPagina    = 'Nueva Liquidación';
$subtituloPagina = 'Generar liquidación de matrícula';
require_once __DIR__ . '/../../includes/header.php';
requireRol(['admin','financiero','cajero']);

$db = Database::getInstance();

$estudiante_id = (int)($_GET['estudiante_id'] ?? 0);
$estudiante = null;

if ($estudiante_id) {
    $estudiante = $db->fetchOne(
        "SELECT e.*, p.nombre AS programa, p.nivel, p.codigo AS prog_codigo
         FROM estudiantes e
         LEFT JOIN programas p ON p.id = e.programa_id
         WHERE e.id = ?",
        [$estudiante_id]
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarCSRF();
    
    $est_id     = (int)$_POST['estudiante_id'];
    $periodo_id = (int)$_POST['periodo_id'];
    $items      = $_POST['items'] ?? [];
    $obs        = trim($_POST['observaciones'] ?? '');

    if (!$est_id || !$periodo_id || empty($items)) {
        $_SESSION['flash_error'] = 'Debe seleccionar estudiante, período e ítems.';
    } else {
        // Verificar que no exista factura para ese período
        $existe = $db->fetchValue(
            "SELECT id FROM facturas WHERE estudiante_id = ? AND periodo_id = ? AND estado != 'anulada'",
            [$est_id, $periodo_id]
        );
        
        if ($existe) {
            $_SESSION['flash_error'] = 'Ya existe una liquidación para este estudiante en el período seleccionado.';
        } else {
            try {
                $db->beginTransaction();

                $periodo = $db->fetchOne("SELECT * FROM periodos WHERE id = ?", [$periodo_id]);
                $numFactura = $db->fetchValue("SELECT generar_numero_factura(?)", [$periodo['codigo']]);

                $subtotal = 0; $descuentos = 0;
                $itemsData = [];

                foreach ($items as $item) {
                    $concepto = $db->fetchOne("SELECT * FROM conceptos_cobro WHERE id = ?", [(int)$item['concepto_id']]);
                    if (!$concepto) continue;

                    $valor = 0;
                    if ($concepto['aplica_smmlv'] && $concepto['porcentaje_smmlv']) {
                        $valor = calcularValorSMMLV(abs((float)$concepto['porcentaje_smmlv']));
                        if ($concepto['porcentaje_smmlv'] < 0) $valor = -$valor;
                    } elseif ($concepto['valor_fijo']) {
                        $valor = (float)$concepto['valor_fijo'];
                    } else {
                        $valor = (float)($item['valor_manual'] ?? 0);
                    }

                    $esDescuento = ($concepto['tipo'] === 'descuento');
                    $valorAbs = abs($valor);

                    if ($esDescuento) {
                        $descuentos += $valorAbs;
                    } else {
                        $subtotal += $valorAbs;
                    }

                    $itemsData[] = [
                        'concepto_id'   => $concepto['id'],
                        'descripcion'   => $concepto['nombre'],
                        'valor_unitario'=> $valor,
                        'valor_total'   => $valor,
                        'es_descuento'  => $esDescuento,
                    ];
                }

                $total = max(0, $subtotal - $descuentos);

                $db->query(
                    "INSERT INTO facturas (numero_factura, estudiante_id, periodo_id, fecha_emision, fecha_vencimiento, subtotal, descuentos, total, saldo, observaciones, generada_por)
                     VALUES (?, ?, ?, CURRENT_DATE, ?, ?, ?, ?, ?, ?, ?)",
                    [$numFactura, $est_id, $periodo_id, $periodo['fecha_vencimiento_pago'],
                     $subtotal, $descuentos, $total, $total, $obs, $usuario['id']]
                );
                $facturaId = (int) $db->fetchValue("SELECT currval('facturas_id_seq')");

                foreach ($itemsData as $it) {
                    $db->query(
                        "INSERT INTO factura_items (factura_id, concepto_id, descripcion, valor_unitario, valor_total, es_descuento)
                         VALUES (?, ?, ?, ?, ?, ?)",
                        [$facturaId, $it['concepto_id'], $it['descripcion'], $it['valor_unitario'], $it['valor_total'], $it['es_descuento'] ? 'true' : 'false']
                    );
                }

                registrarAuditoria('facturas', 'INSERT', $facturaId, [], ['numero' => $numFactura, 'total' => $total]);
                $db->commit();

                $_SESSION['flash_success'] = "Liquidación $numFactura generada exitosamente por " . formatoPeso($total);
                header('Location: ' . APP_URL . '/modules/facturas/ver.php?id=' . $facturaId);
                exit;

            } catch (Exception $e) {
                $db->rollback();
                $_SESSION['flash_error'] = 'Error al generar la liquidación: ' . $e->getMessage();
            }
        }
    }
}

$periodos    = $db->fetchAll("SELECT * FROM periodos ORDER BY fecha_inicio DESC");
$conceptos   = $db->fetchAll("SELECT * FROM conceptos_cobro WHERE activo = TRUE ORDER BY tipo, nombre");
$estudiantes = $db->fetchAll("SELECT e.id, e.codigo, e.primer_nombre || ' ' || e.primer_apellido AS nombre FROM estudiantes e WHERE e.estado = 'activo' ORDER BY e.primer_apellido");
?>

<div style="display:grid;grid-template-columns:1fr 380px;gap:1.2rem">

<div>
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-file-invoice-dollar" style="color:var(--col-accent)"></i> Datos de la Liquidación</h3>
    </div>
    <div class="card-body">
        <form method="POST" id="formFactura">
            <?= csrfField() ?>

            <div class="form-row cols-2">
                <div class="form-group">
                    <label>Estudiante *</label>
                    <select name="estudiante_id" class="form-select" required id="selectEstudiante">
                        <option value="">Seleccione...</option>
                        <?php foreach ($estudiantes as $est): ?>
                        <option value="<?= $est['id'] ?>" <?= ($estudiante_id == $est['id']) ? 'selected' : '' ?>>
                            [<?= e($est['codigo']) ?>] <?= e($est['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Período Académico *</label>
                    <select name="periodo_id" class="form-select" required>
                        <option value="">Seleccione...</option>
                        <?php foreach ($periodos as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $p['activo'] ? 'selected' : '' ?>>
                            <?= e($p['nombre']) ?> <?= $p['activo'] ? '(Activo)' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Conceptos de Cobro *</label>
                <div style="border:1.5px solid var(--col-border);border-radius:var(--radius-sm);overflow:hidden">
                    <?php foreach ($conceptos as $con): ?>
                    <?php
                        $valorMostrar = '';
                        if ($con['aplica_smmlv'] && $con['porcentaje_smmlv']) {
                            $v = calcularValorSMMLV(abs((float)$con['porcentaje_smmlv']));
                            $valorMostrar = formatoPeso($con['porcentaje_smmlv'] < 0 ? -$v : $v);
                        } elseif ($con['valor_fijo']) {
                            $valorMostrar = formatoPeso($con['valor_fijo']);
                        } else {
                            $valorMostrar = 'Variable';
                        }
                        $isDescuento = $con['tipo'] === 'descuento';
                    ?>
                    <label style="display:flex;align-items:center;gap:.8rem;padding:.65rem 1rem;border-bottom:1px solid var(--col-border);cursor:pointer;transition:background .15s" onmouseover="this.style.background='rgba(26,58,92,.03)'" onmouseout="this.style.background=''">
                        <input type="checkbox" name="items[<?= $con['id'] ?>][concepto_id]" value="<?= $con['id'] ?>">
                        <div style="flex:1">
                            <span style="font-size:.88rem;font-weight:500"><?= e($con['nombre']) ?></span>
                            <span class="badge <?= $isDescuento ? 'badge-danger' : 'badge-primary' ?>" style="margin-left:.4rem;font-size:.65rem"><?= ucfirst($con['tipo']) ?></span>
                        </div>
                        <span style="font-size:.88rem;font-weight:700;color:<?= $isDescuento ? 'var(--col-success)' : 'var(--col-text)' ?>">
                            <?= $isDescuento ? '-' : '' ?><?= $valorMostrar ?>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group">
                <label>Observaciones</label>
                <textarea name="observaciones" class="form-control" rows="2" placeholder="Observaciones opcionales..."></textarea>
            </div>

            <div class="flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fas fa-save"></i> Generar Liquidación
                </button>
                <a href="<?= APP_URL ?>/modules/facturas/lista.php" class="btn btn-outline btn-lg">Cancelar</a>
            </div>
        </form>
    </div>
</div>
</div>

<!-- Sidebar info -->
<div>
    <?php if ($estudiante): ?>
    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Datos del Estudiante</h3></div>
        <div class="card-body">
            <div style="text-align:center;margin-bottom:1rem">
                <div style="width:56px;height:56px;border-radius:50%;background:var(--col-primary);color:#fff;display:grid;place-items:center;font-size:1.4rem;font-family:var(--font-display);margin:0 auto .5rem">
                    <?= strtoupper(substr($estudiante['primer_nombre'], 0, 1)) ?>
                </div>
                <strong><?= e($estudiante['primer_nombre'] . ' ' . $estudiante['primer_apellido']) ?></strong><br>
                <small style="color:var(--col-muted)"><?= e($estudiante['codigo']) ?></small>
            </div>
            <div style="font-size:.83rem">
                <div style="display:flex;justify-content:space-between;padding:.3rem 0;border-bottom:1px solid var(--col-border)">
                    <span style="color:var(--col-muted)">Programa</span>
                    <span><?= e($estudiante['programa']) ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:.3rem 0;border-bottom:1px solid var(--col-border)">
                    <span style="color:var(--col-muted)">Semestre</span>
                    <span><?= $estudiante['semestre_actual'] ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:.3rem 0;border-bottom:1px solid var(--col-border)">
                    <span style="color:var(--col-muted)">Estrato</span>
                    <span><?= $estudiante['estrato'] ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:.3rem 0">
                    <span style="color:var(--col-muted)">Estado</span>
                    <span><?= estadoBadge($estudiante['estado']) ?></span>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h3 class="card-title">Información</h3></div>
        <div class="card-body" style="font-size:.83rem">
            <p style="color:var(--col-muted);margin-bottom:.7rem">
                <i class="fas fa-info-circle" style="color:var(--col-info)"></i>
                Los valores marcados con <strong>SMMLV</strong> se calculan automáticamente sobre el salario mínimo vigente.
            </p>
            <div style="background:rgba(26,58,92,.05);border-radius:var(--radius-sm);padding:.8rem">
                <strong>SMMLV 2025</strong><br>
                <span style="font-size:1.1rem;font-weight:700;color:var(--col-primary)"><?= formatoPeso(SMMLV_VIGENTE) ?></span>
            </div>
        </div>
    </div>
</div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
