<?php
// Entry point for the CMS.  This script simply redirects the user
// depending on their authentication status.  If logged in they go
// directly to the dashboard; otherwise they're sent to the login
// page.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

startSession();

// If the user is already authenticated redirect them to the dashboard.
if (isLoggedIn()) {
    header('Location: ' . BASE_URL . 'dashboard');
    exit;
}

// Otherwise go to the login page.  We include an optional redirect
// parameter so that after logging in the user is returned here.
header('Location: ' . BASE_URL . 'login');
exit;