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

function getPendingMigrationsSummary(bool $forceRefresh = false): array {
    startSession();
    $cacheKey = 'pending_migrations_summary';
    $cacheTtl = 60;
    if (
        !$forceRefresh
        && isset($_SESSION[$cacheKey]['generated_at'], $_SESSION[$cacheKey]['data'])
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
    $files = getMigrationFilesList();
    if (!$files) {
        $_SESSION[$cacheKey] = ['generated_at' => time(), 'data' => $summary];
        return $summary;
    }

    $companiesFile = __DIR__ . '/data/companies.php';
    $companies = is_file($companiesFile) ? (require $companiesFile) : [];
    if (!is_array($companies)) {
        $_SESSION[$cacheKey] = ['generated_at' => time(), 'data' => $summary];
        return $summary;
    }

    foreach ($companies as $nif => $cfg) {
        if (!is_array($cfg) || !empty($cfg['skip_migrations'])) {
            continue;
        }
        $label = $cfg['slug'] ?? (string) $nif;
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

    $_SESSION[$cacheKey] = ['generated_at' => time(), 'data' => $summary];
    return $summary;
}

function runProjectMigrationsFromUi(): array {
    $phpBinary = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $script = __DIR__ . '/scripts/migrate.php';
    if (!is_file($script)) {
        return ['ok' => false, 'output' => ['Script de migracoes nao encontrado.']];
    }
    $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($script) . ' 2>&1';
    $output = [];
    $exitCode = 1;
    exec($cmd, $output, $exitCode);
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
    if ($raw === null || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
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
