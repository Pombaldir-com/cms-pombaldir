<?php
// Helpers para a submissao de ficheiros SAF-T a AT via cliente de linha de
// comandos FACTEMICLI, e para a extracao dos valores do proprio ficheiro
// SAF-T (faturas e totais) para cruzamento posterior com a contabilidade e
// lancamentos. Documentacao de referencia em ENVIO_SAFT.md.

/**
 * Motivos possiveis para marcar manualmente uma empresa/periodo como
 * "tratado" sem enviar aqui o ficheiro SAF-T (ver saftMarkPeriodAsManual()).
 */
function saftManualReasonLabels(): array {
    return [
        'cliente' => 'Enviado pelo cliente',
        'sem_vendas' => 'Sem vendas no período',
    ];
}

/**
 * Marca uma empresa/periodo como "tratado" sem ficheiro (ex.: o proprio
 * cliente ja enviou o SAF-T diretamente, ou nao houve vendas no periodo),
 * para deixar de aparecer como pendente na tarefa de envio. Cria um registo
 * "leve" em accounting_saft_submissions (sem ficheiro/faturas associadas);
 * repetir a marcacao com o mesmo motivo/periodo atualiza o registo existente
 * em vez de duplicar.
 *
 * @return array{id: int, feedback: array{type:string,message:string}}
 */
function saftMarkPeriodAsManual(PDO $pdo, int $entityId, int $periodYear, int $periodMonth, ?int $userId, string $reason): array {
    $labels = saftManualReasonLabels();
    if (!isset($labels[$reason])) {
        return ['id' => 0, 'feedback' => ['type' => 'danger', 'message' => 'Motivo inválido.']];
    }
    $label = $labels[$reason];

    $stmt = $pdo->prepare(
        'INSERT INTO accounting_saft_submissions
            (accounting_entity_id, user_id, period_year, period_month, original_filename, file_path, file_size, status, is_manual_entry, manual_reason)
         VALUES (?, ?, ?, ?, ?, \'\', 0, \'dispensado\', 1, ?)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), user_id = VALUES(user_id), status = \'dispensado\',
            is_manual_entry = 1, manual_reason = VALUES(manual_reason), created_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$entityId, $userId, $periodYear, $periodMonth, $label, $reason]);

    $submissionId = (int) $pdo->lastInsertId();
    if ($submissionId === 0) {
        // ON DUPLICATE KEY UPDATE nao devolve o id inserido originalmente.
        $lookup = $pdo->prepare(
            'SELECT id FROM accounting_saft_submissions
             WHERE accounting_entity_id = ? AND period_year = ? AND period_month = ? AND original_filename = ? LIMIT 1'
        );
        $lookup->execute([$entityId, $periodYear, $periodMonth, $label]);
        $submissionId = (int) ($lookup->fetchColumn() ?: 0);
    }

    logAuditAction('create', 'accounting_saft_submission', $submissionId, [
        'accounting_entity_id' => $entityId,
        'period' => $periodYear . '-' . $periodMonth,
        'manual_reason' => $reason,
    ]);

    return ['id' => $submissionId, 'feedback' => ['type' => 'success', 'message' => 'Período marcado como "' . $label . '".']];
}

/**
 * Normaliza a estrutura de $_FILES de um input com "multiple" (ex.:
 * saft_file[]) numa lista de arrays no formato de ficheiro unico esperado
 * por saftHandleUpload() (['name','type','tmp_name','error','size']).
 * Tambem aceita a estrutura de um input sem "multiple" (um unico ficheiro),
 * devolvendo-o como lista de um elemento. Slots vazios (nenhum ficheiro
 * selecionado nessa posicao) sao ignorados.
 *
 * @return array<int, array{name:string,type:string,tmp_name:string,error:int,size:int}>
 */
function saftNormalizeMultiUpload(array $files): array {
    if (!isset($files['name'])) {
        return [];
    }
    if (!is_array($files['name'])) {
        return $files['error'] === UPLOAD_ERR_NO_FILE ? [] : [$files];
    }
    $result = [];
    foreach ($files['name'] as $i => $name) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE && $name === '') {
            continue;
        }
        $result[] = [
            'name' => $name,
            'type' => $files['type'][$i] ?? '',
            'tmp_name' => $files['tmp_name'][$i] ?? '',
            'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$i] ?? 0,
        ];
    }
    return $result;
}

/**
 * Le o conteudo XML de um ficheiro SAF-T, descomprimindo .zip/.gz se
 * necessario. Ficheiros .zip sao assumidos como contendo um unico XML.
 *
 * $extensionHint permite indicar a extensao original quando $filePath e um
 * ficheiro temporario sem extensao (ex.: $_FILES[...]['tmp_name']).
 */
function saftReadFileContent(string $filePath, ?string $extensionHint = null): string {
    $extension = strtolower($extensionHint ?? pathinfo($filePath, PATHINFO_EXTENSION));

    if ($extension === 'gz') {
        $handle = gzopen($filePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Nao foi possivel abrir o ficheiro .gz.');
        }
        $content = '';
        while (!gzeof($handle)) {
            $content .= gzread($handle, 1048576);
        }
        gzclose($handle);
        return $content;
    }

    if ($extension === 'zip') {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new RuntimeException('Nao foi possivel abrir o ficheiro .zip.');
        }
        $content = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (strtolower(pathinfo((string) $name, PATHINFO_EXTENSION)) === 'xml') {
                $content = (string) $zip->getFromIndex($i);
                break;
            }
        }
        $zip->close();
        if ($content === '') {
            throw new RuntimeException('O ficheiro .zip nao contem um XML.');
        }
        return $content;
    }

    $content = @file_get_contents($filePath);
    if ($content === false) {
        throw new RuntimeException('Nao foi possivel ler o ficheiro SAF-T.');
    }
    return $content;
}

/**
 * Extrai do XML SAF-T (faturacao) o cabecalho, os totais de SalesInvoices e
 * a lista de faturas, para permitir cruzar mais tarde com a contabilidade e
 * os lancamentos importados do ERP.
 *
 * @return array{
 *   fiscal_year: ?string, start_date: ?string, end_date: ?string,
 *   tax_registration_number: ?string, company_name: ?string,
 *   number_of_entries: ?int, total_debit: ?string, total_credit: ?string,
 *   invoices: array<int, array{invoice_no: string, atcud: ?string, invoice_type: ?string,
 *     invoice_status: ?string, invoice_date: ?string, system_entry_date: ?string,
 *     customer_id: ?string, source_id: ?string, tax_payable: ?string, net_total: ?string,
 *     gross_total: ?string}>,
 *   foreign_sales: array<string, array{country: string, tax_id: string, value: float,
 *     invoices: array<int, string>}>
 * }
 */
function saftExtractInvoiceData(string $xmlContent): array {
    $result = [
        'fiscal_year' => null,
        'start_date' => null,
        'end_date' => null,
        'tax_registration_number' => null,
        'company_name' => null,
        'number_of_entries' => null,
        'total_debit' => null,
        'total_credit' => null,
        'invoices' => [],
        'foreign_sales' => [],
    ];

    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlContent);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if ($xml === false) {
        throw new RuntimeException('Ficheiro SAF-T invalido ou nao e XML.');
    }

    $header = $xml->Header ?? null;
    if ($header !== null) {
        $result['fiscal_year'] = saftXmlValue($header->FiscalYear ?? null);
        $result['start_date'] = saftXmlValue($header->StartDate ?? null);
        $result['end_date'] = saftXmlValue($header->EndDate ?? null);
        $result['tax_registration_number'] = saftXmlValue($header->TaxRegistrationNumber ?? null);
        $result['company_name'] = saftXmlValue($header->CompanyName ?? null);
    }

    // Pais de faturacao e NIF de cada cliente, para deteccao de vendas
    // intracomunitarias e/ou para paises terceiros e pre-preenchimento da
    // Declaracao Recapitulativa de IVA.
    $customerCountries = [];
    $customerTaxIds = [];
    if (isset($xml->MasterFiles->Customer)) {
        foreach ($xml->MasterFiles->Customer as $customer) {
            $customerId = (string) $customer->CustomerID;
            foreach ($customer->BillingAddress as $billingAddress) {
                $customerCountries[$customerId] = (string) $billingAddress->Country;
            }
            $taxId = saftXmlValue($customer->CustomerTaxID ?? null);
            if ($taxId !== null) {
                $customerTaxIds[$customerId] = $taxId;
            }
        }
    }

    $salesInvoices = $xml->SourceDocuments->SalesInvoices ?? null;
    if ($salesInvoices === null) {
        return $result;
    }

    $numberOfEntries = saftXmlValue($salesInvoices->NumberOfEntries ?? null);
    $result['number_of_entries'] = $numberOfEntries !== null ? (int) $numberOfEntries : null;
    $result['total_debit'] = saftXmlValue($salesInvoices->TotalDebit ?? null);
    $result['total_credit'] = saftXmlValue($salesInvoices->TotalCredit ?? null);

    foreach ($salesInvoices->Invoice as $invoice) {
        $invoiceNo = (string) ($invoice->InvoiceNo ?? '');
        $customerId = saftXmlValue($invoice->CustomerID ?? null);
        $invoiceStatus = saftXmlValue($invoice->DocumentStatus->InvoiceStatus ?? null);

        $result['invoices'][] = [
            'invoice_no' => $invoiceNo,
            'atcud' => saftXmlValue($invoice->ATCUD ?? null),
            'invoice_type' => saftXmlValue($invoice->InvoiceType ?? null),
            'invoice_status' => $invoiceStatus,
            'invoice_date' => saftXmlValue($invoice->InvoiceDate ?? null),
            'system_entry_date' => saftXmlValue($invoice->SystemEntryDate ?? null),
            'customer_id' => $customerId,
            'source_id' => saftXmlValue($invoice->SourceID ?? null),
            'tax_payable' => saftXmlValue($invoice->DocumentTotals->TaxPayable ?? null),
            'net_total' => saftXmlValue($invoice->DocumentTotals->NetTotal ?? null),
            'gross_total' => saftXmlValue($invoice->DocumentTotals->GrossTotal ?? null),
        ];

        $country = $customerId !== null ? ($customerCountries[$customerId] ?? '') : '';
        if ($customerId !== null && $country !== '' && $country !== 'PT' && $country !== 'Desconhecido' && $invoiceStatus === 'N') {
            $result['foreign_sales'][$customerId]['country'] = $country;
            $result['foreign_sales'][$customerId]['tax_id'] = $customerTaxIds[$customerId] ?? '';
            $result['foreign_sales'][$customerId]['invoices'][] = $invoiceNo;
            $result['foreign_sales'][$customerId]['value'] =
                ($result['foreign_sales'][$customerId]['value'] ?? 0) + (float) ($invoice->DocumentTotals->NetTotal ?? 0);
        }
    }

    ksort($result['foreign_sales']);

    return $result;
}

function saftXmlValue($node): ?string {
    if ($node === null) {
        return null;
    }
    $value = trim((string) $node);
    return $value !== '' ? $value : null;
}

/**
 * Deriva o ano/mes do periodo a partir do cabecalho do ficheiro SAF-T
 * (StartDate, com fallback para EndDate ou FiscalYear quando a data nao da
 * para calcular o mes). Devolve null se nao for possivel determinar.
 *
 * @return array{year: int, month: int}|null
 */
function saftDerivePeriod(array $headerData): ?array {
    foreach ([$headerData['start_date'] ?? null, $headerData['end_date'] ?? null] as $rawDate) {
        if ($rawDate === null) {
            continue;
        }
        $timestamp = strtotime((string) $rawDate);
        if ($timestamp === false) {
            continue;
        }
        return ['year' => (int) date('Y', $timestamp), 'month' => (int) date('n', $timestamp)];
    }

    return null;
}

/**
 * Guarda o cabecalho/totais extraidos na submissao e as faturas em
 * accounting_saft_invoices. Nao lanca excecao: erros ficam registados em
 * saft_extraction_error para nao bloquear o fluxo de envio. Devolve os dados
 * extraidos (incluindo foreign_sales) para uso no feedback do upload, ou
 * null se a extracao falhar.
 */
function saftPersistExtractedData(PDO $pdo, int $submissionId, string $filePath): ?array {
    try {
        $xmlContent = saftReadFileContent($filePath);
        $data = saftExtractInvoiceData($xmlContent);
    } catch (Throwable $e) {
        $pdo->prepare('UPDATE accounting_saft_submissions SET saft_extraction_error = ? WHERE id = ?')
            ->execute([$e->getMessage(), $submissionId]);
        return null;
    }

    $pdo->prepare(
        'UPDATE accounting_saft_submissions SET
            saft_fiscal_year = ?, saft_start_date = ?, saft_end_date = ?,
            saft_number_of_entries = ?, saft_total_debit = ?, saft_total_credit = ?,
            saft_extraction_error = NULL
         WHERE id = ?'
    )->execute([
        $data['fiscal_year'],
        $data['start_date'],
        $data['end_date'],
        $data['number_of_entries'],
        $data['total_debit'],
        $data['total_credit'],
        $submissionId,
    ]);

    if (!$data['invoices']) {
        return $data;
    }

    $insertStmt = $pdo->prepare(
        'INSERT INTO accounting_saft_invoices
            (submission_id, invoice_no, atcud, invoice_type, invoice_status, invoice_date,
             system_entry_date, customer_id, source_id, tax_payable, net_total, gross_total)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($data['invoices'] as $invoice) {
        $invoiceDate = null;
        if (!empty($invoice['invoice_date'])) {
            $timestamp = strtotime((string) $invoice['invoice_date']);
            $invoiceDate = $timestamp !== false ? date('Y-m-d', $timestamp) : null;
        }
        $insertStmt->execute([
            $submissionId,
            $invoice['invoice_no'],
            $invoice['atcud'],
            $invoice['invoice_type'],
            $invoice['invoice_status'],
            $invoiceDate,
            $invoice['system_entry_date'],
            $invoice['customer_id'],
            $invoice['source_id'],
            $invoice['tax_payable'],
            $invoice['net_total'],
            $invoice['gross_total'],
        ]);
    }

    return $data;
}

/**
 * Resolve a empresa (entidade adquirente) correspondente ao NIF indicado no
 * cabecalho do ficheiro SAF-T (Header/TaxRegistrationNumber). Devolve null
 * se o NIF for invalido ou nao corresponder a nenhuma empresa registada.
 */
function saftResolveEntityByNif(PDO $pdo, string $rawNif): ?array {
    $nif = extractVatNumber($rawNif);
    if ($nif === '') {
        return null;
    }
    // Comparacao em PHP (nao em SQL) porque accounting_entities.nif pode ter
    // formatacao variavel (espacos, prefixo "PT", etc.).
    $stmt = $pdo->query("SELECT id, nif, name FROM accounting_entities WHERE entity_type = 'acquirer'");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (extractVatNumber((string) $row['nif']) === $nif) {
            return $row;
        }
    }
    return null;
}

/**
 * Devolve a credencial ativa do portal AT para uma empresa, a partir da
 * tabela partilhada com o modulo e-fatura, ou null se nao existir.
 */
function saftGetEntityPortalCredential(PDO $pdo, int $entityId): ?array {
    if ($entityId <= 0 || !hasTable('efatura_company_credentials')) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT id, portal_username, portal_password_encrypted
         FROM efatura_company_credentials
         WHERE entity_id = ? AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$entityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Descifra um segredo guardado pelo modulo e-fatura (AES-256-CBC com chave
 * derivada de EFATURA_SECRET_KEY). Mesma cifra de contabilidade/efatura.php.
 */
function saftDecryptPortalSecret(string $ciphertext): string {
    $key = trim((string) getenv('EFATURA_SECRET_KEY'));
    if ($key === '') {
        throw new RuntimeException('EFATURA_SECRET_KEY nao configurada.');
    }
    $binary = base64_decode($ciphertext, true);
    if ($binary === false || strlen($binary) <= 16) {
        throw new RuntimeException('Credencial cifrada invalida.');
    }
    $iv = substr($binary, 0, 16);
    $payload = substr($binary, 16);
    $plaintext = openssl_decrypt($payload, 'AES-256-CBC', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
    if ($plaintext === false) {
        throw new RuntimeException('Nao foi possivel descifrar a credencial.');
    }
    return $plaintext;
}

/**
 * Faz parse da resposta XML da AT (ver ENVIO_SAFT.md, "Estrutura de resposta").
 *
 * @return array{code: string, total_faturas: ?string, total_creditos: ?string,
 *               total_debitos: ?string, warning: ?string, id_ficheiro: ?string,
 *               nome_ficheiro: ?string, created_date: ?string, errors: string[]}|null
 *         null quando o conteudo nao contem um elemento <response>.
 */
function saftParseAtResponse(string $xmlContent): ?array {
    $xmlContent = trim($xmlContent);
    if ($xmlContent === '') {
        return null;
    }
    // O cliente pode escrever texto informativo antes/depois do XML; isolar o
    // bloco <response>...</response>.
    if (preg_match('/<response\b[^>]*>.*<\/response>/s', $xmlContent, $match)) {
        $xmlContent = $match[0];
    }

    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlContent);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if ($xml === false || $xml->getName() !== 'response') {
        return null;
    }

    $errors = [];
    if (isset($xml->errors)) {
        foreach ($xml->errors->error as $error) {
            $message = trim((string) $error);
            if ($message !== '') {
                $errors[] = $message;
            }
        }
    }

    $readOptional = static function ($node): ?string {
        if ($node === null) {
            return null;
        }
        $value = trim((string) $node);
        return $value !== '' ? $value : null;
    };

    return [
        'code' => trim((string) ($xml['code'] ?? '')),
        'total_faturas' => $readOptional($xml->totalFaturas ?? null),
        'total_creditos' => $readOptional($xml->totalCreditos ?? null),
        'total_debitos' => $readOptional($xml->totalDebitos ?? null),
        'warning' => $readOptional($xml->warning ?? null),
        'id_ficheiro' => $readOptional($xml->idFicheiro ?? null),
        'nome_ficheiro' => $readOptional($xml->nomeFicheiro ?? null),
        'created_date' => $readOptional($xml->createdDate ?? null),
        'errors' => $errors,
    ];
}

/**
 * Executa o jar FACTEMICLI para submeter um ficheiro SAF-T.
 *
 * Responde ao prompt interativo de inconsistencias nos totais via stdin
 * ("s" para forcar o envio, "n" para abortar).
 *
 * @return array{parsed: ?array, raw: string, stdout: string, exit_code: int}
 */
function saftRunFactemicli(
    string $portalUsername,
    string $portalPassword,
    int $year,
    int $month,
    string $inputFile,
    bool $forceOnAnomalies = false,
    int $timeoutSeconds = 300
): array {
    $jarPath = trim((string) getSetting('saft_jar_path', ''));
    if ($jarPath === '' || !is_file($jarPath)) {
        throw new RuntimeException('Jar FACTEMICLI nao configurado ou nao encontrado (Definicoes > Servicos).');
    }
    $javaBin = trim((string) getSetting('saft_java_bin', ''));
    if ($javaBin === '') {
        $javaBin = 'java';
    }
    if (!is_file($inputFile)) {
        throw new RuntimeException('Ficheiro SAF-T nao encontrado: ' . $inputFile);
    }

    $outputFile = tempnam(sys_get_temp_dir(), 'saft_at_');
    if ($outputFile === false) {
        throw new RuntimeException('Nao foi possivel criar o ficheiro temporario de resposta.');
    }

    $command = implode(' ', [
        escapeshellcmd($javaBin),
        '-jar', escapeshellarg($jarPath),
        '-n', escapeshellarg($portalUsername),
        '-p', escapeshellarg($portalPassword),
        '-a', escapeshellarg((string) $year),
        '-m', escapeshellarg(str_pad((string) $month, 2, '0', STR_PAD_LEFT)),
        '-op', 'enviar',
        '-i', escapeshellarg($inputFile),
        '-o', escapeshellarg($outputFile),
    ]);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        @unlink($outputFile);
        throw new RuntimeException('Falha ao iniciar o processo Java.');
    }

    // Resposta ao prompt "Deseja continuar ... (s/n)"; se nao houver prompt o
    // stdin extra e ignorado pelo cliente.
    fwrite($pipes[0], ($forceOnAnomalies ? 's' : 'n') . "\n");
    fclose($pipes[0]);

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $deadline = time() + max(30, $timeoutSeconds);
    while (true) {
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            $exitCode = (int) $status['exitcode'];
            break;
        }
        if (time() > $deadline) {
            proc_terminate($process, 9);
            $exitCode = -1;
            $stderr .= "\n[timeout] Processo terminado ao fim de {$timeoutSeconds}s.";
            break;
        }
        usleep(200000);
    }
    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $raw = (string) @file_get_contents($outputFile);
    @unlink($outputFile);

    // Alguns cenarios (ex.: erro de autenticacao) escrevem a resposta apenas
    // no stdout; usar o que contiver um bloco <response>.
    $combined = $raw !== '' ? $raw : $stdout;
    $parsed = saftParseAtResponse($combined);
    if ($parsed === null && $raw !== '' && ($fallback = saftParseAtResponse($stdout)) !== null) {
        $parsed = $fallback;
        $combined = $stdout;
    }

    return [
        'parsed' => $parsed,
        'raw' => trim($combined . ($stderr !== '' ? "\n[stderr]\n" . trim($stderr) : '')),
        'stdout' => $stdout,
        'exit_code' => $exitCode,
    ];
}

/**
 * Processa por completo o upload de um ficheiro SAF-T: valida o formato,
 * extrai o NIF/periodo do cabecalho, resolve a empresa atraves de
 * $resolveEntity, grava o ficheiro (substituindo o envio anterior do mesmo
 * periodo), extrai faturas/totais para cruzamento com a contabilidade e
 * tenta a submissao a AT (respeitando o modo teste e a credencial do portal).
 *
 * Usado tanto pela area administrativa (contabilidade/tarefas-envio-saft.php)
 * como pela extranet do cliente (client/saft.php); a unica diferenca entre
 * os dois contextos e como a empresa e validada, pelo que essa logica fica a
 * cargo do callback $resolveEntity.
 *
 * $resolveEntity recebe o NIF do ficheiro (string) e devolve
 * ['entity' => array{id,nif,name}|null, 'error' => ?string]. Quando 'error'
 * vem preenchido, o upload e abortado com essa mensagem; quando 'entity' e
 * null sem 'error', usa-se uma mensagem generica de "empresa nao encontrada".
 *
 * @return array{feedback: array{type:string,message:string}, submission_id: ?int}
 */
function saftHandleUpload(PDO $pdo, array $uploadedFile, callable $resolveEntity, ?int $userId): array {
    if (($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['feedback' => ['type' => 'danger', 'message' => 'Selecione um ficheiro SAF-T válido.'], 'submission_id' => null];
    }

    $originalName = (string) ($uploadedFile['name'] ?? '');
    $tmpName = (string) ($uploadedFile['tmp_name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, ['xml', 'zip', 'gz'], true)) {
        return ['feedback' => ['type' => 'danger', 'message' => 'Formato inválido. Apenas ficheiros .xml, .zip ou .gz.'], 'submission_id' => null];
    }

    try {
        $xmlContent = saftReadFileContent($tmpName, $extension);
        $headerData = saftExtractInvoiceData($xmlContent);
    } catch (Throwable $e) {
        return ['feedback' => ['type' => 'danger', 'message' => 'Não foi possível ler o ficheiro SAF-T: ' . $e->getMessage()], 'submission_id' => null];
    }

    $fileNif = (string) ($headerData['tax_registration_number'] ?? '');
    if ($fileNif === '') {
        return ['feedback' => ['type' => 'danger', 'message' => 'Não foi possível identificar o NIF da empresa no cabeçalho do ficheiro SAF-T.'], 'submission_id' => null];
    }

    $derivedPeriod = saftDerivePeriod($headerData);
    if ($derivedPeriod === null) {
        return ['feedback' => ['type' => 'danger', 'message' => 'Não foi possível determinar o período (ano/mês) a partir do cabeçalho do ficheiro SAF-T.'], 'submission_id' => null];
    }

    $resolved = $resolveEntity($fileNif);
    if (!empty($resolved['error'])) {
        return ['feedback' => ['type' => 'danger', 'message' => (string) $resolved['error']], 'submission_id' => null];
    }
    $matchedEntity = $resolved['entity'] ?? null;
    if (!$matchedEntity) {
        return ['feedback' => ['type' => 'danger', 'message' => 'Não existe nenhuma empresa registada com o NIF ' . htmlspecialchars($fileNif) . ' (indicado no ficheiro SAF-T).'], 'submission_id' => null];
    }

    $entityId = (int) $matchedEntity['id'];
    $periodYear = $derivedPeriod['year'];
    $periodMonth = $derivedPeriod['month'];

    $slug = getCompanySlug();
    $dir = dirname(__DIR__) . '/uploads/' . $slug . '/saft-envios/' . $entityId . '/' . $periodYear . '/' . str_pad((string) $periodMonth, 2, '0', STR_PAD_LEFT) . '/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return ['feedback' => ['type' => 'danger', 'message' => 'Erro ao criar diretório de destino.'], 'submission_id' => null];
    }

    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $originalName) ?: ('saft.' . $extension);
    $storedName = date('YmdHis') . '_' . $safeName;
    $fullPath = $dir . $storedName;
    if (!move_uploaded_file($tmpName, $fullPath)) {
        return ['feedback' => ['type' => 'danger', 'message' => 'Falha ao gravar o ficheiro.'], 'submission_id' => null];
    }

    // Podem existir varios ficheiros distintos para a mesma empresa/periodo
    // (ex.: exportacoes separadas). So se substitui o envio anterior quando
    // e o MESMO ficheiro (igual nome original) para o mesmo ano/mes —
    // tratado como atualizacao desse ficheiro (registo e faturas extraidas
    // via cascade sao apagados e recriados).
    $previousStmt = $pdo->prepare(
        'SELECT id, file_path FROM accounting_saft_submissions
         WHERE accounting_entity_id = ? AND period_year = ? AND period_month = ? AND original_filename = ?
         LIMIT 1'
    );
    $previousStmt->execute([$entityId, $periodYear, $periodMonth, $originalName]);
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
        (int) ($uploadedFile['size'] ?? 0),
    ]);
    $submissionId = (int) $pdo->lastInsertId();
    logAuditAction('create', 'accounting_saft_submission', $submissionId, [
        'accounting_entity_id' => $entityId,
        'period' => $periodYear . '-' . $periodMonth,
    ]);

    // Extrai faturas e totais do proprio ficheiro SAF-T para cruzamento
    // posterior com a contabilidade e lancamentos. Nao bloqueia o envio se
    // falhar.
    $extracted = saftPersistExtractedData($pdo, $submissionId, $fullPath);
    $foreignSales = $extracted['foreign_sales'] ?? [];

    // Submissao a AT via FACTEMICLI, quando o jar esta configurado e a
    // empresa tem credencial do portal. Continua sempre o envio mesmo com
    // inconsistencias nos totais (responde "s" ao aviso interativo da AT).
    $testMode = getSetting('saft_test_mode', '0') === '1';
    $jarConfigured = trim((string) getSetting('saft_jar_path', '')) !== '';
    $credential = saftGetEntityPortalCredential($pdo, $entityId);
    $feedback = null;

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
                true
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

    if ($previousSubmission) {
        $feedback['message'] = 'Substituído o envio anterior deste período. ' . $feedback['message'];
    }
    $feedback['message'] = 'Empresa identificada: ' . $matchedEntity['name'] . ' (NIF ' . $matchedEntity['nif'] . '). ' . $feedback['message'];

    return [
        'feedback' => $feedback,
        'submission_id' => $submissionId,
        'foreign_sales' => $foreignSales,
        'entity' => $matchedEntity,
        'period_year' => $periodYear,
        'period_month' => $periodMonth,
    ];
}

/**
 * Constroi o XML da Declaracao Recapitulativa de IVA (schema DRIVAWeb da AT,
 * http://www.at.gov.pt/2019/DRIVAWeb/schema), a partir das vendas
 * intracomunitarias/pais terceiro detetadas num SAF-T e confirmadas/editadas
 * pelo utilizador.
 *
 * A estrutura e a numeracao dos campos seguem o modelo oficial "Declaracao
 * Recapitulativa" (Portal das Financas) e um XML exportado real:
 *  - quadro01/f1: NIF do sujeito passivo (a propria empresa, nao o cliente).
 *  - quadro02/f1: tipo de declaracao (1 = 1a declaracao do periodo).
 *  - quadro03/f1,f2: ano e mes do periodo.
 *  - quadro0405/table/tableItem: uma linha por adquirente+tipo de operacao
 *    (f2 prefixo pais, f3 NIF do adquirente, f4 valor em centimos, f5 tipo:
 *    1 = transmissao de bens, 4 = operacao triangular, 5 = prestacao de
 *    servicos); f10/f17/f18 = somas por tipo (1/4/5), f19 = soma total.
 *  - quadro06/f1: NIF do contabilista certificado (quando aplicavel).
 *  - quadro07/table: transferencias de bens a consignacao (nao suportado
 *    por este formulario; enviado sempre vazio).
 *
 * @param array{
 *   nif: string, year: int, month: int, accountant_nif?: string,
 *   rows: array<int, array{country: string, nif: string, value: float, type: string}>
 * } $params
 */
function saftBuildDeclaracaoRecapitulativaXml(array $params): string {
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;

    $ns = 'http://www.at.gov.pt/2019/DRIVAWeb/schema';
    $dr = $dom->createElementNS($ns, 'dr');
    $dr->setAttribute('version', '1.0');
    $dom->appendChild($dr);
    $rosto = $dom->createElement('rosto');
    $dr->appendChild($rosto);

    $addField = function (DOMElement $parent, string $tag, string $value) use ($dom): DOMElement {
        $el = $dom->createElement($tag, htmlspecialchars($value, ENT_XML1));
        $parent->appendChild($el);
        return $el;
    };

    $quadro01 = $dom->createElement('quadro01');
    $rosto->appendChild($quadro01);
    $addField($quadro01, 'f1', extractVatNumber((string) $params['nif']));

    $quadro02 = $dom->createElement('quadro02');
    $rosto->appendChild($quadro02);
    $addField($quadro02, 'f1', '1');

    $quadro03 = $dom->createElement('quadro03');
    $rosto->appendChild($quadro03);
    $addField($quadro03, 'f1', (string) (int) $params['year']);
    $addField($quadro03, 'f2', str_pad((string) (int) $params['month'], 2, '0', STR_PAD_LEFT));

    $quadro0405 = $dom->createElement('quadro0405');
    $rosto->appendChild($quadro0405);

    $sumByType = ['1' => 0, '4' => 0, '5' => 0];
    $table = $dom->createElement('table');
    foreach ($params['rows'] as $row) {
        $type = (string) $row['type'];
        if (!isset($sumByType[$type])) {
            continue;
        }
        $valueCents = (int) round(((float) $row['value']) * 100);
        $sumByType[$type] += $valueCents;

        $item = $dom->createElement('tableItem');
        $addField($item, 'f2', strtoupper((string) $row['country']));
        $addField($item, 'f3', (string) $row['nif']);
        $addField($item, 'f4', (string) $valueCents);
        $addField($item, 'f5', $type);
        $table->appendChild($item);
    }

    $addField($quadro0405, 'f10', (string) $sumByType['1']);
    $addField($quadro0405, 'f17', (string) $sumByType['4']);
    $addField($quadro0405, 'f18', (string) $sumByType['5']);
    $addField($quadro0405, 'f19', (string) ($sumByType['1'] + $sumByType['4'] + $sumByType['5']));
    $quadro0405->appendChild($table);

    $quadro06 = $dom->createElement('quadro06');
    $rosto->appendChild($quadro06);
    $addField($quadro06, 'f1', extractVatNumber((string) ($params['accountant_nif'] ?? '')));

    $quadro07 = $dom->createElement('quadro07');
    $rosto->appendChild($quadro07);
    $quadro07->appendChild($dom->createElement('table'));

    return (string) $dom->saveXML();
}
