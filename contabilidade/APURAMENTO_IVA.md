# Apuramento de IVA (nota tarefa)

Especificação da tarefa "Apuramento de IVA" a criar no menu **Tarefas**, com
base no comportamento equivalente da intranet legacy
(`/Users/nelsonsantos/Sites2026/intranet.zcontas.pt/intranet`, só leitura).
No legacy não existe uma página dedicada com este nome — é a tarefa/fase
`workflow_fases.idnum = 6` ("IVA") dentro do motor genérico de fecho de
tarefas por cliente/período (`window.php?act=wkfloproc`), complementada pela
fase 8 ("IVA a pagar/a recuperar"), pelo relatório "Mapa de IVA"
(`processaMapa.php?tp=IVA`) e pela configuração de fórmulas em
**Configuração > Planos de contas > DP IVA** (`planos_contas`).

Este documento descreve o comportamento a replicar. A implementação nesta
aplicação (rota, permissão, tabelas) fica registada na secção
"Integração na aplicação" e deve ser atualizada quando a tarefa for
construída.

## Integração na aplicação (implementado — reconciliação automática)

**Estado atual (2026-09-22)**: a reconciliação campo-a-campo está ligada a
dados reais do ERP-SINC. O bloqueador anterior (falta de endpoint de
balancete/DP) foi resolvido ao portar para o `api.erpsinc.pt` as duas
consultas do webservice legacy `intranet.zcontas.pt/webservices/ctb.php`:

- `GET /contabilidade/saldos?strCodExercicio=YYYY&intMes=M&intMes2=M2`
  → tabela `Ctb_Saldos` (balancete por conta/mês). Equivale ao
  `ctb.php?act_g=balancete`.
- `GET /contabilidade/declperiodica?strPeriodo=YYYYMMDDYYYYMMDD&strAnexo=1`
  → tabela `Tbl_Ctb_DeclPer` (DP gerada no ERP). Equivale ao
  `ctb.php?act_g=declPeriodica`. O período é o 1º + último dia concatenados.

Ambos documentados em `erpsync-api.yaml` e implementados em
`contabilidade.php` (blocos `tipo=saldos` / `tipo=declperiodica`, antes do
`list` genérico). Os helpers do lado da app estão em
[`apuramento-iva-functions.php`](apuramento-iva-functions.php):
`buildVatPeriodRange()`, `fetchErpVatAccountBalances()`,
`fetchErpVatDeclarationValues()`, `computeVatFieldDeviation()`
(= `IvaDeclMargemErro` legacy), `evaluateVatFieldRows()` (regras por campo)
e `computeVatSettlementExpected()` (fase 8: campo 94 senão 93).

Por implementar: relatório "Mapa de IVA" (secção abaixo). O envio por
email está implementado — ver "Envio por email".

- Página: [`contabilidade/tarefas-apuramento-iva.php`](tarefas-apuramento-iva.php),
  rota `contabilidade/tarefas/apuramento-iva`, seguindo o padrão de
  [`contabilidade/tarefas-envio-saft.php`](tarefas-envio-saft.php).
- Menu: **Tarefas > Apuramento de IVA** ([header.php](../header.php), no
  mesmo `<ul class="nav child_menu">` de "Envio de SAF-T"; o item pai
  "Tarefas" mostra-se se o utilizador tiver `ctb_envio_saft` **ou**
  `ctb_apuramento_iva`).
- Permissão por empresa: chave `ctb_apuramento_iva` em
  `getAccountingEntityAdminTaskDefinitions()` (`functions.php`), atribuída na
  ficha da empresa (Entidades > separador Admin > Tarefas administrativas),
  reutilizando a tabela `accounting_entity_admin_task_permissions`
  (entidade + `permission_key` + utilizador) — sem tabela nova de
  "colaboradores".
- Acesso à página: `userHasAccountingEntityTaskPermission('ctb_apuramento_iva')`,
  com `role <= 2` (admin/superadmin) sempre autorizado, tal como no padrão do
  SAF-T.
- A listagem de empresas visíveis na página fica limitada às entidades onde
  o utilizador tem a permissão atribuída (não-admin) ou a todas (admin),
  via `getIvaTaskEntities()` na própria página (mesmo padrão de
  `getSaftTaskEntities()`).
- Periodicidade por empresa: coluna `accounting_entities.vat_periodicity`
  (`ENUM('mensal','trimestral')`, migração
  `20260824162436_add_vat_periodicity_to_accounting_entities.sql`), editável
  em Entidades > ficha da empresa > separador Admin > "Periodicidade de
  IVA" (ação `set-entity-vat-periodicity` em `entidades.php`, apenas para
  `canManageClientAdmin`/`role <= 2`).
- Fecho do período: tabela `accounting_vat_settlements` (migração
  `20260824162500_create_accounting_vat_settlements.sql`), com colunas
  nomeadas (`period_type`, `period_year`, `period_ref`, `period_label`,
  `result_type`, `valor_pagar`, `valor_recuperar`, `observacao`,
  `closed_by`) — ver secção "Fecho da tarefa" para a razão de não replicar
  o blob `serialize()` do `wkflow_cab` legado.
- Configurações da tarefa: botão/ícone de configurações no canto superior
  direito do cabeçalho `x_title` da página, abrindo um modal — visível
  **apenas para admin/superadmin** (`role <= 2`), conforme convenção
  registada em [AGENTS.md](../AGENTS.md) (secção "Tarefas"). Colaboradores
  com permissão `ctb_apuramento_iva` não veem este botão. O modal gere o
  mapeamento campo → fórmula (ver "Mapeamento de campos da DP IVA").

## Conceito

Tarefa recorrente (mensal ou trimestral, por empresa) que reconcilia os
valores da **Declaração Periódica (DP) de IVA** oficial de um período com os
valores calculados a partir da **contabilidade** (balancete) desse mesmo
período, campo a campo, e regista o fecho do período quando os valores
batem certo (ou o utilizador aceita o desvio).

A fonte da DP e do balancete não é local — vem sempre de um serviço externo
de contabilidade (no legacy, o webservice "Ctb"; nesta aplicação, o
equivalente é o **webservice ERP-SINC**, seguindo as regras do
[CLAUDE.md](../CLAUDE.md) — endpoint sempre a partir das Definições, nunca
hardcoded, com `EMP` = Empresa base). A tarefa é, portanto, uma
**reconciliação**, não um motor de cálculo de IVA independente.

## Periodicidade por empresa

- Cada empresa tem uma periodicidade de IVA: **mensal** ou **trimestral**
  (no legacy, campo administrativo do cliente; nesta aplicação é o campo
  `accounting_entities.vat_periodicity`, editável em Entidades > Admin —
  ver "Integração na aplicação").
- **Mensal**: período = 1º ao último dia do mês (`YYYY-MM-01` a
  `YYYY-MM-<último dia>`).
- **Trimestral**: período = 1º dia do trimestre ao último dia do 3º mês do
  trimestre (Q1 = jan-mar, Q2 = abr-jun, Q3 = jul-set, Q4 = out-dez). O
  seletor de período mostra trimestres ("1º Trim. 2026", etc.) em vez de
  meses.
- Só esta tarefa (Apuramento de IVA) muda de granularidade consoante a
  periodicidade da empresa; as restantes tarefas de fecho mensal continuam
  sempre mensais mesmo para empresas com IVA trimestral.
- Períodos já fechados (ver "Fecho da tarefa") **não aparecem** no seletor —
  só é possível reabrir/consultar via relatório, não pela mesma tela de
  fecho.

## Mapeamento de campos da DP IVA (configuração) — implementado

Tabela `accounting_vat_field_formulas` (migração
`20260824163446_create_accounting_vat_field_formulas.sql`), equivalente a
`planos_contas`/aba "DP IVA" do legacy: por **número de campo da declaração
periódica** (1 a 24, mais os campos calculados 93 e 94), guarda uma
**fórmula** que soma/subtrai saldos de contas do balancete, no formato
`C<conta>[cre|deb]<+|->` repetido sem separador (ex.:
`C2432319cre-C243234deb+`) — `cre`/`deb` usam só o saldo credor/devedor da
conta, omitir usa o saldo líquido.

Gerido no modal de configurações da própria tarefa (botão de engrenagem no
canto superior direito de `contabilidade/tarefas/apuramento-iva`, visível
só a admin/superadmin), com uma tabela **Campo / Fórmula / Ação** no
mesmo espírito do admin legacy (`settings.php?act=planoscontas`, aba "DP
IVA"), mas com edição totalmente em AJAX (a modal não fecha ao gravar):
número do campo e fórmula editáveis por linha, botões "Editar" e eliminar
(lixo) por linha, e uma linha final para adicionar um campo novo.
Renumerar um campo para um número já existente é bloqueado com erro.

**Parser/avaliador implementados** (`contabilidade/functions.php`):

- `parseAccountingVatFieldFormula(string $formula): array` — decompõe a
  fórmula em termos `{account, side, sign}`; usado para **validar** o
  formato ao gravar (rejeita texto que não siga exatamente o padrão
  `C<conta>[cre|deb]<+|->` repetido, com mensagem de erro devolvida à
  modal).
- `evaluateAccountingVatFieldFormula(array $terms, array $accountBalances): float`
  — soma os termos contra um array de saldos por conta (`valor` /
  `fltCredito` / `fltDebito` por conta), replicando `cta()`/`ctaFormula()`
  do legacy. Os saldos vêm de `fetchErpVatAccountBalances()`.

### Ecrã "Apurar período" (campo-a-campo) — implementado

Abre como **modal popup** (`#iva-detail-modal` em `tarefas-apuramento-iva.php`,
mimetizando o fancybox do legacy) a partir do botão "Apurar período" de cada
empresa, com o Ano/Mês-Trimestre selecionado no cabeçalho da página. O
conteúdo é obtido via AJAX (`fetch` com `X-Requested-With: XMLHttpRequest`)
de [`tarefas-apuramento-iva-detalhes.php`](tarefas-apuramento-iva-detalhes.php)
(rota `contabilidade/tarefas/apuramento-iva/detalhes?entity_id=<id>`), que
devolve só o fragmento HTML quando pedido via AJAX ou a página completa
quando acedida diretamente pelo URL. Reproduz o ecrã
`window.php?act=wkfloproc` (task=6) do legacy:

- Seletor de período (Mês ou Trimestre, consoante `vat_periodicity`): ao
  mudar, recarrega o fragmento dentro do modal.
- A base ERP da empresa vem de `accounting_entities.erp_database`
  (`resolveAccountingEntityDatabase()`); sem ela, a página avisa e "Ctr Ctb"
  fica a 0,00.
- Uma linha por campo configurado em `accounting_vat_field_formulas`, com:
  - **C{n}-DP**: valor da DP obtida do ERP (anexo `1`, cabeçalho "DP (ERP)",
    inputs só de leitura). Se o ERP ainda não tiver DP para o período, o
    cabeçalho passa a "DP (manual)", os inputs ficam editáveis e o botão
    "Guardar valores DP" persiste-os em
    `accounting_vat_settlement_field_values` (fallback).
  - **Ctr Ctb**: fórmula avaliada contra o balancete real (linhas da mesma
    conta somadas quando o período cobre vários meses). Se der 0, repete
    usando o lado credor nos termos sem lado (fallback do legacy).
  - **Estado**: ✓ verde (ok), ! laranja (aviso, não bloqueia) ou ⚠ vermelho
    (erro, bloqueia o fecho), com tooltip "diferença: X".
- Secção **Resultado do período**: mostra o valor apurado pela contabilidade
  (campo 94 em crédito, senão 93 a pagar) e o formulário de fecho — ver
  "Fase complementar" e "Fecho da tarefa". O fecho passou a fazer-se aqui
  (a página principal deixou de ter o formulário inline).

## Regras de validação por campo — implementado (`evaluateVatFieldRows()`)

- **Campo 2** validado contra Campo 1 × 6% (taxa reduzida).
- **Campo 4** validado contra Campo 3 × 23% (taxa normal).
- **Campo 6** validado contra Campo 5 × 13% (taxa intermédia).
- **Campo 7**: comparação direta DP vs. Ctb com desvio máximo de 1 (€1); se
  bater, o valor Ctb é substituído pelo valor da DP para efeitos de
  apresentação.
- **Campo 13**: validado contra Campo 12, testando as 3 taxas padrão
  (23%, 13%, 6%) e aceitando a que produzir o **menor desvio** — o sistema
  não sabe à partida qual a taxa aplicável a este campo, por isso testa as
  três e fica com a melhor.
- **Campo 17**: mesma lógica de 3 taxas que o Campo 13, contra o Campo 16.
- Cálculo do desvio (margem de erro), por campo com taxa associada:
  `desvio = round(stddev(valor_esperado, valor_real) * 2, 2)`, onde
  `valor_esperado = valor_base × taxa`. Desvio `>= 1` (€1) é considerado
  erro/aviso.
- Se `Ctr Ctb` calcular 0, usar como alternativa o lado crédito da mesma
  fórmula (fallback débito→crédito quando o saldo líquido é zero).
- **Campo 93** (IVA a pagar, calculado): soma dos campos de IVA liquidado
  menos soma dos campos de IVA dedutível —
  `(C2+C6+C4+C13+C17+C41+C66) - (C20+C21+C22+C23+C24+C40+C61)`. Se negativo,
  fixar a 0.00 (não há "a pagar" — a situação é de crédito).
- Se **qualquer** campo ficar em erro (vermelho), o botão de fecho fica
  desativado até o desvio ser corrigido no ERP. Avisos (laranja, campos com
  taxa) não bloqueiam, como no legacy. **Diferença face ao legacy**: no
  legacy o campo 7 com desvio > 1 ficava vermelho mas não bloqueava o fecho
  (`$erro[]` só era preenchido quando não havia override); aqui qualquer
  estado vermelho bloqueia.
- Outra diferença deliberada: no `cta()` legacy, um termo `cre`/`deb`
  alterava `$campo` para os termos seguintes sem sufixo (bug). Aqui o lado é
  por termo.
- Pré-condição desejável (existia no legacy mas estava desativada por
  bug/decisão): a fase anterior "Lançamento de documentos" do mesmo período
  deveria estar fechada antes de permitir apurar o IVA. Avaliar se deve ser
  reativada nesta implementação (recomendado: sim, como aviso não
  bloqueante inicialmente).

## Fase complementar: IVA a pagar / a recuperar

Depois do apuramento por campo, uma segunda etapa determina o resultado
final do período:

- Calcula o valor do **Campo 94** (crédito/a recuperar) pela fórmula
  configurada; se for zero, calcula o **Campo 93** (a pagar).
- Se `93` > 0 → período "a pagar": mostra campo "Valor a pagar", validado
  contra o valor calculado.
- Se `94` > 0 → período "em crédito": mostra campo "Valor a pagar" (0) e um
  campo adicional "Valor Reembolso" (pedido de reembolso, opcional,
  introduzido manualmente pelo utilizador).
- **Implementado** em `computeVatSettlementExpected()` + formulário
  `.iva-detail-close-form` no modal: o tipo de resultado e o valor vêm
  pré-preenchidos; o JS (`bindIvaCloseForm()` na página principal) avisa
  "O valor deveria ser X" quando o valor introduzido difere do apurado, ou
  quando o reembolso pedido excede o crédito (aviso, não bloqueia).

## Fecho da tarefa

Ao fechar a etapa para um período:

- É gravado um registo por empresa + tarefa + período, incluindo utilizador
  que fechou, período, observação e o(s) valor(es) apurados (a pagar,
  recuperar ou crédito, consoante o caso). No legacy isto ficava em
  `wkflow_cab` com um blob `serialize()` pouco estruturado — **nesta
  implementação usar colunas nomeadas explícitas** em vez de um campo
  serializado (ex.: `resultado_tipo` enum `pagar|credito`, `valor_pagar`,
  `valor_recuperar`), para evitar a fragilidade de indexação que existia no
  legacy (o relatório "Mapa de IVA" tinha de adivinhar qual índice do array
  serializado correspondia a "pagar" vs. "recuperar" vs. "crédito").
- A existência do registo para aquele período é o que marca a tarefa como
  concluída (não há agendador/cron — é recalculado a cada carregamento a
  partir da BD). Uma restrição `UNIQUE (accounting_entity_id, period_label)`
  impede fechar o mesmo período duas vezes.
- O fecho é feito no modal (POST `close_period` a
  `tarefas-apuramento-iva-detalhes.php`, via AJAX). Ao fechar o modal depois
  de um fecho bem sucedido, a página principal recarrega. Um período já
  fechado mostra no modal quem/quando fechou e os valores, em vez do
  formulário.
- **Gap conhecido face ao legacy**: os períodos já fechados são listados por
  texto na página ("Períodos já fechados: ..."), mas continuam a aparecer
  no seletor Ano/Mês-Trimestre (o legacy escondia-os do dropdown).

## Envio por email — implementado

Duas ações independentes, ambas no modal "Apurar período"
(`tarefas-apuramento-iva-detalhes.php`), usando `sendSystemEmail()`
(`functions.php`, mesmo transporte SMTP/`mail()` configurado em
Definições) e um campo de email de destino memorizado em `localStorage`
por empresa (mesmo padrão do "Email de destino" da tarefa SAF-T):

- **Relatório de campos** (`action=send_field_report`, sob o quadro
  Campo/DP/Ctb): envia ao email indicado uma tabela HTML com Campo/DP/Ctb
  de todos os campos do período (`buildVatFieldReportEmailBody()`),
  equivalente ao `$htmlt` do legacy (`workflow_iva.php?act=message`).
  Não fecha nem depende do fecho do período.
- **Notificação ao cliente** (checkbox "Enviar notificação ao cliente" no
  formulário de fecho): quando marcada, o fecho e o envio acontecem na
  mesma submissão POST (`action=close_period`), como pedido — "enviar e
  fechar" numa única ação. O corpo (`buildVatClientNotificationEmailBody()`)
  segue o texto "Tem um valor de IVA a pagar de: X €" (a pagar) ou informa o
  crédito e o reembolso pedido, se houver. Uma falha no envio **não**
  desfaz o fecho — fica só registada na mensagem de feedback.
- Ambas as ações registam em `logAuditAction('send_email', ...)`.
- **Bug legado não replicado**: o "até dia 25" fixo do legacy não consta em
  lado nenhum destes textos; não há ainda cálculo de prazos legais de
  pagamento/entrega por regime (ver "Pontos a decidir" se vier a ser
  necessário no futuro).
- **Formatação e assinatura**: os dois corpos de email são gerados por
  `buildVatEmailTemplate()` (`apuramento-iva-functions.php`) — cabeçalho de
  marca, tabela/destaque estilizados inline (seguro para clientes de email,
  sem CSS externo) e assinatura no final. A assinatura reutiliza as
  Definições existentes (sem campo novo dedicado): `app_name`/`app_logo`
  para o cabeçalho, `system_email_from_name`/`system_email_from_email` para
  o nome/contacto assinado — os mesmos usados como remetente em
  `sendSystemEmail()`.

## Relatório "Mapa de IVA"

Página de listagem (separada da tarefa em si, equivalente a
`processaMapa.php?tp=IVA` no legacy) com, por empresa/período fechado:
colaborador responsável, empresa, data de fecho, valor a pagar, valor a
recuperar, valor em crédito. Deve ler diretamente das colunas nomeadas do
registo de fecho (ver secção "Fecho da tarefa"), sem necessidade de
deserializar nada.

## Tabelas envolvidas

Seguindo o padrão de nomenclatura do projeto (não replicar nomes legados
como `wkflow_cab`/`planos_contas`):

- `accounting_vat_settlements` — **criada**. Registo de fecho por entidade +
  período (colunas nomeadas para o resultado, ver "Fecho da tarefa").
- `accounting_entities.vat_periodicity` — **criada**. Periodicidade por
  empresa.
- `accounting_entity_admin_task_permissions` — reutilizada (já existia)
  para a permissão `ctb_apuramento_iva`; sem tabela nova de colaboradores.
- `accounting_vat_field_formulas` — **criada**. Mapeamento campo da DP →
  fórmula de contas do balancete (equivalente a `planos_contas`), gerido no
  modal de configurações da tarefa, avaliado contra o balancete do ERP.
- `accounting_vat_settlement_field_values` — **criada**. Valores manuais de
  C{n}-DP por empresa/período/campo, usados como fallback no modal "Apurar
  período" quando o ERP ainda não tem a DP do período.

## Pontos a decidir

- ~~Bloqueador principal: endpoint ERP-SINC de balancete/DP~~ — **resolvido
  em 2026-09-22** com `GET /contabilidade/saldos` e
  `GET /contabilidade/declperiodica` (ver "Integração na aplicação").
- Prazos reais de pagamento/entrega da DP de IVA por regime (substituir o
  "até dia 25" fixo do legacy por regras corretas) — só relevante quando a
  funcionalidade de envio por email for implementada.
- Reativar (ou não) o bloqueio por fase anterior não fechada — só relevante
  quando existir uma fase "Lançamento de documentos" equivalente nesta
  aplicação.
