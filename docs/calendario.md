# Calendário do dashboard

Porta da "Agenda" da intranet legacy (`intranet.zcontas.pt`: `include/index.inc.php`, `window.php?act=agenda`,
`data/utils.php` `eventos_*`, tabelas `agenda`/`agenda_user`), com a implementação da intranet PombalData
(`int.pombaldata.com`: `app/eventos/`, `app/caldav/`) como referência técnica.

## Uso

- **Criar:** clique num dia ou horário (toque longo no telemóvel), ou "Novo evento". Um dia na vista Mês fica
  09:00–10:00; vários dias selecionados ficam um evento de dia inteiro; um horário da vista Semana/Dia fica com 1 hora.
- **Campos:** título, cliente (opcional), início, fim, dia inteiro, local, lembrete (sem, 15 min, 30 min, 1 h, 2 h,
  4 h, 1 dia; 1 h por omissão), utilizadores convidados e observações.
- **Cliente:** entidade adquirente (`accounting_entities`, `entity_type = 'acquirer'`), pesquisada por nome, NIF ou
  código ERP (como nas Atividades). Só aparece com o módulo Contabilidade ativo. Guarda-se o id e uma cópia de
  nome/NIF/código; o modal tem link para a ficha do cliente e o nome aparece no título do evento no calendário.
- **Quem vê:** o criador e os convidados. Os administradores (role ≤ 2) podem escolher "Todos os utilizadores".
- **Quem altera:** o criador ou um administrador (editar, arrastar, redimensionar, eliminar). Os convidados só veem.
- **Link direto:** `dashboard?evento={uuid}` abre o evento (é o link dos avisos e do campo URL no telemóvel).

## Avisos

- Tipos (`calendar_event_notices.kind`): `invite` (convidado acrescentado), `changed` (mudou a hora; aos convidados
  que já estavam e ao criador, exceto a quem fez a alteração) e `reminder` (lembrete antes do início).
- Um aviso por evento + utilizador + tipo + início: mudar a hora gera um novo lembrete.
- Um evento de dia inteiro conta o lembrete a partir das 09:00 do primeiro dia (`CALENDAR_ALL_DAY_HOUR`).
- Chegam por três vias, independentes (`delivered_at` = mostrado na página, `pushed_at` = enviado por push):
  1. **Na página aberta:** `assets/js/calendario-avisos.js` (todas as páginas) chama
     `calendario/handler?action=notices` a cada 60 s e ao voltar ao separador; o servidor gera os lembretes devidos
     nesse pedido e marca-os como entregues ao devolvê-los (só um separador os mostra). Mostra um PNotify e, sem Web
     Push ativo neste dispositivo, uma notificação do sistema pelo service worker.
  2. **Web Push** (app instalada no telemóvel ou browser, mesmo fechados): ver "Notificações push".
  3. **CalDAV:** no calendário do telemóvel o lembrete vai como `VALARM`.
- Um lembrete cujo evento já começou não é mostrado nem enviado; convites/alterações com mais de 7 dias não são
  mostrados na página (nem com mais de 1 dia enviados por push).

## Notificações push (app instalada)

Porta do Web Push da intranet PombalData (`app/chat/webpush.php`, `include/functions/push.php`, `public/sw.js`,
`public/assets/js/push.js`): sem app nativa, a aplicação instala-se no ecrã principal (PWA) e recebe as
notificações com a app fechada.

- **Nome e ícone da empresa:** a app instalada, os calendários CalDAV ("Z Contas · Eventos") e a conta no iPhone usam
  o nome da app das Definições (`app_name`, `calendarAppName()`); sem ele, "CRM". O manifest é gerado por
  `manifest.php` (rota `manifest.webmanifest`, `crossorigin="use-credentials"` para levar a sessão).
- **Ícone (favicon, app instalada, notificações), por empresa:** gerado por `app-icon.php` (rota
  `app-icon/{slug}/{variante}.png`, com o slug no URL porque os browsers pedem os ícones sem cookies), em cache em
  `uploads/{slug}/branding/pwa/`. Fonte, por ordem:
  1. **Ícone próprio** — Definições > Geral > "Ícone (favicon e app)", a seguir ao logotipo (setting `app_icon`,
     ficheiro em `uploads/{slug}/branding/AAAA/MM/icon_*.png`). PNG/JPG quadrado (tolerância de 5%), mínimo 192 px,
     reduzido a 1024 px; 512×512 recomendado. Usado a toda a área (só o maskable do Android leva 10% de margem); a
     transparência mantém-se no favicon e nos ícones normais, o do iPhone e o maskable ficam com fundo opaco.
     "Remover" volta ao logotipo. Com ícone próprio, este substitui também a casa (`fa-home`) antes do nome da
     empresa no topo do menu lateral (`header.php`, `appIconHasCustom()`, `.site_title_icon` em `custom.css`).
  2. **Logotipo** (`app_logo`) — sem as margens vazias, centrado num quadrado com a cor de fundo do próprio
     logotipo (branco se transparente). Um logotipo muito horizontal fica pequeno: carregar um ícone próprio.
  3. **Genérico** — `assets/images/app-icon-*.png`.

  Como as definições ficam na base de cada empresa e os ficheiros em `uploads/{slug}/`, cada tenant tem o seu. O
  `badge` (barra de estado do Android) é sempre o genérico monocromático. Mudar o desenho dos ícones: subir
  `APP_ICON_RENDER_VERSION`.
- **Instalar:** iPhone/iPad (iOS/iPadOS 16.4 ou mais recente): no Safari, depois de entrar, Partilhar → Adicionar ao
  ecrã principal, e abrir pelo ícone (no Safari normal não há Web Push). Android: Chrome → Instalar app. Instalar a
  partir da página de login não é suportado (ainda não se sabe a empresa: o nome e o ícone seriam os genéricos).
- **Ligar:** perfil → "Notificações neste dispositivo" (interruptor + "Testar") ou "Ativar notificações" no
  dashboard. `assets/js/push.js` subscreve com a chave VAPID e grava em `push_subscriptions` (`push/handler`); com a
  permissão já dada, renova a subscrição em silêncio em cada página. Cada dispositivo liga-se em separado; a
  subscrição pertence ao utilizador (continua depois de terminar a sessão) e desliga-se no interruptor ou quando o
  serviço de push responde 404/410.
- **Envio** (`push/webpush.php`, sem bibliotecas: VAPID ES256 + `aes128gcm` com o `openssl` do PHP):
  convites/alterações logo depois de gravar (no dashboard ou no telemóvel via CalDAV), depois de a resposta sair
  (`pushAfterResponse()`); lembretes pelo cron `scripts/calendar-push.php` (`calendarPushPending()`), que também
  apanha convites que não tenham saído. Com Web Push ativo, a página aberta mostra só o PNotify (sem notificação de
  sistema duplicada).
- **Chaves VAPID:** uma por instalação, `VAPID_PUBLIC_KEY`/`VAPID_PRIVATE_KEY` no `.env` (base64url do ponto público
  e do PEM privado) ou, sem elas, geradas na primeira utilização em `data/vapid-keys.php` (0600, fora do git; PHP
  para não ser servido como texto). Mudá-las invalida as subscrições (renovam-se sozinhas na visita seguinte).
  `VAPID_SUBJECT` (por omissão `https://aicrm.teknisoft.pt`). `PUSH_OFF=1` desliga o envio.
- **Cron:** tarefa `calendar_push` do cron genérico da aplicação (`scripts/cron-jobs.php`, corrida por
  `scripts/cron-run.php` de 5 em 5 minutos, um subprocesso por empresa; ver README, "Tarefas de rotina (cron)").
  Função `calendarCronPush()`. Resultados com envios ou erros em `data/cron.log`.
- **Produção:** o servidor precisa de saída HTTPS para `fcm.googleapis.com` (Chrome/Android), `*.push.apple.com`
  (Safari/iOS) e `*.push.services.mozilla.com` (Firefox).

## Calendário no telemóvel (CalDAV)

Servidor CalDAV em `caldav/` (sabre/dav via composer), para a app Calendário do iPhone/iPad/Mac, o DAVx⁵ no Android,
o Thunderbird, etc. Cada utilizador tem dois calendários (`{app}` = nome da app nas Definições, ex.: "Z Contas"):

| Calendário | Conteúdo | No telemóvel |
|---|---|---|
| {app} · Eventos | eventos criados pelo próprio | criar, alterar, apagar (apaga para todos) |
| {app} · Convites | eventos para que foi convidado | só leitura; apagar tira-o do evento |

- **Acesso:** perfil (`users/profile`, painel "Calendário no telemóvel"). Cada dispositivo tem uma password própria
  (`caldav_access_tokens`, só o hash), revogável. "Instalar no iPhone / iPad / Mac" descarrega um `.mobileconfig`
  que cria a conta; "Android / outro" mostra uma vez o servidor, o utilizador (`{utilizador}@{NIF}`) e a password.
  Funciona também dentro da app instalada no ecrã principal (PWA), no Safari ou no Chrome. O perfil é enviado por
  `caldavSendMobileConfig()` com `X-Content-Type-Options: nosniff`: sem ele o WebKit do iOS vê `<?xml` e mostra o
  perfil como texto em vez de o instalar (a intranet PombalData envia `nosniff` em todas as respostas). Antes dos
  cabeçalhos descarta qualquer output pendente e regista no log se os cabeçalhos já tiverem saído.
- **Autenticação:** Basic, antes do sabre correr (`calendario/caldav.php`); o NIF escolhe a empresa
  (`data/companies.php`) como no login. Sem cookie nem ficheiro de sessão (handler de sessão em memória).
  Tentativas falhadas limitadas a 8 por conta e 30 por IP em 15 min (ficheiros em `sys_get_temp_dir()`).
- **Conversão** (`calendario/caldav-functions.php`): horas de Lisboa enviadas em UTC; dia inteiro com datas. O
  cliente vai no fim da descrição (`— Cliente: …`) e é retirado ao ler; do telemóvel o cliente e os convidados
  mantêm-se. O primeiro alarme passa a ser o lembrete (a opção mais próxima); sem alarme, sem lembrete. Eventos
  repetidos (RRULE) são recusados. O nome e o UID dados pelo telemóvel ficam em `caldav_uri`/`caldav_uid`.
- Só se enviam os eventos dos últimos 180 dias para a frente (`CALDAV_PAST_DAYS`).
- **Descoberta:** `/.well-known/caldav` redireciona para `/caldav/` (`.htaccess` e `router.php`).
- **Produção:** correr `composer install` (o `vendor/` não é versionado; sem ele o painel e o servidor dizem que o
  CalDAV não está instalado). Confirmar que o Apache/ModSecurity deixa passar `PROPFIND`, `REPORT`, `PUT` e `DELETE`
  em `/caldav/` e que o header `Authorization` chega ao PHP (o `.htaccess` já o copia para `HTTP_AUTHORIZATION`).

## Código

- `migrations/20261109120000_create_calendar_events.sql`: `calendar_events`, `calendar_event_members`,
  `calendar_event_notices`, `caldav_access_tokens`.
- `calendario/functions.php` (`calendar*`): validação, gravar, eliminar, feed FullCalendar, próximos eventos, avisos.
- `calendario/handler.php` (rota `calendario/handler`): `feed`, `view`, `clients`, `notices` (GET); `save`, `move`,
  `delete`, `caldav_create`, `caldav_revoke` (POST com `csrf_token`). Regista em `audit_logs` (`calendar_event_*`).
- `calendario/caldav.php`, `caldav-backends.php`, `caldav-functions.php` (`caldav*`): servidor CalDAV.
- `calendario/partials/caldav-panel.php`: painel do perfil.
- `dashboard.php` (painel, modal e `#dash-calendar-config`) + `assets/js/calendario.js`. FullCalendar 6 (CDN jsDelivr)
  só no dashboard, via `$useFullCalendar` em `header.php`/`footer.php`.
- `push/` (`push*`): `webpush.php` (cifra/VAPID), `functions.php` (subscrições, envio, `pushPayload()`),
  `handler.php` (rota `push/handler`), `partials/profile-panel.php`. `assets/js/push.js`; service worker
  `internal-chat-sw.js` (eventos `push` e `notificationclick`; mantém o nome para não perder as instalações).
- `manifest.php`, `app-icon.php`, `memory-session.php` (sessão em memória para CalDAV, ícones e cron).
- `scripts/cron-jobs.php` (tarefa `calendar_push`) + `scripts/cron-run.php`: cron dos lembretes por push.
- `migrations/20261109150000_create_push_subscriptions.sql`: `push_subscriptions` e `calendar_event_notices.pushed_at`.
