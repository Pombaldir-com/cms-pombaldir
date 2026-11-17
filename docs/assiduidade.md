# Assiduidade / Registo de Ponto

Porta de `intranet/assiduidade.php` da intranet legacy (tabela `assiduidade`,
`ponto_next_assina()`, `ponto_getPeriodo()`, botao `#relogioPonto` no
`header.php`, settings `hentrada1/hsaida1/hentrada2/hsaida2/tolerancia`),
alargada com varios horarios, ausencias/ferias com aprovacao, feriados e mapas.

Ativa-se em **Definicoes > Modulos > Assiduidade** (`active_modules` contem
`assiduidade`) e precisa da migracao `20261117120000_create_attendance.sql`.

## Rotas e ficheiros

| Rota | Ficheiro | Quem |
| --- | --- | --- |
| `assiduidade/registo-ponto` | `assiduidade/registo.php` | todos (o proprio); `?colaborador=` para quem ve a equipa |
| `assiduidade/ausencias` | `assiduidade/ausencias.php` | todos (pedidos proprios); gestores aprovam |
| `assiduidade/mapa` | `assiduidade/mapa.php` | quem ve a equipa (ou todos com `attendance_map_public`) |
| `assiduidade/configuracao` | `assiduidade/configuracao.php` | administradores (`role <= 2`) |
| `assiduidade/handler` | `assiduidade/handler.php` | JSON: `status`, `punch`, `day`, `save_day` |

`assiduidade/` e um diretorio real: o Apache serve-o antes do router (regra `-d`
do `.htaccess`), por isso o mapa mensal **nao** pode viver na rota `assiduidade`.

Logica partilhada em `assiduidade/functions.php`; botao/modal de picagem em
`assiduidade/partials/punch-modal.php` + `assets/js/assiduidade-ponto.js`
(incluidos pelo `footer.php`); estilos em `assets/css/assiduidade.css`
(carregado pelo `header.php` quando o modulo esta ativo).

## Permissoes

- Administradores (`role <= 2`): tudo, incluindo a configuracao.
- `assiduidade_gerir` (departamento): corrigir picagens de qualquer colaborador e
  aprovar/recusar/eliminar ausencias. Inclui ver a equipa.
- `assiduidade_ver_equipa` (departamento): ver mapas e registos de todos (so leitura).
- Restantes: registam o proprio ponto, veem o proprio mapa e pedem ausencias.

## Modelo

- `attendance_schedules` + `attendance_schedule_days`: varios horarios; por dia
  da semana (1 = segunda) um periodo de manha e um de tarde. Tarde vazia =
  horario continuo/meio dia; dia sem linha = folga. Um horario e o "por
  omissao" (nao pode ser eliminado). `tolerance_minutes` aplica-se as entradas.
- `attendance_user_settings`: horario do colaborador (NULL = por omissao),
  `tracked` (regista ponto) e dias de ferias por ano. Sem linha = regista ponto
  com o horario por omissao e `attendance_vacation_days_default` dias.
- `attendance_vacation_carryover`: dias transitados por ano.
- `attendance_punches`: uma picagem por colaborador/dia/periodo (1 entrada manha,
  2 saida manha, 3 entrada tarde, 4 saida tarde). `source` = `web` (botao) ou
  `manual` (corrigida por um gestor; marcada com `*` no mapa). `justification`
  guarda o motivo do atraso (o legacy guardava-o em `users_log.extra`).
- `attendance_absence_types`: Ferias (`is_vacation`, desconta no saldo), faltas,
  doenca, formacao, etc. `requires_approval = 0` aprova logo o pedido. Um tipo ja
  usado e desativado em vez de eliminado.
- `attendance_absences`: `day_part` (`full`/`morning`/`afternoon`, so em pedidos
  de um dia) e `status` (`pending`/`approved`/`rejected`/`cancelled`).
- `attendance_holidays`: feriado municipal, tolerancias, encerramentos (com
  `day_part` e `recurring`). Os feriados nacionais sao calculados em
  `attendanceNationalHolidays()` (Pascoa pelo algoritmo de Meeus, sem
  ext-calendar); o Carnaval depende de `attendance_holiday_carnaval`.

## Regras de calculo

- **Proximo registo** (`attendanceTodayStatus`): o periodo seguinte ao ultimo
  registado hoje, entre os periodos do horario do dia (todos os 4 num dia de
  folga). Sem picagens e depois do fim da manha comeca na entrada da tarde (como
  o legacy). Uma ausencia aprovada de meio dia retira esse periodo.
- **Atraso**: entrada depois do inicio + tolerancia. Com
  `attendance_require_justification = 1` o motivo e obrigatorio.
- **Horas previstas** do dia = periodos do horario menos feriados e ausencias
  **aprovadas** (pendentes nao descontam). No mapa so contam ate hoje.
- **Horas trabalhadas** = (2 - 1) + (4 - 3) com pares completos; so 1 e 4 conta
  como dia continuo.
- **Sem registo**: dia passado com horas previstas e nenhuma picagem.
  **Incompleto**: dia passado com pares por fechar ou uma parte prevista sem picagens.
- **Dias de ausencia/ferias** (`attendanceCountAbsenceDays`): dias uteis do
  horario do colaborador, sem feriados; meio dia = 0,5 quando o dia tem manha e tarde.
- **Saldo de ferias** = direito anual + transitados - aprovados - pendentes.
  Colaboradores sem gestao nao podem pedir mais do que o saldo; gestores podem.

## Settings (`settings`)

| Chave | Omissao | Efeito |
| --- | --- | --- |
| `attendance_vacation_days_default` | `22` | dias de ferias para quem nao tem valor proprio |
| `attendance_topbar_button` | `1` | botao "Registar ..." na barra de topo |
| `attendance_require_justification` | `1` | motivo obrigatorio em entradas com atraso |
| `attendance_holiday_carnaval` | `1` | Carnaval conta como feriado |
| `attendance_map_public` | `0` | mapa de ausencias visivel para todos (sem resumo de horas) |

## Notificacoes

Pedidos pendentes notificam por Web Push os gestores (admins + departamentos com
`assiduidade_gerir`); decisoes e ausencias registadas por um gestor notificam o
colaborador (`attendanceNotifyUsers`, reutiliza `push/functions.php`). O menu
mostra um contador de pedidos pendentes aos gestores.

## Auditoria

`logAuditAction`: `attendance_punch`, `attendance_day_edit` (com as alteracoes),
`attendance_absence_*`, `attendance_schedule_*`, `attendance_*_save`.

## Fora do ambito (vs. legacy)

- O legacy terminava a sessao apos a saida (periodos 2 e 4) e pedia a
  justificacao no login; aqui a justificacao e pedida no proprio registo e a
  sessao nao e terminada.
