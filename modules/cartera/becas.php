<?php
// modules/cartera/becas.php
$tituloPagina='Becas y Descuentos'; $subtituloPagina='Beneficios económicos individuales por estudiante';
require_once __DIR__.'/../../includes/header.php';
requireRol(['admin','financiero']);
$db=Database::getInstance();

if($_SERVER['REQUEST_METHOD']==='POST'){
    validarCSRF(); $accion=$_POST['accion']??'';
    if(in_array($accion,['crear','editar'])){
        $bid=(int)($_POST['beca_id']??0);
        $est=(int)($_POST['estudiante_id']??0);
        $con=(int)($_POST['concepto_id']??0);
        $per=(int)($_POST['periodo_id']??0)?:null;
        $pct=(float)str_replace(',','.',$_POST['porcentaje']??0);
        $vmax=(float)str_replace(['.', ','],['','.'],$_POST['valor_maximo']??0)?:null;
        $desc=trim($_POST['descripcion']??'');
        $vig=isset($_POST['vigente']);
        if(!$est||!$con||$pct<=0){$_SESSION['flash_error']='Estudiante, concepto y porcentaje son obligatorios.';}
        else{
            try{
                if($accion==='crear') $db->query("INSERT INTO descuentos (estudiante_id,concepto_id,periodo_id,porcentaje,valor_maximo,descripcion,aprobado_por,fecha_aprobacion,vigente) VALUES (?,?,?,?,?,?,?,NOW(),?)",[$est,$con,$per,$pct,$vmax,$desc?:null,$usuario['id'],$vig?'true':'false']);
                else $db->query("UPDATE descuentos SET concepto_id=?,periodo_id=?,porcentaje=?,valor_maximo=?,descripcion=?,vigente=? WHERE id=? AND estudiante_id=?",[$con,$per,$pct,$vmax,$desc?:null,$vig?'true':'false',$bid,$est]);
                $_SESSION['flash_success']=$accion==='crear'?'Beca asignada correctamente.':'Beca actualizada.';
            }catch(Exception $e){$_SESSION['flash_error']='Error: '.$e->getMessage();}
        }
    }elseif($accion==='toggle'){
        $bid=(int)($_POST['beca_id']??0);
        $db->query("UPDATE descuentos SET vigente=NOT vigente WHERE id=?",[$bid]);
        $_SESSION['flash_success']='Estado actualizado.';
    }
    header('Location: '.APP_URL.'/modules/cartera/becas.php?'.http_build_query($_GET)); exit;
}

$busq=trim($_GET['q']??''); $per=(int)($_GET['periodo']??0); $svg=!isset($_GET['todos']);
$wh=['1=1']; $pr=[];
if($busq){$wh[]="(e.primer_nombre ILIKE ? OR e.primer_apellido ILIKE ? OR e.codigo ILIKE ?)";$pr=array_merge($pr,["%$busq%","%$busq%","%$busq%"]);}
if($per){$wh[]='d.periodo_id=?';$pr[]=$per;}
if($svg){$wh[]='d.vigente=TRUE';}
$becas=$db->fetchAll("SELECT d.*,e.primer_nombre||' '||e.primer_apellido AS estudiante,e.codigo,c.nombre AS concepto,p.nombre AS periodo_nombre,u.nombre||' '||u.apellido AS aprobado FROM descuentos d JOIN estudiantes e ON e.id=d.estudiante_id JOIN conceptos_cobro c ON c.id=d.concepto_id LEFT JOIN periodos p ON p.id=d.periodo_id LEFT JOIN usuarios u ON u.id=d.aprobado_por WHERE ".implode(' AND ',$wh)." ORDER BY d.created_at DESC LIMIT 200",$pr);
$conceptosD=$db->fetchAll("SELECT id,codigo,nombre FROM conceptos_cobro WHERE tipo='descuento' AND activo=TRUE ORDER BY nombre");
$estudiantes=$db->fetchAll("SELECT id,codigo,primer_nombre||' '||primer_apellido AS nombre FROM estudiantes WHERE estado='activo' ORDER BY primer_apellido LIMIT 500");
$periodos=$db->fetchAll("SELECT id,nombre FROM periodos ORDER BY fecha_inicio DESC");
$exportUrl=APP_URL.'/modules/reportes/exportar.php?tipo=becas';
?>
<div class="flex gap-2 mb-4 items-center">
<button data-modal="modalBeca" class="btn btn-primary"><i class="fas fa-plus"></i> Asignar Beca/Descuento</button>
<a href="<?=$exportUrl?>&formato=excel" class="btn btn-success btn-sm"><i class="fas fa-file-excel"></i> Exportar Excel</a>
<a href="<?=$exportUrl?>&formato=csv" class="btn btn-outline btn-sm"><i class="fas fa-file-csv"></i> CSV</a>
</div>
<div class="card mb-3"><div class="card-body" style="padding:.8rem 1.2rem">
<form method="GET" class="flex gap-2 items-center flex-wrap">
<div class="search-bar" style="max-width:250px"><i class="fas fa-search"></i><input type="text" name="q" placeholder="Nombre o código..." value="<?=e($busq)?>"></div>
<select name="periodo" class="form-select" style="width:auto"><option value="">Todos los períodos</option><?php foreach($periodos as $p): ?><option value="<?=$p['id']?>" <?=$per==$p['id']?'selected':''?>><?=e($p['nombre'])?></option><?php endforeach; ?></select>
<label style="font-size:.85rem;display:flex;align-items:center;gap:.4rem;cursor:pointer"><input type="checkbox" name="todos" <?=!$svg?'checked':''?>> Mostrar inactivos</label>
<button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Filtrar</button>
<a href="?" class="btn btn-outline btn-sm">Limpiar</a>
<div style="margin-left:auto;font-size:.82rem;color:var(--col-muted)"><?=count($becas)?> beneficios</div>
</form></div></div>
<div class="card"><div class="table-wrapper"><table>
<thead><tr><th>Estudiante</th><th>Concepto</th><th>Período</th><th style="text-align:center">%</th><th class="text-right">Valor Máx.</th><th>Descripción</th><th>Aprobó</th><th>Estado</th><th>Acciones</th></tr></thead>
<tbody>
<?php foreach($becas as $b): ?>
<tr style="<?=!$b['vigente']?'opacity:.5':''?>">
<td><?=e($b['estudiante'])?><br><small style="color:var(--col-muted)"><?=e($b['codigo'])?></small></td>
<td><span class="badge badge-success" style="font-size:.7rem"><?=e($b['concepto'])?></span></td>
<td><small><?=e($b['periodo_nombre']??'Todos los períodos')?></small></td>
<td style="text-align:center;font-weight:700;color:var(--col-success)"><?=number_format($b['porcentaje'],1)?>%</td>
<td class="text-right"><?=$b['valor_maximo']?formatoPeso($b['valor_maximo']):'—'?></td>
<td><small style="color:var(--col-muted)"><?=e(mb_substr($b['descripcion']??'',0,60))?></small></td>
<td><small><?=e($b['aprobado']??'—')?></small></td>
<td><?=estadoBadge($b['vigente']?'activo':'inactivo')?></td>
<td><div class="flex gap-1">
<button class="btn btn-outline btn-sm" onclick='editarBeca(<?=json_encode($b,JSON_HEX_APOS|JSON_HEX_QUOT)?>'><i class="fas fa-edit"></i></button>
<form method="POST" style="display:inline"><?=csrfField()?><input type="hidden" name="accion" value="toggle"><input type="hidden" name="beca_id" value="<?=$b['id']?>"><button type="submit" class="btn btn-<?=$b['vigente']?'warning':'success'?> btn-sm"><i class="fas fa-<?=$b['vigente']?'pause':'play'?>"></i></button></form>
</div></td></tr>
<?php endforeach; ?>
<?php if(empty($becas)): ?><tr><td colspan="9" class="text-center text-muted" style="padding:3rem">Sin becas registradas</td></tr><?php endif; ?>
</tbody></table></div></div>

<div class="modal-overlay" id="modalBeca"><div class="modal" style="max-width:500px">
<div class="modal-header"><h3 class="modal-title" id="mBecaTitulo"><i class="fas fa-award" style="color:var(--col-accent)"></i> Asignar Beca/Descuento</h3><button class="modal-close"><i class="fas fa-times"></i></button></div>
<form method="POST"><?=csrfField()?>
<input type="hidden" name="accion" id="bAccion" value="crear"><input type="hidden" name="beca_id" id="bId" value="0">
<div class="modal-body">
<div class="form-group"><label>Estudiante *</label><select name="estudiante_id" id="bEst" class="form-select" required><option value="">Seleccione...</option><?php foreach($estudiantes as $e): ?><option value="<?=$e['id']?>">[<?=e($e['codigo'])?>] <?=e($e['nombre'])?></option><?php endforeach; ?></select></div>
<div class="form-row cols-2">
<div class="form-group"><label>Concepto *</label><select name="concepto_id" id="bCon" class="form-select" required><option value="">Seleccione...</option><?php foreach($conceptosD as $c): ?><option value="<?=$c['id']?>"><?=e($c['nombre'])?></option><?php endforeach; ?></select></div>
<div class="form-group"><label>Período</label><select name="periodo_id" id="bPer" class="form-select"><option value="">Todos los períodos</option><?php foreach($periodos as $p): ?><option value="<?=$p['id']?>"><?=e($p['nombre'])?></option><?php endforeach; ?></select></div>
</div>
<div class="form-row cols-2">
<div class="form-group"><label>Porcentaje % *</label><input type="number" name="porcentaje" id="bPct" class="form-control" required min="0.1" max="100" step="0.1" placeholder="50"></div>
<div class="form-group"><label>Valor máximo</label><input type="text" name="valor_maximo" id="bVmax" class="form-control input-currency" placeholder="Sin límite"></div>
</div>
<div class="form-group"><label>Descripción / Soporte</label><textarea name="descripcion" id="bDesc" class="form-control" rows="2" placeholder="Ej: Beca excelencia académica Res.001/2025..."></textarea></div>
<div class="form-group"><label style="cursor:pointer"><input type="checkbox" name="vigente" id="bVig" checked> Beca vigente</label></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline modal-close">Cancelar</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button></div>
</form></div></div>
<script>
function editarBeca(b){
    document.getElementById('bAccion').value='editar'; document.getElementById('bId').value=b.id;
    document.getElementById('bEst').value=b.estudiante_id; document.getElementById('bCon').value=b.concepto_id;
    document.getElementById('bPer').value=b.periodo_id||''; document.getElementById('bPct').value=b.porcentaje;
    document.getElementById('bVmax').value=b.valor_maximo?new Intl.NumberFormat('es-CO').format(b.valor_maximo):'';
    document.getElementById('bDesc').value=b.descripcion||'';
    document.getElementById('bVig').checked=b.vigente===true||b.vigente==='t'||b.vigente===1;
    document.getElementById('mBecaTitulo').innerHTML='<i class="fas fa-edit" style="color:var(--col-accent)"></i> Editar Beca';
    document.getElementById('modalBeca').classList.add('open');
}
</script>
<?php require_once __DIR__.'/../../includes/footer.php'; ?>
