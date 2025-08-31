<?php
// Use the existing user form to edit the currently logged in user's profile.
// This sets a flag so user_form.php knows it's being used for a profile edit
// rather than an administrative user edit.

$_GET['profile'] = 1;
require __DIR__ . '/user_form.php';

