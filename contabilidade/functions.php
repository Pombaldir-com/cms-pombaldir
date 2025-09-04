<?php
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
    $tempBase = tempnam(sys_get_temp_dir(), 'qr_');
    if ($tempBase === false) {
        return null;
    }
    $prefix = $tempBase;
    @unlink($tempBase);

    $cmd = 'pdftoppm -png -r 300 ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($prefix);
    exec($cmd, $output, $status);
    if ($status !== 0) {
        return null;
    }

    $page = 1;
    $text = null;
    while (true) {
        $imagePath = sprintf('%s-%d.png', $prefix, $page);
        if (!file_exists($imagePath)) {
            break;
        }
        try {
            $qrcode = new QrReader($imagePath);
            $decoded = $qrcode->text();
            if ($decoded) {
                $text = $decoded;
                @unlink($imagePath);
                break;
            }
        } catch (Throwable $e) {
            // Ignore errors for individual pages
        }

        @unlink($imagePath);
        $page++;
    }

    // Clean up any remaining generated files
    $page++;
    while (file_exists($imagePath = sprintf('%s-%d.png', $prefix, $page))) {
        @unlink($imagePath);
        $page++;
    }

    return $text ?: null;
}
