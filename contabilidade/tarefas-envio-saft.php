<?php
// Tarefa "Envio de SAF-T": colaboradores enviam o ficheiro SAF-T das empresas
// que lhes foram atribuidas (permissao ctb_envio_saft, gerida na ficha da
// empresa em Entidades > Empresas > Admin). Administradores veem todas as
// empresas e todos os envios.

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/saft-envio-functions.php';

startSession();
requireLogin();

$user = currentUser();
$isAdmin = ((int) ($user['role'] ?? 3)) <= 2;
$userId = (int) ($user['id'] ?? 0);

if (!$isAdmin && !userHasAccountingEntityTaskPermission('ctb_envio_saft')) {
    http_response_code(403);
    echo 'Acesso negado.';
    exit;
}

$pdo = getPDO();

if (!hasTable('accounting_saft_submissions')) {
    http_response_code(500);
    echo 'A tabela accounting_saft_submissions ainda nao existe. Execute as migracoes.';
    exit;
}

/**
 * Empresas (entidades adquirentes) a que o utilizador tem acesso nesta tarefa.
 */
function getSaftTaskEntities(PDO $pdo, bool $isAdmin, int $userId): array {
    if ($isAdmin) {
        $stmt = $pdo->query(
            "SELECT id, nif, name FROM accounting_entities
             WHERE entity_type = 'acquirer'
             ORDER BY name ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $stmt = $pdo->prepare(
        "SELECT ae.id, ae.nif, ae.name
         FROM accounting_entities ae
         INNER JOIN accounting_entity_admin_task_permissions aep
             ON aep.accounting_entity_id = ae.id
         WHERE ae.entity_type = 'acquirer'
           AND aep.permission_key = 'ctb_envio_saft'
           AND aep.user_id = ?
         ORDER BY ae.name ASC"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$entities = getSaftTaskEntities($pdo, $isAdmin, $userId);
$allowedEntityIds = array_map(static fn($row) => (int) $row['id'], $entities);

if (($_GET['action'] ?? '') === 'invoices') {
    header('Content-Type: application/json; charset=utf-8');
    $submissionId = (int) ($_GET['submission_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT accounting_entity_id FROM accounting_saft_submissions WHERE id = ? LIMIT 1');
    $stmt->execute([$submissionId]);
    $entityId = (int) ($stmt->fetchColumn() ?: 0);
    if ($entityId <= 0 || (!$isAdmin && !in_array($entityId, $allowedEntityIds, true))) {
        http_response_code(403);
        echo json_encode(['error' => 'Sem permissão.']);
        exit;
    }
    $stmt = $pdo->prepare(
        'SELECT invoice_no, invoice_type, invoice_status, invoice_date, customer_id, tax_payable, net_total, gross_total
         FROM accounting_saft_invoices WHERE submission_id = ? ORDER BY invoice_date ASC, id ASC'
    );
    $stmt->execute([$submissionId]);
    echo json_encode(['invoices' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []], JSON_UNESCAPED_UNICODE);
    exit;
}

$feedback = null; // ['type' => 'success'|'danger', 'message' => string]

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('Token CSRF inválido');
    }

    $action = $_POST['action'] ?? 'upload';

    if ($action === 'delete') {
        $submissionId = (int) ($_POST['submission_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT id, accounting_entity_id, user_id, file_path FROM accounting_saft_submissions WHERE id = ? LIMIT 1');
        $stmt->execute([$submissionId]);
        $submission = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$submission) {
            $feedback = ['type' => 'danger', 'message' => 'Envio não encontrado.'];
        } elseif (!$isAdmin && !in_array((int) $submission['accounting_entity_id'], $allowedEntityIds, true)) {
            $feedback = ['type' => 'danger', 'message' => 'Sem permissão para eliminar este envio.'];
        } else {
            $fullPath = dirname(__DIR__) . '/' . ltrim((string) $submission['file_path'], '/');
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
            $pdo->prepare('DELETE FROM accounting_saft_submissions WHERE id = ?')->execute([$submissionId]);
            logAuditAction('delete', 'accounting_saft_submission', $submissionId, [
                'accounting_entity_id' => (int) $submission['accounting_entity_id'],
            ]);
            $feedback = ['type' => 'success', 'message' => 'Envio eliminado.'];
        }
    } else {
        $periodYear = (int) ($_POST['period_year'] ?? 0);
        $periodMonth = (int) ($_POST['period_month'] ?? 0);

        if ($periodYear < 2000 || $periodYear > 2100 || $periodMonth < 1 || $periodMonth > 12) {
            $feedback = ['type' => 'danger', 'message' => 'Período inválido.'];
        } elseif (empty($_FILES['saft_file']) || ($_FILES['saft_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $feedback = ['type' => 'danger', 'message' => 'Selecione um ficheiro SAF-T válido.'];
        } else {
            $originalName = (string) $_FILES['saft_file']['name'];
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $entityId = 0;
            if (!in_array($extension, ['xml', 'zip', 'gz'], true)) {
                $feedback = ['type' => 'danger', 'message' => 'Formato inválido. Apenas ficheiros .xml, .zip ou .gz.'];
            } else {
                // A empresa e determinada pelo NIF no cabecalho do proprio
                // ficheiro SAF-T, nao por seleccao manual.
                $matchedEntity = null;
                try {
                    $xmlContent = saftReadFileContent($_FILES['saft_file']['tmp_name'], $extension);
                    $headerData = saftExtractInvoiceData($xmlContent);
                    $fileNif = (string) ($headerData['tax_registration_number'] ?? '');
                    if ($fileNif === '') {
                        $feedback = ['type' => 'danger', 'message' => 'Não foi possível identificar o NIF da empresa no cabeçalho do ficheiro SAF-T.'];
                    } else {
                        $matchedEntity = saftResolveEntityByNif($pdo, $fileNif);
                        if (!$matchedEntity) {
                            $feedback = ['type' => 'danger', 'message' => 'Não existe nenhuma empresa registada com o NIF ' . htmlspecialchars($fileNif) . ' (indicado no ficheiro SAF-T).'];
                        } elseif (!$isAdmin && !in_array((int) $matchedEntity['id'], $allowedEntityIds, true)) {
                            $feedback = ['type' => 'danger', 'message' => 'Não tem permissão para enviar SAF-T da empresa ' . htmlspecialchars($matchedEntity['name']) . ' (NIF ' . htmlspecialchars($matchedEntity['nif']) . ').'];
                        } else {
                            $entityId = (int) $matchedEntity['id'];
                        }
                    }
                } catch (Throwable $e) {
                    $feedback = ['type' => 'danger', 'message' => 'Não foi possível ler o ficheiro SAF-T: ' . $e->getMessage()];
                }
            }
            if ($entityId > 0) {
                $slug = getCompanySlug();
                $dir = dirname(__DIR__) . '/uploads/' . $slug . '/saft-envios/' . $entityId . '/' . $periodYear . '/' . str_pad((string) $periodMonth, 2, '0', STR_PAD_LEFT) . '/';
                if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                    $feedback = ['type' => 'danger', 'message' => 'Erro ao criar diretório de destino.'];
                } else {
                    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $originalName) ?: ('saft.' . $extension);
                    $storedName = date('YmdHis') . '_' . $safeName;
                    $fullPath = $dir . $storedName;
                    if (!move_uploaded_file($_FILES['saft_file']['tmp_name'], $fullPath)) {
                        $feedback = ['type' => 'danger', 'message' => 'Falha ao gravar o ficheiro.'];
                    } else {
                        // So se permite um envio por empresa/periodo: o envio
                        // anterior para o mesmo ano/mes e substituido pelo mais
                        // recente (ficheiro e registo, incluindo faturas
                        // extraidas via cascade).
                        $previousStmt = $pdo->prepare(
                            'SELECT id, file_path FROM accounting_saft_submissions
                             WHERE accounting_entity_id = ? AND period_year = ? AND period_month = ?
                             LIMIT 1'
                        );
                        $previousStmt->execute([$entityId, $periodYear, $periodMonth]);
                        $previousSubmission = $previousStmt->fetch(PDO::FETCH_ASSOC);
                        if ($previousSubmission) {
                            $previousFullPath = dirname(__DIR__) . '/' . ltrim((string) $previousSubmission['file_path'], '/');
                            if (is_file($previousFullPath)) {
                                @unlink($previousFullPath);
                            }
                            $pdo->prepare('DELETE FROM accounting_saft_submissions WHERE id = ?')->execute([(int) $previousSubmission['id']]);
                            logAuditAction('delete', 'accounting_saft_submission', (int) $previousSubmission['id'], [
                                'accounting_entity_id' => $entityId,
                                'period' => $periodYear . '-' . $periodMonth,
                                'reason' => 'substituido por novo envio',
                            ]);
                        }

                        $relativePath = 'uploads/' . $slug . '/saft-envios/' . $entityId . '/' . $periodYear . '/' . str_pad((string) $periodMonth, 2, '0', STR_PAD_LEFT) . '/' . $storedName;
                        $stmt = $pdo->prepare(
                            'INSERT INTO accounting_saft_submissions
                                (accounting_entity_id, user_id, period_year, period_month, original_filename, file_path, file_size)
                             VALUES (?, ?, ?, ?, ?, ?, ?)'
                        );
                        $stmt->execute([
                            $entityId,
                            $userId,
                            $periodYear,
                            $periodMonth,
                            $originalName,
                            $relativePath,
                            (int) ($_FILES['saft_file']['size'] ?? 0),
                        ]);
                        $submissionId = (int) $pdo->lastInsertId();
                        logAuditAction('create', 'accounting_saft_submission', $submissionId, [
                            'accounting_entity_id' => $entityId,
                            'period' => $periodYear . '-' . $periodMonth,
                        ]);

                        // Extrai faturas e totais do proprio ficheiro SAF-T
                        // para cruzamento posterior com a contabilidade e
                        // lancamentos. Nao bloqueia o envio se falhar.
                        saftPersistExtractedData($pdo, $submissionId, $fullPath);

                        // Submissao a AT via FACTEMICLI, quando o jar esta
                        // configurado e a empresa tem credencial do portal.
                        // Continuar sempre o envio mesmo com inconsistencias nos
                        // totais (responde "s" ao aviso interativo da AT).
                        $forceOnAnomalies = true;
                        $testMode = getSetting('saft_test_mode', '0') === '1';
                        $jarConfigured = trim((string) getSetting('saft_jar_path', '')) !== '';
                        $credential = saftGetEntityPortalCredential($pdo, $entityId);

                        if ($testMode) {
                            $pdo->prepare('UPDATE accounting_saft_submissions SET status = ? WHERE id = ?')
                                ->execute(['teste', $submissionId]);
                            $feedback = ['type' => 'info', 'message' => 'Modo teste ativo: o ficheiro foi registado mas não foi enviado à AT.'];
                        } elseif (!$jarConfigured) {
                            $feedback = ['type' => 'warning', 'message' => 'Ficheiro registado, mas o jar FACTEMICLI não está configurado (Definições > Serviços). O envio à AT não foi efetuado.'];
                        } elseif (!$credential) {
                            $feedback = ['type' => 'warning', 'message' => 'Ficheiro registado, mas a empresa não tem credencial do portal AT ativa (módulo E-fatura). O envio à AT não foi efetuado.'];
                        } else {
                            try {
                                $portalPassword = saftDecryptPortalSecret((string) $credential['portal_password_encrypted']);
                                $result = saftRunFactemicli(
                                    (string) $credential['portal_username'],
                                    $portalPassword,
                                    $periodYear,
                                    $periodMonth,
                                    $fullPath,
                                    $forceOnAnomalies
                                );
                                $parsed = $result['parsed'];
                                $status = $parsed !== null && $parsed['code'] === '200' ? 'enviado' : 'erro';
                                $pdo->prepare(
                                    'UPDATE accounting_saft_submissions SET
                                        status = ?, at_response_code = ?, at_total_faturas = ?, at_total_creditos = ?,
                                        at_total_debitos = ?, at_warning = ?, at_id_ficheiro = ?, at_nome_ficheiro = ?,
                                        at_created_date = ?, at_errors = ?, at_response_raw = ?
                                     WHERE id = ?'
                                )->execute([
                                    $status,
                                    $parsed['code'] ?? null,
                                    $parsed['total_faturas'] ?? null,
                                    $parsed['total_creditos'] ?? null,
                                    $parsed['total_debitos'] ?? null,
                                    $parsed['warning'] ?? null,
                                    $parsed['id_ficheiro'] ?? null,
                                    $parsed['nome_ficheiro'] ?? null,
                                    $parsed['created_date'] ?? null,
                                    !empty($parsed['errors']) ? implode("\n", $parsed['errors']) : null,
                                    $result['raw'] !== '' ? $result['raw'] : null,
                                    $submissionId,
                                ]);
                                logAuditAction('update', 'accounting_saft_submission', $submissionId, [
                                    'status' => $status,
                                    'at_response_code' => $parsed['code'] ?? null,
                                ]);
                                if ($status === 'enviado') {
                                    $message = 'SAF-T enviado à AT com sucesso (código 200'
                                        . (!empty($parsed['id_ficheiro']) ? ', ficheiro n.º ' . $parsed['id_ficheiro'] : '')
                                        . ').';
                                    if (!empty($parsed['warning'])) {
                                        $message .= ' Aviso da AT: ' . $parsed['warning'];
                                    }
                                    $feedback = ['type' => 'success', 'message' => $message];
                                } else {
                                    $errorDetail = $parsed !== null
                                        ? 'código ' . $parsed['code'] . (!empty($parsed['errors']) ? ' — ' . implode(' | ', $parsed['errors']) : '')
                                        : 'resposta da AT não reconhecida';
                                    $feedback = ['type' => 'danger', 'message' => 'O ficheiro foi registado mas a AT devolveu erro (' . $errorDetail . '). Consulte os detalhes na listagem.'];
                                }
                            } catch (Throwable $e) {
                                $pdo->prepare('UPDATE accounting_saft_submissions SET status = ?, at_errors = ? WHERE id = ?')
                                    ->execute(['erro', $e->getMessage(), $submissionId]);
                                $feedback = ['type' => 'danger', 'message' => 'Ficheiro registado, mas o envio à AT falhou: ' . $e->getMessage()];
                            }
                        }

                        if ($previousSubmission && $feedback !== null) {
                            $feedback['message'] = 'Substituído o envio anterior deste período. ' . $feedback['message'];
                        }
                        if ($feedback !== null) {
                            $feedback['message'] = 'Empresa identificada: ' . $matchedEntity['name'] . ' (NIF ' . $matchedEntity['nif'] . '). ' . $feedback['message'];
                        }
                    }
                }
            }
        }
    }
}

$csrfToken = generateCsrfToken();

// Seletor de empresa no topbar (mesmo padrao do e-fatura): filtra a listagem
// de envios por uma das empresas a que o utilizador tem acesso nesta tarefa,
// ou todas quando nao ha seleção.
$saftSelectionSessionKey = 'saft_tarefas_selected_entity';
if (array_key_exists('empresa', $_GET)) {
    $selectedEntityFilter = (int) $_GET['empresa'];
    $_SESSION[$saftSelectionSessionKey] = $selectedEntityFilter;
} else {
    $selectedEntityFilter = (int) ($_SESSION[$saftSelectionSessionKey] ?? 0);
}
if ($selectedEntityFilter > 0 && !in_array($selectedEntityFilter, $allowedEntityIds, true)) {
    $selectedEntityFilter = 0;
}

$efaturaTopbarSelector = [
    'enabled' => !empty($entities),
    'action' => BASE_URL . 'contabilidade/tarefas/envio-saft',
    'selected_entity_id' => $selectedEntityFilter,
    'entities' => array_merge(
        [['value' => '0', 'label' => 'Todas as empresas']],
        array_map(static function (array $entity): array {
            return [
                'value' => (string) $entity['id'],
                'label' => (string) $entity['name'] . (trim((string) $entity['nif']) !== '' ? ' (' . $entity['nif'] . ')' : ''),
            ];
        }, $entities)
    ),
];

// Listagem: admin ve todos os envios; colaborador so os das suas empresas.
// O filtro de empresa do topbar aplica-se em ambos os casos.
$listEntityIds = $selectedEntityFilter > 0 ? [$selectedEntityFilter] : $allowedEntityIds;
if ($isAdmin && $selectedEntityFilter === 0) {
    $stmt = $pdo->query(
        'SELECT s.*, ae.name AS entity_name, ae.nif AS entity_nif,
                COALESCE(NULLIF(TRIM(u.name), ""), u.username) AS user_label
         FROM accounting_saft_submissions s
         INNER JOIN accounting_entities ae ON ae.id = s.accounting_entity_id
         LEFT JOIN users u ON u.id = s.user_id
         ORDER BY s.created_at DESC'
    );
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} elseif ($isAdmin && $selectedEntityFilter > 0) {
    $stmt = $pdo->prepare(
        'SELECT s.*, ae.name AS entity_name, ae.nif AS entity_nif,
                COALESCE(NULLIF(TRIM(u.name), ""), u.username) AS user_label
         FROM accounting_saft_submissions s
         INNER JOIN accounting_entities ae ON ae.id = s.accounting_entity_id
         LEFT JOIN users u ON u.id = s.user_id
         WHERE s.accounting_entity_id = ?
         ORDER BY s.created_at DESC'
    );
    $stmt->execute([$selectedEntityFilter]);
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} elseif ($listEntityIds) {
    $placeholders = implode(',', array_fill(0, count($listEntityIds), '?'));
    $stmt = $pdo->prepare(
        'SELECT s.*, ae.name AS entity_name, ae.nif AS entity_nif,
                COALESCE(NULLIF(TRIM(u.name), ""), u.username) AS user_label
         FROM accounting_saft_submissions s
         INNER JOIN accounting_entities ae ON ae.id = s.accounting_entity_id
         LEFT JOIN users u ON u.id = s.user_id
         WHERE s.accounting_entity_id IN (' . $placeholders . ')
         ORDER BY s.created_at DESC'
    );
    $stmt->execute($listEntityIds);
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} else {
    $submissions = [];
}

$monthNames = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
$currentYear = (int) date('Y');
$currentMonth = (int) date('n');
$defaultMonth = $currentMonth === 1 ? 12 : $currentMonth - 1;
$defaultYear = $currentMonth === 1 ? $currentYear - 1 : $currentYear;

$useDataTables = true;
require_once __DIR__ . '/../header.php';
?>

<div class="page-title">
    <div class="title_left">
        <h3>Tarefas <small>Envio de SAF-T</small></h3>
    </div>
</div>
<div class="clearfix"></div>

<?php if ($feedback): ?>
<div class="alert alert-<?= htmlspecialchars($feedback['type']); ?> alert-dismissible" role="alert">
    <button type="button" class="close" data-bs-dismiss="alert" aria-label="Fechar"><span aria-hidden="true">&times;</span></button>
    <?= htmlspecialchars($feedback['message']); ?>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-md-12">
        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-upload"></i> Enviar ficheiro SAF-T</h2>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <?php if (!$entities): ?>
                    <div class="alert alert-info" style="margin-bottom: 0;">
                        Não tem empresas atribuídas para esta tarefa. Contacte o administrador.
                    </div>
                <?php else: ?>
                <form method="post" enctype="multipart/form-data" class="form-horizontal">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="upload">
                    <div class="row">
                        <div class="col-md-2 col-sm-3">
                            <div class="form-group">
                                <label class="control-label">Ano</label>
                                <select class="form-control" name="period_year" required>
                                    <?php for ($y = $currentYear; $y >= $currentYear - 5; $y--): ?>
                                        <option value="<?= $y; ?>" <?= $y === $defaultYear ? 'selected' : ''; ?>><?= $y; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2 col-sm-3">
                            <div class="form-group">
                                <label class="control-label">Mês</label>
                                <select class="form-control" name="period_month" required>
                                    <?php foreach ($monthNames as $num => $label): ?>
                                        <option value="<?= $num; ?>" <?= $num === $defaultMonth ? 'selected' : ''; ?>><?= htmlspecialchars($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-8 col-sm-6">
                            <div class="form-group">
                                <label class="control-label">Ficheiro SAF-T (.xml, .zip, .gz)</label>
                                <input type="file" class="form-control" name="saft_file" accept=".xml,.zip,.gz" required>
                            </div>
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-paper-plane"></i> Enviar SAF-T
                        </button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-history"></i> Envios efetuados<?= $isAdmin ? ' (todas as empresas)' : ''; ?></h2>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <div class="table-responsive">
                    <table id="saft-submissions-table" class="table table-striped jambo_table">
                        <thead>
                            <tr>
                                <th>Data de envio</th>
                                <th>Empresa</th>
                                <th>Período</th>
                                <th>Ficheiro</th>
                                <th>Tamanho</th>
                                <th>Enviado por</th>
                                <th>Estado</th>
                                <th>Resposta AT</th>
                                <th>Valores extraídos</th>
                                <th class="text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($submissions as $submission): ?>
                            <tr>
                                <td data-order="<?= htmlspecialchars($submission['created_at']); ?>"><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $submission['created_at']))); ?></td>
                                <td><?= htmlspecialchars((string) $submission['entity_name']); ?><?= trim((string) $submission['entity_nif']) !== '' ? ' <small class="text-muted">(' . htmlspecialchars((string) $submission['entity_nif']) . ')</small>' : ''; ?></td>
                                <td data-order="<?= (int) $submission['period_year'] * 100 + (int) $submission['period_month']; ?>">
                                    <?= htmlspecialchars(($monthNames[(int) $submission['period_month']] ?? $submission['period_month']) . ' ' . $submission['period_year']); ?>
                                </td>
                                <td><?= htmlspecialchars($submission['original_filename']); ?></td>
                                <td data-order="<?= (int) $submission['file_size']; ?>">
                                    <?php
                                        $size = (int) $submission['file_size'];
                                        echo $size >= 1048576
                                            ? htmlspecialchars(number_format($size / 1048576, 1, ',', '.')) . ' MB'
                                            : htmlspecialchars(number_format(max(1, round($size / 1024)), 0, ',', '.')) . ' KB';
                                    ?>
                                </td>
                                <td><?= htmlspecialchars((string) ($submission['user_label'] ?? '—')); ?></td>
                                <td>
                                    <?php
                                        $status = (string) ($submission['status'] ?? 'registado');
                                        $statusClass = $status === 'enviado' ? 'label-success'
                                            : ($status === 'erro' ? 'label-danger'
                                            : ($status === 'teste' ? 'label-warning' : 'label-default'));
                                    ?>
                                    <span class="label <?= $statusClass; ?>"><?= htmlspecialchars(ucfirst($status)); ?></span>
                                </td>
                                <td>
                                    <?php
                                        $atCode = trim((string) ($submission['at_response_code'] ?? ''));
                                        $atDetails = [];
                                        if ($atCode !== '') {
                                            $atDetails[] = 'Código: ' . $atCode;
                                        }
                                        if (trim((string) ($submission['at_id_ficheiro'] ?? '')) !== '') {
                                            $atDetails[] = 'Ficheiro AT n.º ' . trim((string) $submission['at_id_ficheiro']);
                                        }
                                        if (trim((string) ($submission['at_total_faturas'] ?? '')) !== '') {
                                            $atDetails[] = 'Faturas: ' . trim((string) $submission['at_total_faturas'])
                                                . ' | Créditos: ' . trim((string) ($submission['at_total_creditos'] ?? '—'))
                                                . ' | Débitos: ' . trim((string) ($submission['at_total_debitos'] ?? '—'));
                                        }
                                        $atTooltipParts = [];
                                        if (trim((string) ($submission['at_warning'] ?? '')) !== '') {
                                            $atTooltipParts[] = 'Aviso: ' . trim((string) $submission['at_warning']);
                                        }
                                        if (trim((string) ($submission['at_errors'] ?? '')) !== '') {
                                            $atTooltipParts[] = 'Erros: ' . trim((string) $submission['at_errors']);
                                        }
                                    ?>
                                    <?php if ($atDetails): ?>
                                        <small<?= $atTooltipParts ? ' title="' . htmlspecialchars(implode("\n", $atTooltipParts), ENT_QUOTES) . '"' : ''; ?>>
                                            <?= htmlspecialchars(implode(' · ', $atDetails)); ?>
                                            <?php if ($atTooltipParts): ?><i class="fa fa-info-circle text-warning"></i><?php endif; ?>
                                        </small>
                                    <?php elseif ($atTooltipParts): ?>
                                        <small class="text-danger" title="<?= htmlspecialchars(implode("\n", $atTooltipParts), ENT_QUOTES); ?>">
                                            <?= htmlspecialchars(mb_strimwidth(implode(' ', $atTooltipParts), 0, 80, '…')); ?>
                                        </small>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                        $extractionError = trim((string) ($submission['saft_extraction_error'] ?? ''));
                                        $numberOfEntries = $submission['saft_number_of_entries'] ?? null;
                                    ?>
                                    <?php if ($extractionError !== ''): ?>
                                        <small class="text-danger" title="<?= htmlspecialchars($extractionError, ENT_QUOTES); ?>">
                                            <i class="fa fa-exclamation-triangle"></i> Falha na extração
                                        </small>
                                    <?php elseif ($numberOfEntries !== null): ?>
                                        <small>
                                            <?= (int) $numberOfEntries; ?> faturas
                                            · Débito: <?= htmlspecialchars(number_format((float) ($submission['saft_total_debit'] ?? 0), 2, ',', '.')); ?>
                                            · Crédito: <?= htmlspecialchars(number_format((float) ($submission['saft_total_credit'] ?? 0), 2, ',', '.')); ?>
                                        </small>
                                        <br>
                                        <button type="button" class="btn btn-xs btn-default saft-view-invoices"
                                            data-submission-id="<?= (int) $submission['id']; ?>"
                                            data-entity-name="<?= htmlspecialchars((string) $submission['entity_name'], ENT_QUOTES); ?>"
                                            data-period="<?= htmlspecialchars(($monthNames[(int) $submission['period_month']] ?? '') . ' ' . $submission['period_year'], ENT_QUOTES); ?>">
                                            <i class="fa fa-list"></i> Ver faturas
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right" style="white-space: nowrap;">
                                    <a class="btn btn-xs btn-default" href="<?= htmlspecialchars(BASE_URL . ltrim((string) $submission['file_path'], '/')); ?>" download="<?= htmlspecialchars($submission['original_filename'], ENT_QUOTES); ?>">
                                        <i class="fa fa-download"></i> Transferir
                                    </a>
                                    <form method="post" style="display: inline-block; margin: 0;" onsubmit="return confirm('Eliminar este envio de SAF-T?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="submission_id" value="<?= (int) $submission['id']; ?>">
                                        <button type="submit" class="btn btn-xs btn-danger">
                                            <i class="fa fa-trash"></i>
                                        </button>
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
</div>

<div class="modal fade" id="saft-invoices-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fa fa-list"></i> Faturas extraídas do SAF-T</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body" style="padding: 0;">
                <div id="saft-invoices-modal-summary" class="d-flex flex-wrap justify-content-between align-items-center" style="padding: 12px 20px; background: #f7f9fb; border-bottom: 1px solid #e6e9ed; font-size: 13px; color: #73879c;"></div>
                <div class="table-responsive">
                    <table class="table table-striped table-hover table-sm" style="margin-bottom: 0;">
                        <thead style="background: #f7f9fb;">
                            <tr>
                                <th style="padding: 10px 20px;">N.º Fatura</th>
                                <th>Tipo</th>
                                <th>Data</th>
                                <th>Cliente</th>
                                <th class="text-right">Total s/IVA</th>
                                <th class="text-right">IVA</th>
                                <th class="text-right" style="padding-right: 20px;">Total c/IVA</th>
                            </tr>
                        </thead>
                        <tbody id="saft-invoices-modal-body">
                            <tr><td colspan="7" class="text-center text-muted" style="padding: 30px;"><i class="fa fa-spinner fa-spin"></i> A carregar…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.jQuery && $.fn.DataTable) {
        $('#saft-submissions-table').DataTable({
            language: { url: 'vendors/datatables.net/i18n/pt-PT.json' },
            order: [[0, 'desc']],
            columnDefs: [{ targets: -1, orderable: false }]
        });
    }

    document.querySelectorAll('.saft-view-invoices').forEach(function (button) {
        button.addEventListener('click', function () {
            var submissionId = button.getAttribute('data-submission-id');
            var body = document.getElementById('saft-invoices-modal-body');
            var summary = document.getElementById('saft-invoices-modal-summary');
            body.innerHTML = '<tr><td colspan="7" class="text-center text-muted" style="padding: 30px;"><i class="fa fa-spinner fa-spin"></i> A carregar…</td></tr>';
            summary.innerHTML = '<strong>' + escapeHtml(button.getAttribute('data-entity-name') || '') + '</strong>'
                + '<span>' + escapeHtml(button.getAttribute('data-period') || '') + '</span>';
            if (window.jQuery) {
                $('#saft-invoices-modal').modal('show');
            }
            fetch('<?= htmlspecialchars(BASE_URL . 'contabilidade/tarefas/envio-saft'); ?>?action=invoices&submission_id=' + encodeURIComponent(submissionId))
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    var invoices = data.invoices || [];
                    if (!invoices.length) {
                        body.innerHTML = '<tr><td colspan="7" class="text-center text-muted" style="padding: 30px;">Sem faturas extraídas.</td></tr>';
                        return;
                    }
                    var totalNet = 0, totalTax = 0, totalGross = 0;
                    body.innerHTML = invoices.map(function (inv) {
                        totalNet += parseFloat(inv.net_total) || 0;
                        totalTax += parseFloat(inv.tax_payable) || 0;
                        totalGross += parseFloat(inv.gross_total) || 0;
                        return '<tr>'
                            + '<td style="padding-left: 20px;">' + escapeHtml(inv.invoice_no || '') + '</td>'
                            + '<td>' + escapeHtml(inv.invoice_type || '') + '</td>'
                            + '<td>' + formatDate(inv.invoice_date) + '</td>'
                            + '<td>' + escapeHtml(inv.customer_id || '') + '</td>'
                            + '<td class="text-right">' + formatMoney(inv.net_total) + '</td>'
                            + '<td class="text-right">' + formatMoney(inv.tax_payable) + '</td>'
                            + '<td class="text-right" style="padding-right: 20px;">' + formatMoney(inv.gross_total) + '</td>'
                            + '</tr>';
                    }).join('');
                    summary.innerHTML += '<span><strong>' + invoices.length + '</strong> faturas &nbsp;·&nbsp; Total c/IVA: <strong>' + formatMoney(totalGross) + '</strong></span>';
                })
                .catch(function () {
                    body.innerHTML = '<tr><td colspan="7" class="text-center text-danger" style="padding: 30px;">Erro ao carregar faturas.</td></tr>';
                });
        });
    });

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    function formatMoney(value) {
        var num = parseFloat(value);
        if (isNaN(num)) { return '—'; }
        return num.toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function formatDate(value) {
        if (!value) { return '—'; }
        var parts = String(value).split('-');
        if (parts.length === 3) {
            return parts[2] + '/' + parts[1] + '/' + parts[0];
        }
        return escapeHtml(value);
    }
});
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
