<?php
// Simple company context management for the CMS.

/**
 * Retrieve a company configuration by its NIF.
 *
 * Company settings are stored in data/companies.php as an associative
 * array keyed by NIF. This helper returns the matching configuration
 * or null when the NIF is unknown.
 *
 * @param string $nif
 * @return array|null
 */
function getCompanyByNif(string $nif): ?array {
    $companies = require __DIR__ . '/data/companies.php';
    return $companies[$nif] ?? null;
}

/**
 * Retrieve a company configuration by its slug.
 *
 * @param string $slug
 * @return array|null
 */
function getCompanyBySlug(string $slug): ?array {
    $slug = trim($slug);
    if ($slug === '') {
        return null;
    }

    $companies = require __DIR__ . '/data/companies.php';
    foreach ($companies as $company) {
        if (!is_array($company)) {
            continue;
        }
        if (strcasecmp((string) ($company['slug'] ?? ''), $slug) === 0) {
            return $company;
        }
    }

    return null;
}
