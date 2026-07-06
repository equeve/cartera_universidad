<?php
$tituloPagina='Gestión de Mora';$subtituloPagina='Intereses por pago tardío';
require_once __DIR__.'/../../includes/header.php';
requireRol(['admin','financiero']);
$db=Database::getInstance();
if($_SERVER['REQUEST_METHOD']==='POST'){validarCSRF();$a=$_POST['accion']??'';
  if($a==='guardar_config'){$tm=(float)str_replace(',','.',$_POST['tasa_mensual']??0);$dg=(int)($_POST['dias_gracia']??0);$act=isset($_POST['activa']);$vd=$_POST['vigente_desde']??date('Y-m-d');
    if($tm<=0){$_SESSION['flash_error']='La tasa mensual debe ser mayor a 0.';}
    else{$td=round($tm/30,6);$db->query("UPDATE config_mora SET activa=FALSE");$db->query("INSERT INTO config_mora(nombre,tasa_diaria,tasa_mensual,dias_gracia,aplica_a,activa,vigente_desde)VALUES(?,?,?,?,'vencidas',?,?)",["Config $vd",$td,$tm,$dg,$act?'true':'false',$vd]);$_SESSION['flash_success']="Configuración guardada. Tasa diaria: ".number_format($td,6)."%";}
    header('Location:'.APP_URL.'/modules/cartera/mora.php');exit;}
  if($a==='registrar_mora'){$fi=(int)($_POST['factura_id']??0);$dm=(int)($_POST['dias_mora']??0);$cap=(float)str_replace(['.', ','],['','.'],$_POST['capital_vencido']??0);$vm=(float)str_replace(['.', ','],['','.'],$_POST['valor_mora']??0);$ta=(float)str_replace(',','.',$_POST['tasa_aplicada']??0);
    if(!$fi||$vm<=0){$_SESSION['flash_error']='Datos incompletos.';}
    else{$db->query("INSERT INTO mora_registrada(factura_id,fecha_calculo,dias_mora,capital_vencido,valor_mora,tasa_aplicada)VALUES(?,CURRENT_DATE,?,?,?,?)",[$fi,$dm,$cap,$vm,$ta]);$_SESSION['flash_success']='Mora registrada: '.formatoPeso($vm);}
    header('Location:'.APP_URL.'/modules/cartera/mora.php');exit;}}
$cfg=$db->fetchOne("SELECT * FROM config_mora ORDER BY id DESC LIMIT 1");
$mora=[];if($cfg&&$cfg['activa']&&(float)$cfg['tasa_diaria']>0){$mora=$db->fetchAll("SELECT * FROM fn_calcular_mora(CURRENT_DATE) WHERE valor_mora>0 ORDER BY valor_mora DESC");}
$hist=$db->fetchAll("SELECT mr.*,f.numero_factura,e.primer_nombre||' '||e.primer_apellido AS est,e.codigo FROM mora_registrada mr JOIN facturas f ON f.id=mr.factura_id JOIN estudiantes e ON e.id=f.estudiante_id ORDER BY mr.created_at DESC LIMIT 100");
$totCalc=array_sum(array_column($mora,'valor_mora'));
$totReg=array_sum(array_column($hist,'valor_mora'));
$totCob=array_sum(array_map(fn($m)=>$m['cobrada']?$m['valor_mora']:0,$hist));
?>
<div style="display:grid;grid-template-columns:1fr 360px;gap:1.2rem">
<div>
<?php if(!$cfg||!$cfg['activa']): ?>
<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> <strong>Mora desactivada.</strong> Configure una tasa en el panel derecho.</div>
<?php elseif(empty($mora)): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i> No hay facturas con mora calculada hoy.</div>
<?php else: ?>
<div class="stats-grid mb-3" style="grid-template-columns:repeat(3,1fr)">
  <div class="stat-card"><div class="stat-icon danger"><i class="fas fa-exclamation-triangle"></i></div><div><div class="stat-value"><?=count($mora)?></div><div class="stat-label">Facturas en mora</div></div></div>
  <div class="stat-card"><div class="stat-icon warning"><i class="fas fa-coins"></i></div><div><div class="stat-value"><?=formatoPeso($totCalc)?></div><div class="stat-label">Mora calculada hoy</div></div></div>
  <div class="stat-card"><div class="stat-icon info"><i class="fas fa-percentage"></i></div><div><div class="stat-value"><?=number_format((float)$cfg['tasa_mensual'],2)?>% mensual</div><div class="stat-label">Tasa aplicada</div></div></div>
</div>
<div class="card mb-3"><div class="card-header"><h3 class="card-title"><i class="fas fa-fire" style="color:var(--col-danger)"></i> Mora Calculada — <?=date('d/m/Y')?></h3><small style="color:var(--col-muted)">Tasa diaria: <?=number_format((float)$cfg['tasa_diaria'],6)?>% | <?=$cfg['dias_gracia']?> días gracia</small></div>
<div class="table-wrapper"><table><thead><tr><th>Factura</th><th>Estudiante</th><th class="text-right">Capital</th><th style="text-align:center">Días</th><th class="text-right">Mora</th><th></th></tr></thead><tbody>
<?php foreach($mora as $m): ?>
<tr><td><strong><?=e($m['numero_factura'])?></strong></td><td><?=e($m['estudiante'])?></td><td class="text-right"><?=formatoPeso($m['saldo'])?></td><td style="text-align:center"><span class="badge badge-danger"><?=$m['dias_mora']?></span></td><td class="text-right font-bold" style="color:var(--col-danger)"><?=formatoPeso($m['valor_mora'])?></td>
<td><form method="POST" style="display:inline"><?=csrfField()?><input type="hidden" name="accion" value="registrar_mora"><input type="hidden" name="factura_id" value="<?=$m['factura_id']?>"><input type="hidden" name="dias_mora" value="<?=$m['dias_mora']?>"><input type="hidden" name="capital_vencido" value="<?=$m['saldo']?>"><input type="hidden" name="valor_mora" value="<?=$m['valor_mora']?>"><input type="hidden" name="tasa_aplicada" value="<?=$cfg['tasa_diaria']?>"><button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-registered"></i> Registrar</button></form></td></tr>
<?php endforeach; ?>
</tbody><tfoot><tr style="font-weight:700;background:rgba(220,38,38,.07)"><td colspan="4">TOTAL</td><td class="text-right" style="color:var(--col-danger)"><?=formatoPeso($totCalc)?></td><td></td></tr></tfoot></table></div></div>
<?php endif; ?>
<div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-history" style="color:var(--col-accent)"></i> Historial de Mora</h3><small style="color:var(--col-muted)">Cobrada: <?=formatoPeso($totCob)?> / <?=formatoPeso($totReg)?></small></div>
<div class="table-wrapper"><table><thead><tr><th>Fecha</th><th>Factura</th><th>Estudiante</th><th style="text-align:center">Días</th><th class="text-right">Capital</th><th class="text-right">Mora</th><th>Cobrada</th></tr></thead><tbody>
<?php foreach($hist as $h): ?>
<tr><td><?=formatoFecha($h['fecha_calculo'])?></td><td><small><?=e($h['numero_factura'])?></small></td><td><?=e($h['est'])?><br><small style="color:var(--col-muted)"><?=e($h['codigo'])?></small></td><td style="text-align:center"><span class="badge badge-warning"><?=$h['dias_mora']?></span></td><td class="text-right"><?=formatoPeso($h['capital_vencido'])?></td><td class="text-right font-bold" style="color:var(--col-danger)"><?=formatoPeso($h['valor_mora'])?></td><td><?=$h['cobrada']?'<span class="badge badge-success">Sí</span>':'<span class="badge badge-warning">No</span>'?></td></tr>
<?php endforeach; ?>
<?php if(empty($hist)): ?><tr><td colspan="7" class="text-center text-muted" style="padding:2rem">Sin mora registrada</td></tr><?php endif; ?>
</tbody></table></div></div>
</div>
<div>
<div class="card mb-3"><div class="card-header"><h3 class="card-title"><i class="fas fa-cog" style="color:var(--col-accent)"></i> Configuración</h3></div><div class="card-body">
<?php if($cfg): ?><div style="background:<?=$cfg['activa']?'rgba(22,163,74,.08)':'rgba(217,119,6,.08)'?>;border-radius:var(--radius-md);padding:.8rem;margin-bottom:1rem;font-size:.83rem"><strong>Configuración <?=$cfg['activa']?'ACTIVA':'INACTIVA'?></strong><br>Tasa mensual: <?=number_format((float)$cfg['tasa_mensual'],4)?>%<br>Tasa diaria: <?=number_format((float)$cfg['tasa_diaria'],6)?>%<br>Días de gracia: <?=$cfg['dias_gracia']?><br>Vigente desde: <?=formatoFecha($cfg['vigente_desde'])?></div><?php endif; ?>
<form method="POST"><?=csrfField()?><input type="hidden" name="accion" value="guardar_config">
<div class="form-group"><label>Tasa Mensual (%) *</label><input type="number" name="tasa_mensual" class="form-control" step="0.0001" min="0.0001" value="<?=e(number_format((float)($cfg['tasa_mensual']??2.5),4,'.',''))?>" placeholder="2.5"><small style="color:var(--col-muted)">Interés bancario corriente certificado por Superfinanciera. La tasa diaria = mensual / 30.</small></div>
<div class="form-group"><label>Días de Gracia</label><input type="number" name="dias_gracia" class="form-control" min="0" max="30" value="<?=e($cfg['dias_gracia']??5)?>"></div>
<div class="form-group"><label>Vigente Desde</label><input type="date" name="vigente_desde" class="form-control" value="<?=date('Y-m-d')?>"></div>
<div class="form-group"><label style="cursor:pointer"><input type="checkbox" name="activa" checked> Activar esta configuración</label></div>
<button type="submit" class="btn btn-primary w-full"><i class="fas fa-save"></i> Guardar Configuración</button>
</form></div></div>
<div class="card"><div class="card-header"><h3 class="card-title">Resumen Mora</h3></div><div class="card-body" style="font-size:.83rem">
<?php foreach([['Mora calculada hoy',formatoPeso($totCalc),'var(--col-danger)'],['Mora total registrada',formatoPeso($totReg),'var(--col-warning)'],['Mora cobrada',formatoPeso($totCob),'var(--col-success)'],['Mora pendiente',formatoPeso($totReg-$totCob),'var(--col-danger)']] as [$l,$v,$c]): ?>
<div style="display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid var(--col-border)"><span style="color:var(--col-muted)"><?=$l?></span><strong style="color:<?=$c?>"><?=$v?></strong></div>
<?php endforeach; ?>
</div></div>
</div>
</div>
<?php require_once __DIR__.'/../../includes/footer.php'; ?>
