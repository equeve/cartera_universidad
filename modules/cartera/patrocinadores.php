<?php
// modules/cartera/patrocinadores.php
$tituloPagina='Patrocinadores'; $subtituloPagina='Empresas, entidades y convenios financiadores';
require_once __DIR__.'/../../includes/header.php';
requireRol(['admin','financiero']);
$db=Database::getInstance();

if($_SERVER['REQUEST_METHOD']==='POST'){
    validarCSRF(); $accion=$_POST['accion']??'';

    if(in_array($accion,['crear_pat','editar_pat'])){
        $pid=(int)($_POST['pat_id']??0);
        $d=['tipo'=>$_POST['tipo']??'empresa','nit'=>trim($_POST['nit']??'')?:null,'nombre'=>trim($_POST['nombre']??''),
            'contacto_nombre'=>trim($_POST['contacto_nombre']??'')?:null,'contacto_email'=>trim($_POST['contacto_email']??'')?:null,
            'contacto_telefono'=>trim($_POST['contacto_telefono']??'')?:null,
            'porcentaje_max'=>(float)($_POST['porcentaje_max']??0)?:null,
            'valor_max_periodo'=>(float)str_replace(['.', ','],['','.'],$_POST['valor_max_periodo']??'0')?:null,
            'requiere_soporte'=>isset($_POST['requiere_soporte']),'observaciones'=>trim($_POST['observaciones']??'')?:null,
            'activo'=>isset($_POST['activo'])];
        if(!$d['nombre']){$_SESSION['flash_error']='El nombre es obligatorio.';}
        else{
            try{
                if($accion==='crear_pat') $db->query("INSERT INTO patrocinadores (tipo,nit,nombre,contacto_nombre,contacto_email,contacto_telefono,porcentaje_max,valor_max_periodo,requiere_soporte,observaciones,activo) VALUES (?,?,?,?,?,?,?,?,?,?,?)",[$d['tipo'],$d['nit'],$d['nombre'],$d['contacto_nombre'],$d['contacto_email'],$d['contacto_telefono'],$d['porcentaje_max'],$d['valor_max_periodo'],$d['requiere_soporte']?'true':'false',$d['observaciones'],$d['activo']?'true':'false']);
                else $db->query("UPDATE patrocinadores SET tipo=?,nit=?,nombre=?,contacto_nombre=?,contacto_email=?,contacto_telefono=?,porcentaje_max=?,valor_max_periodo=?,requiere_soporte=?,observaciones=?,activo=? WHERE id=?",[$d['tipo'],$d['nit'],$d['nombre'],$d['contacto_nombre'],$d['contacto_email'],$d['contacto_telefono'],$d['porcentaje_max'],$d['valor_max_periodo'],$d['requiere_soporte']?'true':'false',$d['observaciones'],$d['activo']?'true':'false',$pid]);
                $_SESSION['flash_success']='Patrocinador guardado.';
            }catch(Exception $e){$_SESSION['flash_error']='Error: '.$e->getMessage();}
        }
    }
    if($accion==='crear_patrocinio'){
        $vals=[(int)($_POST['estudiante_id']??0),(int)($_POST['patrocinador_id']??0),(int)($_POST['factura_id']??0)?:null,(int)($_POST['periodo_id']??0)?:null,(float)($_POST['porcentaje']??0)?:null,(float)str_replace(['.', ','],['','.'],$_POST['valor']??'0'),$_POST['fecha_inicio']??null,$_POST['fecha_fin']??null,trim($_POST['observaciones']??'')?:null,$usuario['id']];
        if(!$vals[0]||!$vals[1]||$vals[5]<=0){$_SESSION['flash_error']='Estudiante, patrocinador y valor son obligatorios.';}
        else{
            try{$db->query("INSERT INTO patrocinios (estudiante_id,patrocinador_id,factura_id,periodo_id,porcentaje,valor,fecha_inicio,fecha_fin,observaciones,aprobado_por) VALUES (?,?,?,?,?,?,?,?,?,?)",$vals);$_SESSION['flash_success']='Patrocinio asignado.';}
            catch(Exception $e){$_SESSION['flash_error']='Error: '.$e->getMessage();}
        }
    }
    if($accion==='cambiar_estado_patrocinio'){
        $db->query("UPDATE patrocinios SET estado=? WHERE id=?",[$_POST['nuevo_estado']??'cancelado',(int)($_POST['patrocinio_id']??0)]);
        $_SESSION['flash_success']='Estado actualizado.';
    }
    header('Location: '.APP_URL.'/modules/cartera/patrocinadores.php?tab='.($_GET['tab']??'patrocinadores')); exit;
}

$tab=$_GET['tab']??'patrocinadores';
$pats=$db->fetchAll("SELECT p.*,(SELECT COUNT(*) FROM patrocinios WHERE patrocinador_id=p.id) AS tot,(SELECT COALESCE(SUM(valor),0) FROM patrocinios WHERE patrocinador_id=p.id AND estado='vigente') AS vvig FROM patrocinadores p ORDER BY p.activo DESC,p.nombre");
$busq=trim($_GET['q']??'');
$wh=$busq?"(e.primer_nombre ILIKE ? OR e.primer_apellido ILIKE ? OR pa.nombre ILIKE ?)":"1=1";
$pr=$busq?["%$busq%","%$busq%","%$busq%"]:[];
$patrocinios=$db->fetchAll("SELECT pt.*,e.primer_nombre||' '||e.primer_apellido AS estudiante,e.codigo,pa.nombre AS patrocinador,pa.tipo AS pat_tipo,f.numero_factura,p.nombre AS periodo,u.nombre||' '||u.apellido AS aprobado FROM patrocinios pt JOIN estudiantes e ON e.id=pt.estudiante_id JOIN patrocinadores pa ON pa.id=pt.patrocinador_id LEFT JOIN facturas f ON f.id=pt.factura_id LEFT JOIN periodos p ON p.id=pt.periodo_id LEFT JOIN usuarios u ON u.id=pt.aprobado_por WHERE $wh ORDER BY pt.created_at DESC LIMIT 200",$pr);
$ests=$db->fetchAll("SELECT id,codigo,primer_nombre||' '||primer_apellido AS nombre FROM estudiantes WHERE estado='activo' ORDER BY primer_apellido LIMIT 500");
$periodos=$db->fetchAll("SELECT id,nombre FROM periodos ORDER BY fecha_inicio DESC");
$tipos=['empresa'=>'Empresa','entidad_publica'=>'Ent. Pública','fundacion'=>'Fundación','icetex'=>'ICETEX','convenio'=>'Convenio','otro'=>'Otro'];
$colP=['empresa'=>'primary','entidad_publica'=>'info','fundacion'=>'accent','icetex'=>'success','convenio'=>'warning','otro'=>'secondary'];
$colE=['vigente'=>'success','pagado'=>'info','cancelado'=>'danger','vencido'=>'warning'];
?>
<div style="display:flex;gap:.3rem;margin-bottom:1.2rem;border-bottom:2px solid var(--col-border);padding-bottom:0">
<?php foreach(['patrocinadores'=>'Patrocinadores','patrocinios'=>'Patrocinios Asignados'] as $k=>$v): ?>
<a href="?tab=<?=$k?>" style="padding:.6rem 1.2rem;font-size:.88rem;font-weight:500;text-decoration:none;border-radius:6px 6px 0 0;color:<?=$tab===$k?'var(--col-primary)':'var(--col-muted)'?>;background:<?=$tab===$k?'var(--col-surface)':'transparent'?>;border:<?=$tab===$k?'1.5px solid var(--col-border)':'none'?>;border-bottom:<?=$tab===$k?'2px solid var(--col-surface)':'none'?>;margin-bottom:-2px"><?=$v?></a>
<?php endforeach; ?>
</div>

<?php if($tab==='patrocinadores'): ?>
<div class="flex gap-2 mb-4">
<button data-modal="modalPat" class="btn btn-primary"><i class="fas fa-plus"></i> Nuevo Patrocinador</button>
<a href="<?=APP_URL?>/modules/reportes/exportar.php?tipo=patrocinios&formato=excel" class="btn btn-success btn-sm"><i class="fas fa-file-excel"></i> Exportar Excel</a>
</div>
<div class="stats-grid mb-4" style="grid-template-columns:repeat(4,1fr)">
<div class="stat-card"><div class="stat-icon primary"><i class="fas fa-building"></i></div><div><div class="stat-value"><?=count($pats)?></div><div class="stat-label">Patrocinadores</div></div></div>
<div class="stat-card"><div class="stat-icon success"><i class="fas fa-handshake"></i></div><div><div class="stat-value"><?=array_sum(array_column($pats,'tot'))?></div><div class="stat-label">Patrocinios Totales</div></div></div>
<div class="stat-card"><div class="stat-icon accent"><i class="fas fa-dollar-sign"></i></div><div><div class="stat-value"><?=formatoPeso(array_sum(array_column($pats,'vvig')))?></div><div class="stat-label">Valor Vigente</div></div></div>
<div class="stat-card"><div class="stat-icon info"><i class="fas fa-check-circle"></i></div><div><div class="stat-value"><?=count(array_filter($pats,fn($p)=>$p['activo']))?></div><div class="stat-label">Activos</div></div></div>
</div>
<div class="card"><div class="table-wrapper"><table>
<thead><tr><th>Nombre</th><th>Tipo</th><th>NIT</th><th>Contacto</th><th style="text-align:center">% Máx.</th><th class="text-right">Tope/Per.</th><th style="text-align:center">Patrocinios</th><th class="text-right">Valor Vigente</th><th>Estado</th><th></th></tr></thead>
<tbody>
<?php foreach($pats as $p): $col=$colP[$p['tipo']]??'secondary'; ?>
<tr style="<?=!$p['activo']?'opacity:.5':''?>">
<td><strong><?=e($p['nombre'])?></strong><?php if($p['observaciones']): ?><br><small style="color:var(--col-muted)"><?=e(substr($p['observaciones'],0,50))?></small><?php endif; ?></td>
<td><span class="badge badge-<?=$col?>"><?=$tipos[$p['tipo']]??'Otro'?></span></td>
<td><small><?=e($p['nit']??'—')?></small></td>
<td><small><?=e($p['contacto_nombre']??'')?><?=$p['contacto_email']?'<br><span style="color:var(--col-muted)">'.e($p['contacto_email']).'</span>':''?></small></td>
<td style="text-align:center"><?=$p['porcentaje_max']?number_format($p['porcentaje_max'],1).'%':'—'?></td>
<td class="text-right"><?=$p['valor_max_periodo']?formatoPeso($p['valor_max_periodo']):'—'?></td>
<td style="text-align:center"><?=number_format($p['tot'])?></td>
<td class="text-right font-bold" style="color:var(--col-success)"><?=formatoPeso($p['vvig'])?></td>
<td><?=estadoBadge($p['activo']?'activo':'inactivo')?></td>
<td><div class="flex gap-1">
<button class="btn btn-outline btn-sm" onclick='editPat(<?=json_encode($p,JSON_HEX_APOS|JSON_HEX_QUOT)?>'><i class="fas fa-edit"></i></button>
<a href="?tab=patrocinios&pat=<?=$p['id']?>" class="btn btn-accent btn-sm"><i class="fas fa-list"></i></a>
</div></td>
</tr>
<?php endforeach; ?>
<?php if(empty($pats)): ?><tr><td colspan="10" class="text-center text-muted" style="padding:3rem">Sin patrocinadores</td></tr><?php endif; ?>
</tbody></table></div></div>

<?php else: // tab patrocinios ?>
<div class="flex gap-2 mb-4 items-center">
<button data-modal="modalPat2" class="btn btn-primary"><i class="fas fa-plus"></i> Asignar Patrocinio</button>
<div class="search-bar" style="max-width:260px"><i class="fas fa-search"></i>
<form method="GET" style="display:contents"><input type="hidden" name="tab" value="patrocinios"><input type="text" name="q" placeholder="Estudiante o patrocinador..." value="<?=e($busq)?>" onchange="this.form.submit()"></form></div>
<div style="margin-left:auto;font-size:.82rem;color:var(--col-muted)"><?=count($patrocinios)?> patrocinios · <strong><?=formatoPeso(array_sum(array_column($patrocinios,'valor')))?></strong></div>
</div>
<div class="card"><div class="table-wrapper"><table>
<thead><tr><th>Estudiante</th><th>Patrocinador</th><th>Período/Factura</th><th style="text-align:center">%</th><th class="text-right">Valor</th><th>Vigencia</th><th>Estado</th><th>Aprobó</th><th></th></tr></thead>
<tbody>
<?php foreach($patrocinios as $pt): $col=$colE[$pt['estado']]??'secondary'; ?>
<tr>
<td><?=e($pt['estudiante'])?><br><small style="color:var(--col-muted)"><?=e($pt['codigo'])?></small></td>
<td><strong><?=e($pt['patrocinador'])?></strong><br><small style="color:var(--col-muted)"><?=$tipos[$pt['pat_tipo']]??''?></small></td>
<td><small><?=e($pt['periodo']??'')?><?=$pt['numero_factura']?'<br>'.e($pt['numero_factura']):''?></small></td>
<td style="text-align:center"><?=$pt['porcentaje']?number_format($pt['porcentaje'],1).'%':'—'?></td>
<td class="text-right font-bold" style="color:var(--col-success)"><?=formatoPeso($pt['valor'])?></td>
<td><small><?=$pt['fecha_inicio']?formatoFecha($pt['fecha_inicio']).' → '.($pt['fecha_fin']?formatoFecha($pt['fecha_fin']):'∞'):'—'?></small></td>
<td><span class="badge badge-<?=$col?>"><?=ucfirst($pt['estado'])?></span></td>
<td><small><?=e($pt['aprobado']??'—')?></small></td>
<td><?php if($pt['estado']==='vigente'): ?>
<div class="flex gap-1">
<form method="POST" style="display:inline"><?=csrfField()?><input type="hidden" name="accion" value="cambiar_estado_patrocinio"><input type="hidden" name="patrocinio_id" value="<?=$pt['id']?>"><input type="hidden" name="nuevo_estado" value="pagado"><button type="submit" class="btn btn-success btn-sm" title="Pagado"><i class="fas fa-check"></i></button></form>
<form method="POST" style="display:inline"><?=csrfField()?><input type="hidden" name="accion" value="cambiar_estado_patrocinio"><input type="hidden" name="patrocinio_id" value="<?=$pt['id']?>"><input type="hidden" name="nuevo_estado" value="cancelado"><button type="submit" class="btn btn-danger btn-sm" title="Cancelar"><i class="fas fa-times"></i></button></form>
</div>
<?php endif; ?></td>
</tr>
<?php endforeach; ?>
<?php if(empty($patrocinios)): ?><tr><td colspan="9" class="text-center text-muted" style="padding:3rem">Sin patrocinios asignados</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php endif; ?>

<!-- Modal Patrocinador -->
<div class="modal-overlay" id="modalPat"><div class="modal" style="max-width:520px">
<div class="modal-header"><h3 class="modal-title" id="mPatTit"><i class="fas fa-building" style="color:var(--col-accent)"></i> Nuevo Patrocinador</h3><button class="modal-close"><i class="fas fa-times"></i></button></div>
<form method="POST"><?=csrfField()?><input type="hidden" name="accion" id="pAccion" value="crear_pat"><input type="hidden" name="pat_id" id="pId" value="0">
<div class="modal-body">
<div class="form-row cols-2"><div class="form-group" style="grid-column:span 2"><label>Nombre *</label><input type="text" name="nombre" id="pNom" class="form-control" required></div>
<div class="form-group"><label>Tipo *</label><select name="tipo" id="pTipo" class="form-select"><?php foreach($tipos as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?></select></div>
<div class="form-group"><label>NIT</label><input type="text" name="nit" id="pNit" class="form-control" placeholder="900123456-7"></div></div>
<div class="form-row cols-3">
<div class="form-group"><label>Contacto</label><input type="text" name="contacto_nombre" id="pCon" class="form-control"></div>
<div class="form-group"><label>Email</label><input type="email" name="contacto_email" id="pEmail" class="form-control"></div>
<div class="form-group"><label>Teléfono</label><input type="text" name="contacto_telefono" id="pTel" class="form-control"></div>
</div>
<div class="form-row cols-2">
<div class="form-group"><label>% Máximo</label><input type="number" name="porcentaje_max" id="pPct" class="form-control" min="0" max="100" step="0.1"></div>
<div class="form-group"><label>Tope por período</label><input type="text" name="valor_max_periodo" id="pTope" class="form-control input-currency" placeholder="Sin límite"></div>
</div>
<div class="form-group"><label>Observaciones</label><textarea name="observaciones" id="pObs" class="form-control" rows="2"></textarea></div>
<div class="flex gap-3"><label style="cursor:pointer"><input type="checkbox" name="requiere_soporte" id="pSop" checked> Requiere soporte</label><label style="cursor:pointer"><input type="checkbox" name="activo" id="pAct" checked> Activo</label></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline modal-close">Cancelar</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button></div>
</form></div></div>

<!-- Modal Patrocinio -->
<div class="modal-overlay" id="modalPat2"><div class="modal" style="max-width:480px">
<div class="modal-header"><h3 class="modal-title"><i class="fas fa-handshake" style="color:var(--col-accent)"></i> Asignar Patrocinio</h3><button class="modal-close"><i class="fas fa-times"></i></button></div>
<form method="POST"><?=csrfField()?><input type="hidden" name="accion" value="crear_patrocinio">
<div class="modal-body">
<div class="form-group"><label>Estudiante *</label><select name="estudiante_id" class="form-select" required><option value="">Seleccione...</option><?php foreach($ests as $e): ?><option value="<?=$e['id']?>">[<?=e($e['codigo'])?>] <?=e($e['nombre'])?></option><?php endforeach; ?></select></div>
<div class="form-group"><label>Patrocinador *</label><select name="patrocinador_id" class="form-select" required><option value="">Seleccione...</option><?php foreach($pats as $p): if(!$p['activo']) continue; ?><option value="<?=$p['id']?>"><?=e($p['nombre'])?></option><?php endforeach; ?></select></div>
<div class="form-row cols-2">
<div class="form-group"><label>Período</label><select name="periodo_id" class="form-select"><option value="">No aplica</option><?php foreach($periodos as $p): ?><option value="<?=$p['id']?>"><?=e($p['nombre'])?></option><?php endforeach; ?></select></div>
<div class="form-group"><label>% Financiado</label><input type="number" name="porcentaje" class="form-control" min="0" max="100" step="0.1" placeholder="100"></div>
</div>
<div class="form-group"><label>Valor (COP) *</label><input type="text" name="valor" class="form-control input-currency" required placeholder="0"></div>
<div class="form-row cols-2"><div class="form-group"><label>Inicio</label><input type="date" name="fecha_inicio" class="form-control"></div><div class="form-group"><label>Fin</label><input type="date" name="fecha_fin" class="form-control"></div></div>
<div class="form-group"><label>Observaciones</label><textarea name="observaciones" class="form-control" rows="2"></textarea></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline modal-close">Cancelar</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Asignar</button></div>
</form></div></div>

<script>
function editPat(p){
    document.getElementById('pAccion').value='editar_pat'; document.getElementById('pId').value=p.id;
    document.getElementById('pNom').value=p.nombre; document.getElementById('pTipo').value=p.tipo;
    document.getElementById('pNit').value=p.nit||''; document.getElementById('pCon').value=p.contacto_nombre||'';
    document.getElementById('pEmail').value=p.contacto_email||''; document.getElementById('pTel').value=p.contacto_telefono||'';
    document.getElementById('pPct').value=p.porcentaje_max||'';
    document.getElementById('pTope').value=p.valor_max_periodo?new Intl.NumberFormat('es-CO').format(p.valor_max_periodo):'';
    document.getElementById('pObs').value=p.observaciones||'';
    document.getElementById('pSop').checked=p.requiere_soporte===true||p.requiere_soporte==='t';
    document.getElementById('pAct').checked=p.activo===true||p.activo==='t';
    document.getElementById('mPatTit').innerHTML='<i class="fas fa-edit" style="color:var(--col-accent)"></i> Editar Patrocinador';
    document.getElementById('modalPat').classList.add('open');
}
</script>
<?php require_once __DIR__.'/../../includes/footer.php'; ?>
