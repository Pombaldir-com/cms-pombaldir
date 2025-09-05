<?php ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);
ini_set('memory_limit','4096M');  ini_set('max_execution_time', 120); set_time_limit(120);

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
                '%s -png -r %d %s %s/page',
                escapeshellcmd($pdftoppm),
                150,
                escapeshellarg($pdfPath),
                escapeshellarg($tmpDir)
            );
            exec($cmd . ' 2>&1', $output, $returnVar);
            if ($returnVar !== 0) {
                throw new RuntimeException('pdftoppm conversion failed: ' . implode("\n", $output));
            }
            $images = glob($tmpDir . '/page*.png');
            if (!$images) {
                throw new RuntimeException('pdftoppm did not produce any PNG files.');
            }
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



function detectarQr_safe(string $arquivo, float $scaleInicial = 1.0, bool $debug = false, int $maxTentativas = 12): ?string
{
    // 1) tentativa direta
    $dados = null;
    try {
        $qr = new QrReader($arquivo);
        $dados = $qr->text();
    } catch (Throwable $e) {
        // ignora se o QrReader não conseguir abrir direto
    }

    if (!empty($dados)) {
        return $dados; // sucesso logo aqui
    }

    // 2) se $dados vazio, então chama a tua função robusta
    try {
        return detectarQr($arquivo, $scaleInicial, $debug, $maxTentativas);
    } catch (Throwable $e) {
        // log opcional e retorna null se a detecção falhar
        error_log('detectarQr failed: ' . $e->getMessage());
        return null;
    }
}



/**
 * Attempt to detect and decode a QR code from an image or PDF file using
 * multiple image processing strategies and transformations.
 *
 * The function reads the input file (image or PDF), applies a series of
 * preprocessing steps, and uses the QrReader to attempt to decode a QR code.
 * It tries different strategies, scales, and rotations to maximize the
 * chances of successful detection. If debug mode is enabled, intermediate
 * images are saved in a `qr_debug` directory next to the input file.
 *
 * @param string $arquivo Absolute filesystem path to the image or PDF file.
 * @param float $scaleInicial Initial scaling factor to apply to the image.
 *                            Values >1.0 upscale, <1.0 downscale. Default is 1.0.
 * @param bool $debug If true, saves intermediate processing steps in a
 *                    `qr_debug` directory for inspection. Default is false.
 * @param int $maxTentativas Maximum number of processing attempts before
 *                            giving up. Default is 12.
 * @return string|null Decoded QR code text or null if not found.
 */

function detectarQr(
    string $arquivo,
    float $scaleInicial = 1.0,
    bool $debug = false,
    int $maxTentativas = 12
) {

    putenv('TMPDIR='.dirname($arquivo) . '/tmp_' . uniqid().'');
    // pasta de debug opcional
    $dirDebug = dirname($arquivo) . '/qr_debug';
    if ($debug && !is_dir($dirDebug)) {
        @mkdir($dirDebug, 0775, true);
    }

    // helper para guardar ficheiro temporário
    $saveTmp = function(Imagick $im, string $suf, int $idx) use ($arquivo, $dirDebug, $debug) {
        $tmp = dirname($arquivo) . '/qr_tmp_' . $idx . '_' . $suf . '.png';
        $im->setImageFormat('png');
        $im->writeImage($tmp);
        if ($debug) {
            // cópia para pasta de debug com nome legível
            @copy($tmp, $dirDebug . '/step_' . sprintf('%02d', $idx) . '_' . $suf . '.png');
        }
        return $tmp;
    };

    // lê a imagem (se for PDF/TIFF, considera aumentar density antes)
    $base = new Imagick();
    $ext = strtolower(pathinfo($arquivo, PATHINFO_EXTENSION));
    if (in_array($ext, ['pdf', 'tif', 'tiff'])) {
        // Aumenta a resolução ao ler PDFs/TIFFs
        $base->setResolution(300, 300);
    }
    $base->readImage($arquivo);
    if ($base->getNumberImages() > 1) {
        // usa a primeira página/frame
        $base->setIteratorIndex(0);
    }
    $base->setImageUnits(Imagick::RESOLUTION_PIXELSPERINCH);

    // Pré-processamento base comum
    $prepBase = function(Imagick $img) {
        // remover alpha e fundo transparente → branco
        $img->setImageBackgroundColor('white');
        $img->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);

        // converter para escala de cinzentos
        $img->setImageColorspace(Imagick::COLORSPACE_GRAY);

        // normalizar contraste/níveis automáticos
        $img->autoLevelImage();
        if (method_exists($img, 'autoContrastImage')) {
            $img->autoContrastImage(true);
        } else {
            // fallback
            $img->normalizeImage(); 
            // ou:
            // $img->contrastStretchImage(0.0, 65535.0);
        }

        // leve aumento de contraste sigmoidal
        $img->sigmoidalContrastImage(true, 7, 0.5);

        return $img;
    };

    // Garante tamanho mínimo do QR na imagem (upscale se muito pequena)
    $img0 = clone $base;
    $img0 = $prepBase($img0);

    $w = $img0->getImageWidth();
    $h = $img0->getImageHeight();

    // upscale inicial se imagem for pequena
    $minSide = min($w, $h);
    $scaleBoost = 1.0;
    if ($minSide < 900) { // heurística: 900px no menor lado costuma ajudar
        $scaleBoost = 900 / max(1, $minSide);
    }
    $escalaInicialEfetiva = max($scaleInicial, $scaleBoost);

    $resizeTo = function(Imagick $im, float $scale) {
        $nw = (int) max(1, round($im->getImageWidth()  * $scale));
        $nh = (int) max(1, round($im->getImageHeight() * $scale));
        $im->resizeImage($nw, $nh, Imagick::FILTER_LANCZOS, 1.0);
        return $im;
    };

    // Estratégias de processamento (cada uma será testada com várias rotações)
    $strategies = [
        // nome => função(Imagick):Imagick
        'clean' => function(Imagick $im) { return $im; },

        'unsharp' => function(Imagick $im) {
            // ligeira nitidez
            $im->unsharpMaskImage(1.5, 1.0, 1.0, 0.02);
            return $im;
        },

        'adaptive_threshold_soft' => function(Imagick $im) {
            // binarização adaptativa suave
            $im->adaptiveThresholdImage(15, 15, 5);
            return $im;
        },

        'adaptive_threshold_strong' => function(Imagick $im) {
            // binarização adaptativa mais agressiva
            $im->adaptiveThresholdImage(25, 25, 10);
            return $im;
        },

        'despeckle_unsharp' => function(Imagick $im) {
            $im->despeckleImage();
            $im->unsharpMaskImage(2.0, 1.0, 1.5, 0.02);
            return $im;
        },

        'morph_open' => function(Imagick $im) {
            // abre pequenas manchas (útil para recuperar módulos do QR)
            $kernel = \ImagickKernel::fromBuiltIn(\Imagick::KERNEL_SQUARE, "3");
            $im->morphology(\Imagick::MORPHOLOGY_OPEN, 1, $kernel);
            return $im;
        },
    ];

    // rotações a testar (graus)
    $angles = [0, 5];

    // variações de escala a testar
    $scales = [];
    $s = $escalaInicialEfetiva;
    // escadas multiplicativas: 1x, 1.2x, 1.5x, 2x, 2.5x (cap a 3.5x)
    foreach ([1.0, 1.5] as $m) {
        $val = min(3.5, $s * $m);
        $scales[] = $val;
    }
    // remover duplicados aproximados
    $scales = array_values(array_unique(array_map(function($v){ return round($v, 2); }, $scales)));

    $tentativa = 0;

    foreach ($scales as $scale) {
        foreach ($strategies as $name => $fn) {
            if ($tentativa >= $maxTentativas) break 2;

            // recria imagem base a cada tentativa
            $img = clone $img0;
            $img = $resizeTo($img, $scale);
            $img = $fn($img);

            foreach ($angles as $ang) {
                if ($tentativa >= $maxTentativas) break 3;

                $try = clone $img;
                if ($ang !== 0) {
                    $try->rotateImage(new ImagickPixel('white'), $ang);
                }

                // bordas brancas finas ajudam alguns leitores
                $try->borderImage('white', 10, 10);

                $tmp = $saveTmp($try, $name . '_s' . str_replace('.', '_', (string)$scale) . '_a' . $ang, $tentativa);

                // usa QrReader no ficheiro
                $qr = new QrReader($tmp);
                $dados = $qr->text();

                @unlink($tmp);
                $try->clear();
                $try->destroy();

                if (!empty($dados)) {
                    // libertar tudo antes de retornar
                    $img->clear(); $img->destroy();
                    $img0->clear(); $img0->destroy();
                    $base->clear(); $base->destroy();
                    return $dados;
                }

                $tentativa++;
                if ($debug) {
                    error_log("QR tentativa $tentativa falhou ($name, scale=$scale, angle=$ang)");
                }
            }

            $img->clear(); $img->destroy();
        }
    }

    // limpeza final
    $img0->clear(); $img0->destroy();
    $base->clear(); $base->destroy();

    return null;
}


function extractQR(string $string): array
{
    $parts = explode('*', $string);
    $arr = [];

    foreach ($parts as $v) {
        $del = explode(':', $v, 2); // limita a 2 pedaços no máximo
        if (count($del) === 2) {
            $key = trim($del[0]);
            $val = trim($del[1]);
            $arr[$key] = $val;
        }
    }

    return $arr;
}
