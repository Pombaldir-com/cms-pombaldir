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
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CMS</title>
<link rel="stylesheet" href="vendors/bootstrap/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="vendors/font-awesome/css/font-awesome.min.css">
<link rel="stylesheet" href="vendors/datatables.net-bs5/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="vendors/datatables.net-responsive-bs5/css/responsive.bootstrap5.min.css">
<link rel="stylesheet" href="https://colorlibhq.github.io/gentelella/build/css/gentelella.min.css">
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
                    <a href="dashboard.php" class="site_title"><i class="fa fa-home"></i> <span>CMS</span></a>
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
                            <li><a href="dashboard.php"><i class="fa fa-home"></i> Dashboard</a></li>
                            <li><a href="content_types.php"><i class="fa fa-cubes"></i> Tipos de Conteúdo</a></li>
                            <li><a href="taxonomies.php"><i class="fa fa-tags"></i> Taxonomias</a></li>
<?php
// Dynamically list each content type with shortcuts to common actions.
$sidebarTypes = getContentTypes();
foreach ($sidebarTypes as $sidebarType):
?>
                            <li><a><i class="fa fa-file-text"></i> <?php echo htmlspecialchars($sidebarType['label']); ?> <span class="fa fa-chevron-down"></span></a>
                                <ul class="nav child_menu">
                                    <li><a href="add_content.php?type_id=<?php echo $sidebarType['id']; ?>">Adicionar</a></li>
                                    <li><a href="list_content.php?type_id=<?php echo $sidebarType['id']; ?>">Listar</a></li>
                                    <li><a href="custom_fields.php?type_id=<?php echo $sidebarType['id']; ?>">Campos</a></li>
                                    <li><a href="content_type_taxonomies.php?type_id=<?php echo $sidebarType['id']; ?>">Taxonomias</a></li>
                                </ul>
                            </li>
<?php endforeach; ?>
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
                <nav class="navbar navbar-expand" role="navigation">
                    <ul class="navbar-nav ms-auto">
                        <!-- Explicit logout button for better visibility -->
                        <li class="nav-item">
                            <a href="logout.php" class="btn btn-sm btn-danger">
                                Terminar sessão (<?php echo htmlspecialchars($user['username']); ?>)
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        </div>
        <!-- /Top navigation -->

        <!-- Page content -->
        <div class="right_col" role="main">
