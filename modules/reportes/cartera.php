<?php
// modules/reportes/cartera.php
$tituloPagina    = 'Reporte de Cartera';
$subtituloPagina = 'Análisis de cartera por cobrar';
require_once __DIR__ . '/../../includes/header.php';
requireRol(['admin','financiero']);

$db = Database::getInstance();

$periodo_id  = (int)($_GET['periodo'] ?? 0);
$programa_id = (int)($_GET['programa'] ?? 0);
$estado      = $_GET['estado'] ?? '';

$periodos  = $db->fetchAll("SELECT * FROM periodos ORDER BY fecha_inicio DESC");
$programas = $db->fetchAll("SELECT id, nombre FROM programas WHERE activo = TRUE ORDER BY nombre");

$where  = ["f.estado NOT IN ('anulada')"];
$params = [];

if ($periodo_id)  { $where[] = 'f.periodo_id = ?';    $params[] = $periodo_id; }
if ($programa_id) { $where[] = 'e.programa_id = ?';   $params[] = $programa_id; }
if ($estado)      { $where[] = 'f.estado = ?';        $params[] = $estado; }

$whereSQL = implode(' AND ', $where);

// Resumen general
$resumen = $db->fetchOne(
    "SELECT COUNT(DISTINCT f.id) AS total_facturas,
            COUNT(DISTINCT f.estudiante_id) AS total_estudiantes,
            COALESCE(SUM(f.total), 0)    AS total_facturado,
            COALESCE(SUM(f.saldo), 0)    AS total_cartera,
            COALESCE(SUM(CASE WHEN f.estado = 'vencida' THEN f.saldo ELSE 0 END), 0) AS cartera_vencida,
            COALESCE(SUM(CASE WHEN f.estado = 'pendiente' THEN f.saldo ELSE 0 END), 0) AS cartera_pendiente,
            COALESCE(SUM(CASE WHEN f.estado = 'parcial' THEN f.saldo ELSE 0 END), 0)  AS cartera_parcial
     FROM facturas f
     JOIN estudiantes e ON e.id = f.estudiante_id
     WHERE $whereSQL",
    $params
);

// Cartera por programa
$porPrograma = $db->fetchAll(
    "SELECT pr.nombre AS programa, pr.facultad,
            COUNT(DISTINCT f.id) AS facturas,
            COUNT(DISTINCT f.estudiante_id) AS estudiantes,
            COALESCE(SUM(f.saldo), 0) AS cartera
     FROM facturas f
     JOIN estudiantes e ON e.id = f.estudiante_id
     JOIN programas pr ON pr.id = e.programa_id
     WHERE $whereSQL AND f.estado IN ('pendiente','parcial','vencida')
     GROUP BY pr.id, pr.nombre, pr.facultad
     ORDER BY cartera DESC",
    $params
);

// Cartera por rango de días vencidos
$porRango = $db->fetchAll(
    "SELECT
        CASE
            WHEN CURRENT_DATE <= f.fecha_vencimiento THEN '0. Vigente'
            WHEN (CURRENT_DATE - f.fecha_vencimiento) BETWEEN 1 AND 30  THEN '1. 1-30 días'
            WHEN (CURRENT_DATE - f.fecha_vencimiento) BETWEEN 31 AND 60 THEN '2. 31-60 días'
            WHEN (CURRENT_DATE - f.fecha_vencimiento) BETWEEN 61 AND 90 THEN '3. 61-90 días'
            ELSE '4. Más de 90 días'
        END AS rango,
        COUNT(*) AS facturas,
        COALESCE(SUM(f.saldo), 0) AS cartera
     FROM facturas f
     JOIN estudiantes e ON e.id = f.estudiante_id
     WHERE $whereSQL AND f.estado IN ('pendiente','parcial','vencida')
     GROUP BY rango
     ORDER BY rango",
    $params
);

// Listado detallado
$detalle = $db->fetchAll(
    "SELECT f.numero_factura, f.fecha_emision, f.fecha_vencimiento,
            f.total, f.saldo, f.estado,
            e.codigo, e.primer_nombre || ' ' || e.primer_apellido AS estudiante,
            e.celular, e.email,
            pr.nombre AS programa,
            p.nombre AS periodo,
            (CURRENT_DATE - f.fecha_vencimiento) AS dias_vencido
     FROM facturas f
     JOIN estudiantes e ON e.id = f.estudiante_id
     JOIN programas pr ON pr.id = e.programa_id
     JOIN periodos p ON p.id = f.periodo_id
     WHERE $whereSQL AND f.estado IN ('pendiente','parcial','vencida')
     ORDER BY f.saldo DESC
     LIMIT 100",
    $params
);

$totalCartera = (float)$resumen['total_cartera'];
?>

<!-- Filtros -->
<div class="card mb-4">
    <div class="card-body" style="padding:.9rem 1.4rem">
        <form method="GET" class="flex gap-3 items-center flex-wrap">
            <select name="periodo" class="form-select" style="width:auto">
                <option value="">Todos los períodos</option>
                <?php foreach ($periodos as $per): ?>
                <option value="<?= $per['id'] ?>" <?= $periodo_id == $per['id'] ? 'selected' : '' ?>><?= e($per['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="programa" class="form-select" style="width:auto">
                <option value="">Todos los programas</option>
                <?php foreach ($programas as $prog): ?>
                <option value="<?= $prog['id'] ?>" <?= $programa_id == $prog['id'] ? 'selected' : '' ?>><?= e($prog['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="estado" class="form-select" style="width:auto">
                <option value="">Todos los estados</option>
                <option value="pendiente" <?= $estado === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                <option value="parcial" <?= $estado === 'parcial' ? 'selected' : '' ?>>Parcial</option>
                <option value="vencida" <?= $estado === 'vencida' ? 'selected' : '' ?>>Vencida</option>
            </select>
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filtrar</button>
            <a href="?" class="btn btn-outline">Limpiar</a>
            <a href="<?= APP_URL ?>/modules/reportes/exportar.php?tipo=cartera&formato=excel<?= $periodo_id ? '&periodo='.$periodo_id : '' ?>" class="btn btn-success btn-sm" style="margin-left:auto">
                <i class="fas fa-file-excel"></i> Excel
            </a>
            <button onclick="window.print()" class="btn btn-outline btn-sm">
                <i class="fas fa-print"></i> Imprimir
            </button>
        </form>
    </div>
</div>

<!-- KPIs cartera -->
<div class="stats-grid mb-4">
    <div class="stat-card">
        <div class="stat-icon primary"><i class="fas fa-file-invoice"></i></div>
        <div>
            <div class="stat-value"><?= number_format($resumen['total_facturas']) ?></div>
            <div class="stat-label"><?= number_format($resumen['total_estudiantes']) ?> estudiantes deudores</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning"><i class="fas fa-wallet"></i></div>
        <div>
            <div class="stat-value"><?= formatoPeso($resumen['total_cartera']) ?></div>
            <div class="stat-label">Cartera Total por Cobrar</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon danger"><i class="fas fa-calendar-times"></i></div>
        <div>
            <div class="stat-value"><?= formatoPeso($resumen['cartera_vencida']) ?></div>
            <div class="stat-label">Cartera Vencida</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon info"><i class="fas fa-hourglass-half"></i></div>
        <div>
            <div class="stat-value"><?= formatoPeso($resumen['cartera_pendiente'] + $resumen['cartera_parcial']) ?></div>
            <div class="stat-label">Cartera Vigente</div>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.2rem;margin-bottom:1.2rem">

    <!-- Por programa -->
    <div class="card">
        <div class="card-header"><h3 class="card-title">Cartera por Programa</h3></div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Programa</th><th>Estudiantes</th><th class="text-right">Cartera</th><th>%</th></tr></thead>
                <tbody>
                    <?php foreach ($porPrograma as $pp): ?>
                    <?php $pct = $totalCartera > 0 ? round(($pp['cartera'] / $totalCartera) * 100, 1) : 0; ?>
                    <tr>
                        <td>
                            <strong style="font-size:.85rem"><?= e($pp['programa']) ?></strong><br>
                            <small style="color:var(--col-muted)"><?= e($pp['facultad']) ?></small>
                        </td>
                        <td class="text-center"><?= $pp['estudiantes'] ?></td>
                        <td class="text-right font-bold" style="color:var(--col-danger)"><?= formatoPeso($pp['cartera']) ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:.4rem">
                                <div style="flex:1;height:6px;background:#e2e6ea;border-radius:3px">
                                    <div style="width:<?= $pct ?>%;height:100%;background:var(--col-danger);border-radius:3px"></div>
                                </div>
                                <span style="font-size:.75rem;width:32px"><?= $pct ?>%</span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Por rango de vencimiento -->
    <div class="card">
        <div class="card-header"><h3 class="card-title">Antigüedad de Cartera</h3></div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Rango</th><th>Facturas</th><th class="text-right">Valor</th><th>%</th></tr></thead>
                <tbody>
                    <?php foreach ($porRango as $rango): ?>
                    <?php $pct = $totalCartera > 0 ? round(($rango['cartera'] / $totalCartera) * 100, 1) : 0; ?>
                    <?php $colores = ['0'=>'var(--col-success)','1'=>'var(--col-warning)','2'=>'var(--col-accent)','3'=>'var(--col-danger)','4'=>'#7f1d1d']; ?>
                    <?php $color = $colores[substr($rango['rango'], 0, 1)] ?? 'var(--col-muted)'; ?>
                    <tr>
                        <td><strong style="font-size:.85rem;color:<?= $color ?>"><?= e(substr($rango['rango'], 3)) ?></strong></td>
                        <td class="text-center"><?= $rango['facturas'] ?></td>
                        <td class="text-right font-bold"><?= formatoPeso($rango['cartera']) ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:.4rem">
                                <div style="flex:1;height:6px;background:#e2e6ea;border-radius:3px">
                                    <div style="width:<?= $pct ?>%;height:100%;background:<?= $color ?>;border-radius:3px"></div>
                                </div>
                                <span style="font-size:.75rem;width:32px"><?= $pct ?>%</span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Detalle -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Detalle de Cartera</h3>
        <small style="color:var(--col-muted)">Mostrando hasta 100 registros · <?= count($detalle) ?> encontrados</small>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Factura</th>
                    <th>Estudiante</th>
                    <th>Programa</th>
                    <th>Período</th>
                    <th class="text-right">Total</th>
                    <th class="text-right">Saldo</th>
                    <th>Vence / Vencido</th>
                    <th>Estado</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($detalle as $row): ?>
                <tr>
                    <td><strong><?= e($row['numero_factura']) ?></strong></td>
                    <td>
                        <?= e($row['estudiante']) ?><br>
                        <small style="color:var(--col-muted)"><?= e($row['codigo']) ?></small>
                    </td>
                    <td><small><?= e($row['programa']) ?></small></td>
                    <td><small><?= e($row['periodo']) ?></small></td>
                    <td class="text-right"><?= formatoPeso($row['total']) ?></td>
                    <td class="text-right font-bold" style="color:var(--col-danger)"><?= formatoPeso($row['saldo']) ?></td>
                    <td>
                        <small><?= formatoFecha($row['fecha_vencimiento']) ?></small><br>
                        <?php if ($row['dias_vencido'] > 0): ?>
                        <small style="color:var(--col-danger);font-weight:600"><?= $row['dias_vencido'] ?> días vencido</small>
                        <?php else: ?>
                        <small style="color:var(--col-success)">Vigente</small>
                        <?php endif; ?>
                    </td>
                    <td><?= estadoBadge($row['estado']) ?></td>
                    <td>
                        <a href="<?= APP_URL ?>/modules/pagos/registrar.php?factura_id=<?= urlencode($row['numero_factura']) ?>" class="btn btn-accent btn-sm">
                            <i class="fas fa-dollar-sign"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($detalle)): ?>
                <tr><td colspan="9" class="text-center text-muted" style="padding:3rem">No hay cartera para los filtros seleccionados</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
