<?php
// modules/reportes/estadistico_matriculados.php
$tituloPagina    = 'Estadístico de Matriculados';
$subtituloPagina = 'Nuevos, reintegros, transferencias, ciclo propedéutico — Requerimiento 1';
require_once __DIR__ . '/../../includes/header.php';
requireRol(['admin', 'financiero']);

$db = Database::getInstance();

$periodo_id  = (int)($_GET['periodo']  ?? 0);
$programa_id = (int)($_GET['programa'] ?? 0);

$where  = ['1=1']; $params = [];
if ($periodo_id)  {
    $where[] = 'EXISTS (SELECT 1 FROM facturas f WHERE f.estudiante_id=e.id AND f.periodo_id=?)';
    $params[] = $periodo_id;
}
if ($programa_id) { $where[] = 'e.programa_id=?'; $params[] = $programa_id; }
$wSQL = implode(' AND ', $where);

// Resumen por tipo de admisión
$porTipo = $db->fetchAll(
    "SELECT
        COALESCE(e.tipo_admision, 'nuevo') AS tipo,
        COUNT(*) AS total,
        COUNT(*) FILTER(WHERE e.sexo_biologico='F' OR e.sexo_biologico IS NULL) AS mujeres,
        COUNT(*) FILTER(WHERE e.sexo_biologico='M') AS hombres
     FROM estudiantes e WHERE e.estado='activo' AND $wSQL
     GROUP BY tipo ORDER BY total DESC",
    $params
);

// Resumen por programa y tipo
$porPrograma = $db->fetchAll(
    "SELECT pr.nombre AS programa, pr.nivel,
            COALESCE(e.tipo_admision,'nuevo') AS tipo,
            COUNT(*) AS total
     FROM estudiantes e
     JOIN programas pr ON pr.id=e.programa_id
     WHERE e.estado='activo' AND $wSQL
     GROUP BY pr.nombre, pr.nivel, tipo
     ORDER BY pr.nombre, tipo",
    $params
);

// Opciones de grado
$porGrado = $db->fetchAll(
    "SELECT COALESCE(e.opcion_grado,'no_definido') AS opcion, COUNT(*) AS total
     FROM estudiantes e WHERE e.estado='activo' AND $wSQL
     GROUP BY opcion",
    $params
);

// Detalle
$detalle = $db->fetchAll(
    "SELECT e.codigo, e.primer_nombre||' '||e.primer_apellido AS nombre,
            e.tipo_documento, e.numero_documento,
            e.email, e.celular, e.estrato,
            COALESCE(e.tipo_admision,'nuevo') AS tipo_admision,
            COALESCE(e.opcion_grado,'–') AS opcion_grado,
            pr.nombre AS programa, pr.nivel,
            e.semestre_actual, e.fecha_ingreso, e.estado
     FROM estudiantes e
     LEFT JOIN programas pr ON pr.id=e.programa_id
     WHERE e.estado='activo' AND $wSQL
     ORDER BY pr.nombre, e.tipo_admision, e.primer_apellido",
    $params
);

$periodos  = $db->fetchAll("SELECT id, nombre FROM periodos ORDER BY fecha_inicio DESC");
$programas = $db->fetchAll("SELECT id, nombre FROM programas WHERE activo=TRUE ORDER BY nombre");
$totalEst  = array_sum(array_column($porTipo, 'total'));

$tiposLabel = [
    'nuevo'                => ['Nuevos',                      'success',   'user-plus'],
    'reintegro'            => ['Reintegros',                  'info',      'redo'],
    'transferencia_externa'=> ['Transferencias Externas',     'warning',   'exchange-alt'],
    'transferencia_interna'=> ['Transferencias Internas',     'accent',    'random'],
    'ciclo_propedeutico'   => ['Ciclo Propedéutico',          'primary',   'graduation-cap'],
    'otro'                 => ['Otros',                       'secondary', 'user'],
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
    <a href="<?= APP_URL ?>/modules/reportes/exportar.php?tipo=matriculados&periodo=<?= $periodo_id ?>&programa=<?= $programa_id ?>&formato=excel" class="btn btn-success btn-sm" style="margin-left:auto"><i class="fas fa-file-excel"></i> Excel</a>
    <button type="button" onclick="window.print()" class="btn btn-outline btn-sm"><i class="fas fa-print"></i> Imprimir</button>
</form></div></div>

<!-- KPIs por tipo de admisión -->
<div class="stats-grid mb-4" style="grid-template-columns:repeat(<?= max(3,count($porTipo)) ?>,1fr)">
<?php foreach($porTipo as $t):
    [$lbl,$col,$ico] = $tiposLabel[$t['tipo']] ?? ['Otro','secondary','user'];
    $pct = $totalEst > 0 ? round($t['total']/$totalEst*100,1) : 0;
?>
<div class="stat-card" style="flex-direction:column;align-items:flex-start">
    <div style="display:flex;align-items:center;gap:.6rem;width:100%">
        <div class="stat-icon <?= $col ?>" style="width:38px;height:38px;font-size:.85rem"><i class="fas fa-<?= $ico ?>"></i></div>
        <div>
            <div class="stat-value" style="font-size:1.3rem"><?= number_format($t['total']) ?></div>
            <div class="stat-label" style="font-size:.72rem"><?= $lbl ?></div>
        </div>
    </div>
    <div style="width:100%;margin-top:.5rem">
        <div style="display:flex;justify-content:space-between;font-size:.7rem;color:var(--col-muted)"><span><?= $pct ?>% del total</span></div>
        <div style="background:var(--col-border);border-radius:20px;height:4px;margin-top:.2rem"><div style="width:<?= $pct ?>%;height:100%;background:var(--col-<?= $col ?>);border-radius:20px"></div></div>
    </div>
</div>
<?php endforeach; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.2rem;margin-bottom:1.2rem">

<!-- Por programa -->
<div class="card">
    <div class="card-header"><h3 class="card-title">Por Programa y Tipo de Admisión</h3></div>
    <div class="table-wrapper"><table>
        <thead><tr><th>Programa</th><th>Nivel</th><th>Tipo Admisión</th><th style="text-align:center">Total</th></tr></thead>
        <tbody>
        <?php foreach($porPrograma as $pp):
            [$lbl,$col,] = $tiposLabel[$pp['tipo']] ?? ['Otro','secondary','user'];
        ?>
        <tr>
            <td style="font-size:.83rem"><?= e($pp['programa']) ?></td>
            <td><span class="badge badge-secondary" style="font-size:.65rem"><?= ucfirst($pp['nivel']) ?></span></td>
            <td><span class="badge badge-<?= $col ?>" style="font-size:.7rem"><?= $lbl ?></span></td>
            <td style="text-align:center;font-weight:700"><?= $pp['total'] ?></td>
        </tr>
        <?php endforeach; ?>
        <tr style="background:rgba(26,58,92,.06);font-weight:700">
            <td colspan="3">TOTAL MATRICULADOS ACTIVOS</td>
            <td style="text-align:center;font-size:1rem"><?= number_format($totalEst) ?></td>
        </tr>
        </tbody>
    </table></div>
</div>

<!-- Por opción de grado -->
<div class="card">
    <div class="card-header"><h3 class="card-title">Opciones de Grado</h3></div>
    <div class="card-body">
        <?php
        $gradoLabel = ['por_programa'=>'Por Programa Académico','por_semestre'=>'Por Semestre','otro'=>'Otro','no_definido'=>'No Definido'];
        foreach($porGrado as $g):
            $pct = $totalEst > 0 ? round($g['total']/$totalEst*100,1) : 0;
        ?>
        <div style="margin-bottom:.8rem">
            <div style="display:flex;justify-content:space-between;font-size:.84rem;margin-bottom:.2rem">
                <span><?= $gradoLabel[$g['opcion']] ?? $g['opcion'] ?></span>
                <span class="font-bold"><?= $g['total'] ?> (<?= $pct ?>%)</span>
            </div>
            <div style="background:var(--col-border);border-radius:20px;height:8px">
                <div style="width:<?= $pct ?>%;height:100%;background:var(--col-primary);border-radius:20px"></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
</div>

<!-- Detalle -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-list" style="color:var(--col-accent)"></i> Listado de Matriculados</h3>
        <small style="color:var(--col-muted)"><?= count($detalle) ?> estudiantes activos</small>
    </div>
    <div class="table-wrapper"><table>
        <thead><tr>
            <th>Código</th><th>Nombre</th><th>Documento</th><th>Programa</th>
            <th>Nivel</th><th>Semestre</th><th>Tipo Admisión</th><th>Opción Grado</th>
            <th>Estrato</th><th>Email</th><th>Celular</th>
        </tr></thead>
        <tbody>
        <?php foreach($detalle as $d):
            [$lbl,$col,] = $tiposLabel[$d['tipo_admision']] ?? ['Otro','secondary','user'];
        ?>
        <tr>
            <td><strong><?= e($d['codigo']) ?></strong></td>
            <td><?= e($d['nombre']) ?></td>
            <td><small><?= e($d['tipo_documento'].' '.$d['numero_documento']) ?></small></td>
            <td><small><?= e($d['programa']??'—') ?></small></td>
            <td><span class="badge badge-secondary" style="font-size:.65rem"><?= ucfirst($d['nivel']??'—') ?></span></td>
            <td style="text-align:center"><?= $d['semestre_actual'] ?></td>
            <td><span class="badge badge-<?= $col ?>" style="font-size:.68rem"><?= $lbl ?></span></td>
            <td><small><?= e($gradoLabel[$d['opcion_grado']] ?? $d['opcion_grado']) ?></small></td>
            <td style="text-align:center"><?= $d['estrato'] ?? '—' ?></td>
            <td><small><?= e($d['email']) ?></small></td>
            <td><small><?= e($d['celular']??'') ?></small></td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($detalle)): ?><tr><td colspan="11" class="text-center text-muted" style="padding:3rem">Sin estudiantes para los filtros seleccionados</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
