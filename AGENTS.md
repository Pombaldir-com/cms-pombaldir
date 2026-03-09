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
- DataTables devem estar em Português (PT).

## ERP
- Não usar baseUrl hardcoded; o endpoint do ERP deve vir sempre das Definições (`erp_webservice_url`).
- Em páginas com DataTables, chamar o webservice diretamente no browser (sem proxy interno).

## Classificacao/Importacao CTB
- `contabilidade/classificacao-importacao?import_type=1` e a vista de Classificacao.
- Nesta vista, o botao por linha usa:
  - `Classificar` (quando incompleto)
  - `Classificado` (quando pronto/verde)
- Nesta vista existe o botao global `Classificado`, que processa/importa as linhas verdes diretamente para contabilidade.
- `contabilidade/classificacao-importacao?import_type=1&type=import` e a vista de Importacao.
- Nesta vista aparecem apenas as linhas verdes e o botao `Importar Ctb`.
- O item de menu `Importação` (`type=import`) deve ficar visivel apenas para utilizadores com permissao `ctb_importar_docs`.

## ERP API knowledge
- This system uses the ERPSINC API; use the updated OpenAPI file at `/Users/nelsonsantos/Sites2026/api.erpsinc.pt/erpsync-api.yaml` as the primary reference when working on integrations, together with the local repo at `/Users/nelsonsantos/Sites2026/api.erpsinc.pt`.

## Multi-DB & tenants
- The system can use multiple databases per company; tenant selection is required for company-scoped data.
- Use the tenant-aware flows and apply schema changes via migrations in `migrations/`.
- Documentacao relevante: `README.md` (contexto geral) e `migrations/` (historico de schema).

## Debug mode
- The setting `debug_mode` is configurable in **Definicoes > Geral** and enables extra diagnostics.
- Use `getSetting('debug_mode', '0')` to gate debug logs; `contabilidade/upload-handler.php` writes `contabilidade/debug_qr.txt` when enabled.

## Assistente AI memory
- Persistir memoria e contexto entre sessoes em `ai_assistant_logs`.
- O comportamento por defeito e memorizar instrucoes/mensagens do utilizador.
- Se o utilizador indicar "esquece", "errado" ou equivalente, nao memorizar essa mensagem e remover a memoria recente quando aplicavel.
- Permitir comandos explicitos no chat: `memoriza: ...`, `listar memorias`, `esquece: ...`, `esquece memorias`.

## Testing
- No formal test runner defined; validate changes manually when needed.

## Run locally
- Requires PHP (and a web server if not using the PHP built-in server).
- Quick start: `php -S localhost:8000 router.php`
- Ensure the database is configured in `data/db.php` before logging in.
