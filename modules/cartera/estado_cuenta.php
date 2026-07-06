<?php
$tituloPagina='Estado de Cuenta';$subtituloPagina='Extracto financiero del estudiante';
require_once __DIR__.'/../../includes/header.php';
requireLogin();
$db=Database::getInstance();
$est_id=(int)($_GET['estudiante_id']??0);
$estudiante=null;
if($est_id){$estudiante=$db->fetchOne("SELECT e.*,pr.nombre AS programa,pr.facultad,pr.nivel FROM estudiantes e LEFT JOIN programas pr ON pr.id=e.programa_id WHERE e.id=?",[$est_id]);}
$estudiantes=$db->fetchAll("SELECT id,codigo,primer_nombre||' '||primer_apellido AS nombre FROM estudiantes WHERE estado='activo' ORDER BY primer_apellido LIMIT 500");
$imprimir=isset($_GET['imprimir']);
if($estudiante){
  $facturas=$db->fetchAll("SELECT f.*,p.nombre AS periodo FROM facturas f JOIN periodos p ON p.id=f.periodo_id WHERE f.estudiante_id=? AND f.estado!='anulada' ORDER BY f.fecha_emision DESC",[$est_id]);
  $pagos=$db->fetchAll("SELECT pg.*,mp.nombre AS medio,f.numero_factura FROM pagos pg JOIN facturas f ON f.id=pg.factura_id JOIN medios_pago mp ON mp.id=pg.medio_pago_id WHERE f.estudiante_id=? AND pg.estado='aplicado' ORDER BY pg.fecha_pago DESC",[$est_id]);
  $acuerdos=$db->fetchAll("SELECT ap.*,f.numero_factura,(SELECT COUNT(*) FROM cuotas_acuerdo WHERE acuerdo_id=ap.id AND estado='pendiente') AS cuotas_pend FROM acuerdos_pago ap JOIN facturas f ON f.id=ap.factura_id WHERE f.estudiante_id=? AND ap.estado='vigente'",[$est_id]);
  $becas=$db->fetchAll("SELECT d.*,c.nombre AS concepto,p.nombre AS periodo FROM descuentos d JOIN conceptos_cobro c ON c.id=d.concepto_id LEFT JOIN periodos p ON p.id=d.periodo_id WHERE d.estudiante_id=? AND d.vigente=TRUE",[$est_id]);
  $pats=$db->fetchAll("SELECT pt.*,pa.nombre AS patrocinador FROM patrocinios pt JOIN patrocinadores pa ON pa.id=pt.patrocinador_id WHERE pt.estudiante_id=? AND pt.estado='vigente'",[$est_id]);
  $mora=$db->fetchAll("SELECT mr.*,f.numero_factura FROM mora_registrada mr JOIN facturas f ON f.id=mr.factura_id WHERE f.estudiante_id=? AND mr.cobrada=FALSE",[$est_id]);
  $resumen=$db->fetchOne("SELECT COALESCE(SUM(total),0) AS tf,COALESCE(SUM(saldo),0) AS sal,COALESCE(SUM(CASE WHEN estado='vencida' THEN saldo ELSE 0 END),0) AS salv,COUNT(id) AS nf FROM facturas WHERE estudiante_id=? AND estado!='anulada'",[$est_id]);
  $totPag=array_sum(array_column($pagos,'valor'));
  $totBec=array_sum(array_column($becas,'valor_maximo'));
  $totPat=array_sum(array_column($pats,'valor'));
  $totMora=array_sum(array_column($mora,'valor_mora'));
}
?>
<?php if(!$imprimir): ?>
<div class="card mb-3"><div class="card-body" style="padding:.8rem 1.2rem">
<form method="GET" class="flex gap-2 items-center flex-wrap">
<select name="estudiante_id" class="form-select" style="width:340px" onchange="this.form.submit()">
<option value="">Seleccione un estudiante...</option>
<?php foreach($estudiantes as $e): ?><option value="<?=$e['id']?>" <?=$est_id==$e['id']?'selected':''?>>[<?=e($e['codigo'])?>] <?=e($e['nombre'])?></option><?php endforeach; ?>
</select>
<?php if($estudiante): ?>
<a href="?estudiante_id=<?=$est_id?>&imprimir=1" target="_blank" class="btn btn-outline btn-sm"><i class="fas fa-print"></i> Imprimir</a>
<a href="<?=APP_URL?>/modules/reportes/exportar.php?tipo=estado_cuenta&estudiante_id=<?=$est_id?>" class="btn btn-success btn-sm"><i class="fas fa-file-excel"></i> Excel</a>
<a href="<?=APP_URL?>/modules/reportes/exportar.php?tipo=estado_cuenta&estudiante_id=<?=$est_id?>&formato=csv" class="btn btn-outline btn-sm"><i class="fas fa-file-csv"></i> CSV</a>
<?php endif; ?>
</form></div></div>
<?php endif; ?>
<?php if(!$estudiante): ?>
<div class="card"><div class="card-body" style="text-align:center;padding:3rem;color:var(--col-muted)"><i class="fas fa-user-graduate" style="font-size:2.5rem;display:block;margin-bottom:1rem;opacity:.3"></i>Seleccione un estudiante para ver su estado de cuenta</div></div>
<?php else: ?>
<div class="card"><div class="card-body" style="padding:2rem">
  <div style="display:flex;justify-content:space-between;margin-bottom:1.5rem;padding-bottom:1rem;border-bottom:2px solid var(--col-primary)">
    <div><div style="font-family:var(--font-display);font-size:1.5rem;color:var(--col-primary)">Universidad</div><div style="font-size:.8rem;color:var(--col-muted)">Estado de Cuenta Estudiantil</div></div>
    <div style="text-align:right"><div style="font-size:.72rem;color:var(--col-muted);text-transform:uppercase">Estado de Cuenta</div><div style="font-family:var(--font-display);font-size:1.3rem;color:var(--col-primary)"><?=e($estudiante['codigo'])?></div><div style="font-size:.78rem;color:var(--col-muted)"><?=date('d/m/Y')?></div></div>
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:2rem;margin-bottom:1.5rem">
    <div><div style="font-size:.72rem;text-transform:uppercase;color:var(--col-muted);margin-bottom:.5rem">Datos del Estudiante</div>
    <?php foreach([['Nombre',$estudiante['primer_nombre'].' '.$estudiante['primer_apellido']],['Documento',$estudiante['tipo_documento'].' '.$estudiante['numero_documento']],['Programa',$estudiante['programa']??'—'],['Email',$estudiante['email']]] as [$l,$v]): ?>
    <div style="display:flex;gap:.5rem;padding:.2rem 0;font-size:.84rem"><span style="color:var(--col-muted);width:80px;flex-shrink:0"><?=$l?></span><strong><?=e(trim($v))?></strong></div><?php endforeach; ?></div>
    <div style="background:rgba(26,58,92,.05);border-radius:var(--radius-md);padding:1rem">
    <div style="font-size:.72rem;text-transform:uppercase;color:var(--col-muted);margin-bottom:.5rem">Resumen Financiero</div>
    <?php foreach([['Total Facturado',formatoPeso($resumen['tf']),'var(--col-text)'],['Total Pagado',formatoPeso($totPag),'var(--col-success)'],['Becas/Descuentos',formatoPeso($totBec),'var(--col-success)'],['Patrocinios',formatoPeso($totPat),'var(--col-info)'],['Mora Pendiente',formatoPeso($totMora),'var(--col-warning)'],['SALDO TOTAL',formatoPeso($resumen['sal']),$resumen['sal']>0?'var(--col-danger)':'var(--col-success)']] as [$l,$v,$c]): ?>
    <div style="display:flex;justify-content:space-between;padding:.25rem 0;border-bottom:1px solid rgba(26,58,92,.08);font-size:.84rem"><span style="color:var(--col-muted)"><?=$l?></span><strong style="color:<?=$c?>"><?=$v?></strong></div><?php endforeach; ?></div>
  </div>
  <!-- Facturas -->
  <div style="margin-bottom:1.2rem"><div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:var(--col-muted);margin-bottom:.4rem">LIQUIDACIONES</div>
  <table style="width:100%;border-collapse:collapse"><thead><tr style="background:rgba(26,58,92,.06)"><?php foreach(['Factura','Período','Emisión','Vencimiento','Total','Saldo','Estado'] as $h): ?><th style="padding:.4rem .6rem;font-size:.72rem;font-weight:700;color:var(--col-primary);border-bottom:1.5px solid var(--col-primary);text-align:<?=in_array($h,['Total','Saldo'])?'right':'left'?>"><?=$h?></th><?php endforeach; ?></tr></thead><tbody>
  <?php foreach($facturas as $f): ?><tr style="border-bottom:1px solid #eee"><td style="padding:.4rem .6rem;font-size:.82rem;font-weight:600"><?=e($f['numero_factura'])?></td><td style="padding:.4rem .6rem;font-size:.8rem"><?=e($f['periodo'])?></td><td style="padding:.4rem .6rem;font-size:.8rem"><?=formatoFecha($f['fecha_emision'])?></td><td style="padding:.4rem .6rem;font-size:.8rem"><?=formatoFecha($f['fecha_vencimiento'])?></td><td style="padding:.4rem .6rem;text-align:right;font-size:.82rem"><?=formatoPeso($f['total'])?></td><td style="padding:.4rem .6rem;text-align:right;font-size:.82rem;font-weight:700;color:<?=$f['saldo']>0?'var(--col-danger)':'var(--col-success)'?>"><?=formatoPeso($f['saldo'])?></td><td style="padding:.4rem .6rem"><?=estadoBadge($f['estado'])?></td></tr><?php endforeach; ?>
  <?php if(empty($facturas)): ?><tr><td colspan="7" style="padding:1rem;text-align:center;color:var(--col-muted)">Sin liquidaciones</td></tr><?php endif; ?>
  </tbody></table></div>
  <!-- Pagos -->
  <?php if($pagos): ?><div style="margin-bottom:1.2rem"><div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:var(--col-muted);margin-bottom:.4rem">PAGOS REALIZADOS</div>
  <table style="width:100%;border-collapse:collapse"><thead><tr style="background:rgba(22,163,74,.06)"><?php foreach(['Recibo','Fecha','Factura','Medio','Valor'] as $h): ?><th style="padding:.35rem .6rem;font-size:.72rem;font-weight:700;color:var(--col-success);border-bottom:1.5px solid var(--col-success);text-align:<?=$h==='Valor'?'right':'left'?>"><?=$h?></th><?php endforeach; ?></tr></thead><tbody>
  <?php foreach($pagos as $pg): ?><tr style="border-bottom:1px solid #eee"><td style="padding:.35rem .6rem;font-size:.82rem"><?=e($pg['numero_recibo'])?></td><td style="padding:.35rem .6rem;font-size:.8rem"><?=formatoFecha($pg['fecha_pago'],'d/m/Y H:i')?></td><td style="padding:.35rem .6rem;font-size:.8rem"><?=e($pg['numero_factura'])?></td><td style="padding:.35rem .6rem;font-size:.8rem"><?=e($pg['medio'])?></td><td style="padding:.35rem .6rem;text-align:right;font-weight:700;color:var(--col-success)"><?=formatoPeso($pg['valor'])?></td></tr><?php endforeach; ?>
  <tr style="background:rgba(22,163,74,.08);font-weight:700"><td colspan="4" style="padding:.4rem .6rem">TOTAL PAGADO</td><td style="padding:.4rem .6rem;text-align:right;color:var(--col-success)"><?=formatoPeso($totPag)?></td></tr>
  </tbody></table></div><?php endif; ?>
  <!-- Acuerdos -->
  <?php if($acuerdos): ?><div style="margin-bottom:1.2rem"><div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:var(--col-muted);margin-bottom:.4rem">ACUERDOS DE PAGO VIGENTES</div>
  <?php foreach($acuerdos as $ac): ?><div style="background:rgba(2,132,199,.05);border:1px solid rgba(2,132,199,.2);border-radius:var(--radius-sm);padding:.6rem 1rem;margin-bottom:.4rem;font-size:.83rem">Factura <?=e($ac['numero_factura'])?> · <?=$ac['numero_cuotas']?> cuotas · <strong><?=$ac['cuotas_pend']?> cuotas pendientes</strong> · Valor cuota: <?=formatoPeso($ac['valor_total']/$ac['numero_cuotas'])?></div><?php endforeach; ?></div><?php endif; ?>
  <!-- Becas y patrocinios -->
  <?php if($becas||$pats): ?><div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.2rem">
  <?php if($becas): ?><div><div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:var(--col-muted);margin-bottom:.4rem">BECAS / DESCUENTOS</div><?php foreach($becas as $b): ?><div style="background:rgba(22,163,74,.06);border-radius:var(--radius-sm);padding:.4rem .7rem;margin-bottom:.3rem;font-size:.82rem"><strong><?=e($b['concepto'])?></strong> — <?=number_format($b['porcentaje'],1)?>%<?=$b['valor_maximo']?' (máx. '.formatoPeso($b['valor_maximo']).')':''?></div><?php endforeach; ?></div><?php endif; ?>
  <?php if($pats): ?><div><div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:var(--col-muted);margin-bottom:.4rem">PATROCINIOS</div><?php foreach($pats as $pt): ?><div style="background:rgba(2,132,199,.06);border-radius:var(--radius-sm);padding:.4rem .7rem;margin-bottom:.3rem;font-size:.82rem"><strong><?=e($pt['patrocinador'])?></strong> — <?=formatoPeso($pt['valor'])?></div><?php endforeach; ?></div><?php endif; ?>
  </div><?php endif; ?>
  <!-- Mora -->
  <?php if($mora): ?><div style="background:rgba(220,38,38,.05);border:1px solid rgba(220,38,38,.2);border-radius:var(--radius-md);padding:1rem;margin-bottom:1.2rem"><div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:var(--col-danger);margin-bottom:.4rem">⚠ MORA PENDIENTE</div>
  <?php foreach($mora as $m): ?><div style="display:flex;justify-content:space-between;font-size:.83rem;padding:.2rem 0"><span><?=e($m['numero_factura'])?> · <?=$m['dias_mora']?> días</span><strong style="color:var(--col-danger)"><?=formatoPeso($m['valor_mora'])?></strong></div><?php endforeach; ?>
  <div style="border-top:1px solid rgba(220,38,38,.2);margin-top:.4rem;padding-top:.4rem;display:flex;justify-content:space-between;font-weight:700"><span>TOTAL MORA</span><span style="color:var(--col-danger)"><?=formatoPeso($totMora)?></span></div></div><?php endif; ?>
  <div style="text-align:center;font-size:.72rem;color:var(--col-muted);border-top:1px dashed var(--col-border);padding-top:.8rem"><?=APP_NAME?> · Generado el <?=date('d/m/Y H:i:s')?></div>
</div></div>
<?php endif; ?>
<?php if($imprimir): ?>
<style>.sidebar,.topbar,form,.no-print{display:none!important}.main-content{margin:0!important}.page-content{padding:0!important}</style>
<script>window.onload=()=>window.print();</script>
<?php endif; ?>
<?php require_once __DIR__.'/../../includes/footer.php'; ?>
