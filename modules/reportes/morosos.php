<?php
// modules/reportes/morosos.php
$tituloPagina    = 'Reporte de Morosos';
$subtituloPagina = 'Estudiantes con saldos vencidos';
require_once __DIR__ . '/../../includes/header.php';
requireRol(['admin','financiero']);

$db = Database::getInstance();

$diasMin    = (int)($_GET['dias'] ?? 1);
$programa_id = (int)($_GET['programa'] ?? 0);
$periodo_id  = (int)($_GET['periodo'] ?? 0);

$where  = ["f.estado IN ('vencida','parcial')", "(CURRENT_DATE - f.fecha_vencimiento) >= ?"];
$params = [$diasMin];

if ($programa_id) { $where[] = 'e.programa_id = ?'; $params[] = $programa_id; }
if ($periodo_id)  { $where[] = 'f.periodo_id = ?'; $params[] = $periodo_id; }

$whereSQL = implode(' AND ', $where);

$morosos = $db->fetchAll(
    "SELECT e.id AS est_id, e.codigo, e.primer_nombre || ' ' || e.primer_apellido AS nombre,
            e.email, e.celular, e.estrato,
            pr.nombre AS programa,
            f.id AS factura_id, f.numero_factura, f.total, f.saldo,
            f.fecha_vencimiento,
            (CURRENT_DATE - f.fecha_vencimiento) AS dias_vencido,
            p.nombre AS periodo
     FROM facturas f
     JOIN estudiantes e ON e.id = f.estudiante_id
     JOIN programas pr ON pr.id = e.programa_id
     JOIN periodos p ON p.id = f.periodo_id
     WHERE $whereSQL
     ORDER BY dias_vencido DESC, f.saldo DESC",
    $params
);

$resumen = $db->fetchOne(
    "SELECT COUNT(DISTINCT e.id) AS total_estudiantes,
            COUNT(f.id) AS total_facturas,
            COALESCE(SUM(f.saldo),0) AS total_cartera,
            AVG(CURRENT_DATE - f.fecha_vencimiento) AS promedio_dias
     FROM facturas f
     JOIN estudiantes e ON e.id = f.estudiante_id
     JOIN programas pr ON pr.id = e.programa_id
     WHERE $whereSQL",
    $params
);

$programas = $db->fetchAll("SELECT id, nombre FROM programas WHERE activo=TRUE ORDER BY nombre");
$periodos  = $db->fetchAll("SELECT id, nombre FROM periodos ORDER BY fecha_inicio DESC");
?>

<!-- Filtros -->
<div class="card mb-3">
    <div class="card-body" style="padding:.8rem 1.2rem">
        <form method="GET" class="flex gap-2 flex-wrap items-center">
            <div class="flex items-center gap-2">
                <label style="white-space:nowrap;font-size:.85rem">Vencidos más de</label>
                <input type="number" name="dias" class="form-control" style="width:80px" value="<?= $diasMin ?>" min="0">
                <label style="font-size:.85rem">días</label>
            </div>
            <select name="programa" class="form-select" style="width:auto">
                <option value="">Todos los programas</option>
                <?php foreach ($programas as $prog): ?>
                <option value="<?= $prog['id'] ?>" <?= $programa_id==$prog['id']?'selected':'' ?>><?= e($prog['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="periodo" class="form-select" style="width:auto">
                <option value="">Todos los períodos</option>
                <?php foreach ($periodos as $per): ?>
                <option value="<?= $per['id'] ?>" <?= $periodo_id==$per['id']?'selected':'' ?>><?= e($per['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filtrar</button>
            <a href="?" class="btn btn-outline">Limpiar</a>
            <button onclick="window.print()" type="button" class="btn btn-outline btn-sm" style="margin-left:auto"><i class="fas fa-print"></i> Imprimir</button>
            <a href="<?= APP_URL ?>/modules/reportes/exportar.php?tipo=morosos&dias=<?= $diasMin ?>&formato=excel" class="btn btn-success btn-sm"><i class="fas fa-file-excel"></i> Excel</a>
            <a href="<?= APP_URL ?>/modules/reportes/exportar.php?tipo=morosos&dias=<?= $diasMin ?>&formato=csv" class="btn btn-outline btn-sm"><i class="fas fa-file-csv"></i> CSV</a>
        </form>
    </div>
</div>

<!-- KPIs -->
<div class="stats-grid mb-4">
    <div class="stat-card"><div class="stat-icon danger"><i class="fas fa-user-times"></i></div><div><div class="stat-value"><?= number_format($resumen['total_estudiantes']) ?></div><div class="stat-label">Estudiantes Morosos</div></div></div>
    <div class="stat-card"><div class="stat-icon warning"><i class="fas fa-file-invoice"></i></div><div><div class="stat-value"><?= number_format($resumen['total_facturas']) ?></div><div class="stat-label">Facturas Vencidas</div></div></div>
    <div class="stat-card"><div class="stat-icon danger"><i class="fas fa-exclamation-circle"></i></div><div><div class="stat-value"><?= formatoPeso($resumen['total_cartera']) ?></div><div class="stat-label">Cartera Morosa Total</div></div></div>
    <div class="stat-card"><div class="stat-icon info"><i class="fas fa-calendar-times"></i></div><div><div class="stat-value"><?= round($resumen['promedio_dias'] ?? 0) ?> días</div><div class="stat-label">Promedio Días Vencido</div></div></div>
</div>

<!-- Tabla -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-exclamation-triangle" style="color:var(--col-danger)"></i> Listado de Morosos</h3>
        <small style="color:var(--col-muted)"><?= count($morosos) ?> registros</small>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Código</th><th>Estudiante</th><th>Programa</th><th>Período</th>
                    <th>Factura</th><th class="text-right">Saldo</th>
                    <th>Vencimiento</th><th>Días Vencido</th><th>Contacto</th><th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($morosos as $m): ?>
                <?php
                $dias = (int)$m['dias_vencido'];
                $colorDias = $dias > 90 ? '#7f1d1d' : ($dias > 60 ? 'var(--col-danger)' : ($dias > 30 ? 'var(--col-warning)' : 'var(--col-accent)'));
                ?>
                <tr>
                    <td><strong><?= e($m['codigo']) ?></strong></td>
                    <td><?= e($m['nombre']) ?></td>
                    <td><small><?= e($m['programa']) ?></small></td>
                    <td><small><?= e($m['periodo']) ?></small></td>
                    <td><small><?= e($m['numero_factura']) ?></small></td>
                    <td class="text-right font-bold" style="color:var(--col-danger)"><?= formatoPeso($m['saldo']) ?></td>
                    <td><?= formatoFecha($m['fecha_vencimiento']) ?></td>
                    <td>
                        <span style="font-weight:700;color:<?= $colorDias ?>;font-size:.95rem"><?= $dias ?></span>
                        <small style="color:var(--col-muted)"> días</small>
                    </td>
                    <td>
                        <small><?= e($m['email']) ?></small><br>
                        <small style="color:var(--col-muted)"><?= e($m['celular'] ?? '') ?></small>
                    </td>
                    <td>
                        <a href="<?= APP_URL ?>/modules/pagos/registrar.php?factura_id=<?= $m['factura_id'] ?>" class="btn btn-accent btn-sm">
                            <i class="fas fa-dollar-sign"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($morosos)): ?>
                <tr><td colspan="10" class="text-center text-muted" style="padding:3rem">
                    <i class="fas fa-check-circle" style="font-size:2rem;color:var(--col-success);display:block;margin-bottom:.5rem"></i>
                    No hay morosos con los filtros seleccionados
                </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
