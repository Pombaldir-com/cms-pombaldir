# Webservice "Importar CTB"

Este projeto comunica com o ERP através da função [`import_CTB`](classificacao-importacao.php). A chamada ao botão "Importar CTB" dispara um pedido HTTP `POST` para o endpoint configurado em `erp_webservice_url` (terminando em `contabilidade.php`) e envia, no mesmo payload, os dados completos de cada documento seleccionado.

## Vistas da interface

### 1) Classificação
URL: `contabilidade/classificacao-importacao?import_type=1`

- Mostra a listagem de documentos para classificar.
- O botão por linha muda conforme o estado:
  - `Classificar` (normal)
  - `Classificado` (verde, pronto para importação)
- Existe botão global `Classificado` para processar/importar as linhas verdes.
- Requer permissão `ctb_classificar_docs` para classificar por linha (quando não existe, o botão fica desativado).

### 2) Importação
URL: `contabilidade/classificacao-importacao?import_type=1&type=import`

- Mostra apenas as linhas verdes/prontas.
- Exibe o botão `Importar Ctb`.
- O link para esta vista no menu lateral fica disponível apenas para utilizadores com permissão `ctb_importar_docs`.
- O acesso direto à URL desta vista sem `ctb_importar_docs` devolve `403`.

## Matriz de permissões (resumo)

- `ctb_classificar_docs`:
  - Classificar documentos na vista principal (`Classificar` por linha).
- `ctb_importar_docs`:
  - Aceder ao menu/URL da vista `type=import`.
  - Importar documentos na vista dedicada (`Importar Ctb`).
- `superadmin` e `administrador`:
  - Têm acesso total a estas capacidades.

## Workflow recomendado

1. Entrar na vista **Classificação** (`import_type=1`) e completar documentos até ficarem `Classificado` (verde).
2. Validar que as linhas verdes representam os documentos prontos para envio.
3. Importar:
   - Na própria vista de Classificação, através do botão global `Classificado`; ou
   - Na vista **Importação** (`type=import`), através do botão `Importar Ctb` (quando aplicável por permissão).

O payload enviado para o ERP é construído em [`contabilidade/classificacao-importacao.php`](classificacao-importacao.php) e inclui os seguintes campos de formulário:

- `tp = importMovim`
- `act = importMovim`
- `accao = movimentos`
- `import_type` com o tipo de importação activo
- `document_ids` com a lista de IDs seleccionados
- `documents` com um JSON que contém todas as colunas de cada linha existente na tabela `accounting_imports` (incluindo, quando presente, o conteúdo estruturado de `line_items`).
- `database` com a BD alvo da empresa (quando disponível)
- `db` para compatibilidade com endpoints legados
- `EMP` para seleção/autenticação da empresa no ERP

`EMP` é sempre obtido de **Módulos > Contabilidade > Empresa base**.

## Endpoint de explicação da sugestão (Classificação)

Na vista de classificação existe também o endpoint:

- `POST contabilidade/classificacao-importacao/suggestion-explanation`

Este endpoint devolve uma explicação por taxa para as contas sugeridas, incluindo evidências de:

- histórico MySQL;
- regras em `accounting_classifications`;
- ligação ERP por entidade/tipo (`contabilidade/LigacaoCteTipoDoc`);
- movimentos ERP (`contabilidade/movimentos`);
- plano de contas ERP (`contabilidade/planocontas`) como fallback (última opção).

No caso de `contabilidade/LigacaoCteTipoDoc`, o mapeamento usado é:

- `strConta` -> conta geral;
- `strConta_Iva` -> conta IVA;
- linha `strTipo = C` / conta da entidade -> conta de valor total.

Para escolher a linha correta por taxa de IVA, a regra principal deve ser:

- `PC_Descricao = TAXA REDUZIDA` -> `6%`;
- `PC_Descricao = TAXA INTERMEDIA` / `TAXA INTERMÉDIA` -> `13%`;
- `PC_Descricao = TAXA NORMAL` -> `23%`.

Os campos `fltVatRate`, `fltTaxaValor` e equivalentes podem vir preenchidos a `0`/`.000000`, por isso nao devem ser usados como fonte principal para descobrir a taxa.

### Ordem de pesquisa de bases ERP (`database_candidates`)

Para a pesquisa em `LigacaoCteTipoDoc` no endpoint de explicação, as bases ERP candidatas são tentadas por esta ordem (a primeira combinação BD+NIF que devolver linhas é usada):

1. BD resolvida do contexto atual do documento (adquirente/emitente já identificados);
2. BD do emitente (`accounting_entities`, quando o emitente também é uma entidade conhecida);
3. BD do adquirente (`accounting_entities`);
4. **BDs de outras empresas onde este mesmo emitente já apareceu em `accounting_imports`** (por `field_C`/`field_A`, ordenado por mais recente), via `findAccountingEntityDatabasesForEmitterHistory()` — até 5 bases distintas, excluindo as já tentadas acima;
5. BD "Empresa base" (Definições > Contabilidade, `accounting_base_company`) — fallback final, só tentado se nenhuma das anteriores devolveu resultado.

Esta ordem existe porque a ligação fornecedor→contas no ERP é parametrizada por empresa; se o fornecedor ainda não estiver configurado na base do adquirente atual mas já tiver sido classificado noutra empresa cliente, essa base tem prioridade sobre o fallback genérico da empresa base.

Parâmetros recomendados para esta chamada (dinâmicos por documento):

- `act=importMovim`
- `datadoc` (data do documento no formato `YYYY-MM-DD`, vinda do QR)
- `strNIF` (NIF do adquirente)
- `db`
- `strTpDoc` (tipo documental vindo do QR, ex.: `FT`)

Exemplo:

`GET /contabilidade/LigacaoCteTipoDoc?datadoc=2026-01-12&strNIF=513364790&db=emp_306&strTpDoc=FT`

O webservice devolve as linhas candidatas, mas a aplicação é que faz a seleção final da linha correta por taxa usando `PC_Descricao` e os dados do documento.

### Debug: dados brutos de todas as fontes de sugestão

Com `debug_mode` ativo (Definições > Geral), a resposta de `suggestion-explanation` inclui um bloco `debug` com uma sub-chave por fonte consultada nesta reanálise — não só `LigacaoCteTipoDoc`:

- `ligacao_cte_tipo_doc`: todas as combinações BD/NIF tentadas, a origem de cada BD candidata (`database_candidate_sources`, incluindo `emitter_history_databases` — bases de outras empresas com histórico deste emitente — e `default_database_fallback`), a BD finalmente usada (`resolved_database`), as linhas brutas do ERP (`rows`) e o agrupamento por taxa (`per_rate`);
- `erp_movimentos`: linhas devolvidas por `contabilidade/movimentos` e a contagem de ocorrências por conta (`accounts`);
- `erp_planocontas`: linhas devolvidas por `contabilidade/planocontas` e as contas gerais/IVA disponíveis nesse plano;
- `mysql_history`: amostras usadas de `accounting_imports` (histórico de classificações manuais) e as contas mais frequentes por taxa;
- `mysql_classification_rules`: idem, a partir de `accounting_classifications`;
- `backoffice_instructions`: instruções ativas (globais em Definições e/ou por par emitente/adquirente em `accounting_entity_ai_instructions`), a ordem de origem (`source_order`) e as contas extraídas por taxa.

Cada sub-bloco existe **só quando o endpoint efetivamente encontrou dados nessa fonte** (ex.: se não há instruções guardadas para o par emitente/adquirente, `backoffice_instructions` não aparece com dados relevantes). Isto é importante para diagnosticar de onde vem uma conta sugerida que não parece bater certo com o `LigacaoCteTipoDoc`: pode ter vindo de Movimentos ERP, Plano de Contas, Histórico MySQL ou Regras — a resposta completa deixa isso auditável.

Na modal "Explicação da sugestão", cada sub-bloco aparece como uma secção `<details>` colapsável própria, com o JSON correspondente. Sem `debug_mode` ativo, o bloco `debug` não é incluído na resposta.

No JSON de cada secção, as contas que coincidem com a sugestão final aplicada pelo agente (Conta Geral, Conta IVA por taxa, ou Conta de Valor Total) ficam realçadas a amarelo (`<mark>`), para o IT identificar rapidamente de onde veio cada valor.

Adicionalmente, no topo da modal aparece sempre um aviso com a **origem real** reportada pelo próprio agente no momento em que aplicou a sugestão (`window.aiSuggestionSources`, ex.: "Instruções IA da entidade", "Histórico MySQL", "Movimentos ERP") — esta é a fonte de verdade; os blocos de debug acima são uma reanálise feita a posteriori para fins de diagnóstico e podem, em casos raros, não coincidir exatamente (ex.: dados do ERP entretanto alterados).

## Modal de Classificação: auto-sugestão de contas por escrita

Na modal de **Classificação** (`import_type=1`), os campos:

- `Conta IVA`
- `Conta Geral`
- `Valor Total`

passam a ter sugestões automáticas enquanto o utilizador digita.

Regras de funcionamento:

- As sugestões são lidas do endpoint ERP `GET /contabilidade/planocontas`.
- A chamada é feita no browser para o webservice configurado em `erp_webservice_url`, usando `X-API-KEY`.
- Sempre que possível, é usada a base de dados ERP do adquirente (`accounting_entities.erp_database`).
- Na modal, o parâmetro `db` é enviado com a base de dados ERP do adquirente associada à linha/documento selecionado.
- É enviado `EMP` com o valor de **Módulos > Contabilidade > Empresa base** (`accounting_base_company`).
- Os resultados do plano são mantidos em cache no cliente por contexto (db + NIF adquirente + exercício), para resposta rápida durante a escrita.

Ou seja, ao chamar o webservice "Importar CTB" são reenviados todos os dados disponíveis no MySQL para cada documento seleccionado, permitindo que o ERP trate a importação com base na informação integral (emitente, adquirente, totais, referências, centro de custo, contas, ficheiro associado, etc.).
