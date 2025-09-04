<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Zxing\QrReader;

/**
 * Extract text from a QR code contained in an image.
 *
 * @param string $imagePath Absolute path to the image file.
 * @return string|null Decoded QR code text or null if none was found.
 */
function extractQrStringFromPdf(string $imagePath): ?string {
    $qrcode = new QrReader($imagePath);
    $decoded = $qrcode->text();
    return $decoded !== '' ? $decoded : null;
}

