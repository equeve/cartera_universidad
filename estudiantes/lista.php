<?php
// modules/estudiantes/lista.php
$tituloPagina    = 'Estudiantes';
$subtituloPagina = 'Gestión de estudiantes matriculados';
require_once __DIR__ . '/../../includes/header.php';

$db    = Database::getInstance();
$busq  = trim($_GET['q'] ?? '');
$pagina = max(1, (int)($_GET['pagina'] ?? 1));
$estado = $_GET['estado'] ?? '';
$programa_id = (int)($_GET['programa'] ?? 0);

$where  = ['1=1'];
$params = [];

if ($busq) {
    $where[] = "(e.codigo ILIKE ? OR e.primer_nombre ILIKE ? OR e.primer_apellido ILIKE ? OR e.numero_documento ILIKE ? OR e.email ILIKE ?)";
    $busqLike = "%$busq%";
    $params = array_merge($params, [$busqLike, $busqLike, $busqLike, $busqLike, $busqLike]);
}
if ($estado) { $where[] = 'e.estado = ?'; $params[] = $estado; }
if ($programa_id) { $where[] = 'e.programa_id = ?'; $params[] = $programa_id; }

$whereSQL = implode(' AND ', $where);

$total = (int) $db->fetchValue(
    "SELECT COUNT(*) FROM estudiantes e WHERE $whereSQL", $params
);

$pag = paginar($total, $pagina);

$estudiantes = $db->fetchAll(
    "SELECT e.*, p.nombre AS programa, p.facultad,
            COALESCE(f.saldo_total, 0) AS saldo_pendiente,
            COALESCE(f.num_facturas, 0) AS num_facturas
     FROM estudiantes e
     LEFT JOIN programas p ON p.id = e.programa_id
     LEFT JOIN (
         SELECT estudiante_id,
                SUM(CASE WHEN estado IN ('pendiente','parcial','vencida') THEN saldo ELSE 0 END) AS saldo_total,
                COUNT(*) AS num_facturas
         FROM facturas WHERE estado != 'anulada'
         GROUP BY estudiante_id
     ) f ON f.estudiante_id = e.id
     WHERE $whereSQL
     ORDER BY e.primer_apellido, e.primer_nombre
     LIMIT ? OFFSET ?",
    array_merge($params, [$pag['porPagina'], $pag['offset']])
);

$programas = $db->fetchAll("SELECT id, nombre FROM programas WHERE activo = TRUE ORDER BY nombre");
?>

<div class="card">
    <div class="card-header">
        <div class="flex items-center gap-3">
            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Buscar por nombre, código, documento..."
                       value="<?= e($busq) ?>">
            </div>
            <form method="GET" class="flex gap-2">
                <select name="estado" class="form-select" style="width:auto" onchange="this.form.submit()">
                    <option value="">Todos los estados</option>
                    <?php foreach (['activo','inactivo','graduado','retirado','suspendido'] as $s): ?>
                    <option value="<?= $s ?>" <?= $estado === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="programa" class="form-select" style="width:auto" onchange="this.form.submit()">
                    <option value="">Todos los programas</option>
                    <?php foreach ($programas as $prog): ?>
                    <option value="<?= $prog['id'] ?>" <?= $programa_id === (int)$prog['id'] ? 'selected' : '' ?>><?= e($prog['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <?php if (in_array($usuario['rol'], ['admin','financiero'])): ?>
        <a href="<?= APP_URL ?>/modules/estudiantes/form.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> Nuevo Estudiante
        </a>
        <?php endif; ?>
    </div>

    <div style="padding:.6rem 1.4rem;border-bottom:1px solid var(--col-border);font-size:.82rem;color:var(--col-muted)">
        <?= number_format($pag['total']) ?> estudiantes encontrados
    </div>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Estudiante</th>
                    <th>Programa</th>
                    <th>Semestre</th>
                    <th>Contacto</th>
                    <th class="text-right">Saldo Pendiente</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($estudiantes as $est): ?>
                <tr>
                    <td><strong><?= e($est['codigo']) ?></strong></td>
                    <td>
                        <strong><?= e($est['primer_nombre'] . ' ' . ($est['segundo_nombre'] ?? '') . ' ' . $est['primer_apellido'] . ' ' . ($est['segundo_apellido'] ?? '')) ?></strong><br>
                        <small style="color:var(--col-muted)"><?= e($est['tipo_documento']) ?> <?= e($est['numero_documento']) ?></small>
                    </td>
                    <td>
                        <small><?= e($est['programa'] ?? '–') ?></small><br>
                        <small style="color:var(--col-muted)"><?= e($est['facultad'] ?? '') ?></small>
                    </td>
                    <td class="text-center"><?= $est['semestre_actual'] ?? '–' ?></td>
                    <td>
                        <small><?= e($est['email']) ?></small><br>
                        <small style="color:var(--col-muted)"><?= e($est['celular'] ?? '') ?></small>
                    </td>
                    <td class="text-right">
                        <?php if ($est['saldo_pendiente'] > 0): ?>
                        <span style="color:var(--col-danger);font-weight:700"><?= formatoPeso($est['saldo_pendiente']) ?></span>
                        <?php else: ?>
                        <span style="color:var(--col-success)">Al día</span>
                        <?php endif; ?>
                    </td>
                    <td><?= estadoBadge($est['estado']) ?></td>
                    <td>
                        <div class="flex gap-2">
                            <a href="<?= APP_URL ?>/modules/estudiantes/ver.php?id=<?= $est['id'] ?>" class="btn btn-outline btn-sm" title="Ver detalle">
                                <i class="fas fa-eye"></i>
                            </a>
                            <?php if (in_array($usuario['rol'], ['admin','financiero'])): ?>
                            <a href="<?= APP_URL ?>/modules/estudiantes/form.php?id=<?= $est['id'] ?>" class="btn btn-outline btn-sm" title="Editar">
                                <i class="fas fa-edit"></i>
                            </a>
                            <?php endif; ?>
                            <?php if (in_array($usuario['rol'], ['admin','financiero','cajero'])): ?>
                            <a href="<?= APP_URL ?>/modules/facturas/nueva.php?estudiante_id=<?= $est['id'] ?>" class="btn btn-accent btn-sm" title="Nueva liquidación">
                                <i class="fas fa-file-invoice-dollar"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($estudiantes)): ?>
                <tr>
                    <td colspan="8" class="text-center text-muted" style="padding:3rem">
                        <i class="fas fa-user-graduate" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.3"></i>
                        No se encontraron estudiantes
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
        <a href="?pagina=<?= $pag['pagina'] - 1 ?>&q=<?= urlencode($busq) ?>&estado=<?= urlencode($estado) ?>&programa=<?= $programa_id ?>">
            <i class="fas fa-chevron-left"></i>
        </a>
        <?php endif; ?>
        <?php for ($i = max(1, $pag['pagina'] - 2); $i <= min($pag['totalPaginas'], $pag['pagina'] + 2); $i++): ?>
        <?php if ($i === $pag['pagina']): ?>
        <span class="active"><?= $i ?></span>
        <?php else: ?>
        <a href="?pagina=<?= $i ?>&q=<?= urlencode($busq) ?>&estado=<?= urlencode($estado) ?>&programa=<?= $programa_id ?>"><?= $i ?></a>
        <?php endif; ?>
        <?php endfor; ?>
        <?php if ($pag['haySiguiente']): ?>
        <a href="?pagina=<?= $pag['pagina'] + 1 ?>&q=<?= urlencode($busq) ?>&estado=<?= urlencode($estado) ?>&programa=<?= $programa_id ?>">
            <i class="fas fa-chevron-right"></i>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
