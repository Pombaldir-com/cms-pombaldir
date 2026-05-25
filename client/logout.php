<?php
require_once __DIR__ . '/../functions.php';
startSession();
$tenantSlug = trim((string) ($_GET['tenant_slug'] ?? ($_SESSION['client_user_tenant_slug'] ?? '')));
clientLogout();
if ($tenantSlug !== '') {
    header('Location: ' . BASE_URL . 't/' . rawurlencode($tenantSlug) . '/cliente/login');
    exit;
}
header('Location: ' . BASE_URL . 'login');
