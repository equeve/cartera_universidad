<?php
$tituloPagina='Acuerdos de Pago'; $subtituloPagina='Financiación de deuda por cuotas';
require_once __DIR__.'/../../includes/header.php';
requireRol(['admin','financiero','cajero']);
$db=Database::getInstance();

if($_SERVER['REQUEST_METHOD']==='POST'){
    validarCSRF(); $accion=$_POST['accion']??'';
    if($accion==='crear_acuerdo'){
        $fid=(int)($_POST['factura_id']??0); $nc=(int)($_POST['numero_cuotas']??0);
        $fp=$_POST['fecha_primera_cuota']??date('Y-m-d'); $obs=trim($_POST['observaciones']??'');
        $frec=$_POST['frecuencia']??'mensual';
        $fac=$db->fetchOne("SELECT * FROM facturas WHERE id=? AND estado NOT IN ('anulada','pagada')",[$fid]);
        if(!$fac){$_SESSION['flash_error']='Factura no válida.';}
        elseif($nc<2||$nc>24){$_SESSION['flash_error']='Cuotas debe ser entre 2 y 24.';}
        else{
            $ya=$db->fetchValue("SELECT id FROM acuerdos_pago WHERE factura_id=? AND estado='vigente'",[$fid]);
            if($ya){$_SESSION['flash_error']='Ya existe un acuerdo vigente para esta factura.';}
            else{
                try{
                    $db->beginTransaction();
                    $db->query("INSERT INTO acuerdos_pago (factura_id,fecha_acuerdo,valor_total,numero_cuotas,observaciones,creado_por) VALUES (?,CURRENT_DATE,?,?,?,?)",[$fid,$fac['saldo'],$nc,$obs?:null,$usuario['id']]);
                    $aid=(int)$db->fetchValue("SELECT currval('acuerdos_pago_id_seq')");
                    $vc=round($fac['saldo']/$nc,-2); $aj=$fac['saldo']-($vc*($nc-1));
                    $fd=new DateTime($fp); $dias=match($frec){'quincenal'=>15,'semanal'=>7,default=>30};
                    for($i=1;$i<=$nc;$i++){
                        $v=($i===$nc)?$aj:$vc;
                        $db->query("INSERT INTO cuotas_acuerdo (acuerdo_id,numero_cuota,fecha_vencimiento,valor) VALUES (?,?,?,?)",[$aid,$i,$fd->format('Y-m-d'),$v]);
                        if($frec==='mensual')$fd->modify('+1 month'); else $fd->modify("+{$dias} days");
                    }
                    registrarAuditoria('acuerdos_pago','INSERT',$aid,[],['factura'=>$fid,'cuotas'=>$nc]);
                    $db->commit();
                    $_SESSION['flash_success']="Acuerdo creado: {$nc} cuotas de ".formatoPeso($vc).".";
                    header('Location: '.APP_URL.'/modules/cartera/acuerdos.php?id='.$aid); exit;
                }catch(Exception $e){$db->rollback();$_SESSION['flash_error']='Error: '.$e->getMessage();}
            }
        }
    }
    if($accion==='pagar_cuota'){
        $cid=(int)($_POST['cuota_id']??0); $mid=(int)($_POST['medio_pago_id']??0); $ref=trim($_POST['referencia']??'');
        $cu=$db->fetchOne("SELECT ca.*,ap.factura_id FROM cuotas_acuerdo ca JOIN acuerdos_pago ap ON ap.id=ca.acuerdo_id WHERE ca.id=? AND ca.estado='pendiente'",[$cid]);
        if(!$cu){$_SESSION['flash_error']='Cuota no encontrada o ya pagada.';}
        else{
            try{
                $db->beginTransaction();
                $nr=generarNumeroRecibo();
                $db->query("INSERT INTO pagos (factura_id,medio_pago_id,numero_recibo,fecha_pago,valor,referencia_bancaria,estado,registrado_por) VALUES (?,?,?,NOW(),?,?,'aplicado',?)",[$cu['factura_id'],$mid,$nr,$cu['valor'],$ref?:null,$usuario['id']]);
                $pid=(int)$db->fetchValue("SELECT currval('pagos_id_seq')");
                $db->query("UPDATE cuotas_acuerdo SET estado='pagada',valor_pagado=valor,pago_id=? WHERE id=?",[$pid,$cid]);
                $pend=$db->fetchValue("SELECT COUNT(*) FROM cuotas_acuerdo WHERE acuerdo_id=? AND estado='pendiente'",[$cu['acuerdo_id']]);
                if($pend==0) $db->query("UPDATE acuerdos_pago SET estado='cumplido' WHERE id=?",[$cu['acuerdo_id']]);
                $db->commit();
                $_SESSION['flash_success']="Cuota pagada. Recibo: $nr";
            }catch(Exception $e){$db->rollback();$_SESSION['flash_error']='Error: '.$e->getMessage();}
        }
    }
    header('Location: '.APP_URL.'/modules/cartera/acuerdos.php'.(isset($_GET['id'])?'?id='.(int)$_GET['id']:'')); exit;
}

$idA=(int)($_GET['id']??0); $acuerdo=$cuotas=null;
if($idA){
    $acuerdo=$db->fetchOne("SELECT ap.*,f.numero_factura,f.total AS ftotal,f.saldo AS fsaldo,e.primer_nombre||' '||e.primer_apellido AS estudiante,e.codigo,p.nombre AS periodo,u.nombre||' '||u.apellido AS creado FROM acuerdos_pago ap JOIN facturas f ON f.id=ap.factura_id JOIN estudiantes e ON e.id=f.estudiante_id JOIN periodos p ON p.id=f.periodo_id LEFT JOIN usuarios u ON u.id=ap.creado_por WHERE ap.id=?",[$idA]);
    $cuotas=$db->fetchAll("SELECT ca.*,pg.numero_recibo FROM cuotas_acuerdo ca LEFT JOIN pagos pg ON pg.id=ca.pago_id WHERE ca.acuerdo_id=? ORDER BY ca.numero_cuota",[$idA]);
}
$busq=trim($_GET['q']??''); $estado=$_GET['estado']??'';
$wh=['1=1']; $pr=[];
if($busq){$wh[]="(e.primer_nombre ILIKE ? OR e.primer_apellido ILIKE ? OR e.codigo ILIKE ? OR f.numero_factura ILIKE ?)";$pr=array_merge($pr,["%$busq%","%$busq%","%$busq%","%$busq%"]);}
if($estado){$wh[]='ap.estado=?';$pr[]=$estado;}
$acuerdos=$db->fetchAll("SELECT ap.*,f.numero_factura,e.primer_nombre||' '||e.primer_apellido AS estudiante,e.codigo,(SELECT COUNT(*) FROM cuotas_acuerdo WHERE acuerdo_id=ap.id AND estado='pendiente') AS cpend,(SELECT COUNT(*) FROM cuotas_acuerdo WHERE acuerdo_id=ap.id) AS ctot FROM acuerdos_pago ap JOIN facturas f ON f.id=ap.factura_id JOIN estudiantes e ON e.id=f.estudiante_id WHERE ".implode(' AND ',$wh)." ORDER BY ap.created_at DESC LIMIT 100",$pr);
$medios=$db->fetchAll("SELECT * FROM medios_pago WHERE activo=TRUE ORDER BY nombre");
?>
<?php if($acuerdo): ?>
<div class="flex gap-2 mb-4">
<a href="?" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Todos los acuerdos</a>
<a href="<?=APP_URL?>/modules/facturas/ver.php?id=<?=$acuerdo['factura_id']?>" class="btn btn-outline btn-sm"><i class="fas fa-file-invoice"></i> Ver Factura</a>
<button onclick="window.print()" class="btn btn-outline btn-sm"><i class="fas fa-print"></i> Imprimir</button>
</div>
<div style="display:grid;grid-template-columns:1fr 340px;gap:1.2rem">
<div class="card">
<div class="card-header"><h3 class="card-title"><i class="fas fa-calendar-alt" style="color:var(--col-accent)"></i> Plan de Cuotas · <?=e($acuerdo['numero_factura'])?></h3><?=estadoBadge($acuerdo['estado'])?></div>
<div class="table-wrapper"><table>
<thead><tr><th>Cuota</th><th>Vencimiento</th><th class="text-right">Valor</th><th>Estado</th><th>Recibo</th><th></th></tr></thead>
<tbody>
<?php foreach($cuotas as $cu): $dias=diasVencimiento($cu['fecha_vencimiento']); $venc=$cu['estado']==='pendiente'&&$dias<0; ?>
<tr style="<?=$venc?'background:rgba(220,38,38,.04)':''?>">
<td><strong>Cuota <?=$cu['numero_cuota']?></strong></td>
<td><?=formatoFecha($cu['fecha_vencimiento'])?><?php if($venc): ?><br><small style="color:var(--col-danger)"><?=abs($dias)?> días vencida</small><?php endif; ?></td>
<td class="text-right font-bold"><?=formatoPeso($cu['valor'])?></td>
<td><?=estadoBadge($cu['estado'])?></td>
<td><small><?=e($cu['numero_recibo']??'–')?></small></td>
<td><?php if($cu['estado']==='pendiente'&&in_array($usuario['rol'],['admin','financiero','cajero'])): ?>
<button data-modal="modalPagar" class="btn btn-accent btn-sm" onclick="document.getElementById('inCuotaId').value=<?=$cu['id']?>;document.getElementById('inCuotaVal').textContent='<?=formatoPeso($cu['valor'])?>'">&dollar; Pagar</button>
<?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot><tr style="background:rgba(26,58,92,.06);font-weight:700"><td colspan="2">TOTAL</td><td class="text-right"><?=formatoPeso($acuerdo['valor_total'])?></td><td colspan="3"></td></tr></tfoot>
</table></div>
</div>
<div class="card"><div class="card-header"><h3 class="card-title">Resumen</h3></div><div class="card-body" style="font-size:.84rem">
<?php foreach([['Estudiante',$acuerdo['estudiante'].' ('.$acuerdo['codigo'].')'],['Factura',$acuerdo['numero_factura']],['Período',$acuerdo['periodo']],['Fecha Acuerdo',formatoFecha($acuerdo['fecha_acuerdo'])],['N° Cuotas',$acuerdo['numero_cuotas']],['Valor Total',formatoPeso($acuerdo['valor_total'])],['Saldo Factura',formatoPeso($acuerdo['fsaldo'])],['Creó',$acuerdo['creado']??'–']] as [$l,$v]): ?>
<div style="display:flex;justify-content:space-between;padding:.3rem 0;border-bottom:1px solid var(--col-border)">
<span style="color:var(--col-muted)"><?=$l?></span><span style="font-weight:500"><?=e($v)?></span></div>
<?php endforeach; ?>
</div></div>
</div>
<div class="modal-overlay" id="modalPagar"><div class="modal" style="max-width:380px">
<div class="modal-header"><h3 class="modal-title"><i class="fas fa-hand-holding-dollar" style="color:var(--col-success)"></i> Pagar Cuota</h3><button class="modal-close"><i class="fas fa-times"></i></button></div>
<form method="POST"><?=csrfField()?><input type="hidden" name="accion" value="pagar_cuota"><input type="hidden" name="cuota_id" id="inCuotaId"><input type="hidden" name="acuerdo_id" value="<?=$idA?>">
<div class="modal-body">
<div style="text-align:center;font-size:1.1rem;font-weight:700;margin-bottom:1rem" id="inCuotaVal"></div>
<div class="form-group"><label>Medio de Pago *</label><select name="medio_pago_id" class="form-select" required><option value="">Seleccione...</option><?php foreach($medios as $m): ?><option value="<?=$m['id']?>"><?=e($m['nombre'])?></option><?php endforeach; ?></select></div>
<div class="form-group"><label>Referencia</label><input type="text" name="referencia" class="form-control" placeholder="N° transacción (opcional)"></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline modal-close">Cancelar</button><button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Confirmar Pago</button></div>
</form></div></div>

<?php else: ?>
<div class="flex gap-2 mb-4"><button data-modal="modalNuevo" class="btn btn-primary"><i class="fas fa-plus"></i> Nuevo Acuerdo de Pago</button></div>
<div class="card mb-3"><div class="card-body" style="padding:.8rem 1.2rem">
<form method="GET" class="flex gap-2 items-center flex-wrap">
<div class="search-bar" style="max-width:270px"><i class="fas fa-search"></i><input type="text" name="q" placeholder="Estudiante, código, factura..." value="<?=e($busq)?>"></div>
<select name="estado" class="form-select" style="width:auto"><option value="">Todos los estados</option><?php foreach(['vigente','cumplido','incumplido'] as $s): ?><option value="<?=$s?>" <?=$estado===$s?'selected':''?>><?=ucfirst($s)?></option><?php endforeach; ?></select>
<button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Filtrar</button>
<a href="?" class="btn btn-outline btn-sm">Limpiar</a>
</form></div></div>
<div class="card"><div class="table-wrapper"><table>
<thead><tr><th>Estudiante</th><th>Factura</th><th>Fecha</th><th class="text-right">Valor Total</th><th style="text-align:center">Cuotas</th><th style="text-align:center">Pendientes</th><th>Estado</th><th></th></tr></thead>
<tbody>
<?php foreach($acuerdos as $ac): ?>
<tr><td><?=e($ac['estudiante'])?><br><small style="color:var(--col-muted)"><?=e($ac['codigo'])?></small></td>
<td><small><?=e($ac['numero_factura'])?></small></td>
<td><?=formatoFecha($ac['fecha_acuerdo'])?></td>
<td class="text-right font-bold"><?=formatoPeso($ac['valor_total'])?></td>
<td style="text-align:center"><?=$ac['ctot']?></td>
<td style="text-align:center"><?php if($ac['cpend']>0): ?><span class="badge badge-warning"><?=$ac['cpend']?></span><?php else: ?><span class="badge badge-success">0</span><?php endif; ?></td>
<td><?=estadoBadge($ac['estado'])?></td>
<td><a href="?id=<?=$ac['id']?>" class="btn btn-outline btn-sm"><i class="fas fa-eye"></i></a></td>
</tr>
<?php endforeach; ?>
<?php if(empty($acuerdos)): ?><tr><td colspan="8" class="text-center text-muted" style="padding:3rem">Sin acuerdos registrados</td></tr><?php endif; ?>
</tbody></table></div></div>

<div class="modal-overlay" id="modalNuevo"><div class="modal" style="max-width:460px">
<div class="modal-header"><h3 class="modal-title"><i class="fas fa-handshake" style="color:var(--col-accent)"></i> Nuevo Acuerdo de Pago</h3><button class="modal-close"><i class="fas fa-times"></i></button></div>
<form method="POST"><?=csrfField()?><input type="hidden" name="accion" value="crear_acuerdo">
<div class="modal-body">
<div class="form-group"><label>ID de Factura con Saldo Pendiente *</label>
<?php $fpend=$db->fetchAll("SELECT f.id,f.numero_factura,f.saldo,e.primer_nombre||' '||e.primer_apellido AS est FROM facturas f JOIN estudiantes e ON e.id=f.estudiante_id WHERE f.estado IN ('pendiente','parcial','vencida') AND f.saldo>0 ORDER BY f.numero_factura DESC LIMIT 200"); ?>
<select name="factura_id" class="form-select" required><option value="">Seleccione...</option><?php foreach($fpend as $fp): ?><option value="<?=$fp['id']?>"><?=e($fp['numero_factura'].' — '.$fp['est'].' — '.formatoPeso($fp['saldo']))?></option><?php endforeach; ?></select></div>
<div class="form-row cols-2">
<div class="form-group"><label>N° Cuotas (2-24) *</label><input type="number" name="numero_cuotas" class="form-control" required min="2" max="24" value="3"></div>
<div class="form-group"><label>Frecuencia</label><select name="frecuencia" class="form-select"><option value="mensual">Mensual</option><option value="quincenal">Quincenal</option><option value="semanal">Semanal</option></select></div>
</div>
<div class="form-group"><label>Fecha Primera Cuota *</label><input type="date" name="fecha_primera_cuota" class="form-control" required value="<?=date('Y-m-d',strtotime('+1 month'))?>"></div>
<div class="form-group"><label>Observaciones</label><textarea name="observaciones" class="form-control" rows="2" placeholder="Condiciones del acuerdo..."></textarea></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline modal-close">Cancelar</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Crear Acuerdo</button></div>
</form></div></div>
<?php endif; ?>
<?php require_once __DIR__.'/../../includes/footer.php'; ?>
