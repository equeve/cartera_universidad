<?php
// modules/pagos/recibo.php
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();

$db = Database::getInstance();
$id = (int)($_GET['id'] ?? 0);

$pago = $db->fetchOne(
    "SELECT pg.*, mp.nombre AS medio_pago,
            f.numero_factura, f.total AS factura_total, f.saldo AS factura_saldo,
            p.nombre AS periodo,
            e.primer_nombre || ' ' || e.primer_apellido AS estudiante_nombre,
            e.codigo AS estudiante_codigo, e.numero_documento, e.tipo_documento, e.email,
            pr.nombre AS programa, pr.facultad,
            u.nombre || ' ' || u.apellido AS cajero
     FROM pagos pg
     JOIN medios_pago mp ON mp.id = pg.medio_pago_id
     JOIN facturas f ON f.id = pg.factura_id
     JOIN periodos p ON p.id = f.periodo_id
     JOIN estudiantes e ON e.id = f.estudiante_id
     JOIN programas pr ON pr.id = e.programa_id
     JOIN usuarios u ON u.id = pg.registrado_por
     WHERE pg.id = ?",
    [$id]
);

if (!$pago) {
    $_SESSION['flash_error'] = 'Recibo no encontrado.';
    header('Location: ' . APP_URL . '/modules/pagos/lista.php');
    exit;
}

$tituloPagina = 'Recibo ' . $pago['numero_recibo'];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="flex gap-2 mb-4 no-print">
    <a href="javascript:history.back()" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Volver</a>
    <button onclick="window.print()" class="btn btn-primary btn-sm"><i class="fas fa-print"></i> Imprimir Recibo</button>
    <a href="<?= APP_URL ?>/modules/facturas/ver.php?id=<?= $pago['factura_id'] ?>" class="btn btn-outline btn-sm">
        <i class="fas fa-file-invoice"></i> Ver Factura
    </a>
</div>

<style>
@media print {
    .no-print { display: none !important; }
    .sidebar, .topbar, .main-content > header { display: none !important; }
    .main-content { margin: 0; }
    .page-content { padding: 0; }
    .recibo-wrapper { max-width: 100%; }
}
</style>

<div class="recibo-wrapper" style="max-width:600px;margin:0 auto">
    <div class="card">
        <div class="card-body" style="padding:2rem">

            <!-- Encabezado recibo -->
            <div style="text-align:center;margin-bottom:1.5rem;padding-bottom:1rem;border-bottom:2px solid var(--col-primary)">
                <div style="font-family:var(--font-display);font-size:1.8rem;color:var(--col-primary)">Universidad</div>
                <div style="font-size:.8rem;color:var(--col-muted)">Departamento de Gestión Financiera</div>
                <div style="margin-top:.8rem;padding:.4rem .8rem;background:var(--col-primary);color:#fff;display:inline-block;border-radius:4px;font-family:var(--font-display);font-size:1rem">
                    RECIBO OFICIAL DE CAJA
                </div>
            </div>

            <!-- Número y fecha -->
            <div style="display:flex;justify-content:space-between;margin-bottom:1.2rem;font-size:.85rem">
                <div>
                    <div style="color:var(--col-muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">N° Recibo</div>
                    <div style="font-family:var(--font-display);font-size:1.3rem;color:var(--col-primary)"><?= e($pago['numero_recibo']) ?></div>
                </div>
                <div style="text-align:right">
                    <div style="color:var(--col-muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Fecha y Hora</div>
                    <div style="font-weight:600"><?= formatoFecha($pago['fecha_pago'], 'd/m/Y H:i') ?></div>
                </div>
            </div>

            <?= estadoBadge($pago['estado']) ?>

            <div style="height:1px;background:var(--col-border);margin:1rem 0"></div>

            <!-- Datos estudiante -->
            <div style="margin-bottom:1rem">
                <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:var(--col-muted);margin-bottom:.5rem">Recibido de</div>
                <div style="font-size:1.1rem;font-weight:700;color:var(--col-primary)"><?= e($pago['estudiante_nombre']) ?></div>
                <div style="font-size:.83rem;color:var(--col-muted)">
                    <?= e($pago['tipo_documento'] . ' ' . $pago['numero_documento']) ?> ·
                    Código <?= e($pago['estudiante_codigo']) ?>
                </div>
                <div style="font-size:.83rem;color:var(--col-muted)"><?= e($pago['programa']) ?></div>
            </div>

            <!-- Concepto del pago -->
            <div style="background:rgba(26,58,92,.04);border-radius:var(--radius-md);padding:1rem;margin-bottom:1rem">
                <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:var(--col-muted);margin-bottom:.4rem">Concepto del Pago</div>
                <div style="font-size:.88rem">
                    <strong>Abono a liquidación de matrícula</strong> N° <?= e($pago['numero_factura']) ?><br>
                    Período: <?= e($pago['periodo']) ?><br>
                    <?php if ($pago['referencia_bancaria']): ?>
                    Ref. bancaria: <?= e($pago['referencia_bancaria']) ?> · <?= e($pago['banco'] ?? '') ?><br>
                    <?php endif; ?>
                    Medio de pago: <?= e($pago['medio_pago']) ?>
                </div>
            </div>

            <!-- Valor destacado -->
            <div style="border:2px solid var(--col-primary);border-radius:var(--radius-md);padding:1.2rem;text-align:center;margin-bottom:1rem">
                <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;color:var(--col-muted);margin-bottom:.3rem">Valor Recibido</div>
                <div style="font-family:var(--font-display);font-size:2rem;color:var(--col-primary)"><?= formatoPeso($pago['valor']) ?></div>
                <div style="font-size:.78rem;color:var(--col-muted);margin-top:.2rem">
                    (<?= numToLetras((float)$pago['valor']) ?> pesos colombianos)
                </div>
            </div>

            <!-- Estado de la factura tras pago -->
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.5rem;font-size:.83rem;text-align:center;margin-bottom:1rem">
                <div style="background:rgba(26,58,92,.05);border-radius:var(--radius-sm);padding:.5rem">
                    <div style="color:var(--col-muted);font-size:.72rem">Total Factura</div>
                    <div style="font-weight:700"><?= formatoPeso($pago['factura_total']) ?></div>
                </div>
                <div style="background:rgba(22,163,74,.08);border-radius:var(--radius-sm);padding:.5rem">
                    <div style="color:var(--col-muted);font-size:.72rem">Este Pago</div>
                    <div style="font-weight:700;color:var(--col-success)"><?= formatoPeso($pago['valor']) ?></div>
                </div>
                <div style="background:<?= $pago['factura_saldo'] > 0 ? 'rgba(220,38,38,.08)' : 'rgba(22,163,74,.08)' ?>;border-radius:var(--radius-sm);padding:.5rem">
                    <div style="color:var(--col-muted);font-size:.72rem">Saldo Restante</div>
                    <div style="font-weight:700;color:<?= $pago['factura_saldo'] > 0 ? 'var(--col-danger)' : 'var(--col-success)' ?>"><?= formatoPeso($pago['factura_saldo']) ?></div>
                </div>
            </div>

            <!-- Pie del recibo -->
            <div style="border-top:1px dashed var(--col-border);padding-top:.8rem;margin-top:.8rem">
                <div style="display:flex;justify-content:space-between;font-size:.75rem;color:var(--col-muted)">
                    <div>Atendido por: <strong><?= e($pago['cajero']) ?></strong></div>
                    <div>Generado: <?= date('d/m/Y H:i:s') ?></div>
                </div>
                <?php if ($pago['observaciones']): ?>
                <div style="font-size:.75rem;color:var(--col-muted);margin-top:.3rem">Obs: <?= e($pago['observaciones']) ?></div>
                <?php endif; ?>
                <div style="text-align:center;font-size:.72rem;color:var(--col-muted);margin-top:.5rem">
                    Conserve este recibo como comprobante de pago · <?= APP_NAME ?>
                </div>

                <!-- Firma -->
                <div style="display:flex;justify-content:space-around;margin-top:2rem;padding-top:.5rem">
                    <div style="text-align:center;font-size:.75rem;color:var(--col-muted)">
                        <div style="border-top:1px solid #ccc;width:120px;margin:0 auto .2rem"></div>
                        Cajero / Tesorero
                    </div>
                    <div style="text-align:center;font-size:.75rem;color:var(--col-muted)">
                        <div style="border-top:1px solid #ccc;width:120px;margin:0 auto .2rem"></div>
                        Estudiante
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Función básica de número a letras para Colombia
function numToLetras(float $num): string {
    $num = (int) round($num);
    $unidades = ['','un','dos','tres','cuatro','cinco','seis','siete','ocho','nueve',
                 'diez','once','doce','trece','catorce','quince','dieciséis','diecisiete','dieciocho','diecinueve'];
    $decenas  = ['','diez','veinte','treinta','cuarenta','cincuenta','sesenta','setenta','ochenta','noventa'];
    $centenas = ['','ciento','doscientos','trescientos','cuatrocientos','quinientos','seiscientos','setecientos','ochocientos','novecientos'];

    if ($num === 0) return 'cero';
    if ($num < 20)  return $unidades[$num];
    if ($num < 100) return $decenas[(int)($num/10)] . ($num%10 ? ' y ' . $unidades[$num%10] : '');
    if ($num < 1000) {
        $c = (int)($num/100);
        $r = $num % 100;
        return ($c === 1 && $r === 0 ? 'cien' : $centenas[$c]) . ($r ? ' ' . numToLetras($r) : '');
    }
    if ($num < 1000000) {
        $m = (int)($num/1000);
        $r = $num % 1000;
        return ($m === 1 ? 'mil' : numToLetras($m) . ' mil') . ($r ? ' ' . numToLetras($r) : '');
    }
    $m = (int)($num/1000000);
    $r = $num % 1000000;
    return numToLetras($m) . ($m === 1 ? ' millón' : ' millones') . ($r ? ' ' . numToLetras($r) : '');
}
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
