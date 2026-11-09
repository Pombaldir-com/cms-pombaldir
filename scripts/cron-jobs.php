<?php
// Registo das tarefas agendadas da aplicacao, corridas por scripts/cron-run.php
// (o unico cron do cPanel, de 5 em 5 minutos). Uma tarefa nova acrescenta-se
// aqui; nao e preciso criar outra entrada no cPanel. Ver README, "Tarefas de
// rotina (cron)".
//
// Cada tarefa:
//   description  texto curto (log)
//   scope        'company': corre uma vez por empresa de data/companies.php,
//                num subprocesso com a empresa em $_SESSION['company'] (getPDO()
//                so serve uma empresa por processo); a funcao recebe o PDO.
//                'global': corre uma vez, sem empresa; a funcao nao recebe nada.
//   every        minutos entre execucoes (multiplo de 5, o ritmo do cron)
//   at           em alternativa a every: hora diaria 'HH:MM' (corre na primeira
//                passagem a partir dessa hora, uma vez por dia)
//   require      ficheiro (relativo a raiz) que define a funcao
//   function     funcao a chamar; devolve um array com o resumo (vai para o
//                log quando tiver numeros > 0 ou 'error'), ou ['skipped' => true]

return [
    'calendar_push' => [
        'description' => 'Lembretes do calendario por Web Push',
        'scope' => 'company',
        'every' => 5,
        'require' => 'calendario/functions.php',
        'function' => 'calendarCronPush',
    ],
];
