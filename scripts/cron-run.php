<?php
// Cron unico e generico da aplicacao: corre as tarefas registadas em
// scripts/cron-jobs.php que estejam devidas. Agendado no cPanel de 5 em 5
// minutos (o comando aponta para scripts/calendar-push.php, que so inclui
// este ficheiro). Ver README, "Tarefas de rotina (cron)".
//
// Uso:
//   php scripts/cron-run.php [--url=https://aicrm.teknisoft.pt/]   tarefas devidas
//   php scripts/cron-run.php --job=<nome>                           corre ja essa tarefa
//   php scripts/cron-run.php --list                                 tarefas e ultima execucao
//
// Tarefas 'company' correm num subprocesso por empresa de data/companies.php
// (getPDO() guarda a ligacao num static construido a partir de
// $_SESSION['company']); as 'global' num subprocesso sem empresa. O pai decide
// o que esta devido (data/cron-state.json), impede execucoes sobrepostas
// (data/cron.lock) e escreve em data/cron.log quando ha algo a registar.
// O URL publico (links das notificacoes, BASE_URL) vem de --url ou APP_URL.

declare(strict_types=1);

// So pela linha de comandos (cron): scripts/ e acessivel pelo servidor web.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$cronRoot = dirname(__DIR__);
$cronOptions = getopt('', ['empresa:', 'global', 'jobs:', 'job:', 'url:', 'list']);
$cronUrl = rtrim((string) ($cronOptions['url'] ?? (getenv('APP_URL') ?: 'https://aicrm.teknisoft.pt/')), '/') . '/';
$cronJobs = require __DIR__ . '/cron-jobs.php';

if (isset($cronOptions['empresa']) || isset($cronOptions['global'])) {
    cronRunChild($cronRoot, $cronJobs, $cronOptions, $cronUrl);
    exit(0);
}
exit(cronRunParent($cronRoot, $cronJobs, $cronOptions, $cronUrl));

// ------------------------------------------------------------------ pai

function cronRunParent(string $root, array $jobs, array $options, string $url): int {
    $statePath = $root . '/data/cron-state.json';
    $state = is_file($statePath) ? (json_decode((string) file_get_contents($statePath), true) ?: []) : [];
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Lisbon'));

    if (isset($options['list'])) {
        foreach ($jobs as $name => $job) {
            $last = isset($state[$name]['last']) ? date('Y-m-d H:i', (int) $state[$name]['last']) : 'nunca';
            $when = isset($job['at']) ? 'diaria as ' . $job['at'] : 'cada ' . (int) ($job['every'] ?? 5) . ' min';
            printf("%-20s %-8s %-18s ultima: %s  %s\n", $name, $job['scope'] ?? 'company', $when, $last, $job['description'] ?? '');
        }
        return 0;
    }

    $lock = fopen($root . '/data/cron.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        return 0; // execucao anterior ainda a correr
    }

    if (isset($options['job'])) {
        if (!isset($jobs[$options['job']])) {
            fwrite(STDERR, "Tarefa desconhecida: {$options['job']}\n");
            return 1;
        }
        $due = [$options['job'] => $jobs[$options['job']]];
    } else {
        $due = array_filter($jobs, static fn (array $job, string $name): bool => cronJobIsDue($job, $state[$name] ?? [], $now), ARRAY_FILTER_USE_BOTH);
    }
    if ($due === []) {
        return 0;
    }

    $lines = [];
    $byScope = ['company' => [], 'global' => []];
    foreach ($due as $name => $job) {
        $byScope[($job['scope'] ?? 'company') === 'global' ? 'global' : 'company'][] = $name;
    }
    $targets = [];
    if ($byScope['company'] !== []) {
        $companies = require $root . '/data/companies.php';
        foreach ($companies as $nif => $cfg) {
            if (is_array($cfg) && empty($cfg['skip_migrations'])) {
                $targets[] = ['--empresa=' . $nif, (string) ($cfg['slug'] ?? $nif), $byScope['company']];
            }
        }
    }
    if ($byScope['global'] !== []) {
        $targets[] = ['--global', 'global', $byScope['global']];
    }

    foreach ($targets as [$flag, $label, $names]) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($flag)
            . ' --jobs=' . escapeshellarg(implode(',', $names)) . ' --url=' . escapeshellarg($url) . ' 2>&1';
        $output = [];
        exec($cmd, $output, $code);
        $results = json_decode((string) end($output), true);
        if (!is_array($results)) {
            $lines[] = $label . ': ERRO ' . trim(implode(' ', array_slice($output, -3)));
            continue;
        }
        foreach ($results as $name => $result) {
            if (is_array($result) && cronResultIsWorthLogging($result)) {
                $lines[] = $label . ' ' . $name . ': ' . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
    }

    foreach (array_keys($due) as $name) {
        $state[$name] = ['last' => $now->getTimestamp(), 'day' => $now->format('Y-m-d')];
    }
    @file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
    if ($lines !== []) {
        @file_put_contents($root . '/data/cron.log', $now->format('Y-m-d H:i:s') . ' ' . implode(' | ', $lines) . "\n", FILE_APPEND | LOCK_EX);
    }
    flock($lock, LOCK_UN);
    return 0;
}

/** Devida: 'at' (HH:MM) uma vez por dia a partir dessa hora; 'every' (min) com 1 min de folga. */
function cronJobIsDue(array $job, array $last, DateTimeImmutable $now): bool {
    if (isset($job['at'])) {
        return $now->format('H:i') >= (string) $job['at'] && ($last['day'] ?? '') !== $now->format('Y-m-d');
    }
    $every = max(5, (int) ($job['every'] ?? 5));
    return $now->getTimestamp() - (int) ($last['last'] ?? 0) >= $every * 60 - 60;
}

/** So vai para o log o que tem erro ou algum numero > 0. */
function cronResultIsWorthLogging(array $result): bool {
    if (!empty($result['skipped'])) {
        return false;
    }
    if (isset($result['error'])) {
        return true;
    }
    foreach ($result as $value) {
        if (is_numeric($value) && (float) $value > 0) {
            return true;
        }
    }
    return false;
}

// ------------------------------------------------------------------ filho

function cronRunChild(string $root, array $jobs, array $options, string $url): void {
    ini_set('display_errors', 'stderr');
    $parts = parse_url($url) ?: [];
    // BASE_URL e appAbsoluteBaseUrl() leem o pedido HTTP: simular o do site.
    $_SERVER['SCRIPT_NAME'] = rtrim((string) ($parts['path'] ?? '/'), '/') . '/router.php';
    $_SERVER['HTTP_HOST'] = (string) ($parts['host'] ?? 'localhost') . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $_SERVER['HTTPS'] = ($parts['scheme'] ?? 'https') === 'https' ? 'on' : 'off';
    unset($_SERVER['DOCUMENT_ROOT']);

    require_once $root . '/memory-session.php';
    startMemorySession();
    $scope = isset($options['global']) ? 'global' : 'company';
    if ($scope === 'company') {
        $companies = require $root . '/data/companies.php';
        $company = $companies[(string) $options['empresa']] ?? null;
        if (!is_array($company)) {
            echo json_encode([]), "\n";
            return;
        }
        $_SESSION['company'] = $company;
    }
    require_once $root . '/functions.php';

    $results = [];
    foreach (array_filter(explode(',', (string) ($options['jobs'] ?? ''))) as $name) {
        $job = $jobs[$name] ?? null;
        if ($job === null || (($job['scope'] ?? 'company') === 'global') !== ($scope === 'global')) {
            continue;
        }
        $results[$name] = cronRunJob($root, $job, $scope);
    }
    echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}

/** Corre uma tarefa numa funcao propria (alguns ficheiros escrevem variaveis no escopo de quem os inclui). */
function cronRunJob(string $root, array $job, string $scope): array {
    try {
        if (!empty($job['require'])) {
            require_once $root . '/' . ltrim((string) $job['require'], '/');
        }
        $function = (string) $job['function'];
        if (!function_exists($function)) {
            return ['error' => 'funcao inexistente: ' . $function];
        }
        $result = $scope === 'global' ? $function() : $function(getPDO());
        return is_array($result) ? $result : ['result' => $result];
    } catch (Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}
