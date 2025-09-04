<?php ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);
require_once __DIR__ . '/../vendor/autoload.php';

use Zxing\QrReader;

/**
 * Ensure the environment has the required tools to extract QR codes from PDFs.
 *
 * The extraction requires either the `pdftoppm` binary or the Imagick
 * extension to convert PDFs into images, and one of the `imagick` or `gd`
 * extensions for image processing. Missing requirements are returned as an
 * array of messages so the caller can alert the user.
 *
 * @return string[] List of missing requirements. Empty if all requirements are met.
 */
function checkQrRequirements(): array {
    $missing = [];

    $hasImagick = extension_loaded('imagick');
    $hasGd = extension_loaded('gd');
    if (!$hasImagick && !$hasGd) {
        $missing[] = 'PHP extension imagick or gd';
    }

    $pdftoppm = '';
    if (function_exists('shell_exec')) {
        $pdftoppm = trim((string) shell_exec('which pdftoppm 2>/dev/null'));
    }
    if (!$hasImagick && $pdftoppm === '') {
        $missing[] = 'pdftoppm binary or Imagick extension';
    }

    return $missing;
}

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
    $missing = checkQrRequirements();
    if (!empty($missing)) {
        trigger_error(
            'Missing requirements for QR extraction: ' . implode(', ', $missing),
            E_USER_WARNING
        );
        return null;
    }

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
