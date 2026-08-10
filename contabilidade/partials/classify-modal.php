<?php
$classifyModalImportType = isset($classifyModalImportType) ? (int) $classifyModalImportType : 1;
$classifyModalShowAiButtons = isset($classifyModalShowAiButtons) ? (bool) $classifyModalShowAiButtons : false;
$classifyModalCanManageEntityAiInstructions = isset($classifyModalCanManageEntityAiInstructions)
    ? (bool) $classifyModalCanManageEntityAiInstructions
    : userHasDepartmentPermission('ctb_classificar_docs');
$classifyModalTitle = isset($classifyModalTitle) && trim((string) $classifyModalTitle) !== ''
    ? trim((string) $classifyModalTitle)
    : 'Classificar';
$classifyModalFooterLeftHtml = isset($classifyModalFooterLeftHtml) ? (string) $classifyModalFooterLeftHtml : '';
$classifyModalFooterRightHtml = isset($classifyModalFooterRightHtml) ? (string) $classifyModalFooterRightHtml : '';
?>
<style>
    .classify-modal-dialog {
        width: min(96vw, 1680px);
        max-width: min(96vw, 1680px);
    }

    #classify-form {
        display: flex;
        flex: 1 1 auto;
        flex-direction: column;
        min-height: 0;
        overflow: hidden;
    }

    #classify-form .modal-body {
        flex: 1 1 auto;
        min-height: 0;
        overflow-x: hidden;
        overflow-y: auto;
    }

    .classify-modal-layout {
        display: flex;
        gap: 1rem;
        align-items: stretch;
        min-height: 0;
    }

    .classify-modal-preview-pane {
        flex: 0 0 50%;
        max-width: 50%;
        display: flex;
        flex-direction: column;
        min-height: 0;
        min-width: 0;
    }

    .classify-modal-form-pane {
        flex: 0 0 50%;
        max-width: 50%;
        display: flex;
        flex-direction: column;
        min-height: 0;
        min-width: 320px;
    }

    .classify-document-preview-frame {
        flex: 1 1 auto;
        width: 100%;
        min-height: 64vh;
        border: 1px solid #dee2e6;
        border-radius: 0.5rem;
        background: #f8f9fa;
    }

    .classify-document-preview-empty {
        min-height: 64vh;
        border: 1px dashed #ced4da;
        border-radius: 0.5rem;
        background: #f8f9fa;
        color: #6c757d;
        display: none;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: 1.5rem;
    }

    .classify-modal-vat-table {
        table-layout: fixed;
        width: 100%;
    }

    .classify-modal-table-wrap {
        position: relative;
    }

    .classify-modal-table-overlay {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.75rem;
        background: rgba(255, 255, 255, 0.82);
        backdrop-filter: blur(1px);
        z-index: 5;
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transition: opacity 0.15s ease, visibility 0.15s ease;
    }

    .classify-modal-table-overlay.is-active {
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
    }

    .classify-modal-table-overlay .spinner-border {
        width: 2rem;
        height: 2rem;
    }

    .classify-modal-table-overlay-text {
        font-weight: 600;
        color: #4f6278;
    }

    .classify-modal-vat-table .col-rate {
        width: 10%;
    }

    .classify-modal-vat-table .col-base {
        width: 14%;
    }

    .classify-modal-vat-table .col-iva {
        width: 14%;
    }

    .classify-modal-vat-table .col-iva-account {
        width: 22%;
    }

    .classify-modal-vat-table .col-general-account {
        width: 21%;
    }

    .classify-modal-vat-table .col-cost-center {
        width: 9%;
    }

    .classify-modal-vat-table .col-actions {
        width: 10%;
        white-space: nowrap;
    }

    .classify-modal-vat-table th,
    .classify-modal-vat-table td {
        vertical-align: middle;
    }

    .classify-modal-vat-table .form-control {
        width: 100%;
    }

    .classify-modal-vat-table .actions-cell {
        text-align: center;
        overflow: hidden;
    }

    .classify-modal-vat-table .actions-cell-content {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        flex-wrap: nowrap;
        max-width: 100%;
    }

    .classify-modal-vat-table .actions-cell .btn {
        width: auto;
        flex: 0 0 auto;
    }

    .classify-modal-vat-table .actions-cell .restore-base-btn.d-none {
        display: inline-flex !important;
        visibility: hidden;
        opacity: 0;
        background: transparent !important;
        border-color: transparent !important;
        box-shadow: none !important;
        pointer-events: none;
    }

    .classify-modal-vat-table .rate-label-field {
        min-width: 0;
    }

    .classify-modal-vat-table .cost-center-distribution-wrap {
        display: inline-flex;
        flex-direction: column;
        gap: 0.25rem;
        align-items: center;
        justify-content: center;
        width: 42px;
        max-width: 42px;
        margin: 0 auto;
        overflow: hidden;
        vertical-align: middle;
    }

    .classify-modal-vat-table .cost-center-distribution-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 42px;
        min-width: 42px;
        height: 34px;
        padding: 0;
        overflow: hidden;
        border-radius: 4px;
        box-sizing: border-box;
    }

    .classify-modal-vat-table tr[data-custom-rate="1"] .cost-center-distribution-btn {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .classify-modal-vat-table .cost-center-distribution-summary {
        width: 100%;
        text-align: center;
    }

    .classify-modal-vat-table .cost-center-cell {
        text-align: center;
        overflow: hidden;
        position: relative;
    }

    .classify-modal-vat-table .cost-center-cell-content {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        max-width: 100%;
        overflow: hidden;
    }

    .classify-modal-vat-table .cost-center-cell .cost-center-distribution-summary:empty {
        display: none;
    }

    .classify-modal-section-label {
        font-weight: 700;
    }

    .classify-model-toolbar {
        border: 1px solid #e5e5e5;
        border-radius: 0.5rem;
        background: #fcfcfc;
        padding: 0.85rem;
        margin-bottom: 1rem;
    }

    .classify-model-toolbar .help-block {
        margin-bottom: 0;
    }

    .classify-document-fields-panel {
        margin-bottom: 1rem;
    }

    .classify-document-fields-panel .x_title {
        margin-bottom: 0;
    }

    .classify-document-fields-panel .x_content {
        padding-top: 12px;
    }

    .classify-document-fields-note {
        margin-bottom: 12px;
    }

    .classify-document-field-help {
        display: inline-block;
        margin-left: 0.35rem;
        font-weight: 400;
    }

    .classify-document-field-input {
        width: 100%;
    }

    .classify-manual-values-switch {
        min-width: 170px;
        margin-bottom: 0;
        padding-bottom: 0.15rem;
    }

    .classify-manual-values-switch .form-check-label {
        font-weight: 600;
    }

    .classify-modal-header-content {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex: 1 1 auto;
        min-width: 0;
    }

    .classify-modal-header-content .modal-title {
        margin: 0;
        min-width: 0;
    }

    .classify-modal-company-badge {
        margin-left: auto;
        font-size: 12px;
        line-height: 1.2;
    }

    #costCenterDistributionModal .modal-header {
        cursor: move;
    }

    #costCenterDistributionModal .modal-dialog {
        max-width: none;
        width: min(920px, calc(100vw - 64px));
        height: min(560px, calc(100vh - 120px));
        min-width: 720px;
        min-height: 320px;
        overflow: hidden;
    }

    #costCenterDistributionModal .modal-content {
        height: 100%;
    }

    #costCenterDistributionModal.is-dragging,
    #costCenterDistributionModal.is-dragging .modal-header {
        user-select: none;
    }

    @media (max-width: 1199.98px) {
        .classify-modal-layout {
            flex-direction: column;
            min-height: auto;
        }

        .classify-modal-preview-pane,
        .classify-modal-form-pane {
            flex: 1 1 100%;
            max-width: 100%;
        }

        .classify-modal-form-pane {
            min-width: 0;
        }

        .classify-document-preview-frame,
        .classify-document-preview-empty {
            min-height: 48vh;
        }

        .classify-modal-header-content {
            flex-wrap: wrap;
        }

        .classify-modal-company-badge {
            margin-left: 0;
        }
    }
</style>
<div class="modal fade" id="classifyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable classify-modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <div class="classify-modal-header-content">
                    <h5 class="modal-title" id="classifyModalLabel"><?= htmlspecialchars($classifyModalTitle); ?></h5>
                    <span id="classifyModalCompanyBadge" class="badge badge-default classify-modal-company-badge d-none"></span>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <form id="classify-form">
                <div class="modal-body">
                    <div class="classify-modal-layout">
                        <div class="classify-modal-preview-pane">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Documento</h6>
                            </div>
                            <iframe id="classifyDocumentPreviewFrame" class="classify-document-preview-frame d-none" title="Pre-visualizacao do documento"></iframe>
                            <div id="classifyDocumentPreviewEmpty" class="classify-document-preview-empty">
                                Nao foi possivel apresentar a pre-visualizacao deste documento.
                            </div>
                        </div>
                        <div class="classify-modal-form-pane">
                            <div class="classify-model-toolbar">
                                <div class="row g-3 align-items-end">
                                    <div class="col-md-6">
                                        <label for="classificationModelSelect" class="form-label mb-1 classify-modal-section-label">Modelo</label>
                                        <div class="d-flex gap-2">
                                            <select class="form-control form-control-sm" id="classificationModelSelect">
                                                <option value="">Selecionar modelo</option>
                                            </select>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" id="applyClassificationModelBtn">
                                                <i class="fa fa-clone"></i> Aplicar
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger" id="deleteClassificationModelBtn">
                                                <i class="fa fa-trash"></i> Eliminar
                                            </button>
                                        </div>
                                        <small class="text-muted">Modelos específicos do tenant do documento, adquirente e tipo documental, para reaproveitar classificações manuais entre emitentes.</small>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="d-flex align-items-end gap-2 flex-wrap mb-2">
                                            <div class="form-check form-switch mb-0">
                                                <input class="form-check-input" type="checkbox" id="saveClassificationModelSwitch" value="1">
                                                <label class="form-check-label" for="saveClassificationModelSwitch">Guardar Modelo</label>
                                            </div>
                                            <div>
                                                <label for="emitterTypeSelect" class="form-label mb-1 classify-modal-section-label">Tipo de entidade</label>
                                                <select class="form-control form-control-sm" id="emitterTypeSelect">
                                                    <option value="normal">Normal</option>
                                                    <option value="bank">Banco</option>
                                                    <option value="insurance">Seguradora</option>
                                                </select>
                                            </div>
                                            <?php if ($classifyModalCanManageEntityAiInstructions): ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary position-relative" id="entityPairAiInstructionsBtn" title="Instruções IA" aria-label="Instruções IA">
                                                <i class="fa fa-android"></i>
                                                <span id="entityPairAiInstructionsBadge" class="position-absolute top-0 start-100 translate-middle p-1 bg-warning border border-light rounded-circle d-none" title="Existem Instruções IA guardadas para este emitente/adquirente">
                                                    <span class="visually-hidden">Instruções IA definidas</span>
                                                </span>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                        <input
                                            type="text"
                                            class="form-control form-control-sm d-none"
                                            id="classificationModelNameInput"
                                            placeholder="Nome do modelo, ex.: Restaurante"
                                            maxlength="120"
                                        >
                                        <small class="text-muted d-block mt-1">O modelo guarda apenas linhas, contas e centros de custo. A base e o IVA mostrados vêm sempre do QR Code/documento atual.</small>
                                    </div>
                                </div>
                            </div>
                            <div id="classifyDocumentFieldsPanel" class="x_panel classify-document-fields-panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-file-text-o"></i> Campos do Documento</h2>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">
                                    <p class="text-muted small classify-document-fields-note">
                                        Complete ou corrija os campos do documento.
                                    </p>
                                    <div id="classifyDocumentFieldsGrid" class="row"></div>
                                </div>
                            </div>
                            <small class="text-muted d-block mb-2">Os valores apresentados na grelha correspondem ao que foi lido do QR Code ou ao que foi preenchido manualmente nos campos do documento.</small>
                            <div class="classify-modal-table-wrap">
                                <div id="classifyTableOverlay" class="classify-modal-table-overlay" aria-hidden="true">
                                    <div class="spinner-border text-info" role="status" aria-hidden="true"></div>
                                    <span class="classify-modal-table-overlay-text">A aplicar sugestão IA...</span>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0 classify-modal-vat-table">
                                        <thead>
                                            <tr>
                                                <th class="col-rate">Taxa %</th>
                                                <th class="col-base">Base</th>
                                                <th class="col-iva">IVA</th>
                                                <th class="col-iva-account">Conta IVA</th>
                                                <th class="col-general-account">Conta Geral</th>
                                                <?php if ($classifyModalImportType === 1): ?>
                                                <th class="col-cost-center">C Custo</th>
                                                <?php endif; ?>
                                                <th class="text-center col-actions">Ações</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>
                            <?php if ($classifyModalImportType === 1): ?>
                            <div class="mt-3">
                                <label for="totalAccountInput" class="form-label mb-1 classify-modal-section-label">Valor Total</label>
                                <div class="d-flex align-items-center gap-3 flex-wrap">
                                    <input
                                        type="text"
                                        class="form-control form-control-sm w-auto"
                                        id="totalAccountInput"
                                        placeholder="Conta para o valor total"
                                        style="min-width: 160px; max-width: 220px;"
                                    >
                                    <div class="form-check form-switch classify-manual-values-switch">
                                        <input class="form-check-input" type="checkbox" id="toggleDocumentFieldsSwitch" value="1">
                                        <label class="form-check-label" for="toggleDocumentFieldsSwitch">Editar Valores</label>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="#" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary me-auto d-none" id="classifyDocumentOpenBtn">
                        <i class="fa fa-external-link"></i> Abrir
                    </a>
                    <?= $classifyModalFooterLeftHtml; ?>
                    <?php if ($classifyModalShowAiButtons): ?>
                    <button type="button" class="btn btn-sm btn-outline-info" id="aiSuggestAccountsBtn">
                        <i class="fa fa-lightbulb-o"></i> Sugestão de contas IA
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-warning" id="aiSuggestCorrectionBtn">
                        <i class="fa fa-commenting-o"></i> Sugerir correção
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="aiSuggestionExplainBtn">
                        <i class="fa fa-info-circle"></i> Explicação da sugestão
                    </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="addVatLineBtn">
                        <i class="fa fa-plus"></i> Adicionar linha de IVA
                    </button>
                    <?= $classifyModalFooterRightHtml; ?>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<template id="vatRateRowTemplate">
    <tr data-custom-rate="0">
        <td class="align-middle col-rate">
            <span class="rate-label-static"></span>
        </td>
        <td class="col-base"><input type="text" class="form-control form-control-sm base-field" inputmode="decimal"></td>
        <td class="col-iva"><input type="text" class="form-control form-control-sm iva-field" readonly></td>
        <td class="col-iva-account"><input type="text" class="form-control form-control-sm iva-account-field"></td>
        <td class="col-general-account"><input type="text" class="form-control form-control-sm general-account-field"></td>
        <?php if ($classifyModalImportType === 1): ?>
        <td class="align-middle col-cost-center cost-center-cell">
            <div class="cost-center-cell-content">
                <select class="form-control form-control-sm cost-center-field">
                    <option value="">Selecione o centro de custo</option>
                </select>
                <div class="cost-center-distribution-wrap">
                    <button type="button" class="btn btn-sm btn-default cost-center-distribution-btn"><i class="fa fa-building"></i></button>
                    <div class="cost-center-distribution-summary text-muted small mt-1"></div>
                </div>
            </div>
        </td>
        <?php endif; ?>
        <td class="text-center align-middle actions-cell col-actions">
            <div class="actions-cell-content">
                <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary me-1 restore-base-btn d-none"
                    title="Repor base original"
                >
                    <i class="fa fa-undo"></i>
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger remove-rate-row" title="Remover linha">
                    <i class="fa fa-trash"></i>
                </button>
            </div>
        </td>
    </tr>
</template>
<template id="customRateRowTemplate">
    <tr data-custom-rate="1">
        <td class="col-rate">
            <input type="text" class="form-control form-control-sm rate-label-field" placeholder="Taxa">
        </td>
        <td class="col-base"><input type="text" class="form-control form-control-sm base-field" inputmode="decimal"></td>
        <td class="col-iva"><input type="text" class="form-control form-control-sm iva-field" readonly></td>
        <td class="col-iva-account"><input type="text" class="form-control form-control-sm iva-account-field"></td>
        <td class="col-general-account"><input type="text" class="form-control form-control-sm general-account-field"></td>
        <?php if ($classifyModalImportType === 1): ?>
        <td class="align-middle col-cost-center cost-center-cell">
            <div class="cost-center-cell-content">
                <select class="form-control form-control-sm cost-center-field">
                    <option value="">Selecione o centro de custo</option>
                </select>
                <div class="cost-center-distribution-wrap">
                    <button type="button" class="btn btn-sm btn-default cost-center-distribution-btn"><i class="fa fa-building"></i></button>
                    <div class="cost-center-distribution-summary text-muted small mt-1"></div>
                </div>
            </div>
        </td>
        <?php endif; ?>
        <td class="text-center align-middle actions-cell col-actions">
            <div class="actions-cell-content">
                <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary me-1 restore-base-btn d-none"
                    title="Repor base original"
                >
                    <i class="fa fa-undo"></i>
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger remove-rate-row" title="Remover linha">
                    <i class="fa fa-trash"></i>
                </button>
            </div>
        </td>
    </tr>
</template>
<div class="modal fade" id="costCenterDistributionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Distribuição por Centros de Custo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Documento</label>
                        <input type="text" class="form-control" id="ccDistributionDocumentInfo" readonly>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label mb-1">Data</label>
                        <input type="text" class="form-control" id="ccDistributionDateInfo" readonly>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label mb-1">Tipo</label>
                        <input type="text" class="form-control" id="ccDistributionTypeInfo" readonly>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label mb-1">Emitente</label>
                        <input type="text" class="form-control" id="ccDistributionEmitterInfo" readonly>
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-2">
                        <label class="form-label mb-1">Conta</label>
                        <input type="text" class="form-control" id="ccDistributionAccountInfo" readonly>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label mb-1">Descrição</label>
                        <input type="text" class="form-control" id="ccDistributionAccountLabelInfo" readonly>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Valor a atribuir</label>
                        <input type="text" class="form-control" id="ccDistributionAmountInfo" readonly>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label mb-1">Taxa</label>
                        <input type="text" class="form-control" id="ccDistributionRateInfo" readonly>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped table-bordered table-sm mb-0" id="ccDistributionTable">
                        <thead>
                            <tr>
                                <th>Conta C.C.</th>
                                <th style="width: 14%;" class="text-end">%</th>
                                <th style="width: 18%;" class="text-end">Valor</th>
                                <th style="width: 1%;"></th>
                            </tr>
                        </thead>
                        <tbody id="ccDistributionTableBody"></tbody>
                    </table>
                </div>
                <div class="mt-3 d-flex flex-wrap justify-content-end gap-3">
                    <div><strong>% Atribuída:</strong> <span id="ccDistributionPercentAssigned">0,00</span></div>
                    <div><strong>% Por atribuir:</strong> <span id="ccDistributionPercentRemaining">100,00</span></div>
                    <div><strong>Valor por atribuir:</strong> <span id="ccDistributionAmountRemaining">0,00</span></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-primary me-auto" id="ccDistributionAddRowBtn">
                    <i class="fa fa-plus"></i> Adicionar linha
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="ccDistributionSaveBtn">Guardar</button>
            </div>
        </div>
    </div>
</div>
<template id="costCenterDistributionRowTemplate">
    <tr>
        <td>
            <select class="form-control form-control-sm cc-distribution-code">
                <option value="">Selecione o centro de custo</option>
            </select>
        </td>
        <td>
            <input type="text" class="form-control form-control-sm text-end cc-distribution-percentage" inputmode="decimal" placeholder="0,00">
        </td>
        <td class="text-end align-middle cc-distribution-value">0,00</td>
        <td class="text-center align-middle">
            <button type="button" class="btn btn-sm btn-outline-danger cc-distribution-remove-row" title="Remover">
                <i class="fa fa-trash"></i>
            </button>
        </td>
    </tr>
</template>
