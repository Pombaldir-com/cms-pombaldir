<?php
require_once 'functions.php';
// Start session and log out the current user
startSession();
logoutUser();
// Redirect back to the login page
header('Location: ' . BASE_URL . 'login');
exit;
?>