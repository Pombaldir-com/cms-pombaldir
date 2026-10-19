# Confirmação de Pasta (tarefa)

Tarefa no menu **Tarefas > Confirmação de Pasta**, porta da fase 4
**"Lançamento de docs"** da intranet legacy
(`/Users/nelsonsantos/Sites2026/intranet.zcontas.pt/intranet`, só leitura):

- `window.php?act=wkfloproc&task=4` — ecrã do mês: total por diário
  (lançado no ERP vs. fita) e caixas "Total Débitos / Total Créditos"
  (SAF-T vs. contabilidade), botão "Fechar etapa".
- `ifrm_utils.php?act=diariosmov` — popup "Conferência de valores" de um
  diário: valores da fita vs. valores por documento, divergentes a vermelho.
- `window_calculator.php` + `data/utils.php` `accaop=mov_fita_calc` —
  introdução dos valores da fita (tabela `mov_fita_calc`, expressão
  `serialize()`'d "10.5+20+3").
- `data/workflow.php` `accaop=closeWF` — fecho (`wkflow_cab`, tarefa 4).
- Webservice `webservices/ctb.php?act_g=movimentos` (movimentos do mês por
  diário/lado/documento) e `act_g=balancete`.

## Integração na aplicação

- Páginas: [`tarefas-confirmacao-pasta.php`](tarefas-confirmacao-pasta.php)
  (rota `contabilidade/tarefas/confirmacao-pasta`, lista de empresas +
  histórico) e
  [`tarefas-confirmacao-pasta-detalhes.php`](tarefas-confirmacao-pasta-detalhes.php)
  (rota `.../confirmacao-pasta/detalhes`, fragmento do modal "Conferir",
  mesmo padrão AJAX das tarefas de IVA; com `journal=N` mostra a
  "Conferência de valores" do diário N).
- Helpers: [`confirmacao-pasta-functions.php`](confirmacao-pasta-functions.php).
- Permissão por empresa: `ctb_confirmacao_pasta` (Entidades > ficha da
  empresa > Admin > Tarefas administrativas), tabela
  `accounting_entity_admin_task_permissions`. Admin/superadmin veem todas as
  empresas.
- Periodicidade: sempre **mensal** (como no legacy). Por defeito abre o mês
  anterior.
- Migração `20261019150000_create_accounting_folder_confirmations.sql`:
  - `accounting_folder_tape_values` — um registo por valor da fita
    (empresa, ano, mês, `journal_code` = `intCodDiario`, `valor`).
  - `accounting_folder_confirmations` — fecho por empresa/mês
    (`UNIQUE (accounting_entity_id, period_label)`), com
    `divergences_count`, `summary_json` (totais por diário ERP/fita e
    SAF-T/Ctb no momento do fecho) e `observacao`.

## Diários

Lista fixa do legacy (`$array_diarios`): 1 Caixa, 2 Bancos, 3 Vendas,
4 Compras, 5 Diversos, 8 Imp. efatura, 9 Compras dinh., 11 Recibos,
12 Pagamentos. Outros diários com movimentos (ou fita) no mês são
acrescentados no fim com o nome do ERP (`Tbl_Diarios`).

## Lançado (ERP) vs. fita

- O ERP fornece os totais a **crédito** do mês por diário e documento via
  `GET contabilidade/movimentosdiarios?strCodExercicio=Y&intMes=M&strDeb_Cre=C`
  (endpoint novo na `api.erpsinc.pt`, porta agregada do
  `ctb.php?act_g=movimentos`; o legacy somava `$objsctb[diario]['C']`).
- Base ERP da empresa: `accounting_entities.erp_database`
  (`resolveAccountingEntityDatabase()`).
- Verde quando o total da fita = total lançado (ao cêntimo), vermelho caso
  contrário.
- "Conferir" abre a conferência do diário: à esquerda a fita (editável —
  Enter adiciona; aceita vários valores separados por `+`, `;` ou linha, com
  vírgula ou ponto decimal; cada valor editável/eliminável), à direita os
  documentos com o total a crédito. Valores sem par a vermelho e listas de
  divergentes no fim.
- **Diferença face ao legacy**: o emparelhamento conta repetições (em
  cêntimos). O legacy usava `in_array()`, pelo que dois valores iguais na
  fita ficavam "certos" com um só documento.
- A fita deixa de poder ser alterada depois de o mês estar fechado.

## SAF-T vs. contabilidade

- SAF-T: `saft_total_debit` / `saft_total_credit` da submissão do mês na
  tarefa "Envio de SAF-T" (`accounting_saft_submissions`, preferindo a última
  aceite pela AT). No legacy vinham dos campos adicionais 7/8 do ficheiro
  SAF-T na ficha do cliente. Sem SAF-T, conta como 0,00 (como no legacy).
- Contabilidade: fórmulas do balancete (`fetchErpVatAccountBalances()`,
  mesmo formato `C<conta>[cre|deb]<+|->` do Apuramento de IVA), nas
  settings `confirmacao_pasta_saft_credit_formula` /
  `confirmacao_pasta_saft_debit_formula` (legacy: `ctb_ctas_SAFTC` /
  `ctb_ctas_SAFTD`). Termos sem lado usam o saldo credor (fórmula de
  créditos) ou devedor (fórmula de débitos), como `ctaFormula(..., "fltCredito"/"fltDebito")`.
- Um último termo sem sinal (ex.: `...+C7919`) é aceite como `+`
  (`normalizeFolderSaftFormula()`). **Diferença face ao legacy**: o
  `ctaFormula()` legacy ignorava esse termo sem avisar.
- Fórmulas editáveis no modal de configurações (engrenagem no `x_title`, só
  admin/superadmin). Têm de ser preenchidas com os valores do legacy
  (Definições > "SAFT Créditos/Débitos").

## Fecho

"Fechar etapa" grava `accounting_folder_confirmations`. Como no legacy, as
divergências **não bloqueiam** o fecho; a UI pede confirmação indicando o
número de divergências, que fica registado.

## Não portado

- Exceção hardcoded do legacy para `EMP_182` (excluía contas 981/982 nos
  diários 1, 2, 4, 9).
- O legacy preferia a "CTB Própria" (campo adicional 18) à "CTB Interna"
  (campo 8) do cliente; aqui usa-se sempre `erp_database`.
