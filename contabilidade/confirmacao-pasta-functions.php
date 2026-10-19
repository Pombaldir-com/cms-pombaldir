<?php
// Helpers da tarefa "Confirmacao de Pasta" (tarefas-confirmacao-pasta*.php),
// porta da fase 4 "Lancamento de docs" da intranet legacy
// (window.php?act=wkfloproc&task=4 + ifrm_utils.php?act=diariosmov).
// Ver contabilidade/CONFIRMACAO_PASTA.md.

/**
 * Diarios conferidos, pela ordem do legacy ($array_diarios em window.php).
 * Outros diarios com movimentos no mes sao acrescentados no fim com o nome
 * vindo do ERP (Tbl_Diarios).
 *
 * @return array<int,string> intCodDiario => nome
 */
function getFolderConfirmationDefaultJournals(): array {
    return [
        1 => 'Caixa',
        2 => 'Bancos',
        3 => 'Vendas',
        4 => 'Compras',
        5 => 'Diversos',
        8 => 'Imp. efatura',
        9 => 'Compras dinh.',
        11 => 'Recibos',
        12 => 'Pagamentos',
    ];
}

function buildFolderPeriodLabel(int $year, int $month): string {
    return sprintf('%04d-%02d', $year, $month);
}

function getClosedFolderPeriods(PDO $pdo, int $entityId): array {
    $stmt = $pdo->prepare('SELECT period_label FROM accounting_folder_confirmations WHERE accounting_entity_id = ?');
    $stmt->execute([$entityId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function findFolderConfirmation(PDO $pdo, int $entityId, string $periodLabel): ?array {
    $stmt = $pdo->prepare(
        'SELECT c.*, u.name AS closed_by_name, u.username AS closed_by_username
         FROM accounting_folder_confirmations c
         LEFT JOIN users u ON u.id = c.closed_by
         WHERE c.accounting_entity_id = ? AND c.period_label = ? LIMIT 1'
    );
    $stmt->execute([$entityId, $periodLabel]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Totais dos lancamentos a credito do mes por diario e documento, via
 * ERP-SINC (GET contabilidade/movimentosdiarios, strDeb_Cre=C). O legacy so
 * conferia o lado credor ($objsctb[diario]['C']).
 *
 * @return array{success:bool,journals:array<int,array{name:string,docs:array<int,array{doc:string,date:?string,valor:float}>,total:float}>,error:string}
 */
function fetchErpFolderJournalTotals(string $database, int $year, int $month): array {
    $query = ['strCodExercicio' => (string) $year, 'intMes' => $month, 'strDeb_Cre' => 'C'];
    $path = 'contabilidade/movimentosdiarios?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $response = callErpJsonEndpoint($path, 'GET', null, true, $database);
    if (empty($response['success'])) {
        return ['success' => false, 'journals' => [], 'error' => trim((string) ($response['error'] ?? 'Falha ao obter os movimentos do ERP.'))];
    }
    $payload = $response['data'];
    if (is_array($payload) && isset($payload['success']) && !$payload['success']) {
        return ['success' => false, 'journals' => [], 'error' => trim((string) ($payload['errormsg'] ?? 'Falha ao obter os movimentos do ERP.'))];
    }

    $journals = [];
    foreach (extractErpVatResponseRows($payload) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = (int) ($row['intCodDiario'] ?? 0);
        if (!isset($journals[$code])) {
            $journals[$code] = ['name' => trim((string) ($row['strDescDiario'] ?? '')), 'docs' => [], 'total' => 0.0];
        }
        $valor = round((float) ($row['fltValor'] ?? 0), 2);
        $journals[$code]['docs'][] = [
            'doc' => trim((string) ($row['strNum_Doc'] ?? '')),
            'date' => isset($row['strData']) ? (string) $row['strData'] : null,
            'valor' => $valor,
        ];
        $journals[$code]['total'] = round($journals[$code]['total'] + $valor, 2);
    }

    return ['success' => true, 'journals' => $journals, 'error' => ''];
}

/**
 * @return array<int,array<int,array{id:int,valor:float}>> diario => valores da fita
 */
function getFolderTapeValues(PDO $pdo, int $entityId, int $year, int $month): array {
    $stmt = $pdo->prepare(
        'SELECT id, journal_code, valor FROM accounting_folder_tape_values
         WHERE accounting_entity_id = ? AND period_year = ? AND period_month = ?
         ORDER BY journal_code ASC, id ASC'
    );
    $stmt->execute([$entityId, $year, $month]);
    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $result[(int) $row['journal_code']][] = ['id' => (int) $row['id'], 'valor' => round((float) $row['valor'], 2)];
    }
    return $result;
}

/**
 * Converte o texto introduzido na fita numa lista de valores. Aceita varios
 * valores separados por "+", ";" ou mudanca de linha, com virgula ou ponto
 * decimal (ex.: "12,50+1.234,00\n7.5").
 *
 * @return array{values:float[],invalid:?string}
 */
function parseFolderTapeInput(string $raw): array {
    $values = [];
    foreach (preg_split('/[+;\r\n]+/', $raw) ?: [] as $token) {
        $token = preg_replace('/[\s€]+/u', '', $token);
        if ($token === '' || $token === null) {
            continue;
        }
        $lastComma = strrpos($token, ',');
        $lastDot = strrpos($token, '.');
        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            $token = str_replace(',', '.', str_replace('.', '', $token));
        } else {
            $token = str_replace(',', '', $token);
        }
        if (!preg_match('/^-?\d+(\.\d+)?$/', $token)) {
            return ['values' => [], 'invalid' => $token];
        }
        $values[] = round((float) $token, 2);
    }
    return ['values' => $values, 'invalid' => null];
}

/**
 * Emparelha os valores da fita com os totais por documento do ERP (em
 * centimos, contando repeticoes), para assinalar a vermelho os que nao tem
 * par, como o ecra "Conferencia de valores" do legacy. Diferenca deliberada:
 * o legacy usava in_array(), pelo que dois valores iguais na fita ficavam
 * "certos" com um unico documento correspondente.
 *
 * @param array<int,array{id:int,valor:float}> $tapeValues
 * @param array<int,array{doc:string,date:?string,valor:float}> $docs
 * @return array{tape:array<int,bool>,docs:array<int,bool>,unmatched_tape:float[],unmatched_docs:float[]}
 */
function matchFolderTapeValues(array $tapeValues, array $docs): array {
    $available = [];
    foreach ($docs as $index => $doc) {
        $available[(int) round($doc['valor'] * 100)][] = $index;
    }
    $tapeMatched = [];
    $docMatched = array_fill_keys(array_keys($docs), false);
    $unmatchedTape = [];
    foreach ($tapeValues as $index => $tape) {
        $cents = (int) round($tape['valor'] * 100);
        if ($cents === 0) {
            $tapeMatched[$index] = true;
            continue;
        }
        if (!empty($available[$cents])) {
            $docIndex = array_shift($available[$cents]);
            $docMatched[$docIndex] = true;
            $tapeMatched[$index] = true;
        } else {
            $tapeMatched[$index] = false;
            $unmatchedTape[] = $tape['valor'];
        }
    }
    $unmatchedDocs = [];
    foreach ($docs as $index => $doc) {
        if (!$docMatched[$index]) {
            $unmatchedDocs[] = $doc['valor'];
        }
    }
    return ['tape' => $tapeMatched, 'docs' => $docMatched, 'unmatched_tape' => $unmatchedTape, 'unmatched_docs' => $unmatchedDocs];
}

/**
 * Totais do SAF-T do mes submetido na tarefa "Envio de SAF-T"
 * (accounting_saft_submissions), preferindo a ultima submissao aceite pela
 * AT. No legacy vinham dos campos adicionais 7 (debito) e 8 (credito) do
 * ficheiro SAF-T carregado na ficha do cliente.
 *
 * @return array{found:bool,debit:float,credit:float,submission_id:?int,created_at:?string}
 */
function fetchFolderSaftTotals(PDO $pdo, int $entityId, int $year, int $month): array {
    $empty = ['found' => false, 'debit' => 0.0, 'credit' => 0.0, 'submission_id' => null, 'created_at' => null];
    if (!hasTable('accounting_saft_submissions') || !hasColumn('accounting_saft_submissions', 'saft_total_debit')) {
        return $empty;
    }
    $stmt = $pdo->prepare(
        "SELECT id, saft_total_debit, saft_total_credit, created_at
         FROM accounting_saft_submissions
         WHERE accounting_entity_id = ? AND period_year = ? AND period_month = ?
           AND saft_total_debit IS NOT NULL
         ORDER BY (status = 'enviado') DESC, id DESC
         LIMIT 1"
    );
    $stmt->execute([$entityId, $year, $month]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $empty;
    }
    return [
        'found' => true,
        'debit' => round((float) $row['saft_total_debit'], 2),
        'credit' => round((float) ($row['saft_total_credit'] ?? 0), 2),
        'submission_id' => (int) $row['id'],
        'created_at' => (string) $row['created_at'],
    ];
}

/**
 * Limpa espacos e acrescenta "+" ao ultimo termo quando vem sem sinal
 * (ex.: "...+C7919"). Nota: o ctaFormula() do legacy ignorava esse termo.
 */
function normalizeFolderSaftFormula(string $formula): string {
    $formula = preg_replace('/\s+/', '', $formula);
    if (preg_match('/C\d+(cre|deb)?$/i', $formula)) {
        $formula .= '+';
    }
    return $formula;
}

/**
 * Avalia uma formula do balancete (formato C<conta>[cre|deb]<+|->) usando
 * $defaultSide para os termos sem lado, como ctaFormula($f, $bal,
 * "fltCredito"/"fltDebito") no legacy.
 */
function evaluateFolderSaftFormula(string $formula, array $balances, string $defaultSide): float {
    $terms = parseAccountingVatFieldFormula(normalizeFolderSaftFormula($formula));
    foreach ($terms as &$term) {
        if ($term['side'] === null) {
            $term['side'] = $defaultSide;
        }
    }
    unset($term);
    return round(evaluateAccountingVatFieldFormula($terms, $balances), 2);
}

/**
 * Conferencia completa de um mes: totais por diario (ERP vs fita) e
 * totais do SAF-T vs contabilidade.
 */
function buildFolderConfirmationOverview(PDO $pdo, array $entity, int $year, int $month): array {
    $entityId = (int) $entity['id'];
    $database = resolveAccountingEntityDatabase($entity);
    $warnings = [];

    $erpJournals = [];
    $erpLoaded = false;
    if ($database === '') {
        $warnings[] = 'Esta empresa não tem base de dados ERP associada (Entidades > ficha da empresa). Não é possível obter os lançamentos.';
    } else {
        $movements = fetchErpFolderJournalTotals($database, $year, $month);
        if ($movements['success']) {
            $erpLoaded = true;
            $erpJournals = $movements['journals'];
        } else {
            $warnings[] = 'Movimentos indisponíveis: ' . $movements['error'];
        }
    }

    $tape = getFolderTapeValues($pdo, $entityId, $year, $month);

    $journalNames = getFolderConfirmationDefaultJournals();
    foreach (array_keys($erpJournals + $tape) as $code) {
        if (!isset($journalNames[$code])) {
            $journalNames[$code] = ($erpJournals[$code]['name'] ?? '') !== '' ? $erpJournals[$code]['name'] : 'Diário ' . $code;
        }
    }

    $journals = [];
    $divergences = 0;
    foreach ($journalNames as $code => $name) {
        $erpTotal = (float) ($erpJournals[$code]['total'] ?? 0);
        $tapeTotal = 0.0;
        foreach ($tape[$code] ?? [] as $tapeRow) {
            $tapeTotal += $tapeRow['valor'];
        }
        $tapeTotal = round($tapeTotal, 2);
        $ok = abs($erpTotal - $tapeTotal) < 0.005;
        if (!$ok) {
            $divergences++;
        }
        $journals[$code] = [
            'code' => $code,
            'name' => $name,
            'erp_total' => $erpTotal,
            'erp_docs' => count($erpJournals[$code]['docs'] ?? []),
            'tape_total' => $tapeTotal,
            'tape_count' => count($tape[$code] ?? []),
            'diff' => round($tapeTotal - $erpTotal, 2),
            'ok' => $ok,
        ];
    }

    // SAF-T vs contabilidade (Total Debitos / Total Creditos).
    $saft = fetchFolderSaftTotals($pdo, $entityId, $year, $month);
    $creditFormula = trim((string) getSetting('confirmacao_pasta_saft_credit_formula', ''));
    $debitFormula = trim((string) getSetting('confirmacao_pasta_saft_debit_formula', ''));
    $saftCompare = null;
    if ($creditFormula === '' && $debitFormula === '') {
        $warnings[] = 'As fórmulas de conferência do SAF-T com a contabilidade ainda não estão configuradas (botão de configurações da tarefa).';
    } elseif ($database !== '') {
        $balancesResult = fetchErpVatAccountBalances($database, $year, $month, $month);
        if ($balancesResult['success']) {
            try {
                $ctbDebit = $debitFormula !== '' ? evaluateFolderSaftFormula($debitFormula, $balancesResult['balances'], 'deb') : 0.0;
                $ctbCredit = $creditFormula !== '' ? evaluateFolderSaftFormula($creditFormula, $balancesResult['balances'], 'cre') : 0.0;
                $saftCompare = [
                    'debit' => ['saft' => $saft['debit'], 'ctb' => $ctbDebit, 'diff' => round($saft['debit'] - $ctbDebit, 2)],
                    'credit' => ['saft' => $saft['credit'], 'ctb' => $ctbCredit, 'diff' => round($saft['credit'] - $ctbCredit, 2)],
                ];
                foreach ($saftCompare as $side) {
                    if (abs($side['diff']) >= 0.005) {
                        $divergences++;
                    }
                }
            } catch (InvalidArgumentException $e) {
                $warnings[] = 'Fórmula do SAF-T inválida: ' . $e->getMessage();
            }
        } else {
            $warnings[] = 'Balancete indisponível: ' . $balancesResult['error'];
        }
    }

    return [
        'database' => $database,
        'erp_loaded' => $erpLoaded,
        'erp_journals' => $erpJournals,
        'tape' => $tape,
        'journals' => $journals,
        'saft' => $saft,
        'saft_compare' => $saftCompare,
        'divergences' => $divergences,
        'warnings' => $warnings,
    ];
}
