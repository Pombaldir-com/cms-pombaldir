<?php
/**
 * Painel principal do CMS.
 *
 * Página temporariamente em branco. Requer que o utilizador esteja
 * autenticado para aceder.
 */

// Usar as funções comuns
require_once __DIR__ . '/functions.php';

// Inicia sessão e verifica se o utilizador está autenticado
startSession();
requireLogin();

// Inclui o cabeçalho comum do template
require_once __DIR__ . '/header.php';

?>
<!-- Dashboard temporariamente em branco -->
<?php
// Inclui o rodapé comum do template
require_once __DIR__ . '/footer.php';
