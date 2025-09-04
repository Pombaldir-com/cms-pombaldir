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

    $images = [];

    // Try converting pages using pdftoppm. Fall back to Imagick if pdftoppm is
    // unavailable or fails (e.g. exec disabled).
    $cmd = 'pdftoppm -png -r 300 ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($prefix);

    @exec($cmd, $output, $status);

    if ($status === 0) {
        $page = 1;
        while (file_exists($imagePath = sprintf('%s-%d.png', $prefix, $page))) {
            $images[] = $imagePath;
            $page++;
        }

    } elseif (class_exists('Imagick')) {

        try {
            $imagick = new Imagick();
            $imagick->setResolution(300, 300);
            $imagick->readImage($pdfPath);
            foreach ($imagick as $i => $page) {
                $imagePath = sprintf('%s-%d.png', $prefix, $i + 1);

                $page->setImageFormat('png');
                $page->writeImage($imagePath);

                $images[] = $imagePath;
            }
            $imagick->clear();
            $imagick->destroy();
        } catch (Throwable $e) {
            // If conversion fails, no images will be scanned.
        }
    }

    $text = null;

    foreach ($images as $imagePath) {
        try {
            $qrcode = new QrReader($imagePath);
            $decoded = $qrcode->text();
            if ($decoded) {
                $text = $decoded;
                break;
            }
        } catch (Throwable $e) {
            // Ignore errors for individual pages.
        } finally {

            @unlink($imagePath);
        }

    }

    return $text;
}
