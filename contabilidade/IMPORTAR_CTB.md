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

## Regras de leitura QR por ficheiro

- Um mesmo PDF pode conter vários QR/documentos; o sistema mantém multiplas faturas `FT`/`FR` do mesmo ficheiro.
- Se o mesmo ficheiro contiver pelo menos uma fatura (`FT` ou `FR`) e tambem um recibo (`RC` ou `RG`), os recibos sao ignorados.
- Esta regra e aplicada na grelha de upload, na listagem de classificacao e repetida no backend no momento do `import`, para garantir consistencia.
- Quando uma fatura e mantida mas o mesmo ficheiro tinha tambem um `RC`/`RG`, a linha fica marcada internamente com contexto de `recibo associado`.
- Esse contexto nao cria linhas extra; serve para a memoria da sugestao distinguir:
  - faturas isoladas, em que `Valor Total` tende a apontar para conta do fornecedor;
  - faturas acompanhadas por recibo no mesmo PDF, em que `Valor Total` pode ser corrigido pelo utilizador para uma conta de bancos.

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

Parâmetros recomendados para esta chamada (dinâmicos por documento):

- `act=importMovim`
- `datadoc` (data do documento no formato `YYYY-MM-DD`, vinda do QR)
- `strNIF` (privilegiar o NIF do emitente; o sistema pode testar candidatos alternativos quando necessário)
- `db`
- `strTpDoc` (tipo documental normalizado para o ERP)
- `strCodExercicio` (quando disponivel, usar o ano do documento)

Normalizacao documental usada nesta consulta:

- `FT` -> `FT`
- `FR` / `FTR` -> `FR`
- `RC` / `RG` -> `RC`

Exemplo:

`GET /contabilidade/LigacaoCteTipoDoc?datadoc=2026-01-12&strNIF=504128582&db=emp_566&strCodExercicio=2026&strTpDoc=FT`

O webservice devolve as linhas candidatas, mas a aplicação é que faz a seleção final da linha correta por taxa usando `PC_Descricao` e os dados do documento.

Regras adicionais desta fonte:

- Se o ERP devolver contas por taxa em `strConta` e `strConta_Iva`, essas contas devem ser privilegiadas mesmo quando o plano nao ajuda a mapear a taxa de forma explicita.
- Em documentos com apenas uma taxa, a conta IVA da ligacao pode ser usada diretamente se a separacao por taxa nao for ambigua.
- Se `LigacaoCteTipoDoc` nao devolver resultados para o fornecedor/tipo, a aplicacao nao deve inventar `Conta Geral`.
- Nessa situacao, apenas pode surgir uma sugestao de `Valor Total` para bancos (`12...`) quando a fatura tiver recibo associado no mesmo PDF.
- A explicacao da sugestao deve mencionar explicitamente quando o fornecedor nao foi encontrado no ERP.

## Regras de classificacao

- Em taxas `0%`, `Conta IVA` deve ficar vazia.
- A ausencia de `Conta IVA` numa taxa `0%` nao impede o estado `Classificado` (verde).
- A grelha de classificacao mostra apenas o NIF do emitente na coluna visivel.
- O botao global `Classificado` importa a configuracao contabilistica efetiva do documento, incluindo `Valor Total` vindo da classificacao generica, mesmo sem abrir antes a modal.

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
- `accounting_entities.erp_database` e sempre a base ERP `emp_XXX`; `accounting_entities.erp_client_code` guarda o codigo da entidade dentro dessa base e nao deve ser usado como fallback da base ERP.
- Na modal, o parâmetro `db` é enviado com a base de dados ERP do adquirente associada à linha/documento selecionado.
- É enviado `EMP` com o valor de **Módulos > Contabilidade > Empresa base** (`accounting_base_company`).
- Os resultados do plano são mantidos em cache no cliente por contexto (db + NIF adquirente + exercício), para resposta rápida durante a escrita.

## Memoria da sugestao de contas

A sugestao de contas cruza varias fontes:

- historico em `accounting_imports`;
- regras em `accounting_classifications`;
- `LigacaoCteTipoDoc`;
- movimentos ERP;
- plano de contas ERP como fallback final.

Para a conta de `Valor Total`, a memoria do historico ja nao trata todos os documentos da mesma forma. O fator de memoria passa tambem a distinguir:

- documentos sem `RC`/`RG` no mesmo ficheiro;
- documentos cuja digitalizacao continha tambem um `RC`/`RG`.

Isto e importante porque, no segundo caso, o utilizador pode trocar a conta do `Valor Total` de fornecedor para uma conta de bancos. Essa decisao passa a ser aprendida e reutilizada apenas para documentos do mesmo contexto, evitando contaminar a sugestao de faturas normais.

Persistencia adicional:

- A classificacao generica passa a guardar tambem `receipt_total_account` para o contexto "fatura com recibo associado".
- Isto permite recuperar a conta de `Valor Total` mesmo quando o registo individual em `accounting_imports.account` e apagado ou limpo.
- Os modelos guardados pelo utilizador preservam `Valor Total`, alem das contas por taxa e centros de custo.

## Lançamentos ERP

No fluxo `contabilidade/lancamentos`:

- fechar a modal volta a recarregar a grelha a partir do ERP;
- a eliminacao do lancamento tenta apagar primeiro os anexos digitais;
- se a eliminacao do anexo falhar, o lancamento nao e removido;
- a mensagem de duplicado no ERP passa a indicar a localizacao do lancamento existente;
- em notas de credito (`NC`), a linha de `Valor Total` e preservada e a natureza debito/credito e ajustada ao tipo documental.

Ou seja, ao chamar o webservice "Importar CTB" são reenviados todos os dados disponíveis no MySQL para cada documento seleccionado, permitindo que o ERP trate a importação com base na informação integral (emitente, adquirente, totais, referências, centro de custo, contas, ficheiro associado, etc.).

## Importacao global com varios adquirentes

Na vista `contabilidade/classificacao-importacao?import_type=1`, o botao global `Classificado` tem de continuar a aceitar documentos de varios adquirentes na mesma operacao.

Regras que nao devem regredir:

- Nao bloquear a importacao com erro de "mais do que um adquirente associado" quando as linhas selecionadas pertencem a varios adquirentes validos.
- A importacao tem de agrupar as linhas pela base ERP do adquirente (`accounting_entities.erp_database`) e chamar a importacao CTB uma vez por cada base.
- A modal para escolher a base do adquirente so deve aparecer quando existir exatamente um adquirente sem base ERP definida; nao deve substituir o fluxo multi-adquirente quando as bases ja estao resolvidas.
- As associacoes de tipo documental QR tambem sao por base ERP do grupo; quando a selecao tiver varias bases, a validacao e a gravacao das associacoes devem respeitar esse agrupamento.
- Se houver sucesso parcial entre bases ERP, a resposta deve indicar importacao parcial em vez de falhar toda a operacao silenciosamente.
- Mesmo em resposta agregada por varias bases, a mensagem final nao pode perder os detalhes de documentos ja existentes devolvidos pelo ERP; quando houver duplicados, deve continuar a indicar que o documento ja esta lancado e o nº de diario/lancamento devolvido no `recs.exist`.
