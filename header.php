<?php
/**
 * Common header for the CMS using the Gentelella admin template.
 * This file starts the session, requires the user to be logged in,
 * and outputs the navigation sidebar and top bar. It should be
 * included at the beginning of every page that requires a logged
 * in user. Remember to include the corresponding footer.php to
 * close the HTML structure.
 */

require_once __DIR__ . '/functions.php';

// Start session and enforce that the user is logged in
startSession();
requireLogin();

// Get current user info
$user = currentUser();
$appName = getSetting('app_name', 'CMS');

// Flags to control optional assets
$useDataTables = $useDataTables ?? false;
$useDropzone   = $useDropzone ?? false;
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($appName); ?></title>
    <base href="<?= BASE_URL ?>">
<link rel="stylesheet" href="vendors/bootstrap/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="vendors/font-awesome/css/font-awesome.min.css">
<?php if ($useDataTables): ?>
<link rel="stylesheet" href="vendors/datatables.net-bs5/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="vendors/datatables.net-responsive-bs5/css/responsive.bootstrap5.min.css">
<?php endif; ?>

<?php if ($useDropzone): ?>
<link rel="stylesheet" href="vendors/dropzone/dist/dropzone.css">
<?php endif; ?>

<link rel="stylesheet" href="assets/css/custom.css">


    <!-- Custom styles for the CMS (optional) -->
    <style>
        /* You can put additional custom styles here */
    </style>
</head>
<body class="nav-md">
<div class="container body">
    <div class="main_container">
        <!-- Sidebar -->
        <div class="col-md-3 left_col">
            <div class="left_col scroll-view">
                <div class="navbar nav_title" style="border: 0;">
                    <a href="<?= BASE_URL ?>dashboard" class="site_title"><i class="fa fa-home"></i> <span><?= htmlspecialchars($appName); ?></span></a>
                </div>
                <div class="clearfix"></div>

                <!-- Profile info -->
                <div class="profile clearfix">
                    <div class="profile_info">
                        <span>Bem-vindo,</span>
                        <h2><?php echo htmlspecialchars($user['username']); ?></h2>
                    </div>
                </div>
                <br />

                <!-- Sidebar menu -->
                <div id="sidebar-menu" class="main_menu_side hidden-print main_menu">
                    <div class="menu_section">
                        <ul class="nav side-menu">
                            <li><a href="<?= BASE_URL ?>dashboard"><i class="fa fa-home"></i> Dashboard</a></li>
<?php
// Dynamically list each content type with shortcuts to common actions.
$sidebarTypes = getContentTypes();
foreach ($sidebarTypes as $sidebarType):
?>

                            <li><a><i class="<?php echo htmlspecialchars($sidebarType['icon'] ?? 'fa fa-file-text'); ?>"></i> <?php echo htmlspecialchars($sidebarType['label']); ?> <span class="fa fa-chevron-down"></span></a>

                                <ul class="nav child_menu">

                                    <li><a href="<?= BASE_URL ?><?php echo htmlspecialchars(rawurlencode($sidebarType['name'])); ?>/add">Adicionar</a></li>
                                    <li><a href="<?= BASE_URL ?><?php echo htmlspecialchars(rawurlencode($sidebarType['name'])); ?>">Listar</a></li>
<?php if (($user['role'] ?? 3) <= 2): ?>
                                    <li><a href="<?= BASE_URL . 'fields/' . $sidebarType['id']; ?>">Campos</a></li>
                                    <li><a href="<?= BASE_URL ?>content-types/taxonomies/<?php echo $sidebarType['id']; ?>">Taxonomias</a></li>
<?php endif; ?>
                                </ul>
                            </li>
<?php endforeach; ?>
<?php if (isModuleActive('contabilidade')): ?>
                            <li>
                                <a><i class="fa fa-upload"></i> Multi Upload <span class="fa fa-chevron-down"></span></a>
                                <ul class="nav child_menu">
                                    <li><a href="<?= BASE_URL ?>contabilidade/upload">Compras</a></li>
                                    <li><a href="<?= BASE_URL ?>contabilidade/saft">SAF-T</a></li>
                                </ul>
                            </li>
<?php endif; ?>
<?php if (isModuleActive('contabilidade') || isModuleActive('compras')): ?>
                            <li>
                                <a><i class="fa fa-tasks"></i> Classificação e Importação <span class="fa fa-chevron-down"></span></a>
                                <ul class="nav child_menu">
<?php if (isModuleActive('contabilidade')): ?>
                                    <li><a href="<?= BASE_URL ?>contabilidade/classificacao-importacao?import_type=1">Contabilidade -> Compras</a></li>
<?php endif; ?>
<?php if (isModuleActive('compras')): ?>
                                    <li><a href="<?= BASE_URL ?>contabilidade/classificacao-importacao?import_type=2">Importação de Compras</a></li>
<?php endif; ?>
                                </ul>
                            </li>
<?php endif; ?>
<?php if (($user['role'] ?? 3) <= 2): ?>
                            <li>
                                <a><i class="fa fa-table"></i> Tabelas <span class="fa fa-chevron-down"></span></a>
                                <ul class="nav child_menu">
                                    <li><a href="<?= BASE_URL ?>tabelas/departamentos">Departamentos</a></li>
                                </ul>
                            </li>
<?php endif; ?>
                        </ul>
                    </div>
                </div>
                <!-- /Sidebar menu -->
            </div>
        </div>
        <!-- /Sidebar -->

 <!-- Top navigation -->
<div class="top_nav">
  <div class="nav_menu">
    <nav class="d-flex align-items-center w-100">
      <div class="nav toggle">
        <a id="menu_toggle"><i class="fa fa-bars"></i></a>
      </div>

      <ul class="navbar-nav ms-auto d-flex align-items-center">

        <li class="nav-item dropdown ms-3">
          <a href="javascript:;" class="user-profile nav-link dropdown-toggle d-flex align-items-center"
             id="navbarDropdown" data-bs-toggle="dropdown" aria-expanded="false">
            <img src="<?= !empty($user['photo']) ? htmlspecialchars($user['photo']) : 'assets/images/img.jpg'; ?>" alt="">
            <span class="user-name"><?= htmlspecialchars($user['username']); ?></span>
          </a>
          <div class="dropdown-menu dropdown-menu-end dropdown-usermenu"
               aria-labelledby="navbarDropdown">
            <a class="dropdown-item" href="<?= BASE_URL ?>users/profile"><i class="fa fa-user"></i> Perfil</a>

        <?php if (($user['role'] ?? 3) <= 2): ?>
          <a class="dropdown-item" href="<?= BASE_URL ?>users"><i class="fa fa-users"></i> Utilizadores</a>
          <a class="dropdown-item" href="<?= BASE_URL ?>definicoes">
            <span class="badge bg-red float-end">50%</span>
            <i class="fa fa-cog"></i> <span>Definições</span>
          </a>
          <a class="dropdown-item" href="<?= BASE_URL ?>content-types"><i class="fa fa-cubes"></i> Tipos de Conteúdo</a>
          <a class="dropdown-item" href="<?= BASE_URL ?>taxonomies"><i class="fa fa-tags"></i> Taxonomias</a>
        <?php endif; ?>
        <a class="dropdown-item" href="<?= BASE_URL ?>terminar-sessao">
              <i class="fa fa-sign-out float-end"></i> Terminar Sessão
            </a>
          </div>
        </li>
      </ul>
    </nav>
  </div>
</div>
<!-- /Top navigation -->





       

        <!-- Page content -->
        <div class="right_col" role="main">
