<?php
// Manifest da app instalavel (PWA), rota "manifest.webmanifest". O nome e o
// da app nas Definicoes da empresa da sessao (ex.: "Z Contas"); sem sessao
// (pagina de login) fica "CRM". O <link rel="manifest"> usa
// crossorigin="use-credentials" para o pedido levar o cookie da sessao.
// Ver docs/calendario.md, "Notificacoes push".

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/app-icon.php';

$manifestName = 'CRM';
$manifestIcons = [];
try {
    startSession();
    if (!empty($_SESSION['company'])) {
        $setting = trim((string) getSetting('app_name', ''));
        if ($setting !== '') {
            $manifestName = $setting;
        }
        // Icones a partir do logotipo da empresa (app-icon.php).
        $manifestIcons = [
            ['src' => appIconUrl('192'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => appIconUrl('512'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => appIconUrl('maskable-512'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ];
    }
    session_write_close();
} catch (Throwable $e) {
    // Sem base de dados: nome por omissao.
}

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: private, max-age=3600');
echo json_encode([
    'id' => 'dashboard',
    'name' => $manifestName,
    'short_name' => mb_strimwidth($manifestName, 0, 12, ''),
    'description' => $manifestName . ': contabilidade, calendário e avisos.',
    'lang' => 'pt-PT',
    'start_url' => 'dashboard',
    'scope' => './',
    'display' => 'standalone',
    'background_color' => '#ffffff',
    'theme_color' => '#2a3f54',
    'icons' => $manifestIcons !== [] ? $manifestIcons : [
        ['src' => 'assets/images/app-icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/images/app-icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/images/app-icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
