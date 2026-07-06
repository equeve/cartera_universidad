<?php
// modules/contabilidad/nuevo_comprobante.php
$tituloPagina    = 'Nuevo Comprobante';
$subtituloPagina = 'Registro de asiento contable — Partida doble';
require_once __DIR__ . '/../../includes/header.php';
requireRol(['admin', 'financiero']);

$db = Database::getInstance();

// ── Guardar comprobante ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarCSRF();

    $tipo        = $_POST['tipo']        ?? '';
    $fecha       = $_POST['fecha']       ?? date('Y-m-d');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $obs         = trim($_POST['observaciones'] ?? '');
    $lineas      = $_POST['lineas']      ?? [];
    $guardar     = $_POST['guardar']     ?? 'borrador';   // 'borrador' o 'contabilizar'

    $errores = [];
    if (!$tipo)        $errores[] = 'Seleccione el tipo de comprobante.';
    if (!$descripcion) $errores[] = 'Ingrese una descripción para el comprobante.';
    if (count($lineas) < 2) $errores[] = 'El comprobante debe tener al menos 2 líneas.';

    // Validar líneas
    $lineasValidas = [];
    $totDeb = $totCre = 0;
    foreach ($lineas as $i => $lin) {
        $cuenta  = trim($lin['cuenta_codigo'] ?? '');
        $descLin = trim($lin['descripcion']   ?? '');
        $deb     = (float) str_replace(['.', ','], ['', '.'], $lin['debito']  ?? '0');
        $cre     = (float) str_replace(['.', ','], ['', '.'], $lin['credito'] ?? '0');

        if (!$cuenta || !$descLin) continue;           // línea vacía → ignorar
        if ($deb == 0 && $cre == 0) continue;
        if ($deb > 0 && $cre > 0) { $errores[] = "Línea " . ($i+1) . ": no puede tener débito y crédito a la vez."; continue; }

        // Verificar que la cuenta existe y acepta movimiento
        $cuentaObj = $db->fetchOne("SELECT codigo, nombre, acepta_movimiento FROM puc_cuentas WHERE codigo = ? AND activa = TRUE", [$cuenta]);
        if (!$cuentaObj)               { $errores[] = "Línea " . ($i+1) . ": cuenta '$cuenta' no existe o está inactiva."; continue; }
        if (!$cuentaObj['acepta_movimiento']) { $errores[] = "Línea " . ($i+1) . ": la cuenta '$cuenta' no acepta movimientos directos."; continue; }

        $totDeb += $deb;
        $totCre += $cre;
        $lineasValidas[] = [
            'cuenta_codigo'   => $cuenta,
            'tercero_id'      => ($lin['tercero_id'] ?? '') ?: null,
            'centro_costo_id' => ($lin['centro_costo_id'] ?? '') ?: null,
            'descripcion'     => $descLin,
            'debito'          => $deb,
            'credito'         => $cre,
        ];
    }

    if (empty($lineasValidas)) $errores[] = 'No hay líneas válidas en el comprobante.';
    if ($guardar === 'contabilizar' && abs($totDeb - $totCre) > 0.01) {
        $errores[] = sprintf(
            'La partida NO cuadra. Débitos: %s — Créditos: %s — Diferencia: %s',
            formatoPeso($totDeb), formatoPeso($totCre), formatoPeso(abs($totDeb - $totCre))
        );
    }

    if (empty($errores)) {
        try {
            $db->beginTransaction();

            $estado   = $guardar === 'contabilizar' ? 'contabilizado' : 'borrador';
            $periodo  = date('Y-m', strtotime($fecha));
            $numero   = $db->fetchValue("SELECT fn_numero_comprobante(?, ?::date)", [$tipo, $fecha]);

            $db->query(
                "INSERT INTO comprobantes
                     (numero, tipo, fecha, periodo_contable, descripcion, observaciones,
                      total_debitos, total_creditos, estado, elaborado_por,
                      aprobado_por, fecha_aprobacion)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                [
                    $numero, $tipo, $fecha, $periodo, $descripcion, $obs ?: null,
                    $totDeb, $totCre, $estado,
                    $usuario['id'],
                    $estado === 'contabilizado' ? $usuario['id'] : null,
                    $estado === 'contabilizado' ? date('Y-m-d H:i:s') : null,
                ]
            );
            $compId = (int) $db->fetchValue("SELECT currval('comprobantes_id_seq')");

            foreach ($lineasValidas as $idx => $lin) {
                $db->query(
                    "INSERT INTO movimientos_contables
                         (comprobante_id, linea, cuenta_codigo, tercero_id, centro_costo_id, descripcion, debito, credito)
                     VALUES (?,?,?,?,?,?,?,?)",
                    [
                        $compId, $idx + 1, $lin['cuenta_codigo'],
                        $lin['tercero_id'], $lin['centro_costo_id'],
                        $lin['descripcion'], $lin['debito'], $lin['credito'],
                    ]
                );
            }

            registrarAuditoria('comprobantes', 'INSERT', $compId, [], ['numero' => $numero, 'estado' => $estado]);
            $db->commit();

            $_SESSION['flash_success'] = "Comprobante $numero " . ($estado === 'contabilizado' ? 'contabilizado' : 'guardado como borrador') . " correctamente.";
            header('Location: ' . APP_URL . '/modules/contabilidad/ver_comprobante.php?id=' . $compId);
            exit;
        } catch (Exception $e) {
            $db->rollback();
            $errores[] = 'Error al guardar: ' . $e->getMessage();
        }
    }
}

// ── Datos para selects ────────────────────────────────────────
$cuentasMovimiento = $db->fetchAll(
    "SELECT codigo, nombre, naturaleza, clase
     FROM puc_cuentas
     WHERE acepta_movimiento = TRUE AND activa = TRUE
     ORDER BY codigo"
);
$centrosCosto = $db->fetchAll("SELECT id, codigo, nombre FROM centros_costo WHERE activo = TRUE ORDER BY nombre");
$terceros     = $db->fetchAll("SELECT id, numero_documento, COALESCE(razon_social, nombres||' '||COALESCE(apellidos,'')) AS nombre FROM terceros WHERE activo = TRUE ORDER BY nombre LIMIT 200");

$tiposComp = [
    'comprobante_ingreso' => 'Comprobante de Ingreso (CI)',
    'comprobante_egreso'  => 'Comprobante de Egreso (CE)',
    'nota_contable'       => 'Nota Contable (NC)',
    'causacion'           => 'Causación (CA)',
    'ajuste'              => 'Ajuste (AJ)',
    'apertura'            => 'Apertura (AP)',
    'cierre'              => 'Cierre (CL)',
];

$claseNombre = fn($c) => match($c) {
    '1'=>'ACTIVO','2'=>'PASIVO','3'=>'PATRIMONIO',
    '4'=>'INGRESO','5'=>'GASTO','6'=>'COSTO',default=>'ORDEN'
};
?>

<div class="flex gap-2 mb-4">
    <a href="<?= APP_URL ?>/modules/contabilidad/comprobantes.php" class="btn btn-outline btn-sm">
        <i class="fas fa-arrow-left"></i> Volver
    </a>
</div>

<?php if (!empty($errores)): ?>
<div class="alert alert-danger" style="flex-direction:column;align-items:flex-start;gap:.3rem">
    <strong><i class="fas fa-exclamation-triangle"></i> No se pudo guardar el comprobante:</strong>
    <?php foreach ($errores as $e): ?>
    <div style="margin-left:1.2rem">• <?= e($e) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<form method="POST" id="formComprobante">
    <?= csrfField() ?>

    <!-- Encabezado del comprobante -->
    <div class="card mb-3">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-file-invoice" style="color:var(--col-accent)"></i> Encabezado del Comprobante</h3>
            <div id="indicadorCuadre" style="font-size:.85rem;font-weight:600;padding:.3rem .9rem;border-radius:20px;background:#e2e6ea">
                Llenando...
            </div>
        </div>
        <div class="card-body">
            <div class="form-row cols-4">
                <div class="form-group" style="grid-column:span 2">
                    <label>Tipo de Comprobante *</label>
                    <select name="tipo" class="form-select" required>
                        <option value="">Seleccione...</option>
                        <?php foreach ($tiposComp as $k => $v): ?>
                        <option value="<?= $k ?>" <?= ($_POST['tipo'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Fecha *</label>
                    <input type="date" name="fecha" class="form-control" required
                           value="<?= e($_POST['fecha'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="form-group">
                    <label>Período Contable</label>
                    <input type="text" class="form-control" id="periodoMostrar"
                           value="<?= date('Y-m') ?>" readonly
                           style="background:var(--col-bg);font-weight:600;font-family:monospace">
                </div>
            </div>
            <div class="form-group">
                <label>Descripción del Comprobante *</label>
                <input type="text" name="descripcion" class="form-control" required
                       placeholder="Descripción general del comprobante..."
                       value="<?= e($_POST['descripcion'] ?? '') ?>" maxlength="400">
            </div>
            <div class="form-group">
                <label>Observaciones</label>
                <textarea name="observaciones" class="form-control" rows="2"
                          placeholder="Observaciones adicionales..."><?= e($_POST['observaciones'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <!-- Líneas del comprobante -->
    <div class="card mb-3">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-list-ol" style="color:var(--col-accent)"></i> Líneas del Comprobante — Partida Doble</h3>
            <button type="button" class="btn btn-outline btn-sm" id="btnAgregarLinea">
                <i class="fas fa-plus"></i> Agregar Línea
            </button>
        </div>
        <div class="table-wrapper">
            <table id="tablaLineas" style="min-width:900px">
                <thead>
                    <tr>
                        <th style="width:36px">#</th>
                        <th style="width:160px">Código Cuenta *</th>
                        <th>Nombre Cuenta</th>
                        <th style="width:220px">Descripción *</th>
                        <th style="width:150px">Tercero</th>
                        <th style="width:150px">Centro Costo</th>
                        <th style="width:140px" class="text-right">Débito</th>
                        <th style="width:140px" class="text-right">Crédito</th>
                        <th style="width:40px"></th>
                    </tr>
                </thead>
                <tbody id="cuerpoLineas">
                    <!-- líneas dinámicas -->
                </tbody>
                <tfoot>
                    <tr style="background:rgba(26,58,92,.06);font-weight:700">
                        <td colspan="5"></td>
                        <td style="padding:.6rem 1rem;font-size:.85rem;color:var(--col-primary)">TOTALES</td>
                        <td class="text-right" style="padding:.6rem 1rem;font-size:.95rem;color:var(--col-info)" id="totalDebitos">$ 0</td>
                        <td class="text-right" style="padding:.6rem 1rem;font-size:.95rem;color:var(--col-success)" id="totalCreditos">$ 0</td>
                        <td></td>
                    </tr>
                    <tr id="filaDiferencia" style="display:none">
                        <td colspan="5"></td>
                        <td style="padding:.3rem 1rem;font-size:.82rem;color:var(--col-danger)">Diferencia</td>
                        <td colspan="2" class="text-right" style="padding:.3rem 1rem;font-size:.9rem;font-weight:700;color:var(--col-danger)" id="diferencia"></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div style="padding:.8rem 1.4rem;display:flex;gap:.5rem;flex-wrap:wrap">
            <button type="button" class="btn btn-outline btn-sm" id="btnAgregarLinea2">
                <i class="fas fa-plus"></i> Agregar fila
            </button>
            <button type="button" class="btn btn-outline btn-sm" id="btnBalancear"
                    title="Agrega línea para cuadrar automáticamente">
                <i class="fas fa-balance-scale"></i> Balancear diferencia
            </button>
        </div>
    </div>

    <!-- Botones de acción -->
    <div class="card">
        <div class="card-body" style="display:flex;gap:.8rem;align-items:center;flex-wrap:wrap">
            <button type="submit" name="guardar" value="borrador" class="btn btn-outline btn-lg">
                <i class="fas fa-save"></i> Guardar Borrador
            </button>
            <button type="submit" name="guardar" value="contabilizar" class="btn btn-primary btn-lg" id="btnContabilizar">
                <i class="fas fa-check-double"></i> Contabilizar
            </button>
            <a href="<?= APP_URL ?>/modules/contabilidad/comprobantes.php" class="btn btn-outline btn-lg">Cancelar</a>
            <div style="margin-left:auto;text-align:right">
                <div style="font-size:.78rem;color:var(--col-muted)">Partida doble: débitos = créditos</div>
                <div id="estadoCuadre" style="font-size:.85rem;font-weight:600"></div>
            </div>
        </div>
    </div>
</form>

<!-- ── Cuenta select datalist ─────────────────────────────────── -->
<datalist id="listaCuentas">
    <?php foreach ($cuentasMovimiento as $c): ?>
    <option value="<?= e($c['codigo']) ?>" label="<?= e($c['codigo'] . ' – ' . $c['nombre']) ?>">
        <?= e($c['codigo'] . ' — ' . $c['nombre']) ?>
    </option>
    <?php endforeach; ?>
</datalist>

<script>
// Mapa de cuentas para autocomplete
const cuentasMap = {
    <?php foreach ($cuentasMovimiento as $c): ?>
    "<?= e($c['codigo']) ?>": { nombre: "<?= e(addslashes($c['nombre'])) ?>", naturaleza: "<?= $c['naturaleza'] ?>", clase: "<?= $c['clase'] ?>" },
    <?php endforeach; ?>
};
const centrosCosto = [
    <?php foreach ($centrosCosto as $cc): ?>
    { id: "<?= $cc['id'] ?>", label: "<?= e($cc['codigo'] . ' – ' . $cc['nombre']) ?>" },
    <?php endforeach; ?>
];
const tercerosList = [
    <?php foreach ($terceros as $t): ?>
    { id: "<?= $t['id'] ?>", label: "<?= e(addslashes($t['numero_documento'] . ' – ' . $t['nombre'])) ?>" },
    <?php endforeach; ?>
];

let lineaId = 0;

function formatoPeso(val) {
    return '$ ' + new Intl.NumberFormat('es-CO', {minimumFractionDigits: 0}).format(Math.abs(val));
}

function parseMonto(str) {
    return parseFloat(String(str).replace(/[^0-9.,]/g, '').replace(/\./g, '').replace(',', '.')) || 0;
}

function crearSelectOpciones(lista, placeholder) {
    let html = `<option value="">${placeholder}</option>`;
    lista.forEach(o => { html += `<option value="${o.id}">${o.label}</option>`; });
    return html;
}

function agregarLinea(datos = {}) {
    lineaId++;
    const idx = lineaId;
    const tr  = document.createElement('tr');
    tr.id = `linea_${idx}`;
    tr.style.borderBottom = '1px solid var(--col-border)';

    tr.innerHTML = `
        <td style="padding:.5rem .6rem;color:var(--col-muted);font-size:.8rem;text-align:center">${document.querySelectorAll('#cuerpoLineas tr').length + 1}</td>
        <td style="padding:.3rem .4rem">
            <input type="text"
                   name="lineas[${idx}][cuenta_codigo]"
                   class="form-control input-cuenta" required
                   list="listaCuentas"
                   placeholder="Código..."
                   value="${datos.cuenta || ''}"
                   style="font-family:monospace;font-size:.9rem;letter-spacing:.05em"
                   autocomplete="off">
        </td>
        <td style="padding:.3rem .6rem">
            <span class="nombre-cuenta" style="font-size:.82rem;color:var(--col-primary);font-weight:500">${datos.nombreCuenta || '<span style="color:var(--col-muted)">—</span>'}</span>
            <br><span class="clase-cuenta" style="font-size:.7rem;color:var(--col-muted)"></span>
        </td>
        <td style="padding:.3rem .4rem">
            <input type="text"
                   name="lineas[${idx}][descripcion]"
                   class="form-control" required
                   placeholder="Descripción del movimiento..."
                   value="${datos.descripcion || ''}"
                   style="font-size:.85rem">
        </td>
        <td style="padding:.3rem .4rem">
            <select name="lineas[${idx}][tercero_id]" class="form-select" style="font-size:.8rem">
                ${crearSelectOpciones(tercerosList, 'Tercero...')}
            </select>
        </td>
        <td style="padding:.3rem .4rem">
            <select name="lineas[${idx}][centro_costo_id]" class="form-select" style="font-size:.8rem">
                ${crearSelectOpciones(centrosCosto, 'C. Costo...')}
            </select>
        </td>
        <td style="padding:.3rem .4rem">
            <input type="text"
                   name="lineas[${idx}][debito]"
                   class="form-control input-monto text-right"
                   placeholder="0"
                   value="${datos.debito || ''}"
                   style="font-family:var(--font-display);font-size:.9rem;text-align:right">
        </td>
        <td style="padding:.3rem .4rem">
            <input type="text"
                   name="lineas[${idx}][credito]"
                   class="form-control input-monto text-right"
                   placeholder="0"
                   value="${datos.credito || ''}"
                   style="font-family:var(--font-display);font-size:.9rem;text-align:right">
        </td>
        <td style="padding:.3rem .4rem;text-align:center">
            <button type="button" class="btn-eliminar-linea"
                    onclick="eliminarLinea('linea_${idx}')"
                    style="background:none;border:none;color:var(--col-danger);cursor:pointer;font-size:1rem;padding:.2rem .4rem">
                <i class="fas fa-times"></i>
            </button>
        </td>
    `;

    document.getElementById('cuerpoLineas').appendChild(tr);
    configurarLinea(tr, datos);
    recalcularTotales();
    return tr;
}

function configurarLinea(tr, datos = {}) {
    const inputCuenta = tr.querySelector('.input-cuenta');
    const spanNombre  = tr.querySelector('.nombre-cuenta');
    const spanClase   = tr.querySelector('.clase-cuenta');

    function actualizarCuenta(codigo) {
        const info = cuentasMap[codigo.trim()];
        if (info) {
            spanNombre.innerHTML = info.nombre;
            const claseLabel = {D:'Naturaleza Débito',C:'Naturaleza Crédito'}[info.naturaleza] || '';
            const claseColor = {D:'var(--col-info)',C:'var(--col-success)'}[info.naturaleza] || 'var(--col-muted)';
            spanClase.innerHTML = `<span style="color:${claseColor}">${claseLabel}</span>`;
        } else if (codigo) {
            spanNombre.innerHTML = `<span style="color:var(--col-danger)">Cuenta no encontrada</span>`;
            spanClase.innerHTML  = '';
        } else {
            spanNombre.innerHTML = '<span style="color:var(--col-muted)">—</span>';
            spanClase.innerHTML  = '';
        }
    }

    if (datos.cuenta) actualizarCuenta(datos.cuenta);

    inputCuenta.addEventListener('input',  () => actualizarCuenta(inputCuenta.value));
    inputCuenta.addEventListener('change', () => actualizarCuenta(inputCuenta.value));

    // Montos: formato al salir del campo, mutex débito/crédito
    const inputs = tr.querySelectorAll('.input-monto');
    inputs.forEach(inp => {
        inp.addEventListener('blur', () => {
            const val = parseMonto(inp.value);
            inp.value = val > 0 ? new Intl.NumberFormat('es-CO').format(val) : '';
            recalcularTotales();
        });
        inp.addEventListener('focus', () => {
            inp.value = parseMonto(inp.value) || '';
            inp.select();
        });
        inp.addEventListener('keyup', recalcularTotales);
    });

    // Mutex: si llena débito, limpia crédito y viceversa
    const [inpDeb, inpCre] = inputs;
    if (inpDeb && inpCre) {
        inpDeb.addEventListener('input', () => { if (parseMonto(inpDeb.value) > 0) inpCre.value = ''; });
        inpCre.addEventListener('input', () => { if (parseMonto(inpCre.value) > 0) inpDeb.value = ''; });
    }
}

function eliminarLinea(id) {
    const tr = document.getElementById(id);
    if (document.querySelectorAll('#cuerpoLineas tr').length <= 2) {
        showToast('El comprobante debe tener al menos 2 líneas', 'warning');
        return;
    }
    tr && tr.remove();
    renumerarLineas();
    recalcularTotales();
}

function renumerarLineas() {
    document.querySelectorAll('#cuerpoLineas tr').forEach((tr, i) => {
        const numCell = tr.querySelector('td:first-child');
        if (numCell) numCell.textContent = i + 1;
    });
}

function recalcularTotales() {
    let totDeb = 0, totCre = 0;
    document.querySelectorAll('#cuerpoLineas tr').forEach(tr => {
        const inputs = tr.querySelectorAll('.input-monto');
        if (inputs.length >= 2) {
            totDeb += parseMonto(inputs[0].value);
            totCre += parseMonto(inputs[1].value);
        }
    });

    document.getElementById('totalDebitos').textContent  = formatoPeso(totDeb);
    document.getElementById('totalCreditos').textContent = formatoPeso(totCre);

    const diff = Math.abs(totDeb - totCre);
    const cuadra = diff < 0.01;
    const hayMovs = totDeb + totCre > 0;

    const indicador    = document.getElementById('indicadorCuadre');
    const estadoCuadre = document.getElementById('estadoCuadre');
    const filaDif      = document.getElementById('filaDiferencia');
    const btnCont      = document.getElementById('btnContabilizar');

    if (!hayMovs) {
        indicador.style.background = '#e2e6ea';
        indicador.style.color      = 'var(--col-muted)';
        indicador.innerHTML = 'Sin movimientos';
        estadoCuadre.innerHTML = '';
        filaDif.style.display  = 'none';
    } else if (cuadra) {
        indicador.style.background = 'rgba(22,163,74,.15)';
        indicador.style.color      = 'var(--col-success)';
        indicador.innerHTML = '<i class="fas fa-check-circle"></i> Cuadrado ✓';
        estadoCuadre.innerHTML = `<span style="color:var(--col-success)">✓ Débitos = Créditos = ${formatoPeso(totDeb)}</span>`;
        filaDif.style.display  = 'none';
        btnCont.disabled = false;
    } else {
        indicador.style.background = 'rgba(220,38,38,.12)';
        indicador.style.color      = 'var(--col-danger)';
        indicador.innerHTML = `<i class="fas fa-exclamation-triangle"></i> Diferencia: ${formatoPeso(diff)}`;
        estadoCuadre.innerHTML = `<span style="color:var(--col-danger)">⚠ Diferencia: ${formatoPeso(diff)}</span>`;
        document.getElementById('diferencia').textContent = formatoPeso(diff);
        filaDif.style.display = 'table-row';
        btnCont.disabled = true;
    }
}

// Balancear automáticamente
document.getElementById('btnBalancear').addEventListener('click', () => {
    let totDeb = 0, totCre = 0;
    document.querySelectorAll('#cuerpoLineas tr').forEach(tr => {
        const inputs = tr.querySelectorAll('.input-monto');
        if (inputs.length >= 2) {
            totDeb += parseMonto(inputs[0].value);
            totCre += parseMonto(inputs[1].value);
        }
    });
    const diff = totDeb - totCre;
    if (Math.abs(diff) < 0.01) { showToast('La partida ya cuadra', 'success'); return; }

    const nuevaLinea = agregarLinea({
        descripcion: 'Partida de cuadre automático',
        debito:  diff < 0 ? Math.abs(diff) : '',
        credito: diff > 0 ? diff            : '',
    });
    showToast('Se agregó línea de ajuste por ' + formatoPeso(Math.abs(diff)), 'info');
});

// Iniciar con 2 líneas vacías
document.getElementById('btnAgregarLinea').addEventListener('click',  () => agregarLinea());
document.getElementById('btnAgregarLinea2').addEventListener('click', () => agregarLinea());

// Actualizar período al cambiar fecha
document.querySelector('input[name="fecha"]').addEventListener('change', function() {
    const d = new Date(this.value + 'T12:00:00');
    const periodo = d.toISOString().slice(0, 7);
    document.getElementById('periodoMostrar').value = periodo;
});

// Inicializar
agregarLinea();
agregarLinea();
recalcularTotales();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
