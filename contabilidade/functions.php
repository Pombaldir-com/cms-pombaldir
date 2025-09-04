<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Zxing\QrReader;

/**
 * Read a PDF document and extract the text contained in its QR code, if any.
 *
 * The first page of the PDF is converted to a temporary PNG image using the
 * `pdftoppm` utility. The image is then scanned with the `QrReader` from the
 * khanamiryan/qrcode-detector-decoder package. Temporary files are removed
 * after processing.
 *
 * @param string $pdfPath Absolute filesystem path to the PDF document.
 * @return string|null Decoded QR code text or null if not found.
 */
function extractQrStringFromPdf(string $pdfPath): ?string {
    $tempBase = tempnam(sys_get_temp_dir(), 'qr_');
    if ($tempBase === false) {
        return null;
    }
    $prefix = $tempBase;
    @unlink($tempBase);

    $cmd = 'pdftoppm -png -singlefile ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($prefix);
    exec($cmd, $output, $status);
    $imagePath = $prefix . '.png';
    if ($status !== 0 || !file_exists($imagePath)) {
        @unlink($imagePath);
        return null;
    }

    try {
        $qrcode = new QrReader($imagePath);
        $text = $qrcode->text();
    } catch (Throwable $e) {
        $text = null;
    }

    @unlink($imagePath);
    return $text ?: null;
}
