<?php
// Common functions for the CMS.  This file provides helpers for session
// management, user authentication, and CRUD operations on the various
// entities used by the system (content types, fields, taxonomies, terms,
// and content entries).  It builds on the database connection helper
// defined in db.php.  All functions that touch the database return
// prepared statements or plain data arrays; they throw exceptions on
// failure so callers can decide how to handle errors.

require_once __DIR__ . '/data/db.php';

loadProjectEnvFile(__DIR__ . '/.env');

// Determine the base URL for the application (e.g., "/cms/") so links
// can be generated correctly regardless of the installation directory.
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
if (!defined('BASE_URL')) {
    define('BASE_URL', ($baseDir === '' ? '/' : $baseDir . '/'));
}

function loadProjectEnvFile(string $filePath): void {
    static $loaded = [];
    if (isset($loaded[$filePath])) {
        return;
    }
    $loaded[$filePath] = true;

    if (!is_file($filePath) || !is_readable($filePath)) {
        return;
    }

    $lines = @file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        $separatorPos = strpos($line, '=');
        if ($separatorPos === false) {
            continue;
        }

        $name = trim(substr($line, 0, $separatorPos));
        $value = trim(substr($line, $separatorPos + 1));
        if ($name === '') {
            continue;
        }

        if (
            (substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
            (substr($value, 0, 1) === "'" && substr($value, -1) === "'")
        ) {
            $value = substr($value, 1, -1);
        }

        if (getenv($name) === false) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

function setSessionFlash(string $key, array $payload): void {
    startSession();
    if (!isset($_SESSION['flash_messages']) || !is_array($_SESSION['flash_messages'])) {
        $_SESSION['flash_messages'] = [];
    }
    $_SESSION['flash_messages'][$key] = $payload;
}

function pullSessionFlash(string $key): ?array {
    startSession();
    if (!isset($_SESSION['flash_messages'][$key]) || !is_array($_SESSION['flash_messages'][$key])) {
        return null;
    }
    $payload = $_SESSION['flash_messages'][$key];
    unset($_SESSION['flash_messages'][$key]);
    return $payload;
}

function hasTable(string $table): bool {
    $pdo = getPDO();
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function hasColumn(string $table, string $column): bool {
    $pdo = getPDO();
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetch();
}

function logAuditAction(string $action, string $entity, ?int $entityId = null, array $meta = []): void {
    if (!hasTable('audit_logs')) {
        return;
    }
    $pdo = getPDO();
    $userId = $_SESSION['user_id'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $companySlug = getCompanySlug();
    if ($companySlug !== null) {
        $meta['company_slug'] = $companySlug;
    }
    $payload = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;
    try {
        $stmt = $pdo->prepare('INSERT INTO audit_logs (user_id, action, entity, entity_id, meta, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $action, $entity, $entityId, $payload, $ip, $userAgent]);
    } catch (Throwable $e) {
        // Avoid blocking primary flows if logging fails.
    }
}

/**
 * Retrieve a named setting from the database.
 *
 * The SMTP password is stored base64 encoded for a little obscurity.
 * When requesting the `smtp_pass` setting the value is automatically
 * decoded before being returned.
 *
 * @param string $name
 * @param string|null $default
 * @return string|null
 */
function getSetting(string $name, ?string $default = null): ?string {
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE name = ? LIMIT 1');
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    $value = $row['value'] ?? $default;
    if ($name === 'smtp_pass' && $value !== null) {
        $decoded = base64_decode($value, true);
        if ($decoded !== false) {
            $value = $decoded;
        }
    }
    return $value;
}

/**
 * Maximum size (bytes) a log file may reach before being rotated. Overridable via
 * the `log_max_bytes` setting; defaults to 5 MB.
 *
 * @return int
 */
function logMaxBytes(): int {
    static $configured = null;
    if ($configured === null) {
        try {
            $configured = (int) getSetting('log_max_bytes', '0');
        } catch (Throwable $e) {
            $configured = 0;
        }
    }
    return $configured > 0 ? $configured : 5242880;
}

/**
 * How many rotated generations to keep (`erp.log.1` ... `erp.log.N`). Overridable
 * via the `log_keep_files` setting; defaults to 3.
 *
 * @return int
 */
function logKeepFiles(): int {
    static $configured = null;
    if ($configured === null) {
        try {
            $configured = (int) getSetting('log_keep_files', '0');
        } catch (Throwable $e) {
            $configured = 0;
        }
    }
    return $configured > 0 ? $configured : 3;
}

/**
 * Rotate a log file once it exceeds the configured size.
 *
 * These logs are append-only and had no bound: `erp.log` grows on every ERP call
 * (dozens per classification page) and `contabilidade/debug_ai.txt` had reached
 * ~900 KB. Rotation shifts `x.log` to `x.log.1`, `x.log.1` to `x.log.2` and so on,
 * discarding the oldest generation, so disk use stays bounded at roughly
 * (keep + 1) * max size.
 *
 * Failures here must never break the caller: logging is a side effect, so every
 * filesystem operation is silenced and the function simply gives up.
 *
 * @param string $logFile Absolute path to the log file.
 * @return void
 */
function rotateLogFileIfNeeded(string $logFile): void {
    // No maximo uma rotacao por ficheiro por pedido. Sem isto, a stat cache do PHP
    // devolveria o tamanho antigo nas chamadas seguintes (ha' dezenas por pagina) e
    // a rotacao repetia-se, empurrando as geracoes e descartando logs validos.
    static $rotated = [];
    if (isset($rotated[$logFile])) {
        return;
    }

    if (!is_file($logFile)) {
        return;
    }

    $size = @filesize($logFile);
    if ($size === false || $size < logMaxBytes()) {
        return;
    }

    $rotated[$logFile] = true;

    $keep = logKeepFiles();

    // Descartar a geracao mais antiga e empurrar as restantes uma casa.
    @unlink($logFile . '.' . $keep);
    for ($i = $keep - 1; $i >= 1; $i--) {
        $from = $logFile . '.' . $i;
        if (is_file($from)) {
            @rename($from, $logFile . '.' . ($i + 1));
        }
    }

    @rename($logFile, $logFile . '.1');
}

/**
 * Align PHP's error output with the `debug_mode` setting (Definições > Geral).
 *
 * The hosting php.ini ships with `display_errors = On`. Besides leaking paths and
 * stack traces, that injects HTML into the body of the many JSON/AJAX endpoints
 * (contabilidade, assistente, SAF-T), which makes the response unparseable and
 * surfaces to the user as an opaque generic failure. Errors are therefore always
 * logged and only rendered on screen when debug mode is on.
 *
 * Reading the setting can fail before the database is reachable (install, first
 * migration, DB outage); that falls back to hiding errors, the safe default.
 *
 * @return void
 */
function configureErrorDisplay(): void {
    static $resolved = false;

    // Always report and log everything; only the on-screen display is gated.
    error_reporting(E_ALL);
    ini_set('log_errors', '1');

    if ($resolved) {
        return;
    }

    $debug = false;
    try {
        $debug = (int) getSetting('debug_mode', '0') === 1;
        $resolved = true;
    } catch (Throwable $e) {
        // No tenant selected yet, or the database is unreachable. Hide errors for
        // now and let a later call resolve the real setting.
        $debug = false;
    }

    ini_set('display_errors', $debug ? '1' : '0');
    ini_set('display_startup_errors', $debug ? '1' : '0');
}

/**
 * Save a named setting value in the database.
 *
 * The SMTP password is stored base64 encoded before being persisted.
 *
 * @param string $name
 * @param string $value
 * @return void
 */
function setSetting(string $name, string $value): void {
    if ($name === 'smtp_pass') {
        $value = base64_encode($value);
    }
    $pdo = getPDO();
    $stmt = $pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
    $stmt->execute([$name, $value]);
}

function normalizeAccountingRubricCodeValue($value): string {
    $string = trim((string) ($value ?? ''));
    if ($string === '') {
        return '';
    }

    if (class_exists('Normalizer')) {
        $nfc = \Normalizer::normalize($string, \Normalizer::FORM_C);
        if (is_string($nfc) && $nfc !== '') {
            $string = $nfc;
        }
    }

    $normalized = preg_replace('/\s+/u', ' ', $string);
    if (!is_string($normalized) || $normalized === '') {
        $normalized = $string;
    }

    if (function_exists('mb_strtoupper')) {
        return mb_strtoupper($normalized, 'UTF-8');
    }

    return strtoupper($normalized);
}

function parseAccountingRubricCodeList($value): array {
    $tokens = [];
    if (is_array($value)) {
        $tokens = $value;
    } else {
        $tokens = preg_split('/[\r\n,;]+/u', (string) $value) ?: [];
    }

    $result = [];
    $seen = [];
    foreach ($tokens as $token) {
        $normalized = normalizeAccountingRubricCodeValue($token);
        if ($normalized === '' || isset($seen[$normalized])) {
            continue;
        }
        $seen[$normalized] = true;
        $result[] = $normalized;
    }

    return $result;
}

function getAccountingFuelRubricCodes(): array {
    return parseAccountingRubricCodeList(getSetting('accounting_fuel_rubric_codes', ''));
}

function isAccountingFuelRubricCode($value, ?array $codes = null): bool {
    $normalized = normalizeAccountingRubricCodeValue($value);
    if ($normalized === '') {
        return false;
    }

    if ($codes === null) {
        $codes = getAccountingFuelRubricCodes();
    }

    return in_array($normalized, $codes, true);
}

/**
 * Retrieve the slug identifying the configured company.
 *
 * This slug is used to isolate uploaded files per company and to expose the
 * company through the public API.
 */
function getConfiguredCompanySlug(): string {
    return getSetting('company_slug', 'default');
}

/**
 * Fetch the API token used for authenticating external requests.
 */
function getApiToken(): string {
    return getSetting('api_token', '');
}

/**
 * Retrieve the list of active modules.
 *
 * The modules are stored as a JSON encoded array in the settings table.
 *
 * @return array<string>
 */
function getActiveModules(): array {
    $json = getSetting('active_modules', '[]');
    $modules = json_decode($json, true);
    return is_array($modules) ? $modules : [];
}

/**
 * Determine whether a specific module is active.
 *
 * @param string $module Module identifier
 * @return bool
 */
function isModuleActive(string $module): bool {
    return in_array($module, getActiveModules(), true);
}

/**
 * Start a session if it hasn't been started yet.  This helper uses
 * session cookies with the HttpOnly flag for security.  It does not
 * change existing session behaviour if a session is already active.
 */
function startSession() {
    if (session_status() === PHP_SESSION_NONE) {
        $params = session_get_cookie_params();

        $isHttps = false;
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
            $isHttps = true;
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $isHttps = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
        } elseif (!empty($_SERVER['SERVER_PORT'])) {
            $isHttps = (int)$_SERVER['SERVER_PORT'] === 443;
        }

        $cookieParams = [
            'lifetime' => $params['lifetime'],
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $isHttps,
            'httponly' => true,
        ];

        if (PHP_VERSION_ID >= 70300) {
            // PHP 7.3+ supports setting SameSite via array options
            $cookieParams['samesite'] = 'Lax';
            session_set_cookie_params($cookieParams);
        } else {
            // On older PHP versions append SameSite manually to the path
            $path = $cookieParams['path'];
            if (stripos($path, 'samesite=') === false) {
                $path .= '; samesite=Lax';
            }
            session_set_cookie_params(
                $cookieParams['lifetime'],
                $path,
                $cookieParams['domain'],
                $cookieParams['secure'],
                $cookieParams['httponly']
            );
        }

        session_start();
    }

    // Safe earliest point to resolve `debug_mode`: the session (and therefore the
    // tenant connection) is available, and the cookie parameters above are already
    // applied, so reading the setting cannot start the session prematurely.
    configureErrorDisplay();
}

/**
 * Store the active company configuration in the session.
 *
 * @param array $config
 * @return void
 */
function setCompanyContext(array $config): void {
    startSession();
    $_SESSION['company'] = $config;
}

/**
 * Retrieve the slug for the currently selected company.
 *
 * @return string|null
 */
function getCompanySlug(): ?string {
    startSession();
    return $_SESSION['company']['slug'] ?? null;
}

/**
 * Generate a CSRF token and store it in the session.
 *
 * Tokens are single-use. Pass `$renew = true` to force generation of a
 * new token even if one already exists (useful after a failed
 * validation attempt).
 *
 * @param bool $renew Whether to force creation of a fresh token
 * @return string
 */
function generateCsrfToken(bool $renew = false): string {
    startSession();
    if ($renew || empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate a CSRF token against the session.
 *
 * By default the stored token is cleared to enforce single-use.
 *
 * @param string $token Token supplied by the client
 * @param bool $consume Whether to consume the token on success
 * @return bool True if the token matches the session value
 */
function validateCsrfToken(string $token, bool $consume = true): bool {
    startSession();
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        return false;
    }
    if ($consume) {
        unset($_SESSION['csrf_token']);
    }
    return true;
}

/**
 * Check whether the current request is associated with a logged in user.
 *
 * @return bool
 */
function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

/**
 * Enforce authentication and company context.
 *
 * Redirects to the login page when the user is not authenticated or when
 * no company has been selected for the current session. After successful
 * login the user is returned to the originally requested page via the
 * `redirect` query parameter.
 */
/**
 * Validate and normalize a redirect target ensuring it stays within the
 * current application. Returns the normalized path (including optional query
 * string and fragment) or null when the provided value is not a safe
 * application-local redirect.
 *
 * @param string|null $redirect
 * @return string|null
 */
function normalizeRedirectTarget(?string $redirect): ?string {
    if ($redirect === null) {
        return null;
    }

    $redirect = trim($redirect);
    if ($redirect === '') {
        return null;
    }

    $decoded = rawurldecode($redirect);
    if ($decoded === '') {
        return null;
    }

    if (preg_match("/[\r\n]/", $decoded)) {
        return null;
    }

    if (strncmp($decoded, '//', 2) === 0) {
        return null;
    }

    $parts = parse_url($decoded);
    if ($parts === false) {
        return null;
    }

    if (isset($parts['scheme']) || isset($parts['host']) || isset($parts['port'])
        || isset($parts['user']) || isset($parts['pass'])) {
        return null;
    }

    $path = $parts['path'] ?? '';
    if ($path === '' || $path[0] !== '/') {
        return null;
    }

    $normalized = $path;
    if (isset($parts['query']) && $parts['query'] !== '') {
        $normalized .= '?' . $parts['query'];
    }
    if (isset($parts['fragment']) && $parts['fragment'] !== '') {
        $normalized .= '#' . $parts['fragment'];
    }

    return $normalized;
}

function requireLogin() {
    startSession();
    if (!isLoggedIn() || empty($_SESSION['company'])) {
        $requestedPath = normalizeRedirectTarget($_SERVER['REQUEST_URI'] ?? null);
        if ($requestedPath === null) {
            $requestedPath = BASE_URL . 'dashboard';
        }
        $redirect = urlencode($requestedPath);
        header('Location: ' . BASE_URL . 'login?redirect=' . $redirect);
        exit;
    }
}

function ensureTenantCompanyBySlug(string $slug): bool {
    startSession();
    $slug = trim($slug);
    if ($slug === '') {
        return false;
    }

    $currentSlug = trim((string) ($_SESSION['company']['slug'] ?? ''));
    if ($currentSlug !== '' && strcasecmp($currentSlug, $slug) === 0) {
        return true;
    }

    require_once __DIR__ . '/companies.php';
    $company = getCompanyBySlug($slug);
    if (!$company) {
        return false;
    }

    setCompanyContext($company);
    return true;
}

/**
 * Ensure the current user has a role equal or lower (more privileged) than
 * the provided level. Roles: 1=superadmin, 2=administrator, 3=user.
 * If the user does not meet the requirement, execution stops with a 403.
 *
 * @param int $maxRole
 * @return void
 */
function requireRole(int $maxRole): void {
    startSession();
    requireLogin();
    $role = $_SESSION['user_role'] ?? null;
    if ($role === null) {
        $u = currentUser();
        $role = $u['role'] ?? 3;
    }
    if ($role > $maxRole) {
        http_response_code(403);
        echo 'Acesso negado.';
        exit;
    }
}

/**
 * Authenticate a user with username and password.  On success the
 * user's id is stored in the session.  Returns true on success or
 * false on failure.  Passwords are stored hashed using PHP's
 * password_hash() function.  If you need to create an admin account
 * manually you can run `php -r "echo password_hash('yourpass', PASSWORD_DEFAULT);"`.
 *
 * @param string $username
 * @param string $password
 * @return bool
 */
function loginUser(string $username, string $password): bool {
    startSession();
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id, password, role FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password'])) {
        // Regenerate session ID to prevent fixation attacks
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['user_role'] = (int)$user['role'];
        logAuditAction('login', 'user', (int) $user['id'], ['username' => $username]);
        return true;
    }
    return false;
}

/**
 * Create a password reset token for the given user and persist its hash.
 * Any previous unused tokens for this user are invalidated first.
 *
 * @return string The plain-text token to embed in the reset link (never stored as-is).
 */
function createPasswordResetToken(int $userId): string {
    $pdo = getPDO();
    if (!hasTable('password_resets')) {
        throw new RuntimeException('Tabela password_resets nao existe. Corra as migracoes.');
    }
    $stmt = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
    $stmt->execute([$userId]);

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))');
    $stmt->execute([$userId, $tokenHash]);
    return $token;
}

/**
 * Validate a password reset token and return the associated user row, or null
 * if the token is unknown, expired or already used.
 */
function findUserByPasswordResetToken(string $token): ?array {
    if (!hasTable('password_resets') || trim($token) === '') {
        return null;
    }
    $pdo = getPDO();
    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare(
        'SELECT u.id, u.username, u.name, u.email
         FROM password_resets pr
         JOIN users u ON u.id = pr.user_id
         WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([$tokenHash]);
    $user = $stmt->fetch();
    return $user ?: null;
}

/**
 * Mark a password reset token as used and update the user's password.
 */
function consumePasswordResetToken(string $token, string $newPasswordHash): void {
    $pdo = getPDO();
    $tokenHash = hash('sha256', $token);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT user_id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1');
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();
        if (!$row) {
            $pdo->rollBack();
            throw new RuntimeException('Token de recuperacao invalido ou expirado.');
        }
        $userId = (int) $row['user_id'];
        $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->execute([$newPasswordHash, $userId]);
        $stmt = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
        $stmt->execute([$userId]);
        $pdo->commit();
        logAuditAction('password_reset', 'user', $userId, []);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Build the absolute base URL of the application (scheme + host + BASE_URL),
 * for use in contexts like emails where a relative path is not usable.
 */
function appAbsoluteBaseUrl(): string {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $isHttps ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host . BASE_URL;
}

/**
 * Send an email using the configured SMTP settings, falling back to PHP's
 * mail() when no SMTP host is configured. Mirrors the transport logic used
 * by the e-fatura module but kept self-contained here so pages outside
 * contabilidade/ (e.g. login/password recovery) don't need to load it.
 */
function sendSystemEmail(string $toEmail, string $subject, string $body, bool $isHtml = false): void {
    $smtpHost = trim((string) getSetting('smtp_host', ''));
    $fromEmail = trim((string) getSetting('system_email_from_email', ''));
    if ($fromEmail === '' || strpos($fromEmail, '@') === false) {
        $fromEmail = trim((string) getSetting('smtp_user', ''));
    }
    if ($fromEmail === '' || strpos($fromEmail, '@') === false) {
        $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?? 'localhost');
        $fromEmail = 'noreply@' . ($host !== '' ? $host : 'localhost');
    }
    $fromName = trim((string) getSetting('system_email_from_name', ''));
    if ($fromName === '') {
        $fromName = trim((string) getSetting('app_name', 'CMS'));
    }

    if ($smtpHost !== '') {
        sendSystemEmailViaSmtp($smtpHost, $toEmail, $subject, $body, $fromEmail, $fromName, $isHtml);
        return;
    }

    if (!function_exists('mail')) {
        throw new RuntimeException('Nao existe transporte de email configurado.');
    }
    $contentType = $isHtml ? 'text/html' : 'text/plain';
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers = "From: {$fromName} <{$fromEmail}>\r\nMIME-Version: 1.0\r\nContent-Type: {$contentType}; charset=UTF-8\r\nContent-Transfer-Encoding: base64";
    $encodedBody = chunk_split(base64_encode($body));
    if (!@mail($toEmail, $encodedSubject, $encodedBody, $headers, '-f ' . $fromEmail)) {
        throw new RuntimeException('Falha ao enviar email pelo transporte local.');
    }
}

/**
 * Como sendSystemEmail(), mas com um unico ficheiro anexo (multipart/mixed).
 * Usa o mesmo transporte SMTP/mail() configurado nas Definicoes.
 */
function sendSystemEmailWithAttachment(
    string $toEmail,
    string $subject,
    string $body,
    string $attachmentContent,
    string $attachmentFilename,
    string $attachmentMimeType = 'application/octet-stream'
): void {
    $smtpHost = trim((string) getSetting('smtp_host', ''));
    $fromEmail = trim((string) getSetting('system_email_from_email', ''));
    if ($fromEmail === '' || strpos($fromEmail, '@') === false) {
        $fromEmail = trim((string) getSetting('smtp_user', ''));
    }
    if ($fromEmail === '' || strpos($fromEmail, '@') === false) {
        $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?? 'localhost');
        $fromEmail = 'noreply@' . ($host !== '' ? $host : 'localhost');
    }
    $fromName = trim((string) getSetting('system_email_from_name', ''));
    if ($fromName === '') {
        $fromName = trim((string) getSetting('app_name', 'CMS'));
    }

    $boundary = 'AICRM-' . bin2hex(random_bytes(16));
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $safeFilename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $attachmentFilename) ?: 'anexo';

    $messageBody = "--{$boundary}\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($body))
        . "--{$boundary}\r\n"
        . "Content-Type: {$attachmentMimeType}; name=\"{$safeFilename}\"\r\n"
        . "Content-Transfer-Encoding: base64\r\n"
        . "Content-Disposition: attachment; filename=\"{$safeFilename}\"\r\n\r\n"
        . chunk_split(base64_encode($attachmentContent))
        . "--{$boundary}--";

    if ($smtpHost !== '') {
        sendSystemMimeMessageViaSmtp($smtpHost, $toEmail, $encodedSubject, $messageBody, $boundary, $fromEmail, $fromName);
        return;
    }

    if (!function_exists('mail')) {
        throw new RuntimeException('Nao existe transporte de email configurado.');
    }
    $headers = "From: {$fromName} <{$fromEmail}>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"{$boundary}\"";
    if (!@mail($toEmail, $encodedSubject, $messageBody, $headers, '-f ' . $fromEmail)) {
        throw new RuntimeException('Falha ao enviar email pelo transporte local.');
    }
}

/**
 * Liga ao SMTP configurado e envia uma mensagem MIME ja construida (usado
 * por sendSystemEmailWithAttachment()). Repete a plumbing de baixo nivel de
 * sendSystemEmailViaSmtp() em vez de a reaproveitar porque aqui o
 * Content-Type/boundary ja vem definido pelo chamador.
 */
function sendSystemMimeMessageViaSmtp(string $host, string $toEmail, string $encodedSubject, string $mimeBody, string $boundary, string $fromEmail, string $fromName): void {
    $port = (int) getSetting('smtp_port', '0');
    $encryption = strtolower(trim((string) getSetting('smtp_encryption', '')));
    $username = trim((string) getSetting('smtp_user', ''));
    $password = (string) getSetting('smtp_pass', '');
    if ($port <= 0) {
        $port = $encryption === 'ssl' ? 465 : 587;
    }

    $clientHost = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost.localdomain')) ?? 'localhost.localdomain');
    $remoteHost = $encryption === 'ssl' ? 'ssl://' . $host : $host;
    $socket = @stream_socket_client($remoteHost . ':' . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        throw new RuntimeException('Falha ao ligar ao SMTP: ' . $errstr);
    }
    stream_set_timeout($socket, 20);

    $expect = function ($socket, array $codes, string $context) {
        $response = '';
        while (!feof($socket)) {
            $line = fgets($socket, 515);
            if ($line === false) {
                break;
            }
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        if ($response === '') {
            throw new RuntimeException('Resposta vazia do servidor SMTP em ' . $context . '.');
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('SMTP falhou em ' . $context . ': ' . trim($response));
        }
        return $response;
    };
    $command = function ($socket, string $cmd, array $codes, string $context) use ($expect) {
        fwrite($socket, $cmd . "\r\n");
        return $expect($socket, $codes, $context);
    };

    $expect($socket, [220], 'ligacao inicial');
    $command($socket, 'EHLO ' . $clientHost, [250], 'EHLO');

    if ($encryption === 'tls') {
        $command($socket, 'STARTTLS', [220], 'STARTTLS');
        if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
            fclose($socket);
            throw new RuntimeException('Nao foi possivel ativar TLS no SMTP.');
        }
        $command($socket, 'EHLO ' . $clientHost, [250], 'EHLO pos-TLS');
    }

    if ($username !== '') {
        $command($socket, 'AUTH LOGIN', [334], 'AUTH LOGIN');
        $command($socket, base64_encode($username), [334], 'SMTP utilizador');
        $command($socket, base64_encode($password), [235], 'SMTP password');
    }

    $envelopeFrom = $username !== '' && strpos($username, '@') !== false ? $username : $fromEmail;
    $command($socket, 'MAIL FROM:<' . $envelopeFrom . '>', [250], 'MAIL FROM');
    $command($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251], 'RCPT TO');
    $command($socket, 'DATA', [354], 'DATA');

    $headers = implode("\r\n", [
        'To: ' . $toEmail,
        'From: ' . $fromName . ' <' . $fromEmail . '>',
        'Subject: ' . $encodedSubject,
        'Date: ' . date(DATE_RFC2822),
        'Message-ID: <' . uniqid('dr_', true) . '@' . $clientHost . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
        'X-Mailer: AICRM',
    ]);
    $rawMessage = $headers . "\r\n\r\n" . $mimeBody;
    $rawMessage = preg_replace("/(?m)^\\./", '..', $rawMessage) ?? $rawMessage;
    fwrite($socket, $rawMessage . "\r\n.\r\n");
    $expect($socket, [250], 'corpo da mensagem');
    @fwrite($socket, "QUIT\r\n");
    fclose($socket);
}

function sendSystemEmailViaSmtp(string $host, string $toEmail, string $subject, string $body, string $fromEmail, string $fromName, bool $isHtml = false): void {
    $port = (int) getSetting('smtp_port', '0');
    $encryption = strtolower(trim((string) getSetting('smtp_encryption', '')));
    $username = trim((string) getSetting('smtp_user', ''));
    $password = (string) getSetting('smtp_pass', '');
    if ($port <= 0) {
        $port = $encryption === 'ssl' ? 465 : 587;
    }

    $clientHost = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost.localdomain')) ?? 'localhost.localdomain');
    $remoteHost = $encryption === 'ssl' ? 'ssl://' . $host : $host;
    $socket = @stream_socket_client($remoteHost . ':' . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        throw new RuntimeException('Falha ao ligar ao SMTP: ' . $errstr);
    }
    stream_set_timeout($socket, 20);

    $expect = function ($socket, array $codes, string $context) {
        $response = '';
        while (!feof($socket)) {
            $line = fgets($socket, 515);
            if ($line === false) {
                break;
            }
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        if ($response === '') {
            throw new RuntimeException('Resposta vazia do servidor SMTP em ' . $context . '.');
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('SMTP falhou em ' . $context . ': ' . trim($response));
        }
        return $response;
    };
    $command = function ($socket, string $cmd, array $codes, string $context) use ($expect) {
        fwrite($socket, $cmd . "\r\n");
        return $expect($socket, $codes, $context);
    };

    $expect($socket, [220], 'ligacao inicial');
    $command($socket, 'EHLO ' . $clientHost, [250], 'EHLO');

    if ($encryption === 'tls') {
        $command($socket, 'STARTTLS', [220], 'STARTTLS');
        if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
            fclose($socket);
            throw new RuntimeException('Nao foi possivel ativar TLS no SMTP.');
        }
        $command($socket, 'EHLO ' . $clientHost, [250], 'EHLO pos-TLS');
    }

    if ($username !== '') {
        $command($socket, 'AUTH LOGIN', [334], 'AUTH LOGIN');
        $command($socket, base64_encode($username), [334], 'SMTP utilizador');
        $command($socket, base64_encode($password), [235], 'SMTP password');
    }

    $envelopeFrom = $username !== '' && strpos($username, '@') !== false ? $username : $fromEmail;
    $command($socket, 'MAIL FROM:<' . $envelopeFrom . '>', [250], 'MAIL FROM');
    $command($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251], 'RCPT TO');
    $command($socket, 'DATA', [354], 'DATA');

    $contentType = $isHtml ? 'text/html' : 'text/plain';
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers = implode("\r\n", [
        'To: ' . $toEmail,
        'From: ' . $fromName . ' <' . $fromEmail . '>',
        'Subject: ' . $encodedSubject,
        'Date: ' . date(DATE_RFC2822),
        'Message-ID: <' . uniqid('reset_', true) . '@' . $clientHost . '>',
        'MIME-Version: 1.0',
        'Content-Type: ' . $contentType . '; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'X-Mailer: AICRM',
    ]);
    $encodedBody = chunk_split(base64_encode($body));
    $rawMessage = $headers . "\r\n\r\n" . $encodedBody;
    $rawMessage = preg_replace("/(?m)^\\./", '..', $rawMessage) ?? $rawMessage;
    fwrite($socket, $rawMessage . "\r\n.\r\n");
    $expect($socket, [250], 'corpo da mensagem');
    @fwrite($socket, "QUIT\r\n");
    fclose($socket);
}

function hasClientUsersTable(): bool {
    return hasTable('client_users');
}

function hasAccountingEntityAdminUsersTable(): bool {
    return hasTable('accounting_entity_admin_users');
}

function hasAccountingEntityAdminTaskPermissionsTable(): bool {
    return hasTable('accounting_entity_admin_task_permissions');
}

function hasAccountingEntityExtranetSettingsTable(): bool {
    return hasTable('accounting_entity_extranet_settings');
}

function clientLogin(string $username, string $password, string $tenantSlug): bool {
    startSession();
    if (!hasClientUsersTable()) {
        return false;
    }

    $tenantSlug = trim($tenantSlug);
    if ($tenantSlug === '') {
        return false;
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'SELECT id, accounting_entity_id, username, password, is_active
         FROM client_users
         WHERE tenant_slug = ? AND username = ?
         LIMIT 1'
    );
    $stmt->execute([$tenantSlug, $username]);
    $client = $stmt->fetch();
    if (!$client) {
        return false;
    }

    if ((int) ($client['is_active'] ?? 0) !== 1) {
        return false;
    }

    if (!password_verify($password, (string) ($client['password'] ?? ''))) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['client_user_id'] = (int) $client['id'];
    $_SESSION['client_user_tenant_slug'] = $tenantSlug;
    $_SESSION['client_accounting_entity_id'] = (int) $client['accounting_entity_id'];
    logAuditAction('login', 'client_user', (int) $client['id'], [
        'tenant_slug' => $tenantSlug,
        'username' => $username,
    ]);
    return true;
}

function currentClientUser(): ?array {
    startSession();
    $clientId = (int) ($_SESSION['client_user_id'] ?? 0);
    if ($clientId <= 0) {
        return null;
    }
    if (!hasClientUsersTable()) {
        return null;
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'SELECT cu.id, cu.accounting_entity_id, cu.username, cu.name, cu.email, cu.is_active,
                cu.tenant_slug, ae.nif AS entity_nif, ae.name AS entity_name
         FROM client_users cu
         INNER JOIN accounting_entities ae ON ae.id = cu.accounting_entity_id
         WHERE cu.id = ?
         LIMIT 1'
    );
    $stmt->execute([$clientId]);
    $row = $stmt->fetch() ?: null;
    if (!$row) {
        return null;
    }
    if ((int) ($row['is_active'] ?? 0) !== 1) {
        return null;
    }
    return $row;
}

function clientLogout(): void {
    startSession();
    $clientId = (int) ($_SESSION['client_user_id'] ?? 0);
    if ($clientId > 0) {
        logAuditAction('logout', 'client_user', $clientId, [
            'tenant_slug' => (string) ($_SESSION['client_user_tenant_slug'] ?? ''),
        ]);
    }
    unset(
        $_SESSION['client_user_id'],
        $_SESSION['client_user_tenant_slug'],
        $_SESSION['client_accounting_entity_id'],
        $_SESSION['client_impersonator_user_id'],
        $_SESSION['client_impersonator_return_url']
    );
}

/**
 * Start impersonating a client (extranet) user without requiring credentials.
 * Reserved for back-office staff that can manage the extranet; access control
 * must be enforced by the caller. Returns the impersonated client row or null.
 */
function startClientImpersonation(int $clientUserId, int $impersonatorUserId): ?array {
    startSession();
    if (!hasClientUsersTable()) {
        return null;
    }

    $client = getClientUserById($clientUserId);
    if (!$client || (int) ($client['is_active'] ?? 0) !== 1) {
        return null;
    }

    $tenantSlug = trim((string) ($client['tenant_slug'] ?? ''));
    if ($tenantSlug === '') {
        return null;
    }

    $_SESSION['client_user_id'] = (int) $client['id'];
    $_SESSION['client_user_tenant_slug'] = $tenantSlug;
    $_SESSION['client_accounting_entity_id'] = (int) ($client['accounting_entity_id'] ?? 0);
    $_SESSION['client_impersonator_user_id'] = $impersonatorUserId;

    logAuditAction('impersonate', 'client_user', (int) $client['id'], [
        'tenant_slug' => $tenantSlug,
        'username' => (string) ($client['username'] ?? ''),
        'impersonator_user_id' => $impersonatorUserId,
    ]);

    return $client;
}

function isClientImpersonation(): bool {
    startSession();
    return (int) ($_SESSION['client_impersonator_user_id'] ?? 0) > 0;
}

function stopClientImpersonation(): void {
    startSession();
    $clientId = (int) ($_SESSION['client_user_id'] ?? 0);
    $impersonatorUserId = (int) ($_SESSION['client_impersonator_user_id'] ?? 0);
    if ($clientId > 0 && $impersonatorUserId > 0) {
        logAuditAction('stop-impersonate', 'client_user', $clientId, [
            'tenant_slug' => (string) ($_SESSION['client_user_tenant_slug'] ?? ''),
            'impersonator_user_id' => $impersonatorUserId,
        ]);
    }
    unset(
        $_SESSION['client_user_id'],
        $_SESSION['client_user_tenant_slug'],
        $_SESSION['client_accounting_entity_id'],
        $_SESSION['client_impersonator_user_id'],
        $_SESSION['client_impersonator_return_url']
    );
}

function requireClientLogin(string $tenantSlug): void {
    startSession();
    $tenantSlug = trim($tenantSlug);

    if ($tenantSlug === '' || !ensureTenantCompanyBySlug($tenantSlug)) {
        http_response_code(404);
        exit('Tenant invalida.');
    }

    $isLoggedIn = !empty($_SESSION['client_user_id'])
        && strcasecmp((string) ($_SESSION['client_user_tenant_slug'] ?? ''), $tenantSlug) === 0;
    if (!$isLoggedIn) {
        $target = BASE_URL . 't/' . rawurlencode($tenantSlug) . '/cliente/login';
        header('Location: ' . $target);
        exit;
    }

    $client = currentClientUser();
    if (!$client) {
        clientLogout();
        $target = BASE_URL . 't/' . rawurlencode($tenantSlug) . '/cliente/login';
        header('Location: ' . $target);
        exit;
    }

    if (strcasecmp((string) ($client['tenant_slug'] ?? ''), $tenantSlug) !== 0) {
        clientLogout();
        http_response_code(403);
        exit('Acesso negado.');
    }
}

/**
 * Validate password strength.
 * A strong password has at least 8 characters and includes
 * uppercase and lowercase letters plus at least one number.
 *
 * @param string $password
 * @return bool
 */
function isStrongPassword(string $password): bool {
    return strlen($password) >= 8
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[a-z]/', $password)
        && preg_match('/\d/', $password);
}
/**
 * Clear company-related context stored in the session.
 *
 * Removes the generic 'company' entry and any other session variables
 * prefixed with 'company_'.
 *
 * @return void
 */
function clearCompanyContext(): void {
    startSession();
    unset($_SESSION['company']);
    foreach (array_keys($_SESSION) as $key) {
        if (strpos($key, 'company_') === 0) {
            unset($_SESSION[$key]);
        }
    }
}


/**
 * Log out the current user by destroying the session and clearing
 * cookies.  After calling this function you should redirect the
 * browser to a public page.
 */
function logoutUser() {
    startSession();
    clearCompanyContext();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/**
 * Fetch the current logged in user's record or null if not logged in.
 *
 * @return array|null
 */
function currentUser(): ?array {
    startSession();
    if (!isLoggedIn()) {
        return null;
    }
    $pdo = getPDO();
    if (hasUserAiPreferenceColumns()) {
        $stmt = $pdo->prepare('SELECT id, username, name, email, phone, photo, role, ai_chat_floating, ai_read_only FROM users WHERE id = ?');
    } else {
        $stmt = $pdo->prepare('SELECT id, username, name, email, phone, photo, role, 0 AS ai_chat_floating, 1 AS ai_read_only FROM users WHERE id = ?');
    }
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    if ($user) {
        $_SESSION['user_role'] = (int)$user['role'];
    }
    return $user;
}

function getMigrationFilesList(): array {
    $dir = __DIR__ . '/migrations';
    if (!is_dir($dir)) {
        return [];
    }
    $files = glob($dir . '/*.sql');
    if (!$files) {
        return [];
    }
    sort($files, SORT_STRING);
    return array_map('basename', $files);
}

function buildMigrationDsn(array $cfg): string {
    $host = $cfg['db_host'] ?? 'localhost';
    $port = isset($cfg['db_port']) && $cfg['db_port'] !== '' ? ';port=' . $cfg['db_port'] : '';
    $db = $cfg['db_name'] ?? '';
    $socket = isset($cfg['db_socket']) && $cfg['db_socket'] !== '' ? ';unix_socket=' . $cfg['db_socket'] : '';
    return "mysql:host={$host}{$port}{$socket};dbname={$db};charset=utf8mb4";
}

function splitMigrationSqlStatements(string $sql): array {
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    $lines = explode("\n", $sql);
    $clean = [];
    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        if (strpos($trimmed, '--') === 0 || strpos($trimmed, '#') === 0) {
            continue;
        }
        $clean[] = $line;
    }
    $sql = implode("\n", $clean);
    $parts = array_map('trim', explode(';', $sql));
    $statements = [];
    foreach ($parts as $part) {
        if ($part !== '') {
            $statements[] = $part;
        }
    }
    return $statements;
}

function shouldIgnoreMigrationStatementError(Throwable $e, string $statement = ''): bool {
    if (!$e instanceof PDOException) {
        return false;
    }
    $code = $e->errorInfo[1] ?? null;
    if (in_array($code, [1050, 1060, 1061, 1091], true)) {
        return true;
    }
    if ($code === 1146) {
        $normalized = ltrim($statement);
        if (preg_match('/^ALTER\s+TABLE\s+/i', $normalized)) {
            return true;
        }
    }
    return false;
}

function ensureMigrationsTableExists(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL UNIQUE,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    );
}

function markMigrationAsApplied(PDO $pdo, string $filename): void {
    $stmt = $pdo->prepare('INSERT INTO migrations (filename) VALUES (?) ON DUPLICATE KEY UPDATE filename = VALUES(filename)');
    $stmt->execute([$filename]);
}

function repairCurrentCompanySchemaFromMigrations(): array {
    startSession();
    if (empty($_SESSION['company']) || !is_array($_SESSION['company'])) {
        return ['ok' => true, 'output' => []];
    }

    $dir = __DIR__ . '/migrations';
    $files = glob($dir . '/*.sql');
    if (!$files) {
        return ['ok' => true, 'output' => []];
    }
    sort($files, SORT_STRING);

    $cfg = $_SESSION['company'];
    $label = $cfg['slug'] ?? ($cfg['db_name'] ?? 'current');
    $output = [];

    try {
        $pdo = new PDO(buildMigrationDsn($cfg), $cfg['db_user'], $cfg['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        ensureMigrationsTableExists($pdo);

        foreach ($files as $file) {
            $filename = basename($file);
            $sql = file_get_contents($file);
            if ($sql === false) {
                continue;
            }
            $statements = splitMigrationSqlStatements($sql);
            if (!$statements) {
                markMigrationAsApplied($pdo, $filename);
                continue;
            }

            $startedTransaction = $pdo->beginTransaction();
            try {
                foreach ($statements as $statement) {
                    try {
                        $pdo->exec($statement);
                    } catch (Throwable $e) {
                        if (shouldIgnoreMigrationStatementError($e, $statement)) {
                            continue;
                        }
                        throw $e;
                    }
                }
                markMigrationAsApplied($pdo, $filename);
                if ($startedTransaction && $pdo->inTransaction()) {
                    $pdo->commit();
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return [
                    'ok' => false,
                    'output' => ["[{$label}] Reparacao falhou em {$filename}: " . $e->getMessage()],
                ];
            }
        }
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'output' => ["[{$label}] Reparacao da base ativa falhou: " . $e->getMessage()],
        ];
    }

    $output[] = "[{$label}] Base ativa validada/reparada";
    return ['ok' => true, 'output' => $output];
}

function getPendingMigrationsSummary(bool $forceRefresh = false): array {
    startSession();
    $cacheKey = 'pending_migrations_summary';
    $cacheTtl = 60;
    $files = getMigrationFilesList();
    $filesSignature = sha1(implode('|', $files));
    if (
        !$forceRefresh
        && isset($_SESSION[$cacheKey]['generated_at'], $_SESSION[$cacheKey]['data'])
        && (string) ($_SESSION[$cacheKey]['files_signature'] ?? '') === $filesSignature
        && (time() - (int) $_SESSION[$cacheKey]['generated_at']) < $cacheTtl
        && is_array($_SESSION[$cacheKey]['data'])
    ) {
        return $_SESSION[$cacheKey]['data'];
    }

    $summary = [
        'has_pending' => false,
        'pending_total' => 0,
        'companies' => [],
        'errors' => [],
    ];
    if (!$files) {
        $_SESSION[$cacheKey] = [
            'generated_at' => time(),
            'files_signature' => $filesSignature,
            'data' => $summary,
        ];
        return $summary;
    }

    $companiesFile = __DIR__ . '/data/companies.php';
    $companies = is_file($companiesFile) ? (require $companiesFile) : [];
    if (!is_array($companies)) {
        $companies = [];
    }

    $migrationTargets = [];
    $addMigrationTarget = static function (array $cfg, string $fallbackLabel = '') use (&$migrationTargets): void {
        $dbName = trim((string) ($cfg['db_name'] ?? ''));
        if ($dbName === '') {
            return;
        }

        $identity = implode('|', [
            trim((string) ($cfg['db_host'] ?? 'localhost')),
            trim((string) ($cfg['db_port'] ?? '')),
            trim((string) ($cfg['db_socket'] ?? '')),
            $dbName,
        ]);

        if (isset($migrationTargets[$identity])) {
            return;
        }

        if (!isset($cfg['slug']) || trim((string) $cfg['slug']) === '') {
            $cfg['slug'] = $fallbackLabel !== '' ? $fallbackLabel : $dbName;
        }

        $migrationTargets[$identity] = $cfg;
    };

    foreach ($companies as $nif => $cfg) {
        if (!is_array($cfg)) {
            continue;
        }
        $addMigrationTarget($cfg, (string) $nif);
    }

    if (!empty($_SESSION['company']) && is_array($_SESSION['company'])) {
        $sessionCompany = $_SESSION['company'];
        $sessionLabel = trim((string) ($sessionCompany['slug'] ?? ($sessionCompany['db_name'] ?? '')));
        $addMigrationTarget($sessionCompany, $sessionLabel);
    }

    foreach ($migrationTargets as $cfg) {
        if (!is_array($cfg) || !empty($cfg['skip_migrations'])) {
            continue;
        }
        $label = trim((string) ($cfg['slug'] ?? ($cfg['db_name'] ?? 'base')));
        try {
            $pdo = new PDO(buildMigrationDsn($cfg), $cfg['db_user'], $cfg['db_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS migrations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    filename VARCHAR(255) NOT NULL UNIQUE,
                    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )"
            );
            $rows = $pdo->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
            $applied = array_fill_keys(array_map('strval', $rows ?: []), true);
            $pending = array_values(array_filter($files, static function ($file) use ($applied): bool {
                return !isset($applied[$file]);
            }));
            if ($pending) {
                $summary['has_pending'] = true;
                $summary['pending_total'] += count($pending);
                $summary['companies'][] = [
                    'label' => $label,
                    'pending' => $pending,
                    'count' => count($pending),
                ];
            }
        } catch (Throwable $e) {
            $summary['errors'][] = [
                'label' => $label,
                'error' => $e->getMessage(),
            ];
        }
    }

    $_SESSION[$cacheKey] = [
        'generated_at' => time(),
        'files_signature' => $filesSignature,
        'data' => $summary,
    ];
    return $summary;
}

function resolvePhpCliBinary(): ?string {
    $candidates = [];

    if (defined('PHP_BINARY') && PHP_BINARY !== '') {
        $candidates[] = PHP_BINARY;
    }

    $candidates = array_merge($candidates, [
        'php',
        '/usr/bin/php',
        '/usr/local/bin/php',
    ]);

    $seen = [];
    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate === '' || isset($seen[$candidate])) {
            continue;
        }
        $seen[$candidate] = true;

        $probeCommand = escapeshellarg($candidate) . ' -r ' . escapeshellarg('echo PHP_SAPI;') . ' 2>/dev/null';
        $probeOutput = [];
        $probeExitCode = 1;
        exec($probeCommand, $probeOutput, $probeExitCode);
        if ($probeExitCode === 0 && trim(implode("\n", $probeOutput)) === 'cli') {
            return $candidate;
        }
    }

    return null;
}

function runProjectMigrationsFromUi(): array {
    $phpBinary = resolvePhpCliBinary();
    $script = __DIR__ . '/scripts/migrate.php';
    if (!is_file($script)) {
        return ['ok' => false, 'output' => ['Script de migracoes nao encontrado.']];
    }
    if ($phpBinary === null) {
        return ['ok' => false, 'output' => ['Nao foi encontrado um binario PHP CLI valido no servidor.']];
    }
    $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($script) . ' 2>&1';
    $output = [];
    $exitCode = 1;
    exec($cmd, $output, $exitCode);
    $repairResult = repairCurrentCompanySchemaFromMigrations();
    if (!empty($repairResult['output']) && is_array($repairResult['output'])) {
        $output = array_merge($output, $repairResult['output']);
    }
    if (!$repairResult['ok']) {
        $exitCode = 1;
    }
    unset($_SESSION['pending_migrations_summary']);
    return [
        'ok' => $exitCode === 0,
        'exit_code' => $exitCode,
        'output' => $output,
    ];
}

/**
 * Update the basic profile information for a user.
 *
 * @param int $id
 * @param string|null $name
 * @param string|null $email
 * @param string|null $phone
 * @param string|null $passwordHash
 * @param string|null $photoPath
 * @return void
 */
function updateUserProfile(int $id, ?string $name, ?string $email, ?string $phone, ?string $passwordHash = null, ?string $photoPath = null): void {
    $pdo = getPDO();
    $sql = 'UPDATE users SET name = ?, email = ?, phone = ?';
    $params = [$name, $email, $phone];
    if ($passwordHash !== null) {
        $sql .= ', password = ?';
        $params[] = $passwordHash;
    }
    if ($photoPath !== null) {
        $sql .= ', photo = ?';
        $params[] = $photoPath;
    }
    $sql .= ' WHERE id = ?';
    $params[] = $id;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    logAuditAction('update', 'user_profile', $id);
}

/**
 * Retrieve all users ordered by id.
 *
 * @return array
 */
function getUsers(): array {
    $pdo = getPDO();
    $hasDept = hasTable('user_taxonomy_terms') && hasTable('taxonomy_terms') && hasTable('taxonomies');
    if ($hasDept) {
        $stmt = $pdo->query("SELECT u.id, u.username, u.name, u.email, u.phone, u.role, GROUP_CONCAT(DISTINCT CASE WHEN tx.id IS NULL THEN NULL ELSE tt.name END ORDER BY tt.name SEPARATOR ', ') AS department_names FROM users u LEFT JOIN user_taxonomy_terms utt ON utt.user_id = u.id LEFT JOIN taxonomy_terms tt ON tt.id = utt.term_id LEFT JOIN taxonomies tx ON tx.id = tt.taxonomy_id AND LOWER(tx.name) = 'departamentos' GROUP BY u.id ORDER BY u.id ASC");
    } else {
        $stmt = $pdo->query('SELECT id, username, name, email, phone, role, NULL AS department_names FROM users ORDER BY id ASC');
    }
    return $stmt->fetchAll();
}

/**
 * Fetch a single user by id.
 *
 * @param int $id
 * @return array|null
 */
function getUserById(int $id): ?array {
    $pdo = getPDO();
    $hasDept = hasTable('user_taxonomy_terms') && hasTable('taxonomy_terms') && hasTable('taxonomies');
    $hasAiPrefs = hasUserAiPreferenceColumns();
    if ($hasDept) {
        if ($hasAiPrefs) {
            $stmt = $pdo->prepare("SELECT u.id, u.username, u.name, u.email, u.phone, u.photo, u.role, u.ai_chat_floating, u.ai_read_only FROM users u WHERE u.id = ?");
        } else {
            $stmt = $pdo->prepare("SELECT u.id, u.username, u.name, u.email, u.phone, u.photo, u.role, 0 AS ai_chat_floating, 1 AS ai_read_only FROM users u WHERE u.id = ?");
        }
    } else {
        if ($hasAiPrefs) {
            $stmt = $pdo->prepare('SELECT id, username, name, email, phone, photo, role, ai_chat_floating, ai_read_only FROM users WHERE id = ?');
        } else {
            $stmt = $pdo->prepare('SELECT id, username, name, email, phone, photo, role, 0 AS ai_chat_floating, 1 AS ai_read_only FROM users WHERE id = ?');
        }
    }
    $stmt->execute([$id]);
    $user = $stmt->fetch() ?: null;
    if ($user && $hasDept) {
        $user['department_term_ids'] = getUserDepartmentTermIds($id);
    }
    return $user;
}

function getClientUsersByAccountingEntityId(int $accountingEntityId): array {
    if ($accountingEntityId <= 0 || !hasClientUsersTable()) {
        return [];
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'SELECT id, accounting_entity_id, tenant_slug, username, name, email, is_active, created_at, updated_at
         FROM client_users
         WHERE accounting_entity_id = ?
         ORDER BY id ASC'
    );
    $stmt->execute([$accountingEntityId]);
    return $stmt->fetchAll() ?: [];
}

function getClientUserById(int $id): ?array {
    if ($id <= 0 || !hasClientUsersTable()) {
        return null;
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'SELECT id, accounting_entity_id, tenant_slug, username, name, email, is_active
         FROM client_users
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function createClientUser(int $accountingEntityId, string $tenantSlug, string $username, string $passwordHash, ?string $name, ?string $email, int $isActive = 1): int {
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'INSERT INTO client_users (accounting_entity_id, tenant_slug, username, password, name, email, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $accountingEntityId,
        $tenantSlug,
        $username,
        $passwordHash,
        $name,
        $email,
        $isActive ? 1 : 0,
    ]);
    $id = (int) $pdo->lastInsertId();
    logAuditAction('create', 'client_user', $id, [
        'accounting_entity_id' => $accountingEntityId,
        'tenant_slug' => $tenantSlug,
        'username' => $username,
    ]);
    return $id;
}

function updateClientUser(int $id, ?string $passwordHash, ?string $name, ?string $email, int $isActive): void {
    $pdo = getPDO();
    $sql = 'UPDATE client_users SET name = ?, email = ?, is_active = ?';
    $params = [$name, $email, $isActive ? 1 : 0];
    if ($passwordHash !== null) {
        $sql .= ', password = ?';
        $params[] = $passwordHash;
    }
    $sql .= ' WHERE id = ?';
    $params[] = $id;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    logAuditAction('update', 'client_user', $id, ['password_changed' => $passwordHash !== null ? 1 : 0]);
}

function deleteClientUser(int $id): void {
    if ($id <= 0 || !hasClientUsersTable()) {
        return;
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare('DELETE FROM client_users WHERE id = ?');
    $stmt->execute([$id]);

    if ($stmt->rowCount() > 0) {
        logAuditAction('delete', 'client_user', $id);
    }
}

function getAccountingEntityAdminPermissionOptions(): array {
    return [
        'can_manage_extranet' => 'Extranet',
        'can_manage_documents' => 'Documentos',
        'can_manage_ai' => 'IA',
        'can_manage_users' => 'Utilizadores',
    ];
}

function getAccountingEntityAdminUsers(int $accountingEntityId): array {
    if ($accountingEntityId <= 0 || !hasAccountingEntityAdminUsersTable()) {
        return [];
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'SELECT aeu.id, aeu.accounting_entity_id, aeu.user_id, aeu.is_active, aeu.can_manage_extranet, aeu.can_manage_documents, aeu.can_manage_ai, aeu.can_manage_users, aeu.created_at, aeu.updated_at, u.username, u.name, u.email, u.role
         FROM accounting_entity_admin_users aeu
         LEFT JOIN users u ON u.id = aeu.user_id
         WHERE aeu.accounting_entity_id = ?
         ORDER BY COALESCE(NULLIF(TRIM(u.name), \'\'), u.username) ASC, aeu.id ASC'
    );
    $stmt->execute([$accountingEntityId]);
    return $stmt->fetchAll() ?: [];
}

function getAccountingEntityAdminUserById(int $id): ?array {
    if ($id <= 0 || !hasAccountingEntityAdminUsersTable()) {
        return null;
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'SELECT aeu.id, aeu.accounting_entity_id, aeu.user_id, aeu.is_active, aeu.can_manage_extranet, aeu.can_manage_documents, aeu.can_manage_ai, aeu.can_manage_users, aeu.created_at, aeu.updated_at, u.username, u.name, u.email, u.role
         FROM accounting_entity_admin_users aeu
         LEFT JOIN users u ON u.id = aeu.user_id
         WHERE aeu.id = ?
         LIMIT 1'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function saveAccountingEntityAdminUser(int $accountingEntityId, int $userId, array $permissions): int {
    if ($accountingEntityId <= 0 || $userId <= 0 || !hasAccountingEntityAdminUsersTable()) {
        return 0;
    }

    $isActive = !empty($permissions['is_active']) ? 1 : 0;
    $canManageExtranet = !empty($permissions['can_manage_extranet']) ? 1 : 0;
    $canManageDocuments = !empty($permissions['can_manage_documents']) ? 1 : 0;
    $canManageAi = !empty($permissions['can_manage_ai']) ? 1 : 0;
    $canManageUsers = !empty($permissions['can_manage_users']) ? 1 : 0;

    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'INSERT INTO accounting_entity_admin_users
            (accounting_entity_id, user_id, is_active, can_manage_extranet, can_manage_documents, can_manage_ai, can_manage_users)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            is_active = VALUES(is_active),
            can_manage_extranet = VALUES(can_manage_extranet),
            can_manage_documents = VALUES(can_manage_documents),
            can_manage_ai = VALUES(can_manage_ai),
            can_manage_users = VALUES(can_manage_users)'
    );
    $stmt->execute([
        $accountingEntityId,
        $userId,
        $isActive,
        $canManageExtranet,
        $canManageDocuments,
        $canManageAi,
        $canManageUsers,
    ]);

    $lookup = $pdo->prepare('SELECT id FROM accounting_entity_admin_users WHERE accounting_entity_id = ? AND user_id = ? LIMIT 1');
    $lookup->execute([$accountingEntityId, $userId]);
    $id = (int) ($lookup->fetchColumn() ?: 0);

    logAuditAction('update', 'accounting_entity_admin_user', $id, [
        'accounting_entity_id' => $accountingEntityId,
        'user_id' => $userId,
        'is_active' => $isActive,
        'can_manage_extranet' => $canManageExtranet,
        'can_manage_documents' => $canManageDocuments,
        'can_manage_ai' => $canManageAi,
        'can_manage_users' => $canManageUsers,
    ]);

    return $id;
}

function deleteAccountingEntityAdminUser(int $id): void {
    if ($id <= 0 || !hasAccountingEntityAdminUsersTable()) {
        return;
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare('DELETE FROM accounting_entity_admin_users WHERE id = ?');
    $stmt->execute([$id]);

    if ($stmt->rowCount() > 0) {
        logAuditAction('delete', 'accounting_entity_admin_user', $id);
    }
}

function getAccountingEntityAdminTaskDefinitions(): array {
    return [
        'ctb_classificar_docs' => [
            'label' => 'Classificação de Documentos',
            'description' => 'Permite classificar documentos na área de contabilidade.',
        ],
        'ctb_importar_docs' => [
            'label' => 'Importação de Lançamentos',
            'description' => 'Permite importar os lançamentos classificados para a contabilidade.',
        ],
        'ctb_envio_saft' => [
            'label' => 'Envio de SAF-T',
            'description' => 'Permite enviar o ficheiro SAF-T desta empresa na área de tarefas.',
        ],
        'ctb_apuramento_iva' => [
            'label' => 'Apuramento de IVA',
            'description' => 'Permite tratar o apuramento de IVA desta empresa na área de tarefas.',
        ],
    ];
}

function getAccountingEntityAdminTaskPermissions(int $accountingEntityId): array {
    $definitions = getAccountingEntityAdminTaskDefinitions();
    $result = [];
    foreach ($definitions as $permissionKey => $definition) {
        $result[$permissionKey] = [
            'permission_key' => $permissionKey,
            'label' => $definition['label'] ?? $permissionKey,
            'description' => $definition['description'] ?? '',
            'users' => [],
            'user_ids' => [],
        ];
    }

    if ($accountingEntityId <= 0 || !hasAccountingEntityAdminTaskPermissionsTable()) {
        return $result;
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'SELECT aep.permission_key, aep.user_id, u.username, u.name, u.email, u.role
         FROM accounting_entity_admin_task_permissions aep
         LEFT JOIN users u ON u.id = aep.user_id
         WHERE aep.accounting_entity_id = ?
         ORDER BY aep.permission_key ASC, COALESCE(NULLIF(TRIM(u.name), \'\'), u.username) ASC, aep.id ASC'
    );
    $stmt->execute([$accountingEntityId]);
    $rows = $stmt->fetchAll() ?: [];

    foreach ($rows as $row) {
        $permissionKey = trim((string) ($row['permission_key'] ?? ''));
        if ($permissionKey === '') {
            continue;
        }
        if (!isset($result[$permissionKey])) {
            $result[$permissionKey] = [
                'permission_key' => $permissionKey,
                'label' => $permissionKey,
                'description' => '',
                'users' => [],
                'user_ids' => [],
            ];
        }

        $userId = (int) ($row['user_id'] ?? 0);
        if ($userId <= 0) {
            continue;
        }

        $result[$permissionKey]['users'][] = [
            'id' => $userId,
            'username' => (string) ($row['username'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'role' => (int) ($row['role'] ?? 3),
        ];
        $result[$permissionKey]['user_ids'][] = $userId;
    }

    foreach ($result as &$permission) {
        $permission['user_ids'] = array_values(array_unique(array_map('intval', $permission['user_ids'] ?? [])));
    }
    unset($permission);

    return $result;
}

function userHasAccountingEntityTaskPermission(string $permissionKey, ?int $accountingEntityId = null): bool {
    $permissionKey = trim($permissionKey);
    if ($permissionKey === '') {
        return false;
    }

    $user = currentUser();
    if (!$user) {
        return false;
    }

    if (($user['role'] ?? 3) <= 2) {
        return true;
    }

    $userId = (int) ($user['id'] ?? 0);
    if ($userId <= 0 || !hasAccountingEntityAdminTaskPermissionsTable()) {
        return false;
    }

    static $cache = [];
    $cacheKey = $userId . '|' . $permissionKey . '|' . ($accountingEntityId !== null ? (string) $accountingEntityId : '*');
    if (array_key_exists($cacheKey, $cache)) {
        return (bool) $cache[$cacheKey];
    }

    $pdo = getPDO();
    if ($accountingEntityId !== null && $accountingEntityId > 0) {
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM accounting_entity_admin_task_permissions
             WHERE accounting_entity_id = ? AND permission_key = ? AND user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$accountingEntityId, $permissionKey, $userId]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM accounting_entity_admin_task_permissions
             WHERE permission_key = ? AND user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$permissionKey, $userId]);
    }

    $cache[$cacheKey] = (bool) $stmt->fetchColumn();
    return (bool) $cache[$cacheKey];
}

function getUserAccountingEntityTaskNifs(int $userId, string $permissionKey): array {
    $permissionKey = trim($permissionKey);
    if ($userId <= 0 || $permissionKey === '' || !hasAccountingEntityAdminTaskPermissionsTable()) {
        return [];
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'SELECT ae.nif
         FROM accounting_entity_admin_task_permissions aep
         INNER JOIN accounting_entities ae ON ae.id = aep.accounting_entity_id
         WHERE aep.user_id = ? AND aep.permission_key = ? AND ae.entity_type = ?'
    );
    $stmt->execute([$userId, $permissionKey, 'acquirer']);

    $nifs = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $rawNif) {
        $nif = extractVatNumber((string) $rawNif);
        if ($nif !== '') {
            $nifs[$nif] = true;
        }
    }

    // PHP coerces numeric-string array keys to integers, so array_keys() would
    // return ints here. Cast back to strings so strict comparisons against the
    // (string) selected NIF — e.g. in_array(..., true) in buildCtbCompanyScopeSql
    // — match correctly.
    return array_map('strval', array_keys($nifs));
}

function saveAccountingEntityAdminTaskPermissions(int $accountingEntityId, string $permissionKey, array $userIds): void {
    if ($accountingEntityId <= 0 || !hasAccountingEntityAdminTaskPermissionsTable()) {
        return;
    }

    $definitions = getAccountingEntityAdminTaskDefinitions();
    if (!isset($definitions[$permissionKey])) {
        return;
    }

    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn($value) => $value > 0)));
    $pdo = getPDO();
    $deleteStmt = $pdo->prepare('DELETE FROM accounting_entity_admin_task_permissions WHERE accounting_entity_id = ? AND permission_key = ?');
    $deleteStmt->execute([$accountingEntityId, $permissionKey]);

    if ($userIds) {
        $insertStmt = $pdo->prepare(
            'INSERT INTO accounting_entity_admin_task_permissions (accounting_entity_id, permission_key, user_id)
             VALUES (?, ?, ?)'
        );
        foreach ($userIds as $userId) {
            $insertStmt->execute([$accountingEntityId, $permissionKey, $userId]);
        }
    }

    logAuditAction('update', 'accounting_entity_admin_task_permissions', null, [
        'accounting_entity_id' => $accountingEntityId,
        'permission_key' => $permissionKey,
        'user_ids' => $userIds,
    ]);
}

function getClientAccountingDocuments(int $accountingEntityId, int $limit = 300): array {
    if ($accountingEntityId <= 0) {
        return [];
    }

    $pdo = getPDO();
    $entityStmt = $pdo->prepare('SELECT nif FROM accounting_entities WHERE id = ? LIMIT 1');
    $entityStmt->execute([$accountingEntityId]);
    $entity = $entityStmt->fetch() ?: null;
    $nif = extractVatNumber((string) ($entity['nif'] ?? ''));
    if ($nif === '' || !hasTable('accounting_imports')) {
        return [];
    }

    $limit = max(20, min(1000, $limit));
    $nifRegex = '(^|[^0-9])' . $nif . '([^0-9]|$)';
    $where = '(field_B = ? OR field_B REGEXP ?)';
    $params = [$nif, $nifRegex];
    if (hasColumn('accounting_imports', 'field_C')) {
        $where = '(' . $where . ' OR field_C = ? OR field_C REGEXP ?)';
        $params[] = $nif;
        $params[] = $nifRegex;
    }

    $sql = 'SELECT id, date, file_name, field_A, field_B, field_C, total, status, account, created_at
            FROM accounting_imports
            WHERE ' . $where . '
            ORDER BY id DESC
            LIMIT ' . (int) $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

function getAccountingEntityExtranetSettings(int $accountingEntityId): array {
    $defaults = [
        'accounting_entity_id' => $accountingEntityId,
        'erp_software' => '',
        'erp_api_url' => '',
        'erp_api_username' => '',
        'erp_api_password' => '',
        'erp_api_token' => '',
        'support_enabled' => 0,
        'support_user_id' => null,
    ];

    if ($accountingEntityId <= 0 || !hasAccountingEntityExtranetSettingsTable()) {
        return $defaults;
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'SELECT accounting_entity_id, erp_software, erp_api_url, erp_api_username, erp_api_password, erp_api_token, support_enabled, support_user_id
         FROM accounting_entity_extranet_settings
         WHERE accounting_entity_id = ?
         LIMIT 1'
    );
    $stmt->execute([$accountingEntityId]);
    $row = $stmt->fetch();
    if (!$row) {
        return $defaults;
    }

    $row['support_enabled'] = (int) ($row['support_enabled'] ?? 0);
    $row['support_user_id'] = isset($row['support_user_id']) ? (int) $row['support_user_id'] : null;
    return array_merge($defaults, $row);
}

function saveAccountingEntityExtranetSettings(int $accountingEntityId, array $settings): void {
    if ($accountingEntityId <= 0 || !hasAccountingEntityExtranetSettingsTable()) {
        return;
    }

    $erpSoftware = trim((string) ($settings['erp_software'] ?? ''));
    $allowedErpSoftware = ['', 'Eticadata', 'Techsul', 'Wintouch'];
    if (!in_array($erpSoftware, $allowedErpSoftware, true)) {
        $erpSoftware = '';
    }

    $erpApiUrl = trim((string) ($settings['erp_api_url'] ?? ''));
    $erpApiUsername = trim((string) ($settings['erp_api_username'] ?? ''));
    $erpApiPassword = trim((string) ($settings['erp_api_password'] ?? ''));
    $erpApiToken = trim((string) ($settings['erp_api_token'] ?? ''));
    $supportEnabled = !empty($settings['support_enabled']) ? 1 : 0;
    $supportUserId = isset($settings['support_user_id']) && (int) $settings['support_user_id'] > 0
        ? (int) $settings['support_user_id']
        : null;

    if ($supportEnabled === 0) {
        $supportUserId = null;
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'INSERT INTO accounting_entity_extranet_settings
            (accounting_entity_id, erp_software, erp_api_url, erp_api_username, erp_api_password, erp_api_token, support_enabled, support_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            erp_software = VALUES(erp_software),
            erp_api_url = VALUES(erp_api_url),
            erp_api_username = VALUES(erp_api_username),
            erp_api_password = VALUES(erp_api_password),
            erp_api_token = VALUES(erp_api_token),
            support_enabled = VALUES(support_enabled),
            support_user_id = VALUES(support_user_id)'
    );
    $stmt->execute([
        $accountingEntityId,
        $erpSoftware,
        $erpApiUrl,
        $erpApiUsername,
        $erpApiPassword,
        $erpApiToken,
        $supportEnabled,
        $supportUserId,
    ]);

    logAuditAction('update', 'accounting_entity_extranet_settings', $accountingEntityId, [
        'erp_software' => $erpSoftware,
        'support_enabled' => $supportEnabled,
        'support_user_id' => $supportUserId,
    ]);
}

/**
 * Create a new user and return its ID.
 *
 * @return int
 */
function createUser(string $username, string $passwordHash, ?string $name, ?string $email, ?string $phone, int $role, ?string $photoPath = null, ?array $departmentTermIds = null, int $aiChatFloating = 0, int $aiReadOnly = 1): int {
    $pdo = getPDO();
    if (hasUserAiPreferenceColumns()) {
        $stmt = $pdo->prepare('INSERT INTO users (username, password, name, email, phone, role, photo, ai_chat_floating, ai_read_only) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$username, $passwordHash, $name, $email, $phone, $role, $photoPath, $aiChatFloating, $aiReadOnly]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO users (username, password, name, email, phone, role, photo) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$username, $passwordHash, $name, $email, $phone, $role, $photoPath]);
    }
    $userId = (int) $pdo->lastInsertId();
    if ($departmentTermIds !== null) {
        setUserDepartmentTerms($userId, $departmentTermIds);
    }
    logAuditAction('create', 'user', $userId, ['username' => $username]);
    return $userId;
}

/**
 * Update an existing user.
 *
 * The username cannot be modified once a user is created, so this function
 * only updates the remaining profile fields.
 */
function updateUser(int $id, ?string $passwordHash, ?string $name, ?string $email, ?string $phone, int $role, ?string $photoPath = null, ?array $departmentTermIds = null, int $aiChatFloating = 0, int $aiReadOnly = 1): void {
    $pdo = getPDO();
    if (hasUserAiPreferenceColumns()) {
        $sql = 'UPDATE users SET name = ?, email = ?, phone = ?, ai_chat_floating = ?, ai_read_only = ?';
        $params = [$name, $email, $phone, $aiChatFloating, $aiReadOnly];
    } else {
        $sql = 'UPDATE users SET name = ?, email = ?, phone = ?';
        $params = [$name, $email, $phone];
    }
    if ($id !== 1) {
        $sql .= ', role = ?';
        $params[] = $role;
    }
    if ($passwordHash !== null) {
        $sql .= ', password = ?';
        $params[] = $passwordHash;
    }
    if ($photoPath !== null) {
        $sql .= ', photo = ?';
        $params[] = $photoPath;
    }
    $sql .= ' WHERE id = ?';
    $params[] = $id;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ($departmentTermIds !== null) {
        setUserDepartmentTerms($id, $departmentTermIds);
    }
    logAuditAction('update', 'user', $id);
}

function hasUserAiPreferenceColumns(): bool {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $cached = hasColumn('users', 'ai_chat_floating') && hasColumn('users', 'ai_read_only');
    return $cached;
}

function getDepartmentTaxonomyId(bool $createIfMissing = true): ?int {
    if (!hasTable('taxonomies')) {
        return null;
    }
    $taxonomy = getTaxonomyBySlug('departamentos');
    if (!$taxonomy && $createIfMissing) {
        $taxonomyId = createTaxonomy('departamentos', 'Departamentos');
        return $taxonomyId;
    }
    return $taxonomy ? (int) $taxonomy['id'] : null;
}

function getDepartmentTerms(): array {
    $taxonomyId = getDepartmentTaxonomyId();
    if (!$taxonomyId) {
        return [];
    }
    return getTerms($taxonomyId);
}

function getDepartmentTermIds(): array {
    $taxonomyId = getDepartmentTaxonomyId();
    if (!$taxonomyId) {
        return [];
    }
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id FROM taxonomy_terms WHERE taxonomy_id = ? ORDER BY name ASC');
    $stmt->execute([$taxonomyId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function getDepartmentTermsWithCounts(): array {
    $taxonomyId = getDepartmentTaxonomyId();
    if (!$taxonomyId) {
        return [];
    }
    $pdo = getPDO();
    if (hasTable('user_taxonomy_terms')) {
        $stmt = $pdo->prepare('SELECT tt.id, tt.name, COUNT(utt.user_id) AS user_count FROM taxonomy_terms tt LEFT JOIN user_taxonomy_terms utt ON utt.term_id = tt.id WHERE tt.taxonomy_id = ? GROUP BY tt.id ORDER BY tt.name ASC');
        $stmt->execute([$taxonomyId]);
        return $stmt->fetchAll();
    }
    $stmt = $pdo->prepare('SELECT id, name, 0 AS user_count FROM taxonomy_terms WHERE taxonomy_id = ? ORDER BY name ASC');
    $stmt->execute([$taxonomyId]);
    return $stmt->fetchAll();
}

function ensureUserDepartmentTermsTable(): bool {
    if (hasTable('user_taxonomy_terms')) {
        return true;
    }
    if (!hasTable('users') || !hasTable('taxonomy_terms')) {
        return false;
    }

    $pdo = getPDO();
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS user_taxonomy_terms (
                user_id INT NOT NULL,
                term_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, term_id),
                CONSTRAINT fk_user_taxonomy_terms_user
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_user_taxonomy_terms_term
                    FOREIGN KEY (term_id) REFERENCES taxonomy_terms(id) ON DELETE CASCADE
            )"
        );
    } catch (Throwable $e) {
        // Fallback without FK constraints for legacy schemas.
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS user_taxonomy_terms (
                    user_id INT NOT NULL,
                    term_id INT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (user_id, term_id)
                )"
            );
        } catch (Throwable $inner) {
            return false;
        }
    }

    return hasTable('user_taxonomy_terms');
}

function isDepartmentTermInUse(int $termId): bool {
    if (!hasTable('user_taxonomy_terms')) {
        return false;
    }
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT 1 FROM user_taxonomy_terms WHERE term_id = ? LIMIT 1');
    $stmt->execute([$termId]);
    return (bool) $stmt->fetch();
}

function getUserDepartmentTermIds(int $userId): array {
    if (!hasTable('user_taxonomy_terms') && !ensureUserDepartmentTermsTable()) {
        return [];
    }
    $taxonomyId = getDepartmentTaxonomyId();
    if (!$taxonomyId) {
        return [];
    }
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT utt.term_id FROM user_taxonomy_terms utt JOIN taxonomy_terms tt ON tt.id = utt.term_id WHERE utt.user_id = ? AND tt.taxonomy_id = ? ORDER BY tt.name ASC');
    $stmt->execute([$userId, $taxonomyId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function setUserDepartmentTerms(int $userId, array $termIds): void {
    if (!hasTable('user_taxonomy_terms') && !ensureUserDepartmentTermsTable()) {
        return;
    }
    $taxonomyId = getDepartmentTaxonomyId();
    if (!$taxonomyId) {
        return;
    }
    $allowedIds = array_flip(getDepartmentTermIds());
    $cleanIds = [];
    foreach ($termIds as $termId) {
        $termId = (int) $termId;
        if ($termId && isset($allowedIds[$termId])) {
            $cleanIds[] = $termId;
        }
    }
    $pdo = getPDO();
    $pdo->beginTransaction();
    $delete = $pdo->prepare('DELETE utt FROM user_taxonomy_terms utt JOIN taxonomy_terms tt ON tt.id = utt.term_id WHERE utt.user_id = ? AND tt.taxonomy_id = ?');
    $delete->execute([$userId, $taxonomyId]);
    if ($cleanIds) {
        $insert = $pdo->prepare('INSERT INTO user_taxonomy_terms (user_id, term_id) VALUES (?, ?)');
        foreach (array_unique($cleanIds) as $termId) {
            $insert->execute([$userId, $termId]);
        }
    }
    $pdo->commit();
}

function getBaseDepartmentPermissionOptions(): array {
    return [
        'compras_upload' => 'Compras -> Upload',
        'entidades_editar' => 'Entidades - Editar',
        'entidades_campos_adicionais_ver' => 'Entidades - Ver Campos Adicionais',
        'ctb_classificar_docs' => 'CTB Classificacao Docs',
        'ctb_importar_docs' => 'CTB Importar Docs',
        'ctb_lancamentos_aceder' => 'CTB Lancamentos - Aceder',
        'ctb_lancamentos_remover_local' => 'CTB Lancamentos - Remover locais',
        'ctb_efatura_aceder' => 'CTB E-fatura - Aceder',
        'ctb_efatura_sincronizar' => 'CTB E-fatura - Sincronizar',
        'ctb_efatura_credenciais' => 'CTB E-fatura - Gerir credenciais',
        'ai_assistant' => 'Assistente AI - Acesso',
        'ai_create_tasks' => 'Assistente AI - Criar tarefas',
        'ai_open_lancamentos' => 'Assistente AI - Abrir lancamentos',
        'ai_suggest_vat' => 'Assistente AI - Sugerir contas IVA',
        'ai_approve_docs' => 'Assistente AI - Aprovar/Rejeitar docs',
    ];
}

/**
 * "Tecnico (Base)" e um departamento especial: as suas permissoes aplicam-se
 * automaticamente a todos os utilizadores com role = 3 (tecnico), mesmo que
 * nao estejam formalmente atribuidos a este departamento. Serve para definir
 * um conjunto minimo de permissoes que todos os tecnicos devem ter, sem
 * depender da atribuicao manual de departamentos. Ver AGENTS.md ("Perfil
 * base Tecnico") para o racional completo.
 */
const BASELINE_DEPARTMENT_NAME = 'Tecnico (Base)';

function getBaselineDepartmentDefaultPermissions(): array {
    return [
        'entidades_campos_adicionais_ver',
    ];
}

function getBaselineDepartmentId(): int {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $taxonomyId = getDepartmentTaxonomyId();
    if (!$taxonomyId) {
        return $cached = 0;
    }

    $storedId = (int) (getSetting('baseline_department_id', '0') ?? '0');
    if ($storedId > 0) {
        $term = getTerm($storedId);
        if ($term && (int) ($term['taxonomy_id'] ?? 0) === $taxonomyId) {
            return $cached = $storedId;
        }
    }

    foreach (getTerms($taxonomyId) as $term) {
        if ($term['name'] === BASELINE_DEPARTMENT_NAME) {
            $id = (int) $term['id'];
            setSetting('baseline_department_id', (string) $id);
            return $cached = $id;
        }
    }

    $id = createTerm($taxonomyId, BASELINE_DEPARTMENT_NAME);
    setSetting('baseline_department_id', (string) $id);
    return $cached = $id;
}

function normalizeDepartmentPermissionKey(string $permission, array $customPermissions = []): ?string {
    $permission = trim($permission);
    if ($permission === '') {
        return null;
    }

    $baseOptions = getBaseDepartmentPermissionOptions();
    if (isset($baseOptions[$permission])) {
        return $permission;
    }

    if (in_array($permission, $customPermissions, true)) {
        return $permission;
    }

    $normalized = strtolower($permission);
    $normalized = strtr($normalized, [
        'á' => 'a',
        'à' => 'a',
        'ã' => 'a',
        'â' => 'a',
        'é' => 'e',
        'ê' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ô' => 'o',
        'õ' => 'o',
        'ú' => 'u',
        'ç' => 'c',
    ]);
    $normalized = preg_replace('/[^a-z0-9]+/', '', $normalized) ?? '';
    if ($normalized === '') {
        return null;
    }

    $aliases = [];
    foreach ($baseOptions as $key => $label) {
        $aliases[strtolower($key)] = $key;
        $labelKey = strtolower(strtr($label, [
            'á' => 'a',
            'à' => 'a',
            'ã' => 'a',
            'â' => 'a',
            'é' => 'e',
            'ê' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ô' => 'o',
            'õ' => 'o',
            'ú' => 'u',
            'ç' => 'c',
        ]));
        $labelKey = preg_replace('/[^a-z0-9]+/', '', $labelKey) ?? '';
        if ($labelKey !== '') {
            $aliases[$labelKey] = $key;
        }
    }

    $aliases['assistenteaisugerircontasdeiva'] = 'ai_suggest_vat';
    $aliases['assisteneaisugerircontasdeiva'] = 'ai_suggest_vat';
    $aliases['assisteneaisugerircontasiva'] = 'ai_suggest_vat';
    $aliases['aisuggestiva'] = 'ai_suggest_vat';

    return $aliases[$normalized] ?? null;
}

function getDepartmentPermissionOptions(): array {
    $options = getBaseDepartmentPermissionOptions();

    // Keep legacy/custom permissions visible in settings so they are not
    // silently dropped when the options list evolves over time.
    $raw = getSetting('department_permissions', '');
    if ($raw !== null && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $permissions) {
                if (!is_array($permissions)) {
                    continue;
                }
                foreach ($permissions as $permission) {
                    if (!is_string($permission)) {
                        continue;
                    }
                    $permission = trim($permission);
                    if ($permission === '') {
                        continue;
                    }
                    if (normalizeDepartmentPermissionKey($permission) !== null || isset($options[$permission])) {
                        continue;
                    }
                    $options[$permission] = 'Permissao personalizada (' . $permission . ')';
                }
            }
        }
    }

    return $options;
}

function getDepartmentPermissions(): array {
    $raw = getSetting('department_permissions', '');
    $decoded = ($raw !== null && trim($raw) !== '') ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        $decoded = [];
    }

    $allowed = array_keys(getDepartmentPermissionOptions());
    $customPermissions = array_values(array_diff($allowed, array_keys(getBaseDepartmentPermissionOptions())));
    $result = [];

    foreach ($decoded as $deptId => $permissions) {
        $deptId = (int) $deptId;
        if ($deptId <= 0 || !is_array($permissions)) {
            continue;
        }
        $clean = [];
        foreach ($permissions as $permission) {
            if (!is_string($permission)) {
                continue;
            }
            $normalized = normalizeDepartmentPermissionKey($permission, $customPermissions);
            if ($normalized !== null && in_array($normalized, $allowed, true)) {
                $clean[] = $normalized;
            }
        }
        $result[$deptId] = array_values(array_unique($clean));
    }

    // Enquanto ninguem configurar explicitamente as permissoes do
    // departamento base "Tecnico (Base)" em Definicoes, aplica-se o valor
    // por omissao. Assim que for guardado uma vez a partir da UI, o valor
    // explicito (mesmo vazio) passa a prevalecer.
    $baselineDeptId = getBaselineDepartmentId();
    if ($baselineDeptId > 0 && !array_key_exists($baselineDeptId, $decoded)) {
        $result[$baselineDeptId] = getBaselineDepartmentDefaultPermissions();
    }

    return $result;
}

function userHasDepartmentPermission(string $permission): bool {
    $user = currentUser();
    if (!$user) {
        return false;
    }
    if (($user['role'] ?? 3) <= 2) {
        return true;
    }

    $userId = (int) ($user['id'] ?? 0);
    if ($userId <= 0) {
        return false;
    }

    $departments = getUserDepartmentTermIds($userId);
    $baselineDeptId = getBaselineDepartmentId();
    if ($baselineDeptId > 0 && !in_array($baselineDeptId, $departments, true)) {
        // Permissoes do departamento base aplicam-se a todos os tecnicos
        // (role 3), independentemente de estarem atribuidos a ele.
        $departments[] = $baselineDeptId;
    }
    if (!$departments) {
        return false;
    }

    $permissionsMap = getDepartmentPermissions();
    foreach ($departments as $departmentId) {
        if (!empty($permissionsMap[$departmentId]) && in_array($permission, $permissionsMap[$departmentId], true)) {
            return true;
        }
    }

    return false;
}

function isInternalChatEnabled(): bool {
    return getSetting('internal_chat_enabled', '0') === '1';
}

function hasInternalChatTables(): bool {
    static $hasTables = null;
    if ($hasTables !== null) {
        return $hasTables;
    }

    $hasTables = hasTable('internal_chat_channels')
        && hasTable('internal_chat_channel_members')
        && hasTable('internal_chat_messages');

    return $hasTables;
}

function ensureInternalChatPublicChannel(): ?int {
    if (!hasInternalChatTables()) {
        return null;
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id FROM internal_chat_channels WHERE slug = ? LIMIT 1');
    $stmt->execute(['public']);
    $channelId = (int) ($stmt->fetchColumn() ?: 0);
    if ($channelId > 0) {
        return $channelId;
    }

    try {
        $insert = $pdo->prepare(
            'INSERT INTO internal_chat_channels (slug, name, channel_type, created_by) VALUES (?, ?, ?, NULL)'
        );
        $insert->execute(['public', 'Canal Publico', 'public']);
        return (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        $stmt->execute(['public']);
        $channelId = (int) ($stmt->fetchColumn() ?: 0);
        return $channelId > 0 ? $channelId : null;
    }
}

function userCanAccessInternalChatChannel(int $userId, int $channelId): bool {
    if ($userId <= 0 || $channelId <= 0 || !hasInternalChatTables()) {
        return false;
    }

    ensureInternalChatPublicChannel();

    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'SELECT c.id
         FROM internal_chat_channels c
         LEFT JOIN internal_chat_channel_members m
           ON m.channel_id = c.id AND m.user_id = ?
         WHERE c.id = ?
           AND (c.channel_type = ? OR m.user_id IS NOT NULL)
         LIMIT 1'
    );
    $stmt->execute([$userId, $channelId, 'public']);
    return (bool) $stmt->fetchColumn();
}

function getInternalChatChannelsForUser(int $userId): array {
    if ($userId <= 0 || !hasInternalChatTables()) {
        return [];
    }

    ensureInternalChatPublicChannel();

    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'SELECT
            c.id,
            c.slug,
            c.name,
            c.channel_type,
            c.updated_at,
            (
                SELECT message
                FROM internal_chat_messages lm
                WHERE lm.channel_id = c.id
                ORDER BY lm.id DESC
                LIMIT 1
            ) AS last_message,
            (
                SELECT created_at
                FROM internal_chat_messages lm
                WHERE lm.channel_id = c.id
                ORDER BY lm.id DESC
                LIMIT 1
            ) AS last_message_at,
            (
                SELECT COUNT(*)
                FROM internal_chat_channel_members cm
                WHERE cm.channel_id = c.id
            ) AS member_count
         FROM internal_chat_channels c
         LEFT JOIN internal_chat_channel_members m
           ON m.channel_id = c.id AND m.user_id = ?
         WHERE c.channel_type = ? OR m.user_id IS NOT NULL
         ORDER BY
            CASE WHEN c.slug = ? THEN 0 ELSE 1 END,
            COALESCE(
                (
                    SELECT created_at
                    FROM internal_chat_messages lm
                    WHERE lm.channel_id = c.id
                    ORDER BY lm.id DESC
                    LIMIT 1
                ),
                c.updated_at,
                c.created_at
            ) DESC,
            c.name ASC'
    );
    $stmt->execute([$userId, 'public', 'public']);
    $channels = $stmt->fetchAll();

    foreach ($channels as &$channel) {
        $channel['id'] = (int) ($channel['id'] ?? 0);
        $channel['member_count'] = (int) ($channel['member_count'] ?? 0);
        $channel['is_public'] = ($channel['channel_type'] ?? '') === 'public';
    }
    unset($channel);

    return $channels;
}

function getInternalChatMessages(int $userId, int $channelId, int $limit = 80): array {
    if (!userCanAccessInternalChatChannel($userId, $channelId)) {
        return [];
    }

    $limit = max(1, min(200, $limit));
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'SELECT *
         FROM (
            SELECT
                m.id,
                m.channel_id,
                m.user_id,
                m.message,
                m.created_at,
                u.username,
                u.name,
                u.photo
            FROM internal_chat_messages m
            LEFT JOIN users u ON u.id = m.user_id
            WHERE m.channel_id = ?
            ORDER BY m.id DESC
            LIMIT ' . $limit . '
         ) recent
         ORDER BY recent.id ASC'
    );
    $stmt->execute([$channelId]);
    $messages = $stmt->fetchAll();

    foreach ($messages as &$message) {
        $message['id'] = (int) ($message['id'] ?? 0);
        $message['channel_id'] = (int) ($message['channel_id'] ?? 0);
        $message['user_id'] = isset($message['user_id']) ? (int) $message['user_id'] : null;
        $message['display_name'] = trim((string) ($message['name'] ?? '')) !== ''
            ? (string) $message['name']
            : (trim((string) ($message['username'] ?? '')) !== '' ? (string) $message['username'] : 'Utilizador removido');
    }
    unset($message);

    return $messages;
}

function getInternalChatAvailableUsers(): array {
    if (!hasTable('users')) {
        return [];
    }

    $pdo = getPDO();
    $stmt = $pdo->query('SELECT id, username, name, photo, role FROM users ORDER BY COALESCE(NULLIF(name, \'\'), username) ASC, id ASC');
    $users = $stmt->fetchAll();

    foreach ($users as &$user) {
        $user['id'] = (int) ($user['id'] ?? 0);
        $user['display_name'] = trim((string) ($user['name'] ?? '')) !== ''
            ? (string) $user['name']
            : (string) ($user['username'] ?? '');
    }
    unset($user);

    return $users;
}

function createInternalChatGroup(string $name, array $memberIds, int $createdBy): int {
    if ($createdBy <= 0 || !hasInternalChatTables()) {
        throw new RuntimeException('Chat interno indisponivel.');
    }

    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('Indique o nome do grupo.');
    }
    if (strlen($name) > 150) {
        $name = substr($name, 0, 150);
    }

    $cleanMemberIds = [];
    foreach ($memberIds as $memberId) {
        $memberId = (int) $memberId;
        if ($memberId > 0) {
            $cleanMemberIds[] = $memberId;
        }
    }
    $cleanMemberIds[] = $createdBy;
    $cleanMemberIds = array_values(array_unique($cleanMemberIds));

    $availableUsers = array_column(getInternalChatAvailableUsers(), 'id');
    $allowedUserIds = array_flip($availableUsers);
    $cleanMemberIds = array_values(array_filter(
        $cleanMemberIds,
        static fn (int $memberId): bool => isset($allowedUserIds[$memberId])
    ));

    if (!$cleanMemberIds) {
        throw new InvalidArgumentException('Selecione pelo menos um membro valido.');
    }

    $pdo = getPDO();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO internal_chat_channels (slug, name, channel_type, created_by) VALUES (NULL, ?, ?, ?)'
        );
        $stmt->execute([$name, 'group', $createdBy]);
        $channelId = (int) $pdo->lastInsertId();

        $memberStmt = $pdo->prepare(
            'INSERT INTO internal_chat_channel_members (channel_id, user_id, added_by) VALUES (?, ?, ?)'
        );
        foreach ($cleanMemberIds as $memberId) {
            $memberStmt->execute([$channelId, $memberId, $createdBy]);
        }

        $pdo->commit();
        logAuditAction('internal_chat_group_create', 'internal_chat_channels', $channelId, ['name' => $name]);

        return $channelId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function createInternalChatMessage(int $channelId, int $userId, string $message): int {
    if (!userCanAccessInternalChatChannel($userId, $channelId)) {
        throw new RuntimeException('Sem acesso ao canal selecionado.');
    }

    $message = trim($message);
    if ($message === '') {
        throw new InvalidArgumentException('A mensagem nao pode estar vazia.');
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare('INSERT INTO internal_chat_messages (channel_id, user_id, message) VALUES (?, ?, ?)');
    $stmt->execute([$channelId, $userId, $message]);

    $touch = $pdo->prepare('UPDATE internal_chat_channels SET updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $touch->execute([$channelId]);

    $messageId = (int) $pdo->lastInsertId();
    logAuditAction('internal_chat_message_create', 'internal_chat_messages', $messageId, ['channel_id' => $channelId]);

    return $messageId;
}

function hasInternalChatPresenceTable(): bool {
    static $hasTablePresence = null;
    if ($hasTablePresence !== null) {
        return $hasTablePresence;
    }

    $hasTablePresence = hasTable('internal_chat_user_presence');
    return $hasTablePresence;
}

function upsertInternalChatPresence(int $userId, string $state = 'online', ?string $page = null, bool $touchActivity = true): void {
    if ($userId <= 0 || !hasInternalChatTables() || !hasInternalChatPresenceTable()) {
        return;
    }

    $state = strtolower(trim($state));
    if (!in_array($state, ['online', 'away'], true)) {
        $state = 'online';
    }

    $page = $page !== null ? trim($page) : null;
    if ($page !== null && $page !== '' && strlen($page) > 255) {
        $page = substr($page, 0, 255);
    }
    if ($page === '') {
        $page = null;
    }

    $pdo = getPDO();
    if ($touchActivity) {
        $stmt = $pdo->prepare(
            'INSERT INTO internal_chat_user_presence (user_id, state, last_seen, last_activity, last_page)
             VALUES (?, ?, NOW(), NOW(), ?)
             ON DUPLICATE KEY UPDATE
                state = VALUES(state),
                last_seen = NOW(),
                last_activity = NOW(),
                last_page = VALUES(last_page)'
        );
        $stmt->execute([$userId, $state, $page]);
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO internal_chat_user_presence (user_id, state, last_seen, last_activity, last_page)
         VALUES (?, ?, NOW(), NOW(), ?)
         ON DUPLICATE KEY UPDATE
            state = VALUES(state),
            last_seen = NOW(),
            last_page = VALUES(last_page)'
    );
    $stmt->execute([$userId, $state, $page]);
}

function getInternalChatPresenceUsers(): array {
    if (!hasTable('users')) {
        return [];
    }

    $pdo = getPDO();
    $hasPresence = hasInternalChatPresenceTable();
    if ($hasPresence) {
        $stmt = $pdo->query(
            'SELECT
                u.id,
                u.username,
                u.name,
                u.photo,
                p.state AS raw_state,
                p.last_seen,
                p.last_activity,
                p.last_page,
                CASE
                    WHEN p.last_seen IS NULL THEN "offline"
                    WHEN p.last_seen < (NOW() - INTERVAL 2 MINUTE) THEN "offline"
                    WHEN p.state = "away" THEN "away"
                    WHEN p.last_activity < (NOW() - INTERVAL 5 MINUTE) THEN "away"
                    ELSE "online"
                END AS presence_state
             FROM users u
             LEFT JOIN internal_chat_user_presence p ON p.user_id = u.id
             ORDER BY
                CASE
                    WHEN p.last_seen IS NULL THEN 2
                    WHEN p.last_seen < (NOW() - INTERVAL 2 MINUTE) THEN 2
                    WHEN p.state = "away" OR p.last_activity < (NOW() - INTERVAL 5 MINUTE) THEN 1
                    ELSE 0
                END ASC,
                COALESCE(NULLIF(u.name, ""), u.username) ASC,
                u.id ASC'
        );
    } else {
        $stmt = $pdo->query(
            'SELECT
                u.id,
                u.username,
                u.name,
                u.photo,
                NULL AS raw_state,
                NULL AS last_seen,
                NULL AS last_activity,
                NULL AS last_page,
                "offline" AS presence_state
             FROM users u
             ORDER BY COALESCE(NULLIF(u.name, ""), u.username) ASC, u.id ASC'
        );
    }

    $users = $stmt->fetchAll();
    foreach ($users as &$presenceUser) {
        $presenceUser['id'] = (int) ($presenceUser['id'] ?? 0);
        $presenceUser['display_name'] = trim((string) ($presenceUser['name'] ?? '')) !== ''
            ? (string) $presenceUser['name']
            : (string) ($presenceUser['username'] ?? '');
        $presenceUser['presence_state'] = (string) ($presenceUser['presence_state'] ?? 'offline');
    }
    unset($presenceUser);

    return $users;
}

function getInternalChatPresenceCounts(): array {
    $users = getInternalChatPresenceUsers();
    $counts = [
        'online' => 0,
        'away' => 0,
        'offline' => 0,
    ];

    foreach ($users as $presenceUser) {
        $state = (string) ($presenceUser['presence_state'] ?? 'offline');
        if (!isset($counts[$state])) {
            $state = 'offline';
        }
        $counts[$state] += 1;
    }

    return $counts;
}

function getInternalChatLatestVisibleMessage(int $userId, int $afterMessageId = 0): ?array {
    if ($userId <= 0 || !hasInternalChatTables()) {
        return null;
    }

    $afterMessageId = max(0, $afterMessageId);
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'SELECT
            m.id,
            m.channel_id,
            m.user_id,
            m.message,
            m.created_at,
            c.name AS channel_name,
            c.channel_type,
            u.username,
            u.name,
            u.photo
         FROM internal_chat_messages m
         INNER JOIN internal_chat_channels c ON c.id = m.channel_id
         LEFT JOIN internal_chat_channel_members cm
           ON cm.channel_id = c.id AND cm.user_id = ?
         LEFT JOIN users u ON u.id = m.user_id
         WHERE (c.channel_type = ? OR cm.user_id IS NOT NULL)
           AND m.id > ?
         ORDER BY m.id DESC
         LIMIT 1'
    );
    $stmt->execute([$userId, 'public', $afterMessageId]);
    $message = $stmt->fetch() ?: null;

    if ($message === null) {
        return null;
    }

    $message['id'] = (int) ($message['id'] ?? 0);
    $message['channel_id'] = (int) ($message['channel_id'] ?? 0);
    $message['user_id'] = isset($message['user_id']) ? (int) $message['user_id'] : null;
    $message['display_name'] = trim((string) ($message['name'] ?? '')) !== ''
        ? (string) $message['name']
        : (trim((string) ($message['username'] ?? '')) !== '' ? (string) $message['username'] : 'Utilizador removido');

    return $message;
}

function countInternalChatUnreadMessages(int $userId, int $afterMessageId = 0): int {
    if ($userId <= 0 || !hasInternalChatTables()) {
        return 0;
    }

    $afterMessageId = max(0, $afterMessageId);
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM internal_chat_messages m
         INNER JOIN internal_chat_channels c ON c.id = m.channel_id
         LEFT JOIN internal_chat_channel_members cm
           ON cm.channel_id = c.id AND cm.user_id = ?
         WHERE (c.channel_type = ? OR cm.user_id IS NOT NULL)
           AND m.id > ?
           AND COALESCE(m.user_id, 0) <> ?'
    );
    $stmt->execute([$userId, 'public', $afterMessageId, $userId]);
    return (int) $stmt->fetchColumn();
}

function getInternalChatSummary(int $userId, int $afterMessageId = 0): array {
    return [
        'latest_message' => getInternalChatLatestVisibleMessage($userId, $afterMessageId),
        'unread_count' => countInternalChatUnreadMessages($userId, $afterMessageId),
        'presence_counts' => getInternalChatPresenceCounts(),
    ];
}

/**
 * Delete a user by id.
 */
/**
 * Delete a user if the current user has a higher privilege.
 * Users with an equal or lower privilege level (numerically higher value)
 * cannot delete accounts with greater privileges.
 *
 * @param int $id
 * @return void
 */
function deleteUser(int $id): void {
    $current = currentUser();
    $target  = getUserById($id);
    if (!$current || !$target) {
        return;
    }
    // Prevent deletion if current user role is >= target user's role (less privileged or same level)
    if ($current['role'] >= $target['role']) {
        return;
    }
    // Prevent deleting oneself for additional safety
    if ($current['id'] === $id) {
        return;
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$id]);
}

/**
 * Retrieve all content types.
 *
 * @return array
 */
function getContentTypes(): array {
    $pdo = getPDO();
    $hasAuthor = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'show_author'")->fetch();
    $hasDate   = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'show_date'")->fetch();
    $hasTax    = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'show_taxonomies'")->fetch();
    $hasOrder  = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'sort_order'")->fetch();
    $hasApi    = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'api_enabled'")->fetch();
    $authorExpr = $hasAuthor ? 'show_author' : '1 AS show_author';
    $dateExpr   = $hasDate ? 'show_date' : '1 AS show_date';
    $taxExpr    = $hasTax ? 'show_taxonomies' : '1 AS show_taxonomies';
    $apiExpr    = $hasApi ? 'api_enabled' : '0 AS api_enabled';
    $orderExpr  = $hasOrder ? 'sort_order' : 'id';
    $stmt = $pdo->query("SELECT id, name, label, icon, $authorExpr, $dateExpr, $taxExpr, $apiExpr, $orderExpr AS sort_order FROM content_types ORDER BY $orderExpr ASC, id ASC");
    return $stmt->fetchAll();
}

/**
 * Fetch a single content type by id.  Returns null if not found.
 *
 * @param int $id
 * @return array|null
 */
function getContentType(int $id): ?array {
    $pdo = getPDO();
    $hasAuthor = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'show_author'")->fetch();
    $hasDate   = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'show_date'")->fetch();
    $hasTax    = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'show_taxonomies'")->fetch();
    $hasOrder  = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'sort_order'")->fetch();
    $hasApi    = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'api_enabled'")->fetch();
    $hasTitleRow   = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'title_grid_row'")->fetch();
    $hasTitleCol   = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'title_grid_col'")->fetch();
    $hasTitleWidth = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'title_grid_width'")->fetch();
    $hasBodyRow    = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'body_grid_row'")->fetch();
    $hasBodyCol    = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'body_grid_col'")->fetch();
    $hasBodyWidth  = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'body_grid_width'")->fetch();
    $authorExpr = $hasAuthor ? 'show_author' : '1 AS show_author';
    $dateExpr   = $hasDate ? 'show_date' : '1 AS show_date';
    $taxExpr    = $hasTax ? 'show_taxonomies' : '1 AS show_taxonomies';
    $apiExpr    = $hasApi ? 'api_enabled' : '0 AS api_enabled';
    $orderExpr  = $hasOrder ? 'sort_order' : '0 AS sort_order';
    $titleRowExpr   = $hasTitleRow ? 'title_grid_row' : '0 AS title_grid_row';
    $titleColExpr   = $hasTitleCol ? 'title_grid_col' : '0 AS title_grid_col';
    $titleWidthExpr = $hasTitleWidth ? 'title_grid_width' : '12 AS title_grid_width';
    $bodyRowExpr    = $hasBodyRow ? 'body_grid_row' : '0 AS body_grid_row';
    $bodyColExpr    = $hasBodyCol ? 'body_grid_col' : '0 AS body_grid_col';
    $bodyWidthExpr  = $hasBodyWidth ? 'body_grid_width' : '12 AS body_grid_width';
    $stmt = $pdo->prepare("SELECT id, name, label, icon, $authorExpr, $dateExpr, $taxExpr, $apiExpr, $orderExpr, $titleRowExpr, $titleColExpr, $titleWidthExpr, $bodyRowExpr, $bodyColExpr, $bodyWidthExpr FROM content_types WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Retrieve a content type using its slug (name).
 *
 * @param string $slug
 * @return array|null
 */
function getContentTypeBySlug(string $slug): ?array {
    $pdo = getPDO();
    $hasAuthor = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'show_author'")->fetch();
    $hasDate   = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'show_date'")->fetch();
    $hasTax    = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'show_taxonomies'")->fetch();
    $hasOrder  = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'sort_order'")->fetch();
    $hasApi    = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'api_enabled'")->fetch();
    $hasTitleRow   = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'title_grid_row'")->fetch();
    $hasTitleCol   = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'title_grid_col'")->fetch();
    $hasTitleWidth = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'title_grid_width'")->fetch();
    $hasBodyRow    = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'body_grid_row'")->fetch();
    $hasBodyCol    = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'body_grid_col'")->fetch();
    $hasBodyWidth  = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'body_grid_width'")->fetch();
    $authorExpr = $hasAuthor ? 'show_author' : '1 AS show_author';
    $dateExpr   = $hasDate ? 'show_date' : '1 AS show_date';
    $taxExpr    = $hasTax ? 'show_taxonomies' : '1 AS show_taxonomies';
    $apiExpr    = $hasApi ? 'api_enabled' : '0 AS api_enabled';
    $orderExpr  = $hasOrder ? 'sort_order' : '0 AS sort_order';
    $titleRowExpr   = $hasTitleRow ? 'title_grid_row' : '0 AS title_grid_row';
    $titleColExpr   = $hasTitleCol ? 'title_grid_col' : '0 AS title_grid_col';
    $titleWidthExpr = $hasTitleWidth ? 'title_grid_width' : '12 AS title_grid_width';
    $bodyRowExpr    = $hasBodyRow ? 'body_grid_row' : '0 AS body_grid_row';
    $bodyColExpr    = $hasBodyCol ? 'body_grid_col' : '0 AS body_grid_col';
    $bodyWidthExpr  = $hasBodyWidth ? 'body_grid_width' : '12 AS body_grid_width';

    $stmt = $pdo->prepare("SELECT id, name, label, icon, $authorExpr, $dateExpr, $taxExpr, $apiExpr, $orderExpr, $titleRowExpr, $titleColExpr, $titleWidthExpr, $bodyRowExpr, $bodyColExpr, $bodyWidthExpr FROM content_types WHERE name = ?");
    $stmt->execute([$slug]);
    return $stmt->fetch() ?: null;
}

/**
 * Get the next sort order value for content types.
 *
 * @return int
 */
function getNextContentTypeSortOrder(): int {
    $pdo = getPDO();
    $hasOrder = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'sort_order'")->fetch();
    if ($hasOrder) {
        $max = $pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM content_types')->fetchColumn();
        return (int)$max + 1;
    }
    return 0;
}

/**
 * Create a new content type.  Returns the id of the new row.
 *
 * @param string $name       Slug used internally
 * @param string $label      Human-readable label
 * @param string $icon       CSS class for an icon
 * @param int    $sort_order Order used in navigation
 * @return int
 */

function createContentType(string $name, string $label, string $icon, bool $show_author = false, bool $show_date = false, bool $show_taxonomies = true, int $sort_order = 0, bool $api_enabled = false): int {
    $pdo = getPDO();
    $hasAuthor = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'show_author'")->fetch();
    $hasDate   = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'show_date'")->fetch();
    $hasTax    = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'show_taxonomies'")->fetch();
    $hasOrder  = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'sort_order'")->fetch();
    $hasApi    = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'api_enabled'")->fetch();
    $fields = ['name', 'label', 'icon'];
    $placeholders = ['?', '?', '?'];
    $values = [$name, $label, $icon];
    if ($hasOrder) { $fields[] = 'sort_order'; $placeholders[] = '?'; $values[] = $sort_order; }
    if ($hasAuthor) { $fields[] = 'show_author'; $placeholders[] = '?'; $values[] = $show_author ? 1 : 0; }
    if ($hasDate) { $fields[] = 'show_date'; $placeholders[] = '?'; $values[] = $show_date ? 1 : 0; }
    if ($hasTax) { $fields[] = 'show_taxonomies'; $placeholders[] = '?'; $values[] = $show_taxonomies ? 1 : 0; }
    if ($hasApi) { $fields[] = 'api_enabled'; $placeholders[] = '?'; $values[] = $api_enabled ? 1 : 0; }
    $sql = 'INSERT INTO content_types (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
    $id = (int)$pdo->lastInsertId();
    logAuditAction('create', 'content_type', $id, ['name' => $name]);
    return $id;
}

/**
 * Update an existing content type.
 *
 * @param int         $id
 * @param string      $name
 * @param string      $label
 * @param string|null $icon
 * @param bool        $show_author
 * @param bool        $show_date
 * @param int         $sort_order
 * @return void
 */
function updateContentType(int $id, string $name, string $label, ?string $icon = null, bool $show_author = false, bool $show_date = false, bool $show_taxonomies = true, int $sort_order = 0, bool $api_enabled = false): void {
    $pdo = getPDO();
    $hasAuthor = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'show_author'")->fetch();
    $hasDate   = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'show_date'")->fetch();
    $hasTax    = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'show_taxonomies'")->fetch();
    $hasOrder  = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'sort_order'")->fetch();
    $hasApi    = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'api_enabled'")->fetch();
    $fields = ['name = ?', 'label = ?', 'icon = ?'];
    $values = [$name, $label, $icon];
    if ($hasOrder) { $fields[] = 'sort_order = ?'; $values[] = $sort_order; }
    if ($hasAuthor) { $fields[] = 'show_author = ?'; $values[] = $show_author ? 1 : 0; }
    if ($hasDate) { $fields[] = 'show_date = ?'; $values[] = $show_date ? 1 : 0; }
    if ($hasTax) { $fields[] = 'show_taxonomies = ?'; $values[] = $show_taxonomies ? 1 : 0; }
    if ($hasApi) { $fields[] = 'api_enabled = ?'; $values[] = $api_enabled ? 1 : 0; }
    $sql = 'UPDATE content_types SET ' . implode(', ', $fields) . ' WHERE id = ?';
    $values[] = $id;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
    logAuditAction('update', 'content_type', $id, ['name' => $name]);
}

/**
 * Enable or disable API access for a content type.
 *
 * @param int  $id
 * @param bool $enabled
 * @return void
 */
function setContentTypeApi(int $id, bool $enabled): void {
    $pdo = getPDO();
    $hasApi = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'api_enabled'")->fetch();
    if (!$hasApi) {
        return;
    }
    $stmt = $pdo->prepare('UPDATE content_types SET api_enabled = ? WHERE id = ?');
    $stmt->execute([$enabled ? 1 : 0, $id]);
}

/**
 * Delete a content type by id.
 *
 * @param int $id
 * @return void
 */
function deleteContentType(int $id): void {
    $pdo = getPDO();
    $stmt = $pdo->prepare('DELETE FROM content_types WHERE id = ?');
    $stmt->execute([$id]);
}

/**
 * Count how many content entries belong to a content type.
 *
 * @param int $content_type_id
 * @return int Number of associated content records
 */
function countContentByContentType(int $content_type_id): int {
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM content WHERE content_type_id = ?');
    $stmt->execute([$content_type_id]);
    return (int)$stmt->fetchColumn();
}

/**
 * Retrieve custom fields for a given content type.
 *
 * @param int $content_type_id
 * @return array
 */
function getCustomFields(int $content_type_id): array {
    $pdo = getPDO();

    // Older installations might lack the "label" column.  Detect its
    // existence and fall back to using the field name as a label so the
    // application continues to work without a fatal database error.
    $hasLabel = $pdo->query("SHOW COLUMNS FROM custom_fields LIKE 'label'")->fetch();
    $labelExpr = $hasLabel ? 'label' : 'name AS label';
    $hasList = $pdo->query("SHOW COLUMNS FROM custom_fields LIKE 'show_in_list'")->fetch();
    $listExpr = $hasList ? 'show_in_list' : '0 AS show_in_list';
    $hasSortable = $pdo->query("SHOW COLUMNS FROM custom_fields LIKE 'sortable'")->fetch();
    $sortableExpr = $hasSortable ? 'sortable' : '1 AS sortable';
    $hasRow = $pdo->query("SHOW COLUMNS FROM custom_fields LIKE 'grid_row'")->fetch();
    $rowExpr = $hasRow ? 'grid_row' : '0 AS grid_row';
    $hasCol = $pdo->query("SHOW COLUMNS FROM custom_fields LIKE 'grid_col'")->fetch();
    $colExpr = $hasCol ? 'grid_col' : '0 AS grid_col';
    $hasWidth = $pdo->query("SHOW COLUMNS FROM custom_fields LIKE 'grid_width'")->fetch();


    $widthExpr = $hasWidth ? 'grid_width' : '12 AS grid_width';

    $stmt = $pdo->prepare("SELECT id, name, $labelExpr, type, options, required, $listExpr, $sortableExpr, $rowExpr, $colExpr, $widthExpr FROM custom_fields WHERE content_type_id = ? ORDER BY id ASC");
    $stmt->execute([$content_type_id]);
    return $stmt->fetchAll();
}

/**
 * Sort custom fields by grid position while preserving original order
 * for fields without layout information.
 *
 * @param array $fields
 * @return array
 */
function sortFieldsByGrid(array $fields): array {
    foreach ($fields as $index => &$field) {
        $field['_index'] = $index;
    }
    usort($fields, function ($a, $b) {
        $rowA = $a['grid_row'] ?? PHP_INT_MAX;
        $rowB = $b['grid_row'] ?? PHP_INT_MAX;
        if ($rowA === $rowB) {
            $colA = $a['grid_col'] ?? PHP_INT_MAX;
            $colB = $b['grid_col'] ?? PHP_INT_MAX;
            if ($colA === $colB) {
                return $a['_index'] <=> $b['_index'];
            }
            return $colA <=> $colB;
        }
        return $rowA <=> $rowB;
    });
    foreach ($fields as &$field) {
        unset($field['_index']);
    }
    return $fields;
}

/**
 * Determine the next available grid row for a content type's layout.
 *
 * Takes into account existing custom fields, core title/body fields and
 * taxonomy fields to ensure a new field is appended at the end without
 * disturbing the current layout.
 *
 * @param int $content_type_id
 * @return int
 */
function getNextFieldGridRow(int $content_type_id): int {
    $pdo = getPDO();
    $max = -1;

    // Custom fields
    $hasRow = $pdo->query("SHOW COLUMNS FROM custom_fields LIKE 'grid_row'")->fetch();
    if ($hasRow) {
        $stmt = $pdo->prepare('SELECT MAX(grid_row) FROM custom_fields WHERE content_type_id = ?');
        $stmt->execute([$content_type_id]);
        $val = $stmt->fetchColumn();
        if ($val !== false && $val !== null) {
            $max = max($max, (int) $val);
        }
    }

    // Title and body fields
    $hasTitleRow = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'title_grid_row'")->fetch();
    $hasBodyRow  = $pdo->query("SHOW COLUMNS FROM content_types LIKE 'body_grid_row'")->fetch();
    if ($hasTitleRow || $hasBodyRow) {
        $cols = [];
        if ($hasTitleRow) {
            $cols[] = 'title_grid_row';
        }
        if ($hasBodyRow) {
            $cols[] = 'body_grid_row';
        }
        $stmt = $pdo->prepare('SELECT ' . implode(', ', $cols) . ' FROM content_types WHERE id = ?');
        $stmt->execute([$content_type_id]);
        $rows = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($rows) {
            foreach ($rows as $row) {
                if ($row !== null) {
                    $max = max($max, (int) $row);
                }
            }
        }
    }

    // Taxonomy fields
    $hasTaxRow = $pdo->query("SHOW COLUMNS FROM content_type_taxonomy LIKE 'grid_row'")->fetch();
    if ($hasTaxRow) {
        $stmt = $pdo->prepare('SELECT MAX(grid_row) FROM content_type_taxonomy WHERE content_type_id = ?');
        $stmt->execute([$content_type_id]);
        $val = $stmt->fetchColumn();
        if ($val !== false && $val !== null) {
            $max = max($max, (int) $val);
        }
    }

    return $max + 1;
}

/**
 * Create a custom field for a content type.
 *
 * @param int $content_type_id
 * @param string $name Internal slug
 * @param string $label Display label
 * @param string $type One of: text, textarea, number, date, datetime, select
 * @param string $options Comma-separated values for select type (empty otherwise)
 * @param bool $required Whether the field is mandatory
 * @return int
 */
function createCustomField(int $content_type_id, string $name, string $label, string $type, string $options = '', bool $required = false, bool $show_in_list = false, bool $sortable = true, int $grid_row = 0, int $grid_col = 0, int $grid_width = 12): int {
    $pdo = getPDO();

    // Detect optional columns to keep compatibility with older schemas.
    $hasLabel    = $pdo->query("SHOW COLUMNS FROM custom_fields LIKE 'label'")->fetch();
    $hasList     = $pdo->query("SHOW COLUMNS FROM custom_fields LIKE 'show_in_list'")->fetch();
    $hasSortable = $pdo->query("SHOW COLUMNS FROM custom_fields LIKE 'sortable'")->fetch();
    $hasRow      = $pdo->query("SHOW COLUMNS FROM custom_fields LIKE 'grid_row'")->fetch();
    $hasCol      = $pdo->query("SHOW COLUMNS FROM custom_fields LIKE 'grid_col'")->fetch();
    $hasWidth    = $pdo->query("SHOW COLUMNS FROM custom_fields LIKE 'grid_width'")->fetch();

    $columns = ['content_type_id', 'name'];
    $placeholders = ['?', '?'];
    $params = [$content_type_id, $name];

    if ($hasLabel) {
        $columns[] = 'label';
        $placeholders[] = '?';
        $params[] = $label;
    }

    $columns[] = 'type';
    $placeholders[] = '?';
    $params[] = $type;

    $columns[] = 'options';
    $placeholders[] = '?';
    $params[] = $options;

    $columns[] = 'required';
    $placeholders[] = '?';
    $params[] = $required ? 1 : 0;

    if ($hasList) {
        $columns[] = 'show_in_list';
        $placeholders[] = '?';
        $params[] = $show_in_list ? 1 : 0;
    }

    if ($hasSortable) {
        $columns[] = 'sortable';
        $placeholders[] = '?';
        $params[] = $sortable ? 1 : 0;
    }

    if ($hasRow) {
        if ($grid_row === 0) {
            $grid_row = getNextFieldGridRow($content_type_id);
        }
        $columns[] = 'grid_row';
        $placeholders[] = '?';
        $params[] = $grid_row;
    }

    if ($hasCol) {
        $columns[] = 'grid_col';
        $placeholders[] = '?';
        $params[] = $grid_col;
    }

    if ($hasWidth) {
        $columns[] = 'grid_width';
        $placeholders[] = '?';
        $params[] = $grid_width;
    }

    $sql = 'INSERT INTO custom_fields (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $id = (int)$pdo->lastInsertId();
    logAuditAction('create', 'custom_field', $id, ['content_type_id' => $content_type_id, 'name' => $name]);
    return $id;
}

/**
 * Fetch a single custom field by id.
 *
 * @param int $id
 * @return array|null
 */
function getCustomField(int $id): ?array {
    $pdo = getPDO();
    $hasList = $pdo->query("SHOW COLUMNS FROM custom_fields LIKE 'show_in_list'")->fetch();
    $listExpr = $hasList ? 'show_in_list' : '0 AS show_in_list';
    $hasSortable = $pdo->query("SHOW COLUMNS FROM custom_fields LIKE 'sortable'")->fetch();
    $sortableExpr = $hasSortable ? 'sortable' : '1 AS sortable';
    $hasRow = $pdo->query("SHOW COLUMNS FROM custom_fields LIKE 'grid_row'")->fetch();
    $rowExpr = $hasRow ? 'grid_row' : '0 AS grid_row';
    $hasCol = $pdo->query("SHOW COLUMNS FROM custom_fields LIKE 'grid_col'")->fetch();
    $colExpr = $hasCol ? 'grid_col' : '0 AS grid_col';
    $hasWidth = $pdo->query("SHOW COLUMNS FROM custom_fields LIKE 'grid_width'")->fetch();

    $widthExpr = $hasWidth ? 'grid_width' : '12 AS grid_width';

    $stmt = $pdo->prepare("SELECT id, content_type_id, name, label, type, options, required, $listExpr, $sortableExpr, $rowExpr, $colExpr, $widthExpr FROM custom_fields WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Update an existing custom field.
 *
 * @param int $id
 * @param string $name
 * @param string $label
 * @param string $type
 * @param string $options
 * @param bool $required
 * @return void
 */
function updateCustomField(int $id, string $name, string $label, string $type, string $options = '', bool $required = false, bool $show_in_list = false, bool $sortable = true, int $grid_row = 0, int $grid_col = 0, int $grid_width = 12): void {
    $pdo = getPDO();
    $hasList = $pdo->query("SHOW COLUMNS FROM custom_fields LIKE 'show_in_list'")->fetch();
    $hasSortable = $pdo->query("SHOW COLUMNS FROM custom_fields LIKE 'sortable'")->fetch();
    $hasRow = $pdo->query("SHOW COLUMNS FROM custom_fields LIKE 'grid_row'")->fetch();
    $hasCol = $pdo->query("SHOW COLUMNS FROM custom_fields LIKE 'grid_col'")->fetch();
    $hasWidth = $pdo->query("SHOW COLUMNS FROM custom_fields LIKE 'grid_width'")->fetch();

    $sets = ['name = ?'];
    $params = [$name];

    $sets[] = 'label = ?';
    $params[] = $label;

    $sets[] = 'type = ?';
    $params[] = $type;

    $sets[] = 'options = ?';
    $params[] = $options;

    $sets[] = 'required = ?';
    $params[] = $required ? 1 : 0;

    if ($hasList) {
        $sets[] = 'show_in_list = ?';
        $params[] = $show_in_list ? 1 : 0;
    }

    if ($hasSortable) {
        $sets[] = 'sortable = ?';
        $params[] = $sortable ? 1 : 0;
    }

    if ($hasRow) {
        $sets[] = 'grid_row = ?';
        $params[] = $grid_row;
    }

    if ($hasCol) {
        $sets[] = 'grid_col = ?';
        $params[] = $grid_col;
    }

    if ($hasWidth) {
        $sets[] = 'grid_width = ?';
        $params[] = $grid_width;
    }

    $params[] = $id;
    $sql = 'UPDATE custom_fields SET ' . implode(', ', $sets) . ' WHERE id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    logAuditAction('update', 'custom_field', $id, ['name' => $name]);
}

/**
 * Delete a custom field by id.
 *
 * @param int $id
 * @return void
 */
function deleteCustomField(int $id): void {
    $pdo = getPDO();
    $stmt = $pdo->prepare('DELETE FROM custom_fields WHERE id = ?');
    $stmt->execute([$id]);
}

/**
 * Update grid layout info for a custom field if columns exist.
 */
function updateFieldLayout(int|string $id, int $typeId, int $row, int $col, int $width): void {
    $pdo = getPDO();
    if (is_numeric($id)) {
        $hasRow = $pdo->query("SHOW COLUMNS FROM custom_fields LIKE 'grid_row'")->fetch();
        $hasCol = $pdo->query("SHOW COLUMNS FROM custom_fields LIKE 'grid_col'")->fetch();
        $hasWidth = $pdo->query("SHOW COLUMNS FROM custom_fields LIKE 'grid_width'")->fetch();
        if ($hasRow && $hasCol && $hasWidth) {
            $stmt = $pdo->prepare('UPDATE custom_fields SET grid_row = ?, grid_col = ?, grid_width = ? WHERE id = ?');
            $stmt->execute([$row, $col, $width, (int)$id]);
        }
    } elseif ($id === 'title' || $id === 'body') {
        $prefix = $id === 'title' ? 'title' : 'body';
        $hasRow = $pdo->query("SHOW COLUMNS FROM content_types LIKE '{$prefix}_grid_row'")->fetch();
        $hasCol = $pdo->query("SHOW COLUMNS FROM content_types LIKE '{$prefix}_grid_col'")->fetch();
        $hasWidth = $pdo->query("SHOW COLUMNS FROM content_types LIKE '{$prefix}_grid_width'")->fetch();
        if ($hasRow && $hasCol && $hasWidth) {
            $stmt = $pdo->prepare("UPDATE content_types SET {$prefix}_grid_row = ?, {$prefix}_grid_col = ?, {$prefix}_grid_width = ? WHERE id = ?");
            $stmt->execute([$row, $col, $width, $typeId]);
        }
    } elseif (is_string($id) && str_starts_with($id, 'tax_')) {
        $taxId = (int) substr($id, 4);
        $hasRow = $pdo->query("SHOW COLUMNS FROM content_type_taxonomy LIKE 'grid_row'")->fetch();
        $hasCol = $pdo->query("SHOW COLUMNS FROM content_type_taxonomy LIKE 'grid_col'")->fetch();
        $hasWidth = $pdo->query("SHOW COLUMNS FROM content_type_taxonomy LIKE 'grid_width'")->fetch();
        if ($hasRow && $hasCol && $hasWidth) {
            $stmt = $pdo->prepare('UPDATE content_type_taxonomy SET grid_row = ?, grid_col = ?, grid_width = ? WHERE taxonomy_id = ? AND content_type_id = ?');
            $stmt->execute([$row, $col, $width, $taxId, $typeId]);
        }
    }
}

/**
 * Retrieve all taxonomies.
 *
 * @return array
 */
function getTaxonomies(): array {
    $pdo = getPDO();
    $stmt = $pdo->query('SELECT id, name, label FROM taxonomies ORDER BY id ASC');
    return $stmt->fetchAll();
}

/**
 * Fetch a single taxonomy by id.
 *
 * @param int $id
 * @return array|null
 */
function getTaxonomy(int $id): ?array {
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id, name, label FROM taxonomies WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Fetch a single taxonomy by slug.
 *
 * @param string $slug
 * @return array|null
 */
function getTaxonomyBySlug(string $slug): ?array {
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id, name, label FROM taxonomies WHERE LOWER(name) = LOWER(?)');
    $stmt->execute([$slug]);
    return $stmt->fetch() ?: null;
}

/**
 * Create a taxonomy.  Returns new id.
 *
 * @param string $name Slug
 * @param string $label Label
 * @return int
 */
function createTaxonomy(string $name, string $label): int {
    $pdo = getPDO();
    $stmt = $pdo->prepare('INSERT INTO taxonomies (name, label) VALUES (?, ?)');
    $stmt->execute([$name, $label]);
    $id = (int)$pdo->lastInsertId();
    logAuditAction('create', 'taxonomy', $id, ['name' => $name]);
    return $id;
}

/**
 * Update an existing taxonomy.
 *
 * @param int $id
 * @param string $name
 * @param string $label
 * @return void
 */
function updateTaxonomy(int $id, string $name, string $label): void {
    $pdo = getPDO();
    $stmt = $pdo->prepare('UPDATE taxonomies SET name = ?, label = ? WHERE id = ?');
    $stmt->execute([$name, $label, $id]);
    logAuditAction('update', 'taxonomy', $id, ['name' => $name]);
}

/**
 * Delete a taxonomy by id.
 *
 * @param int $id
 * @return void
 */
function deleteTaxonomy(int $id): void {
    $pdo = getPDO();
    $stmt = $pdo->prepare('DELETE FROM taxonomies WHERE id = ?');
    $stmt->execute([$id]);
}

/**
 * Count how many content entries reference a given taxonomy.
 *
 * @param int $taxonomy_id
 * @return int Number of associated content records
 */
function countContentByTaxonomy(int $taxonomy_id): int {
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM content_taxonomy WHERE taxonomy_id = ?');
    $stmt->execute([$taxonomy_id]);
    return (int)$stmt->fetchColumn();
}

/**
 * Retrieve taxonomy terms for a taxonomy id.
 *
 * @param int $taxonomy_id
 * @return array
 */
function getTerms(int $taxonomy_id): array {
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id, name FROM taxonomy_terms WHERE taxonomy_id = ? ORDER BY name ASC');
    $stmt->execute([$taxonomy_id]);
    return $stmt->fetchAll();
}

/**
 * Retrieve a single taxonomy term by id.
 *
 * @param int $term_id
 * @return array|null
 */
function getTerm(int $term_id): ?array {
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id, taxonomy_id, name FROM taxonomy_terms WHERE id = ?');
    $stmt->execute([$term_id]);
    return $stmt->fetch() ?: null;
}

/**
 * Create a taxonomy term.  Returns new id.
 *
 * @param int $taxonomy_id
 * @param string $term
 * @return int
 */
function createTerm(int $taxonomy_id, string $term): int {
    $pdo = getPDO();
    $stmt = $pdo->prepare('INSERT INTO taxonomy_terms (taxonomy_id, name) VALUES (?, ?)');
    $stmt->execute([$taxonomy_id, $term]);
    $id = (int)$pdo->lastInsertId();
    logAuditAction('create', 'taxonomy_term', $id, ['taxonomy_id' => $taxonomy_id, 'name' => $term]);
    return $id;
}

/**
 * Update a taxonomy term's name.
 *
 * @param int $term_id
 * @param string $term
 * @return void
 */
function updateTerm(int $term_id, string $term): void {
    $pdo = getPDO();
    $stmt = $pdo->prepare('UPDATE taxonomy_terms SET name = ? WHERE id = ?');
    $stmt->execute([$term, $term_id]);
    logAuditAction('update', 'taxonomy_term', $term_id, ['name' => $term]);
}

/**
 * Delete a taxonomy term by id.
 *
 * @param int $term_id
 * @return void
 */
function deleteTerm(int $term_id): void {
    $pdo = getPDO();
    $stmt = $pdo->prepare('DELETE FROM taxonomy_terms WHERE id = ?');
    $stmt->execute([$term_id]);
}

function getAccountingAdditionalFieldScopeOptions(): array {
    return [
        'client' => 'Clientes',
        'supplier' => 'Fornecedores',
    ];
}

function getAccountingAdditionalFieldTypeOptions(): array {
    return [
        'text' => 'Texto',
        'textarea' => 'Texto longo',
        'password' => 'Password',
        'integer' => 'Numero inteiro',
        'decimal' => 'Numero decimal',
        'select' => 'Select',
        'multiselect' => 'Multi-select',
        'boolean_select' => 'Sim/Não (select)',
    ];
}

function getAccountingAdditionalFieldBootstrapColumnOptions(): array {
    return [
        12 => '12/12 - largura total',
        9 => '9/12',
        8 => '8/12',
        6 => '6/12 - meia largura',
        4 => '4/12 - tres colunas',
        3 => '3/12 - quatro colunas',
        2 => '2/12',
        1 => '1/12',
    ];
}

function getAccountingAdditionalFieldBootstrapOffsetOptions(): array {
    return [
        0 => '0/12 - sem offset',
        1 => '1/12',
        2 => '2/12',
        3 => '3/12',
        4 => '4/12',
        5 => '5/12',
        6 => '6/12',
        7 => '7/12',
        8 => '8/12',
        9 => '9/12',
        10 => '10/12',
        11 => '11/12',
    ];
}

function normalizeAccountingAdditionalFieldBootstrapColumn($value): int {
    $allowed = array_map('intval', array_keys(getAccountingAdditionalFieldBootstrapColumnOptions()));
    $normalized = (int) $value;
    if (!in_array($normalized, $allowed, true)) {
        return 6;
    }
    return $normalized;
}

function normalizeAccountingAdditionalFieldBootstrapOffset($value): int {
    $allowed = array_map('intval', array_keys(getAccountingAdditionalFieldBootstrapOffsetOptions()));
    $normalized = (int) $value;
    if (!in_array($normalized, $allowed, true)) {
        return 0;
    }
    return $normalized;
}

function normalizeAccountingAdditionalFieldSlug(string $value): string {
    $value = trim($value);
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii) && $ascii !== '') {
            $value = $ascii;
        }
    }
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    $value = trim((string) $value, '_');
    return $value;
}

function getAccountingAdditionalFieldTaxonomies(): array {
    if (!hasTable('accounting_additional_field_taxonomies')) {
        return [];
    }
    $pdo = getPDO();
    $stmt = $pdo->query('SELECT id, name, label, created_at, updated_at FROM accounting_additional_field_taxonomies ORDER BY label ASC, id ASC');
    return $stmt->fetchAll();
}

function getAccountingAdditionalFieldTaxonomy(int $id): ?array {
    if (!hasTable('accounting_additional_field_taxonomies')) {
        return null;
    }
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id, name, label, created_at, updated_at FROM accounting_additional_field_taxonomies WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function getAccountingAdditionalFieldTaxonomyBySlug(string $slug): ?array {
    if (!hasTable('accounting_additional_field_taxonomies')) {
        return null;
    }
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id, name, label, created_at, updated_at FROM accounting_additional_field_taxonomies WHERE LOWER(name) = LOWER(?)');
    $stmt->execute([$slug]);
    return $stmt->fetch() ?: null;
}

function createAccountingAdditionalFieldTaxonomy(string $name, string $label): int {
    $pdo = getPDO();
    $stmt = $pdo->prepare('INSERT INTO accounting_additional_field_taxonomies (name, label) VALUES (?, ?)');
    $stmt->execute([$name, $label]);
    $id = (int) $pdo->lastInsertId();
    logAuditAction('create', 'accounting_additional_field_taxonomy', $id, ['name' => $name]);
    return $id;
}

function updateAccountingAdditionalFieldTaxonomy(int $id, string $name, string $label): void {
    $pdo = getPDO();
    $stmt = $pdo->prepare('UPDATE accounting_additional_field_taxonomies SET name = ?, label = ? WHERE id = ?');
    $stmt->execute([$name, $label, $id]);
    logAuditAction('update', 'accounting_additional_field_taxonomy', $id, ['name' => $name]);
}

function deleteAccountingAdditionalFieldTaxonomy(int $id): void {
    $pdo = getPDO();
    $stmt = $pdo->prepare('DELETE FROM accounting_additional_field_taxonomies WHERE id = ?');
    $stmt->execute([$id]);
    logAuditAction('delete', 'accounting_additional_field_taxonomy', $id);
}

function countAccountingAdditionalFieldsByTaxonomy(int $taxonomyId): int {
    if (!hasTable('accounting_additional_fields')) {
        return 0;
    }
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM accounting_additional_fields WHERE taxonomy_id = ?');
    $stmt->execute([$taxonomyId]);
    return (int) $stmt->fetchColumn();
}

function getAccountingAdditionalFieldTerms(int $taxonomyId): array {
    if (!hasTable('accounting_additional_field_taxonomy_terms')) {
        return [];
    }
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id, taxonomy_id, name, sort_order, created_at, updated_at FROM accounting_additional_field_taxonomy_terms WHERE taxonomy_id = ? ORDER BY sort_order ASC, name ASC, id ASC');
    $stmt->execute([$taxonomyId]);
    return $stmt->fetchAll();
}

function getAccountingAdditionalFieldTerm(int $id): ?array {
    if (!hasTable('accounting_additional_field_taxonomy_terms')) {
        return null;
    }
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id, taxonomy_id, name, sort_order, created_at, updated_at FROM accounting_additional_field_taxonomy_terms WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function createAccountingAdditionalFieldTerm(int $taxonomyId, string $name, int $sortOrder = 0): int {
    $pdo = getPDO();
    $stmt = $pdo->prepare('INSERT INTO accounting_additional_field_taxonomy_terms (taxonomy_id, name, sort_order) VALUES (?, ?, ?)');
    $stmt->execute([$taxonomyId, $name, $sortOrder]);
    $id = (int) $pdo->lastInsertId();
    logAuditAction('create', 'accounting_additional_field_taxonomy_term', $id, ['taxonomy_id' => $taxonomyId, 'name' => $name]);
    return $id;
}

function updateAccountingAdditionalFieldTerm(int $id, string $name, int $sortOrder = 0): void {
    $pdo = getPDO();
    $stmt = $pdo->prepare('UPDATE accounting_additional_field_taxonomy_terms SET name = ?, sort_order = ? WHERE id = ?');
    $stmt->execute([$name, $sortOrder, $id]);
    logAuditAction('update', 'accounting_additional_field_taxonomy_term', $id, ['name' => $name]);
}

function deleteAccountingAdditionalFieldTerm(int $id): void {
    $pdo = getPDO();
    $stmt = $pdo->prepare('DELETE FROM accounting_additional_field_taxonomy_terms WHERE id = ?');
    $stmt->execute([$id]);
    logAuditAction('delete', 'accounting_additional_field_taxonomy_term', $id);
}

function countAccountingAdditionalValueUsageByTerm(int $termId): int {
    if (!hasTable('accounting_entity_additional_values') || !hasTable('accounting_additional_fields')) {
        return 0;
    }
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM accounting_entity_additional_values v
         INNER JOIN accounting_additional_fields f ON f.id = v.field_id
         WHERE f.type = 'taxonomy'
           AND TRIM(COALESCE(v.value, '')) = ?"
    );
    $stmt->execute([(string) $termId]);
    return (int) $stmt->fetchColumn();
}

function getAccountingAdditionalFields(?string $scope = null): array {
    if (!hasTable('accounting_additional_fields')) {
        return [];
    }
    $pdo = getPDO();
    $bootstrapColExpr = hasColumn('accounting_additional_fields', 'bootstrap_col') ? 'f.bootstrap_col' : '6';
    $bootstrapOffsetExpr = hasColumn('accounting_additional_fields', 'bootstrap_offset') ? 'f.bootstrap_offset' : '0';
    $sql = "SELECT f.id, f.scope, f.name, f.label, f.type, f.options, f.taxonomy_id, f.required, f.sort_order,
                   {$bootstrapColExpr} AS bootstrap_col,
                   {$bootstrapOffsetExpr} AS bootstrap_offset,
                   t.label AS taxonomy_label
            FROM accounting_additional_fields f
            LEFT JOIN accounting_additional_field_taxonomies t ON t.id = f.taxonomy_id";
    $params = [];
    if ($scope !== null && $scope !== '') {
        $sql .= ' WHERE f.scope = ?';
        $params[] = $scope;
    }
    $sql .= ' ORDER BY f.scope ASC, f.sort_order ASC, f.label ASC, f.id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getAccountingAdditionalField(int $id): ?array {
    if (!hasTable('accounting_additional_fields')) {
        return null;
    }
    $pdo = getPDO();
    $bootstrapColExpr = hasColumn('accounting_additional_fields', 'bootstrap_col') ? 'f.bootstrap_col' : '6';
    $bootstrapOffsetExpr = hasColumn('accounting_additional_fields', 'bootstrap_offset') ? 'f.bootstrap_offset' : '0';
    $stmt = $pdo->prepare(
        "SELECT f.id, f.scope, f.name, f.label, f.type, f.options, f.taxonomy_id, f.required, f.sort_order,
                {$bootstrapColExpr} AS bootstrap_col,
                {$bootstrapOffsetExpr} AS bootstrap_offset,
                t.label AS taxonomy_label
         FROM accounting_additional_fields f
         LEFT JOIN accounting_additional_field_taxonomies t ON t.id = f.taxonomy_id
         WHERE f.id = ?"
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function getAccountingAdditionalFieldByScopeAndName(string $scope, string $name): ?array {
    if (!hasTable('accounting_additional_fields')) {
        return null;
    }
    $pdo = getPDO();
    $bootstrapColExpr = hasColumn('accounting_additional_fields', 'bootstrap_col') ? 'f.bootstrap_col' : '6';
    $bootstrapOffsetExpr = hasColumn('accounting_additional_fields', 'bootstrap_offset') ? 'f.bootstrap_offset' : '0';
    $stmt = $pdo->prepare(
        "SELECT f.id, f.scope, f.name, f.label, f.type, f.options, f.taxonomy_id, f.required, f.sort_order,
                {$bootstrapColExpr} AS bootstrap_col,
                {$bootstrapOffsetExpr} AS bootstrap_offset,
                t.label AS taxonomy_label
         FROM accounting_additional_fields f
         LEFT JOIN accounting_additional_field_taxonomies t ON t.id = f.taxonomy_id
         WHERE f.scope = ? AND LOWER(f.name) = LOWER(?)"
    );
    $stmt->execute([$scope, $name]);
    return $stmt->fetch() ?: null;
}

function createAccountingAdditionalField(string $scope, string $name, string $label, string $type, string $options = '', ?int $taxonomyId = null, bool $required = false, int $sortOrder = 0, int $bootstrapCol = 6, int $bootstrapOffset = 0): int {
    $pdo = getPDO();
    $bootstrapCol = normalizeAccountingAdditionalFieldBootstrapColumn($bootstrapCol);
    $bootstrapOffset = normalizeAccountingAdditionalFieldBootstrapOffset($bootstrapOffset);
    if (hasColumn('accounting_additional_fields', 'bootstrap_col') && hasColumn('accounting_additional_fields', 'bootstrap_offset')) {
        $stmt = $pdo->prepare(
            'INSERT INTO accounting_additional_fields (scope, name, label, type, options, taxonomy_id, required, sort_order, bootstrap_col, bootstrap_offset)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$scope, $name, $label, $type, $options, $taxonomyId, $required ? 1 : 0, $sortOrder, $bootstrapCol, $bootstrapOffset]);
    } elseif (hasColumn('accounting_additional_fields', 'bootstrap_col')) {
        $stmt = $pdo->prepare(
            'INSERT INTO accounting_additional_fields (scope, name, label, type, options, taxonomy_id, required, sort_order, bootstrap_col)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$scope, $name, $label, $type, $options, $taxonomyId, $required ? 1 : 0, $sortOrder, $bootstrapCol]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO accounting_additional_fields (scope, name, label, type, options, taxonomy_id, required, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$scope, $name, $label, $type, $options, $taxonomyId, $required ? 1 : 0, $sortOrder]);
    }
    $id = (int) $pdo->lastInsertId();
    logAuditAction('create', 'accounting_additional_field', $id, ['scope' => $scope, 'name' => $name, 'type' => $type, 'bootstrap_col' => $bootstrapCol, 'bootstrap_offset' => $bootstrapOffset]);
    return $id;
}

function updateAccountingAdditionalField(int $id, string $scope, string $name, string $label, string $type, string $options = '', ?int $taxonomyId = null, bool $required = false, int $sortOrder = 0, int $bootstrapCol = 6, int $bootstrapOffset = 0): void {
    $pdo = getPDO();
    $bootstrapCol = normalizeAccountingAdditionalFieldBootstrapColumn($bootstrapCol);
    $bootstrapOffset = normalizeAccountingAdditionalFieldBootstrapOffset($bootstrapOffset);
    if (hasColumn('accounting_additional_fields', 'bootstrap_col') && hasColumn('accounting_additional_fields', 'bootstrap_offset')) {
        $stmt = $pdo->prepare(
            'UPDATE accounting_additional_fields
             SET scope = ?, name = ?, label = ?, type = ?, options = ?, taxonomy_id = ?, required = ?, sort_order = ?, bootstrap_col = ?, bootstrap_offset = ?
             WHERE id = ?'
        );
        $stmt->execute([$scope, $name, $label, $type, $options, $taxonomyId, $required ? 1 : 0, $sortOrder, $bootstrapCol, $bootstrapOffset, $id]);
    } elseif (hasColumn('accounting_additional_fields', 'bootstrap_col')) {
        $stmt = $pdo->prepare(
            'UPDATE accounting_additional_fields
             SET scope = ?, name = ?, label = ?, type = ?, options = ?, taxonomy_id = ?, required = ?, sort_order = ?, bootstrap_col = ?
             WHERE id = ?'
        );
        $stmt->execute([$scope, $name, $label, $type, $options, $taxonomyId, $required ? 1 : 0, $sortOrder, $bootstrapCol, $id]);
    } else {
        $stmt = $pdo->prepare(
            'UPDATE accounting_additional_fields
             SET scope = ?, name = ?, label = ?, type = ?, options = ?, taxonomy_id = ?, required = ?, sort_order = ?
             WHERE id = ?'
        );
        $stmt->execute([$scope, $name, $label, $type, $options, $taxonomyId, $required ? 1 : 0, $sortOrder, $id]);
    }
    logAuditAction('update', 'accounting_additional_field', $id, ['scope' => $scope, 'name' => $name, 'type' => $type, 'bootstrap_col' => $bootstrapCol, 'bootstrap_offset' => $bootstrapOffset]);
}

function deleteAccountingAdditionalField(int $id): void {
    $pdo = getPDO();
    $stmt = $pdo->prepare('DELETE FROM accounting_additional_fields WHERE id = ?');
    $stmt->execute([$id]);
    logAuditAction('delete', 'accounting_additional_field', $id);
}

function getAccountingEntityAdditionalValues(int $entityId): array {
    if (!hasTable('accounting_entity_additional_values')) {
        return [];
    }
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT field_id, value FROM accounting_entity_additional_values WHERE entity_id = ?');
    $stmt->execute([$entityId]);
    $values = [];
    foreach ($stmt->fetchAll() as $row) {
        $values[(int) $row['field_id']] = (string) ($row['value'] ?? '');
    }
    return $values;
}

function saveAccountingEntityAdditionalValue(int $entityId, int $fieldId, ?string $value): void {
    if (!hasTable('accounting_entity_additional_values')) {
        return;
    }
    $pdo = getPDO();
    $normalizedValue = trim((string) $value);
    if ($normalizedValue === '') {
        $stmt = $pdo->prepare('DELETE FROM accounting_entity_additional_values WHERE entity_id = ? AND field_id = ?');
        $stmt->execute([$entityId, $fieldId]);
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO accounting_entity_additional_values (entity_id, field_id, value)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$entityId, $fieldId, $normalizedValue]);
}

function getAccountingAdditionalFieldOptions(array $field): array {
    $type = trim((string) ($field['type'] ?? ''));
    if ($type === 'boolean_select') {
        return [
            ['value' => '1', 'label' => 'Sim'],
            ['value' => '0', 'label' => 'Nao'],
        ];
    }

    if ($type === 'select' || $type === 'multiselect') {
        $rawOptions = preg_split('/\r\n|\r|\n/', (string) ($field['options'] ?? '')) ?: [];
        $options = [];
        foreach ($rawOptions as $option) {
            $option = trim((string) $option);
            if ($option === '') {
                continue;
            }
            $options[] = ['value' => $option, 'label' => $option];
        }
        return $options;
    }

    if ($type === 'taxonomy') {
        $taxonomyId = (int) ($field['taxonomy_id'] ?? 0);
        $terms = getAccountingAdditionalFieldTerms($taxonomyId);
        return array_map(function (array $term): array {
            return [
                'value' => (string) $term['id'],
                'label' => (string) $term['name'],
            ];
        }, $terms);
    }

    return [];
}

function normalizeAccountingAdditionalFieldSubmittedValue(array $field, $rawInput): string {
    $type = trim((string) ($field['type'] ?? 'text'));
    $allowedOptions = getAccountingAdditionalFieldOptions($field);
    $allowedValues = array_map('strval', array_column($allowedOptions, 'value'));

    if ($type === 'multiselect') {
        $values = is_array($rawInput) ? $rawInput : [];
        $normalizedValues = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value === '' || !in_array($value, $allowedValues, true)) {
                continue;
            }
            if (!in_array($value, $normalizedValues, true)) {
                $normalizedValues[] = $value;
            }
        }
        return $normalizedValues ? json_encode($normalizedValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    }

    $value = trim((string) (is_array($rawInput) ? '' : $rawInput));
    if ($type === 'integer') {
        if ($value === '') {
            return '';
        }
        return preg_match('/^-?\d+$/', $value) ? $value : '';
    }

    if ($type === 'decimal') {
        if ($value === '') {
            return '';
        }
        $normalizedDecimal = str_replace(',', '.', $value);
        return preg_match('/^-?\d+(?:\.\d+)?$/', $normalizedDecimal) ? $normalizedDecimal : '';
    }

    if (($type === 'select' || $type === 'taxonomy' || $type === 'boolean_select') && $value !== '') {
        if (!in_array($value, $allowedValues, true)) {
            return '';
        }
    }

    return $value;
}

function getAccountingAdditionalFieldStoredValues(array $field, ?string $storedValue): array {
    $type = trim((string) ($field['type'] ?? 'text'));
    $storedValue = (string) $storedValue;
    if ($type !== 'multiselect') {
        return $storedValue === '' ? [] : [$storedValue];
    }

    $decoded = json_decode($storedValue, true);
    if (!is_array($decoded)) {
        return $storedValue === '' ? [] : [$storedValue];
    }

    $values = [];
    foreach ($decoded as $value) {
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
        $values[] = $value;
    }
    return $values;
}

/**
 * Retrieve taxonomies assigned to a given content type.
 *
 * @param int $content_type_id
 * @return array
 */
function getTaxonomiesForContentType(int $content_type_id): array {
    $pdo = getPDO();
    $hasRow = $pdo->query("SHOW COLUMNS FROM content_type_taxonomy LIKE 'grid_row'")->fetch();
    $hasCol = $pdo->query("SHOW COLUMNS FROM content_type_taxonomy LIKE 'grid_col'")->fetch();
    $hasWidth = $pdo->query("SHOW COLUMNS FROM content_type_taxonomy LIKE 'grid_width'")->fetch();
    $rowExpr = $hasRow ? 'ctt.grid_row' : '0 AS grid_row';
    $colExpr = $hasCol ? 'ctt.grid_col' : '0 AS grid_col';
    $widthExpr = $hasWidth ? 'ctt.grid_width' : '12 AS grid_width';
    $stmt = $pdo->prepare("SELECT t.id, t.name, t.label, $rowExpr, $colExpr, $widthExpr FROM taxonomies t JOIN content_type_taxonomy ctt ON t.id = ctt.taxonomy_id WHERE ctt.content_type_id = ? ORDER BY t.id ASC");
    $stmt->execute([$content_type_id]);
    return $stmt->fetchAll();
}

/**
 * Assign a set of taxonomies to a content type.
 * Existing assignments are removed before inserting the new ones.
 *
 * @param int $content_type_id
 * @param array $taxonomy_ids
 * @return void
 */
function setContentTypeTaxonomies(int $content_type_id, array $taxonomy_ids): void {
    $pdo = getPDO();
    $pdo->beginTransaction();
    $del = $pdo->prepare('DELETE FROM content_type_taxonomy WHERE content_type_id = ?');
    $del->execute([$content_type_id]);
    $ins = $pdo->prepare('INSERT INTO content_type_taxonomy (content_type_id, taxonomy_id) VALUES (?, ?)');
    foreach ($taxonomy_ids as $tid) {
        $ins->execute([$content_type_id, $tid]);
    }
    $pdo->commit();
}

/**
 * Create a content entry.  The function inserts a row into the
 * `content` table and returns the new content id.
 *
 * @param int $content_type_id
 * @param int $user_id
 * @param string $title
 * @param string|null $body Optional body text
 * @return int
 */
function createContent(int $content_type_id, int $user_id, string $title, ?string $body = null): int {
    $pdo = getPDO();
    $stmt = $pdo->prepare('INSERT INTO content (content_type_id, user_id, title, body, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([$content_type_id, $user_id, $title, $body]);
    $id = (int)$pdo->lastInsertId();
    logAuditAction('create', 'content', $id, ['content_type_id' => $content_type_id, 'title' => $title]);
    return $id;
}

/**
 * Save a value for a custom field on a content entry.
 *
 * @param int $content_id
 * @param int $field_id
 * @param string|array|null $value
 * @return void
 */
function saveCustomValue(int $content_id, int $field_id, $value): void {
    $values = [];
    $collect = function ($item) use (&$collect, &$values): void {
        if ($item === null) {
            return;
        }
        if (is_array($item)) {
            foreach ($item as $subItem) {
                $collect($subItem);
            }
            return;
        }
        if (is_scalar($item) || (is_object($item) && method_exists($item, '__toString'))) {
            $values[] = (string)$item;
        }
    };

    $collect($value);

    if (empty($values)) {
        return;
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare('INSERT INTO custom_values (content_id, field_id, value) VALUES (?, ?, ?)');
    foreach ($values as $scalarValue) {
        $stmt->execute([$content_id, $field_id, $scalarValue]);
    }
}

/**
 * Associate content with taxonomy terms.  Accepts an array of term ids
 * and inserts rows into the join table.  Existing assignments are
 * removed first.
 *
 * @param int $content_id
 * @param int $taxonomy_id
 * @param array $term_ids
 * @return void
 */
function setContentTaxonomyTerms(int $content_id, int $taxonomy_id, array $term_ids): void {
    $pdo = getPDO();
    // Clear existing terms for this taxonomy/content combination
    $delete = $pdo->prepare('DELETE FROM content_taxonomy WHERE content_id = ? AND taxonomy_id = ?');
    $delete->execute([$content_id, $taxonomy_id]);
    // Insert new ones
    $insert = $pdo->prepare('INSERT INTO content_taxonomy (content_id, taxonomy_id, term_id) VALUES (?, ?, ?)');
    foreach ($term_ids as $term_id) {
        $insert->execute([$content_id, $taxonomy_id, $term_id]);
    }
}

/**
 * Fetch a single content entry by id.
 *
 * @param int $id
 * @return array|null
 */
function getContent(int $id): ?array {
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id, content_type_id, user_id, title, body FROM content WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Update basic fields of a content entry.
 *
 * @param int $id
 * @param string $title
 * @param string|null $body
 * @return void
 */
function updateContent(int $id, string $title, ?string $body = null): void {
    $pdo = getPDO();
    $stmt = $pdo->prepare('UPDATE content SET title = ?, body = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$title, $body, $id]);
    logAuditAction('update', 'content', $id, ['title' => $title]);
}

/**
 * Delete a content entry by id.
 *
 * @param int $id
 * @return void
 */
function deleteContent(int $id): void {
    $pdo = getPDO();
    $stmt = $pdo->prepare('DELETE FROM content WHERE id = ?');
    $stmt->execute([$id]);
}

/**
 * Retrieve custom field values for a content entry keyed by field id.
 *
 * @param int $content_id
 * @return array<int,string>
 */
function getCustomValuesForContent(int $content_id): array {
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT field_id, value FROM custom_values WHERE content_id = ?');
    $stmt->execute([$content_id]);
    $values = [];
    foreach ($stmt->fetchAll() as $row) {
        $fid = (int)$row['field_id'];
        if (!isset($values[$fid])) {
            $values[$fid] = [];
        }
        $values[$fid][] = $row['value'];
    }
    return $values;
}

/**
 * Remove all custom values for a content entry.
 *
 * @param int $content_id
 * @return void
 */
function deleteCustomValuesForContent(int $content_id): void {
    $pdo = getPDO();
    $stmt = $pdo->prepare('DELETE FROM custom_values WHERE content_id = ?');
    $stmt->execute([$content_id]);
}

/**
 * Retrieve taxonomy term assignments for a content entry, grouped by taxonomy id.
 *
 * @param int $content_id
 * @return array<int,array<int>>
 */
function getContentTaxonomy(int $content_id): array {
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT taxonomy_id, term_id FROM content_taxonomy WHERE content_id = ?');
    $stmt->execute([$content_id]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $taxId = (int)$row['taxonomy_id'];
        $map[$taxId][] = (int)$row['term_id'];
    }
    return $map;
}

/**
 * Retrieve content entries of a given type along with their custom
 * values and assigned taxonomy terms.  This function returns a flat
 * structure with each custom field stored as a key in the 'fields'
 * array and each taxonomy stored in 'taxonomies' keyed by taxonomy
 * slug.  It's intended for display in list views.
 *
 * @param int $content_type_id
 * @return array
 */
function getContentList(int $content_type_id, array $filters = []): array {
    $pdo = getPDO();

    $sql = 'SELECT c.id, c.title, c.created_at, u.username AS author_name FROM content c JOIN users u ON c.user_id = u.id';
    $params = [];

    $i = 0;
    foreach ($filters as $fieldId => $value) {
        if (strpos((string)$fieldId, 'tax_') === 0) {
            $alias = 'ct' . $i++;
            $taxId = (int)substr($fieldId, 4);
            $sql .= " JOIN content_taxonomy $alias ON $alias.content_id = c.id AND $alias.taxonomy_id = ? AND $alias.term_id = ?";
            $params[] = $taxId;
            $params[] = $value;
        } else {
            $alias = 'cf' . $i++;
            $sql .= " JOIN custom_values $alias ON $alias.content_id = c.id AND $alias.field_id = ? AND $alias.value = ?";
            $params[] = (int)$fieldId;
            $params[] = $value;
        }

    }

    $sql .= ' WHERE c.content_type_id = ? ORDER BY c.id DESC';
    $params[] = $content_type_id;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $contents = $stmt->fetchAll();

    $fields = getCustomFields($content_type_id);
    $taxonomies = getTaxonomies();

    foreach ($contents as &$content) {
        $cstmt = $pdo->prepare('SELECT cv.field_id, cv.value FROM custom_values cv WHERE cv.content_id = ?');
        $cstmt->execute([$content['id']]);
        $content['fields'] = $cstmt->fetchAll();

        $tstmt = $pdo->prepare('SELECT ct.taxonomy_id, tt.name AS term_name FROM content_taxonomy ct LEFT JOIN taxonomy_terms tt ON ct.term_id = tt.id WHERE ct.content_id = ?');
        $tstmt->execute([$content['id']]);
        $content['taxonomies'] = $tstmt->fetchAll();
    }

    return $contents;
}

/**
 * Retrieve a list of taxonomy names associated with a content type.
 * This helper is used when building forms for adding new content.  It
 * returns all taxonomies; you can decide which to include in your UI.
 *
 * @return array
 */
function getAllTaxonomies(): array {
    return getTaxonomies();
}

?>
