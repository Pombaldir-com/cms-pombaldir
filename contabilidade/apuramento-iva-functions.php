<?php
// Helpers partilhados pelas paginas da tarefa "Apuramento de IVA"
// (tarefas-apuramento-iva.php e tarefas-apuramento-iva-detalhes.php).
// Ver contabilidade/APURAMENTO_IVA.md.

/**
 * Empresas (entidades adquirentes) a que o utilizador tem acesso nesta tarefa.
 */
function getIvaTaskEntities(PDO $pdo, bool $isAdmin, int $userId): array {
    $periodicityColumn = hasColumn('accounting_entities', 'vat_periodicity') ? 'vat_periodicity' : "'mensal'";
    if ($isAdmin) {
        $stmt = $pdo->query(
            "SELECT id, nif, name, erp_database, $periodicityColumn AS vat_periodicity FROM accounting_entities
             WHERE entity_type = 'acquirer'
             ORDER BY name ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $stmt = $pdo->prepare(
        "SELECT ae.id, ae.nif, ae.name, ae.erp_database, ae.$periodicityColumn AS vat_periodicity
         FROM accounting_entities ae
         INNER JOIN accounting_entity_admin_task_permissions aep
             ON aep.accounting_entity_id = ae.id
         WHERE ae.entity_type = 'acquirer'
           AND aep.permission_key = 'ctb_apuramento_iva'
           AND aep.user_id = ?
         ORDER BY ae.name ASC"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function buildVatPeriodLabel(string $periodType, int $year, int $ref): string {
    return $periodType === 'trimestral' ? "$year-T$ref" : sprintf('%04d-%02d', $year, $ref);
}

function getClosedVatPeriods(PDO $pdo, int $entityId): array {
    $stmt = $pdo->prepare(
        'SELECT period_label FROM accounting_vat_settlements WHERE accounting_entity_id = ?'
    );
    $stmt->execute([$entityId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/**
 * Datas do periodo de IVA (mensal ou trimestral) e chave do periodo no
 * formato usado pelo ERP na Tbl_Ctb_DeclPer: YYYYMMDD do 1o dia + YYYYMMDD
 * do ultimo dia, concatenados (ex.: 2026010120260331).
 *
 * @return array{start:string,end:string,month_start:int,month_end:int,erp_period:string}
 */
function buildVatPeriodRange(string $periodType, int $year, int $ref): array {
    if ($periodType === 'trimestral') {
        $monthStart = ($ref - 1) * 3 + 1;
        $monthEnd = $monthStart + 2;
    } else {
        $monthStart = $ref;
        $monthEnd = $ref;
    }
    $start = sprintf('%04d-%02d-01', $year, $monthStart);
    $end = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $monthEnd)));
    return [
        'start' => $start,
        'end' => $end,
        'month_start' => $monthStart,
        'month_end' => $monthEnd,
        'erp_period' => str_replace('-', '', $start) . str_replace('-', '', $end),
    ];
}

/**
 * Extrai a lista de linhas de uma resposta do ERP-SINC, qualquer que seja o
 * wrapper usado (data / aaData / lista simples).
 */
function extractErpVatResponseRows($payload): array {
    if (!is_array($payload)) {
        return [];
    }
    if (isset($payload['data']) && is_array($payload['data'])) {
        return $payload['data'];
    }
    if (isset($payload['aaData']) && is_array($payload['aaData'])) {
        return $payload['aaData'];
    }
    return array_is_list($payload) ? $payload : [];
}

/**
 * Balancete do periodo via ERP-SINC (GET contabilidade/saldos), agregado por
 * conta no formato esperado por evaluateAccountingVatFieldFormula():
 * [conta => ['valor' => credito-debito, 'fltCredito' => ..., 'fltDebito' => ...]].
 * Quando o intervalo cobre varios meses (trimestre) as linhas da mesma conta
 * sao somadas, tal como fazia cta() na intranet legacy.
 *
 * @return array{success:bool,balances:array<string,array<string,float>>,error:string}
 */
function fetchErpVatAccountBalances(string $database, int $year, int $monthStart, int $monthEnd): array {
    $query = [
        'strCodExercicio' => (string) $year,
        'intMes' => $monthStart,
        'intMes2' => $monthEnd,
    ];
    $path = 'contabilidade/saldos?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $response = callErpJsonEndpoint($path, 'GET', null, true, $database);
    if (empty($response['success'])) {
        return ['success' => false, 'balances' => [], 'error' => trim((string) ($response['error'] ?? 'Falha ao obter o balancete do ERP.'))];
    }
    $payload = $response['data'];
    if (is_array($payload) && isset($payload['success']) && !$payload['success']) {
        return ['success' => false, 'balances' => [], 'error' => trim((string) ($payload['errormsg'] ?? 'Falha ao obter o balancete do ERP.'))];
    }

    $balances = [];
    foreach (extractErpVatResponseRows($payload) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $account = trim((string) ($row['strConta'] ?? ''));
        if ($account === '') {
            continue;
        }
        if (!isset($balances[$account])) {
            $balances[$account] = ['valor' => 0.0, 'fltCredito' => 0.0, 'fltDebito' => 0.0];
        }
        $credit = (float) ($row['fltCredito'] ?? 0);
        $debit = (float) ($row['fltDebito'] ?? 0);
        $balances[$account]['fltCredito'] += $credit;
        $balances[$account]['fltDebito'] += $debit;
        $balances[$account]['valor'] += isset($row['fltSaldo']) ? (float) $row['fltSaldo'] : ($credit - $debit);
    }

    return ['success' => true, 'balances' => $balances, 'error' => ''];
}

/**
 * Valores da Declaracao Periodica gerada no ERP (GET contabilidade/declperiodica)
 * para o periodo, indexados por numero de campo. Apenas o anexo "1" (rosto),
 * como no legacy ($objsctb[campo][1]['valor']).
 *
 * @return array{success:bool,values:array<int,float>,error:string}
 */
function fetchErpVatDeclarationValues(string $database, string $erpPeriod): array {
    $query = ['strPeriodo' => $erpPeriod, 'strAnexo' => '1'];
    $path = 'contabilidade/declperiodica?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $response = callErpJsonEndpoint($path, 'GET', null, true, $database);
    if (empty($response['success'])) {
        return ['success' => false, 'values' => [], 'error' => trim((string) ($response['error'] ?? 'Falha ao obter a Declaração Periódica do ERP.'))];
    }
    $payload = $response['data'];
    if (is_array($payload) && isset($payload['success']) && !$payload['success']) {
        return ['success' => false, 'values' => [], 'error' => trim((string) ($payload['errormsg'] ?? 'Falha ao obter a Declaração Periódica do ERP.'))];
    }

    $values = [];
    foreach (extractErpVatResponseRows($payload) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $field = (int) ($row['intCodigo'] ?? 0);
        if ($field <= 0) {
            continue;
        }
        $values[$field] = round((float) ($row['fltValor'] ?? 0), 2);
    }

    return ['success' => true, 'values' => $values, 'error' => ''];
}

/**
 * Margem de erro entre um valor esperado (base x taxa) e o valor real,
 * replicando IvaDeclMargemErro() do legacy: desvio-padrao populacional dos
 * dois valores vezes 2 (= |esperado - real|), arredondado a 2 casas.
 */
function computeVatFieldDeviation(float $base, float $value, float $rate): float {
    $expected = $base * $rate;
    $mean = ($expected + $value) / 2;
    $stddev = sqrt((($expected - $mean) ** 2 + ($value - $mean) ** 2) / 2);
    return round($stddev * 2, 2);
}

/**
 * Avalia uma formula contra os saldos; se o resultado for 0, repete usando o
 * lado credor para os termos sem lado explicito (fallback do legacy).
 */
function evaluateVatFieldFormulaWithFallback(string $formula, array $balances): float {
    $terms = parseAccountingVatFieldFormula($formula);
    $value = evaluateAccountingVatFieldFormula($terms, $balances);
    if (abs($value) < 0.005) {
        foreach ($terms as &$term) {
            if ($term['side'] === null) {
                $term['side'] = 'cre';
            }
        }
        unset($term);
        $value = evaluateAccountingVatFieldFormula($terms, $balances);
    }
    return round($value, 2);
}

/**
 * Compara campo-a-campo os valores da DP com os calculados a partir do
 * balancete, aplicando as regras por campo da intranet legacy
 * (window.php?act=wkfloproc, task=6). Ver contabilidade/APURAMENTO_IVA.md.
 *
 * Estados: 'ok' (verde), 'warning' (laranja, nao bloqueia o fecho) e
 * 'error' (vermelho, bloqueia o fecho).
 *
 * @param array<int,array{field_number:int|string,formula:string}> $fieldFormulas
 * @param array<int,float> $dpValues
 * @param array<string,array<string,float>> $balances
 * @return array{rows:array<int,array<string,mixed>>,has_error:bool,dp_values:array<int,float>}
 */
function evaluateVatFieldRows(array $fieldFormulas, array $dpValues, array $balances): array {
    $dp = static function (int $field) use (&$dpValues): float {
        return (float) ($dpValues[$field] ?? 0);
    };
    $rateChecks = [
        2 => [1, [0.06]],
        4 => [3, [0.23]],
        6 => [5, [0.13]],
        13 => [12, [0.23, 0.13, 0.06]],
        17 => [16, [0.23, 0.13, 0.06]],
    ];

    $rows = [];
    $hasError = false;
    foreach ($fieldFormulas as $formulaRow) {
        $field = (int) $formulaRow['field_number'];
        $formulaError = '';
        try {
            $ctb = evaluateVatFieldFormulaWithFallback((string) $formulaRow['formula'], $balances);
        } catch (InvalidArgumentException $e) {
            $ctb = 0.0;
            $formulaError = $e->getMessage();
        }
        if ($field === 7) {
            $ctb = round($ctb, 0);
        }

        // Campo 93 (IVA a pagar) e calculado a partir dos restantes campos da DP.
        if ($field === 93) {
            $computed = ($dp(2) + $dp(6) + $dp(4) + $dp(13) + $dp(17) + $dp(41) + $dp(66))
                - ($dp(20) + $dp(21) + $dp(22) + $dp(23) + $dp(24) + $dp(40) + $dp(61));
            $dpValues[93] = $computed < 0 ? 0.0 : round($computed, 2);
        }
        $dpValue = $dp($field);

        $override = '';
        $note = '';
        if (isset($rateChecks[$field])) {
            [$baseField, $rates] = $rateChecks[$field];
            $deviations = [];
            foreach ($rates as $rate) {
                $deviations[] = computeVatFieldDeviation($dp($baseField), $dpValue, $rate);
            }
            $best = min($deviations);
            if ($best >= 1) {
                $override = 'warning';
                $note = 'diferença: ' . number_format($deviations[0], 2, ',', '.') . ' (C' . $baseField . ' × taxa)';
            }
        } elseif ($field === 7) {
            $deviation = abs($ctb - $dpValue);
            $note = 'diferença: ' . number_format($deviation, 2, ',', '.');
            if ($deviation <= 1) {
                $override = 'ok';
                $ctb = $dpValue;
            } else {
                $override = 'error';
            }
        } elseif ($field === 93 && $dpValue <= 0) {
            $override = 'ok';
        }

        $matches = number_format($ctb, 2, '.', '') === number_format($dpValue, 2, '.', '');
        $diff = round($ctb - $dpValue, 2);
        if ($matches) {
            $status = $override !== '' ? $override : 'ok';
        } else {
            $status = $override !== '' ? $override : 'error';
            if ($note === '') {
                $note = 'diferença: ' . number_format($diff, 2, ',', '.');
            }
        }
        if ($status === 'error') {
            $hasError = true;
        }

        $rows[] = [
            'field_number' => $field,
            'dp_value' => $dpValue,
            'ctb_value' => $ctb,
            'diff' => $diff,
            'status' => $status,
            'note' => $note,
            'formula_error' => $formulaError,
        ];
    }

    return ['rows' => $rows, 'has_error' => $hasError, 'dp_values' => $dpValues];
}

/**
 * Resultado do periodo (fase "IVA a pagar / a recuperar" do legacy): avalia a
 * formula do campo 94 (credito) e, se for zero, a do campo 93 (a pagar).
 *
 * @return array{type:string,field:int,value:float,available:bool}
 */
function computeVatSettlementExpected(array $fieldFormulas, array $balances): array {
    $formulasByField = [];
    foreach ($fieldFormulas as $formulaRow) {
        $formulasByField[(int) $formulaRow['field_number']] = (string) $formulaRow['formula'];
    }
    foreach ([94 => 'credito', 93 => 'pagar'] as $field => $type) {
        if (!isset($formulasByField[$field])) {
            continue;
        }
        try {
            $value = evaluateVatFieldFormulaWithFallback($formulasByField[$field], $balances);
        } catch (InvalidArgumentException $e) {
            continue;
        }
        if (abs($value) >= 0.005) {
            return ['type' => $type, 'field' => $field, 'value' => round(abs($value), 2), 'available' => true];
        }
    }
    $available = isset($formulasByField[93]) || isset($formulasByField[94]);
    return ['type' => 'pagar', 'field' => 93, 'value' => 0.0, 'available' => $available];
}

/**
 * Envolve o conteudo de um email da tarefa de IVA num template HTML simples
 * (inline styles, sem CSS externo, seguro para clientes de email), com
 * cabecalho de marca (app_name/app_logo) e assinatura da empresa de
 * contabilidade no final. Reutilizado por buildVatFieldReportEmailBody() e
 * buildVatClientNotificationEmailBody().
 */
function buildVatEmailTemplate(string $title, string $subtitle, string $bodyHtml): string {
    $appName = trim((string) getSetting('app_name', '')) ?: 'Contabilidade';
    $logoPath = trim((string) getSetting('app_logo', ''));
    $logoUrl = '';
    if ($logoPath !== '' && function_exists('appAbsoluteBaseUrl') && file_exists(__DIR__ . '/../' . $logoPath)) {
        $logoUrl = appAbsoluteBaseUrl() . ltrim($logoPath, '/');
    }

    $signerName = trim((string) getSetting('system_email_from_name', '')) ?: $appName;
    $signerEmail = trim((string) getSetting('system_email_from_email', ''));

    $headerLogo = $logoUrl !== ''
        ? '<img src="' . htmlspecialchars($logoUrl) . '" alt="" height="28" style="display:block; vertical-align:middle;">'
        : '<span style="color:#ffffff; font-size:17px; font-weight:700; letter-spacing:.02em;">' . htmlspecialchars($appName) . '</span>';

    $signatureLines = '<strong style="color:#2a3f54;">' . htmlspecialchars($signerName) . '</strong>';
    if ($signerEmail !== '') {
        $signatureLines .= '<br><a href="mailto:' . htmlspecialchars($signerEmail) . '" style="color:#73879c; text-decoration:none;">' . htmlspecialchars($signerEmail) . '</a>';
    }

    return '<div style="font-family: Arial, Helvetica, sans-serif; max-width:640px; margin:0 auto; background:#ffffff;">'
        . '<div style="background:#2a3f54; padding:16px 24px; border-radius:6px 6px 0 0;">' . $headerLogo . '</div>'
        . '<div style="border:1px solid #e6e9ed; border-top:none; border-radius:0 0 6px 6px; padding:26px 24px;">'
        . '<h2 style="margin:0 0 4px; font-size:18px; color:#2a3f54;">' . htmlspecialchars($title) . '</h2>'
        . ($subtitle !== '' ? '<p style="margin:0 0 20px; font-size:13px; color:#97a3b3;">' . htmlspecialchars($subtitle) . '</p>' : '')
        . '<div style="font-size:14px; color:#2a3f54; line-height:1.55;">' . $bodyHtml . '</div>'
        . '<div style="margin-top:28px; padding-top:16px; border-top:1px solid #e6e9ed; font-size:13px; line-height:1.6;">'
        . '<p style="margin:0 0 10px; color:#73879c;">Com os melhores cumprimentos,</p>'
        . '<p style="margin:0;">' . $signatureLines . '</p>'
        . '</div>'
        . '</div>'
        . '</div>';
}

/**
 * Corpo HTML do email de relatorio (Campo / DP / Ctb) enviado a partir do
 * quadro de campos, equivalente a tabela $htmlt de workflow_iva.php no
 * legacy (accao "message"), com formatacao propria e assinatura da empresa
 * de contabilidade (ver buildVatEmailTemplate()).
 *
 * @param array<int,array<string,mixed>> $fieldRows
 */
function buildVatFieldReportEmailBody(array $entity, string $periodLabel, array $fieldRows, array $expected = []): string {
    $rowsHtml = '';
    foreach ($fieldRows as $index => $row) {
        $status = (string) ($row['status'] ?? 'ok');
        $rowBg = $status !== 'ok'
            ? ($status === 'error' ? ' background:#fdecea;' : ' background:#fdf3e6;')
            : ($index % 2 === 1 ? ' background:#f7f9fb;' : '');
        $statusColor = $status === 'error' ? '#c0392b' : ($status === 'warning' ? '#a9720f' : '#26b99a');
        $statusLabel = $status === 'error' ? 'Diferença' : ($status === 'warning' ? 'Aviso' : 'OK');
        $statusNote = trim((string) ($row['note'] ?? ''));
        $statusCell = '<span style="color:' . $statusColor . '; font-weight:600;">' . $statusLabel . '</span>'
            . ($status !== 'ok' && $statusNote !== '' ? '<br><span style="font-size:11px; color:#97a3b3;">' . htmlspecialchars($statusNote) . '</span>' : '');
        $rowsHtml .= '<tr style="' . trim($rowBg) . '">'
            . '<td style="padding:7px 10px; border-bottom:1px solid #eef1f4; font-weight:600;">C' . (int) $row['field_number'] . '</td>'
            . '<td style="padding:7px 10px; border-bottom:1px solid #eef1f4; text-align:right;">' . number_format((float) $row['dp_value'], 2, ',', '.') . ' €</td>'
            . '<td style="padding:7px 10px; border-bottom:1px solid #eef1f4; text-align:right;">' . number_format((float) $row['ctb_value'], 2, ',', '.') . ' €</td>'
            . '<td style="padding:7px 10px; border-bottom:1px solid #eef1f4; text-align:center;">' . $statusCell . '</td>'
            . '</tr>';
    }

    $summaryHtml = '';
    if (!empty($expected['available'])) {
        $isCredito = (string) $expected['type'] === 'credito';
        $summaryBg = $isCredito ? '#eef9f6' : '#fdf3e6';
        $summaryBorder = $isCredito ? '#cdeee5' : '#f8e2bd';
        $summaryColor = $isCredito ? '#26b99a' : '#a9720f';
        $summaryLabel = $isCredito ? 'em crédito (a recuperar)' : 'a pagar';
        $summaryHtml = '<div style="background:' . $summaryBg . '; border:1px solid ' . $summaryBorder . '; border-radius:6px; padding:14px 18px; margin:16px 0 0;">'
            . '<span style="font-size:11px; color:#73879c; text-transform:uppercase; letter-spacing:.03em;">Valor apurado pela contabilidade (campo ' . (int) $expected['field'] . ')</span><br>'
            . '<span style="font-size:20px; font-weight:700; color:' . $summaryColor . ';">' . number_format((float) $expected['value'], 2, ',', '.') . ' €</span>'
            . ' <span style="font-size:12px; color:#97a3b3;">' . $summaryLabel . '</span>'
            . '</div>';
    }

    $body = '<p style="margin:0 0 16px;">Segue os valores da Declaração Periódica de IVA apurados para o cliente '
        . '<strong>' . htmlspecialchars((string) $entity['name']) . '</strong> '
        . '(NIF ' . htmlspecialchars((string) $entity['nif']) . '), referente ao período <strong>' . htmlspecialchars($periodLabel) . '</strong>.</p>'
        . '<table style="width:100%; border-collapse:collapse; font-size:13.5px;">'
        . '<thead><tr style="background:#eef1f4;">'
        . '<th style="text-align:left; padding:8px 10px; color:#73879c; font-size:11px; text-transform:uppercase; letter-spacing:.03em;">Campo</th>'
        . '<th style="text-align:right; padding:8px 10px; color:#73879c; font-size:11px; text-transform:uppercase; letter-spacing:.03em;">DP</th>'
        . '<th style="text-align:right; padding:8px 10px; color:#73879c; font-size:11px; text-transform:uppercase; letter-spacing:.03em;">Ctb</th>'
        . '<th style="text-align:center; padding:8px 10px; color:#73879c; font-size:11px; text-transform:uppercase; letter-spacing:.03em;">Estado</th>'
        . '</tr></thead>'
        . '<tbody>' . $rowsHtml . '</tbody>'
        . '</table>'
        . $summaryHtml;

    return buildVatEmailTemplate('Apuramento de IVA', 'Período ' . $periodLabel, $body);
}

/**
 * Corpo HTML da notificacao ao cliente com o valor a pagar ou em credito do
 * periodo, equivalente ao email da fase 8 ("IVA a pagar/a recuperar") do
 * legacy, com formatacao propria e assinatura da empresa de contabilidade
 * (ver buildVatEmailTemplate()).
 *
 * $expected e o resultado de computeVatSettlementExpected() (valor apurado
 * pela contabilidade a partir do balancete, campo 93/94) — mostrado como
 * referencia por baixo do valor fechado, que pode ter sido ajustado
 * manualmente no formulario de fecho. Passar [] quando nao disponivel.
 */
function buildVatClientNotificationEmailBody(array $entity, string $periodLabel, string $resultType, float $valorPagar, float $valorRecuperar, array $expected = []): string {
    $name = htmlspecialchars((string) $entity['name']);
    $nif = htmlspecialchars((string) $entity['nif']);
    $period = htmlspecialchars($periodLabel);

    $expectedNote = '';
    if (!empty($expected['available'])) {
        $expectedNote = '<p style="margin:8px 0 0; font-size:12px; color:#97a3b3;">'
            . 'Valor apurado pela contabilidade (campo ' . (int) $expected['field'] . '): '
            . number_format((float) $expected['value'], 2, ',', '.') . ' € '
            . ((string) $expected['type'] === 'credito' ? 'em crédito (a recuperar)' : 'a pagar')
            . '.</p>';
    }

    if ($resultType === 'credito') {
        $body = '<p style="margin:0 0 14px;">Exmo(a). Sr(a)., informamos que o apuramento de IVA de <strong>' . $name . '</strong> '
            . '(NIF ' . $nif . '), referente ao período <strong>' . $period . '</strong>, ficou em crédito.</p>';
        if ($valorRecuperar > 0) {
            $body .= '<div style="background:#eef9f6; border:1px solid #cdeee5; border-radius:6px; padding:14px 18px; margin:0 0 6px;">'
                . '<span style="font-size:12px; color:#73879c; text-transform:uppercase; letter-spacing:.03em;">Reembolso solicitado</span><br>'
                . '<span style="font-size:20px; font-weight:700; color:#26b99a;">' . number_format($valorRecuperar, 2, ',', '.') . ' €</span>'
                . '</div>'
                . $expectedNote;
        }
    } else {
        $body = '<p style="margin:0 0 14px;">Exmo(a). Sr(a)., referente ao período <strong>' . $period . '</strong> '
            . '(' . $name . ', NIF ' . $nif . '), tem um valor de IVA a pagar de:</p>'
            . '<div style="background:#fdf3e6; border:1px solid #f8e2bd; border-radius:6px; padding:14px 18px; margin:0 0 6px;">'
            . '<span style="font-size:20px; font-weight:700; color:#a9720f;">' . number_format($valorPagar, 2, ',', '.') . ' €</span>'
            . '</div>'
            . $expectedNote;
    }

    return buildVatEmailTemplate('IVA a pagar / a recuperar', 'Período ' . $periodLabel, $body);
}

