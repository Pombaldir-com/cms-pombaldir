<?php
require_once __DIR__ . '/functions.php';

startSession();
requireLogin();
requireRole(2);

$csrfToken = generateCsrfToken();
$useDataTables = true;

$scopeOptions = getAccountingAdditionalFieldScopeOptions();
$typeOptions = getAccountingAdditionalFieldTypeOptions();
$bootstrapColumnOptions = getAccountingAdditionalFieldBootstrapColumnOptions();
$bootstrapOffsetOptions = getAccountingAdditionalFieldBootstrapOffsetOptions();
$schemaReady = hasTable('accounting_additional_fields')
    && hasTable('accounting_entity_additional_values');

$action = trim((string) ($_GET['action'] ?? 'list'));
$fieldId = isset($_GET['field_id']) ? (int) $_GET['field_id'] : 0;
if (in_array($action, ['add_taxonomy', 'edit_taxonomy', 'add_term', 'edit_term'], true)) {
    $action = 'list';
}

$fieldErrors = [];
$listErrors = [];

$field = $fieldId > 0 ? getAccountingAdditionalField($fieldId) : null;
if ($fieldId > 0 && !$field) {
    $action = 'list';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $schemaReady) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('Token CSRF invalido');
    }
    $csrfToken = generateCsrfToken(true);
    $postAction = trim((string) ($_POST['post_action'] ?? ''));

    if ($postAction === 'save_field') {
        $fieldId = isset($_POST['field_id']) ? (int) $_POST['field_id'] : 0;
        $scope = trim((string) ($_POST['scope'] ?? ''));
        $name = normalizeAccountingAdditionalFieldSlug((string) ($_POST['name'] ?? ''));
        $label = trim((string) ($_POST['label'] ?? ''));
        $type = trim((string) ($_POST['type'] ?? 'text'));
        $options = trim((string) ($_POST['options'] ?? ''));
        $required = isset($_POST['required']);
        $sortOrder = isset($_POST['sort_order']) ? (int) $_POST['sort_order'] : 0;
        $bootstrapCol = normalizeAccountingAdditionalFieldBootstrapColumn($_POST['bootstrap_col'] ?? 6);
        $bootstrapOffset = normalizeAccountingAdditionalFieldBootstrapOffset($_POST['bootstrap_offset'] ?? 0);
        $existingPersistedField = $fieldId > 0 ? getAccountingAdditionalField($fieldId) : null;
        $allowLegacyTaxonomyType = $existingPersistedField && ($existingPersistedField['type'] ?? '') === 'taxonomy' && $type === 'taxonomy';

        if (!isset($scopeOptions[$scope])) {
            $fieldErrors[] = 'Ambito invalido.';
        }
        if ($name === '') {
            $fieldErrors[] = 'O slug do campo e obrigatorio.';
        }
        if ($label === '') {
            $fieldErrors[] = 'O rotulo do campo e obrigatorio.';
        }
        if (!isset($typeOptions[$type]) && !$allowLegacyTaxonomyType) {
            $fieldErrors[] = 'Tipo de campo invalido.';
        }
        if (($type === 'select' || $type === 'multiselect') && $options === '') {
            $fieldErrors[] = 'Indique pelo menos uma opcao para o campo selecionavel.';
        }
        if ($type !== 'select' && $type !== 'multiselect') {
            $options = '';
        }
        $existingField = ($scope !== '' && $name !== '') ? getAccountingAdditionalFieldByScopeAndName($scope, $name) : null;
        if ($existingField && (int) ($existingField['id'] ?? 0) !== $fieldId) {
            $fieldErrors[] = 'Ja existe um campo com este slug para o ambito selecionado.';
        }

        $field = [
            'id' => $fieldId,
            'scope' => $scope,
            'name' => $name,
            'label' => $label,
            'type' => $type,
            'options' => $options,
            'taxonomy_id' => 0,
            'required' => $required ? 1 : 0,
            'sort_order' => $sortOrder,
            'bootstrap_col' => $bootstrapCol,
            'bootstrap_offset' => $bootstrapOffset,
        ];

        if (!$fieldErrors) {
            if ($fieldId > 0) {
                updateAccountingAdditionalField($fieldId, $scope, $name, $label, $type, $options, null, $required, $sortOrder, $bootstrapCol, $bootstrapOffset);
            } else {
                createAccountingAdditionalField($scope, $name, $label, $type, $options, null, $required, $sortOrder, $bootstrapCol, $bootstrapOffset);
            }
            header('Location: ' . BASE_URL . 'tabelas/campos-adicionais');
            exit;
        }
        $action = $fieldId > 0 ? 'edit_field' : 'add_field';
    } elseif ($postAction === 'delete_field') {
        $fieldId = isset($_POST['field_id']) ? (int) $_POST['field_id'] : 0;
        if ($fieldId > 0) {
            deleteAccountingAdditionalField($fieldId);
        }
        header('Location: ' . BASE_URL . 'tabelas/campos-adicionais');
        exit;
    }
}

$fields = getAccountingAdditionalFields();

require_once __DIR__ . '/header.php';
?>
<div class="container-fluid">
    <style>
        .additional-field-editor .x_panel {
            margin-bottom: 18px;
        }
        .additional-field-editor .field-section-title {
            margin: 0 0 15px;
            font-size: 14px;
            font-weight: 600;
            color: #34495e;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .additional-field-editor .field-section-title i {
            margin-right: 6px;
            color: #1abb9c;
        }
        .additional-field-editor .field-help {
            display: block;
            margin-top: 6px;
            font-size: 12px;
            color: #73879c;
            line-height: 1.5;
        }
        .additional-field-editor .field-checkbox-wrap {
            padding-top: 30px;
        }
        .additional-field-editor .field-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            padding-top: 12px;
            border-top: 1px solid #e6e9ed;
        }
        .additional-field-editor .field-actions .btn {
            margin-bottom: 0;
        }
        .additional-field-editor .field-side-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .additional-field-editor .field-side-list li {
            padding: 10px 0;
            border-bottom: 1px solid #f0f2f5;
        }
        .additional-field-editor .field-side-list li:last-child {
            border-bottom: 0;
        }
        .additional-field-editor .field-side-label {
            display: block;
            font-size: 11px;
            text-transform: uppercase;
            color: #73879c;
            margin-bottom: 4px;
        }
        .additional-field-editor .field-side-value code {
            font-size: 12px;
        }
    </style>
    <div class="x_panel">
        <div class="x_title">
            <h2><i class="fa fa-sliders"></i> Campos Adicionais</h2>
            <ul class="nav navbar-right panel_toolbox" style="min-width: auto;">
                <li><a href="<?= BASE_URL ?>tabelas/campos-adicionais?action=add_field" class="btn btn-primary btn-sm" style="color:#fff;"><i class="fa fa-plus"></i> Novo Campo</a></li>
            </ul>
            <div class="clearfix"></div>
        </div>
        <div class="x_content">
            <?php if (!$schemaReady): ?>
                <div class="alert alert-warning">
                    As tabelas de campos adicionais ainda nao existem. Execute as migracoes antes de usar esta area.
                </div>
            <?php endif; ?>
            <?php foreach ($listErrors as $err): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($err); ?></div>
            <?php endforeach; ?>

            <?php if ($schemaReady && ($action === 'add_field' || $action === 'edit_field')): ?>
                <?php
                    $field = is_array($field) ? $field : ['scope' => 'client', 'name' => '', 'label' => '', 'type' => 'text', 'options' => '', 'taxonomy_id' => 0, 'required' => 0, 'sort_order' => 0, 'bootstrap_col' => 6, 'bootstrap_offset' => 0];
                    $isLegacyTaxonomyField = (($field['type'] ?? '') === 'taxonomy');
                    $fieldTypeOptions = $typeOptions;
                    $selectedScopeLabel = $scopeOptions[$field['scope'] ?? 'client'] ?? ($field['scope'] ?? 'Clientes');
                    $selectedTypeLabel = $fieldTypeOptions[$field['type'] ?? 'text'] ?? (($field['type'] ?? '') === 'taxonomy' ? 'Select legado' : ($field['type'] ?? 'Texto'));
                    if ($isLegacyTaxonomyField) {
                        $fieldTypeOptions['taxonomy'] = 'Select legado';
                    }
                ?>
                <div class="additional-field-editor">
                    <div class="row">
                        <div class="col-md-8 col-sm-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><?= $action === 'edit_field' ? 'Editar campo adicional' : 'Novo campo adicional'; ?></h2>
                                    <ul class="nav navbar-right panel_toolbox" style="min-width:auto;">
                                        <li>
                                            <a href="<?= BASE_URL ?>tabelas/campos-adicionais" class="btn btn-default btn-xs">
                                                <i class="fa fa-arrow-left"></i> Voltar a lista
                                            </a>
                                        </li>
                                    </ul>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">
                                    <?php foreach ($fieldErrors as $err): ?>
                                        <div class="alert alert-danger"><?= htmlspecialchars($err); ?></div>
                                    <?php endforeach; ?>
                                    <?php if ($isLegacyTaxonomyField): ?>
                                        <div class="alert alert-info">
                                            Este campo usa um modelo legado baseado em taxonomia. Novos campos devem usar o tipo <strong>Select</strong> com opcoes diretas.
                                        </div>
                                    <?php endif; ?>
                                    <form method="post" class="form-horizontal">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                                        <input type="hidden" name="post_action" value="save_field">
                                        <input type="hidden" name="field_id" value="<?= (int) ($field['id'] ?? 0); ?>">

                                        <div class="field-section-title"><i class="fa fa-tag"></i> Identificacao</div>
                                        <div class="row">
                                            <div class="col-md-4 col-sm-12">
                                                <div class="form-group">
                                                    <label class="control-label">Ambito</label>
                                                    <select name="scope" class="form-control">
                                                        <?php foreach ($scopeOptions as $scopeKey => $scopeLabel): ?>
                                                            <option value="<?= htmlspecialchars($scopeKey); ?>" <?= ($field['scope'] ?? '') === $scopeKey ? 'selected' : ''; ?>><?= htmlspecialchars($scopeLabel); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <span class="field-help">Define se o campo aparece em clientes ou fornecedores.</span>
                                                </div>
                                            </div>
                                            <div class="col-md-4 col-sm-12">
                                                <div class="form-group">
                                                    <label class="control-label">Rotulo</label>
                                                    <input type="text" name="label" class="form-control" value="<?= htmlspecialchars((string) ($field['label'] ?? '')); ?>" required>
                                                    <span class="field-help">Texto visivel no formulario da ficha.</span>
                                                </div>
                                            </div>
                                            <div class="col-md-4 col-sm-12">
                                                <div class="form-group">
                                                    <label class="control-label">Slug</label>
                                                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars((string) ($field['name'] ?? '')); ?>" required>
                                                    <span class="field-help">Identificador tecnico unico dentro do ambito escolhido.</span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="field-section-title"><i class="fa fa-sliders"></i> Comportamento</div>
                                        <div class="row">
                                            <div class="col-md-3 col-sm-12">
                                                <div class="form-group">
                                                    <label class="control-label">Tipo</label>
                                                    <select name="type" class="form-control" id="additionalFieldType">
                                                        <?php foreach ($fieldTypeOptions as $typeKey => $typeLabel): ?>
                                                            <option value="<?= htmlspecialchars($typeKey); ?>" <?= ($field['type'] ?? '') === $typeKey ? 'selected' : ''; ?>><?= htmlspecialchars($typeLabel); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <span class="field-help">Controla como o valor e introduzido.</span>
                                                </div>
                                            </div>
                                            <div class="col-md-2 col-sm-12">
                                                <div class="form-group">
                                                    <label class="control-label">Ordem</label>
                                                    <input type="number" name="sort_order" class="form-control" value="<?= (int) ($field['sort_order'] ?? 0); ?>">
                                                    <span class="field-help">Menor valor aparece primeiro.</span>
                                                </div>
                                            </div>
                                            <div class="col-md-2 col-sm-12">
                                                <div class="form-group field-checkbox-wrap">
                                                    <label><input type="checkbox" name="required" value="1" <?= !empty($field['required']) ? 'checked' : ''; ?>> Obrigatorio</label>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="field-section-title"><i class="fa fa-columns"></i> Layout</div>
                                        <div class="row">
                                            <div class="col-md-3 col-sm-12">
                                                <div class="form-group">
                                                    <label class="control-label">Coluna</label>
                                                    <select name="bootstrap_col" class="form-control">
                                                        <?php foreach ($bootstrapColumnOptions as $colValue => $colLabel): ?>
                                                            <option value="<?= (int) $colValue; ?>" <?= (int) ($field['bootstrap_col'] ?? 6) === (int) $colValue ? 'selected' : ''; ?>><?= htmlspecialchars($colLabel); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <span class="field-help">Largura ocupada na grelha Bootstrap.</span>
                                                </div>
                                            </div>
                                            <div class="col-md-3 col-sm-12">
                                                <div class="form-group">
                                                    <label class="control-label">Offset</label>
                                                    <select name="bootstrap_offset" class="form-control">
                                                        <?php foreach ($bootstrapOffsetOptions as $offsetValue => $offsetLabel): ?>
                                                            <option value="<?= (int) $offsetValue; ?>" <?= (int) ($field['bootstrap_offset'] ?? 0) === (int) $offsetValue ? 'selected' : ''; ?>><?= htmlspecialchars($offsetLabel); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <span class="field-help">Espaco vazio antes do campo na mesma linha.</span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="field-section-title"><i class="fa fa-list"></i> Opcoes</div>
                                        <div class="row">
                                            <div class="col-md-12 col-sm-12" id="additionalFieldOptionsWrap" style="<?= in_array(($field['type'] ?? ''), ['select', 'multiselect'], true) ? '' : 'display:none;'; ?>">
                                                <div class="form-group">
                                                    <label class="control-label">Opcoes</label>
                                                    <textarea name="options" class="form-control" rows="5" placeholder="Uma opcao por linha"><?= htmlspecialchars((string) ($field['options'] ?? '')); ?></textarea>
                                                    <span class="field-help">Cada linha corresponde a uma opcao disponivel para `Select` ou `Multi-select`.</span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="field-actions">
                                            <a href="<?= BASE_URL ?>tabelas/campos-adicionais" class="btn btn-default"><i class="fa fa-times"></i> Cancelar</a>
                                            <button type="submit" class="btn btn-success"><i class="fa fa-save"></i> Guardar campo</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 col-sm-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-info-circle"></i> Resumo</h2>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">
                                    <ul class="field-side-list">
                                        <li>
                                            <span class="field-side-label">Ambito</span>
                                            <div class="field-side-value"><span class="label label-primary"><?= htmlspecialchars($selectedScopeLabel); ?></span></div>
                                        </li>
                                        <li>
                                            <span class="field-side-label">Tipo</span>
                                            <div class="field-side-value"><span class="label label-default"><?= htmlspecialchars($selectedTypeLabel); ?></span></div>
                                        </li>
                                        <li>
                                            <span class="field-side-label">Slug</span>
                                            <div class="field-side-value"><code><?= htmlspecialchars((string) ($field['name'] ?? '')); ?></code></div>
                                        </li>
                                        <li>
                                            <span class="field-side-label">Layout</span>
                                            <div class="field-side-value">
                                                <span class="label label-default">col-md-<?= (int) ($field['bootstrap_col'] ?? 6); ?></span>
                                                <span class="label label-info">offset-<?= (int) ($field['bootstrap_offset'] ?? 0); ?></span>
                                            </div>
                                        </li>
                                        <li>
                                            <span class="field-side-label">Obrigatorio</span>
                                            <div class="field-side-value">
                                                <?php if (!empty($field['required'])): ?>
                                                    <span class="label label-success">Sim</span>
                                                <?php else: ?>
                                                    <span class="label label-default">Nao</span>
                                                <?php endif; ?>
                                            </div>
                                        </li>
                                    </ul>
                                </div>
                            </div>

                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-lightbulb-o"></i> Boas praticas</h2>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">
                                    <p>Use `Slug` curtos e estaveis para evitar mudancas desnecessarias no codigo e nos dados gravados.</p>
                                    <p>Para listas simples, prefira `Select` com opcoes diretas em vez de estruturas mais complexas.</p>
                                    <p>Em formularios com varios campos, combine `Coluna` e `Offset` para criar uma grelha mais limpa.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($action === 'list'): ?>
                <div class="row">
                    <div class="col-md-12 col-sm-12">
                        <div class="x_panel">
                            <div class="x_title">
                                <h2><i class="fa fa-list-alt"></i> Lista de campos</h2>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                <table class="table table-striped jambo_table datatable">
                                    <thead>
                                        <tr>
                                            <th>Ambito</th>
                                            <th>Rotulo</th>
                                            <th>Slug</th>
                                            <th>Tipo</th>
                                            <th>Coluna</th>
                                            <th>Offset</th>
                                            <th>Opcoes</th>
                                            <th class="text-right">Acoes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($fields as $row): ?>
                                            <?php
                                                $rowType = (string) ($row['type'] ?? '');
                                                $rowTypeLabel = $typeOptions[$rowType] ?? ($rowType === 'taxonomy' ? 'Select legado' : $rowType);
                                                $rowOptionsLabel = '-';
                                                if ($rowType === 'select' || $rowType === 'multiselect' || $rowType === 'boolean_select') {
                                                    $rowOptionsLabel = 'Lista manual';
                                                } elseif ($rowType === 'taxonomy') {
                                                    $rowOptionsLabel = 'Lista legada';
                                                }
                                            ?>
                                            <tr>
                                                <td><?= htmlspecialchars($scopeOptions[$row['scope']] ?? $row['scope']); ?></td>
                                                <td><?= htmlspecialchars((string) $row['label']); ?></td>
                                                <td><code><?= htmlspecialchars((string) $row['name']); ?></code></td>
                                                <td><?= htmlspecialchars($rowTypeLabel); ?></td>
                                                <td><span class="label label-default">col-md-<?= (int) ($row['bootstrap_col'] ?? 6); ?></span></td>
                                                <td><span class="label label-info">offset-<?= (int) ($row['bootstrap_offset'] ?? 0); ?></span></td>
                                                <td><?= htmlspecialchars($rowOptionsLabel); ?></td>
                                                <td class="text-right">
                                                    <a href="<?= BASE_URL ?>tabelas/campos-adicionais?action=edit_field&field_id=<?= (int) $row['id']; ?>" class="btn btn-xs btn-primary"><i class="fa fa-pencil"></i> Editar</a>
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                                                        <input type="hidden" name="post_action" value="delete_field">
                                                        <input type="hidden" name="field_id" value="<?= (int) $row['id']; ?>">
                                                        <button type="submit" class="btn btn-xs btn-danger" onclick="return confirm('Eliminar este campo adicional?');"><i class="fa fa-trash"></i> Eliminar</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var typeSelect = document.getElementById('additionalFieldType');
    var optionsWrap = document.getElementById('additionalFieldOptionsWrap');
    if (!typeSelect || !optionsWrap) {
        return;
    }

    function syncFieldTypeUi() {
        var type = typeSelect.value || '';
        optionsWrap.style.display = (type === 'select' || type === 'multiselect') ? '' : 'none';
    }

    typeSelect.addEventListener('change', syncFieldTypeUi);
    syncFieldTypeUi();
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
