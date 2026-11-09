<?php
// Tarefa de rotina (cron, a cada 5 minutos): envia por Web Push os lembretes
// do calendario (e convites/alteracoes que nao tenham saido logo ao gravar)
// aos dispositivos subscritos. Ver docs/calendario.md, "Notificacoes push".
//
// Uso: php scripts/calendar-push.php [--url=https://aicrm.teknisoft.pt/]
//      php scripts/calendar-push.php --empresa=<NIF>   (so uma empresa)
//
// getPDO() guarda a ligacao num static construido a partir de
// $_SESSION['company']: cada empresa corre num subprocesso proprio (--empresa)
// e o processo pai junta os resultados. O URL publico (links e icones das
// notificacoes) vem de --url ou da variavel APP_URL.

declare(strict_types=1);

$appRoot = dirname(__DIR__);
$options = getopt('', ['empresa:', 'url:']);
$appUrl = rtrim((string) ($options['url'] ?? (getenv('APP_URL') ?: 'https://aicrm.teknisoft.pt/')), '/') . '/';
$logFile = $appRoot . '/data/calendar-push.log';

// ------------------------------------------------------------------ pai
if (!isset($options['empresa'])) {
    $companies = require $appRoot . '/data/companies.php';
    $lines = [];
    foreach (array_keys($companies) as $nif) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
            . ' --empresa=' . escapeshellarg((string) $nif) . ' --url=' . escapeshellarg($appUrl) . ' 2>&1';
        $output = [];
        exec($cmd, $output, $code);
        $json = json_decode((string) end($output), true);
        $label = (string) ($companies[$nif]['slug'] ?? $nif);
        if (!is_array($json)) {
            $lines[] = $label . ': ERRO ' . trim(implode(' ', array_slice($output, -3)));
            continue;
        }
        if (!empty($json['skipped'])) {
            continue;
        }
        if (($json['notices'] ?? 0) > 0 || ($json['failed'] ?? 0) > 0) {
            $lines[] = sprintf('%s: %d avisos, %d enviados, %d removidos, %d falhados', $label, $json['notices'], $json['sent'], $json['removed'], $json['failed']);
        }
    }
    if ($lines !== []) {
        @file_put_contents($logFile, date('Y-m-d H:i:s') . ' ' . implode(' | ', $lines) . "\n", FILE_APPEND | LOCK_EX);
    }
    exit(0);
}

// ------------------------------------------------------------------ filho
ini_set('display_errors', 'stderr');
$parts = parse_url($appUrl) ?: [];
// BASE_URL e appAbsoluteBaseUrl() leem o pedido HTTP: simular o do site.
$_SERVER['SCRIPT_NAME'] = rtrim((string) ($parts['path'] ?? '/'), '/') . '/router.php';
$_SERVER['HTTP_HOST'] = (string) ($parts['host'] ?? 'localhost') . (isset($parts['port']) ? ':' . $parts['port'] : '');
$_SERVER['HTTPS'] = ($parts['scheme'] ?? 'https') === 'https' ? 'on' : 'off';
unset($_SERVER['DOCUMENT_ROOT']);

require_once $appRoot . '/memory-session.php';
startMemorySession();
$companies = require $appRoot . '/data/companies.php';
$company = $companies[(string) $options['empresa']] ?? null;
if (!is_array($company) || !empty($company['skip_migrations'])) {
    echo json_encode(['skipped' => true]), "\n";
    exit(0);
}
$_SESSION['company'] = $company;

require_once $appRoot . '/calendario/functions.php';
if (!calendarHasTables()) {
    echo json_encode(['skipped' => true]), "\n";
    exit(0);
}
$result = calendarPushPending(getPDO());
echo json_encode($result), "\n";
