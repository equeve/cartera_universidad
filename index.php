<?php
require_once __DIR__ . '/includes/helpers.php';
if (usuarioLogueado()) {
    header('Location: ' . APP_URL . '/dashboard.php');
} else {
    header('Location: ' . APP_URL . '/login.php');
}
exit;
