<?php
// modules/configuracion/periodos.php
$tituloPagina    = 'Períodos Académicos';
$subtituloPagina = 'Configuración de períodos';
require_once __DIR__ . '/../../includes/header.php';
requireRol(['admin']);

$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarCSRF();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear' || $accion === 'editar') {
        $pid      = (int)($_POST['periodo_id'] ?? 0);
        $codigo   = strtoupper(trim($_POST['codigo'] ?? ''));
        $nombre   = trim($_POST['nombre'] ?? '');
        $inicio   = $_POST['fecha_inicio'] ?? '';
        $fin      = $_POST['fecha_fin'] ?? '';
        $vence    = $_POST['fecha_vencimiento_pago'] ?? '';
        $activo   = isset($_POST['activo']);

        if ($codigo && $nombre && $inicio && $fin && $vence) {
            try {
                if ($activo) {
                    $db->query("UPDATE periodos SET activo = FALSE");
                }
                if ($accion === 'crear') {
                    $db->query(
                        "INSERT INTO periodos (codigo,nombre,fecha_inicio,fecha_fin,fecha_vencimiento_pago,activo) VALUES (?,?,?,?,?,?)",
                        [$codigo, $nombre, $inicio, $fin, $vence, $activo ? 'true' : 'false']
                    );
                    $_SESSION['flash_success'] = 'Período creado correctamente.';
                } else {
                    $db->query(
                        "UPDATE periodos SET codigo=?,nombre=?,fecha_inicio=?,fecha_fin=?,fecha_vencimiento_pago=?,activo=? WHERE id=?",
                        [$codigo, $nombre, $inicio, $fin, $vence, $activo ? 'true' : 'false', $pid]
                    );
                    $_SESSION['flash_success'] = 'Período actualizado.';
                }
            } catch (Exception $e) {
                $_SESSION['flash_error'] = 'Error: ' . $e->getMessage();
            }
        } else {
            $_SESSION['flash_error'] = 'Todos los campos son obligatorios.';
        }
        header('Location: ' . APP_URL . '/modules/configuracion/periodos.php');
        exit;
    }
}

$periodos = $db->fetchAll(
    "SELECT p.*, 
            (SELECT COUNT(*) FROM facturas WHERE periodo_id = p.id) AS num_facturas,
            (SELECT COALESCE(SUM(total),0) FROM facturas WHERE periodo_id = p.id AND estado != 'anulada') AS total_facturado
     FROM periodos p ORDER BY fecha_inicio DESC"
);
?>

<div class="flex gap-2 mb-4">
    <button data-modal="modalPeriodo" class="btn btn-primary"><i class="fas fa-plus"></i> Nuevo Período</button>
</div>

<div class="card">
    <div class="card-header"><h3 class="card-title">Períodos Académicos Registrados</h3></div>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Código</th><th>Nombre</th><th>Inicio</th><th>Fin</th><th>Venc. Pago</th><th>Facturas</th><th>Facturado</th><th>Estado</th><th>Acción</th></tr></thead>
            <tbody>
                <?php foreach ($periodos as $per): ?>
                <tr>
                    <td><strong><?= e($per['codigo']) ?></strong></td>
                    <td><?= e($per['nombre']) ?></td>
                    <td><?= formatoFecha($per['fecha_inicio']) ?></td>
                    <td><?= formatoFecha($per['fecha_fin']) ?></td>
                    <td><?= formatoFecha($per['fecha_vencimiento_pago']) ?></td>
                    <td class="text-center"><?= $per['num_facturas'] ?></td>
                    <td><?= formatoPeso($per['total_facturado']) ?></td>
                    <td>
                        <?php if ($per['activo']): ?>
                        <span class="badge badge-success"><i class="fas fa-circle" style="font-size:.5rem"></i> Activo</span>
                        <?php else: ?>
                        <span class="badge badge-secondary">Inactivo</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button class="btn btn-outline btn-sm"
                                onclick="editarPeriodo(<?= htmlspecialchars(json_encode($per), ENT_QUOTES) ?>)">
                            <i class="fas fa-edit"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal período -->
<div class="modal-overlay" id="modalPeriodo">
    <div class="modal" style="max-width:520px">
        <div class="modal-header">
            <h3 class="modal-title" id="modalPeriodoTitulo"><i class="fas fa-calendar-alt" style="color:var(--col-accent)"></i> Nuevo Período</h3>
            <button class="modal-close"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="accion" id="periodoAccion" value="crear">
            <input type="hidden" name="periodo_id" id="periodoId" value="0">
            <div class="modal-body">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label>Código * <small style="color:var(--col-muted)">(Ej: 2025-2)</small></label>
                        <input type="text" name="codigo" id="pCodigo" class="form-control" required placeholder="2025-2">
                    </div>
                    <div class="form-group">
                        <label>Nombre *</label>
                        <input type="text" name="nombre" id="pNombre" class="form-control" required placeholder="Segundo Semestre 2025">
                    </div>
                </div>
                <div class="form-row cols-3">
                    <div class="form-group"><label>Fecha Inicio *</label><input type="date" name="fecha_inicio" id="pInicio" class="form-control" required></div>
                    <div class="form-group"><label>Fecha Fin *</label><input type="date" name="fecha_fin" id="pFin" class="form-control" required></div>
                    <div class="form-group"><label>Venc. Pago *</label><input type="date" name="fecha_vencimiento_pago" id="pVence" class="form-control" required></div>
                </div>
                <div class="form-group">
                    <label style="cursor:pointer"><input type="checkbox" name="activo" id="pActivo"> Marcar como período activo (desactivará el actual)</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline modal-close">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
            </div>
        </form>
    </div>
</div>

<script>
function editarPeriodo(p) {
    document.getElementById('periodoAccion').value  = 'editar';
    document.getElementById('periodoId').value      = p.id;
    document.getElementById('pCodigo').value        = p.codigo;
    document.getElementById('pNombre').value        = p.nombre;
    document.getElementById('pInicio').value        = p.fecha_inicio;
    document.getElementById('pFin').value           = p.fecha_fin;
    document.getElementById('pVence').value         = p.fecha_vencimiento_pago;
    document.getElementById('pActivo').checked      = p.activo == 1 || p.activo === true;
    document.getElementById('modalPeriodoTitulo').innerHTML = '<i class="fas fa-calendar-alt" style="color:var(--col-accent)"></i> Editar Período';
    document.getElementById('modalPeriodo').classList.add('open');
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
