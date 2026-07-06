<?php
// modules/contabilidad/estados_financieros.php
$tituloPagina    = 'Estados Financieros';
$subtituloPagina = 'Balance General y Estado de Resultados';
require_once __DIR__ . '/../../includes/header.php';
requireRol(['admin', 'financiero']);

$db = Database::getInstance();

$periodo = $_GET['periodo'] ?? date('Y-m');
$corte   = $_GET['corte'] ?? date('Y-m-t', strtotime($periodo.'-01')); // último día del mes
$vista   = $_GET['vista'] ?? 'balance'; // balance | resultados

$periodos = $db->fetchAll("SELECT DISTINCT TO_CHAR(fecha,'YYYY-MM') AS p FROM comprobantes WHERE estado='contabilizado' ORDER BY p DESC LIMIT 24");

// ── Saldos acumulados a la fecha de corte (para Balance General) ──
function saldosPorClase(Database $db, string $corte, array $clases): array {
    $placeholders = implode(',', array_fill(0, count($clases), '?'));
    return $db->fetchAll(
        "SELECT c.codigo, c.nombre, c.clase, c.nivel, c.naturaleza,
                COALESCE(SUM(m.debito),0)  AS deb,
                COALESCE(SUM(m.credito),0) AS cre
         FROM puc_cuentas c
         LEFT JOIN movimientos_contables m ON m.cuenta_codigo = c.codigo
         LEFT JOIN comprobantes cp ON cp.id = m.comprobante_id
             AND cp.estado = 'contabilizado' AND cp.fecha <= ?
         WHERE c.acepta_movimiento = TRUE AND c.clase IN ($placeholders)
         GROUP BY c.codigo,c.nombre,c.clase,c.nivel,c.naturaleza
         HAVING COALESCE(SUM(m.debito),0) + COALESCE(SUM(m.credito),0) > 0
         ORDER BY c.codigo",
        array_merge([$corte], $clases)
    );
}

// ── Movimientos del período (para Estado de Resultados) ──
function movimientosPeriodo(Database $db, string $periodo, array $clases): array {
    $placeholders = implode(',', array_fill(0, count($clases), '?'));
    return $db->fetchAll(
        "SELECT c.codigo, c.nombre, c.clase, c.nivel, c.naturaleza,
                COALESCE(SUM(m.debito),0)  AS deb,
                COALESCE(SUM(m.credito),0) AS cre
         FROM puc_cuentas c
         LEFT JOIN movimientos_contables m ON m.cuenta_codigo = c.codigo
         LEFT JOIN comprobantes cp ON cp.id = m.comprobante_id
             AND cp.estado='contabilizado' AND TO_CHAR(cp.fecha,'YYYY-MM') = ?
         WHERE c.acepta_movimiento = TRUE AND c.clase IN ($placeholders)
         GROUP BY c.codigo,c.nombre,c.clase,c.nivel,c.naturaleza
         HAVING COALESCE(SUM(m.debito),0) + COALESCE(SUM(m.credito),0) > 0
         ORDER BY c.codigo",
        array_merge([$periodo], $clases)
    );
}

function saldoCuenta(array $c): float {
    return $c['naturaleza'] === 'D'
        ? max(0, (float)$c['deb'] - (float)$c['cre'])
        : max(0, (float)$c['cre'] - (float)$c['deb']);
}
?>

<div class="card mb-3"><div class="card-body" style="padding:.8rem 1.2rem">
<form method="GET" class="flex gap-2 flex-wrap items-center">
    <div class="flex gap-1" style="background:var(--col-bg);border-radius:var(--radius-sm);padding:.2rem">
        <a href="?vista=balance&periodo=<?= e($periodo) ?>" class="btn btn-sm <?= $vista==='balance'?'btn-primary':'btn-outline' ?>" style="border:none">
            <i class="fas fa-balance-scale"></i> Balance General
        </a>
        <a href="?vista=resultados&periodo=<?= e($periodo) ?>" class="btn btn-sm <?= $vista==='resultados'?'btn-primary':'btn-outline' ?>" style="border:none">
            <i class="fas fa-chart-line"></i> Estado de Resultados
        </a>
    </div>
    <label style="font-size:.85rem;margin-left:1rem">Período:</label>
    <select name="periodo" class="form-select" style="width:auto" onchange="this.form.submit()">
        <?php $hoyListo=false; foreach ($periodos as $p): if($p['p']===date('Y-m')) $hoyListo=true; ?>
        <option value="<?= e($p['p']) ?>" <?= $periodo===$p['p']?'selected':'' ?>><?= e($p['p']) ?></option>
        <?php endforeach; ?>
        <?php if (!$hoyListo): ?>
        <option value="<?= date('Y-m') ?>" <?= $periodo===date('Y-m')?'selected':'' ?>><?= date('Y-m') ?> (actual)</option>
        <?php endif; ?>
    </select>
    <input type="hidden" name="vista" value="<?= e($vista) ?>">
    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-sync"></i> Generar</button>
    <button type="button" onclick="window.print()" class="btn btn-outline btn-sm"><i class="fas fa-print"></i> Imprimir</button>
</form>
</div></div>

<?php if ($vista === 'balance'):
    $corteFecha = date('Y-m-t', strtotime($periodo.'-01'));
    $activos    = saldosPorClase($db, $corteFecha, ['1']);
    $pasivos    = saldosPorClase($db, $corteFecha, ['2']);
    $patrimonio = saldosPorClase($db, $corteFecha, ['3']);

    $totActivos    = array_sum(array_map('saldoCuenta', $activos));
    $totPasivos    = array_sum(array_map('saldoCuenta', $pasivos));
    $totPatrimonio = array_sum(array_map('saldoCuenta', $patrimonio));

    // Utilidad/pérdida del ejercicio a la fecha (ingresos - gastos acumulados del año)
    $anio = substr($periodo, 0, 4);
    $ingresosAcum = $db->fetchOne(
        "SELECT COALESCE(SUM(m.credito)-SUM(m.debito),0) AS total
         FROM movimientos_contables m
         JOIN comprobantes cp ON cp.id=m.comprobante_id AND cp.estado='contabilizado'
         JOIN puc_cuentas c ON c.codigo = m.cuenta_codigo
         WHERE c.clase='4' AND cp.fecha BETWEEN ? AND ?",
        ["$anio-01-01", $corteFecha]
    )['total'] ?? 0;
    $gastosAcum = $db->fetchOne(
        "SELECT COALESCE(SUM(m.debito)-SUM(m.credito),0) AS total
         FROM movimientos_contables m
         JOIN comprobantes cp ON cp.id=m.comprobante_id AND cp.estado='contabilizado'
         JOIN puc_cuentas c ON c.codigo = m.cuenta_codigo
         WHERE c.clase='5' AND cp.fecha BETWEEN ? AND ?",
        ["$anio-01-01", $corteFecha]
    )['total'] ?? 0;
    $resultadoEjercicio = (float)$ingresosAcum - (float)$gastosAcum;
    $totPatrimonioConResultado = $totPatrimonio + $resultadoEjercicio;
    $totPasivoPatrimonio = $totPasivos + $totPatrimonioConResultado;
    $cuadraBalance = abs($totActivos - $totPasivoPatrimonio) < 1;
?>

<?php if (count($activos)+count($pasivos)+count($patrimonio) > 0): ?>
<div class="alert <?= $cuadraBalance ? 'alert-success' : 'alert-danger' ?>">
    <i class="fas fa-<?= $cuadraBalance?'check-circle':'exclamation-triangle' ?>"></i>
    <?php if ($cuadraBalance): ?>
        <strong>Balance cuadrado.</strong> Activos = Pasivo + Patrimonio = <?= formatoPeso($totActivos) ?>
    <?php else: ?>
        <strong>Balance descuadrado.</strong> Activos: <?= formatoPeso($totActivos) ?> ≠ Pasivo+Patrimonio: <?= formatoPeso($totPasivoPatrimonio) ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.2rem">
<!-- ACTIVOS -->
<div class="card">
    <div class="card-header" style="background:rgba(26,58,92,.06)">
        <h3 class="card-title">ACTIVOS</h3>
    </div>
    <div class="table-wrapper"><table>
        <tbody>
        <?php foreach ($activos as $c):
            $s = saldoCuenta($c);
            $fw = $c['nivel']<=2?'700':'400';
            $bg = $c['nivel']<=2?'rgba(26,58,92,.03)':'transparent';
        ?>
        <tr style="background:<?= $bg ?>">
            <td style="padding:.4rem .7rem"><code style="font-size:.78rem;color:var(--col-muted)"><?= e($c['codigo']) ?></code></td>
            <td style="padding:.4rem .7rem;font-weight:<?= $fw ?>;font-size:.85rem;padding-left:<?= ($c['nivel']-1)*12+10 ?>px"><?= e($c['nombre']) ?></td>
            <td style="padding:.4rem .7rem;text-align:right;font-weight:<?= $fw ?>;font-size:.85rem"><?= formatoPeso($s) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($activos)): ?><tr><td colspan="3" class="text-center text-muted" style="padding:2rem">Sin saldos</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr style="background:rgba(26,58,92,.1);font-weight:700">
            <td colspan="2" style="padding:.6rem .7rem;color:var(--col-primary)">TOTAL ACTIVOS</td>
            <td style="padding:.6rem .7rem;text-align:right;color:var(--col-primary);font-size:1rem"><?= formatoPeso($totActivos) ?></td>
        </tr></tfoot>
    </table></div>
</div>

<!-- PASIVO + PATRIMONIO -->
<div>
    <div class="card mb-3">
        <div class="card-header" style="background:rgba(220,38,38,.05)"><h3 class="card-title">PASIVOS</h3></div>
        <div class="table-wrapper"><table>
            <tbody>
            <?php foreach ($pasivos as $c): $s=saldoCuenta($c); $fw=$c['nivel']<=2?'700':'400'; $bg=$c['nivel']<=2?'rgba(220,38,38,.02)':'transparent'; ?>
            <tr style="background:<?= $bg ?>">
                <td style="padding:.4rem .7rem"><code style="font-size:.78rem;color:var(--col-muted)"><?= e($c['codigo']) ?></code></td>
                <td style="padding:.4rem .7rem;font-weight:<?= $fw ?>;font-size:.85rem;padding-left:<?= ($c['nivel']-1)*12+10 ?>px"><?= e($c['nombre']) ?></td>
                <td style="padding:.4rem .7rem;text-align:right;font-weight:<?= $fw ?>;font-size:.85rem"><?= formatoPeso($s) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($pasivos)): ?><tr><td colspan="3" class="text-center text-muted" style="padding:1rem">Sin saldos</td></tr><?php endif; ?>
            </tbody>
            <tfoot><tr style="background:rgba(220,38,38,.08);font-weight:700">
                <td colspan="2" style="padding:.6rem .7rem;color:var(--col-danger)">TOTAL PASIVOS</td>
                <td style="padding:.6rem .7rem;text-align:right;color:var(--col-danger)"><?= formatoPeso($totPasivos) ?></td>
            </tr></tfoot>
        </table></div>
    </div>

    <div class="card">
        <div class="card-header" style="background:rgba(2,132,199,.05)"><h3 class="card-title">PATRIMONIO</h3></div>
        <div class="table-wrapper"><table>
            <tbody>
            <?php foreach ($patrimonio as $c): $s=saldoCuenta($c); $fw=$c['nivel']<=2?'700':'400'; $bg=$c['nivel']<=2?'rgba(2,132,199,.02)':'transparent'; ?>
            <tr style="background:<?= $bg ?>">
                <td style="padding:.4rem .7rem"><code style="font-size:.78rem;color:var(--col-muted)"><?= e($c['codigo']) ?></code></td>
                <td style="padding:.4rem .7rem;font-weight:<?= $fw ?>;font-size:.85rem;padding-left:<?= ($c['nivel']-1)*12+10 ?>px"><?= e($c['nombre']) ?></td>
                <td style="padding:.4rem .7rem;text-align:right;font-weight:<?= $fw ?>;font-size:.85rem"><?= formatoPeso($s) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr style="background:rgba(2,132,199,.04)">
                <td style="padding:.4rem .7rem"></td>
                <td style="padding:.4rem .7rem;font-size:.85rem;font-style:italic">Resultado del ejercicio (<?= $anio ?>)</td>
                <td style="padding:.4rem .7rem;text-align:right;font-size:.85rem;color:<?= $resultadoEjercicio>=0?'var(--col-success)':'var(--col-danger)' ?>"><?= $resultadoEjercicio>=0?'':'-' ?><?= formatoPeso(abs($resultadoEjercicio)) ?></td>
            </tr>
            </tbody>
            <tfoot><tr style="background:rgba(2,132,199,.1);font-weight:700">
                <td colspan="2" style="padding:.6rem .7rem;color:var(--col-info)">TOTAL PATRIMONIO</td>
                <td style="padding:.6rem .7rem;text-align:right;color:var(--col-info)"><?= formatoPeso($totPatrimonioConResultado) ?></td>
            </tr></tfoot>
        </table></div>
    </div>
</div>
</div>

<div class="card" style="margin-top:1.2rem">
    <div class="card-body" style="padding:1rem 1.4rem;background:rgba(26,58,92,.05)">
        <div style="display:flex;justify-content:space-between;font-size:1rem;font-weight:700">
            <span style="color:var(--col-primary)">TOTAL ACTIVOS: <?= formatoPeso($totActivos) ?></span>
            <span style="color:<?= $cuadraBalance?'var(--col-success)':'var(--col-danger)' ?>">TOTAL PASIVO + PATRIMONIO: <?= formatoPeso($totPasivoPatrimonio) ?></span>
        </div>
    </div>
</div>

<?php else: // ESTADO DE RESULTADOS
    $ingresos = movimientosPeriodo($db, $periodo, ['4']);
    $gastos   = movimientosPeriodo($db, $periodo, ['5']);

    $totIngresos = array_sum(array_map('saldoCuenta', $ingresos));
    $totGastos   = array_sum(array_map('saldoCuenta', $gastos));
    $resultado   = $totIngresos - $totGastos;
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Estado de Resultados · Período <?= e($periodo) ?></h3>
        <small style="color:var(--col-muted)"><?= date('d/m/Y H:i') ?></small>
    </div>
    <div class="card-body">
        <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:var(--col-success);margin-bottom:.5rem;font-weight:700">Ingresos Operacionales</div>
        <table style="width:100%;margin-bottom:1.2rem">
        <?php foreach ($ingresos as $c): $s = saldoCuenta($c); $fw=$c['nivel']<=2?'700':'400'; ?>
        <tr>
            <td style="padding:.35rem .5rem;width:90px"><code style="font-size:.78rem;color:var(--col-muted)"><?= e($c['codigo']) ?></code></td>
            <td style="padding:.35rem .5rem;font-weight:<?= $fw ?>;font-size:.85rem;padding-left:<?= ($c['nivel']-1)*12 ?>px"><?= e($c['nombre']) ?></td>
            <td style="padding:.35rem .5rem;text-align:right;font-weight:<?= $fw ?>;font-size:.85rem;color:var(--col-success)"><?= formatoPeso($s) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($ingresos)): ?><tr><td colspan="3" class="text-center text-muted" style="padding:1rem">Sin ingresos en el período</td></tr><?php endif; ?>
        <tr style="background:rgba(22,163,74,.08);font-weight:700">
            <td colspan="2" style="padding:.5rem">TOTAL INGRESOS</td>
            <td style="padding:.5rem;text-align:right;color:var(--col-success)"><?= formatoPeso($totIngresos) ?></td>
        </tr>
        </table>

        <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:var(--col-danger);margin-bottom:.5rem;font-weight:700">Gastos</div>
        <table style="width:100%;margin-bottom:1.2rem">
        <?php foreach ($gastos as $c): $s = saldoCuenta($c); $fw=$c['nivel']<=2?'700':'400'; ?>
        <tr>
            <td style="padding:.35rem .5rem;width:90px"><code style="font-size:.78rem;color:var(--col-muted)"><?= e($c['codigo']) ?></code></td>
            <td style="padding:.35rem .5rem;font-weight:<?= $fw ?>;font-size:.85rem;padding-left:<?= ($c['nivel']-1)*12 ?>px"><?= e($c['nombre']) ?></td>
            <td style="padding:.35rem .5rem;text-align:right;font-weight:<?= $fw ?>;font-size:.85rem;color:var(--col-danger)"><?= formatoPeso($s) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($gastos)): ?><tr><td colspan="3" class="text-center text-muted" style="padding:1rem">Sin gastos en el período</td></tr><?php endif; ?>
        <tr style="background:rgba(220,38,38,.08);font-weight:700">
            <td colspan="2" style="padding:.5rem">TOTAL GASTOS</td>
            <td style="padding:.5rem;text-align:right;color:var(--col-danger)"><?= formatoPeso($totGastos) ?></td>
        </tr>
        </table>

        <div style="border-top:2px solid var(--col-primary);padding-top:1rem;display:flex;justify-content:space-between;align-items:center">
            <span style="font-family:var(--font-display);font-size:1.1rem;color:var(--col-primary)">RESULTADO DEL PERÍODO</span>
            <span style="font-family:var(--font-display);font-size:1.5rem;font-weight:700;color:<?= $resultado>=0?'var(--col-success)':'var(--col-danger)' ?>">
                <?= $resultado>=0?'Utilidad ':'Pérdida ' ?><?= formatoPeso(abs($resultado)) ?>
            </span>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
