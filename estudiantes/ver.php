<?php
// modules/estudiantes/ver.php
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();

$db = Database::getInstance();
$id = (int)($_GET['id'] ?? 0);

$estudiante = $db->fetchOne(
    "SELECT e.*, p.nombre AS programa, p.facultad, p.nivel, p.semestres_duracion
     FROM estudiantes e
     LEFT JOIN programas p ON p.id = e.programa_id
     WHERE e.id = ?",
    [$id]
);

if (!$estudiante) {
    $_SESSION['flash_error'] = 'Estudiante no encontrado.';
    header('Location: ' . APP_URL . '/modules/estudiantes/lista.php');
    exit;
}

$tituloPagina    = $estudiante['primer_nombre'] . ' ' . $estudiante['primer_apellido'];
$subtituloPagina = 'Perfil del estudiante · ' . $estudiante['codigo'];
require_once __DIR__ . '/../../includes/header.php';

// Facturas del estudiante
$facturas = $db->fetchAll(
    "SELECT f.*, p.nombre AS periodo, p.codigo AS periodo_codigo,
            (SELECT COALESCE(SUM(valor),0) FROM pagos WHERE factura_id = f.id AND estado = 'aplicado') AS pagado
     FROM facturas f
     JOIN periodos p ON p.id = f.periodo_id
     WHERE f.estudiante_id = ? AND f.estado != 'anulada'
     ORDER BY f.fecha_emision DESC",
    [$id]
);

// KPIs financieros
$kpis = $db->fetchOne(
    "SELECT COALESCE(SUM(f.total),0) AS total_facturado,
            COALESCE(SUM(CASE WHEN pg.valor IS NOT NULL THEN pg.valor ELSE 0 END),0) AS total_pagado,
            COALESCE(SUM(f.saldo),0) AS saldo_total,
            COUNT(f.id) AS num_facturas
     FROM facturas f
     LEFT JOIN pagos pg ON pg.factura_id = f.id AND pg.estado = 'aplicado'
     WHERE f.estudiante_id = ? AND f.estado != 'anulada'",
    [$id]
);

// Últimos pagos
$ultimosPagos = $db->fetchAll(
    "SELECT pg.*, mp.nombre AS medio_pago, f.numero_factura
     FROM pagos pg
     JOIN facturas f ON f.id = pg.factura_id
     JOIN medios_pago mp ON mp.id = pg.medio_pago_id
     WHERE f.estudiante_id = ? AND pg.estado = 'aplicado'
     ORDER BY pg.fecha_pago DESC LIMIT 10",
    [$id]
);
?>

<div class="flex gap-2 mb-4">
    <a href="<?= APP_URL ?>/modules/estudiantes/lista.php" class="btn btn-outline btn-sm">
        <i class="fas fa-arrow-left"></i> Volver
    </a>
    <?php if (in_array($usuario['rol'], ['admin','financiero'])): ?>
    <a href="<?= APP_URL ?>/modules/estudiantes/form.php?id=<?= $id ?>" class="btn btn-outline btn-sm">
        <i class="fas fa-edit"></i> Editar
    </a>
    <?php endif; ?>
    <?php if (in_array($usuario['rol'], ['admin','financiero','cajero'])): ?>
    <a href="<?= APP_URL ?>/modules/facturas/nueva.php?estudiante_id=<?= $id ?>" class="btn btn-accent btn-sm">
        <i class="fas fa-file-invoice-dollar"></i> Nueva Liquidación
    </a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/modules/pagos/registrar.php?buscar=<?= urlencode($estudiante['codigo']) ?>" class="btn btn-success btn-sm">
        <i class="fas fa-hand-holding-dollar"></i> Registrar Pago
    </a>
</div>

<div style="display:grid;grid-template-columns:300px 1fr;gap:1.2rem">

<!-- Panel izquierdo -->
<div>
    <div class="card mb-3">
        <div class="card-body" style="text-align:center;padding:2rem 1.4rem">
            <div style="width:72px;height:72px;border-radius:50%;background:var(--col-primary);color:#fff;display:grid;place-items:center;font-size:1.8rem;font-family:var(--font-display);margin:0 auto 1rem">
                <?= strtoupper(substr($estudiante['primer_nombre'], 0, 1)) ?>
            </div>
            <h2 style="font-family:var(--font-display);font-size:1.2rem;color:var(--col-primary);line-height:1.2;margin-bottom:.3rem">
                <?= e($estudiante['primer_nombre'] . ' ' . ($estudiante['segundo_nombre'] ?? '') . ' ' . $estudiante['primer_apellido'] . ' ' . ($estudiante['segundo_apellido'] ?? '')) ?>
            </h2>
            <div style="font-size:.82rem;color:var(--col-muted);margin-bottom:.8rem"><?= e($estudiante['codigo']) ?></div>
            <?= estadoBadge($estudiante['estado']) ?>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Información Personal</h3></div>
        <div class="card-body" style="font-size:.83rem">
            <?php
            $campos = [
                ['label'=>'Documento','value'=> $estudiante['tipo_documento'] . ' ' . $estudiante['numero_documento']],
                ['label'=>'Email','value'=> $estudiante['email']],
                ['label'=>'Institucional','value'=> $estudiante['email_institucional'] ?? '–'],
                ['label'=>'Celular','value'=> $estudiante['celular'] ?? '–'],
                ['label'=>'Estrato','value'=> $estudiante['estrato'] ?? '–'],
                ['label'=>'Municipio','value'=> ($estudiante['municipio'] ?? '') . ' ' . ($estudiante['departamento'] ?? '')],
                ['label'=>'Ingreso','value'=> $estudiante['fecha_ingreso'] ? formatoFecha($estudiante['fecha_ingreso']) : '–'],
            ];
            foreach ($campos as $c): ?>
            <div style="display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid var(--col-border)">
                <span style="color:var(--col-muted)"><?= $c['label'] ?></span>
                <span style="font-weight:500;text-align:right;max-width:60%"><?= e($c['value']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title">Programa Académico</h3></div>
        <div class="card-body" style="font-size:.83rem">
            <div style="font-weight:600;margin-bottom:.3rem"><?= e($estudiante['programa'] ?? '–') ?></div>
            <div style="color:var(--col-muted);margin-bottom:.8rem"><?= e($estudiante['facultad'] ?? '') ?></div>
            <?php
            $camposAcad = [
                ['label'=>'Nivel','value'=> ucfirst($estudiante['nivel'] ?? '–')],
                ['label'=>'Semestre actual','value'=> $estudiante['semestre_actual'] ?? '–'],
                ['label'=>'Duración','value'=> ($estudiante['semestres_duracion'] ?? '–') . ' semestres'],
            ];
            foreach ($camposAcad as $c): ?>
            <div style="display:flex;justify-content:space-between;padding:.3rem 0;border-bottom:1px solid var(--col-border)">
                <span style="color:var(--col-muted)"><?= $c['label'] ?></span>
                <span style="font-weight:500"><?= e($c['value']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Panel derecho -->
<div>

    <!-- KPIs financieros -->
    <div class="stats-grid mb-3" style="grid-template-columns:repeat(4,1fr)">
        <div class="stat-card">
            <div class="stat-icon accent"><i class="fas fa-file-invoice-dollar"></i></div>
            <div>
                <div class="stat-value" style="font-size:1.2rem"><?= formatoPeso($kpis['total_facturado']) ?></div>
                <div class="stat-label">Total Facturado</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon success"><i class="fas fa-check-circle"></i></div>
            <div>
                <div class="stat-value" style="font-size:1.2rem"><?= formatoPeso($kpis['total_pagado']) ?></div>
                <div class="stat-label">Total Pagado</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon <?= $kpis['saldo_total'] > 0 ? 'danger' : 'success' ?>"><i class="fas fa-wallet"></i></div>
            <div>
                <div class="stat-value" style="font-size:1.2rem;color:<?= $kpis['saldo_total'] > 0 ? 'var(--col-danger)' : 'var(--col-success)' ?>">
                    <?= formatoPeso($kpis['saldo_total']) ?>
                </div>
                <div class="stat-label"><?= $kpis['saldo_total'] > 0 ? 'Saldo Pendiente' : 'Al Día ✓' ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon primary"><i class="fas fa-receipt"></i></div>
            <div>
                <div class="stat-value" style="font-size:1.2rem"><?= $kpis['num_facturas'] ?></div>
                <div class="stat-label">Liquidaciones</div>
            </div>
        </div>
    </div>

    <!-- Historial de liquidaciones -->
    <div class="card mb-3">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-history" style="color:var(--col-accent)"></i> Historial de Liquidaciones</h3>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>N° Factura</th>
                        <th>Período</th>
                        <th>Emisión</th>
                        <th>Vencimiento</th>
                        <th class="text-right">Total</th>
                        <th class="text-right">Pagado</th>
                        <th class="text-right">Saldo</th>
                        <th>Estado</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($facturas as $fac): ?>
                    <tr>
                        <td><strong><?= e($fac['numero_factura']) ?></strong></td>
                        <td><?= e($fac['periodo']) ?></td>
                        <td><?= formatoFecha($fac['fecha_emision']) ?></td>
                        <td>
                            <?= formatoFecha($fac['fecha_vencimiento']) ?>
                            <?php $dias = diasVencimiento($fac['fecha_vencimiento']); ?>
                            <?php if ($fac['estado'] !== 'pagada' && $dias < 0): ?>
                            <br><small style="color:var(--col-danger)"><?= abs($dias) ?> días venc.</small>
                            <?php endif; ?>
                        </td>
                        <td class="text-right"><?= formatoPeso($fac['total']) ?></td>
                        <td class="text-right" style="color:var(--col-success)"><?= formatoPeso($fac['pagado']) ?></td>
                        <td class="text-right font-bold" style="color:<?= $fac['saldo'] > 0 ? 'var(--col-danger)' : 'var(--col-success)' ?>">
                            <?= formatoPeso($fac['saldo']) ?>
                        </td>
                        <td><?= estadoBadge($fac['estado']) ?></td>
                        <td>
                            <div class="flex gap-2">
                                <a href="<?= APP_URL ?>/modules/facturas/ver.php?id=<?= $fac['id'] ?>" class="btn btn-outline btn-sm"><i class="fas fa-eye"></i></a>
                                <?php if (!in_array($fac['estado'], ['pagada','anulada'])): ?>
                                <a href="<?= APP_URL ?>/modules/pagos/registrar.php?factura_id=<?= $fac['id'] ?>" class="btn btn-accent btn-sm"><i class="fas fa-dollar-sign"></i></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($facturas)): ?>
                    <tr><td colspan="9" class="text-center text-muted" style="padding:2rem">Sin liquidaciones registradas</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Historial de pagos -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-money-bill-wave" style="color:var(--col-success)"></i> Historial de Pagos</h3>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr><th>Recibo</th><th>Fecha</th><th>Factura</th><th>Medio de Pago</th><th>Referencia</th><th class="text-right">Valor</th><th>Estado</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($ultimosPagos as $pg): ?>
                    <tr>
                        <td><strong><?= e($pg['numero_recibo']) ?></strong></td>
                        <td><?= formatoFecha($pg['fecha_pago'], 'd/m/Y H:i') ?></td>
                        <td><small><?= e($pg['numero_factura']) ?></small></td>
                        <td><?= e($pg['medio_pago']) ?></td>
                        <td><small><?= e($pg['referencia_bancaria'] ?? '–') ?></small></td>
                        <td class="text-right font-bold" style="color:var(--col-success)"><?= formatoPeso($pg['valor']) ?></td>
                        <td><?= estadoBadge($pg['estado']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($ultimosPagos)): ?>
                    <tr><td colspan="7" class="text-center text-muted" style="padding:2rem">Sin pagos registrados</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
