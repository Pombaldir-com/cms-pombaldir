<?php
// Helpers para a submissao de ficheiros SAF-T a AT via cliente de linha de
// comandos FACTEMICLI, e para a extracao dos valores do proprio ficheiro
// SAF-T (faturas e totais) para cruzamento posterior com a contabilidade e
// lancamentos. Documentacao de referencia em ENVIO_SAFT.md.

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
 *     gross_total: ?string}>
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

    $salesInvoices = $xml->SourceDocuments->SalesInvoices ?? null;
    if ($salesInvoices === null) {
        return $result;
    }

    $numberOfEntries = saftXmlValue($salesInvoices->NumberOfEntries ?? null);
    $result['number_of_entries'] = $numberOfEntries !== null ? (int) $numberOfEntries : null;
    $result['total_debit'] = saftXmlValue($salesInvoices->TotalDebit ?? null);
    $result['total_credit'] = saftXmlValue($salesInvoices->TotalCredit ?? null);

    foreach ($salesInvoices->Invoice as $invoice) {
        $result['invoices'][] = [
            'invoice_no' => (string) ($invoice->InvoiceNo ?? ''),
            'atcud' => saftXmlValue($invoice->ATCUD ?? null),
            'invoice_type' => saftXmlValue($invoice->InvoiceType ?? null),
            'invoice_status' => saftXmlValue($invoice->DocumentStatus->InvoiceStatus ?? null),
            'invoice_date' => saftXmlValue($invoice->InvoiceDate ?? null),
            'system_entry_date' => saftXmlValue($invoice->SystemEntryDate ?? null),
            'customer_id' => saftXmlValue($invoice->CustomerID ?? null),
            'source_id' => saftXmlValue($invoice->SourceID ?? null),
            'tax_payable' => saftXmlValue($invoice->DocumentTotals->TaxPayable ?? null),
            'net_total' => saftXmlValue($invoice->DocumentTotals->NetTotal ?? null),
            'gross_total' => saftXmlValue($invoice->DocumentTotals->GrossTotal ?? null),
        ];
    }

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
 * Guarda o cabecalho/totais extraidos na submissao e as faturas em
 * accounting_saft_invoices. Nao lanca excecao: erros ficam registados em
 * saft_extraction_error para nao bloquear o fluxo de envio.
 */
function saftPersistExtractedData(PDO $pdo, int $submissionId, string $filePath): void {
    try {
        $xmlContent = saftReadFileContent($filePath);
        $data = saftExtractInvoiceData($xmlContent);
    } catch (Throwable $e) {
        $pdo->prepare('UPDATE accounting_saft_submissions SET saft_extraction_error = ? WHERE id = ?')
            ->execute([$e->getMessage(), $submissionId]);
        return;
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
        return;
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
