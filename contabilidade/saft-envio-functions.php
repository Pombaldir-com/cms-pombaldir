<?php
// Helpers para a submissao de ficheiros SAF-T a AT via cliente de linha de
// comandos FACTEMICLI. Documentacao de referencia em ENVIO_SAFT.md.

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
