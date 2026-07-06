<?php
// dashboard.php
$tituloPagina = 'Dashboard';
require_once __DIR__ . '/includes/header.php';

$db = Database::getInstance();

// KPIs principales
$kpis = $db->fetchOne("
    SELECT
        (SELECT COUNT(*) FROM estudiantes WHERE estado = 'activo') AS total_estudiantes,
        (SELECT COALESCE(SUM(total),0) FROM facturas WHERE estado != 'anulada') AS total_facturado,
        (SELECT COALESCE(SUM(valor),0) FROM pagos WHERE estado = 'aplicado') AS total_recaudado,
        (SELECT COALESCE(SUM(saldo),0) FROM facturas WHERE estado IN ('pendiente','parcial','vencida')) AS cartera_total,
        (SELECT COALESCE(SUM(saldo),0) FROM facturas WHERE estado = 'vencida') AS cartera_vencida,
        (SELECT COUNT(*) FROM facturas WHERE estado = 'vencida') AS facturas_vencidas,
        (SELECT COUNT(*) FROM facturas WHERE estado = 'pendiente') AS facturas_pendientes,
        (SELECT COALESCE(SUM(valor),0) FROM pagos WHERE estado = 'aplicado' AND DATE(fecha_pago) = CURRENT_DATE) AS recaudo_hoy,
        (SELECT COUNT(*) FROM acuerdos_pago WHERE estado = 'vigente') AS acuerdos_vigentes,
        (SELECT COUNT(*) FROM cuotas_acuerdo ca JOIN acuerdos_pago ap ON ap.id=ca.acuerdo_id WHERE ca.estado='pendiente' AND ca.fecha_vencimiento < CURRENT_DATE) AS cuotas_vencidas,
        (SELECT COALESCE(SUM(valor),0) FROM patrocinios WHERE estado = 'vigente') AS patrocinios_vigentes,
        (SELECT COUNT(*) FROM descuentos WHERE vigente = TRUE) AS becas_activas,
        (SELECT COALESCE(SUM(valor_mora),0) FROM mora_registrada WHERE cobrada = FALSE) AS mora_pendiente
");

// Recaudo por mes (últimos 6 meses)
$recaudoMeses = $db->fetchAll("
    SELECT TO_CHAR(fecha_pago, 'Mon') AS mes,
           EXTRACT(MONTH FROM fecha_pago) AS num_mes,
           COALESCE(SUM(valor),0) AS total
    FROM pagos
    WHERE estado = 'aplicado'
      AND fecha_pago >= NOW() - INTERVAL '6 months'
    GROUP BY mes, num_mes
    ORDER BY num_mes
");

// Distribución cartera por estado
$estadosFactura = $db->fetchAll("
    SELECT estado, COUNT(*) AS cantidad, COALESCE(SUM(saldo),0) AS valor
    FROM facturas
    WHERE estado != 'anulada'
    GROUP BY estado
    ORDER BY valor DESC
");

// Top 10 deudores
$topDeudores = $db->fetchAll("
    SELECT e.codigo, e.primer_nombre || ' ' || e.primer_apellido AS nombre,
           p.nombre AS programa, f.saldo, f.fecha_vencimiento, f.estado
    FROM facturas f
    JOIN estudiantes e ON e.id = f.estudiante_id
    JOIN programas p ON p.id = e.programa_id
    WHERE f.estado IN ('pendiente','parcial','vencida')
    ORDER BY f.saldo DESC
    LIMIT 10
");

// Últimos pagos
$ultimosPagos = $db->fetchAll("
    SELECT pg.numero_recibo, pg.fecha_pago, pg.valor,
           e.primer_nombre || ' ' || e.primer_apellido AS estudiante,
           mp.nombre AS medio_pago, pg.estado
    FROM pagos pg
    JOIN facturas f ON f.id = pg.factura_id
    JOIN estudiantes e ON e.id = f.estudiante_id
    JOIN medios_pago mp ON mp.id = pg.medio_pago_id
    ORDER BY pg.created_at DESC
    LIMIT 8
");

$porcentajeRecaudo = $kpis['total_facturado'] > 0
    ? round(($kpis['total_recaudado'] / $kpis['total_facturado']) * 100, 1)
    : 0;
?>

<!-- KPI Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon primary"><i class="fas fa-user-graduate"></i></div>
        <div>
            <div class="stat-value"><?= number_format($kpis['total_estudiantes']) ?></div>
            <div class="stat-label">Estudiantes Activos</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon accent"><i class="fas fa-file-invoice-dollar"></i></div>
        <div>
            <div class="stat-value"><?= formatoPeso($kpis['total_facturado']) ?></div>
            <div class="stat-label">Total Facturado</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon success"><i class="fas fa-hand-holding-dollar"></i></div>
        <div>
            <div class="stat-value"><?= formatoPeso($kpis['total_recaudado']) ?></div>
            <div class="stat-label">Total Recaudado (<?= $porcentajeRecaudo ?>%)</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning"><i class="fas fa-clock"></i></div>
        <div>
            <div class="stat-value"><?= formatoPeso($kpis['cartera_total']) ?></div>
            <div class="stat-label">Cartera Total</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon danger"><i class="fas fa-exclamation-triangle"></i></div>
        <div>
            <div class="stat-value"><?= formatoPeso($kpis['cartera_vencida']) ?></div>
            <div class="stat-label"><?= $kpis['facturas_vencidas'] ?> Facturas Vencidas</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon info"><i class="fas fa-calendar-day"></i></div>
        <div>
            <div class="stat-value"><?= formatoPeso($kpis['recaudo_hoy']) ?></div>
            <div class="stat-label">Recaudo de Hoy</div>
        </div>
    </div>
</div>

<!-- KPIs módulos avanzados -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));margin-bottom:1rem">
    <div class="stat-card">
        <div class="stat-icon info"><i class="fas fa-handshake"></i></div>
        <div>
            <div class="stat-value"><?= number_format($kpis['acuerdos_vigentes']) ?></div>
            <div class="stat-label">Acuerdos Vigentes</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning"><i class="fas fa-calendar-times"></i></div>
        <div>
            <div class="stat-value"><?= number_format($kpis['cuotas_vencidas']) ?></div>
            <div class="stat-label">Cuotas Vencidas</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon success"><i class="fas fa-award"></i></div>
        <div>
            <div class="stat-value"><?= number_format($kpis['becas_activas']) ?></div>
            <div class="stat-label">Becas Activas</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon accent"><i class="fas fa-building"></i></div>
        <div>
            <div class="stat-value"><?= formatoPeso($kpis['patrocinios_vigentes']) ?></div>
            <div class="stat-label">Patrocinios Vigentes</div>
        </div>
    </div>
    <?php if ($kpis['mora_pendiente'] > 0): ?>
    <div class="stat-card">
        <div class="stat-icon danger"><i class="fas fa-fire"></i></div>
        <div>
            <div class="stat-value"><?= formatoPeso($kpis['mora_pendiente']) ?></div>
            <div class="stat-label">Mora por Cobrar</div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Progress bar recaudo -->
<div class="card mb-4">
    <div class="card-body" style="padding: 1rem 1.4rem;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem">
            <span style="font-size:.85rem;font-weight:500">Progreso de Recaudo del Período</span>
            <span style="font-size:.85rem;color:var(--col-muted)"><?= $porcentajeRecaudo ?>% completado</span>
        </div>
        <div style="background:#e2e6ea;border-radius:20px;height:10px;overflow:hidden">
            <div style="width:<?= $porcentajeRecaudo ?>%;height:100%;background:linear-gradient(90deg,var(--col-primary),var(--col-accent));border-radius:20px;transition:width 1s ease"></div>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.2rem;margin-bottom:1.2rem">

    <!-- Distribución por estado -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-chart-pie" style="color:var(--col-accent)"></i> Estado de Cartera</h3>
        </div>
        <div class="card-body">
            <?php foreach ($estadosFactura as $e): ?>
            <?php
                $colores = ['pendiente'=>'#d97706','parcial'=>'#0284c7','pagada'=>'#16a34a','vencida'=>'#dc2626','anulada'=>'#94a3b8'];
                $color = $colores[$e['estado']] ?? '#94a3b8';
                $pct = $kpis['total_facturado'] > 0 ? round(($e['valor'] / $kpis['total_facturado']) * 100, 1) : 0;
            ?>
            <div style="margin-bottom:.8rem">
                <div style="display:flex;justify-content:space-between;font-size:.83rem;margin-bottom:.3rem">
                    <span><?= estadoBadge($e['estado']) ?> <span style="color:var(--col-muted)">(<?= $e['cantidad'] ?>)</span></span>
                    <span class="font-bold"><?= formatoPeso($e['valor']) ?></span>
                </div>
                <div style="background:#e2e6ea;border-radius:20px;height:6px">
                    <div style="width:<?= $pct ?>%;height:100%;background:<?= $color ?>;border-radius:20px"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Recaudo últimos meses -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-chart-bar" style="color:var(--col-accent)"></i> Recaudo Últimos 6 Meses</h3>
        </div>
        <div class="card-body">
            <?php if ($recaudoMeses): ?>
            <?php
                $maxVal = max(array_column($recaudoMeses, 'total'));
            ?>
            <div style="display:flex;align-items:flex-end;gap:.6rem;height:120px;padding-bottom:1.5rem;position:relative">
                <?php foreach ($recaudoMeses as $r): ?>
                <?php $h = $maxVal > 0 ? round(($r['total'] / $maxVal) * 100) : 0; ?>
                <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:.3rem;position:relative">
                    <span style="font-size:.65rem;color:var(--col-muted);white-space:nowrap"><?= formatoPeso($r['total']) ?></span>
                    <div style="width:100%;height:<?= $h ?>px;background:linear-gradient(180deg,var(--col-accent),var(--col-primary));border-radius:4px 4px 0 0;min-height:4px;transition:height .5s ease" title="<?= $r['mes'] ?>: <?= formatoPeso($r['total']) ?>"></div>
                    <span style="font-size:.72rem;font-weight:600;color:var(--col-muted);position:absolute;bottom:-1.2rem"><?= $r['mes'] ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p style="color:var(--col-muted);text-align:center;padding:2rem 0">Sin datos de recaudo</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:3fr 2fr;gap:1.2rem">

    <!-- Top deudores -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-exclamation-circle" style="color:var(--col-danger)"></i> Principales Saldos Pendientes</h3>
            <a href="<?= APP_URL ?>/modules/reportes/morosos.php" class="btn btn-outline btn-sm">Ver todos</a>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Estudiante</th>
                        <th>Programa</th>
                        <th class="text-right">Saldo</th>
                        <th>Vence</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topDeudores as $d): ?>
                    <tr>
                        <td>
                            <strong><?= e($d['nombre']) ?></strong><br>
                            <small style="color:var(--col-muted)"><?= e($d['codigo']) ?></small>
                        </td>
                        <td><small><?= e($d['programa']) ?></small></td>
                        <td class="text-right font-bold" style="color:var(--col-danger)"><?= formatoPeso($d['saldo']) ?></td>
                        <td>
                            <?php $dias = diasVencimiento($d['fecha_vencimiento']); ?>
                            <small <?= $dias < 0 ? 'style="color:var(--col-danger)"' : '' ?>>
                                <?= $dias < 0 ? abs($dias) . ' días venc.' : ($dias === 0 ? 'Hoy' : $dias . ' días') ?>
                            </small>
                        </td>
                        <td><?= estadoBadge($d['estado']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($topDeudores)): ?>
                    <tr><td colspan="5" class="text-center text-muted" style="padding:2rem">Sin saldos pendientes</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Últimos pagos -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-receipt" style="color:var(--col-success)"></i> Últimos Pagos</h3>
            <a href="<?= APP_URL ?>/modules/pagos/lista.php" class="btn btn-outline btn-sm">Ver todos</a>
        </div>
        <div class="card-body" style="padding:0">
            <?php foreach ($ultimosPagos as $pg): ?>
            <div style="padding:.7rem 1rem;border-bottom:1px solid var(--col-border);display:flex;justify-content:space-between;align-items:center">
                <div>
                    <div style="font-size:.85rem;font-weight:500"><?= e($pg['estudiante']) ?></div>
                    <div style="font-size:.75rem;color:var(--col-muted)"><?= e($pg['numero_recibo']) ?> · <?= e($pg['medio_pago']) ?></div>
                    <div style="font-size:.73rem;color:var(--col-muted)"><?= formatoFecha($pg['fecha_pago'], 'd/m/Y H:i') ?></div>
                </div>
                <div class="text-right">
                    <div class="font-bold" style="color:var(--col-success)"><?= formatoPeso($pg['valor']) ?></div>
                    <?= estadoBadge($pg['estado']) ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($ultimosPagos)): ?>
            <p class="text-center text-muted" style="padding:2rem">Sin pagos registrados</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
