<?php
// Database configuration. Adjust these constants according to your environment.
//
// Create a MySQL database called "cms" and a user with privileges on it.
// Then update the DB_USER and DB_PASS constants below.  The rest of this
// application will use these settings to establish a PDO connection.

define('DB_HOST', 'localhost');
define('DB_NAME', 'cms');
// Updated database credentials for local development.  In production
// you should create a dedicated database user with limited privileges.
define('DB_USER', 'root');
define('DB_PASS', 'root');

/**
 * Return a shared PDO connection.
 *
 * This helper caches the connection in a static variable so that multiple
 * calls in the same request don't open additional connections.  If the
 * connection cannot be established the script will abort with a message.
 *
 * @return PDO
 */
function getPDO() {
    static $pdo;
    if (!$pdo) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            // In a real application you might log this error instead of
            // displaying it.  Aborting here keeps things simple for a demo.
            die('Database connection failed: ' . $e->getMessage());
        }
    }
    return $pdo;
}
?>