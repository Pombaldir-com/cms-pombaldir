<?php
// Database connection helper using company settings stored in the session.

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
            die('Database connection failed: ' . $e->getMessage());
        }
    }
    return $pdo;
}
