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

## Integração na aplicação (implementado — fecho manual)

**Estado atual**: a página existe e permite fechar o período por
empresa/período com introdução manual dos valores (a pagar / a recuperar),
mas a reconciliação automática campo-a-campo (secções "Mapeamento de campos
da DP IVA" e "Regras de validação por campo" abaixo) **ainda não está
implementada** — depende de um endpoint ERP-SINC que ainda não existe (ver
"Pontos a decidir"). Esta secção descreve o que existe hoje; as secções
seguintes descrevem o comportamento-alvo (legacy) a implementar quando o
endpoint estiver disponível.

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
  com permissão `ctb_apuramento_iva` não veem este botão. Por agora o modal
  é só informativo (o mapeamento de campos da DP IVA ainda não existe,
  porque depende do endpoint ERP-SINC em falta).

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

## Mapeamento de campos da DP IVA (configuração) — por implementar

> As secções seguintes ("Mapeamento de campos da DP IVA" até "Envio por
> email") descrevem o comportamento-alvo do legacy, ainda **não
> implementado** nesta aplicação — bloqueado pela falta do endpoint
> ERP-SINC equivalente a `declPeriodica`/`balancete` (ver "Pontos a decidir
> antes de implementar"). O que já existe é o fecho manual descrito acima.

Uma tabela de configuração (equivalente a `planos_contas`, aba "DP IVA")
define, por **número de campo da declaração periódica** (1 a 24, mais os
campos calculados 93 e 94), uma **fórmula** que soma/subtrai saldos de
contas do balancete (formato `C<conta>+C<conta>-...`, com prefixo opcional
`cre`/`deb` para forçar o lado crédito/débito da conta). Esta configuração é
por aplicação (não por empresa) e deve ser gerível através do botão de
configurações da própria tarefa (canto superior direito de
`contabilidade/tarefas/apuramento-iva`, visível só a admin/superadmin —
ver secção "Integração na aplicação"), em vez de ficar espalhada em
**Configuração > Planos de contas** junto com as restantes fórmulas
(SAF-T, Salários, DR, Trib. Autónoma) já existentes nesse admin geral.

Cada linha da tela de apuramento representa um campo, com 3 colunas:

- **C{n}-DP**: valor da declaração periódica oficial, obtido do serviço
  externo (`declPeriodica` no legacy).
- **Ctr Ctb**: valor calculado a partir do balancete, aplicando a fórmula do
  campo `n`.
- **Estado**: verde (✓) quando os dois valores batem certo (dentro da
  tolerância abaixo), vermelho/laranja (⚠) quando não batem, com tooltip a
  mostrar a diferença.

## Regras de validação por campo

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
- Se **qualquer** campo ficar em erro, os botões de fecho da tarefa e de
  envio ficam desativados até o desvio ser corrigido/aceite.
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
- **Gap conhecido face ao legacy**: os períodos já fechados são listados por
  texto na página ("Períodos já fechados: ..."), mas continuam a aparecer
  no seletor Ano/Mês-Trimestre (o legacy escondia-os do dropdown). Tentar
  fechar um período repetido é bloqueado no submit com mensagem de erro, em
  vez de o impedir visualmente à partida — melhoria a fazer no seletor.

## Envio por email

Ação opcional "Enviar", disponível a partir da tela de apuramento e da tela
de a pagar/a recuperar:

- Apuramento por campo: envia ao destinatário configurado (equivalente ao
  `emailRespIVA` do legacy) uma tabela com Campo / DP / Ctb para todos os
  campos apurados no período.
- A pagar/a recuperar: envia notificação ao cliente com o valor a pagar (ou
  em crédito) e, se aplicável, o valor de reembolso solicitado, com texto
  base tipo "Tem um valor de IVA a pagar/em crédito de: X €" e, quando a
  pagar, referência à guia de pagamento em anexo.
- **Nota (bug legado a não replicar)**: no legacy o prazo de pagamento
  aparecia sempre como "até dia 25", independentemente da periodicidade
  (mensal/trimestral) — isto deve ser revisto com as regras reais em vigor
  (os prazos legais de entrega/pagamento da DP de IVA diferem consoante o
  regime), não copiado tal e qual.
- Deve existir opção de "enviar e fechar" numa única ação (checkbox), como
  no legacy, para reduzir passos.

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
- Mapeamento campo da DP → fórmula de contas do balancete (equivalente a
  `planos_contas`) — **ainda não criada**, por implementar junto com a
  reconciliação automática (ver secções acima e "Pontos a decidir").

## Pontos a decidir antes de implementar (reconciliação automática)

- **Bloqueador principal**: não existe, nem na spec OpenAPI
  (`api.erpsinc.pt/erpsync-api.yaml`) nem no código-fonte local do
  ERP-SINC, nenhum endpoint equivalente a `declPeriodica`/`balancete` do
  legacy (confirmado por investigação em 2026-08-24). É preciso decidir
  entre: (a) pedir a criação desse endpoint à equipa ERP-SINC; ou (b)
  derivar o "Ctr Ctb" a partir do endpoint `/contabilidade/movimentos` já
  existente, aplicando as fórmulas por campo do lado da aplicação — mas
  mesmo nesse caso continua a faltar uma fonte para o valor oficial da
  Declaração Periódica (`C{n}-DP`), que no legacy também vinha do
  webservice externo.
- Prazos reais de pagamento/entrega da DP de IVA por regime (substituir o
  "até dia 25" fixo do legacy por regras corretas) — só relevante quando a
  funcionalidade de envio por email for implementada.
- Reativar (ou não) o bloqueio por fase anterior não fechada — só relevante
  quando existir uma fase "Lançamento de documentos" equivalente nesta
  aplicação.
