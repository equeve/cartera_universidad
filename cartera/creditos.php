<?php
// modules/cartera/creditos.php
$tituloPagina    = 'Créditos Estudiantiles';
$subtituloPagina = 'Gestión de créditos con tabla de amortización';
require_once __DIR__ . '/../../includes/header.php';
requireRol(['admin', 'financiero', 'cajero']);

$db = Database::getInstance();

// ── Acciones POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarCSRF();
    $accion = $_POST['accion'] ?? '';

    // Crear crédito
    if ($accion === 'crear_credito') {
        $est_id       = (int)($_POST['estudiante_id']   ?? 0);
        $tipo         = $_POST['tipo']                  ?? 'interno';
        $tipo_int     = $_POST['tipo_credito_interno']  ?? 'matricula';
        $entidad      = trim($_POST['entidad']          ?? '');
        $monto        = (float) str_replace(['.', ','], ['', '.'], $_POST['monto_aprobado'] ?? '0');
        $tasa         = (float) str_replace(',', '.', $_POST['tasa_interes']     ?? '0');
        $tasa_mora    = (float) str_replace(',', '.', $_POST['tasa_mora_mensual'] ?? '0');
        $plazo        = (int)  ($_POST['plazo_meses']   ?? 0);
        $fecha_ini    = $_POST['fecha_inicio']           ?? date('Y-m-d');
        $obs          = trim($_POST['observaciones']    ?? '');

        if (!$est_id || $monto <= 0 || $plazo < 1) {
            $_SESSION['flash_error'] = 'Estudiante, monto y plazo son obligatorios.';
        } else {
            try {
                $db->beginTransaction();
                $db->query(
                    "INSERT INTO creditos_estudiantiles
                        (estudiante_id, tipo, tipo_credito_interno, entidad, monto_aprobado,
                         tasa_interes, tasa_mora_mensual, plazo_meses, fecha_inicio,
                         fecha_vencimiento, estado, observaciones, aprobado_por)
                     VALUES (?,?,?,?,?,?,?,?,?,
                             (?::date + (? || ' months')::interval)::date,
                             'aprobado',?,?)",
                    [$est_id, $tipo, $tipo_int, $entidad ?: null, $monto,
                     $tasa / 100, $tasa_mora / 100, $plazo, $fecha_ini,
                     $fecha_ini, $plazo, $obs ?: null, $usuario['id']]
                );
                $credId = (int) $db->fetchValue("SELECT currval('creditos_estudiantiles_id_seq')");

                // Generar cuotas con sistema francés
                $cuotas = $db->fetchAll(
                    "SELECT * FROM fn_tabla_amortizacion(?, ?, ?, ?)",
                    [$monto, $tasa / 100, $plazo, $tasa_mora / 100]
                );
                $fecha = new DateTime($fecha_ini);
                $fecha->modify('+1 month');

                foreach ($cuotas as $cu) {
                    $db->query(
                        "INSERT INTO cuotas_acuerdo
                            (acuerdo_id, numero_cuota, fecha_vencimiento, valor,
                             valor_capital, valor_interes, tasa_interes_aplicada,
                             valor_total_pagar, saldo_capital)
                         VALUES (0,?,?,?,?,?,?,?,?)",
                        [$cu['cuota_no'], $fecha->format('Y-m-d'), $cu['valor_cuota'],
                         $cu['valor_capital'], $cu['valor_interes'],
                         $tasa / 100, $cu['valor_cuota'], $cu['saldo_final']]
                    );
                    // Actualizar acuerdo_id con el crédito (reutilizamos cuotas_acuerdo)
                    $cuotaId = (int) $db->fetchValue("SELECT currval('cuotas_acuerdo_id_seq')");
                    $db->query("UPDATE cuotas_acuerdo SET acuerdo_id=? WHERE id=?", [$credId, $cuotaId]);
                    $fecha->modify('+1 month');
                }

                // Actualizar estado a vigente y registrar desembolso
                $db->query("UPDATE creditos_estudiantiles SET estado='vigente', monto_desembolsado=? WHERE id=?",
                    [$monto, $credId]);

                registrarAuditoria('creditos_estudiantiles', 'INSERT', $credId, [],
                    ['estudiante_id' => $est_id, 'monto' => $monto, 'plazo' => $plazo]);
                $db->commit();
                $_SESSION['flash_success'] = "Crédito creado con tabla de amortización de $plazo cuotas.";
                header('Location: ' . APP_URL . '/modules/cartera/creditos.php?id=' . $credId);
                exit;
            } catch (Exception $e) {
                $db->rollback();
                $_SESSION['flash_error'] = 'Error: ' . $e->getMessage();
            }
        }
    }

    // Registrar pago de cuota de crédito
    if ($accion === 'pagar_cuota_credito') {
        $cuota_id   = (int)($_POST['cuota_id']      ?? 0);
        $credito_id = (int)($_POST['credito_id']    ?? 0);
        $medio_id   = (int)($_POST['medio_pago_id'] ?? 0);
        $fecha_real = $_POST['fecha_pago_real']     ?? date('Y-m-d');
        $ref        = trim($_POST['referencia']     ?? '');

        $cuota = $db->fetchOne(
            "SELECT * FROM cuotas_acuerdo WHERE id=? AND acuerdo_id=? AND estado='pendiente'",
            [$cuota_id, $credito_id]
        );
        $credito = $db->fetchOne("SELECT * FROM creditos_estudiantiles WHERE id=?", [$credito_id]);

        if (!$cuota || !$credito) {
            $_SESSION['flash_error'] = 'Cuota no encontrada o ya pagada.';
        } else {
            // Calcular mora exacta por días
            $fVenc = new DateTime($cuota['fecha_vencimiento']);
            $fPago = new DateTime($fecha_real);
            $dias  = max(0, $fPago->diff($fVenc)->days * ($fPago > $fVenc ? 1 : -1));
            $tasa_mora = (float)($credito['tasa_mora_mensual'] ?? 0);
            $mora  = $dias > 0 ? round($cuota['saldo_capital'] * $tasa_mora / 30 * $dias, 0) : 0;
            $total = (float)$cuota['valor_total_pagar'] + $mora;

            try {
                $db->beginTransaction();

                // Buscar factura del crédito para el pago (puede ser nulo si es crédito interno)
                $numRecibo = generarNumeroRecibo();
                $facturaId = $db->fetchValue(
                    "SELECT f.id FROM facturas f JOIN estudiantes e ON e.id=f.estudiante_id WHERE e.id=? AND f.estado NOT IN ('anulada','pagada') LIMIT 1",
                    [$credito['estudiante_id']]
                );

                if ($facturaId) {
                    $db->query(
                        "INSERT INTO pagos (factura_id, medio_pago_id, numero_recibo, fecha_pago, valor, referencia_bancaria, estado, registrado_por)
                         VALUES (?,?,?,?,?,'aplicado',?)",
                        [$facturaId, $medio_id, $numRecibo, $fecha_real, $total, $ref ?: null, $usuario['id']]
                    );
                    $pagoId = (int) $db->fetchValue("SELECT currval('pagos_id_seq')");
                } else {
                    $pagoId = null;
                }

                $db->query(
                    "UPDATE cuotas_acuerdo SET
                        estado='pagada', fecha_pago_real=?, valor_pagado=?,
                        dias_mora=?, valor_mora=?, pago_id=?
                     WHERE id=?",
                    [$fecha_real, $total, $dias, $mora, $pagoId, $cuota_id]
                );

                // Verificar si todas las cuotas están pagadas
                $pend = $db->fetchValue(
                    "SELECT COUNT(*) FROM cuotas_acuerdo WHERE acuerdo_id=? AND estado='pendiente'",
                    [$credito_id]
                );
                if ($pend == 0) {
                    $db->query("UPDATE creditos_estudiantiles SET estado='pagado' WHERE id=?", [$credito_id]);
                }

                $db->commit();
                $msg = "Cuota pagada: " . formatoPeso($total);
                if ($mora > 0) $msg .= " (incluye mora: " . formatoPeso($mora) . " por $dias días)";
                $_SESSION['flash_success'] = $msg;
            } catch (Exception $e) {
                $db->rollback();
                $_SESSION['flash_error'] = 'Error: ' . $e->getMessage();
            }
        }
        header('Location: ' . APP_URL . '/modules/cartera/creditos.php?id=' . $credito_id);
        exit;
    }

    if ($accion !== 'crear_credito') {
        header('Location: ' . APP_URL . '/modules/cartera/creditos.php');
        exit;
    }
}

// ── Vista detalle de un crédito ───────────────────────────────
$idCredito = (int)($_GET['id'] ?? 0);
$credito   = null;
$cuotas    = [];

if ($idCredito) {
    $credito = $db->fetchOne(
        "SELECT c.*, e.primer_nombre||' '||e.primer_apellido AS estudiante,
                e.codigo AS est_codigo, e.numero_documento,
                pr.nombre AS programa,
                u.nombre||' '||u.apellido AS aprobado_nombre
         FROM creditos_estudiantiles c
         JOIN estudiantes e ON e.id=c.estudiante_id
         LEFT JOIN programas pr ON pr.id=e.programa_id
         LEFT JOIN usuarios u ON u.id=c.aprobado_por
         WHERE c.id=?", [$idCredito]
    );
    $cuotas = $db->fetchAll(
        "SELECT ca.*, pg.numero_recibo FROM cuotas_acuerdo ca
         LEFT JOIN pagos pg ON pg.id=ca.pago_id
         WHERE ca.acuerdo_id=? ORDER BY ca.numero_cuota",
        [$idCredito]
    );
}

// ── Listado general ───────────────────────────────────────────
$busq   = trim($_GET['q'] ?? '');
$estado = $_GET['estado'] ?? '';
$where  = ['1=1']; $params = [];
if ($busq)   { $where[] = "(e.primer_nombre ILIKE ? OR e.primer_apellido ILIKE ? OR e.codigo ILIKE ?)"; $params = array_merge($params, ["%$busq%","%$busq%","%$busq%"]); }
if ($estado) { $where[] = 'c.estado=?'; $params[] = $estado; }

$creditos = $db->fetchAll(
    "SELECT c.*, e.primer_nombre||' '||e.primer_apellido AS estudiante,
            e.codigo, pr.nombre AS programa,
            (SELECT COUNT(*) FROM cuotas_acuerdo WHERE acuerdo_id=c.id AND estado='pendiente') AS cuotas_pend,
            (SELECT COALESCE(SUM(valor_mora),0) FROM cuotas_acuerdo WHERE acuerdo_id=c.id) AS mora_total
     FROM creditos_estudiantiles c
     JOIN estudiantes e ON e.id=c.estudiante_id
     LEFT JOIN programas pr ON pr.id=e.programa_id
     WHERE ".implode(' AND ',$where)."
     ORDER BY c.created_at DESC LIMIT 200", $params
);

$estudiantes = $db->fetchAll("SELECT id, codigo, primer_nombre||' '||primer_apellido AS nombre FROM estudiantes WHERE estado='activo' ORDER BY primer_apellido LIMIT 500");
$mediosPago  = $db->fetchAll("SELECT * FROM medios_pago WHERE activo=TRUE ORDER BY nombre");

// ── Simulador (GET) ───────────────────────────────────────────
$simCapital   = (float) str_replace(['.', ','], ['', '.'], $_GET['capital'] ?? '0');
$simTasa      = (float) str_replace(',', '.', $_GET['tasa']   ?? '0');
$simPlazo     = (int)  ($_GET['plazo']   ?? 0);
$simTabla     = [];
if ($simCapital > 0 && $simTasa >= 0 && $simPlazo > 0) {
    $simTabla = $db->fetchAll(
        "SELECT * FROM fn_tabla_amortizacion(?, ?, ?, 0)",
        [$simCapital, $simTasa / 100, $simPlazo]
    );
}
?>

<?php if ($credito): ?>
<!-- ═══ Vista Detalle Crédito ═══ -->
<div class="flex gap-2 mb-4">
    <a href="?" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Todos los créditos</a>
    <button onclick="window.print()" class="btn btn-outline btn-sm"><i class="fas fa-print"></i> Imprimir Plan</button>
</div>

<?php
    $totCapital   = array_sum(array_column($cuotas, 'valor_capital'));
    $totIntereses = array_sum(array_column($cuotas, 'valor_interes'));
    $totMora      = array_sum(array_column($cuotas, 'valor_mora'));
    $totPagado    = array_sum(array_map(fn($c) => $c['estado']==='pagada' ? $c['valor_pagado'] : 0, $cuotas));
    $cuotasPend   = count(array_filter($cuotas, fn($c) => $c['estado'] === 'pendiente'));
    $cuotasPagadas= count(array_filter($cuotas, fn($c) => $c['estado'] === 'pagada'));
?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:1.2rem">
<div>
<!-- Encabezado crédito -->
<div class="card mb-3">
    <div class="card-body" style="padding:1.2rem 1.4rem;background:linear-gradient(135deg,rgba(26,58,92,.06),rgba(200,146,42,.06))">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem">
            <div>
                <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:var(--col-muted)">Crédito Estudiantil #<?= $idCredito ?></div>
                <div style="font-family:var(--font-display);font-size:1.3rem;color:var(--col-primary)"><?= e($credito['estudiante']) ?></div>
                <div style="font-size:.84rem;color:var(--col-muted)"><?= e($credito['est_codigo']) ?> · <?= e($credito['programa'] ?? '') ?></div>
            </div>
            <div style="text-align:right"><?= estadoBadge($credito['estado']) ?></div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:.8rem;margin-top:1rem">
            <?php foreach([
                ['Monto Aprobado',  formatoPeso($credito['monto_aprobado']), 'primary'],
                ['Tasa Mensual',    number_format($credito['tasa_interes']*100,2).'%', 'info'],
                ['Plazo',           $credito['plazo_meses'].' meses', 'secondary'],
                ['Total Intereses', formatoPeso($totIntereses), 'warning'],
                ['Mora Acumulada',  formatoPeso($totMora), $totMora>0?'danger':'success'],
            ] as [$l,$v,$c]): ?>
            <div style="background:white;border-radius:var(--radius-sm);padding:.6rem;text-align:center;box-shadow:var(--shadow-sm)">
                <div style="font-size:.68rem;color:var(--col-muted);text-transform:uppercase"><?= $l ?></div>
                <div style="font-weight:700;font-size:.95rem;color:var(--col-<?= $c ?>)"><?= $v ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Tabla de amortización -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-table" style="color:var(--col-accent)"></i> Tabla de Amortización — Sistema Francés</h3>
        <div class="flex gap-2">
            <span style="font-size:.8rem;color:var(--col-muted)"><?= $cuotasPagadas ?>/<?= count($cuotas) ?> cuotas pagadas</span>
        </div>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th style="text-align:center">N°</th>
                    <th>Vencimiento</th>
                    <th>Fecha Pago</th>
                    <th class="text-right">Cuota</th>
                    <th class="text-right">Capital</th>
                    <th class="text-right">Interés</th>
                    <th style="text-align:center">Días Mora</th>
                    <th class="text-right">Mora</th>
                    <th class="text-right">Total a Pagar</th>
                    <th class="text-right">Saldo Capital</th>
                    <th>Estado</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php $saldoAcum = (float)$credito['monto_aprobado']; ?>
                <?php foreach ($cuotas as $cu):
                    $vencida = $cu['estado']==='pendiente' && new DateTime($cu['fecha_vencimiento']) < new DateTime();
                    $hoy = new DateTime();
                    $fVenc = new DateTime($cu['fecha_vencimiento']);
                    $diasAHoy = $cu['estado']==='pendiente' ? max(0, $hoy->diff($fVenc)->days * ($hoy > $fVenc ? 1 : -1)) : $cu['dias_mora'];
                    $moraHoy  = $diasAHoy > 0 && $cu['estado']==='pendiente'
                        ? round($cu['saldo_capital'] * ((float)$credito['tasa_mora_mensual'] / 30) * $diasAHoy, 0)
                        : (float)$cu['valor_mora'];
                ?>
                <tr style="<?= $cu['estado']==='pagada'?'background:rgba(22,163,74,.03)':($vencida?'background:rgba(220,38,38,.04)':'') ?>">
                    <td style="text-align:center;font-weight:700"><?= $cu['numero_cuota'] ?></td>
                    <td>
                        <?= formatoFecha($cu['fecha_vencimiento']) ?>
                        <?php if ($vencida): ?><br><small style="color:var(--col-danger)"><?= abs($diasAHoy) ?> días</small><?php endif; ?>
                    </td>
                    <td><small style="color:var(--col-success)"><?= $cu['fecha_pago_real'] ? formatoFecha($cu['fecha_pago_real']) : '–' ?></small></td>
                    <td class="text-right"><?= formatoPeso($cu['valor']) ?></td>
                    <td class="text-right" style="color:var(--col-primary)"><?= formatoPeso($cu['valor_capital']) ?></td>
                    <td class="text-right" style="color:var(--col-warning)"><?= formatoPeso($cu['valor_interes']) ?></td>
                    <td style="text-align:center">
                        <?php if ($diasAHoy > 0): ?>
                        <span class="badge badge-danger"><?= $diasAHoy ?></span>
                        <?php else: ?><span style="color:var(--col-muted)">0</span><?php endif; ?>
                    </td>
                    <td class="text-right" style="color:var(--col-danger)"><?= $moraHoy > 0 ? formatoPeso($moraHoy) : '–' ?></td>
                    <td class="text-right font-bold" style="color:var(--col-<?= $cu['estado']==='pagada'?'success':'text' ?>)">
                        <?= formatoPeso((float)$cu['valor_total_pagar'] + $moraHoy) ?>
                    </td>
                    <td class="text-right" style="color:var(--col-muted);font-size:.83rem"><?= formatoPeso($cu['saldo_capital']) ?></td>
                    <td><?= estadoBadge($cu['estado']) ?></td>
                    <td>
                        <?php if ($cu['estado']==='pendiente' && in_array($usuario['rol'],['admin','financiero','cajero'])): ?>
                        <button data-modal="modalPagarCuota" class="btn btn-accent btn-sm"
                                onclick="prepPago(<?= $cu['id'] ?>, <?= $idCredito ?>, '<?= formatoPeso((float)$cu['valor_total_pagar'] + $moraHoy) ?>')">
                            <i class="fas fa-dollar-sign"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:rgba(26,58,92,.07);font-weight:700">
                    <td colspan="3" style="padding:.6rem;color:var(--col-primary)">TOTALES</td>
                    <td class="text-right" style="padding:.6rem"><?= formatoPeso($totCapital+$totIntereses) ?></td>
                    <td class="text-right" style="padding:.6rem;color:var(--col-primary)"><?= formatoPeso($totCapital) ?></td>
                    <td class="text-right" style="padding:.6rem;color:var(--col-warning)"><?= formatoPeso($totIntereses) ?></td>
                    <td></td>
                    <td class="text-right" style="padding:.6rem;color:var(--col-danger)"><?= formatoPeso($totMora) ?></td>
                    <td class="text-right" style="padding:.6rem"><?= formatoPeso($totPagado) ?></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
</div>

<!-- Panel lateral -->
<div>
<div class="card mb-3">
    <div class="card-header"><h3 class="card-title">Detalle del Crédito</h3></div>
    <div class="card-body" style="font-size:.83rem">
        <?php foreach ([
            ['Tipo',        ucfirst($credito['tipo'])],
            ['Concepto',    ucfirst(str_replace('_',' ',$credito['tipo_credito_interno']))],
            ['Entidad',     $credito['entidad'] ?? 'Interno'],
            ['F. Inicio',   formatoFecha($credito['fecha_inicio'])],
            ['F. Vencimiento', formatoFecha($credito['fecha_vencimiento'])],
            ['Tasa Interés',number_format($credito['tasa_interes']*100,4).'% mensual'],
            ['Tasa Mora',   number_format($credito['tasa_mora_mensual']*100,4).'% mensual'],
            ['Aprobó',      $credito['aprobado_nombre'] ?? '–'],
        ] as [$l,$v]): ?>
        <div style="display:flex;justify-content:space-between;padding:.3rem 0;border-bottom:1px solid var(--col-border)">
            <span style="color:var(--col-muted)"><?= $l ?></span>
            <strong style="text-align:right;max-width:60%"><?= e($v) ?></strong>
        </div>
        <?php endforeach; ?>
        <?php if ($credito['observaciones']): ?>
        <div style="margin-top:.6rem;font-style:italic;color:var(--col-muted)"><?= e($credito['observaciones']) ?></div>
        <?php endif; ?>
    </div>
</div>
<div class="card">
    <div class="card-header"><h3 class="card-title">Asientos Contables</h3></div>
    <div class="card-body" style="font-size:.8rem">
        <div style="background:rgba(26,58,92,.05);border-radius:var(--radius-sm);padding:.6rem;margin-bottom:.5rem">
            <strong>1. Facturación del crédito:</strong><br>
            DB 13 CxC por Cobrar: <?= formatoPeso($credito['monto_aprobado']) ?><br>
            CR 29 Ing. Terceros: <?= formatoPeso($credito['monto_aprobado']) ?>
        </div>
        <div style="background:rgba(22,163,74,.06);border-radius:var(--radius-sm);padding:.6rem">
            <strong>2. Pago de cuota (sin mora):</strong><br>
            DB 11 Banco: Valor cuota<br>
            CR 13 CxC: Abono capital<br>
            CR 48 Interés corriente: Valor interés
        </div>
    </div>
</div>
</div>
</div>

<!-- Modal pagar cuota -->
<div class="modal-overlay" id="modalPagarCuota">
    <div class="modal" style="max-width:380px">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-hand-holding-dollar" style="color:var(--col-success)"></i> Pagar Cuota</h3>
            <button class="modal-close"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="accion" value="pagar_cuota_credito">
            <input type="hidden" name="cuota_id" id="cuotaId">
            <input type="hidden" name="credito_id" value="<?= $idCredito ?>">
            <div class="modal-body">
                <div style="text-align:center;font-size:1.1rem;font-weight:700;margin-bottom:1rem;color:var(--col-primary)" id="lblValorCuota"></div>
                <div class="form-group">
                    <label>Fecha Real de Pago *</label>
                    <input type="date" name="fecha_pago_real" class="form-control" required value="<?= date('Y-m-d') ?>">
                    <small style="color:var(--col-muted)">Si la fecha es posterior al vencimiento se calculará mora automáticamente.</small>
                </div>
                <div class="form-group">
                    <label>Medio de Pago *</label>
                    <select name="medio_pago_id" class="form-select" required>
                        <option value="">Seleccione...</option>
                        <?php foreach ($mediosPago as $mp): ?>
                        <option value="<?= $mp['id'] ?>"><?= e($mp['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Referencia</label>
                    <input type="text" name="referencia" class="form-control" placeholder="N° transacción">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline modal-close">Cancelar</button>
                <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Confirmar</button>
            </div>
        </form>
    </div>
</div>
<script>function prepPago(cid,cred,val){document.getElementById('cuotaId').value=cid;document.getElementById('lblValorCuota').textContent='Valor estimado: '+val;}</script>

<?php else: ?>
<!-- ═══ Listado + Nuevo crédito ═══ -->
<div class="flex gap-2 mb-4">
    <button data-modal="modalNuevoCredito" class="btn btn-primary"><i class="fas fa-plus"></i> Nuevo Crédito</button>
    <button data-modal="modalSimulador" class="btn btn-outline"><i class="fas fa-calculator"></i> Simulador</button>
</div>

<div class="stats-grid mb-4" style="grid-template-columns:repeat(4,1fr)">
    <?php
    $totales = $db->fetchOne("SELECT COUNT(*) AS total, SUM(monto_aprobado) AS monto, COUNT(*) FILTER(WHERE estado='vigente') AS vigentes, SUM(monto_aprobado) FILTER(WHERE estado='vigente') AS monto_vigente FROM creditos_estudiantiles");
    ?>
    <?php foreach([
        ['Total Créditos',   number_format($totales['total']),'file-alt','primary',false],
        ['Vigentes',         number_format($totales['vigentes']),'play-circle','success',false],
        ['Monto Total',      formatoPeso($totales['monto']),'coins','accent',false],
        ['Cartera Créditos', formatoPeso($totales['monto_vigente']),'wallet','warning',false],
    ] as [$l,$v,$i,$c,]): ?>
    <div class="stat-card"><div class="stat-icon <?= $c ?>" style="width:40px;height:40px;font-size:.9rem"><i class="fas fa-<?= $i ?>"></i></div><div><div class="stat-value" style="font-size:1.1rem"><?= $v ?></div><div class="stat-label"><?= $l ?></div></div></div>
    <?php endforeach; ?>
</div>

<div class="card mb-3"><div class="card-body" style="padding:.8rem 1.2rem">
<form method="GET" class="flex gap-2 items-center flex-wrap">
    <div class="search-bar" style="max-width:260px"><i class="fas fa-search"></i>
        <input type="text" name="q" placeholder="Estudiante o código..." value="<?= e($busq) ?>">
    </div>
    <select name="estado" class="form-select" style="width:auto">
        <option value="">Todos</option>
        <?php foreach(['aprobado','vigente','pagado','mora','cancelado'] as $s): ?>
        <option value="<?= $s ?>" <?= $estado===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Filtrar</button>
    <a href="?" class="btn btn-outline btn-sm">Limpiar</a>
    <a href="<?= APP_URL ?>/modules/reportes/exportar.php?tipo=creditos&formato=excel" class="btn btn-success btn-sm" style="margin-left:auto"><i class="fas fa-file-excel"></i> Excel</a>
</form></div></div>

<div class="card"><div class="table-wrapper"><table>
<thead><tr><th>Estudiante</th><th>Programa</th><th>Tipo</th><th class="text-right">Monto</th><th style="text-align:center">Plazo</th><th style="text-align:center">Tasa %</th><th style="text-align:center">Cuotas Pend.</th><th class="text-right">Mora</th><th>Estado</th><th></th></tr></thead>
<tbody>
<?php foreach($creditos as $cr): ?>
<tr>
    <td><?= e($cr['estudiante']) ?><br><small style="color:var(--col-muted)"><?= e($cr['codigo']) ?></small></td>
    <td><small><?= e($cr['programa']??'–') ?></small></td>
    <td><span class="badge badge-info" style="font-size:.7rem"><?= ucfirst($cr['tipo']) ?></span></td>
    <td class="text-right font-bold"><?= formatoPeso($cr['monto_aprobado']) ?></td>
    <td style="text-align:center"><?= $cr['plazo_meses'] ?> meses</td>
    <td style="text-align:center"><?= number_format($cr['tasa_interes']*100,2) ?>%</td>
    <td style="text-align:center">
        <?= $cr['cuotas_pend']>0 ? '<span class="badge badge-warning">'.$cr['cuotas_pend'].'</span>' : '<span class="badge badge-success">0</span>' ?>
    </td>
    <td class="text-right" style="color:var(--col-danger)"><?= $cr['mora_total']>0 ? formatoPeso($cr['mora_total']) : '–' ?></td>
    <td><?= estadoBadge($cr['estado']) ?></td>
    <td><a href="?id=<?= $cr['id'] ?>" class="btn btn-outline btn-sm"><i class="fas fa-table"></i> Plan</a></td>
</tr>
<?php endforeach; ?>
<?php if(empty($creditos)): ?><tr><td colspan="10" class="text-center text-muted" style="padding:3rem">Sin créditos registrados</td></tr><?php endif; ?>
</tbody>
</table></div></div>

<!-- Modal Nuevo Crédito -->
<div class="modal-overlay" id="modalNuevoCredito">
    <div class="modal" style="max-width:520px">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-file-invoice-dollar" style="color:var(--col-accent)"></i> Nuevo Crédito Estudiantil</h3>
            <button class="modal-close"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="accion" value="crear_credito">
            <div class="modal-body">
                <div class="form-group">
                    <label>Estudiante *</label>
                    <select name="estudiante_id" class="form-select" required>
                        <option value="">Seleccione...</option>
                        <?php foreach($estudiantes as $e): ?><option value="<?= $e['id'] ?>">[<?= e($e['codigo']) ?>] <?= e($e['nombre']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label>Tipo de Crédito *</label>
                        <select name="tipo" class="form-select"><option value="interno">Interno</option><option value="icetex">ICETEX</option><option value="banco">Banco</option><option value="otro">Otro</option></select>
                    </div>
                    <div class="form-group">
                        <label>Concepto</label>
                        <select name="tipo_credito_interno" class="form-select"><option value="matricula">Matrícula</option><option value="consumo">Consumo</option><option value="alimentacion">Alimentación</option><option value="materiales">Materiales</option><option value="otro">Otro</option></select>
                    </div>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label>Monto Aprobado (COP) *</label>
                        <input type="text" name="monto_aprobado" class="form-control input-currency" required placeholder="1.000.000">
                    </div>
                    <div class="form-group">
                        <label>Plazo (meses) *</label>
                        <input type="number" name="plazo_meses" class="form-control" required min="1" max="60" value="4">
                    </div>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label>Tasa Interés Mensual (%)</label>
                        <input type="number" name="tasa_interes" class="form-control" step="0.0001" min="0" value="2.8" placeholder="2.8">
                        <small style="color:var(--col-muted)">0 = sin interés</small>
                    </div>
                    <div class="form-group">
                        <label>Tasa Mora Mensual (%)</label>
                        <input type="number" name="tasa_mora_mensual" class="form-control" step="0.0001" min="0" value="3.5" placeholder="3.5">
                    </div>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label>Fecha de Inicio</label>
                        <input type="date" name="fecha_inicio" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label>Entidad</label>
                        <input type="text" name="entidad" class="form-control" placeholder="Nombre entidad / interno">
                    </div>
                </div>
                <div class="form-group">
                    <label>Observaciones</label>
                    <textarea name="observaciones" class="form-control" rows="2" placeholder="Condiciones del crédito..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline modal-close">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Crear y Generar Plan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Simulador -->
<div class="modal-overlay" id="modalSimulador">
    <div class="modal" style="max-width:640px">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-calculator" style="color:var(--col-accent)"></i> Simulador de Crédito — Sistema Francés</h3>
            <button class="modal-close"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="GET" class="flex gap-2 flex-wrap items-end">
                <input type="hidden" name="sim" value="1">
                <div class="form-group" style="margin:0;flex:1"><label>Capital</label><input type="text" name="capital" class="form-control input-currency" value="<?= $simCapital ? number_format($simCapital,0,',','.') : '' ?>" placeholder="1.000.000"></div>
                <div class="form-group" style="margin:0;width:120px"><label>Tasa % mensual</label><input type="number" name="tasa" class="form-control" step="0.0001" value="<?= $simTasa ?: 2.8 ?>"></div>
                <div class="form-group" style="margin:0;width:100px"><label>Plazo (meses)</label><input type="number" name="plazo" class="form-control" min="1" max="60" value="<?= $simPlazo ?: 4 ?>"></div>
                <button type="submit" class="btn btn-primary" style="margin-bottom:1rem"><i class="fas fa-calculator"></i> Simular</button>
            </form>
            <?php if ($simTabla): ?>
            <div style="margin-top:1rem;overflow-x:auto">
                <div style="font-size:.82rem;color:var(--col-muted);margin-bottom:.5rem">
                    Cuota fija: <strong><?= formatoPeso($simTabla[0]['valor_cuota']) ?></strong> |
                    Total a pagar: <strong><?= formatoPeso(array_sum(array_column($simTabla,'valor_cuota'))) ?></strong> |
                    Total intereses: <strong style="color:var(--col-warning)"><?= formatoPeso(array_sum(array_column($simTabla,'valor_interes'))) ?></strong>
                </div>
                <table style="width:100%;border-collapse:collapse;font-size:.83rem">
                    <thead><tr style="background:rgba(26,58,92,.07)">
                        <?php foreach(['N°','Saldo Inicial','Cuota','Capital','Interés','Saldo Final'] as $h): ?>
                        <th style="padding:.4rem .6rem;text-align:<?= in_array($h,['N°'])?'center':'right' ?>;border-bottom:1.5px solid var(--col-primary);font-size:.75rem;color:var(--col-primary)"><?= $h ?></th>
                        <?php endforeach; ?>
                    </tr></thead>
                    <tbody>
                    <?php foreach($simTabla as $r): ?>
                    <tr style="border-bottom:1px solid #eee">
                        <td style="text-align:center;padding:.35rem .6rem;font-weight:700"><?= $r['cuota_no'] ?></td>
                        <td style="text-align:right;padding:.35rem .6rem"><?= formatoPeso($r['saldo_inicial']) ?></td>
                        <td style="text-align:right;padding:.35rem .6rem;font-weight:700"><?= formatoPeso($r['valor_cuota']) ?></td>
                        <td style="text-align:right;padding:.35rem .6rem;color:var(--col-primary)"><?= formatoPeso($r['valor_capital']) ?></td>
                        <td style="text-align:right;padding:.35rem .6rem;color:var(--col-warning)"><?= formatoPeso($r['valor_interes']) ?></td>
                        <td style="text-align:right;padding:.35rem .6rem;color:var(--col-muted)"><?= formatoPeso($r['saldo_final']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div style="text-align:center;padding:2rem;color:var(--col-muted)">Ingrese los datos y haga clic en Simular para ver la tabla</div>
            <?php endif; ?>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline modal-close">Cerrar</button></div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
