<?php ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);
require_once __DIR__ . '/../vendor/autoload.php';

use Zxing\QrReader;

/**
 * Read a PDF document and extract the text contained in its QR code, if any.
 *
 * Each page of the PDF is converted to a temporary PNG image using the
 * `pdftoppm` command when available, falling back to the Imagick extension
 * otherwise. Images are generated at a constrained resolution to avoid
 * exhausting resources. Every image is scanned with the `QrReader` from the
 * khanamiryan/qrcode-detector-decoder package until a QR code is found. All
 * temporary files are removed after processing.
 *
 * @param string $pdfPath Absolute filesystem path to the PDF document.
 * @return string|null Decoded QR code text or null if not found.
 * @throws RuntimeException When conversion of the PDF fails or when neither
 *                          `pdftoppm` nor Imagick are available.
 */
function extractQrStringFromPdf(string $pdfPath): ?string
{
    //$tmpDir = sys_get_temp_dir() . '/qr_' . uniqid();
    $tmpDir = dirname($pdfPath) . '/tmp_' . uniqid();
    if (!mkdir($concurrentDirectory = $tmpDir) && !is_dir($concurrentDirectory)) {
        throw new RuntimeException('Unable to create temporary directory.');
    }

    try {
        $images = [];

        // Try using pdftoppm if available
        $pdftoppm = trim(shell_exec('command -v pdftoppm'));
        if ($pdftoppm !== '') {
            $cmd = sprintf(
                '%s -r %d %s %s/page',
                escapeshellcmd($pdftoppm),
                150,
                escapeshellarg($pdfPath),
                escapeshellarg($tmpDir)
            );
            exec($cmd, $output, $returnVar);
            if ($returnVar !== 0) {
                throw new RuntimeException('pdftoppm conversion failed.');
            }
            $images = glob($tmpDir . '/page*.png');
        } else {
            if (!class_exists('Imagick')) {
                throw new RuntimeException('pdftoppm not found and Imagick extension missing.');
            }

            // Limit Imagick resources to avoid exhausting the system
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 32 * 1024 * 1024);
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MAP, 32 * 1024 * 1024);

            $imagick = new \Imagick();
            $imagick->setResolution(150, 150);

            try {
                $imagick->readImage($pdfPath);
            } catch (\Exception $e) {
                throw new RuntimeException('Imagick failed to read PDF: ' . $e->getMessage());
            }

            foreach ($imagick as $index => $page) {
                /** @var \Imagick $page */
                $page->setImageFormat('png');
                $filename = sprintf('%s/page-%d.png', $tmpDir, $index);
                if (!$page->writeImage($filename)) {
                    throw new RuntimeException('Imagick failed to write image: ' . $filename);
                }
                $images[] = $filename;
            }

            $imagick->clear();
            $imagick->destroy();
        }

        foreach ($images as $image) {
            $qr = new QrReader($image);
            $text = $qr->text();
            if (!empty($text)) {
                return $text;
            }
        }

        return null;
    } finally {
        // Cleanup temporary files
        foreach (glob($tmpDir . '/*.png') as $file) {
            @unlink($file);
        }
        @rmdir($tmpDir);
    }
}
