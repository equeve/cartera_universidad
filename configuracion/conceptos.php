<?php
// modules/configuracion/conceptos.php
$tituloPagina    = 'Conceptos de Cobro';
$subtituloPagina = 'Catálogo de rubros para liquidaciones de matrícula';
require_once __DIR__ . '/../../includes/header.php';
requireRol(['admin']);

$db = Database::getInstance();

// ── Alta / edición / toggle ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarCSRF();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear' || $accion === 'editar') {
        $id          = (int)($_POST['concepto_id'] ?? 0);
        $codigo      = strtoupper(trim($_POST['codigo'] ?? ''));
        $nombre      = trim($_POST['nombre'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $tipo        = $_POST['tipo'] ?? 'otro';
        $modoValor   = $_POST['modo_valor'] ?? 'fijo'; // 'fijo' | 'smmlv'
        $esDescuento = $tipo === 'descuento';

        $valorFijo      = null;
        $porcentajeSmmlv = null;
        $aplicaSmmlv    = false;

        if ($modoValor === 'smmlv') {
            $aplicaSmmlv = true;
            $pct = (float) str_replace(',', '.', $_POST['porcentaje_smmlv'] ?? '0');
            $porcentajeSmmlv = $esDescuento ? -abs($pct) : abs($pct);
        } else {
            $vf = (float) str_replace(['.', ','], ['', '.'], $_POST['valor_fijo'] ?? '0');
            $valorFijo = $esDescuento ? -abs($vf) : abs($vf);
        }

        $activo = isset($_POST['activo']);

        if (!$codigo || !$nombre) {
            $_SESSION['flash_error'] = 'Código y nombre son obligatorios.';
        } else {
            try {
                if ($accion === 'crear') {
                    $existe = $db->fetchValue("SELECT id FROM conceptos_cobro WHERE codigo = ?", [$codigo]);
                    if ($existe) {
                        $_SESSION['flash_error'] = "Ya existe un concepto con el código $codigo.";
                    } else {
                        $db->query(
                            "INSERT INTO conceptos_cobro (codigo, nombre, descripcion, tipo, aplica_smmlv, porcentaje_smmlv, valor_fijo, activo)
                             VALUES (?,?,?,?,?,?,?,?)",
                            [$codigo, $nombre, $descripcion ?: null, $tipo, $aplicaSmmlv ? 'true' : 'false', $porcentajeSmmlv, $valorFijo, $activo ? 'true' : 'false']
                        );
                        registrarAuditoria('conceptos_cobro', 'INSERT', 0, [], ['codigo' => $codigo]);
                        $_SESSION['flash_success'] = "Concepto $codigo creado correctamente.";
                    }
                } else {
                    $db->query(
                        "UPDATE conceptos_cobro SET
                            codigo=?, nombre=?, descripcion=?, tipo=?,
                            aplica_smmlv=?, porcentaje_smmlv=?, valor_fijo=?, activo=?
                         WHERE id=?",
                        [$codigo, $nombre, $descripcion ?: null, $tipo, $aplicaSmmlv ? 'true' : 'false', $porcentajeSmmlv, $valorFijo, $activo ? 'true' : 'false', $id]
                    );
                    registrarAuditoria('conceptos_cobro', 'UPDATE', $id, [], ['codigo' => $codigo]);
                    $_SESSION['flash_success'] = "Concepto $codigo actualizado.";
                }
            } catch (Exception $e) {
                $_SESSION['flash_error'] = 'Error: ' . $e->getMessage();
            }
        }
    } elseif ($accion === 'toggle') {
        $id = (int)($_POST['concepto_id'] ?? 0);
        $db->query("UPDATE conceptos_cobro SET activo = NOT activo WHERE id = ?", [$id]);
        $_SESSION['flash_success'] = 'Estado del concepto actualizado.';
    }

    header('Location: ' . APP_URL . '/modules/configuracion/conceptos.php');
    exit;
}

$conceptos = $db->fetchAll(
    "SELECT *,
            (SELECT COUNT(*) FROM factura_items WHERE concepto_id = conceptos_cobro.id) AS veces_usado
     FROM conceptos_cobro
     ORDER BY tipo, nombre"
);

$tipos = [
    'matricula'           => ['Matrícula', 'primary', 'graduation-cap'],
    'derechos_academicos' => ['Derechos Académicos', 'info', 'file-alt'],
    'servicios'           => ['Servicios', 'accent', 'concierge-bell'],
    'descuento'           => ['Descuento / Beca', 'success', 'percent'],
    'otro'                => ['Otro', 'secondary', 'tag'],
];

function valorMostrar(array $c): string {
    if ($c['aplica_smmlv'] && $c['porcentaje_smmlv'] !== null) {
        $pct = (float) $c['porcentaje_smmlv'];
        $valor = calcularValorSMMLV(abs($pct));
        $signo = $pct < 0 ? '–' : '';
        return $signo . formatoPeso($valor) . ' <small style="color:var(--col-muted)">(' . abs($pct) . '% SMMLV)</small>';
    }
    if ($c['valor_fijo'] !== null) {
        $vf = (float) $c['valor_fijo'];
        return ($vf < 0 ? '–' : '') . formatoPeso(abs($vf));
    }
    return '<span style="color:var(--col-muted)">Variable / manual</span>';
}
?>

<div class="flex gap-2 mb-4 items-center">
    <button data-modal="modalCrear" class="btn btn-primary"><i class="fas fa-plus"></i> Nuevo Concepto</button>
    <div style="margin-left:auto;font-size:.83rem;color:var(--col-muted)">
        SMMLV vigente: <strong style="color:var(--col-primary)"><?= formatoPeso(SMMLV_VIGENTE) ?></strong>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-tags" style="color:var(--col-accent)"></i> Conceptos de Cobro</h3>
        <small style="color:var(--col-muted)"><?= count($conceptos) ?> registrados</small>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Código</th><th>Nombre</th><th>Tipo</th>
                    <th>Modo de Cálculo</th><th class="text-right">Valor</th>
                    <th style="text-align:center">Usado en</th>
                    <th>Estado</th><th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($conceptos as $c):
                    [$tLbl, $tCol, $tIco] = $tipos[$c['tipo']] ?? ['Otro','secondary','tag'];
                ?>
                <tr style="<?= !$c['activo'] ? 'opacity:.5' : '' ?>">
                    <td><strong style="font-family:monospace"><?= e($c['codigo']) ?></strong></td>
                    <td>
                        <?= e($c['nombre']) ?>
                        <?php if ($c['descripcion']): ?>
                        <br><small style="color:var(--col-muted)"><?= e($c['descripcion']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge-<?= $tCol ?>"><i class="fas fa-<?= $tIco ?>" style="font-size:.65rem"></i> <?= $tLbl ?></span></td>
                    <td>
                        <?php if ($c['aplica_smmlv']): ?>
                        <span class="badge badge-info" style="font-size:.7rem">% SMMLV</span>
                        <?php elseif ($c['valor_fijo'] !== null): ?>
                        <span class="badge badge-secondary" style="font-size:.7rem">Valor Fijo</span>
                        <?php else: ?>
                        <span class="badge badge-warning" style="font-size:.7rem">Manual</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-right" style="font-weight:600;color:<?= $c['tipo']==='descuento' ? 'var(--col-success)' : 'var(--col-text)' ?>">
                        <?= valorMostrar($c) ?>
                    </td>
                    <td style="text-align:center"><small><?= number_format($c['veces_usado']) ?> facturas</small></td>
                    <td><?= estadoBadge($c['activo'] ? 'activo' : 'inactivo') ?></td>
                    <td>
                        <div class="flex gap-2">
                            <button class="btn btn-outline btn-sm"
                                    onclick='editarConcepto(<?= json_encode($c, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="POST" style="display:inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="accion" value="toggle">
                                <input type="hidden" name="concepto_id" value="<?= $c['id'] ?>">
                                <button type="submit" class="btn btn-<?= $c['activo'] ? 'warning' : 'success' ?> btn-sm"
                                        title="<?= $c['activo'] ? 'Desactivar' : 'Activar' ?>">
                                    <i class="fas fa-<?= $c['activo'] ? 'ban' : 'check' ?>"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($conceptos)): ?>
                <tr><td colspan="8" class="text-center text-muted" style="padding:3rem">Sin conceptos registrados</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Crear -->
<div class="modal-overlay" id="modalCrear">
    <div class="modal" style="max-width:520px">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-plus-circle" style="color:var(--col-accent)"></i> Nuevo Concepto de Cobro</h3>
            <button class="modal-close"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="accion" value="crear">
            <div class="modal-body">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label>Código *</label>
                        <input type="text" name="codigo" class="form-control" required placeholder="MAT-PRE" maxlength="30" style="font-family:monospace">
                    </div>
                    <div class="form-group">
                        <label>Tipo *</label>
                        <select name="tipo" class="form-select" onchange="actualizarSignoDescuento(this)">
                            <?php foreach ($tipos as $k => [$lbl,,]): ?>
                            <option value="<?= $k ?>"><?= $lbl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Nombre *</label>
                    <input type="text" name="nombre" class="form-control" required placeholder="Matrícula Pregrado">
                </div>
                <div class="form-group">
                    <label>Descripción</label>
                    <textarea name="descripcion" class="form-control" rows="2" placeholder="Descripción opcional..."></textarea>
                </div>

                <div class="form-group">
                    <label>Modo de Cálculo *</label>
                    <div class="flex gap-3" style="margin-top:.3rem">
                        <label style="cursor:pointer;display:flex;align-items:center;gap:.4rem">
                            <input type="radio" name="modo_valor" value="fijo" checked onclick="toggleModoValor('fijo')"> Valor fijo en pesos
                        </label>
                        <label style="cursor:pointer;display:flex;align-items:center;gap:.4rem">
                            <input type="radio" name="modo_valor" value="smmlv" onclick="toggleModoValor('smmlv')"> % del SMMLV
                        </label>
                    </div>
                </div>

                <div id="grupoValorFijo" class="form-group">
                    <label>Valor Fijo (COP)</label>
                    <input type="text" name="valor_fijo" class="form-control input-currency" placeholder="0">
                </div>

                <div id="grupoSmmlv" class="form-group" style="display:none">
                    <label>Porcentaje sobre SMMLV (%)</label>
                    <input type="text" name="porcentaje_smmlv" class="form-control" placeholder="Ej: 150">
                    <small style="color:var(--col-muted)">SMMLV vigente: <?= formatoPeso(SMMLV_VIGENTE) ?>. Para conceptos de descuento se restará automáticamente.</small>
                </div>

                <div class="form-group">
                    <label style="cursor:pointer"><input type="checkbox" name="activo" checked> Concepto activo</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline modal-close">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Crear Concepto</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Editar -->
<div class="modal-overlay" id="modalEditar">
    <div class="modal" style="max-width:520px">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-edit" style="color:var(--col-accent)"></i> Editar Concepto</h3>
            <button class="modal-close"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="accion" value="editar">
            <input type="hidden" name="concepto_id" id="editId">
            <div class="modal-body">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label>Código *</label>
                        <input type="text" name="codigo" id="editCodigo" class="form-control" required maxlength="30" style="font-family:monospace">
                    </div>
                    <div class="form-group">
                        <label>Tipo *</label>
                        <select name="tipo" id="editTipo" class="form-select">
                            <?php foreach ($tipos as $k => [$lbl,,]): ?>
                            <option value="<?= $k ?>"><?= $lbl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Nombre *</label>
                    <input type="text" name="nombre" id="editNombre" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Descripción</label>
                    <textarea name="descripcion" id="editDescripcion" class="form-control" rows="2"></textarea>
                </div>

                <div class="form-group">
                    <label>Modo de Cálculo *</label>
                    <div class="flex gap-3" style="margin-top:.3rem">
                        <label style="cursor:pointer;display:flex;align-items:center;gap:.4rem">
                            <input type="radio" name="modo_valor" value="fijo" id="editModoFijo" onclick="toggleModoValor('fijo','edit')"> Valor fijo en pesos
                        </label>
                        <label style="cursor:pointer;display:flex;align-items:center;gap:.4rem">
                            <input type="radio" name="modo_valor" value="smmlv" id="editModoSmmlv" onclick="toggleModoValor('smmlv','edit')"> % del SMMLV
                        </label>
                    </div>
                </div>

                <div id="editGrupoValorFijo" class="form-group">
                    <label>Valor Fijo (COP)</label>
                    <input type="text" name="valor_fijo" id="editValorFijo" class="form-control input-currency" placeholder="0">
                </div>

                <div id="editGrupoSmmlv" class="form-group" style="display:none">
                    <label>Porcentaje sobre SMMLV (%)</label>
                    <input type="text" name="porcentaje_smmlv" id="editPorcentajeSmmlv" class="form-control" placeholder="Ej: 150">
                </div>

                <div class="form-group">
                    <label style="cursor:pointer"><input type="checkbox" name="activo" id="editActivo"> Concepto activo</label>
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
function toggleModoValor(modo, prefix = '') {
    const pFijo  = document.getElementById(prefix ? 'editGrupoValorFijo' : 'grupoValorFijo');
    const pSmmlv = document.getElementById(prefix ? 'editGrupoSmmlv' : 'grupoSmmlv');
    if (modo === 'smmlv') {
        pFijo.style.display = 'none';
        pSmmlv.style.display = '';
    } else {
        pFijo.style.display = '';
        pSmmlv.style.display = 'none';
    }
}

function editarConcepto(c) {
    document.getElementById('editId').value          = c.id;
    document.getElementById('editCodigo').value       = c.codigo;
    document.getElementById('editTipo').value         = c.tipo;
    document.getElementById('editNombre').value       = c.nombre;
    document.getElementById('editDescripcion').value  = c.descripcion || '';
    document.getElementById('editActivo').checked     = c.activo === true || c.activo === 't' || c.activo === 1 || c.activo === '1';

    const aplicaSmmlv = c.aplica_smmlv === true || c.aplica_smmlv === 't' || c.aplica_smmlv === 1 || c.aplica_smmlv === '1';

    if (aplicaSmmlv) {
        document.getElementById('editModoSmmlv').checked = true;
        document.getElementById('editPorcentajeSmmlv').value = Math.abs(parseFloat(c.porcentaje_smmlv || 0));
        document.getElementById('editValorFijo').value = '';
        toggleModoValor('smmlv', 'edit');
    } else {
        document.getElementById('editModoFijo').checked = true;
        const vf = Math.abs(parseFloat(c.valor_fijo || 0));
        document.getElementById('editValorFijo').value = vf ? new Intl.NumberFormat('es-CO').format(vf) : '';
        document.getElementById('editPorcentajeSmmlv').value = '';
        toggleModoValor('fijo', 'edit');
    }

    document.getElementById('modalEditar').classList.add('open');
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
