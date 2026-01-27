# AGENTS.md

## Overview
This repository contains a PHP-based CMS with a custom router and Apache rewrite rules.

## Local conventions
- Use ASCII in code/comments unless the file already contains non-ASCII.
- Prefer `rg` for search.
- Avoid destructive git commands unless explicitly requested.

## Web routing
- Friendly URLs are handled by `router.php`.
- Apache rewrites live in `/.htaccess` and should route all non-file requests to `router.php`.

## Paths & config
- `functions.php` defines `BASE_URL` from `$_SERVER['SCRIPT_NAME']`.
- Keep URL building consistent with `BASE_URL`.

## UI / Template
- Use the Gentelella template and its components (`x_panel`, `x_title`, tabs, switches, badges) for all UI/layout changes.
- Sempre usar componentes do tema Gentelella (não introduzir padrões fora do tema).
- Preserve the existing visual language and class naming when editing views.

## Menu lateral
- Manter o item do menu lateral aberto/ativo nas páginas filhas (ex.: entidades/empresas/ID) e preservar o estado do toggle (colapsado/aberto) com persistência.

## Assets
- Carregar apenas os scripts/estilos necessários por rota (ex.: DataTables/Dropzone/Select2/Modal OCR).

## ERP
- Não usar baseUrl hardcoded; o endpoint do ERP deve vir sempre das Definições (`erp_webservice_url`).

## ERP API knowledge
- This system uses the ERPSINC API; consult the documentation and the local repo at `/Users/nelsonsantos/Sites2026/api.erpsinc.pt` when working on integrations. online Docs: https://app.swaggerhub.com/apis-docs/Pombaldir.com/ERPSinc/1.0.0

## Multi-DB & tenants
- The system can use multiple databases per company; tenant selection is required for company-scoped data.
- Use the tenant-aware flows and apply schema changes via migrations in `migrations/`.
- Documentacao relevante: `README.md` (contexto geral) e `migrations/` (historico de schema).

## Debug mode
- The setting `debug_mode` is configurable in **Definicoes > Geral** and enables extra diagnostics.
- Use `getSetting('debug_mode', '0')` to gate debug logs; `contabilidade/upload-handler.php` writes `contabilidade/debug_qr.txt` when enabled.

## Testing
- No formal test runner defined; validate changes manually when needed.

## Run locally
- Requires PHP (and a web server if not using the PHP built-in server).
- Quick start: `php -S localhost:8000 router.php`
- Ensure the database is configured in `data/db.php` before logging in.
