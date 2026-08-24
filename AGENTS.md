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

## Tarefas
- Cada página de tarefa (menu Tarefas, ex.: `contabilidade/tarefas/envio-saft`) que tenha configurações próprias (parâmetros específicos da tarefa, não as permissões de acesso por empresa) deve expor um botão/ícone de configurações no canto superior direito do cabeçalho `x_title` da página, abrindo um modal Gentelella.
- Esse botão/modal de configurações só é visível para utilizadores admin/superadmin (`role <= 2`); colaboradores com permissão de acesso à tarefa (`userHasAccountingEntityTaskPermission`) não veem o botão de configurações.

## Assets
- Carregar apenas os scripts/estilos necessários por rota (ex.: DataTables/Dropzone/Select2/Modal OCR).
- DataTables devem estar em Português (PT).

## DataTables (versão 2.x — armadilhas)
O projeto usa **DataTables 2.3.x** com a integração Bootstrap 5 (`vendors/datatables.net/js/dataTables.min.js` + `vendors/datatables.net-bs5/...`). O DT 2.x **renomeou todas as classes CSS** em relação ao DT 1.x. Usar os nomes antigos (em JS `closest()/find()` ou em CSS) falha **em silêncio** — o seletor não encontra nada e o layout parece "não fazer efeito". Tabela de equivalências:

| DataTables 1.x (não existe em 2.x) | DataTables 2.x (usar este) |
| --- | --- |
| `.dataTables_wrapper` | `.dt-container` (o id `#{tabela}_wrapper` mantém-se) |
| `.dataTables_length` | `.dt-length` |
| `.dataTables_filter` | `.dt-search` |
| `.dataTables_info` | `.dt-info` |
| `.dataTables_paginate` | `.dt-paging` (lista interna continua `.pagination`) |
| `.dataTables_scroll(Body)` | `.dt-scroll(-body)` |

Regras ao mexer em DataTables:
- Para alcançar o contentor a partir da tabela: `$('#tabela').closest('.dt-container')` (nunca `.dataTables_wrapper`).
- `language: { url: ... }` carrega o JSON de tradução de forma **assíncrona**, por isso o DataTables só constrói o `dom`/`layout` (e os slots/controlos) **depois** do JSON chegar. Qualquer manipulação do DOM do cabeçalho/rodapé (mover botões, filtros, etc.) tem de correr em `initComplete`/evento `init`, **nunca** de forma síncrona logo a seguir a `.DataTable({...})` — nessa altura os elementos ainda não existem.
- O `dom: "..."` é legado mas ainda funciona; divs só-com-classe (ex.: `<'col-md-4 classify-company-slot'>`) renderizam como slots vazios onde se pode injetar conteúdo. Não misturar com a API nova `layout` (não coexistem com `dom`).
- Manter **uma só** estratégia de posicionamento por controlo (slots do `dom` *ou* `layout`), para não criar funções concorrentes que se anulam.
- Confirmar dúvidas de markup renderizando uma harness isolada com os mesmos vendors em Chrome headless (`--dump-dom`/`--screenshot`), em vez de adivinhar pelas classes do DT 1.x.

## ERP
- Não usar baseUrl hardcoded; o endpoint do ERP deve vir sempre das Definições (`erp_webservice_url`).
- Em páginas com DataTables, chamar o webservice diretamente no browser (sem proxy interno).
- Em `accounting_entities`, `erp_database` e sempre a base ERP da empresa no formato `emp_XXX`.
- Em `accounting_entities`, `erp_client_code` e o codigo da entidade/cliente/fornecedor dentro da base ERP e nunca deve ser usado como substituto de `erp_database`.
- **Nunca fazer chamadas ERP síncronas por linha no render de listas/DataTables.** `fetchErpJsonForSuggestion()` (sugestões/ligação/plano de contas) faz HTTP ao ERP e era chamada por linha em `prepareImportRow` → listagem lenta e cada troca de empresa a recarregar. Está agora protegida por cache: memo por request (`$GLOBALS['erp_suggestion_request_cache']`) + cache persistente por tenant na tabela `erp_suggestion_cache` (TTL via setting `erp_suggestion_cache_ttl`, por defeito 6h), com a chave = `sha1(endpoint)` (o token vai no header, não na URL). Só respostas 2xx são cacheadas (inclui resultados vazios = negative caching). Ao adicionar novas chamadas ERP em caminhos quentes, usar `fetchErpJsonForSuggestion`/o mesmo padrão de cache, não cURL direto.

## Permissoes de departamento & perfil base "Tecnico"
- Permissoes por utilizador nao-admin (`role = 3`) vem exclusivamente dos **departamentos** a que o utilizador pertence (taxonomia `departamentos`, tabela `user_taxonomy_terms`). Cada departamento tem uma lista de chaves de permissao guardada em JSON na setting `department_permissions` (editada em Definicoes > Permissoes). Admins (`role <= 2`) tem sempre acesso total via `userHasDepartmentPermission()`.
- Existe um departamento especial, **"Tecnico (Base)"**, cujo id fica guardado na setting `baseline_department_id` (criado/obtido por `getBaselineDepartmentId()` em `functions.php`). As permissoes deste departamento aplicam-se **automaticamente a todos os utilizadores com `role = 3`**, mesmo que nao estejam formalmente atribuidos a ele — serve para definir o conjunto minimo de permissoes que todo o tecnico deve ter (ex.: `entidades_campos_adicionais_ver`, que controla a visibilidade do separador "Campos Adicionais" — incluindo campos do tipo senha — na ficha da empresa em `contabilidade/entidades.php`).
- Regras a manter ao adicionar novas "permissoes base para todos os tecnicos":
  - Adicionar a chave a `getBaseDepartmentPermissionOptions()` (`functions.php`) e, se for para ser base por omissao, tambem a `getBaselineDepartmentDefaultPermissions()`.
  - O valor por omissao so vale enquanto ninguem guardar explicitamente as permissoes do departamento "Tecnico (Base)" a partir de Definicoes; a partir do primeiro guardar, o valor gravado prevalece (ver `getDepartmentPermissions()`).
  - `departments.php` bloqueia a eliminacao do departamento "Tecnico (Base)" (comparando com `getBaselineDepartmentId()`); nao remover essa proteccao.
  - Nao confundir com `accounting_entity_admin_task_permissions` (permissao por tecnico **e por empresa especifica**, usada em `ctb_classificar_docs`/`ctb_importar_docs`/`ctb_envio_saft`) — o perfil base "Tecnico" e transversal a todas as empresas, sem atribuicao manual.

## Extranet / Area reservada de clientes
- A area reservada de cada cliente vive em `client/` e e servida pelas rotas `t/{tenantSlug}/cliente/...` (ver `router.php`). As contas vivem na tabela `client_users` e a sessao usa as chaves `client_user_id` / `client_user_tenant_slug` / `client_accounting_entity_id`.
- A gestao de contas esta no separador **Extranet > Gestao de utilizadores** da ficha da entidade (`contabilidade/entidades.php`), apenas para perfis com `role <= 2` (`$canManageClientExtranet`).
- **Impersonar**: cada utilizador tem um botao `Impersonar` (POST `action=impersonate-client-user`, abre em novo separador) que entra na area reservada sem credenciais. Implementado por `startClientImpersonation()` / `stopClientImpersonation()` / `isClientImpersonation()` em `functions.php`.
  - Gated por `$canManageClientExtranet`; valida CSRF, entidade adquirente, pertenca do utilizador a entidade e estado ativo.
  - Marca a sessao com `client_impersonator_user_id` e regista audit log (`impersonate` / `stop-impersonate`); nao termina a sessao de back-office (`user_id` mantem-se).
  - Em modo impersonacao, `client/header.php` mostra um banner com `Terminar impersonacao` (rota `t/{slug}/cliente/stop-impersonation`), que regressa a ficha da entidade.
  - Manter o botao tanto na renderizacao estatica como no JS que reconstroi linhas apos criar utilizador via AJAX (`buildExtranetUserRowHtml`).

## Classificacao/Importacao CTB
- `contabilidade/classificacao-importacao?import_type=1` e a vista de Classificacao.
- Nesta vista, o botao por linha usa:
  - `Classificar` (quando incompleto)
  - `Classificado` (quando pronto/verde)
- Nesta vista existe o botao global `Classificado`, que processa/importa as linhas verdes diretamente para contabilidade.
- O botao global `Classificado` tem de suportar selecao de documentos de varios adquirentes ao mesmo tempo; nao reintroduzir validacoes que exijam um unico adquirente por operacao.
- Quando existem varios adquirentes selecionados, a importacao CTB deve agrupar internamente as linhas por `accounting_entities.erp_database` do adquirente e importar cada grupo para a respetiva base ERP.
- A selecao manual de base de dados do adquirente so deve surgir quando existir exatamente um adquirente sem `erp_database` resolvida; se houver varios adquirentes resolvidos, o fluxo deve continuar sem bloquear.
- A validacao e gravacao das associacoes QR (`qr_doc_type_mapping`) tambem tem de respeitar a base ERP de cada grupo de adquirente, e nao uma unica base global da selecao.
- No fluxo multi-base, a mensagem final da importacao nao pode ocultar detalhes de duplicados ja devolvidos pelo ERP; se um documento ja existir, a UI deve continuar a mostrar essa informacao e o nº de lancamento/diario devolvido pelo webservice.
- `contabilidade/classificacao-importacao?import_type=1&type=import` e a vista de Importacao.
- Nesta vista aparecem apenas as linhas verdes e o botao `Importar Ctb`.
- O item de menu `Importação` (`type=import`) deve ficar visivel apenas para utilizadores com permissao `ctb_importar_docs`.

## ERP API knowledge
- This system uses the ERPSINC API; use the updated OpenAPI file at `/Users/nelsonsantos/Sites2026/api.erpsinc.pt/erpsync-api.yaml` as the primary reference when working on integrations, together with the local repo at `/Users/nelsonsantos/Sites2026/api.erpsinc.pt`.
- When there is doubt about the real SQL Server schema behind the ERP webservice (column length, type, nullability), do not guess from PHP alone. Prefer confirming it directly on `api.erpsinc.pt` with a temporary probe that queries `INFORMATION_SCHEMA.COLUMNS`, then remove that probe immediately after use.
- This was already confirmed for `Movimentos_Ctb_Cab.strNum_Doc`, whose real SQL Server type is `varchar(30)`.

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

## Legacy (intranet.zcontas.pt)
- A aplicacao anterior (intranet legacy) vive em `/Users/nelsonsantos/Sites2026/intranet.zcontas.pt/intranet` (repo local separado, so leitura, so para consulta de logica/UX antiga).
- Ex.: `data/gest_clientes.php` contem a logica legacy de deteccao de vendas intracomunitarias/pais terceiro (durante o processamento do SAF-T) e o popup com os botoes "Download Ficheiro" / "Enviar Ficheiro" para a Declaracao Recapitulativa (gera um `.txt` fixo `RECAP-<timestamp>.txt` em `uploadDir/tmp`, download via `window.php?act=download`, envio por email via accao `mailsendadmin`, destino configuravel em `destRecapitulativa`).
- Ao portar funcionalidade legacy para este projeto, confirmar sempre no codigo antigo o formato de dados exato antes de reimplementar — nao adivinhar a partir de capturas de ecra.
