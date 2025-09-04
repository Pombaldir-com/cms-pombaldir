<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Zxing\QrReader;
use Smalot\PdfParser\Parser;
use thiagoalessio\TesseractOCR\TesseractOCR;

/**
 * Convert a PDF into images and scan each page for QR codes.
 *
 * @param string $pdfPath Path to the PDF file.
 * @return string|null Decoded QR text or null if none found.
 */
function extractQrStringFromPdf(string $pdfPath): ?string {
    $outputBase = sys_get_temp_dir() . '/' . uniqid('pdfqr_');
    $cmd = 'pdftoppm -png ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($outputBase);
    exec($cmd);
    $images = glob($outputBase . '-*.png');
    foreach ($images as $img) {
        $qrcode = new QrReader($img);
        $decoded = $qrcode->text();
        if ($decoded !== '') {
            foreach ($images as $file) { @unlink($file); }
            return $decoded;
        }
    }
    foreach ($images as $file) { @unlink($file); }
    return null;
}

/**
 * Extract relevant fields from an invoice PDF.
 *
 * @param string $pdfPath Path to the PDF.
 * @return array Parsed fields including text, nif, iban, etc.
 */
function extractInvoiceFields(string $pdfPath): array {
    $parser = new Parser();
    try {
        $pdf = $parser->parseFile($pdfPath);
        $text = $pdf->getText();
    } catch (Throwable $e) {
        $text = '';
    }

    $minChars = 100;
    $minWords = 10;
    $wordList = array_filter(preg_split('/\s+/', strip_tags($text)));
    $wordCount = count($wordList);

    if (strlen($text) < $minChars || $wordCount < $minWords) {
        $tempImageBase = sys_get_temp_dir() . '/' . uniqid('page1_');
        $cmd = 'pdftoppm -f 1 -singlefile -png ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($tempImageBase);
        exec($cmd);
        $tempImage = $tempImageBase . '.png';

        $ocr = new TesseractOCR($tempImage);
        $ocr->lang('por');
        $text = $ocr->run();
        @unlink($tempImage);
        $source = 'OCR via Tesseract';
    } else {
        $source = 'Texto nativo do PDF';
    }

    $foundNIF = null;
    if (preg_match('/\b(?:NIF|NIPC|Contribuinte)[\s\:\-]*([1-9]\d{2}[\s]?\d{3}[\s]?\d{3})\b/i', $text, $nifMatch)) {
        $foundNIF = str_replace(' ', '', $nifMatch[1]);
    } elseif (preg_match('/\b([1-9]\d{2}[\s]?\d{3}[\s]?\d{3})\b/', $text, $nifMatch)) {
        $foundNIF = str_replace(' ', '', $nifMatch[1]);
    }

    preg_match_all('/(?<![A-Z0-9])([A-Z]{2}\d{2}(?:\s?[A-Z0-9]){11,30})(?![A-Z0-9])/i', $text, $ibanMatches);
    $foundIBANs = [];
    if (!empty($ibanMatches[1])) {
        foreach ($ibanMatches[1] as $ibanRaw) {
            $ibanClean = strtoupper(preg_replace('/\s+/', '', $ibanRaw));
            if (strlen($ibanClean) >= 15 && strlen($ibanClean) <= 34) {
                $foundIBANs[] = $ibanClean;
            }
        }
    }

    $foundInvoice = null;
    if (preg_match('/\b(?:FATURA|FACTURA)[\s\:\-]*(?:N[ºo]{1,2})?[\s\:\-]*([A-Z]{1,5}[\s\-\/]?\d{1,6}(?:\/\d{1,6})?)\b/i', $text, $invoiceMatch)) {
        $foundInvoice = strtoupper(preg_replace('/\s+/', '', $invoiceMatch[1]));
    }

    $foundATCUD = null;
    if (preg_match('/\bATCUD[\s\:\-]*([A-Z0-9]{3,}-\d{4,})\b/i', $text, $atcudMatch)) {
        $foundATCUD = strtoupper($atcudMatch[1]);
    }

    $lines = explode("\n", $text);
    $foundName = null;
    foreach ($lines as $line) {
        if (preg_match('/^[A-Z\s&\.\-]{8,}$/', trim($line)) && !preg_match('/\d/', $line)) {
            $foundName = trim($line);
            break;
        }
    }

    return [
        'text' => $text,
        'nif' => $foundNIF,
        'nome_emissor' => $foundName,
        'iban' => $foundIBANs,
        'docnum' => $foundInvoice,
        'atcud' => $foundATCUD,
        'linhas' => $lines,
        'origem' => $source,
    ];
}

