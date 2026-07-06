<?php
// modules/pagos/lista.php
$tituloPagina    = 'Pagos';
$subtituloPagina = 'Historial de pagos registrados';
require_once __DIR__ . '/../../includes/header.php';

$db     = Database::getInstance();
$busq   = trim($_GET['q'] ?? '');
$pagina = max(1, (int)($_GET['pagina'] ?? 1));
$medio  = (int)($_GET['medio'] ?? 0);
$desde  = $_GET['desde'] ?? date('Y-m-01');
$hasta  = $_GET['hasta'] ?? date('Y-m-d');

$where  = ['pg.estado = \'aplicado\''];
$params = [];

if ($busq) {
    $where[] = "(pg.numero_recibo ILIKE ? OR f.numero_factura ILIKE ? OR e.primer_nombre ILIKE ? OR e.primer_apellido ILIKE ? OR e.codigo ILIKE ?)";
    $busqL = "%$busq%";
    $params = array_merge($params, [$busqL, $busqL, $busqL, $busqL, $busqL]);
}
if ($medio)  { $where[] = 'pg.medio_pago_id = ?'; $params[] = $medio; }
if ($desde)  { $where[] = 'DATE(pg.fecha_pago) >= ?'; $params[] = $desde; }
if ($hasta)  { $where[] = 'DATE(pg.fecha_pago) <= ?'; $params[] = $hasta; }

$whereSQL = implode(' AND ', $where);

$total = (int) $db->fetchValue(
    "SELECT COUNT(*) FROM pagos pg JOIN facturas f ON f.id = pg.factura_id JOIN estudiantes e ON e.id = f.estudiante_id WHERE $whereSQL", $params
);

$totalMonto = (float) $db->fetchValue(
    "SELECT COALESCE(SUM(pg.valor),0) FROM pagos pg JOIN facturas f ON f.id = pg.factura_id JOIN estudiantes e ON e.id = f.estudiante_id WHERE $whereSQL", $params
);

$pag = paginar($total, $pagina);

$pagos = $db->fetchAll(
    "SELECT pg.id, pg.numero_recibo, pg.fecha_pago, pg.valor, pg.referencia_bancaria, pg.banco, pg.estado,
            mp.nombre AS medio_pago,
            f.numero_factura, f.id AS factura_id,
            e.primer_nombre || ' ' || e.primer_apellido AS estudiante,
            e.codigo AS estudiante_codigo,
            pr.nombre AS programa,
            u.nombre || ' ' || u.apellido AS cajero
     FROM pagos pg
     JOIN medios_pago mp ON mp.id = pg.medio_pago_id
     JOIN facturas f ON f.id = pg.factura_id
     JOIN estudiantes e ON e.id = f.estudiante_id
     JOIN programas pr ON pr.id = e.programa_id
     JOIN usuarios u ON u.id = pg.registrado_por
     WHERE $whereSQL
     ORDER BY pg.fecha_pago DESC
     LIMIT ? OFFSET ?",
    array_merge($params, [$pag['porPagina'], $pag['offset']])
);

$mediosPago = $db->fetchAll("SELECT * FROM medios_pago WHERE activo=TRUE ORDER BY nombre");

// Resumen por medio de pago en el rango
$resumenMedios = $db->fetchAll(
    "SELECT mp.nombre, COUNT(pg.id) AS cantidad, COALESCE(SUM(pg.valor),0) AS total
     FROM pagos pg
     JOIN medios_pago mp ON mp.id = pg.medio_pago_id
     JOIN facturas f ON f.id = pg.factura_id
     JOIN estudiantes e ON e.id = f.estudiante_id
     WHERE $whereSQL
     GROUP BY mp.nombre ORDER BY total DESC",
    $params
);
?>

<!-- Filtros -->
<div class="card mb-3">
    <div class="card-body" style="padding:.8rem 1.2rem">
        <form method="GET" class="flex gap-2 flex-wrap items-center">
            <div class="search-bar" style="max-width:280px">
                <i class="fas fa-search"></i>
                <input type="text" name="q" placeholder="Recibo, factura, estudiante..." value="<?= e($busq) ?>">
            </div>
            <input type="date" name="desde" class="form-control" style="width:150px" value="<?= e($desde) ?>">
            <span style="color:var(--col-muted);font-size:.85rem">a</span>
            <input type="date" name="hasta" class="form-control" style="width:150px" value="<?= e($hasta) ?>">
            <select name="medio" class="form-select" style="width:auto">
                <option value="">Todos los medios</option>
                <?php foreach ($mediosPago as $mp): ?>
                <option value="<?= $mp['id'] ?>" <?= $medio == $mp['id'] ? 'selected' : '' ?>><?= e($mp['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filtrar</button>
            <a href="?" class="btn btn-outline">Limpiar</a>
            <?php if (in_array($usuario['rol'], ['admin','financiero','cajero'])): ?>
            <a href="<?= APP_URL ?>/modules/pagos/registrar.php" class="btn btn-success" style="margin-left:auto">
                <i class="fas fa-plus"></i> Registrar Pago
            </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Resumen -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1.2rem">
    <div class="stat-card">
        <div class="stat-icon success"><i class="fas fa-receipt"></i></div>
        <div>
            <div class="stat-value"><?= number_format($total) ?></div>
            <div class="stat-label">Pagos en el período</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon accent"><i class="fas fa-money-bill-wave"></i></div>
        <div>
            <div class="stat-value"><?= formatoPeso($totalMonto) ?></div>
            <div class="stat-label">Total recaudado</div>
        </div>
    </div>
    <?php foreach (array_slice($resumenMedios, 0, 2) as $rm): ?>
    <div class="stat-card">
        <div class="stat-icon info"><i class="fas fa-university"></i></div>
        <div>
            <div class="stat-value"><?= formatoPeso($rm['total']) ?></div>
            <div class="stat-label"><?= e($rm['nombre']) ?> (<?= $rm['cantidad'] ?>)</div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Historial de Pagos</h3>
        <small style="color:var(--col-muted)"><?= number_format($pag['total']) ?> registros</small>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>N° Recibo</th>
                    <th>Fecha / Hora</th>
                    <th>Estudiante</th>
                    <th>Factura</th>
                    <th>Medio de Pago</th>
                    <th>Referencia</th>
                    <th>Cajero</th>
                    <th class="text-right">Valor</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pagos as $pg): ?>
                <tr>
                    <td><strong><?= e($pg['numero_recibo']) ?></strong></td>
                    <td>
                        <small><?= formatoFecha($pg['fecha_pago'], 'd/m/Y') ?></small><br>
                        <small style="color:var(--col-muted)"><?= date('H:i', strtotime($pg['fecha_pago'])) ?></small>
                    </td>
                    <td>
                        <?= e($pg['estudiante']) ?><br>
                        <small style="color:var(--col-muted)"><?= e($pg['estudiante_codigo']) ?></small>
                    </td>
                    <td>
                        <a href="<?= APP_URL ?>/modules/facturas/ver.php?id=<?= $pg['factura_id'] ?>" style="font-size:.83rem;color:var(--col-primary)">
                            <?= e($pg['numero_factura']) ?>
                        </a>
                    </td>
                    <td><small><?= e($pg['medio_pago']) ?></small></td>
                    <td><small style="color:var(--col-muted)"><?= e($pg['referencia_bancaria'] ?? '–') ?></small></td>
                    <td><small><?= e($pg['cajero']) ?></small></td>
                    <td class="text-right font-bold" style="color:var(--col-success)"><?= formatoPeso($pg['valor']) ?></td>
                    <td><?= estadoBadge($pg['estado']) ?></td>
                    <td>
                        <div class="flex gap-2">
                            <a href="<?= APP_URL ?>/modules/pagos/recibo.php?id=<?= $pg['id'] ?>" class="btn btn-outline btn-sm" title="Ver recibo">
                                <i class="fas fa-receipt"></i>
                            </a>
                            <?php if ($usuario['rol'] === 'admin' && $pg['estado'] === 'aplicado'): ?>
                            <button class="btn btn-danger btn-sm"
                                    onclick="confirmarReverso(<?= $pg['id'] ?>, '<?= e($pg['numero_recibo']) ?>')"
                                    title="Reversar pago">
                                <i class="fas fa-undo"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($pagos)): ?>
                <tr><td colspan="10" class="text-center text-muted" style="padding:3rem">Sin pagos en el período seleccionado</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Paginación -->
    <?php if ($pag['totalPaginas'] > 1): ?>
    <div class="pagination">
        <?php if ($pag['hayAnterior']): ?>
        <a href="?pagina=<?= $pag['pagina']-1 ?>&q=<?= urlencode($busq) ?>&desde=<?= $desde ?>&hasta=<?= $hasta ?>&medio=<?= $medio ?>"><i class="fas fa-chevron-left"></i></a>
        <?php endif; ?>
        <?php for ($i=max(1,$pag['pagina']-2); $i<=min($pag['totalPaginas'],$pag['pagina']+2); $i++): ?>
        <?= $i===$pag['pagina'] ? "<span class='active'>$i</span>" : "<a href='?pagina=$i&q=".urlencode($busq)."&desde=$desde&hasta=$hasta&medio=$medio'>$i</a>" ?>
        <?php endfor; ?>
        <?php if ($pag['haySiguiente']): ?>
        <a href="?pagina=<?= $pag['pagina']+1 ?>&q=<?= urlencode($busq) ?>&desde=<?= $desde ?>&hasta=<?= $hasta ?>&medio=<?= $medio ?>"><i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Modal reverso -->
<div class="modal-overlay" id="modalReverso">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-undo" style="color:var(--col-danger)"></i> Reversar Pago</h3>
            <button class="modal-close"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="<?= APP_URL ?>/modules/pagos/reversar.php">
            <?= csrfField() ?>
            <input type="hidden" name="pago_id" id="reversoPagoId">
            <div class="modal-body">
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> Esta acción revertirá el pago y actualizará el saldo de la factura.</div>
                <p style="font-size:.88rem;margin-bottom:.8rem">Reversando el recibo: <strong id="reversoRecibo"></strong></p>
                <div class="form-group">
                    <label>Motivo del Reverso *</label>
                    <textarea name="motivo" class="form-control" rows="3" required placeholder="Indique el motivo..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline modal-close">Cancelar</button>
                <button type="submit" class="btn btn-danger"><i class="fas fa-undo"></i> Confirmar Reverso</button>
            </div>
        </form>
    </div>
</div>

<script>
function confirmarReverso(id, recibo) {
    document.getElementById('reversoPagoId').value = id;
    document.getElementById('reversoRecibo').textContent = recibo;
    document.getElementById('modalReverso').classList.add('open');
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
