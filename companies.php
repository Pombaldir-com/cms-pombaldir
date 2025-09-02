<?php
// Simple company context management for the CMS.

require_once __DIR__ . '/functions.php';

/**
 * Retrieve a company by its NIF.
 *
 * This implementation assumes a table `companies` with at least `id` and `nif` columns.
 * Adjust as necessary for your data source.
 *
 * @param string $nif
 * @return array|null
 */
function getCompanyByNif(string $nif): ?array {
    $pdo = getPDO();
    try {
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE nif = ? LIMIT 1');
        $stmt->execute([$nif]);
        $company = $stmt->fetch();
        return $company ?: null;
    } catch (Exception $e) {
        return null;
    }
}

