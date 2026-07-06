<?php
// modules/contabilidad/plan_cuentas.php
$tituloPagina    = 'Plan Único de Cuentas';
$subtituloPagina = 'PUC — Decreto 2650/1993 adaptado IES Colombia';
require_once __DIR__ . '/../../includes/header.php';
requireRol(['admin', 'financiero']);

$db = Database::getInstance();

// ── Alta de cuenta nueva ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarCSRF();
    $codigo  = strtoupper(trim($_POST['codigo'] ?? ''));
    $nombre  = trim($_POST['nombre'] ?? '');
    $nat     = $_POST['naturaleza'] ?? 'D';
    $padre   = trim($_POST['codigo_padre'] ?? '') ?: null;
    $acepta  = isset($_POST['acepta_movimiento']);
    $desc    = trim($_POST['descripcion'] ?? '');

    if ($codigo && $nombre) {
        $len   = strlen($codigo);
        $nivel = $len <= 1 ? 1 : ($len <= 2 ? 2 : ($len <= 4 ? 3 : ($len <= 6 ? 4 : 5)));
        $clase = substr($codigo, 0, 1);
        try {
            $db->query(
                "INSERT INTO puc_cuentas (codigo,nombre,naturaleza,nivel,clase,codigo_padre,acepta_movimiento,descripcion)
                 VALUES (?,?,?,?,?,?,?,?)",
                [$codigo, $nombre, $nat, $nivel, $clase, $padre, $acepta ? 'true' : 'false', $desc]
            );
            $_SESSION['flash_success'] = "Cuenta $codigo creada correctamente.";
        } catch (Exception $e) {
            $_SESSION['flash_error'] = 'Error: ' . $e->getMessage();
        }
    } else {
        $_SESSION['flash_error'] = 'Código y nombre son obligatorios.';
    }
    header('Location: ' . APP_URL . '/modules/contabilidad/plan_cuentas.php?' . http_build_query($_GET));
    exit;
}

$busq     = trim($_GET['q'] ?? '');
$clase    = $_GET['clase'] ?? '';
$nivel    = (int)($_GET['nivel'] ?? 0);
$solo_mov = isset($_GET['mov']);

$where  = ['1=1'];
$params = [];
if ($busq)    { $where[] = '(c.codigo ILIKE ? OR c.nombre ILIKE ?)'; $params = array_merge($params, ["%$busq%","%$busq%"]); }
if ($clase)   { $where[] = 'c.clase = ?'; $params[] = $clase; }
if ($nivel)   { $where[] = 'c.nivel = ?'; $params[] = $nivel; }
if ($solo_mov){ $where[] = 'c.acepta_movimiento = TRUE'; }

$cuentas = $db->fetchAll(
    "SELECT c.*,
            COALESCE(SUM(m.debito),0)  AS tot_deb,
            COALESCE(SUM(m.credito),0) AS tot_cre
     FROM puc_cuentas c
     LEFT JOIN movimientos_contables m ON m.cuenta_codigo = c.codigo
     LEFT JOIN comprobantes cp ON cp.id = m.comprobante_id AND cp.estado='contabilizado'
     WHERE " . implode(' AND ', $where) . "
     GROUP BY c.id
     ORDER BY c.codigo
     LIMIT 500",
    $params
);

$stats = $db->fetchOne(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN acepta_movimiento THEN 1 ELSE 0 END) AS con_mov,
            SUM(CASE WHEN nivel=1 THEN 1 ELSE 0 END) AS clases,
            SUM(CASE WHEN nivel=2 THEN 1 ELSE 0 END) AS grupos,
            SUM(CASE WHEN nivel=3 THEN 1 ELSE 0 END) AS cuentas,
            SUM(CASE WHEN nivel>=4 THEN 1 ELSE 0 END) AS auxiliares
     FROM puc_cuentas"
);

$clases = [
    '1'=>['ACTIVOS','primary'],   '2'=>['PASIVOS','danger'],
    '3'=>['PATRIMONIO','info'],   '4'=>['INGRESOS','success'],
    '5'=>['GASTOS','warning'],    '6'=>['COSTOS','accent'],
    '8'=>['ORDEN DEUDORAS','secondary'], '9'=>['ORDEN ACREEDORAS','secondary'],
];
?>

<div class="stats-grid mb-4" style="grid-template-columns:repeat(6,1fr)">
<?php foreach ([
    ['Total Cuentas',$stats['total'],'list','primary'],
    ['Acepta Mov.',$stats['con_mov'],'exchange-alt','accent'],
    ['Clases',$stats['clases'],'layer-group','info'],
    ['Grupos',$stats['grupos'],'object-group','success'],
    ['Cuentas',$stats['cuentas'],'book','warning'],
    ['Auxiliares',$stats['auxiliares'],'code-branch','secondary'],
] as [$lbl,$val,$ico,$col]): ?>
<div class="stat-card" style="padding:.9rem">
    <div class="stat-icon <?= $col ?>" style="width:38px;height:38px;font-size:.9rem"><i class="fas fa-<?= $ico ?>"></i></div>
    <div><div class="stat-value" style="font-size:1.1rem"><?= number_format($val) ?></div><div class="stat-label" style="font-size:.72rem"><?= $lbl ?></div></div>
</div>
<?php endforeach; ?>
</div>

<div class="card mb-3">
    <div class="card-body" style="padding:.8rem 1.2rem">
        <form method="GET" class="flex gap-2 flex-wrap items-center">
            <div class="search-bar" style="max-width:250px">
                <i class="fas fa-search"></i>
                <input type="text" name="q" placeholder="Código o nombre..." value="<?= e($busq) ?>">
            </div>
            <select name="clase" class="form-select" style="width:auto">
                <option value="">Todas las clases</option>
                <?php foreach ($clases as $k=>[$lbl,]): ?>
                <option value="<?= $k ?>" <?= $clase===$k?'selected':'' ?>><?= $k ?> — <?= $lbl ?></option>
                <?php endforeach; ?>
            </select>
            <select name="nivel" class="form-select" style="width:auto">
                <option value="">Todos los niveles</option>
                <?php for ($i=1;$i<=5;$i++): ?>
                <option value="<?= $i ?>" <?= $nivel===$i?'selected':'' ?>>Nivel <?= $i ?></option>
                <?php endfor; ?>
            </select>
            <label style="font-size:.85rem;display:flex;align-items:center;gap:.4rem;cursor:pointer;white-space:nowrap">
                <input type="checkbox" name="mov" <?= $solo_mov?'checked':'' ?>> Solo con movimiento
            </label>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Filtrar</button>
            <a href="?" class="btn btn-outline btn-sm">Limpiar</a>
            <button type="button" data-modal="modalNueva" class="btn btn-accent btn-sm" style="margin-left:auto">
                <i class="fas fa-plus"></i> Nueva Cuenta
            </button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-book" style="color:var(--col-accent)"></i> Plan Único de Cuentas</h3>
        <small style="color:var(--col-muted)"><?= count($cuentas) ?> cuentas mostradas</small>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Código</th><th>Nombre de la Cuenta</th><th>Clase</th>
                    <th style="text-align:center">Niv.</th><th style="text-align:center">Nat.</th>
                    <th style="text-align:center">Mov.</th>
                    <th class="text-right">Débitos</th><th class="text-right">Créditos</th>
                    <th class="text-right">Saldo</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($cuentas as $c):
                $pad = ($c['nivel']-1)*16;
                [$clNom,$clCol] = $clases[$c['clase']] ?? ['?','secondary'];
                $saldo = $c['naturaleza']==='D'
                    ? (float)$c['tot_deb'] - (float)$c['tot_cre']
                    : (float)$c['tot_cre'] - (float)$c['tot_deb'];
                $fw = $c['nivel']<=2 ? '700' : ($c['nivel']===3 ? '600' : '400');
                $bg = $c['nivel']===1 ? 'rgba(26,58,92,.06)' : ($c['nivel']===2 ? 'rgba(26,58,92,.03)' : 'transparent');
            ?>
            <tr style="background:<?= $bg ?>;<?= !$c['activa']?'opacity:.4':'' ?>">
                <td><code style="font-weight:700;font-size:.88rem;letter-spacing:.06em;color:<?= $c['nivel']<=1?'var(--col-primary)':($c['nivel']<=2?'var(--col-primary-l)':'var(--col-muted)') ?>"><?= e($c['codigo']) ?></code></td>
                <td><span style="display:inline-block;margin-left:<?= $pad ?>px;font-weight:<?= $fw ?>;font-size:<?= $c['nivel']<=1?'1rem':($c['nivel']<=2?'.9rem':'.85rem') ?>"><?= $c['nivel']<=2 ? strtoupper(e($c['nombre'])) : e($c['nombre']) ?></span></td>
                <td><span class="badge badge-<?= $clCol ?>" style="font-size:.65rem"><?= $clNom ?></span></td>
                <td style="text-align:center"><span style="font-size:.72rem;background:var(--col-border);padding:.1rem .4rem;border-radius:10px"><?= $c['nivel'] ?></span></td>
                <td style="text-align:center"><span style="font-size:.72rem;font-weight:700;color:<?= $c['naturaleza']==='D'?'var(--col-info)':'var(--col-success)' ?>"><?= $c['naturaleza']==='D'?'DB':'CR' ?></span></td>
                <td style="text-align:center"><?= $c['acepta_movimiento'] ? '<i class="fas fa-check" style="color:var(--col-success);font-size:.8rem"></i>' : '<span style="color:var(--col-border)">–</span>' ?></td>
                <td class="text-right" style="font-size:.82rem;color:var(--col-muted)"><?= $c['tot_deb']>0?formatoPeso($c['tot_deb']):'' ?></td>
                <td class="text-right" style="font-size:.82rem;color:var(--col-muted)"><?= $c['tot_cre']>0?formatoPeso($c['tot_cre']):'' ?></td>
                <td class="text-right" style="font-size:.83rem;font-weight:<?= abs($saldo)>0?'700':'400' ?>;color:<?= $saldo>0?'var(--col-success)':($saldo<0?'var(--col-danger)':'var(--col-muted)') ?>"><?= abs($saldo)>0?formatoPeso(abs($saldo)):'' ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($cuentas)): ?>
            <tr><td colspan="9" class="text-center text-muted" style="padding:3rem">No se encontraron cuentas</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal-overlay" id="modalNueva">
    <div class="modal" style="max-width:540px">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-plus-circle" style="color:var(--col-accent)"></i> Nueva Cuenta PUC</h3>
            <button class="modal-close"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <div class="modal-body">
                <div class="alert alert-info" style="font-size:.82rem;padding:.6rem .9rem">
                    <i class="fas fa-info-circle"></i> El nivel se detecta por longitud del código: 1 dígito=Clase, 2=Grupo, 4=Cuenta, 6=Subcuenta, 8+=Auxiliar
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label>Código PUC *</label>
                        <input type="text" name="codigo" class="form-control" required placeholder="Ej: 141010" maxlength="20" style="font-family:monospace;letter-spacing:.1em">
                    </div>
                    <div class="form-group">
                        <label>Código Padre</label>
                        <input type="text" name="codigo_padre" class="form-control" placeholder="Cuenta padre" maxlength="20" style="font-family:monospace;letter-spacing:.1em">
                    </div>
                </div>
                <div class="form-group">
                    <label>Nombre de la Cuenta *</label>
                    <input type="text" name="nombre" class="form-control" required placeholder="Nombre descriptivo...">
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label>Naturaleza *</label>
                        <select name="naturaleza" class="form-select">
                            <option value="D">Débito — Activos, Gastos, Costos</option>
                            <option value="C">Crédito — Pasivos, Patrimonio, Ingresos</option>
                        </select>
                    </div>
                    <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:.5rem">
                        <label style="cursor:pointer;display:flex;align-items:center;gap:.5rem">
                            <input type="checkbox" name="acepta_movimiento"> Acepta movimientos contables
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label>Descripción</label>
                    <textarea name="descripcion" class="form-control" rows="2" placeholder="Descripción o uso de la cuenta..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline modal-close">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Crear Cuenta</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
