<?php
// Ponto de entrada do cron do cPanel (de 5 em 5 minutos). O nome ficou da
// primeira tarefa (lembretes do calendario), mas corre TODAS as tarefas
// registadas em scripts/cron-jobs.php atraves de scripts/cron-run.php.
// Nao e preciso criar outras entradas no cPanel para tarefas novas.
//
//   */5 * * * * /usr/local/bin/ea-php82 /home/teknisoft/domains/aicrm.teknisoft.pt/scripts/calendar-push.php --url=https://aicrm.teknisoft.pt/ >/dev/null 2>&1

declare(strict_types=1);

// So pela linha de comandos (cron): scripts/ e acessivel pelo servidor web.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/cron-run.php';
