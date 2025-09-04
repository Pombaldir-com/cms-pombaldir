<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Zxing\QrReader;

/**
 * Read a PDF document and extract the text contained in its QR code, if any.
 *
 * Pages of the PDF are converted to temporary PNG images using the
 * `pdftoppm` utility. Each image is scanned for a QR code using the
 * `zbarimg` command line tool when available, falling back to the PHP
 * `QrReader` from the khanamiryan/qrcode-detector-decoder package. All
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

    $cmd = 'pdftoppm -png -r 300 ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($prefix);
    exec($cmd, $output, $status);
    if ($status === 0) {
        $page = 1;
        while (file_exists($path = sprintf('%s-%d.png', $prefix, $page))) {
            $images[] = $path;
            $page++;
        }
    }

    if (empty($images)) {
        try {
            $imagick = new Imagick();
            $imagick->setResolution(300, 300);
            $imagick->readImage($pdfPath);
            foreach ($imagick as $index => $img) {
                $img->setImageFormat('png');
                $imgPath = sprintf('%s-%d.png', $prefix, $index + 1);
                $img->writeImage($imgPath);
                $images[] = $imgPath;
            }
            $imagick->clear();
            $imagick->destroy();
        } catch (Throwable $e) {
            foreach ($images as $path) {
                @unlink($path);
            }
            return null;
        }
    }

    $text = null;
    foreach ($images as $imagePath) {
        $decoded = [];
        $status = 1;
        exec('zbarimg --quiet --raw ' . escapeshellarg($imagePath), $decoded, $status);
        if ($status === 0 && !empty($decoded[0])) {
            $text = trim($decoded[0]);
            break;
        }

        try {
            $qrcode = new QrReader($imagePath);
            $decodedText = $qrcode->text();
            if ($decodedText) {
                $text = $decodedText;
                break;
            }
        } catch (Throwable $e) {
            // Ignore errors for individual pages
        }
    }

    foreach ($images as $path) {
        @unlink($path);
    }

    return $text ?: null;
}
