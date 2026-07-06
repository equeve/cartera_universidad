<?php
// modules/reportes/edades_cartera.php
$tituloPagina    = 'Edades de Cartera';
$subtituloPagina = 'Distribución por rangos de vencimiento — Requerimiento 7';
require_once __DIR__ . '/../../includes/header.php';
requireRol(['admin', 'financiero']);

$db = Database::getInstance();

$periodo_id  = (int)($_GET['periodo']  ?? 0);
$programa_id = (int)($_GET['programa'] ?? 0);

$where  = ["f.estado IN ('pendiente','parcial','vencida')", 'f.saldo > 0'];
$params = [];
if ($periodo_id)  { $where[] = 'f.periodo_id=?';    $params[] = $periodo_id; }
if ($programa_id) { $where[] = 'e.programa_id=?';   $params[] = $programa_id; }
$wSQL = implode(' AND ', $where);

// Resumen por rangos
$rangos = $db->fetchAll(
    "SELECT
        CASE
            WHEN CURRENT_DATE <= f.fecha_vencimiento THEN '0_vigente'
            WHEN (CURRENT_DATE - f.fecha_vencimiento) BETWEEN 1  AND 30  THEN '1_1_30'
            WHEN (CURRENT_DATE - f.fecha_vencimiento) BETWEEN 31 AND 60  THEN '2_31_60'
            WHEN (CURRENT_DATE - f.fecha_vencimiento) BETWEEN 61 AND 90  THEN '3_61_90'
            ELSE '4_mas90'
        END AS rango,
        COUNT(DISTINCT f.estudiante_id) AS estudiantes,
        COUNT(f.id) AS facturas,
        COALESCE(SUM(f.saldo),0) AS saldo
     FROM facturas f
     JOIN estudiantes e ON e.id=f.estudiante_id
     WHERE $wSQL
     GROUP BY rango ORDER BY rango",
    $params
);

// Detalle completo con rango
$detalle = $db->fetchAll(
    "SELECT
        e.codigo, e.primer_nombre||' '||e.primer_apellido AS estudiante,
        e.numero_documento, e.email, e.celular,
        pr.nombre AS programa, p.nombre AS periodo,
        f.numero_factura, f.fecha_vencimiento, f.total, f.saldo,
        (CURRENT_DATE - f.fecha_vencimiento) AS dias_vencido,
        CASE
            WHEN CURRENT_DATE <= f.fecha_vencimiento          THEN 'Vigente'
            WHEN (CURRENT_DATE-f.fecha_vencimiento)<=30       THEN '1-30 días'
            WHEN (CURRENT_DATE-f.fecha_vencimiento)<=60       THEN '31-60 días'
            WHEN (CURRENT_DATE-f.fecha_vencimiento)<=90       THEN '61-90 días'
            ELSE 'Más de 90 días'
        END AS rango_label,
        CASE
            WHEN CURRENT_DATE <= f.fecha_vencimiento          THEN 0
            WHEN (CURRENT_DATE-f.fecha_vencimiento)<=30       THEN 1
            WHEN (CURRENT_DATE-f.fecha_vencimiento)<=60       THEN 2
            WHEN (CURRENT_DATE-f.fecha_vencimiento)<=90       THEN 3
            ELSE 4
        END AS rango_ord
     FROM facturas f
     JOIN estudiantes e ON e.id=f.estudiante_id
     JOIN programas pr ON pr.id=e.programa_id
     JOIN periodos p ON p.id=f.periodo_id
     WHERE $wSQL
     ORDER BY rango_ord DESC, f.saldo DESC
     LIMIT 500",
    $params
);

$periodos  = $db->fetchAll("SELECT id, nombre FROM periodos ORDER BY fecha_inicio DESC");
$programas = $db->fetchAll("SELECT id, nombre FROM programas WHERE activo=TRUE ORDER BY nombre");
$totalCartera = array_sum(array_column($detalle, 'saldo'));

$rangoInfo = [
    '0_vigente' => ['Vigente (No vencido)',    'success', 'check-circle'],
    '1_1_30'    => ['1 a 30 días',             'warning', 'clock'],
    '2_31_60'   => ['31 a 60 días',            'accent',  'hourglass-half'],
    '3_61_90'   => ['61 a 90 días',            'danger',  'exclamation-triangle'],
    '4_mas90'   => ['Más de 90 días',          'secondary','skull'],
];
?>

<div class="card mb-3"><div class="card-body" style="padding:.8rem 1.2rem">
<form method="GET" class="flex gap-2 items-center flex-wrap">
    <select name="periodo" class="form-select" style="width:auto">
        <option value="">Todos los períodos</option>
        <?php foreach($periodos as $per): ?><option value="<?= $per['id'] ?>" <?= $periodo_id==$per['id']?'selected':''?>><?= e($per['nombre']) ?></option><?php endforeach; ?>
    </select>
    <select name="programa" class="form-select" style="width:auto">
        <option value="">Todos los programas</option>
        <?php foreach($programas as $prog): ?><option value="<?= $prog['id'] ?>" <?= $programa_id==$prog['id']?'selected':''?>><?= e($prog['nombre']) ?></option><?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Filtrar</button>
    <a href="?" class="btn btn-outline btn-sm">Limpiar</a>
    <a href="<?= APP_URL ?>/modules/reportes/exportar.php?tipo=edades_cartera&periodo=<?= $periodo_id ?>&programa=<?= $programa_id ?>&formato=excel" class="btn btn-success btn-sm" style="margin-left:auto"><i class="fas fa-file-excel"></i> Excel</a>
    <button onclick="window.print()" type="button" class="btn btn-outline btn-sm"><i class="fas fa-print"></i> Imprimir</button>
</form></div></div>

<!-- Resumen por rangos -->
<div class="stats-grid mb-4" style="grid-template-columns:repeat(5,1fr)">
<?php foreach($rangos as $r):
    $info = $rangoInfo[$r['rango']] ?? ['?','secondary','question'];
    [$lbl,$col,$ico] = $info;
    $pct = $totalCartera > 0 ? round($r['saldo']/$totalCartera*100,1) : 0;
?>
<div class="stat-card" style="flex-direction:column;align-items:flex-start">
    <div style="display:flex;align-items:center;gap:.6rem;width:100%">
        <div class="stat-icon <?= $col ?>" style="width:36px;height:36px;font-size:.85rem"><i class="fas fa-<?= $ico ?>"></i></div>
        <div style="flex:1">
            <div class="stat-value" style="font-size:1rem"><?= formatoPeso($r['saldo']) ?></div>
            <div class="stat-label" style="font-size:.72rem"><?= $lbl ?></div>
        </div>
    </div>
    <div style="width:100%;margin-top:.6rem">
        <div style="display:flex;justify-content:space-between;font-size:.72rem;color:var(--col-muted);margin-bottom:.2rem">
            <span><?= $r['facturas'] ?> fact. · <?= $r['estudiantes'] ?> est.</span><span><?= $pct ?>%</span>
        </div>
        <div style="background:var(--col-border);border-radius:20px;height:5px">
            <div style="width:<?= $pct ?>%;height:100%;background:var(--col-<?= $col ?>);border-radius:20px"></div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Detalle -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-layer-group" style="color:var(--col-accent)"></i> Detalle por Estudiante y Rango</h3>
        <small style="color:var(--col-muted)"><?= count($detalle) ?> facturas · Total: <?= formatoPeso($totalCartera) ?></small>
    </div>
    <div class="table-wrapper"><table>
        <thead><tr>
            <th>Rango</th><th>Estudiante</th><th>Programa</th><th>Período</th>
            <th>Factura</th><th>Vencimiento</th><th style="text-align:center">Días</th>
            <th class="text-right">Total</th><th class="text-right">Saldo</th>
            <th>Email</th><th>Celular</th>
        </tr></thead>
        <tbody>
        <?php
        $rangoColores = ['Vigente'=>'success','1-30 días'=>'warning','31-60 días'=>'accent','61-90 días'=>'danger','Más de 90 días'=>'secondary'];
        foreach($detalle as $r):
            $col = $rangoColores[$r['rango_label']] ?? 'secondary';
        ?>
        <tr>
            <td><span class="badge badge-<?= $col ?>" style="font-size:.7rem;white-space:nowrap"><?= $r['rango_label'] ?></span></td>
            <td><?= e($r['estudiante']) ?><br><small style="color:var(--col-muted)"><?= e($r['codigo']) ?></small></td>
            <td><small><?= e($r['programa']) ?></small></td>
            <td><small><?= e($r['periodo']) ?></small></td>
            <td><small><?= e($r['numero_factura']) ?></small></td>
            <td><small><?= formatoFecha($r['fecha_vencimiento']) ?></small></td>
            <td style="text-align:center">
                <?php if ($r['dias_vencido'] > 0): ?>
                <span style="font-weight:700;color:var(--col-danger)"><?= $r['dias_vencido'] ?></span>
                <?php else: ?><span style="color:var(--col-success)">Vigente</span><?php endif; ?>
            </td>
            <td class="text-right"><?= formatoPeso($r['total']) ?></td>
            <td class="text-right font-bold" style="color:var(--col-danger)"><?= formatoPeso($r['saldo']) ?></td>
            <td><small><?= e($r['email']) ?></small></td>
            <td><small><?= e($r['celular']??'') ?></small></td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($detalle)): ?><tr><td colspan="11" class="text-center text-muted" style="padding:3rem">Sin cartera para los filtros seleccionados</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr style="background:rgba(26,58,92,.07);font-weight:700">
            <td colspan="8" style="padding:.6rem">TOTAL CARTERA</td>
            <td class="text-right" style="padding:.6rem;color:var(--col-danger)"><?= formatoPeso($totalCartera) ?></td>
            <td colspan="2"></td>
        </tr></tfoot>
    </table></div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
