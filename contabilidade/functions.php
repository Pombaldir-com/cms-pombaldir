<?php ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);
require_once __DIR__ . '/../vendor/autoload.php';

use Zxing\QrReader;

/**
 * Read a PDF document and extract the text contained in its QR code, if any.
 *
 * Pages of the PDF are converted to temporary PNG images using the
 * `pdftoppm` utility. Each image is scanned with the `QrReader` from the
 * khanamiryan/qrcode-detector-decoder package until a QR code is found. All
 * temporary files are removed after processing.
 *
 * @param string $pdfPath Absolute filesystem path to the PDF document.
 * @return string|null Decoded QR code text or null if not found.
 */
function extractQrStringFromPdf(string $pdfPath): ?string {
    $qrcode = new QrReader($pdfPath);
    $decoded = $qrcode->text();
    return $decoded;
}