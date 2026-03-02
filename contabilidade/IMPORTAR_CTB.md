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
- movimentos ERP (`contabilidade/movimentos`);
- plano de contas ERP (`contabilidade/planocontas`).

Ou seja, ao chamar o webservice "Importar CTB" são reenviados todos os dados disponíveis no MySQL para cada documento seleccionado, permitindo que o ERP trate a importação com base na informação integral (emitente, adquirente, totais, referências, centro de custo, contas, ficheiro associado, etc.).
