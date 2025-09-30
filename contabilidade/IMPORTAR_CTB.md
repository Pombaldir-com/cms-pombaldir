# Webservice "Importar CTB"

Este projeto comunica com o ERP através da função [`import_CTB`](classificacao-importacao.php). A chamada ao botão "Importar CTB" dispara um pedido HTTP `POST` para o endpoint configurado em `erp_webservice_url` e envia, no mesmo payload, os dados completos de cada documento seleccionado.

O payload enviado para o ERP é construído em [`contabilidade/classificacao-importacao.php`](classificacao-importacao.php) e inclui os seguintes campos de formulário:

- `tp = importMovim`
- `act = importMovim`
- `accao = movimentos`
- `import_type` com o tipo de importação activo
- `document_ids` com a lista de IDs seleccionados
- `documents` com um JSON que contém todas as colunas de cada linha existente na tabela `accounting_imports` (incluindo, quando presente, o conteúdo estruturado de `line_items`).

Ou seja, ao chamar o webservice "Importar CTB" são reenviados todos os dados disponíveis no MySQL para cada documento seleccionado, permitindo que o ERP trate a importação com base na informação integral (emitente, adquirente, totais, referências, centro de custo, contas, ficheiro associado, etc.).
