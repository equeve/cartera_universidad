<?php
// modules/reportes/recaudos.php
$tituloPagina    = 'Reporte de Recaudos';
$subtituloPagina = 'Análisis de pagos y recaudo por período';
require_once __DIR__ . '/../../includes/header.php';
requireRol(['admin','financiero']);

$db = Database::getInstance();

$desde  = $_GET['desde'] ?? date('Y-m-01');
$hasta  = $_GET['hasta'] ?? date('Y-m-d');
$periodo_id = (int)($_GET['periodo'] ?? 0);

$whereExtra = $periodo_id ? 'AND f.periodo_id = ?' : '';
$paramsBase = $periodo_id ? [$desde, $hasta, $periodo_id] : [$desde, $hasta];

// Resumen general del rango
$resumen = $db->fetchOne(
    "SELECT COUNT(pg.id) AS total_pagos,
            COALESCE(SUM(pg.valor),0) AS total_recaudado,
            COUNT(DISTINCT f.estudiante_id) AS estudiantes_pagaron,
            COUNT(DISTINCT DATE(pg.fecha_pago)) AS dias_con_movimiento,
            COALESCE(MAX(pg.valor),0) AS pago_mayor,
            COALESCE(AVG(pg.valor),0) AS pago_promedio
     FROM pagos pg
     JOIN facturas f ON f.id = pg.factura_id
     WHERE pg.estado = 'aplicado'
       AND DATE(pg.fecha_pago) BETWEEN ? AND ?
       $whereExtra",
    $paramsBase
);

// Recaudo por día
$porDia = $db->fetchAll(
    "SELECT DATE(pg.fecha_pago) AS dia,
            COUNT(pg.id) AS pagos,
            COALESCE(SUM(pg.valor),0) AS total
     FROM pagos pg
     JOIN facturas f ON f.id = pg.factura_id
     WHERE pg.estado = 'aplicado'
       AND DATE(pg.fecha_pago) BETWEEN ? AND ?
       $whereExtra
     GROUP BY dia ORDER BY dia",
    $paramsBase
);

// Por medio de pago
$porMedio = $db->fetchAll(
    "SELECT mp.nombre, COUNT(pg.id) AS cantidad, COALESCE(SUM(pg.valor),0) AS total,
            ROUND(100.0 * SUM(pg.valor) / NULLIF(SUM(SUM(pg.valor)) OVER(),0), 1) AS porcentaje
     FROM pagos pg
     JOIN facturas f ON f.id = pg.factura_id
     JOIN medios_pago mp ON mp.id = pg.medio_pago_id
     WHERE pg.estado = 'aplicado'
       AND DATE(pg.fecha_pago) BETWEEN ? AND ?
       $whereExtra
     GROUP BY mp.nombre ORDER BY total DESC",
    $paramsBase
);

// Por programa
$porPrograma = $db->fetchAll(
    "SELECT pr.nombre AS programa, COUNT(pg.id) AS pagos,
            COALESCE(SUM(pg.valor),0) AS total
     FROM pagos pg
     JOIN facturas f ON f.id = pg.factura_id
     JOIN estudiantes e ON e.id = f.estudiante_id
     JOIN programas pr ON pr.id = e.programa_id
     WHERE pg.estado = 'aplicado'
       AND DATE(pg.fecha_pago) BETWEEN ? AND ?
       $whereExtra
     GROUP BY pr.nombre ORDER BY total DESC",
    $paramsBase
);

// Cajeros del período
$porCajero = $db->fetchAll(
    "SELECT u.nombre || ' ' || u.apellido AS cajero, u.rol,
            COUNT(pg.id) AS pagos,
            COALESCE(SUM(pg.valor),0) AS total
     FROM pagos pg
     JOIN facturas f ON f.id = pg.factura_id
     JOIN usuarios u ON u.id = pg.registrado_por
     WHERE pg.estado = 'aplicado'
       AND DATE(pg.fecha_pago) BETWEEN ? AND ?
       $whereExtra
     GROUP BY cajero, u.rol ORDER BY total DESC",
    $paramsBase
);

$periodos = $db->fetchAll("SELECT id, nombre FROM periodos ORDER BY fecha_inicio DESC");
$maxDia   = $porDia ? max(array_column($porDia, 'total')) : 1;
?>

<!-- Filtros -->
<div class="card mb-3">
    <div class="card-body" style="padding:.8rem 1.2rem">
        <form method="GET" class="flex gap-2 items-center flex-wrap">
            <label style="font-size:.85rem">Desde:</label>
            <input type="date" name="desde" class="form-control" style="width:150px" value="<?= e($desde) ?>">
            <label style="font-size:.85rem">Hasta:</label>
            <input type="date" name="hasta" class="form-control" style="width:150px" value="<?= e($hasta) ?>">
            <select name="periodo" class="form-select" style="width:auto">
                <option value="">Todos los períodos</option>
                <?php foreach ($periodos as $per): ?>
                <option value="<?= $per['id'] ?>" <?= $periodo_id==$per['id']?'selected':'' ?>><?= e($per['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filtrar</button>
            <button type="button" onclick="window.print()" class="btn btn-outline btn-sm"><i class="fas fa-print"></i> Imprimir</button>
        </form>
    </div>
</div>

<!-- KPIs -->
<div class="stats-grid mb-4">
    <div class="stat-card"><div class="stat-icon success"><i class="fas fa-hand-holding-dollar"></i></div><div><div class="stat-value"><?= formatoPeso($resumen['total_recaudado']) ?></div><div class="stat-label">Total Recaudado</div></div></div>
    <div class="stat-card"><div class="stat-icon primary"><i class="fas fa-receipt"></i></div><div><div class="stat-value"><?= number_format($resumen['total_pagos']) ?></div><div class="stat-label">Pagos Registrados</div></div></div>
    <div class="stat-card"><div class="stat-icon accent"><i class="fas fa-user-check"></i></div><div><div class="stat-value"><?= number_format($resumen['estudiantes_pagaron']) ?></div><div class="stat-label">Estudiantes Pagaron</div></div></div>
    <div class="stat-card"><div class="stat-icon info"><i class="fas fa-chart-line"></i></div><div><div class="stat-value"><?= formatoPeso($resumen['pago_promedio']) ?></div><div class="stat-label">Pago Promedio</div></div></div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:1.2rem;margin-bottom:1.2rem">

    <!-- Recaudo diario -->
    <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-calendar-alt" style="color:var(--col-accent)"></i> Recaudo por Día</h3></div>
        <div class="card-body">
            <?php if ($porDia): ?>
            <div style="overflow-x:auto">
                <table style="width:100%;min-width:400px">
                    <thead><tr>
                        <th style="text-align:left;font-size:.75rem;padding:.3rem .5rem;color:var(--col-muted)">Fecha</th>
                        <th style="text-align:center;font-size:.75rem;padding:.3rem .5rem;color:var(--col-muted)">Pagos</th>
                        <th style="text-align:right;font-size:.75rem;padding:.3rem .5rem;color:var(--col-muted)">Total</th>
                        <th style="font-size:.75rem;padding:.3rem .5rem;color:var(--col-muted)">Barra</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($porDia as $d): ?>
                        <?php $pct = $maxDia > 0 ? round(($d['total'] / $maxDia) * 100) : 0; ?>
                        <tr>
                            <td style="font-size:.83rem;padding:.3rem .5rem"><?= formatoFecha($d['dia']) ?></td>
                            <td style="text-align:center;font-size:.83rem;padding:.3rem .5rem"><?= $d['pagos'] ?></td>
                            <td style="text-align:right;font-weight:600;font-size:.83rem;padding:.3rem .5rem;color:var(--col-success)"><?= formatoPeso($d['total']) ?></td>
                            <td style="padding:.3rem .5rem;width:30%">
                                <div style="background:#e2e6ea;border-radius:3px;height:8px">
                                    <div style="width:<?= $pct ?>%;height:100%;background:linear-gradient(90deg,var(--col-primary),var(--col-accent));border-radius:3px"></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:rgba(26,58,92,.05)">
                            <td style="font-weight:700;padding:.5rem;font-size:.85rem">Total</td>
                            <td style="text-align:center;font-weight:700;padding:.5rem;font-size:.85rem"><?= $resumen['total_pagos'] ?></td>
                            <td style="text-align:right;font-weight:700;padding:.5rem;font-size:.9rem;color:var(--col-primary)"><?= formatoPeso($resumen['total_recaudado']) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php else: ?>
            <p class="text-muted text-center" style="padding:2rem">Sin movimientos en el período seleccionado</p>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <!-- Por medio de pago -->
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Por Medio de Pago</h3></div>
            <div class="card-body">
                <?php foreach ($porMedio as $pm): ?>
                <div style="margin-bottom:.8rem">
                    <div style="display:flex;justify-content:space-between;font-size:.83rem;margin-bottom:.2rem">
                        <span><?= e($pm['nombre']) ?> <small style="color:var(--col-muted)">(<?= $pm['cantidad'] ?>)</small></span>
                        <span class="font-bold"><?= formatoPeso($pm['total']) ?></span>
                    </div>
                    <div style="background:#e2e6ea;border-radius:3px;height:6px">
                        <div style="width:<?= $pm['porcentaje'] ?>%;height:100%;background:var(--col-accent);border-radius:3px"></div>
                    </div>
                    <div style="font-size:.72rem;color:var(--col-muted);text-align:right"><?= $pm['porcentaje'] ?>%</div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($porMedio)): ?><p class="text-muted text-center">Sin datos</p><?php endif; ?>
            </div>
        </div>

        <!-- Por cajero -->
        <div class="card">
            <div class="card-header"><h3 class="card-title">Por Cajero / Asesor</h3></div>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>Cajero</th><th class="text-center">Pagos</th><th class="text-right">Total</th></tr></thead>
                    <tbody>
                        <?php foreach ($porCajero as $pc): ?>
                        <tr>
                            <td style="font-size:.83rem"><?= e($pc['cajero']) ?><br><small style="color:var(--col-muted)"><?= ucfirst($pc['rol']) ?></small></td>
                            <td class="text-center"><?= $pc['pagos'] ?></td>
                            <td class="text-right font-bold" style="color:var(--col-success)"><?= formatoPeso($pc['total']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($porCajero)): ?><tr><td colspan="3" class="text-center text-muted" style="padding:1rem">Sin datos</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Por programa -->
<div class="card">
    <div class="card-header"><h3 class="card-title">Recaudo por Programa Académico</h3></div>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Programa</th><th class="text-center">Pagos</th><th class="text-right">Total Recaudado</th><th>Distribución</th></tr></thead>
            <tbody>
                <?php $totalProg = array_sum(array_column($porPrograma, 'total')); ?>
                <?php foreach ($porPrograma as $pp): ?>
                <?php $pct = $totalProg > 0 ? round(($pp['total'] / $totalProg) * 100, 1) : 0; ?>
                <tr>
                    <td><?= e($pp['programa']) ?></td>
                    <td class="text-center"><?= $pp['pagos'] ?></td>
                    <td class="text-right font-bold"><?= formatoPeso($pp['total']) ?></td>
                    <td style="width:30%">
                        <div style="display:flex;align-items:center;gap:.4rem">
                            <div style="flex:1;height:8px;background:#e2e6ea;border-radius:4px">
                                <div style="width:<?= $pct ?>%;height:100%;background:var(--col-primary);border-radius:4px"></div>
                            </div>
                            <span style="font-size:.75rem;width:35px"><?= $pct ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($porPrograma)): ?><tr><td colspan="4" class="text-center text-muted" style="padding:2rem">Sin datos en el período seleccionado</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
