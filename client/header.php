<?php
require_once __DIR__ . '/../functions.php';

startSession();
$tenantSlug = trim((string) ($_GET['tenant_slug'] ?? ($_SESSION['client_user_tenant_slug'] ?? '')));
if ($tenantSlug === '' || !ensureTenantCompanyBySlug($tenantSlug)) {
    http_response_code(404);
    exit('Tenant invalida.');
}

requireClientLogin($tenantSlug);
$clientUser = currentClientUser();
$appName = getSetting('app_name', 'Portal Cliente');
$appLogo = trim((string) getSetting('app_logo', ''));
if ($appLogo !== '' && !file_exists(__DIR__ . '/../' . ltrim($appLogo, '/'))) {
    $appLogo = '';
}
$tenantPrefix = BASE_URL . 't/' . rawurlencode($tenantSlug) . '/cliente/';
$useDataTables = $useDataTables ?? false;
$currentClientPage = $currentClientPage ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($appName); ?> - Portal Cliente</title>
    <base href="<?= BASE_URL ?>">
    <link rel="stylesheet" href="vendors/bootstrap/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="vendors/font-awesome/css/font-awesome.min.css">
<?php if ($useDataTables): ?>
    <link rel="stylesheet" href="vendors/datatables.net-bs5/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="vendors/datatables.net-responsive-bs5/css/responsive.bootstrap5.min.css">
<?php endif; ?>
    <link rel="stylesheet" href="assets/css/custom.css">
    <style>
        .client-portal .right_col {
            background:
                radial-gradient(circle at 85% -10%, rgba(26, 187, 156, 0.08), transparent 42%),
                linear-gradient(180deg, #f6f9fc 0%, #eef3f8 100%);
            min-height: calc(100vh - 56px);
        }
        .client-portal .impersonation-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            margin: 12px 0 4px;
            padding: 7px 14px;
            background: #fbe6c8;
            border: 1px solid #f0ad4e;
            border-radius: 6px;
            color: #8a5a00;
            font-size: 13px;
            line-height: 1.3;
        }
        .client-portal .impersonation-bar--top {
            margin: 0;
            padding: 8px 18px;
            border-width: 0 0 1px;
            border-radius: 0;
            position: relative;
            z-index: 1100;
        }
        .client-portal .impersonation-bar__msg i {
            margin-right: 6px;
        }
        .client-portal .impersonation-bar__btn {
            flex: 0 0 auto;
            background: #f0ad4e;
            color: #fff;
            border-radius: 4px;
            padding: 4px 12px;
            font-weight: 600;
            white-space: nowrap;
            text-decoration: none;
        }
        .client-portal .impersonation-bar__btn:hover,
        .client-portal .impersonation-bar__btn:focus {
            background: #ec971f;
            color: #fff;
            text-decoration: none;
        }
        .client-portal .profile_info span {
            font-size: 14px;
            line-height: 1.25;
            display: block;
        }
        .client-portal .profile_info h2 {
            line-height: 1.25;
            font-size: 14px;
            margin-top: 0;
            font-weight: 400;
        }
        .client-portal .nav_title .site_title {
            display: block;
            width: 100%;
            text-align: center;
            padding: 10px 12px;
            height: auto;
            line-height: normal;
        }
        .client-portal .nav_title .tenant-logo {
            max-width: 100%;
            max-height: 72px;
            width: auto;
            height: auto;
            display: block;
            margin: 0 auto;
        }
        .client-portal .nav.side-menu > li.active > a {
            background: linear-gradient(90deg, rgba(26, 187, 156, 0.22) 0%, rgba(26, 187, 156, 0.08) 100%);
            border-right: 4px solid #1abb9c;
        }
        .client-portal .nav.side-menu > li > a {
            font-weight: 600;
        }
        .client-portal .top_nav .nav_menu {
            box-shadow: 0 1px 0 rgba(36, 56, 77, 0.08);
            min-height: 58px;
        }
        .client-portal .topbar-nav {
            margin: 0;
            float: right;
            display: flex;
            align-items: center;
            height: 58px;
        }
        .client-portal .topbar-nav > li {
            display: flex;
            align-items: center;
            height: 58px;
        }
        .client-portal .topbar-nav .user-profile {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #5a738e;
            font-weight: 600;
            padding: 0 6px;
            letter-spacing: 0;
            min-height: 58px;
        }
        .client-portal .topbar-nav .user-profile:hover,
        .client-portal .topbar-nav .user-profile:focus {
            color: #2a3f54;
            text-decoration: none;
        }
        .client-portal .topbar-nav .user-profile .user-name {
            font-size: inherit;
            font-weight: inherit;
            line-height: 1;
            color: inherit;
        }
        .client-portal .topbar-nav .dropdown-menu {
            min-width: 210px;
        }
        .client-portal .x_panel {
            border: 1px solid #dbe5ef;
            border-radius: 6px;
            box-shadow: 0 8px 24px rgba(36, 56, 77, 0.06);
        }
        .client-portal .x_title h2 {
            font-weight: 700;
            color: #2a3f54;
        }
        .client-portal .tile-stats {
            min-height: 118px;
            border: 1px solid #dfe7f0;
            box-shadow: none;
            padding-top: 12px;
            padding-bottom: 10px;
            margin-bottom: 12px;
        }
        .client-portal .tile-stats .icon {
            color: #9cb0c6;
            width: 52px;
            right: 12px;
        }
        .client-portal .tile-stats .icon i {
            font-size: 46px;
            line-height: 46px;
            opacity: 0.8;
        }
        .client-portal .tile-stats .count {
            font-size: 40px;
            color: #60758d;
        }
        .client-portal .tile-stats h3 {
            font-size: 14px;
            font-weight: 700;
            color: #7b8ea3;
        }
        .client-portal .tile_count {
            margin-bottom: 12px;
        }
        .client-portal .tile_count .tile_stats_count {
            border-bottom: 0;
            padding: 0 12px 10px;
        }
        .client-portal .tile_count .count_top {
            color: #73879c;
            font-weight: 700;
        }
        .client-portal .tile_count .count {
            font-size: 28px;
            line-height: 1.25;
            color: #2a3f54;
        }
        .client-portal .tile_count .count_bottom {
            color: #73879c;
        }
    </style>
</head>
<body class="nav-md client-portal">
<?php if (isClientImpersonation()): ?>
<div class="impersonation-bar impersonation-bar--top">
    <span class="impersonation-bar__msg">
        <i class="fa fa-user-secret"></i>
        Está a navegar como <strong><?= htmlspecialchars((string) ($clientUser['name'] ?: $clientUser['username'])); ?></strong> · modo impersonação
    </span>
    <a class="impersonation-bar__btn" href="<?= $tenantPrefix ?>stop-impersonation">
        <i class="fa fa-sign-out"></i> Terminar impersonação
    </a>
</div>
<?php endif; ?>
<div class="container body">
    <div class="main_container">
        <div class="col-md-3 left_col">
            <div class="left_col scroll-view">
                <div class="navbar nav_title" style="border: 0;">
                    <a href="<?= $tenantPrefix ?>dashboard" class="site_title">
<?php if ($appLogo !== ''): ?>
                        <img src="<?= htmlspecialchars(BASE_URL . ltrim($appLogo, '/')); ?>" alt="<?= htmlspecialchars($appName); ?>" class="tenant-logo">
<?php else: ?>
                        <i class="fa fa-user-circle"></i> <span>Portal Cliente</span>
<?php endif; ?>
                    </a>
                </div>
                <div class="clearfix"></div>
                <div class="profile clearfix">
                    <div class="profile_info">
                        <span>Bem-vindo,</span>
                        <h2><?= htmlspecialchars((string) ($clientUser['name'] ?: $clientUser['username'])); ?></h2>
                    </div>
                </div>
                <br />
                <div id="sidebar-menu" class="main_menu_side hidden-print main_menu">
                    <div class="menu_section">
                        <ul class="nav side-menu">
                            <li class="<?= $currentClientPage === 'dashboard' ? 'active' : ''; ?>"><a href="<?= $tenantPrefix ?>dashboard"><i class="fa fa-home"></i> Dashboard</a></li>
                            <li class="<?= $currentClientPage === 'documentos' ? 'active' : ''; ?>"><a href="<?= $tenantPrefix ?>documentos"><i class="fa fa-files-o"></i> Documentos</a></li>
                            <li class="<?= $currentClientPage === 'saft' ? 'active' : ''; ?>"><a href="<?= $tenantPrefix ?>saft"><i class="fa fa-upload"></i> Envio de SAF-T</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <div class="top_nav">
            <div class="nav_menu">
                <nav>
                    <div class="nav toggle">
                        <a id="menu_toggle"><i class="fa fa-bars"></i></a>
                    </div>
                    <ul class="nav navbar-nav navbar-right topbar-nav">
                        <li class="nav-item dropdown ms-3">
                            <a href="javascript:;" class="user-profile nav-link dropdown-toggle d-flex align-items-center"
                               id="clientNavbarDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <span class="user-name"><?= htmlspecialchars((string) ($clientUser['name'] ?: $clientUser['username'])); ?></span>
                            </a>
                            <div class="dropdown-menu dropdown-menu-end dropdown-usermenu" aria-labelledby="clientNavbarDropdown">
                                <a class="dropdown-item" href="<?= $tenantPrefix ?>logout">
                                    <i class="fa fa-sign-out float-end"></i> Terminar Sessão
                                </a>
                            </div>
                        </li>
                    </ul>
                </nav>
            </div>
        </div>
        <div class="right_col" role="main">
