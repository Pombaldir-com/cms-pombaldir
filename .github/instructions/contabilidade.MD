# Instruções Contabilidade

## Objetivo
Guia para implementar funcionalidades de contabilidade neste projeto (classificação, importação, upload e QR), mantendo consistência funcional e técnica.

## Regras Gerais
- Responder e apresentar textos em PT-PT.
- Não inventar dados de ERP, contas, movimentos ou documentos.
- Respeitar permissões e contexto de empresa/tenant.
- Em ações de escrita sensíveis, usar confirmação explícita quando aplicável.

## Multi-tenant e Base de Dados
- Para dados por empresa, garantir seleção de tenant antes de ler/escrever.
- Em alterações de schema, criar migration em `migrations/` (não alterar só `schema.sql`).
- Tabela principal de importação: `accounting_imports`.
- Campo NIF do adquirente: `accounting_imports.field_B`.
- Ligação empresa ERP:
1. `accounting_imports.field_B` -> `accounting_entities.nif`
2. usar `accounting_entities.erp_database`

## ERP (ERPSINC)
- Nunca hardcode do endpoint ERP.
- Obter endpoint sempre das definições: `erp_webservice_url`.
- Em páginas com DataTables, consumir webservice diretamente no browser (sem proxy interno).
- Campos ERP (`str*`, `int*`, `flt*`) pertencem ao payload ERP, não ao schema MySQL local.

## Classificação de Contas
- Seguir prioridade obrigatória:
1. Histórico MySQL (ex.: `accounting_classifications` e exemplos por fornecedor/tipo doc).
2. Movimentos ERP.
3. Plano de contas ERP.
- Não saltar diretamente para heurísticas sem consultar histórico e ERP.
- Conta geral: preferir gastos (`62`, `63`, `6`), evitar fornecedores/clientes (ex.: classe 21) para despesas.
- Conta IVA: priorizar histórico; se faltar, inferir por taxa e validar no plano de contas.

## Estrutura de Importação e Conta
- Campo `accounting_imports.account` guarda JSON de contas por taxa.
- Preservar estrutura existente (`version`, `rates`, `meta.total_account`).
- Não sobrescrever valores já preenchidos sem ação explícita do utilizador.

## UI de Classificação
- Manter padrões Gentelella (componentes, classes e linguagem visual atual).
- Na ação `classify-row`, preencher apenas campos vazios:
1. `.general-account-field`
2. `.iva-account-field`
- Aplicar apenas ao contexto/taxa ativa da linha.

## Upload e Processamento
- Não carregar assets globais desnecessários; incluir por rota (Dropzone, Select2, DataTables, modal OCR).
- Validar ficheiro, empresa e tenant antes de gravar registos.
- Em falhas de parsing/importação, devolver erro claro e acionável (sem expor detalhes sensíveis).

## QR e Diagnóstico
- Usar `getSetting('debug_mode', '0')` para ativar logs de diagnóstico.
- Em fluxo de contabilidade/upload, escrever debug apenas com `debug_mode=1`.
- Ficheiro de debug QR: `contabilidade/debug_qr.txt`.

## Qualidade e Validação Manual
- Não existe test runner formal obrigatório.
- Validar manualmente:
1. Upload de documento.
2. Extração/leitura QR.
3. Sugestão de contas por taxa.
4. Persistência de classificação.
5. Comportamento em tenant/empresa incorreta.
