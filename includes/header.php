<?php
// includes/header.php
require_once __DIR__ . '/helpers.php';
requireLogin();
$usuario = sesionUsuario();
$paginaActual = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($tituloPagina ?? 'Sistema de Cartera') ?> · <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css">
</head>
<body class="<?= $paginaActual ?>-page">

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon"><i class="fas fa-university"></i></div>
        <div class="brand-text">
            <span class="brand-name">SisCartera</span>
            <span class="brand-sub">Universidad</span>
        </div>
    </div>

    <nav class="sidebar-nav">

        <!-- Principal -->
        <div class="nav-section">
            <span class="nav-label">Principal</span>
            <a href="<?= APP_URL ?>/dashboard.php" class="nav-item <?= $paginaActual==='dashboard'?'active':'' ?>">
                <i class="fas fa-chart-pie"></i><span>Dashboard</span>
            </a>
        </div>

        <!-- Gestión básica -->
        <div class="nav-section">
            <span class="nav-label">Gestión</span>
            <a href="<?= APP_URL ?>/modules/estudiantes/lista.php" class="nav-item <?= strpos($paginaActual,'estudiante')!==false?'active':'' ?>">
                <i class="fas fa-user-graduate"></i><span>Estudiantes</span>
            </a>
            <a href="<?= APP_URL ?>/modules/facturas/lista.php" class="nav-item <?= strpos($paginaActual,'factura')!==false?'active':'' ?>">
                <i class="fas fa-file-invoice-dollar"></i><span>Liquidaciones</span>
            </a>
            <a href="<?= APP_URL ?>/modules/pagos/lista.php" class="nav-item <?= strpos($paginaActual,'pago')!==false?'active':'' ?>">
                <i class="fas fa-money-bill-wave"></i><span>Pagos</span>
            </a>
        </div>

        <!-- Cartera avanzada -->
        <div class="nav-section">
            <span class="nav-label">Cartera</span>
            <a href="<?= APP_URL ?>/modules/cartera/estado_cuenta.php" class="nav-item <?= strpos($paginaActual,'estado_cuenta')!==false?'active':'' ?>">
                <i class="fas fa-user-clock"></i><span>Estado de Cuenta</span>
            </a>
            <a href="<?= APP_URL ?>/modules/cartera/acuerdos.php" class="nav-item <?= strpos($paginaActual,'acuerdo')!==false?'active':'' ?>">
                <i class="fas fa-handshake"></i><span>Acuerdos de Pago</span>
            </a>
            <a href="<?= APP_URL ?>/modules/cartera/becas.php" class="nav-item <?= strpos($paginaActual,'beca')!==false?'active':'' ?>">
                <i class="fas fa-award"></i><span>Becas / Descuentos</span>
            </a>
            <a href="<?= APP_URL ?>/modules/cartera/patrocinadores.php" class="nav-item <?= strpos($paginaActual,'patrocinador')!==false?'active':'' ?>">
                <i class="fas fa-building"></i><span>Patrocinadores</span>
            </a>
            <?php if (in_array($usuario['rol'], ['admin','financiero'])): ?>
            <a href="<?= APP_URL ?>/modules/cartera/mora.php" class="nav-item <?= strpos($paginaActual,'mora')!==false?'active':'' ?>">
                <i class="fas fa-fire"></i><span>Mora</span>
            </a>
            <a href="<?= APP_URL ?>/modules/cartera/creditos.php" class="nav-item <?= strpos($paginaActual,'credito')!==false?'active':'' ?>">
                <i class="fas fa-file-invoice-dollar"></i><span>Créditos</span>
            </a>
            <?php endif; ?>
        </div>

        <!-- Reportes -->
        <?php if (in_array($usuario['rol'], ['admin','financiero'])): ?>
        <div class="nav-section">
            <span class="nav-label">Reportes</span>
            <a href="<?= APP_URL ?>/modules/reportes/cartera.php" class="nav-item <?= $paginaActual==='cartera'?'active':'' ?>">
                <i class="fas fa-chart-bar"></i><span>Cartera</span>
            </a>
            <a href="<?= APP_URL ?>/modules/reportes/recaudos.php" class="nav-item <?= strpos($paginaActual,'recaudo')!==false?'active':'' ?>">
                <i class="fas fa-hand-holding-dollar"></i><span>Recaudos</span>
            </a>
            <a href="<?= APP_URL ?>/modules/reportes/morosos.php" class="nav-item <?= strpos($paginaActual,'moroso')!==false?'active':'' ?>">
                <i class="fas fa-exclamation-triangle"></i><span>Morosos</span>
            </a>
            <!-- Exportables rápidos -->
            <div style="padding:.4rem 1.4rem .1rem;font-size:.68rem;color:rgba(255,255,255,.3);text-transform:uppercase;letter-spacing:.08em">Exportar</div>
            <a href="<?= APP_URL ?>/modules/reportes/exportar.php?tipo=cartera&formato=excel" class="nav-item" style="font-size:.8rem;padding:.45rem 1.4rem">
                <i class="fas fa-file-excel" style="font-size:.8rem"></i><span>Cartera Excel</span>
            </a>
            <a href="<?= APP_URL ?>/modules/reportes/exportar.php?tipo=recaudos&desde=<?= date('Y-m-01') ?>&hasta=<?= date('Y-m-d') ?>&formato=excel" class="nav-item" style="font-size:.8rem;padding:.45rem 1.4rem">
                <i class="fas fa-file-excel" style="font-size:.8rem"></i><span>Recaudos Excel</span>
            </a>
            <a href="<?= APP_URL ?>/modules/reportes/exportar.php?tipo=morosos&formato=excel" class="nav-item" style="font-size:.8rem;padding:.45rem 1.4rem">
                <i class="fas fa-file-excel" style="font-size:.8rem"></i><span>Morosos Excel</span>
            </a>
            <a href="<?= APP_URL ?>/modules/reportes/exportar.php?tipo=becas&formato=excel" class="nav-item" style="font-size:.8rem;padding:.45rem 1.4rem">
                <i class="fas fa-file-excel" style="font-size:.8rem"></i><span>Becas Excel</span>
            </a>
            <a href="<?= APP_URL ?>/modules/reportes/edades_cartera.php" class="nav-item <?= strpos($paginaActual,'edades')!==false?'active':'' ?>">
                <i class="fas fa-layer-group"></i><span>Edades de Cartera</span>
            </a>
            <a href="<?= APP_URL ?>/modules/reportes/estadistico_matriculados.php" class="nav-item <?= strpos($paginaActual,'estadistico')!==false?'active':'' ?>">
                <i class="fas fa-chart-bar"></i><span>Estadístico Matriculados</span>
            </a>
        </div>

        <!-- Contabilidad PUC -->
        <div class="nav-section">
            <span class="nav-label">Contabilidad (PUC)</span>
            <a href="<?= APP_URL ?>/modules/contabilidad/comprobantes.php" class="nav-item <?= strpos($paginaActual,'comprobante')!==false?'active':'' ?>">
                <i class="fas fa-book-open"></i><span>Comprobantes</span>
            </a>
            <a href="<?= APP_URL ?>/modules/contabilidad/plan_cuentas.php" class="nav-item <?= strpos($paginaActual,'plan_cuentas')!==false?'active':'' ?>">
                <i class="fas fa-sitemap"></i><span>Plan de Cuentas</span>
            </a>
            <a href="<?= APP_URL ?>/modules/contabilidad/libro_mayor.php" class="nav-item <?= strpos($paginaActual,'libro_mayor')!==false?'active':'' ?>">
                <i class="fas fa-book"></i><span>Libro Mayor</span>
            </a>
            <a href="<?= APP_URL ?>/modules/contabilidad/balance_prueba.php" class="nav-item <?= strpos($paginaActual,'balance_prueba')!==false?'active':'' ?>">
                <i class="fas fa-balance-scale"></i><span>Balance de Prueba</span>
            </a>
            <a href="<?= APP_URL ?>/modules/contabilidad/estados_financieros.php" class="nav-item <?= strpos($paginaActual,'estados_financieros')!==false?'active':'' ?>">
                <i class="fas fa-file-invoice"></i><span>Estados Financieros</span>
            </a>
        </div>
        <?php endif; ?>

        <!-- Configuración -->
        <?php if ($usuario['rol'] === 'admin'): ?>
        <div class="nav-section">
            <span class="nav-label">Configuración</span>
            <a href="<?= APP_URL ?>/modules/usuarios/lista.php" class="nav-item <?= strpos($paginaActual,'usuario')!==false?'active':'' ?>">
                <i class="fas fa-users-cog"></i><span>Usuarios</span>
            </a>
            <a href="<?= APP_URL ?>/modules/configuracion/periodos.php" class="nav-item <?= strpos($paginaActual,'periodo')!==false?'active':'' ?>">
                <i class="fas fa-calendar-alt"></i><span>Períodos</span>
            </a>
            <a href="<?= APP_URL ?>/modules/configuracion/conceptos.php" class="nav-item <?= strpos($paginaActual,'concepto')!==false?'active':'' ?>">
                <i class="fas fa-tags"></i><span>Conceptos de Cobro</span>
            </a>
        </div>
        <?php endif; ?>

    </nav>

    <div class="sidebar-footer">
        <div class="user-info">
            <div class="user-avatar"><?= strtoupper(substr($usuario['nombre'], 0, 1)) ?></div>
            <div class="user-detail">
                <span class="user-name"><?= e($usuario['nombre']) ?></span>
                <span class="user-role"><?= ucfirst($usuario['rol']) ?></span>
            </div>
        </div>
        <a href="<?= APP_URL ?>/logout.php" class="btn-logout" title="Cerrar sesión">
            <i class="fas fa-sign-out-alt"></i>
        </a>
    </div>
</aside>

<!-- Main content -->
<main class="main-content" id="mainContent">
    <header class="topbar">
        <button class="btn-menu-toggle" id="menuToggle">
            <i class="fas fa-bars"></i>
        </button>
        <div class="topbar-title">
            <h1><?= e($tituloPagina ?? '') ?></h1>
            <?php if (!empty($subtituloPagina)): ?>
            <span class="topbar-sub"><?= e($subtituloPagina) ?></span>
            <?php endif; ?>
        </div>
        <div class="topbar-actions">
            <?php $periodoActivo = Database::getInstance()->fetchOne("SELECT codigo, nombre FROM periodos WHERE activo = TRUE LIMIT 1"); ?>
            <?php if ($periodoActivo): ?>
            <span class="periodo-badge">
                <i class="fas fa-calendar-check"></i>
                <?= e($periodoActivo['nombre']) ?>
            </span>
            <?php endif; ?>
            <span class="topbar-date"><?= date('d/m/Y') ?></span>
        </div>
    </header>

    <div class="page-content">
        <!-- Flash messages -->
        <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="alert alert-success alert-dismissible">
            <i class="fas fa-check-circle"></i> <?= e($_SESSION['flash_success']) ?>
            <button class="alert-close"><i class="fas fa-times"></i></button>
        </div>
        <?php unset($_SESSION['flash_success']); endif; ?>
        <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="alert alert-danger alert-dismissible">
            <i class="fas fa-times-circle"></i> <?= e($_SESSION['flash_error']) ?>
            <button class="alert-close"><i class="fas fa-times"></i></button>
        </div>
        <?php unset($_SESSION['flash_error']); endif; ?>
