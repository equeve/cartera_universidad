<?php
// modules/usuarios/lista.php
$tituloPagina    = 'Usuarios del Sistema';
$subtituloPagina = 'Gestión de acceso y roles';
require_once __DIR__ . '/../../includes/header.php';
requireRol(['admin']);

$db = Database::getInstance();

// Crear/editar usuario via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarCSRF();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear' || $accion === 'editar') {
        $uid      = (int)($_POST['usuario_id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $nombre   = trim($_POST['nombre'] ?? '');
        $apellido = trim($_POST['apellido'] ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $rol      = $_POST['rol'] ?? 'consulta';
        $activo   = isset($_POST['activo']) ? true : false;
        $password = $_POST['password'] ?? '';

        if ($username && $nombre && $email) {
            if ($accion === 'crear') {
                if (!$password) {
                    $_SESSION['flash_error'] = 'La contraseña es obligatoria para nuevos usuarios.';
                } else {
                    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                    try {
                        $db->query(
                            "INSERT INTO usuarios (username, password_hash, nombre, apellido, email, rol, activo) VALUES (?,?,?,?,?,?,?)",
                            [$username, $hash, $nombre, $apellido, $email, $rol, $activo ? 'true' : 'false']
                        );
                        registrarAuditoria('usuarios', 'INSERT', 0, [], ['username' => $username, 'rol' => $rol]);
                        $_SESSION['flash_success'] = 'Usuario creado correctamente.';
                    } catch (Exception $e) {
                        $_SESSION['flash_error'] = 'Error: ' . $e->getMessage();
                    }
                }
            } else {
                $sets = "username=?, nombre=?, apellido=?, email=?, rol=?, activo=?";
                $vals = [$username, $nombre, $apellido, $email, $rol, $activo ? 'true' : 'false'];
                if ($password) {
                    $sets .= ', password_hash=?';
                    $vals[] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                }
                $vals[] = $uid;
                try {
                    $db->query("UPDATE usuarios SET $sets WHERE id=?", $vals);
                    $_SESSION['flash_success'] = 'Usuario actualizado.';
                } catch (Exception $e) {
                    $_SESSION['flash_error'] = 'Error: ' . $e->getMessage();
                }
            }
        } else {
            $_SESSION['flash_error'] = 'Campos obligatorios incompletos.';
        }
    } elseif ($accion === 'toggle') {
        $uid = (int)($_POST['usuario_id'] ?? 0);
        if ($uid !== $usuario['id']) {
            $db->query("UPDATE usuarios SET activo = NOT activo WHERE id=?", [$uid]);
            $_SESSION['flash_success'] = 'Estado del usuario actualizado.';
        }
    }

    header('Location: ' . APP_URL . '/modules/usuarios/lista.php');
    exit;
}

$usuarios = $db->fetchAll(
    "SELECT *, (SELECT COUNT(*) FROM auditoria WHERE usuario_id = u.id) AS acciones
     FROM usuarios u ORDER BY activo DESC, rol, nombre"
);
?>

<div class="flex gap-2 mb-4">
    <button data-modal="modalCrear" class="btn btn-primary"><i class="fas fa-plus"></i> Nuevo Usuario</button>
</div>

<div class="card">
    <div class="card-header"><h3 class="card-title">Usuarios Registrados</h3></div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr><th>Usuario</th><th>Nombre Completo</th><th>Email</th><th>Rol</th><th>Último Acceso</th><th>Acciones Reg.</th><th>Estado</th><th>Opciones</th></tr>
            </thead>
            <tbody>
                <?php foreach ($usuarios as $u): ?>
                <tr style="<?= !$u['activo'] ? 'opacity:.55' : '' ?>">
                    <td><strong><?= e($u['username']) ?></strong></td>
                    <td><?= e($u['nombre'] . ' ' . $u['apellido']) ?></td>
                    <td><small><?= e($u['email']) ?></small></td>
                    <td>
                        <?php
                        $rolColors = ['admin'=>'primary','financiero'=>'info','cajero'=>'accent','consulta'=>'secondary'];
                        $rc = $rolColors[$u['rol']] ?? 'secondary';
                        ?>
                        <span class="badge badge-<?= $rc ?>"><?= ucfirst($u['rol']) ?></span>
                    </td>
                    <td><small><?= $u['ultimo_acceso'] ? formatoFecha($u['ultimo_acceso'], 'd/m/Y H:i') : 'Nunca' ?></small></td>
                    <td class="text-center"><?= number_format($u['acciones']) ?></td>
                    <td><?= estadoBadge($u['activo'] ? 'activo' : 'inactivo') ?></td>
                    <td>
                        <div class="flex gap-2">
                            <button class="btn btn-outline btn-sm"
                                    onclick="editarUsuario(<?= htmlspecialchars(json_encode($u), ENT_QUOTES) ?>)">
                                <i class="fas fa-edit"></i>
                            </button>
                            <?php if ($u['id'] != $usuario['id']): ?>
                            <form method="POST" style="display:inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="accion" value="toggle">
                                <input type="hidden" name="usuario_id" value="<?= $u['id'] ?>">
                                <button type="submit" class="btn btn-<?= $u['activo'] ? 'warning' : 'success' ?> btn-sm"
                                        title="<?= $u['activo'] ? 'Desactivar' : 'Activar' ?>">
                                    <i class="fas fa-<?= $u['activo'] ? 'ban' : 'check' ?>"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Crear -->
<div class="modal-overlay" id="modalCrear">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-user-plus" style="color:var(--col-accent)"></i> Nuevo Usuario</h3>
            <button class="modal-close"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="accion" value="crear">
            <div class="modal-body">
                <div class="form-row cols-2">
                    <div class="form-group"><label>Nombre *</label><input type="text" name="nombre" class="form-control" required></div>
                    <div class="form-group"><label>Apellido</label><input type="text" name="apellido" class="form-control"></div>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group"><label>Username *</label><input type="text" name="username" class="form-control" required></div>
                    <div class="form-group"><label>Email *</label><input type="email" name="email" class="form-control" required></div>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label>Rol *</label>
                        <select name="rol" class="form-select">
                            <option value="admin">Administrador</option>
                            <option value="financiero">Financiero</option>
                            <option value="cajero" selected>Cajero</option>
                            <option value="consulta">Solo Consulta</option>
                        </select>
                    </div>
                    <div class="form-group"><label>Contraseña *</label><input type="password" name="password" class="form-control" required minlength="8"></div>
                </div>
                <div class="form-group">
                    <label><input type="checkbox" name="activo" checked> Usuario activo</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline modal-close">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Crear Usuario</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Editar -->
<div class="modal-overlay" id="modalEditar">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-user-edit" style="color:var(--col-accent)"></i> Editar Usuario</h3>
            <button class="modal-close"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="accion" value="editar">
            <input type="hidden" name="usuario_id" id="editId">
            <div class="modal-body">
                <div class="form-row cols-2">
                    <div class="form-group"><label>Nombre *</label><input type="text" name="nombre" id="editNombre" class="form-control" required></div>
                    <div class="form-group"><label>Apellido</label><input type="text" name="apellido" id="editApellido" class="form-control"></div>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group"><label>Username *</label><input type="text" name="username" id="editUsername" class="form-control" required></div>
                    <div class="form-group"><label>Email *</label><input type="email" name="email" id="editEmail" class="form-control" required></div>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label>Rol *</label>
                        <select name="rol" id="editRol" class="form-select">
                            <option value="admin">Administrador</option>
                            <option value="financiero">Financiero</option>
                            <option value="cajero">Cajero</option>
                            <option value="consulta">Solo Consulta</option>
                        </select>
                    </div>
                    <div class="form-group"><label>Nueva Contraseña <small style="color:var(--col-muted)">(dejar vacío para no cambiar)</small></label><input type="password" name="password" class="form-control" minlength="8"></div>
                </div>
                <div class="form-group">
                    <label><input type="checkbox" name="activo" id="editActivo"> Usuario activo</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline modal-close">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<script>
function editarUsuario(u) {
    document.getElementById('editId').value       = u.id;
    document.getElementById('editNombre').value   = u.nombre;
    document.getElementById('editApellido').value = u.apellido;
    document.getElementById('editUsername').value = u.username;
    document.getElementById('editEmail').value    = u.email;
    document.getElementById('editRol').value      = u.rol;
    document.getElementById('editActivo').checked = u.activo == 1 || u.activo === true;
    document.getElementById('modalEditar').classList.add('open');
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
