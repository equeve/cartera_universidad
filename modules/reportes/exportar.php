<?php
// modules/reportes/exportar.php - Exportador universal CSV y Excel
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();

$db     = Database::getInstance();
$tipo   = $_GET['tipo']   ?? '';
$fmt    = $_GET['formato'] ?? 'excel'; // excel | csv

function xlsHeader(string $f): void {
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"{$f}\"");
    header("Pragma: no-cache"); header("Expires: 0");
}
function csvHeader(string $f): void {
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"{$f}\"");
    header("Pragma: no-cache"); header("Expires: 0");
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
}
function toXls(array $cols, array $rows, string $titulo = ''): string {
    $x  = '<?xml version="1.0" encoding="UTF-8"?><?mso-application progid="Excel.Sheet"?>';
    $x .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
    $x .= '<Worksheet ss:Name="'.htmlspecialchars(mb_substr($titulo ?: 'Reporte', 0, 30)).'"><Table>';
    if ($titulo) $x .= '<Row><Cell ss:MergeAcross="'.(count($cols)-1).'"><Data ss:Type="String">'.htmlspecialchars($titulo).'</Data></Cell></Row>';
    $x .= '<Row>'; foreach ($cols as $h) $x .= '<Cell><Data ss:Type="String">'.htmlspecialchars($h).'</Data></Cell>'; $x .= '</Row>';
    foreach ($rows as $row) {
        $x .= '<Row>';
        foreach ($row as $v) {
            $num = is_numeric(str_replace(['.', ',', ' ', '$'], '', $v)) && !preg_match('/^\d{6,}$/', preg_replace('/\D/','',$v));
            $val = $num ? preg_replace('/[^0-9.,\-]/', '', str_replace('.', '', str_replace(',', '.', $v))) : $v;
            $x .= '<Cell><Data ss:Type="'.($num?'Number':'String').'">'.htmlspecialchars($val).'</Data></Cell>';
        }
        $x .= '</Row>';
    }
    $x .= '</Table></Worksheet></Workbook>';
    return $x;
}
function emitir(array $cols, array $rows, string $titulo, string $base, string $fmt): void {
    $fecha = date('Ymd');
    if ($fmt === 'csv') {
        csvHeader("{$base}_{$fecha}.csv");
        $h = fopen('php://output', 'w');
        fputcsv($h, $cols, ';');
        foreach ($rows as $r) fputcsv($h, $r, ';');
        fclose($h);
    } else {
        xlsHeader("{$base}_{$fecha}.xls");
        echo toXls($cols, $rows, $titulo);
    }
    exit;
}

// ── CARTERA ──────────────────────────────────────────────────
if ($tipo === 'cartera') {
    $periodo = (int)($_GET['periodo'] ?? 0);
    $where = ["f.estado IN ('pendiente','parcial','vencida')"]; $p = [];
    if ($periodo) { $where[] = 'f.periodo_id=?'; $p[] = $periodo; }
    $rows = $db->fetchAll(
        "SELECT f.numero_factura, e.codigo, e.primer_nombre||' '||e.primer_apellido,
                e.numero_documento, pr.nombre, per.nombre, f.fecha_emision,
                f.fecha_vencimiento, f.total, f.saldo,
                (CURRENT_DATE-f.fecha_vencimiento) AS dv, f.estado
         FROM facturas f JOIN estudiantes e ON e.id=f.estudiante_id
         JOIN programas pr ON pr.id=e.programa_id JOIN periodos per ON per.id=f.periodo_id
         WHERE ".implode(' AND ',$where)." ORDER BY f.saldo DESC", $p
    );
    $cols = ['N° Factura','Código','Estudiante','Documento','Programa','Período','F. Emisión','F. Vencimiento','Total','Saldo','Días Vencido','Estado'];
    $data = array_map(fn($r)=>array_values($r), $rows);
    foreach ($data as &$r) { $r[8]=number_format($r[8],2,',','.'); $r[9]=number_format($r[9],2,',','.'); }
    emitir($cols,$data,'Cartera por Cobrar - '.date('d/m/Y'),'cartera',$fmt);
}

// ── MOROSOS ──────────────────────────────────────────────────
if ($tipo === 'morosos') {
    $dias = (int)($_GET['dias'] ?? 1);
    $rows = $db->fetchAll(
        "SELECT e.codigo, e.primer_nombre||' '||e.primer_apellido, e.numero_documento,
                e.email, e.celular, pr.nombre, f.numero_factura, per.nombre AS periodo,
                f.total, f.saldo, f.fecha_vencimiento,
                (CURRENT_DATE-f.fecha_vencimiento) AS dv
         FROM facturas f JOIN estudiantes e ON e.id=f.estudiante_id
         JOIN programas pr ON pr.id=e.programa_id JOIN periodos per ON per.id=f.periodo_id
         WHERE f.estado IN ('vencida','parcial') AND f.saldo>0
           AND (CURRENT_DATE-f.fecha_vencimiento)>=?
         ORDER BY f.saldo DESC", [$dias]
    );
    $cols = ['Código','Estudiante','Documento','Email','Celular','Programa','Factura','Período','Total','Saldo','F. Vencimiento','Días Vencido'];
    $data = array_map(fn($r)=>array_values($r), $rows);
    foreach ($data as &$r) { $r[8]=number_format($r[8],2,',','.'); $r[9]=number_format($r[9],2,',','.'); }
    emitir($cols,$data,"Morosos (>{$dias} días) - ".date('d/m/Y'),'morosos',$fmt);
}

// ── RECAUDOS ─────────────────────────────────────────────────
if ($tipo === 'recaudos') {
    $desde = $_GET['desde'] ?? date('Y-m-01');
    $hasta = $_GET['hasta'] ?? date('Y-m-d');
    $rows = $db->fetchAll(
        "SELECT pg.numero_recibo, pg.fecha_pago::date, e.codigo,
                e.primer_nombre||' '||e.primer_apellido, pr.nombre,
                f.numero_factura, per.nombre AS periodo, mp.nombre AS medio,
                pg.referencia_bancaria, pg.valor
         FROM pagos pg JOIN facturas f ON f.id=pg.factura_id
         JOIN estudiantes e ON e.id=f.estudiante_id
         JOIN programas pr ON pr.id=e.programa_id
         JOIN periodos per ON per.id=f.periodo_id
         JOIN medios_pago mp ON mp.id=pg.medio_pago_id
         WHERE pg.estado='aplicado' AND pg.fecha_pago::date BETWEEN ? AND ?
         ORDER BY pg.fecha_pago", [$desde,$hasta]
    );
    $cols = ['N° Recibo','Fecha','Código','Estudiante','Programa','Factura','Período','Medio Pago','Referencia','Valor'];
    $data = array_map(fn($r)=>array_values($r), $rows);
    foreach ($data as &$r) { $r[9]=number_format($r[9],2,',','.'); }
    emitir($cols,$data,"Recaudos $desde a $hasta",'recaudos',$fmt);
}

// ── ESTADO DE CUENTA ─────────────────────────────────────────
if ($tipo === 'estado_cuenta') {
    $eid = (int)($_GET['estudiante_id'] ?? 0);
    $est = $db->fetchOne("SELECT codigo,primer_nombre||' '||primer_apellido AS nombre FROM estudiantes WHERE id=?",[$eid]);
    if (!$est) { http_response_code(404); echo "Estudiante no encontrado"; exit; }

    $facturas = $db->fetchAll(
        "SELECT f.numero_factura, per.nombre AS periodo, f.fecha_emision,
                f.fecha_vencimiento, f.total, f.saldo, f.estado
         FROM facturas f JOIN periodos per ON per.id=f.periodo_id
         WHERE f.estudiante_id=? AND f.estado!='anulada' ORDER BY f.fecha_emision DESC",[$eid]
    );
    $pagos = $db->fetchAll(
        "SELECT pg.numero_recibo, pg.fecha_pago::date, f.numero_factura, mp.nombre, pg.valor
         FROM pagos pg JOIN facturas f ON f.id=pg.factura_id
         JOIN medios_pago mp ON mp.id=pg.medio_pago_id
         WHERE f.estudiante_id=? AND pg.estado='aplicado' ORDER BY pg.fecha_pago DESC",[$eid]
    );

    if ($fmt === 'csv') {
        csvHeader("estado_cuenta_{$est['codigo']}_".date('Ymd').".csv");
        $h = fopen('php://output','w');
        fputcsv($h,['Estado de Cuenta — '.$est['nombre'].' ('.$est['codigo'].')'],';');
        fputcsv($h,[],';');
        fputcsv($h,['=== LIQUIDACIONES ==='],';');
        fputcsv($h,['Factura','Período','Emisión','Vencimiento','Total','Saldo','Estado'],';');
        foreach ($facturas as $r) fputcsv($h,[$r['numero_factura'],$r['periodo'],$r['fecha_emision'],$r['fecha_vencimiento'],number_format($r['total'],2,',','.'),number_format($r['saldo'],2,',','.'),$r['estado']],';');
        fputcsv($h,[],';');
        fputcsv($h,['=== PAGOS ==='],';');
        fputcsv($h,['Recibo','Fecha','Factura','Medio','Valor'],';');
        foreach ($pagos as $r) fputcsv($h,[$r['numero_recibo'],$r['fecha_pago'],$r['numero_factura'],$r['nombre'],number_format($r['valor'],2,',','.')],';');
        fclose($h);
    } else {
        xlsHeader("estado_cuenta_{$est['codigo']}_".date('Ymd').".xls");
        $cols=['Factura','Período','Emisión','Vencimiento','Total','Saldo','Estado'];
        $data=array_map(fn($r)=>[$r['numero_factura'],$r['periodo'],$r['fecha_emision'],$r['fecha_vencimiento'],number_format($r['total'],2,',','.'),number_format($r['saldo'],2,',','.'),$r['estado']],$facturas);
        echo toXls($cols,$data,"Estado de Cuenta — ".$est['nombre']);
    }
    exit;
}

// ── BECAS ────────────────────────────────────────────────────
if ($tipo === 'becas') {
    $rows = $db->fetchAll(
        "SELECT e.codigo, e.primer_nombre||' '||e.primer_apellido, c.nombre,
                d.porcentaje, d.valor_maximo, COALESCE(per.nombre,'Todos'),
                d.descripcion, CASE WHEN d.vigente THEN 'Sí' ELSE 'No' END,
                u.nombre||' '||u.apellido
         FROM descuentos d JOIN estudiantes e ON e.id=d.estudiante_id
         JOIN conceptos_cobro c ON c.id=d.concepto_id
         LEFT JOIN periodos per ON per.id=d.periodo_id
         LEFT JOIN usuarios u ON u.id=d.aprobado_por
         ORDER BY d.vigente DESC, e.primer_apellido"
    );
    $cols=['Código','Estudiante','Concepto','% Descuento','Valor Máximo','Período','Descripción','Vigente','Aprobó'];
    $data=array_map(fn($r)=>array_values($r),$rows);
    foreach($data as &$r){$r[3]=number_format($r[3],1,',','.');$r[4]=$r[4]?number_format($r[4],2,',','.'):'—';}
    emitir($cols,$data,'Becas y Descuentos - '.date('d/m/Y'),'becas',$fmt);
}

// ── PATROCINIOS ───────────────────────────────────────────────
if ($tipo === 'patrocinios') {
    $rows = $db->fetchAll(
        "SELECT e.codigo, e.primer_nombre||' '||e.primer_apellido,
                pa.nombre, pa.tipo, COALESCE(pt.porcentaje::text,'—'),
                pt.valor, COALESCE(pt.fecha_inicio::text,'—'), COALESCE(pt.fecha_fin::text,'—'),
                pt.estado, COALESCE(per.nombre,'—'), COALESCE(f.numero_factura,'—')
         FROM patrocinios pt JOIN estudiantes e ON e.id=pt.estudiante_id
         JOIN patrocinadores pa ON pa.id=pt.patrocinador_id
         LEFT JOIN periodos per ON per.id=pt.periodo_id
         LEFT JOIN facturas f ON f.id=pt.factura_id
         ORDER BY pt.created_at DESC"
    );
    $cols=['Código','Estudiante','Patrocinador','Tipo','%','Valor','F. Inicio','F. Fin','Estado','Período','Factura'];
    $data=array_map(fn($r)=>array_values($r),$rows);
    foreach($data as &$r){$r[5]=number_format($r[5],2,',','.');}
    emitir($cols,$data,'Patrocinios - '.date('d/m/Y'),'patrocinios',$fmt);
}

// ── ACUERDOS ─────────────────────────────────────────────────
if ($tipo === 'acuerdos') {
    $rows = $db->fetchAll(
        "SELECT e.codigo, e.primer_nombre||' '||e.primer_apellido,
                f.numero_factura, ap.fecha_acuerdo::text, ap.valor_total,
                ap.numero_cuotas, ap.estado,
                (SELECT COUNT(*) FROM cuotas_acuerdo WHERE acuerdo_id=ap.id AND estado='pendiente'),
                (SELECT COALESCE(SUM(valor_pagado),0) FROM cuotas_acuerdo WHERE acuerdo_id=ap.id)
         FROM acuerdos_pago ap JOIN facturas f ON f.id=ap.factura_id
         JOIN estudiantes e ON e.id=f.estudiante_id
         ORDER BY ap.created_at DESC"
    );
    $cols=['Código','Estudiante','Factura','Fecha Acuerdo','Valor Total','N° Cuotas','Estado','Cuotas Pend.','Valor Pagado'];
    $data=array_map(fn($r)=>array_values($r),$rows);
    foreach($data as &$r){$r[4]=number_format($r[4],2,',','.');$r[8]=number_format($r[8],2,',','.');}
    emitir($cols,$data,'Acuerdos de Pago - '.date('d/m/Y'),'acuerdos',$fmt);
}

// ── MORA ─────────────────────────────────────────────────────
if ($tipo === 'mora') {
    $rows = $db->fetchAll(
        "SELECT mr.fecha_calculo::text, f.numero_factura,
                e.codigo, e.primer_nombre||' '||e.primer_apellido,
                mr.dias_mora, mr.capital_vencido, mr.valor_mora,
                mr.tasa_aplicada,
                CASE WHEN mr.cobrada THEN 'Sí' ELSE 'No' END
         FROM mora_registrada mr JOIN facturas f ON f.id=mr.factura_id
         JOIN estudiantes e ON e.id=f.estudiante_id
         ORDER BY mr.created_at DESC"
    );
    $cols=['Fecha Cálculo','Factura','Código','Estudiante','Días Mora','Capital Vencido','Valor Mora','Tasa Diaria %','Cobrada'];
    $data=array_map(fn($r)=>array_values($r),$rows);
    foreach($data as &$r){$r[5]=number_format($r[5],2,',','.');$r[6]=number_format($r[6],2,',','.');}
    emitir($cols,$data,'Mora Registrada - '.date('d/m/Y'),'mora',$fmt);
}

http_response_code(400);
echo "Tipo no válido. Opciones: cartera | morosos | recaudos | estado_cuenta | becas | patrocinios | acuerdos | mora";

// ── EDADES DE CARTERA ─────────────────────────────────────────
if ($tipo === 'edades_cartera') {
    $periodo  = (int)($_GET['periodo']  ?? 0);
    $programa = (int)($_GET['programa'] ?? 0);
    $where = ["f.estado IN ('pendiente','parcial','vencida')","f.saldo>0"]; $p=[];
    if($periodo) { $where[]='f.periodo_id=?'; $p[]=$periodo; }
    if($programa){ $where[]='e.programa_id=?'; $p[]=$programa; }
    $rows = $db->fetchAll(
        "SELECT CASE WHEN CURRENT_DATE<=f.fecha_vencimiento THEN 'Vigente' WHEN (CURRENT_DATE-f.fecha_vencimiento)<=30 THEN '1-30 días' WHEN (CURRENT_DATE-f.fecha_vencimiento)<=60 THEN '31-60 días' WHEN (CURRENT_DATE-f.fecha_vencimiento)<=90 THEN '61-90 días' ELSE 'Más de 90 días' END AS rango,
                e.codigo, e.primer_nombre||' '||e.primer_apellido AS est, e.numero_documento, e.email, e.celular, pr.nombre AS prog, per.nombre AS periodo,
                f.numero_factura, f.fecha_vencimiento::text, (CURRENT_DATE-f.fecha_vencimiento) AS dias, f.total, f.saldo
         FROM facturas f JOIN estudiantes e ON e.id=f.estudiante_id JOIN programas pr ON pr.id=e.programa_id JOIN periodos per ON per.id=f.periodo_id
         WHERE ".implode(' AND ',$where)." ORDER BY 1 DESC, f.saldo DESC",$p
    );
    $cols=['Rango','Código','Estudiante','Documento','Email','Celular','Programa','Período','Factura','Vencimiento','Días Vencido','Total','Saldo'];
    $data=array_map(fn($r)=>array_values($r),$rows);
    foreach($data as &$r){ $r[11]=number_format($r[11],2,',','.'); $r[12]=number_format($r[12],2,',','.'); }
    emitir($cols,$data,'Edades de Cartera - '.date('d/m/Y'),'edades_cartera',$fmt);
}

// ── ESTADÍSTICO MATRICULADOS ──────────────────────────────────
if ($tipo === 'matriculados') {
    $periodo  = (int)($_GET['periodo']  ?? 0);
    $programa = (int)($_GET['programa'] ?? 0);
    $where=['e.estado=\'activo\'']; $p=[];
    if($periodo) { $where[]="EXISTS(SELECT 1 FROM facturas f WHERE f.estudiante_id=e.id AND f.periodo_id=?)"; $p[]=$periodo; }
    if($programa){ $where[]='e.programa_id=?'; $p[]=$programa; }
    $rows = $db->fetchAll(
        "SELECT e.codigo, e.primer_nombre||' '||e.primer_apellido AS nombre, e.tipo_documento, e.numero_documento,
                COALESCE(e.tipo_admision,'nuevo') AS tipo_admision, COALESCE(e.opcion_grado,'–') AS opcion_grado,
                pr.nombre AS programa, pr.nivel, e.semestre_actual::text, e.estrato::text,
                e.email, e.celular, e.fecha_ingreso::text
         FROM estudiantes e LEFT JOIN programas pr ON pr.id=e.programa_id WHERE ".implode(' AND ',$where)." ORDER BY pr.nombre, e.primer_apellido",$p
    );
    $cols=['Código','Nombre','Doc. Tipo','Documento','Tipo Admisión','Opción Grado','Programa','Nivel','Semestre','Estrato','Email','Celular','F. Ingreso'];
    $data=array_map(fn($r)=>array_values($r),$rows);
    emitir($cols,$data,'Estadístico Matriculados - '.date('d/m/Y'),'matriculados',$fmt);
}

// ── CRÉDITOS ─────────────────────────────────────────────────
if ($tipo === 'creditos') {
    $rows = $db->fetchAll(
        "SELECT e.codigo, e.primer_nombre||' '||e.primer_apellido, c.tipo, c.tipo_credito_interno, c.entidad,
                c.monto_aprobado, c.monto_desembolsado, c.tasa_interes*100 AS tasa_pct, c.tasa_mora_mensual*100 AS mora_pct,
                c.plazo_meses::text, c.fecha_inicio::text, c.fecha_vencimiento::text, c.estado,
                (SELECT COUNT(*) FROM cuotas_acuerdo WHERE acuerdo_id=c.id AND estado='pendiente')::text AS cuotas_pend
         FROM creditos_estudiantiles c JOIN estudiantes e ON e.id=c.estudiante_id ORDER BY c.created_at DESC"
    );
    $cols=['Código','Estudiante','Tipo','Concepto','Entidad','Monto Aprobado','Desembolsado','Tasa %','Mora %','Plazo','F. Inicio','F. Vencimiento','Estado','Cuotas Pend.'];
    $data=array_map(fn($r)=>array_values($r),$rows);
    foreach($data as &$r){ $r[5]=number_format($r[5],2,',','.'); $r[6]=number_format($r[6],2,',','.'); $r[7]=number_format($r[7],4,',','.'); $r[8]=number_format($r[8],4,',','.'); }
    emitir($cols,$data,'Créditos Estudiantiles - '.date('d/m/Y'),'creditos',$fmt);
}
