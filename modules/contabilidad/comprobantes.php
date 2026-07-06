<?php
// modules/contabilidad/comprobantes.php
$tituloPagina    = 'Comprobantes Contables';
$subtituloPagina = 'Registro de asientos — Partida Doble';
require_once __DIR__ . '/../../includes/header.php';
requireRol(['admin', 'financiero']);

$db = Database::getInstance();

$busq   = trim($_GET['q']     ?? '');
$tipo   = $_GET['tipo']       ?? '';
$estado = $_GET['estado']     ?? '';
$desde  = $_GET['desde']      ?? date('Y-m-01');
$hasta  = $_GET['hasta']      ?? date('Y-m-d');
$pagina = max(1, (int)($_GET['pagina'] ?? 1));

$where  = ['1=1'];
$params = [];
if ($busq) {
    $where[]  = '(c.numero ILIKE ? OR c.descripcion ILIKE ?)';
    $params    = array_merge($params, ["%$busq%", "%$busq%"]);
}
if ($tipo)   { $where[] = 'c.tipo = ?';    $params[] = $tipo; }
if ($estado) { $where[] = 'c.estado = ?';  $params[] = $estado; }
if ($desde)  { $where[] = 'c.fecha >= ?';  $params[] = $desde; }
if ($hasta)  { $where[] = 'c.fecha <= ?';  $params[] = $hasta; }
$wSQL = implode(' AND ', $where);

$total = (int) $db->fetchValue("SELECT COUNT(*) FROM comprobantes c WHERE $wSQL", $params);
$pag   = paginar($total, $pagina);

$comprobantes = $db->fetchAll(
    "SELECT c.*,
            u.nombre||' '||u.apellido AS elaborado_nombre,
            (SELECT COUNT(*) FROM movimientos_contables WHERE comprobante_id=c.id) AS num_lineas
     FROM comprobantes c
     LEFT JOIN usuarios u ON u.id = c.elaborado_por
     WHERE $wSQL
     ORDER BY c.fecha DESC, c.numero DESC
     LIMIT ? OFFSET ?",
    array_merge($params, [$pag['porPagina'], $pag['offset']])
);

$tots = $db->fetchOne(
    "SELECT COALESCE(SUM(total_debitos),0)  AS deb,
            COALESCE(SUM(total_creditos),0) AS cre,
            COUNT(*) FILTER(WHERE estado='contabilizado') AS cont,
            COUNT(*) FILTER(WHERE estado='borrador')      AS borra,
            COUNT(*) FILTER(WHERE estado='anulado')       AS anu
     FROM comprobantes c WHERE $wSQL",
    $params
);

$tiposLabel = [
    'comprobante_ingreso' => ['CI', 'Comp. Ingreso',  'success'],
    'comprobante_egreso'  => ['CE', 'Comp. Egreso',   'danger'],
    'nota_contable'       => ['NC', 'Nota Contable',  'info'],
    'causacion'           => ['CA', 'Causación',      'warning'],
    'ajuste'              => ['AJ', 'Ajuste',          'accent'],
    'apertura'            => ['AP', 'Apertura',        'primary'],
    'cierre'              => ['CL', 'Cierre',          'secondary'],
];
?>

<!-- KPIs -->
<div class="stats-grid mb-4" style="grid-template-columns:repeat(5,1fr)">
    <?php
    $kpis = [
        ['Total',           $total,          'file-alt',      'primary', false],
        ['Contabilizados',  $tots['cont'],   'check-double',  'success', false],
        ['Borradores',      $tots['borra'],  'pencil-alt',    'warning', false],
        ['Total Débitos',   $tots['deb'],    'arrow-up',      'info',    true],
        ['Total Créditos',  $tots['cre'],    'arrow-down',    'accent',  true],
    ];
    foreach ($kpis as [$lbl, $val, $ico, $col, $esMonto]): ?>
    <div class="stat-card">
        <div class="stat-icon <?= $col ?>" style="width:40px;height:40px;font-size:.9rem"><i class="fas fa-<?= $ico ?>"></i></div>
        <div>
            <div class="stat-value" style="font-size:<?= $esMonto ? '1rem' : '1.2rem' ?>">
                <?= $esMonto ? formatoPeso($val) : number_format($val) ?>
            </div>
            <div class="stat-label"><?= $lbl ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Filtros -->
<div class="card mb-3">
    <div class="card-body" style="padding:.8rem 1.2rem">
        <form method="GET" class="flex gap-2 flex-wrap items-center">
            <div class="search-bar" style="max-width:240px">
                <i class="fas fa-search"></i>
                <input type="text" name="q" placeholder="N° comprobante o descripción..." value="<?= e($busq) ?>">
            </div>
            <select name="tipo" class="form-select" style="width:auto">
                <option value="">Todos los tipos</option>
                <?php foreach ($tiposLabel as $k => [,$lbl,]): ?>
                <option value="<?= $k ?>" <?= $tipo === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
            </select>
            <select name="estado" class="form-select" style="width:auto">
                <option value="">Todos los estados</option>
                <option value="contabilizado" <?= $estado === 'contabilizado' ? 'selected' : '' ?>>Contabilizado</option>
                <option value="borrador"      <?= $estado === 'borrador'      ? 'selected' : '' ?>>Borrador</option>
                <option value="anulado"       <?= $estado === 'anulado'       ? 'selected' : '' ?>>Anulado</option>
            </select>
            <div class="flex items-center gap-2">
                <input type="date" name="desde" class="form-control" style="width:145px" value="<?= e($desde) ?>">
                <span style="color:var(--col-muted)">–</span>
                <input type="date" name="hasta" class="form-control" style="width:145px" value="<?= e($hasta) ?>">
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Filtrar</button>
            <a href="?" class="btn btn-outline btn-sm">Limpiar</a>
            <a href="<?= APP_URL ?>/modules/contabilidad/nuevo_comprobante.php"
               class="btn btn-accent btn-sm" style="margin-left:auto">
                <i class="fas fa-plus"></i> Nuevo Comprobante
            </a>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-book-open" style="color:var(--col-accent)"></i> Comprobantes del Período</h3>
        <small style="color:var(--col-muted)"><?= number_format($total) ?> registros</small>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>N° Comprobante</th>
                    <th>Tipo</th>
                    <th>Fecha</th>
                    <th>Período</th>
                    <th>Descripción</th>
                    <th style="text-align:center">Líneas</th>
                    <th class="text-right">Débitos</th>
                    <th class="text-right">Créditos</th>
                    <th style="text-align:center">Cuadra</th>
                    <th>Elaboró</th>
                    <th>Estado</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($comprobantes as $comp):
                [$pref, $lbl, $col] = $tiposLabel[$comp['tipo']] ?? ['??', 'Otro', 'secondary'];
                $cuadra = abs($comp['total_debitos'] - $comp['total_creditos']) < 0.01;
            ?>
            <tr style="<?= $comp['estado'] === 'anulado' ? 'opacity:.4' : '' ?>">
                <td>
                    <a href="<?= APP_URL ?>/modules/contabilidad/ver_comprobante.php?id=<?= $comp['id'] ?>"
                       style="font-family:monospace;font-size:.88rem;font-weight:700;color:var(--col-primary)">
                        <?= e($comp['numero']) ?>
                    </a>
                </td>
                <td><span class="badge badge-<?= $col ?>" style="font-size:.68rem"><?= $lbl ?></span></td>
                <td><?= formatoFecha($comp['fecha']) ?></td>
                <td><code style="font-size:.8rem"><?= e($comp['periodo_contable']) ?></code></td>
                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <small><?= e($comp['descripcion']) ?></small>
                </td>
                <td style="text-align:center"><small><?= $comp['num_lineas'] ?></small></td>
                <td class="text-right" style="color:var(--col-info);font-weight:600;font-size:.88rem">
                    <?= formatoPeso($comp['total_debitos']) ?>
                </td>
                <td class="text-right" style="color:var(--col-success);font-weight:600;font-size:.88rem">
                    <?= formatoPeso($comp['total_creditos']) ?>
                </td>
                <td style="text-align:center">
                    <?php if ($comp['total_debitos'] + $comp['total_creditos'] == 0): ?>
                    <span style="color:var(--col-muted)">–</span>
                    <?php elseif ($cuadra): ?>
                    <i class="fas fa-check-circle" style="color:var(--col-success)" title="Cuadra"></i>
                    <?php else: ?>
                    <i class="fas fa-exclamation-triangle" style="color:var(--col-danger)" title="No cuadra: diferencia <?= formatoPeso(abs($comp['total_debitos']-$comp['total_creditos'])) ?>"></i>
                    <?php endif; ?>
                </td>
                <td><small><?= e($comp['elaborado_nombre'] ?? '–') ?></small></td>
                <td>
                    <?php if ($comp['estado'] === 'contabilizado'): ?>
                    <span class="badge badge-success">Contabilizado</span>
                    <?php elseif ($comp['estado'] === 'borrador'): ?>
                    <span class="badge badge-warning">Borrador</span>
                    <?php else: ?>
                    <span class="badge badge-secondary">Anulado</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="flex gap-1">
                        <a href="<?= APP_URL ?>/modules/contabilidad/ver_comprobante.php?id=<?= $comp['id'] ?>"
                           class="btn btn-outline btn-sm" title="Ver detalle">
                            <i class="fas fa-eye"></i>
                        </a>
                        <a href="<?= APP_URL ?>/modules/contabilidad/ver_comprobante.php?id=<?= $comp['id'] ?>&imprimir=1"
                           target="_blank" class="btn btn-outline btn-sm" title="Imprimir">
                            <i class="fas fa-print"></i>
                        </a>
                        <?php if ($comp['estado'] === 'borrador'): ?>
                        <a href="<?= APP_URL ?>/modules/contabilidad/editar_comprobante.php?id=<?= $comp['id'] ?>"
                           class="btn btn-outline btn-sm" title="Editar">
                            <i class="fas fa-edit"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($comprobantes)): ?>
            <tr>
                <td colspan="12" class="text-center text-muted" style="padding:3rem">
                    <i class="fas fa-file-invoice" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.3"></i>
                    Sin comprobantes en el período seleccionado
                </td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Paginación -->
    <?php if ($pag['totalPaginas'] > 1): ?>
    <div class="pagination">
        <?php if ($pag['hayAnterior']): ?>
        <a href="?pagina=<?= $pag['pagina']-1 ?>&q=<?= urlencode($busq) ?>&tipo=<?= $tipo ?>&estado=<?= $estado ?>&desde=<?= $desde ?>&hasta=<?= $hasta ?>">
            <i class="fas fa-chevron-left"></i>
        </a>
        <?php endif; ?>
        <?php for ($i = max(1,$pag['pagina']-2); $i <= min($pag['totalPaginas'],$pag['pagina']+2); $i++): ?>
        <?php if ($i === $pag['pagina']): ?>
            <span class="active"><?= $i ?></span>
        <?php else: ?>
            <a href="?pagina=<?= $i ?>&q=<?= urlencode($busq) ?>&tipo=<?= $tipo ?>&estado=<?= $estado ?>&desde=<?= $desde ?>&hasta=<?= $hasta ?>"><?= $i ?></a>
        <?php endif; ?>
        <?php endfor; ?>
        <?php if ($pag['haySiguiente']): ?>
        <a href="?pagina=<?= $pag['pagina']+1 ?>&q=<?= urlencode($busq) ?>&tipo=<?= $tipo ?>&estado=<?= $estado ?>&desde=<?= $desde ?>&hasta=<?= $hasta ?>">
            <i class="fas fa-chevron-right"></i>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
