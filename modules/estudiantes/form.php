<?php
// modules/estudiantes/form.php
require_once __DIR__ . '/../../includes/helpers.php';
requireRol(['admin','financiero']);

$db = Database::getInstance();
$id = (int)($_GET['id'] ?? 0);
$esEdicion = $id > 0;
$estudiante = [];

if ($esEdicion) {
    $estudiante = $db->fetchOne("SELECT * FROM estudiantes WHERE id = ?", [$id]);
    if (!$estudiante) {
        $_SESSION['flash_error'] = 'Estudiante no encontrado.';
        header('Location: ' . APP_URL . '/modules/estudiantes/lista.php');
        exit;
    }
}

$tituloPagina    = $esEdicion ? 'Editar Estudiante' : 'Nuevo Estudiante';
$subtituloPagina = $esEdicion ? 'Modificar datos del estudiante' : 'Registrar nuevo estudiante';
require_once __DIR__ . '/../../includes/header.php';

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarCSRF();

    $datos = [
        'codigo'            => strtoupper(trim($_POST['codigo'] ?? '')),
        'tipo_documento'    => $_POST['tipo_documento'] ?? '',
        'numero_documento'  => trim($_POST['numero_documento'] ?? ''),
        'primer_nombre'     => trim($_POST['primer_nombre'] ?? ''),
        'segundo_nombre'    => trim($_POST['segundo_nombre'] ?? '') ?: null,
        'primer_apellido'   => trim($_POST['primer_apellido'] ?? ''),
        'segundo_apellido'  => trim($_POST['segundo_apellido'] ?? '') ?: null,
        'email'             => strtolower(trim($_POST['email'] ?? '')),
        'email_institucional'=> strtolower(trim($_POST['email_institucional'] ?? '')) ?: null,
        'celular'           => trim($_POST['celular'] ?? '') ?: null,
        'telefono'          => trim($_POST['telefono'] ?? '') ?: null,
        'direccion'         => trim($_POST['direccion'] ?? '') ?: null,
        'municipio'         => trim($_POST['municipio'] ?? '') ?: null,
        'departamento'      => trim($_POST['departamento'] ?? '') ?: null,
        'estrato'           => $_POST['estrato'] ? (int)$_POST['estrato'] : null,
        'programa_id'       => (int)($_POST['programa_id'] ?? 0) ?: null,
        'semestre_actual'   => (int)($_POST['semestre_actual'] ?? 1),
        'estado'            => $_POST['estado'] ?? 'activo',
        'fecha_ingreso'     => $_POST['fecha_ingreso'] ?: null,
    ];

    // Validaciones
    if (!$datos['codigo'])           $errores['codigo']           = 'El código es obligatorio';
    if (!$datos['numero_documento'])  $errores['numero_documento']  = 'El documento es obligatorio';
    if (!$datos['primer_nombre'])    $errores['primer_nombre']    = 'El primer nombre es obligatorio';
    if (!$datos['primer_apellido'])  $errores['primer_apellido']  = 'El primer apellido es obligatorio';
    if (!filter_var($datos['email'], FILTER_VALIDATE_EMAIL)) $errores['email'] = 'Email inválido';

    // Unicidad
    if (!$errores) {
        $existeCodigo = $db->fetchValue(
            "SELECT id FROM estudiantes WHERE codigo = ? AND id != ?",
            [$datos['codigo'], $id]
        );
        if ($existeCodigo) $errores['codigo'] = 'Este código ya está registrado';

        $existeDoc = $db->fetchValue(
            "SELECT id FROM estudiantes WHERE numero_documento = ? AND id != ?",
            [$datos['numero_documento'], $id]
        );
        if ($existeDoc) $errores['numero_documento'] = 'Este número de documento ya está registrado';
    }

    if (!$errores) {
        try {
            if ($esEdicion) {
                $anterior = $estudiante;
                $db->query(
                    "UPDATE estudiantes SET
                        codigo=?, tipo_documento=?, numero_documento=?,
                        primer_nombre=?, segundo_nombre=?, primer_apellido=?, segundo_apellido=?,
                        email=?, email_institucional=?, celular=?, telefono=?,
                        direccion=?, municipio=?, departamento=?, estrato=?,
                        programa_id=?, semestre_actual=?, estado=?, fecha_ingreso=?,
                        updated_at=NOW()
                     WHERE id=?",
                    [...array_values($datos), $id]
                );
                registrarAuditoria('estudiantes', 'UPDATE', $id, $anterior, $datos);
                $_SESSION['flash_success'] = 'Estudiante actualizado correctamente.';
            } else {
                $db->query(
                    "INSERT INTO estudiantes (codigo, tipo_documento, numero_documento,
                        primer_nombre, segundo_nombre, primer_apellido, segundo_apellido,
                        email, email_institucional, celular, telefono,
                        direccion, municipio, departamento, estrato,
                        programa_id, semestre_actual, estado, fecha_ingreso)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                    array_values($datos)
                );
                $nuevoId = (int)$db->fetchValue("SELECT currval('estudiantes_id_seq')");
                registrarAuditoria('estudiantes', 'INSERT', $nuevoId, [], $datos);
                $_SESSION['flash_success'] = 'Estudiante registrado correctamente.';
                header('Location: ' . APP_URL . '/modules/estudiantes/ver.php?id=' . $nuevoId);
                exit;
            }
            header('Location: ' . APP_URL . '/modules/estudiantes/ver.php?id=' . $id);
            exit;
        } catch (Exception $e) {
            $errores['general'] = 'Error al guardar: ' . $e->getMessage();
        }
    }

    // Repoblar con datos POSTados
    $estudiante = array_merge($estudiante, $datos);
}

$programas   = $db->fetchAll("SELECT id, nombre, facultad, nivel FROM programas WHERE activo=TRUE ORDER BY nombre");
$deptos      = ['Amazonas','Antioquia','Arauca','Atlántico','Bolívar','Boyacá','Caldas','Caquetá','Casanare','Cauca','Cesar','Chocó','Córdoba','Cundinamarca','Guainía','Guaviare','Huila','La Guajira','Magdalena','Meta','Nariño','Norte de Santander','Putumayo','Quindío','Risaralda','San Andrés','Santander','Sucre','Tolima','Valle del Cauca','Vaupés','Vichada'];

function campo(array $est, string $key): string {
    return e($est[$key] ?? '');
}
function seleccionado(array $est, string $key, $valor): string {
    return ($est[$key] ?? '') == $valor ? 'selected' : '';
}
?>

<div class="flex gap-2 mb-4">
    <a href="<?= APP_URL ?>/modules/estudiantes/lista.php" class="btn btn-outline btn-sm">
        <i class="fas fa-arrow-left"></i> Volver al listado
    </a>
    <?php if ($esEdicion): ?>
    <a href="<?= APP_URL ?>/modules/estudiantes/ver.php?id=<?= $id ?>" class="btn btn-outline btn-sm">
        <i class="fas fa-eye"></i> Ver perfil
    </a>
    <?php endif; ?>
</div>

<?php if (!empty($errores['general'])): ?>
<div class="alert alert-danger"><i class="fas fa-times-circle"></i> <?= e($errores['general']) ?></div>
<?php endif; ?>

<form method="POST">
    <?= csrfField() ?>

    <!-- Identificación -->
    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-id-card" style="color:var(--col-accent)"></i> Identificación</h3></div>
        <div class="card-body">
            <div class="form-row cols-3">
                <div class="form-group">
                    <label>Código Estudiantil *</label>
                    <input type="text" name="codigo" class="form-control <?= isset($errores['codigo'])?'is-invalid':'' ?>"
                           value="<?= campo($estudiante,'codigo') ?>" placeholder="20250001" required>
                    <?php if (isset($errores['codigo'])): ?><div class="invalid-feedback"><?= $errores['codigo'] ?></div><?php endif; ?>
                </div>
                <div class="form-group">
                    <label>Tipo de Documento *</label>
                    <select name="tipo_documento" class="form-select" required>
                        <?php foreach (['CC'=>'Cédula de Ciudadanía','TI'=>'Tarjeta de Identidad','CE'=>'Cédula de Extranjería','PASAPORTE'=>'Pasaporte','PEP'=>'PEP'] as $v=>$l): ?>
                        <option value="<?= $v ?>" <?= seleccionado($estudiante,'tipo_documento',$v) ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Número de Documento *</label>
                    <input type="text" name="numero_documento" class="form-control <?= isset($errores['numero_documento'])?'is-invalid':'' ?>"
                           value="<?= campo($estudiante,'numero_documento') ?>" required>
                    <?php if (isset($errores['numero_documento'])): ?><div class="invalid-feedback"><?= $errores['numero_documento'] ?></div><?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Nombres -->
    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-user" style="color:var(--col-accent)"></i> Datos Personales</h3></div>
        <div class="card-body">
            <div class="form-row cols-2 mb-3">
                <div class="form-group">
                    <label>Primer Nombre *</label>
                    <input type="text" name="primer_nombre" class="form-control <?= isset($errores['primer_nombre'])?'is-invalid':'' ?>"
                           value="<?= campo($estudiante,'primer_nombre') ?>" required>
                    <?php if (isset($errores['primer_nombre'])): ?><div class="invalid-feedback"><?= $errores['primer_nombre'] ?></div><?php endif; ?>
                </div>
                <div class="form-group">
                    <label>Segundo Nombre</label>
                    <input type="text" name="segundo_nombre" class="form-control" value="<?= campo($estudiante,'segundo_nombre') ?>">
                </div>
                <div class="form-group">
                    <label>Primer Apellido *</label>
                    <input type="text" name="primer_apellido" class="form-control <?= isset($errores['primer_apellido'])?'is-invalid':'' ?>"
                           value="<?= campo($estudiante,'primer_apellido') ?>" required>
                    <?php if (isset($errores['primer_apellido'])): ?><div class="invalid-feedback"><?= $errores['primer_apellido'] ?></div><?php endif; ?>
                </div>
                <div class="form-group">
                    <label>Segundo Apellido</label>
                    <input type="text" name="segundo_apellido" class="form-control" value="<?= campo($estudiante,'segundo_apellido') ?>">
                </div>
            </div>
            <div class="form-row cols-3">
                <div class="form-group">
                    <label>Email Personal *</label>
                    <input type="email" name="email" class="form-control <?= isset($errores['email'])?'is-invalid':'' ?>"
                           value="<?= campo($estudiante,'email') ?>" required>
                    <?php if (isset($errores['email'])): ?><div class="invalid-feedback"><?= $errores['email'] ?></div><?php endif; ?>
                </div>
                <div class="form-group">
                    <label>Email Institucional</label>
                    <input type="email" name="email_institucional" class="form-control" value="<?= campo($estudiante,'email_institucional') ?>">
                </div>
                <div class="form-group">
                    <label>Celular</label>
                    <input type="text" name="celular" class="form-control" value="<?= campo($estudiante,'celular') ?>" placeholder="310 000 0000">
                </div>
            </div>
            <div class="form-row cols-3">
                <div class="form-group">
                    <label>Departamento</label>
                    <select name="departamento" class="form-select">
                        <option value="">Seleccione...</option>
                        <?php foreach ($deptos as $d): ?>
                        <option value="<?= $d ?>" <?= seleccionado($estudiante,'departamento',$d) ?>><?= $d ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Municipio</label>
                    <input type="text" name="municipio" class="form-control" value="<?= campo($estudiante,'municipio') ?>">
                </div>
                <div class="form-group">
                    <label>Estrato Socioeconómico</label>
                    <select name="estrato" class="form-select">
                        <option value="">–</option>
                        <?php for ($i=1;$i<=6;$i++): ?>
                        <option value="<?= $i ?>" <?= seleccionado($estudiante,'estrato',$i) ?>>Estrato <?= $i ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Dirección de Residencia</label>
                <input type="text" name="direccion" class="form-control" value="<?= campo($estudiante,'direccion') ?>" placeholder="Calle, carrera, barrio...">
            </div>
        </div>
    </div>

    <!-- Académico -->
    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-graduation-cap" style="color:var(--col-accent)"></i> Información Académica</h3></div>
        <div class="card-body">
            <div class="form-row cols-4">
                <div class="form-group" style="grid-column:span 2">
                    <label>Programa Académico</label>
                    <select name="programa_id" class="form-select">
                        <option value="">Seleccione...</option>
                        <?php foreach ($programas as $prog): ?>
                        <option value="<?= $prog['id'] ?>" <?= seleccionado($estudiante,'programa_id',$prog['id']) ?>>
                            <?= e($prog['nombre']) ?> (<?= ucfirst($prog['nivel']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Semestre Actual</label>
                    <select name="semestre_actual" class="form-select">
                        <?php for ($s=1;$s<=14;$s++): ?>
                        <option value="<?= $s ?>" <?= seleccionado($estudiante,'semestre_actual',$s) ?>>Semestre <?= $s ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Estado</label>
                    <select name="estado" class="form-select" required>
                        <?php foreach (['activo','inactivo','graduado','retirado','suspendido'] as $est): ?>
                        <option value="<?= $est ?>" <?= seleccionado($estudiante,'estado',$est) ?>><?= ucfirst($est) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Fecha de Ingreso</label>
                    <input type="date" name="fecha_ingreso" class="form-control" value="<?= campo($estudiante,'fecha_ingreso') ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="flex gap-2">
        <button type="submit" class="btn btn-primary btn-lg">
            <i class="fas fa-save"></i> <?= $esEdicion ? 'Guardar Cambios' : 'Registrar Estudiante' ?>
        </button>
        <a href="<?= APP_URL ?>/modules/estudiantes/lista.php" class="btn btn-outline btn-lg">Cancelar</a>
    </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
