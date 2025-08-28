<?php
// Common functions for the CMS.  This file provides helpers for session
// management, user authentication, and CRUD operations on the various
// entities used by the system (content types, fields, taxonomies, terms,
// and content entries).  It builds on the database connection helper
// defined in db.php.  All functions that touch the database return
// prepared statements or plain data arrays; they throw exceptions on
// failure so callers can decide how to handle errors.

require_once __DIR__ . '/db.php';

/**
 * Start a session if it hasn't been started yet.  This helper uses
 * session cookies with the HttpOnly flag for security.  It does not
 * change existing session behaviour if a session is already active.
 */
function startSession() {
    if (session_status() === PHP_SESSION_NONE) {
        $params = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => $params['lifetime'],
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
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
 * Enforce authentication.  If the user is not logged in they will be
 * redirected to the login page.  After successful login they'll be
 * returned to the originally requested page via the `redirect` query
 * parameter.
 */
function requireLogin() {
    startSession();
    if (!isLoggedIn()) {
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: login.php?redirect=' . $redirect);
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
    $stmt = $pdo->prepare('SELECT id, password FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password'])) {
        // Regenerate session ID to prevent fixation attacks
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        return true;
    }
    return false;
}

/**
 * Log out the current user by destroying the session and clearing
 * cookies.  After calling this function you should redirect the
 * browser to a public page.
 */
function logoutUser() {
    startSession();
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
    $stmt = $pdo->prepare('SELECT id, username FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

/**
 * Retrieve all content types.
 *
 * @return array
 */
function getContentTypes(): array {
    $pdo = getPDO();
    $stmt = $pdo->query('SELECT id, name, label FROM content_types ORDER BY id ASC');
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
    $stmt = $pdo->prepare('SELECT id, name, label FROM content_types WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Create a new content type.  Returns the id of the new row.
 *
 * @param string $name Slug used internally
 * @param string $label Human-readable label
 * @return int
 */
function createContentType(string $name, string $label): int {
    $pdo = getPDO();
    $stmt = $pdo->prepare('INSERT INTO content_types (name, label) VALUES (?, ?)');
    $stmt->execute([$name, $label]);
    return (int)$pdo->lastInsertId();
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

    $stmt = $pdo->prepare("SELECT id, name, $labelExpr, type, options, required FROM custom_fields WHERE content_type_id = ? ORDER BY id ASC");
    $stmt->execute([$content_type_id]);
    return $stmt->fetchAll();
}

/**
 * Create a custom field for a content type.
 *
 * @param int $content_type_id
 * @param string $name Internal slug
 * @param string $label Display label
 * @param string $type One of: text, textarea, number, date, select
 * @param string $options Comma-separated values for select type (empty otherwise)
 * @param bool $required Whether the field is mandatory
 * @return int
 */
function createCustomField(int $content_type_id, string $name, string $label, string $type, string $options = '', bool $required = false): int {
    $pdo = getPDO();

    // If the table doesn't have a "label" column (older schema), insert
    // without it.  This keeps the function compatible with both schema
    // versions and avoids SQL errors during field creation.
    $hasLabel = $pdo->query("SHOW COLUMNS FROM custom_fields LIKE 'label'")->fetch();
    if ($hasLabel) {
        $stmt = $pdo->prepare('INSERT INTO custom_fields (content_type_id, name, label, type, options, required) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$content_type_id, $name, $label, $type, $options, $required ? 1 : 0]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO custom_fields (content_type_id, name, type, options, required) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$content_type_id, $name, $type, $options, $required ? 1 : 0]);
    }

    return (int)$pdo->lastInsertId();
}

/**
 * Fetch a single custom field by id.
 *
 * @param int $id
 * @return array|null
 */
function getCustomField(int $id): ?array {
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id, content_type_id, name, label, type, options, required FROM custom_fields WHERE id = ?');
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
function updateCustomField(int $id, string $name, string $label, string $type, string $options = '', bool $required = false): void {
    $pdo = getPDO();
    $stmt = $pdo->prepare('UPDATE custom_fields SET name = ?, label = ?, type = ?, options = ?, required = ? WHERE id = ?');
    $stmt->execute([$name, $label, $type, $options, $required ? 1 : 0, $id]);
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
    return (int)$pdo->lastInsertId();
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
    return (int)$pdo->lastInsertId();
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
    return (int)$pdo->lastInsertId();
}

/**
 * Save a value for a custom field on a content entry.
 *
 * @param int $content_id
 * @param int $field_id
 * @param string $value
 * @return void
 */
function saveCustomValue(int $content_id, int $field_id, string $value): void {
    $pdo = getPDO();
    $stmt = $pdo->prepare('INSERT INTO custom_values (content_id, field_id, value) VALUES (?, ?, ?)');
    $stmt->execute([$content_id, $field_id, $value]);
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
 * Retrieve content entries of a given type along with their custom
 * values and assigned taxonomy terms.  This function returns a flat
 * structure with each custom field stored as a key in the 'fields'
 * array and each taxonomy stored in 'taxonomies' keyed by taxonomy
 * slug.  It's intended for display in list views.
 *
 * @param int $content_type_id
 * @return array
 */
function getContentList(int $content_type_id): array {
    $pdo = getPDO();
    // Fetch basic content
    $stmt = $pdo->prepare('SELECT c.id, c.title, c.created_at, u.username AS author_name FROM content c JOIN users u ON c.user_id = u.id WHERE c.content_type_id = ? ORDER BY c.id DESC');
    $stmt->execute([$content_type_id]);
    $contents = $stmt->fetchAll();
    // Preload fields definitions and taxonomy definitions
    $fields = getCustomFields($content_type_id);
    $taxonomies = getTaxonomies();
    // For each content entry, fetch custom values and terms
    foreach ($contents as &$content) {
        // Fetch custom values
        $cstmt = $pdo->prepare('SELECT cv.field_id, cv.value FROM custom_values cv WHERE cv.content_id = ?');
        $cstmt->execute([$content['id']]);
        $content['fields'] = $cstmt->fetchAll();
        // Fetch taxonomy assignments
        $tstmt = $pdo->prepare('SELECT ct.taxonomy_id, tt.name AS term_name FROM content_taxonomy ct JOIN taxonomy_terms tt ON ct.term_id = tt.id WHERE ct.content_id = ?');
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