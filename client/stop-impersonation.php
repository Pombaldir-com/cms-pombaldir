<?php
require_once __DIR__ . '/../functions.php';

startSession();

// Only meaningful when an impersonation session is active. Capture the
// back-office return URL before clearing the impersonation state.
$returnUrl = (string) ($_SESSION['client_impersonator_return_url'] ?? '');
stopClientImpersonation();

$target = $returnUrl !== '' ? $returnUrl : (BASE_URL . 'contabilidade/entidades/empresas');
header('Location: ' . $target);
exit;
