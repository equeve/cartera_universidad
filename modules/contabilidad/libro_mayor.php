<?php
// modules/contabilidad/libro_mayor.php
$tituloPagina    = 'Libro Mayor';
$subtituloPagina = 'Movimientos detallados por cuenta contable';
require_once __DIR__ . '/../../includes/header.php';
requireRol(['admin', 'financiero']);

$db = Database::getInstance();

$cuenta = trim($_GET['cuenta'] ?? '');
$desde  = $_GET['desde'] ?? date('Y-m-01');
$hasta  = $_GET['hasta'] ?? date('Y-m-d');
$busq   = trim($_GET['q'] ?? '');

// Cuentas con movimiento para el selector
$cuentasDisponibles = $db->fetchAll(
    "SELECT DISTINCT c.codigo, c.nombre
     FROM puc_cuentas c
     JOIN movimientos_contables m ON m.cuenta_codigo = c.codigo
     JOIN comprobantes cp ON cp.id = m.comprobante_id AND cp.estado = 'contabilizado'
     ORDER BY c.codigo"
);

$cuentaInfo = null;
$movimientos = [];
$saldoInicial = 0;

if ($cuenta) {
    $cuentaInfo = $db->fetchOne("SELECT * FROM puc_cuentas WHERE codigo = ?", [$cuenta]);

    if ($cuentaInfo) {
        // Saldo inicial: todo lo contabilizado antes de la fecha "desde"
        $saldoPrevio = $db->fetchOne(
            "SELECT COALESCE(SUM(m.debito),0) AS deb, COALESCE(SUM(m.credito),0) AS cre
             FROM movimientos_contables m
             JOIN comprobantes cp ON cp.id = m.comprobante_id AND cp.estado = 'contabilizado'
             WHERE m.cuenta_codigo = ? AND cp.fecha < ?",
            [$cuenta, $desde]
        );
        $saldoInicial = $cuentaInfo['naturaleza'] === 'D'
            ? (float)$saldoPrevio['deb'] - (float)$saldoPrevio['cre']
            : (float)$saldoPrevio['cre'] - (float)$saldoPrevio['deb'];

        $where  = ['m.cuenta_codigo = ?', "cp.estado = 'contabilizado'", 'cp.fecha BETWEEN ? AND ?'];
        $params = [$cuenta, $desde, $hasta];
        if ($busq) {
            $where[] = '(cp.numero ILIKE ? OR m.descripcion ILIKE ?)';
            $params  = array_merge($params, ["%$busq%", "%$busq%"]);
        }

        $movimientos = $db->fetchAll(
            "SELECT m.*, cp.numero, cp.fecha, cp.tipo,
                    COALESCE(t.razon_social, t.nombres||' '||COALESCE(t.apellidos,'')) AS tercero,
                    cc.nombre AS centro_costo
             FROM movimientos_contables m
             JOIN comprobantes cp ON cp.id = m.comprobante_id
             LEFT JOIN terceros t ON t.id = m.tercero_id
             LEFT JOIN centros_costo cc ON cc.id = m.centro_costo_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY cp.fecha, cp.numero, m.linea",
            $params
        );
    }
}

$tiposLabel = [
    'comprobante_ingreso'=>'CI','comprobante_egreso'=>'CE','nota_contable'=>'NC',
    'causacion'=>'CA','ajuste'=>'AJ','apertura'=>'AP','cierre'=>'CL',
];
?>

<!-- Selector de cuenta -->
<div class="card mb-3">
    <div class="card-body" style="padding:.8rem 1.2rem">
        <form method="GET" class="flex gap-2 flex-wrap items-center">
            <select name="cuenta" class="form-select" style="width:340px" required onchange="this.form.submit()">
                <option value="">Seleccione una cuenta...</option>
                <?php foreach ($cuentasDisponibles as $cd): ?>
                <option value="<?= e($cd['codigo']) ?>" <?= $cuenta === $cd['codigo'] ? 'selected' : '' ?>>
                    <?= e($cd['codigo']) ?> — <?= e($cd['nombre']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="desde" class="form-control" style="width:145px" value="<?= e($desde) ?>">
            <span style="color:var(--col-muted)">–</span>
            <input type="date" name="hasta" class="form-control" style="width:145px" value="<?= e($hasta) ?>">
            <div class="search-bar" style="max-width:220px">
                <i class="fas fa-search"></i>
                <input type="text" name="q" placeholder="N° comprobante o desc..." value="<?= e($busq) ?>">
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Consultar</button>
            <?php if ($cuenta): ?>
            <button type="button" onclick="window.print()" class="btn btn-outline btn-sm"><i class="fas fa-print"></i> Imprimir</button>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if (!$cuenta): ?>
<div class="card">
    <div class="card-body" style="text-align:center;padding:3rem;color:var(--col-muted)">
        <i class="fas fa-book" style="font-size:2.5rem;display:block;margin-bottom:1rem;opacity:.3"></i>
        Seleccione una cuenta contable para ver su libro mayor
    </div>
</div>
<?php elseif (!$cuentaInfo): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> Cuenta no encontrada.</div>
<?php else:
    $totDeb = array_sum(array_column($movimientos, 'debito'));
    $totCre = array_sum(array_column($movimientos, 'credito'));
    $saldoFinal = $cuentaInfo['naturaleza'] === 'D'
        ? $saldoInicial + $totDeb - $totCre
        : $saldoInicial + $totCre - $totDeb;
?>

<!-- Encabezado de cuenta -->
<div class="card mb-3">
    <div class="card-body" style="padding:1.2rem 1.4rem">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem">
            <div>
                <code style="font-size:1.3rem;font-weight:700;color:var(--col-primary);letter-spacing:.05em"><?= e($cuentaInfo['codigo']) ?></code>
                <h2 style="font-family:var(--font-display);font-size:1.3rem;color:var(--col-primary);margin-top:.2rem"><?= e($cuentaInfo['nombre']) ?></h2>
                <span class="badge badge-<?= $cuentaInfo['naturaleza']==='D'?'info':'success' ?>"><?= $cuentaInfo['naturaleza']==='D'?'Naturaleza Débito':'Naturaleza Crédito' ?></span>
            </div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1.5rem;text-align:right">
                <div>
                    <div style="font-size:.72rem;color:var(--col-muted);text-transform:uppercase">Saldo Inicial</div>
                    <div style="font-weight:700;font-size:1rem"><?= formatoPeso(abs($saldoInicial)) ?></div>
                </div>
                <div>
                    <div style="font-size:.72rem;color:var(--col-muted);text-transform:uppercase">Movimiento Período</div>
                    <div style="font-weight:700;font-size:1rem;color:var(--col-info)"><?= formatoPeso($totDeb) ?> / <span style="color:var(--col-success)"><?= formatoPeso($totCre) ?></span></div>
                </div>
                <div>
                    <div style="font-size:.72rem;color:var(--col-muted);text-transform:uppercase">Saldo Final</div>
                    <div style="font-weight:700;font-size:1.15rem;color:<?= $saldoFinal>=0?'var(--col-success)':'var(--col-danger)' ?>"><?= formatoPeso(abs($saldoFinal)) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Movimientos del Período</h3>
        <small style="color:var(--col-muted)"><?= count($movimientos) ?> movimientos</small>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Fecha</th><th>Comprobante</th><th>Descripción</th>
                    <th>Tercero</th><th>C. Costo</th>
                    <th class="text-right">Débito</th><th class="text-right">Crédito</th>
                    <th class="text-right">Saldo</th>
                </tr>
            </thead>
            <tbody>
                <tr style="background:rgba(26,58,92,.04)">
                    <td colspan="7" style="font-style:italic;font-size:.85rem;color:var(--col-muted)">Saldo inicial al <?= formatoFecha($desde) ?></td>
                    <td class="text-right font-bold"><?= formatoPeso(abs($saldoInicial)) ?></td>
                </tr>
                <?php
                $saldoCorrido = $saldoInicial;
                foreach ($movimientos as $mov):
                    $saldoCorrido += $cuentaInfo['naturaleza'] === 'D'
                        ? ($mov['debito'] - $mov['credito'])
                        : ($mov['credito'] - $mov['debito']);
                    $pref = $tiposLabel[$mov['tipo']] ?? '??';
                ?>
                <tr>
                    <td><?= formatoFecha($mov['fecha']) ?></td>
                    <td>
                        <a href="<?= APP_URL ?>/modules/contabilidad/ver_comprobante.php?id=<?= $mov['comprobante_id'] ?>" style="font-family:monospace;font-size:.82rem;color:var(--col-primary)">
                            <?= e($mov['numero']) ?>
                        </a>
                        <span class="badge badge-secondary" style="font-size:.62rem;margin-left:.3rem"><?= $pref ?></span>
                    </td>
                    <td><small><?= e($mov['descripcion']) ?></small></td>
                    <td><small style="color:var(--col-muted)"><?= e($mov['tercero'] ?? '–') ?></small></td>
                    <td><small style="color:var(--col-muted)"><?= e($mov['centro_costo'] ?? '–') ?></small></td>
                    <td class="text-right" style="color:var(--col-info);font-weight:600"><?= $mov['debito']>0?formatoPeso($mov['debito']):'' ?></td>
                    <td class="text-right" style="color:var(--col-success);font-weight:600"><?= $mov['credito']>0?formatoPeso($mov['credito']):'' ?></td>
                    <td class="text-right font-bold" style="color:<?= $saldoCorrido>=0?'var(--col-text)':'var(--col-danger)' ?>"><?= formatoPeso(abs($saldoCorrido)) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($movimientos)): ?>
                <tr><td colspan="8" class="text-center text-muted" style="padding:2rem">Sin movimientos en el período seleccionado</td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background:rgba(26,58,92,.07);font-weight:700">
                    <td colspan="5" style="font-family:var(--font-display);color:var(--col-primary)">TOTALES DEL PERÍODO</td>
                    <td class="text-right" style="color:var(--col-info)"><?= formatoPeso($totDeb) ?></td>
                    <td class="text-right" style="color:var(--col-success)"><?= formatoPeso($totCre) ?></td>
                    <td class="text-right" style="color:<?= $saldoFinal>=0?'var(--col-success)':'var(--col-danger)' ?>"><?= formatoPeso(abs($saldoFinal)) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
