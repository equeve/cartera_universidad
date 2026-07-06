<?php
// modules/contabilidad/balance_prueba.php
$tituloPagina    = 'Balance de Prueba';
$subtituloPagina = 'Comprobación de saldos — PUC Colombia';
require_once __DIR__ . '/../../includes/header.php';
requireRol(['admin', 'financiero']);

$db = Database::getInstance();

$periodo = $_GET['periodo'] ?? date('Y-m');
$nivel   = (int)($_GET['nivel'] ?? 4);
$clase   = $_GET['clase'] ?? '';

$where  = ['c.acepta_movimiento = TRUE'];
$params = [];
if ($clase) { $where[] = 'c.clase = ?'; $params[] = $clase; }
if ($nivel) { $where[] = 'c.nivel <= ?'; $params[] = $nivel; }
$wSQL = implode(' AND ', $where);

$cuentas = $db->fetchAll(
    "SELECT c.codigo, c.nombre, c.clase, c.nivel, c.naturaleza,
            COALESCE(SUM(m.debito),0)  AS mov_debito,
            COALESCE(SUM(m.credito),0) AS mov_credito
     FROM puc_cuentas c
     LEFT JOIN movimientos_contables m ON m.cuenta_codigo = c.codigo
     LEFT JOIN comprobantes cp ON cp.id = m.comprobante_id
         AND cp.estado = 'contabilizado'
         AND TO_CHAR(cp.fecha,'YYYY-MM') = ?
     WHERE $wSQL
     GROUP BY c.codigo, c.nombre, c.clase, c.nivel, c.naturaleza
     HAVING COALESCE(SUM(m.debito),0) + COALESCE(SUM(m.credito),0) > 0
     ORDER BY c.codigo",
    array_merge([$periodo], $params)
);

$totDeb = array_sum(array_column($cuentas, 'mov_debito'));
$totCre = array_sum(array_column($cuentas, 'mov_credito'));
$cuadra = abs($totDeb - $totCre) < 0.01;

$periodos = $db->fetchAll("SELECT DISTINCT TO_CHAR(fecha,'YYYY-MM') AS p FROM comprobantes WHERE estado='contabilizado' ORDER BY p DESC LIMIT 24");
$clases   = ['1'=>'Activos','2'=>'Pasivos','3'=>'Patrimonio','4'=>'Ingresos','5'=>'Gastos','6'=>'Costos','8'=>'Orden Deud.','9'=>'Orden Acr.'];

$grupAct = $grupPas = $grupPat = $grupIng = $grupGas = 0;
foreach ($cuentas as $c) {
    $sDb = $c['naturaleza']==='D' ? max(0,(float)$c['mov_debito']-(float)$c['mov_credito']) : 0;
    $sCr = $c['naturaleza']==='C' ? max(0,(float)$c['mov_credito']-(float)$c['mov_debito']) : 0;
    if ($c['clase']==='1') $grupAct += $sDb;
    if ($c['clase']==='2') $grupPas += $sCr;
    if ($c['clase']==='3') $grupPat += $sCr;
    if ($c['clase']==='4') $grupIng += $sCr;
    if ($c['clase']==='5') $grupGas += $sDb;
}
?>

<div class="card mb-3"><div class="card-body" style="padding:.8rem 1.2rem">
<form method="GET" class="flex gap-2 flex-wrap items-center">
    <label style="font-size:.85rem">Período:</label>
    <select name="periodo" class="form-select" style="width:auto">
        <?php $yaListo = false; foreach ($periodos as $p): if ($p['p'] === date('Y-m')) $yaListo = true; ?>
        <option value="<?= e($p['p']) ?>" <?= $periodo===$p['p']?'selected':'' ?>><?= e($p['p']) ?></option>
        <?php endforeach; ?>
        <?php if (!$yaListo): ?>
        <option value="<?= date('Y-m') ?>" <?= $periodo===date('Y-m')?'selected':'' ?>><?= date('Y-m') ?> (actual)</option>
        <?php endif; ?>
    </select>
    <label style="font-size:.85rem">Nivel:</label>
    <select name="nivel" class="form-select" style="width:auto">
        <?php for ($i=3;$i<=5;$i++): ?>
        <option value="<?= $i ?>" <?= $nivel===$i?'selected':'' ?>>Hasta nivel <?= $i ?></option>
        <?php endfor; ?>
    </select>
    <select name="clase" class="form-select" style="width:auto">
        <option value="">Todas las clases</option>
        <?php foreach ($clases as $k=>$v): ?>
        <option value="<?= $k ?>" <?= $clase===$k?'selected':'' ?>><?= $k ?> – <?= $v ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Generar</button>
    <button type="button" onclick="window.print()" class="btn btn-outline btn-sm"><i class="fas fa-print"></i> Imprimir</button>
</form>
</div></div>

<?php if (!$cuadra && count($cuentas) > 0): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <strong>¡PARTIDA NO CUADRA!</strong> Diferencia: <?= formatoPeso(abs($totDeb-$totCre)) ?>. Revise los comprobantes del período.</div>
<?php elseif (count($cuentas) > 0): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i> <strong>La partida cuadra correctamente.</strong> Débitos = Créditos = <?= formatoPeso($totDeb) ?></div>
<?php endif; ?>

<div class="card">
<div class="card-header">
    <div>
        <h3 class="card-title">Balance de Prueba · Período <?= e($periodo) ?></h3>
        <small style="color:var(--col-muted)">PUC Decreto 2650/1993 — Universidad Colombia</small>
    </div>
    <div style="text-align:right;font-size:.82rem;color:var(--col-muted)"><?= date('d/m/Y H:i') ?></div>
</div>
<div class="table-wrapper"><table>
<thead><tr>
    <th>Código</th><th>Nombre de la Cuenta</th><th>Clase</th>
    <th class="text-right">Débitos del Período</th>
    <th class="text-right">Créditos del Período</th>
    <th class="text-right">Saldo Débito</th>
    <th class="text-right">Saldo Crédito</th>
</tr></thead>
<tbody>
<?php foreach ($cuentas as $c):
    $sDb = $c['naturaleza']==='D' ? max(0,(float)$c['mov_debito']-(float)$c['mov_credito']) : 0;
    $sCr = $c['naturaleza']==='C' ? max(0,(float)$c['mov_credito']-(float)$c['mov_debito']) : 0;
    $cNombre = $clases[$c['clase']] ?? '?';
    $fw = $c['nivel']<=2?'700':($c['nivel']===3?'600':'400');
    $bg = $c['nivel']===1?'rgba(26,58,92,.05)':($c['nivel']===2?'rgba(26,58,92,.02)':'transparent');
?>
<tr style="background:<?= $bg ?>">
    <td><code style="font-size:.85rem;font-weight:700;letter-spacing:.06em"><?= e($c['codigo']) ?></code></td>
    <td style="font-weight:<?= $fw ?>;font-size:.86rem;padding-left:<?= ($c['nivel']-1)*14 ?>px"><?= e($c['nombre']) ?></td>
    <td><span class="badge badge-<?= ['1'=>'primary','2'=>'danger','3'=>'info','4'=>'success','5'=>'warning','6'=>'accent','8'=>'secondary','9'=>'secondary'][$c['clase']]??'secondary' ?>" style="font-size:.65rem"><?= $cNombre ?></span></td>
    <td class="text-right" style="font-size:.84rem"><?= $c['mov_debito']>0?formatoPeso($c['mov_debito']):'–' ?></td>
    <td class="text-right" style="font-size:.84rem"><?= $c['mov_credito']>0?formatoPeso($c['mov_credito']):'–' ?></td>
    <td class="text-right" style="font-weight:700;font-size:.84rem;color:<?= $sDb>0?'var(--col-info)':'var(--col-muted)' ?>"><?= $sDb>0?formatoPeso($sDb):'–' ?></td>
    <td class="text-right" style="font-weight:700;font-size:.84rem;color:<?= $sCr>0?'var(--col-success)':'var(--col-muted)' ?>"><?= $sCr>0?formatoPeso($sCr):'–' ?></td>
</tr>
<?php endforeach; ?>
<?php if (empty($cuentas)): ?>
<tr><td colspan="7" class="text-center text-muted" style="padding:3rem">Sin movimientos en el período <?= e($periodo) ?></td></tr>
<?php endif; ?>
</tbody>
<tfoot>
<tr style="background:rgba(26,58,92,.08);font-weight:700">
    <td colspan="2" style="font-family:var(--font-display);font-size:.95rem;color:var(--col-primary)">TOTALES</td>
    <td></td>
    <td class="text-right" style="color:var(--col-info);font-size:.95rem"><?= formatoPeso($totDeb) ?></td>
    <td class="text-right" style="color:var(--col-success);font-size:.95rem"><?= formatoPeso($totCre) ?></td>
    <td class="text-right" style="color:var(--col-info)"><?= formatoPeso(array_sum(array_map(fn($c)=>$c['naturaleza']==='D'?max(0,(float)$c['mov_debito']-(float)$c['mov_credito']):0,$cuentas))) ?></td>
    <td class="text-right" style="color:var(--col-success)"><?= formatoPeso(array_sum(array_map(fn($c)=>$c['naturaleza']==='C'?max(0,(float)$c['mov_credito']-(float)$c['mov_debito']):0,$cuentas))) ?></td>
</tr>
</tfoot>
</table></div>

<?php if ($grupIng>0 || $grupGas>0): ?>
<div style="padding:1rem 1.4rem;border-top:1px solid var(--col-border);display:grid;grid-template-columns:repeat(3,1fr);gap:1rem">
    <div style="font-size:.83rem"><span style="color:var(--col-muted)">Total Ingresos:</span><br><strong style="color:var(--col-success)"><?= formatoPeso($grupIng) ?></strong></div>
    <div style="font-size:.83rem"><span style="color:var(--col-muted)">Total Gastos:</span><br><strong style="color:var(--col-danger)"><?= formatoPeso($grupGas) ?></strong></div>
    <div style="font-size:.83rem"><span style="color:var(--col-muted)">Resultado del Período:</span><br>
        <strong style="color:<?= $grupIng>=$grupGas?'var(--col-success)':'var(--col-danger)' ?>;font-size:1rem">
            <?= $grupIng>=$grupGas?'Utilidad ':'Pérdida ' ?><?= formatoPeso(abs($grupIng-$grupGas)) ?>
        </strong>
    </div>
</div>
<?php endif; ?>

<?php if ($grupAct>0 || $grupPas>0 || $grupPat>0): ?>
<div style="padding:1rem 1.4rem;border-top:1px solid var(--col-border);display:grid;grid-template-columns:repeat(3,1fr);gap:1rem">
    <div style="font-size:.83rem"><span style="color:var(--col-muted)">Total Activos:</span><br><strong style="color:var(--col-primary)"><?= formatoPeso($grupAct) ?></strong></div>
    <div style="font-size:.83rem"><span style="color:var(--col-muted)">Total Pasivos:</span><br><strong style="color:var(--col-danger)"><?= formatoPeso($grupPas) ?></strong></div>
    <div style="font-size:.83rem"><span style="color:var(--col-muted)">Total Patrimonio:</span><br><strong style="color:var(--col-info)"><?= formatoPeso($grupPat) ?></strong></div>
</div>
<div style="padding:0 1.4rem 1rem;font-size:.78rem;color:var(--col-muted)">
    Ecuación contable: Activos (<?= formatoPeso($grupAct) ?>) <?= abs($grupAct-($grupPas+$grupPat))<1 ? '=' : '≠' ?> Pasivos + Patrimonio (<?= formatoPeso($grupPas+$grupPat) ?>)
</div>
<?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
