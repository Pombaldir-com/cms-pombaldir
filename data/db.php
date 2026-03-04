<?php
// Database connection helper using company settings stored in the session.

/**
 * Build app base URL from the current script path.
 *
 * @return string
 */
function getAppBaseUrl(): string {
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $baseDir = '';
    if ($scriptName !== '' && str_ends_with($scriptName, '.php')) {
        $baseDir = rtrim(dirname($scriptName), '/');
    } else {
        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
        if ($docRoot !== '' && strpos(__DIR__, $docRoot) === 0) {
            $baseDir = rtrim(substr(__DIR__, strlen($docRoot)), '/');
        }
    }
    return $baseDir === '' ? '/' : $baseDir . '/';
}

/**
 * Render a user-friendly database connection error page.
 *
 * @param PDOException $exception
 * @param array $cfg
 * @return void
 */
function renderDbConnectionErrorPage(PDOException $exception, array $cfg): void {
    http_response_code(500);
    $baseUrl = getAppBaseUrl();

    $host = (string) ($cfg['db_host'] ?? '');
    $port = (string) ($cfg['db_port'] ?? '');
    $dbName = (string) ($cfg['db_name'] ?? '');
    $errorMessage = $exception->getMessage();

    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>';
    echo '<html lang="pt"><head>';
    echo '<meta charset="utf-8">';
    echo '<meta http-equiv="X-UA-Compatible" content="IE=edge">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Erro de ligacao a base de dados</title>';
    echo '<link rel="stylesheet" href="' . htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') . 'vendors/bootstrap/dist/css/bootstrap.min.css">';
    echo '<link rel="stylesheet" href="' . htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') . 'vendors/font-awesome/css/font-awesome.min.css">';
    echo '<link rel="stylesheet" href="' . htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') . 'vendors/nprogress/nprogress.css">';
    echo '<link rel="stylesheet" href="' . htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') . 'assets/css/custom.css">';
    echo '<style>
        body.db-error {
          min-height: 100vh;
          margin: 0;
          background:
            radial-gradient(circle at 8% 12%, rgba(231, 76, 60, 0.16), transparent 35%),
            radial-gradient(circle at 88% 86%, rgba(243, 156, 18, 0.18), transparent 36%),
            linear-gradient(135deg, #f8fafc 0%, #eef3f7 100%);
        }
        .db-error-wrap {
          min-height: 100vh;
          display: flex;
          align-items: center;
          justify-content: center;
          padding: 24px;
        }
        .db-error-panel {
          width: 100%;
          max-width: 760px;
          border: 1px solid #dce4ec;
          border-radius: 14px;
          overflow: hidden;
          box-shadow: 0 24px 60px rgba(21, 31, 43, 0.16);
          margin: 0;
        }
        .db-error-panel .x_title {
          margin: 0;
          padding: 16px 22px;
          border-bottom: 1px solid #dce4ec;
          background: linear-gradient(90deg, #a93226 0%, #c0392b 100%);
        }
        .db-error-panel .x_title h2 {
          margin: 0;
          color: #fff;
          font-size: 20px;
          font-weight: 600;
        }
        .db-error-panel .x_content {
          padding: 22px;
        }
        .db-error-panel .error-code {
          display: inline-block;
          margin-bottom: 12px;
          font-weight: 600;
        }
        .db-error-panel .actions .btn {
          margin-right: 8px;
          margin-bottom: 8px;
        }
        .db-error-panel pre {
          margin-bottom: 0;
          white-space: pre-wrap;
          word-break: break-word;
          background: #1f2d3a;
          color: #f1f5f9;
          border: 0;
          border-radius: 8px;
          padding: 14px;
          font-size: 12px;
        }
      </style>';
    echo '</head><body class="db-error">';
    echo '<div class="db-error-wrap">';
    echo '<div class="x_panel db-error-panel">';
    echo '<div class="x_title"><h2><i class="fa fa-database"></i> Falha de ligacao a base de dados</h2><div class="clearfix"></div></div>';
    echo '<div class="x_content">';
    echo '<span class="badge bg-red error-code">Erro 500</span>';
    echo '<p>O sistema nao conseguiu estabelecer ligacao com a base de dados da empresa selecionada.</p>';
    echo '<div class="alert alert-warning">';
    echo '<strong>Verifique:</strong> servidor MySQL ativo, host/porta corretos e credenciais da empresa.';
    echo '</div>';
    echo '<div class="row">';
    echo '<div class="col-sm-4"><strong>Host</strong><br>' . htmlspecialchars($host === '' ? '-' : $host, ENT_QUOTES, 'UTF-8') . '</div>';
    echo '<div class="col-sm-4"><strong>Porta</strong><br>' . htmlspecialchars($port === '' ? '-' : $port, ENT_QUOTES, 'UTF-8') . '</div>';
    echo '<div class="col-sm-4"><strong>Base de dados</strong><br>' . htmlspecialchars($dbName === '' ? '-' : $dbName, ENT_QUOTES, 'UTF-8') . '</div>';
    echo '</div>';
    echo '<hr>';
    echo '<div class="actions">';
    echo '<a class="btn btn-primary" href="' . htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') . 'login"><i class="fa fa-sign-in"></i> Voltar ao login</a>';
    echo '<button class="btn btn-default" type="button" onclick="window.location.reload();"><i class="fa fa-refresh"></i> Tentar novamente</button>';
    echo '</div>';
    echo '<h4>Detalhe tecnico</h4>';
    echo '<pre>' . htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') . '</pre>';
    echo '</div></div></div>';
    echo '</body></html>';
}

/**
 * Return a shared PDO connection configured for the selected company.
 *
 * @throws RuntimeException If no company is selected in the session.
 * @return PDO
 */
function getPDO() {
    static $pdo;
    if (!$pdo) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['company'])) {
            throw new RuntimeException('Empresa não selecionada.');
        }
        $cfg = $_SESSION['company'];
        $port = isset($cfg['db_port']) && $cfg['db_port'] !== '' ? ';port=' . $cfg['db_port'] : '';
        $dsn = 'mysql:host=' . $cfg['db_host'] . $port . ';dbname=' . $cfg['db_name'] . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            renderDbConnectionErrorPage($e, $cfg);
            exit;
        }
    }
    return $pdo;
}
