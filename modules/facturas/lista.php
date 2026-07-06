<?php
// modules/facturas/lista.php
$tituloPagina    = 'Liquidaciones';
$subtituloPagina = 'Gestión de liquidaciones de matrícula';
require_once __DIR__ . '/../../includes/header.php';

$db     = Database::getInstance();
$busq   = trim($_GET['q'] ?? '');
$pagina = max(1, (int)($_GET['pagina'] ?? 1));
$estado = $_GET['estado'] ?? '';
$periodo_id = (int)($_GET['periodo'] ?? 0);

$where  = ['1=1'];
$params = [];

if ($busq) {
    $where[] = "(f.numero_factura ILIKE ? OR e.primer_nombre ILIKE ? OR e.primer_apellido ILIKE ? OR e.codigo ILIKE ?)";
    $busqL = "%$busq%";
    $params = array_merge($params, [$busqL, $busqL, $busqL, $busqL]);
}
if ($estado)     { $where[] = 'f.estado = ?'; $params[] = $estado; }
if ($periodo_id) { $where[] = 'f.periodo_id = ?'; $params[] = $periodo_id; }

$whereSQL = implode(' AND ', $where);

$total = (int)$db->fetchValue("SELECT COUNT(*) FROM facturas f JOIN estudiantes e ON e.id=f.estudiante_id WHERE $whereSQL", $params);
$pag   = paginar($total, $pagina);

$facturas = $db->fetchAll(
    "SELECT f.*, p.nombre AS periodo, p.codigo AS periodo_codigo,
            e.primer_nombre || ' ' || e.primer_apellido AS estudiante,
            e.codigo AS est_codigo,
            pr.nombre AS programa
     FROM facturas f
     JOIN periodos p ON p.id = f.periodo_id
     JOIN estudiantes e ON e.id = f.estudiante_id
     JOIN programas pr ON pr.id = e.programa_id
     WHERE $whereSQL
     ORDER BY f.fecha_emision DESC
     LIMIT ? OFFSET ?",
    array_merge($params, [$pag['porPagina'], $pag['offset']])
);

$periodos = $db->fetchAll("SELECT id, nombre FROM periodos ORDER BY fecha_inicio DESC");

// Totalizadores
$totales = $db->fetchOne(
    "SELECT COALESCE(SUM(f.total),0) AS total, COALESCE(SUM(f.saldo),0) AS saldo
     FROM facturas f JOIN estudiantes e ON e.id=f.estudiante_id WHERE $whereSQL AND f.estado != 'anulada'",
    $params
);
?>

<div class="card mb-3">
    <div class="card-body" style="padding:.8rem 1.2rem">
        <form method="GET" class="flex gap-2 flex-wrap items-center">
            <div class="search-bar" style="max-width:280px">
                <i class="fas fa-search"></i>
                <input type="text" name="q" placeholder="Factura, código, nombre..." value="<?= e($busq) ?>">
            </div>
            <select name="periodo" class="form-select" style="width:auto">
                <option value="">Todos los períodos</option>
                <?php foreach ($periodos as $per): ?>
                <option value="<?= $per['id'] ?>" <?= $periodo_id==$per['id']?'selected':'' ?>><?= e($per['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="estado" class="form-select" style="width:auto">
                <option value="">Todos los estados</option>
                <?php foreach (['pendiente','parcial','pagada','vencida','anulada'] as $s): ?>
                <option value="<?= $s ?>" <?= $estado===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filtrar</button>
            <a href="?" class="btn btn-outline">Limpiar</a>
            <?php if (in_array($usuario['rol'], ['admin','financiero','cajero'])): ?>
            <a href="<?= APP_URL ?>/modules/facturas/nueva.php" class="btn btn-accent" style="margin-left:auto">
                <i class="fas fa-plus"></i> Nueva Liquidación
            </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.2rem">
    <div class="stat-card">
        <div class="stat-icon primary"><i class="fas fa-file-invoice-dollar"></i></div>
        <div><div class="stat-value"><?= number_format($pag['total']) ?></div><div class="stat-label">Liquidaciones</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon accent"><i class="fas fa-coins"></i></div>
        <div><div class="stat-value"><?= formatoPeso($totales['total']) ?></div><div class="stat-label">Total Facturado</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning"><i class="fas fa-clock"></i></div>
        <div><div class="stat-value"><?= formatoPeso($totales['saldo']) ?></div><div class="stat-label">Saldo Pendiente</div></div>
    </div>
</div>

<div class="card">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>N° Factura</th><th>Estudiante</th><th>Programa</th><th>Período</th>
                    <th>Emisión</th><th>Vencimiento</th>
                    <th class="text-right">Total</th><th class="text-right">Saldo</th>
                    <th>Estado</th><th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($facturas as $fac): ?>
                <tr>
                    <td><strong><?= e($fac['numero_factura']) ?></strong></td>
                    <td><?= e($fac['estudiante']) ?><br><small style="color:var(--col-muted)"><?= e($fac['est_codigo']) ?></small></td>
                    <td><small><?= e($fac['programa']) ?></small></td>
                    <td><small><?= e($fac['periodo']) ?></small></td>
                    <td><?= formatoFecha($fac['fecha_emision']) ?></td>
                    <td>
                        <?= formatoFecha($fac['fecha_vencimiento']) ?>
                        <?php $dias = diasVencimiento($fac['fecha_vencimiento']); ?>
                        <?php if (!in_array($fac['estado'],['pagada','anulada']) && $dias < 0): ?>
                        <br><small style="color:var(--col-danger)"><?= abs($dias) ?> días</small>
                        <?php endif; ?>
                    </td>
                    <td class="text-right"><?= formatoPeso($fac['total']) ?></td>
                    <td class="text-right font-bold" style="color:<?= $fac['saldo']>0?'var(--col-danger)':'var(--col-success)' ?>"><?= formatoPeso($fac['saldo']) ?></td>
                    <td><?= estadoBadge($fac['estado']) ?></td>
                    <td>
                        <div class="flex gap-2">
                            <a href="<?= APP_URL ?>/modules/facturas/ver.php?id=<?= $fac['id'] ?>" class="btn btn-outline btn-sm"><i class="fas fa-eye"></i></a>
                            <?php if (!in_array($fac['estado'],['pagada','anulada'])): ?>
                            <a href="<?= APP_URL ?>/modules/pagos/registrar.php?factura_id=<?= $fac['id'] ?>" class="btn btn-accent btn-sm"><i class="fas fa-dollar-sign"></i></a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($facturas)): ?>
                <tr><td colspan="10" class="text-center text-muted" style="padding:3rem">Sin liquidaciones encontradas</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pag['totalPaginas'] > 1): ?>
    <div class="pagination">
        <?php if ($pag['hayAnterior']): ?><a href="?pagina=<?= $pag['pagina']-1 ?>&q=<?= urlencode($busq) ?>&estado=<?= $estado ?>&periodo=<?= $periodo_id ?>"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
        <?php for ($i=max(1,$pag['pagina']-2);$i<=min($pag['totalPaginas'],$pag['pagina']+2);$i++): ?>
        <?= $i===$pag['pagina'] ? "<span class='active'>$i</span>" : "<a href='?pagina=$i&q=".urlencode($busq)."&estado=$estado&periodo=$periodo_id'>$i</a>" ?>
        <?php endfor; ?>
        <?php if ($pag['haySiguiente']): ?><a href="?pagina=<?= $pag['pagina']+1 ?>&q=<?= urlencode($busq) ?>&estado=<?= $estado ?>&periodo=<?= $periodo_id ?>"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
