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
$appLogo = getSetting('app_logo', '');
if ($appLogo && !file_exists(__DIR__ . '/' . $appLogo)) {
    $appLogo = '';
}

// Flags to control optional assets
$useDataTables = $useDataTables ?? false;
$useDropzone   = $useDropzone ?? false;
$useSelect2    = $useSelect2 ?? false;
$useSwitchery  = $useSwitchery ?? false;
$aiEnabled = getSetting('ai_enabled', '0') === '1';
$internalChatEnabled = isInternalChatEnabled();
$showInternalChatFloating = $internalChatEnabled
    && function_exists('hasInternalChatTables')
    && hasInternalChatTables()
    && !($disableInternalChatFloating ?? false);
$aiChatFloating = !empty($user['ai_chat_floating'] ?? 0);
$migrationFlash = ((int) ($user['role'] ?? 3) === 1) ? pullSessionFlash('migration_runner') : null;
$migrationSummary = ((int) ($user['role'] ?? 3) === 1)
    ? getPendingMigrationsSummary(is_array($migrationFlash))
    : ['has_pending' => false, 'companies' => [], 'pending_total' => 0, 'errors' => []];
$showMigrationAlert = ((int) ($user['role'] ?? 3) === 1)
    && (!empty($migrationSummary['has_pending']) || !empty($migrationSummary['errors']) || is_array($migrationFlash));
$efaturaTopbarSelector = $efaturaTopbarSelector ?? ['enabled' => false];
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

<?php if ($useSelect2): ?>
<link rel="stylesheet" href="vendors/select2/dist/css/select2.min.css">
<?php endif; ?>
<?php if ($useSwitchery): ?>
<link rel="stylesheet" href="vendors/switchery/standalone/switchery.css">
<?php endif; ?>

<link rel="stylesheet" href="assets/css/custom.css">


    <!-- Custom styles for the CMS (optional) -->
    <style>
        /* You can put additional custom styles here */
<?php if ($showMigrationAlert): ?>
        .migration-alert-fixed {
            position: fixed;
            top: 66px;
            right: 18px;
            z-index: 1060;
            width: min(420px, calc(100vw - 36px));
        }
        .migration-alert-fixed .alert {
            margin-bottom: 10px;
            box-shadow: 0 14px 34px rgba(32, 45, 64, 0.18);
            border: 1px solid #d9e3ec;
        }
        .migration-alert-fixed .migration-output {
            max-height: 140px;
            overflow: auto;
            background: #ffffff;
            color: #2f3b52;
            border: 1px solid #d6dee8;
            border-radius: 4px;
            padding: 10px;
            font-size: 12px;
            line-height: 1.5;
            margin-top: 10px;
            white-space: pre-wrap;
        }
        .migration-alert-fixed .migration-company-list {
            margin: 8px 0 0;
            padding-left: 18px;
        }
<?php endif; ?>
        .topbar-efatura-selector {
            display: flex;
            align-items: center;
            white-space: nowrap;
            margin-bottom: 0;
            max-width: 380px;
        }
        .topbar-efatura-selector .form-control {
            width: 300px;
            min-width: 300px;
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .topbar-nav {
            flex-wrap: nowrap;
        }
        .topbar-nav .nav-item {
            flex: 0 0 auto;
        }
        .topbar-nav .nav-item.dropdown {
            margin-left: 12px;
            display: flex;
            align-items: center;
        }
        .topbar-nav .user-profile {
            white-space: nowrap;
            display: flex;
            align-items: center;
            padding-top: 0;
            padding-bottom: 0;
            min-height: 40px;
        }
        .topbar-nav .user-name {
            white-space: nowrap;
        }
        .topbar-chat-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-height: 40px;
            padding: 0 10px;
            color: #4f6278;
            text-decoration: none;
            font-weight: 600;
        }
        .topbar-chat-link:hover,
        .topbar-chat-link:focus {
            color: #2a3f54;
            text-decoration: none;
        }
        .topbar-chat-link .chat-link-label {
            white-space: nowrap;
        }
        .topbar-chat-link.has-unread {
            color: #1f78d1;
        }
        .topbar-chat-unread {
            display: none;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            border-radius: 999px;
            background: #1f78d1;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            line-height: 18px;
            text-align: center;
        }
        .topbar-chat-unread.is-public {
            background: #1f78d1;
        }
        .topbar-chat-unread.is-group {
            background: #26b99a;
        }
        .topbar-chat-unread.is-visible {
            display: inline-block;
        }
    </style>
    <script>
      (function () {
        try {
          localStorage.setItem('last_company_name', <?=
            json_encode((string) $appName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
          ?>);
        } catch (e) {
          // localStorage may be unavailable in private mode or restricted browsers.
        }
      })();
    </script>
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
<?php if ($internalChatEnabled && !$showInternalChatFloating): ?>
                            <li><a href="<?= BASE_URL ?>chat-interno"><i class="fa fa-comments-o"></i> Chat Interno</a></li>
<?php endif; ?>
<?php if ($aiEnabled && userHasDepartmentPermission('ai_assistant')): ?>
                            <li><a href="<?= BASE_URL ?>assistant"><i class="fa fa-comments"></i> Assistente AI</a></li>
<?php endif; ?>
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
<?php if (isModuleActive('contabilidade') && userHasDepartmentPermission('compras_upload')): ?>
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
                                <a><i class="fa fa-tasks"></i> Contabilidade <span class="fa fa-chevron-down"></span></a>
                                <ul class="nav child_menu">
<?php if (isModuleActive('contabilidade')): ?>
    <li><a href="<?= BASE_URL ?>contabilidade/classificacao-importacao?import_type=1">Classificação</a></li>
<?php if (userHasDepartmentPermission('ctb_importar_docs')): ?>
    <li><a href="<?= BASE_URL ?>contabilidade/classificacao-importacao?import_type=1&type=import">Importação</a></li>
<?php endif; ?>
<?php if (userHasDepartmentPermission('ctb_lancamentos_aceder')): ?>
<li><a href="<?= BASE_URL ?>contabilidade/lancamentos">Lançamentos</a></li>
<?php endif; ?>

<?php endif; ?>
<?php if (isModuleActive('compras')): ?>
                                    <li><a href="<?= BASE_URL ?>contabilidade/classificacao-importacao?import_type=2">Importação de Compras</a></li>
<?php endif; ?>
                                </ul>
                            </li>
<?php endif; ?>
<?php if (isModuleActive('efatura') && userHasDepartmentPermission('ctb_efatura_aceder')): ?>
                            <li>
                                <a><i class="fa fa-file-text-o"></i> E-fatura <span class="fa fa-chevron-down"></span></a>
                                <ul class="nav child_menu">
                                    <li><a href="<?= BASE_URL ?>contabilidade/efatura/empresas">Empresas</a></li>
                                    <li><a href="<?= BASE_URL ?>contabilidade/efatura/documentos">Documentos</a></li>
                                    <li><a href="<?= BASE_URL ?>contabilidade/efatura/sincronizacoes">Sincronizações</a></li>
                                </ul>
                            </li>
<?php endif; ?>
<?php if (isModuleActive('contabilidade') && ($user['role'] ?? 3) <= 2): ?>
                            <li>
                                <a><i class="fa fa-building"></i> Entidades <span class="fa fa-chevron-down"></span></a>
                                <ul class="nav child_menu">
                                    <li><a href="<?= BASE_URL ?>contabilidade/entidades/empresas">Empresas</a></li>
                                </ul>
                            </li>

<?php endif; ?>
<?php if (($user['role'] ?? 3) <= 2): ?>
                            <li>
                                <a><i class="fa fa-table"></i> Tabelas <span class="fa fa-chevron-down"></span></a>
                                <ul class="nav child_menu">
                                    <li><a href="<?= BASE_URL ?>tabelas/departamentos">Departamentos</a></li>
                                    <li><a href="<?= BASE_URL ?>contabilidade/ai-tarefas">Tarefas AI</a></li>

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

      <div class="ms-auto d-flex align-items-center">
<?php if (!empty($efaturaTopbarSelector['enabled']) && !empty($efaturaTopbarSelector['entities'])): ?>
      <div class="me-3 d-flex align-items-center">
          <form method="get" action="<?= htmlspecialchars((string) ($efaturaTopbarSelector['action'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" class="topbar-efatura-selector">
            <label for="efatura-top-empresa" class="me-2 text-muted small" style="margin-bottom:0;">Empresa</label>
            <select id="efatura-top-empresa" name="empresa" class="form-control input-sm" onchange="this.form.submit()">
<?php foreach (($efaturaTopbarSelector['entities'] ?? []) as $topEntity): ?>
<?php $topEntityValue = (string) ($topEntity['value'] ?? ($topEntity['id'] ?? '')); ?>
              <option value="<?= htmlspecialchars($topEntityValue, ENT_QUOTES, 'UTF-8'); ?>" <?= (string) ($efaturaTopbarSelector['selected_entity_id'] ?? '') === $topEntityValue ? 'selected' : ''; ?>>
                <?= htmlspecialchars((string) (($topEntity['label'] ?? ($topEntity['name'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?>
              </option>
<?php endforeach; ?>
            </select>
          </form>
      </div>
<?php endif; ?>
<?php if ($showInternalChatFloating): ?>
      <div class="d-flex align-items-center me-2">
        <a href="#" class="topbar-chat-link" id="internalChatTopbarLink" data-bs-toggle="modal" data-bs-target="#internalChatModal">
          <i class="fa fa-comments-o"></i> <span class="chat-link-label">Chat</span>
          <span class="topbar-chat-unread" id="internalChatUnreadBadge">0</span>
        </a>
      </div>
<?php endif; ?>
      <ul class="navbar-nav d-flex align-items-center topbar-nav">
        <li class="nav-item dropdown ms-3">
          <a href="javascript:;" class="user-profile nav-link dropdown-toggle d-flex align-items-center"
             id="navbarDropdown" data-bs-toggle="dropdown" aria-expanded="false">
            <img src="<?= !empty($user['photo']) ? htmlspecialchars($user['photo']) : (!empty($appLogo) ? htmlspecialchars($appLogo) : 'assets/images/img.jpg'); ?>" alt="">
            <span class="user-name"><?= htmlspecialchars($user['username']); ?></span>
          </a>
          <div class="dropdown-menu dropdown-menu-end dropdown-usermenu"
               aria-labelledby="navbarDropdown">
            <a class="dropdown-item" href="<?= BASE_URL ?>users/profile"><i class="fa fa-user"></i> Perfil</a>

        <?php if (($user['role'] ?? 3) <= 2): ?>
          <a class="dropdown-item" href="<?= BASE_URL ?>users"><i class="fa fa-users"></i> Utilizadores</a>
          <a class="dropdown-item" href="<?= BASE_URL ?>definicoes">
            <!--<span class="badge bg-red float-end">50%</span> -->
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
      </div>
    </nav>
  </div>
</div>
<!-- /Top navigation -->

<?php if ($showMigrationAlert): ?>
<div class="migration-alert-fixed">
    <?php if ($migrationFlash): ?>
        <div class="alert <?= ($migrationFlash['type'] ?? '') === 'success' ? 'alert-success' : 'alert-danger'; ?>" role="alert">
            <strong><?= htmlspecialchars((string) ($migrationFlash['message'] ?? '')); ?></strong>
            <?php if (!empty($migrationFlash['output']) && is_array($migrationFlash['output'])): ?>
                <div class="migration-output"><?= htmlspecialchars(implode("\n", $migrationFlash['output'])); ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($migrationSummary['has_pending']) || !empty($migrationSummary['errors'])): ?>
        <div class="alert alert-warning" role="alert">
            <div class="d-flex justify-content-between align-items-start gap-3">
                <div>
                    <?php if (!empty($migrationSummary['has_pending'])): ?>
                        <strong>Migracoes pendentes</strong><br>
                        <span>Existem migracoes pendentes nas bases configuradas. Podes executa-las diretamente pela interface.</span>
                    <?php else: ?>
                        <strong>Verificacao de migracoes com alertas</strong><br>
                        <span>As migracoes conhecidas ja estao aplicadas, mas houve erros ao verificar algumas bases configuradas.</span>
                    <?php endif; ?>
                </div>
                <form method="post" action="<?= BASE_URL ?>system/run-migrations" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()); ?>">
                    <button type="submit" class="btn btn-sm btn-warning">
                        <i class="fa fa-refresh"></i> Executar migracoes
                    </button>
                </form>
            </div>
            <?php if (!empty($migrationSummary['companies'])): ?>
                <ul class="migration-company-list">
                    <?php foreach (array_slice($migrationSummary['companies'], 0, 5) as $migrationCompany): ?>
                        <li><?= htmlspecialchars((string) $migrationCompany['label']); ?>: <?= (int) $migrationCompany['count']; ?> pendente(s)</li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (!empty($migrationSummary['errors'])): ?>
                <div class="migration-output"><?php foreach (array_slice($migrationSummary['errors'], 0, 5) as $migrationError): ?><?= htmlspecialchars((string) ($migrationError['label'] ?? 'base')); ?>: <?= htmlspecialchars((string) ($migrationError['error'] ?? '')); ?><?="\n"?><?php endforeach; ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>





       

        <!-- Page content -->
        <div class="right_col" role="main">
