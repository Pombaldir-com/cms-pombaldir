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
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-ENjdO4Dr2bkBIFxQpeo3xXbl4ClbBZ9OezHET57ikQRAxQF93FhjV0z9WTR2xmQf" crossorigin="anonymous">
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" integrity="sha512-dN3c8+Gl9FPT0IwwxDRf6b0yYmFjc7Nrg5oWlVm1DrF7kRquYnpHPgSlx2uP9duADy9OLdPzPU1jU5hTBdGdZg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <!-- Gentelella CSS (Bootstrap 5 based) -->
    <link rel="stylesheet" href="https://unpkg.com/gentelella@2.0.0/dist/css/gentelella.min.css">
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
                            <li><a href="content_types.php"><i class="fa fa-layer-group"></i> Tipos de Conteúdo</a></li>
                            <li><a href="taxonomies.php"><i class="fa fa-tags"></i> Taxonomias</a></li>
                            <li><a href="add_content.php"><i class="fa fa-plus-circle"></i> Adicionar Conteúdo</a></li>
                            <li><a href="list_content.php"><i class="fa fa-list"></i> Listar Conteúdo</a></li>
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
                <nav class="" role="navigation">
                    <ul class="nav navbar-nav navbar-right">
                        <li class="">
                            <a href="logout.php" class="user-profile">
                                <!-- Display username and logout link -->
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