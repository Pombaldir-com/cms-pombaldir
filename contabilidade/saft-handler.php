<?php
require_once __DIR__ . '/../functions.php';

startSession();
header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['error' => 'Sessão inválida']);
    exit;
}

$newToken = generateCsrfToken();

// Validate CSRF token before processing the upload
$token = $_POST['csrf_token'] ?? '';
if (!validateCsrfToken($token)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Token CSRF inválido',
        'csrf_token' => $newToken,
    ]);
    exit;
}

if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Ficheiro não enviado',
        'csrf_token' => $newToken,
    ]);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($_FILES['file']['tmp_name']);
if ($mime !== 'text/xml' && $mime !== 'application/xml') {
    http_response_code(400);
    echo json_encode([
        'error' => 'Apenas ficheiros XML são permitidos',
        'csrf_token' => $newToken,
    ]);
    exit;
}

$slug = getCompanySlug();
if (!$slug) {
    http_response_code(500);
    $fileName = $_FILES['file']['name'] ?? 'desconhecido';
    echo json_encode([
        'error' => 'Empresa não selecionada para o ficheiro ' . $fileName,
        'csrf_token' => $newToken,
    ]);
    exit;
}

$year = date('Y');
$month = date('m');
$uploadDir = dirname(__DIR__) . '/uploads/' . $slug . '/saft/' . $year . '/' . $month . '/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        http_response_code(500);
        $fileName = $_FILES['file']['name'] ?? 'desconhecido';
        echo json_encode([
            'error' => 'Erro ao criar diretório de upload para o ficheiro ' . $fileName,
            'csrf_token' => $newToken,
        ]);
        exit;
    }
}

$filename = bin2hex(random_bytes(16)) . '.xml';
$targetPath = $uploadDir . $filename;
if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
    http_response_code(500);
    $fileName = $_FILES['file']['name'] ?? 'desconhecido';
    echo json_encode([
        'error' => 'Erro ao guardar o ficheiro ' . $fileName,
        'csrf_token' => $newToken,
    ]);
    exit;
}

$transactions = [];
$foreignSales = [];
try {
    $xml = simplexml_load_file($targetPath);
    if ($xml !== false) {
        if (isset($xml->SourceDocuments->Payments->Payment)) {
            foreach ($xml->SourceDocuments->Payments->Payment as $payment) {
                $date = (string) $payment->TransactionDate;
                $desc = (string) ($payment->PaymentRefNo ?? '');
                $amount = (string) ($payment->DocumentTotals->GrossTotal ?? '');
                $transactions[] = [
                    'date' => $date,
                    'description' => $desc,
                    'amount' => $amount,
                ];
            }
        }

        // Vendas intracomunitárias e/ou para países terceiros: cliente com país
        // de faturação diferente de Portugal.
        $customerCountries = [];
        if (isset($xml->MasterFiles->Customer)) {
            foreach ($xml->MasterFiles->Customer as $customer) {
                $customerId = (string) $customer->CustomerID;
                foreach ($customer->BillingAddress as $billingAddress) {
                    $customerCountries[$customerId] = (string) $billingAddress->Country;
                }
            }
        }

        if (isset($xml->SourceDocuments->SalesInvoices->Invoice)) {
            foreach ($xml->SourceDocuments->SalesInvoices->Invoice as $invoice) {
                $customerId = (string) $invoice->CustomerID;
                $country = $customerCountries[$customerId] ?? '';
                $invoiceStatus = (string) ($invoice->DocumentStatus->InvoiceStatus ?? '');

                if ($country !== '' && $country !== 'PT' && $country !== 'Desconhecido' && $invoiceStatus === 'N') {
                    $foreignSales[$customerId]['country'] = $country;
                    $foreignSales[$customerId]['invoices'][] = (string) $invoice->InvoiceNo;
                }
            }
        }
        ksort($foreignSales);
    }
} catch (Throwable $e) {
    // ignore parse errors
}

$relativePath = 'uploads/' . $slug . '/saft/' . $year . '/' . $month . '/' . $filename;

echo json_encode([
    'success' => true,
    'file' => $relativePath,
    'transactions' => $transactions,
    'foreignSales' => $foreignSales,
    'csrf_token' => $newToken,
]);

