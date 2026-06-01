# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Visão geral

CMS/CRM em PHP (sem framework) baseado no tema **Gentelella** (Bootstrap). Cresceu de um CMS simples (tipos de conteúdo, campos personalizados, taxonomias) para uma aplicação multi-tenant de contabilidade que integra com o webservice **ERP-SINC** e com o **E-fatura**, e que inclui um assistente AI interno (PT-PT).

Não há framework MVC. As "páginas" são scripts PHP na raiz e em `contabilidade/`, servidos por um router de URLs amigáveis.

## Comandos

```bash
composer install              # dependências PHP (Tesseract OCR, pdfparser, qrcode, fpdf)
php scripts/migrate.php       # aplicar migrações (alias: npm run migrate)
php -S localhost:8001 router.php   # servidor de desenvolvimento (alias: npm run start)
mysql -u root -p < schema.sql # esquema inicial (cria admin/admin123)
```

- Não existe test runner formal nem lint configurado. Validar alterações manualmente. `tests/` contém apenas scripts ad-hoc (`redirect_test.php`).
- Credenciais da BD em [data/db.php](data/db.php). A aplicação usa MySQL/MariaDB (a `data/database.sqlite` é auxiliar).

## Arquitetura

- **Routing**: [router.php](router.php) recebe todos os pedidos não-ficheiro (via [.htaccess](.htaccess)) e inclui o script certo. URLs amigáveis (`dashboard`, `login`, `contabilidade/classificacao-importacao`). Rotas de cliente/extranet usam o padrão `t/{tenantSlug}/cliente/...` → scripts em [client/](client/).
- **`BASE_URL`**: definido em [functions.php](functions.php) a partir de `$_SERVER['SCRIPT_NAME']`. Construir sempre URLs com `BASE_URL`; nunca hardcoded.
- **Núcleo partilhado**: [functions.php](functions.php) (~139KB) concentra helpers de sessão, settings, permissões, tenants e ERP. [header.php](header.php)/[footer.php](footer.php) montam o layout Gentelella.
- **Migrações**: SQL versionado por timestamp em [migrations/](migrations/), aplicado por `scripts/migrate.php`. Toda alteração de schema passa por aqui — não editar `schema.sql` para mudanças incrementais.
- **Contabilidade**: módulo principal em [contabilidade/](contabilidade/). Fluxo Classificação → Importação CTB para o ERP, leitura de QR/PDF, E-fatura, OCR (Tesseract por defeito, AWS Textract opcional via `contabilidade/textract.py`).
- **Assistente AI**: [assistant-handler.php](assistant-handler.php) (~278KB) é o handler principal; persiste memória/contexto entre sessões na tabela `ai_assistant_logs`. Scripts Python auxiliares em [scripts/](scripts/) e `contabilidade/` (`ai_document_reader.py`, `detectar_qr.py`, `efatura_worker.py`).
- **Multi-tenant / multi-DB**: a app pode usar várias bases por empresa. A seleção de tenant é obrigatória para dados com escopo de empresa. A identidade ERP da empresa resolve-se por `accounting_entities.erp_database` no formato `emp_XXX`.

### Integração ERP-SINC (regras críticas)

- O endpoint ERP vem **sempre** das Definições (`erp_webservice_url`) — nunca hardcoded. Em páginas com DataTables, chamar o webservice diretamente no browser (sem proxy interno).
- Enviar `EMP` (= `Empresa base` do módulo Contabilidade, `accounting_base_company`) e, quando aplicável, `db`/`database` (compatibilidade legada).
- Campos ERP usam prefixos `str*`/`int*`/`flt*` e **não existem** no MySQL local.
- Em dúvida sobre o schema real do SQL Server por trás do ERP, confirmar em `api.erpsinc.pt` com um probe temporário (`INFORMATION_SCHEMA.COLUMNS`) e removê-lo logo a seguir — não adivinhar a partir do PHP.
- Referência da API: `/Users/nelsonsantos/Sites2026/api.erpsinc.pt/erpsync-api.yaml` (OpenAPI) e o repo local `/Users/nelsonsantos/Sites2026/api.erpsinc.pt` (só leitura).

## Convenções

- ASCII em código/comentários, salvo se o ficheiro já contiver não-ASCII. Preferir `rg` na pesquisa.
- Evitar comandos git destrutivos sem pedido explícito.
- UI **sempre** com componentes do tema Gentelella (`x_panel`, `x_title`, tabs, switches, badges). Preservar a linguagem visual e o naming de classes existente.
- Manter o item do menu lateral ativo/aberto nas páginas filhas e preservar o estado do toggle (persistência).
- Carregar apenas os scripts/estilos necessários por rota (DataTables/Dropzone/Select2/Modal OCR). DataTables em Português (PT).
- **DataTables é a versão 2.x**: as classes CSS mudaram (`.dataTables_wrapper`→`.dt-container`, `.dataTables_length`→`.dt-length`, `.dataTables_filter`→`.dt-search`, `.dataTables_paginate`→`.dt-paging`). Usar os nomes antigos falha em silêncio. Como `language.url` é assíncrono, manipular o DOM do cabeçalho/rodapé só em `initComplete`. Tabela completa e regras em [AGENTS.md](AGENTS.md) (secção "DataTables (versão 2.x — armadilhas)").
- `debug_mode` (Definições > Geral): gatilhar logs extra com `getSetting('debug_mode', '0')`.

## Documentação de referência

A documentação detalhada do domínio vive nos ficheiros abaixo. **Ler o ficheiro relevante antes de mexer na área correspondente** (não estão pré-carregados — abrir sob demanda):

- [README.md](README.md) — contexto geral, instalação, API, integração ERP, Textract OCR, e fluxo Classificação/Importação CTB.
- [AGENTS.md](AGENTS.md) — convenções de routing, UI Gentelella, ERP, regras CTB multi-base e memória do assistente.
- [AI_ASSISTANT.md](AI_ASSISTANT.md) — comportamento, escopo, modo seguro, nomenclatura e regras de sugestão de contas do assistente AI.
- [AI_ASSISTANT_IDENTITY.md](AI_ASSISTANT_IDENTITY.md) — identidade do assistente por tenant (resolução por NIF da empresa).
- [contabilidade/IMPORTAR_CTB.md](contabilidade/IMPORTAR_CTB.md) — detalhe do webservice "Importar CTB", vistas e matriz de permissões.
- [docs/database-cleanup-audit.md](docs/database-cleanup-audit.md) — auditoria de tabelas ativas vs. removidas.
