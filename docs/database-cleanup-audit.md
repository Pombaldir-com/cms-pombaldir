# Auditoria de limpeza da base de dados

Data: 2026-05-18

## Limpeza aplicada

- `accounting_documents`
  - Estado: removida por migracao em `20260518143000_drop_unused_accounting_documents.sql`
  - Evidencia: sem referencias no codigo de runtime; apenas existia em `schema.sql`

## Tabelas confirmadas como ativas

- `accounting_classifications`
- `accounting_entities`
- `accounting_imports`
- `supplier_documents`
- `ai_tasks`
- `ai_assistant_logs`
- `custom_values`
- `content_taxonomy`
- `content_type_taxonomy`
- `efatura_*`
- `internal_chat_*`
- `accounting_entity_ai_instructions`
- `accounting_additional_*`

## Colunas revistas e mantidas

As colunas abaixo pareciam candidatas a limpeza, mas foram mantidas porque existem referencias ativas no codigo:

- `content_types.show_author`
- `content_types.show_date`
- `content_types.sort_order`
- `content_types.api_enabled`
- `accounting_entities.erp_client_code`
- `accounting_imports.field_N`
- `accounting_imports.field_O`
- `accounting_imports.field_Q`
- `accounting_imports.field_R`
- `accounting_imports.account_original`
- `accounting_imports.cost_center`
- `accounting_imports.line_items`
- `accounting_imports.cab_id`
- `accounting_imports.approval_status`
- `accounting_imports.approval_note`
- `accounting_imports.approved_by`
- `accounting_imports.approved_at`

## Nota operacional

Esta limpeza foi conservadora de proposito. Antes de remover mais colunas, convem confirmar tambem:

- uso por jobs manuais/importacoes historicas
- uso por scripts fora deste repositorio
- uso em SQL ad hoc no servidor

O objetivo foi retirar apenas o legado que esta claramente orfao e evitar regressões.
