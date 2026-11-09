<?php
// Icones da app instalavel (PWA), favicon e notificacoes push, por empresa
// (tenant). Fonte, por esta ordem (Definicoes > Geral):
//   app_icon  "Icone (favicon e app)": imagem quadrada propria, usada a toda
//             a area (so o maskable do Android leva margem de seguranca);
//   app_logo  o logotipo, sem as margens vazias, centrado com margem;
//   nenhum    os icones genericos de assets/images/.
//
// Rota "app-icon/{slug}/{variante}.png" (router.php). O slug vai no URL porque
// os browsers pedem os icones do manifest sem cookies. O PNG gerado fica em
// cache em uploads/{slug}/branding/pwa/ e muda de nome quando a fonte muda (?v=).
// Ver docs/calendario.md, "Notificacoes push".

const APP_ICON_RENDER_VERSION = 3;
// Ícone proprio carregado nas Definicoes: limites do upload.
const APP_ICON_UPLOAD_MAX_BYTES = 2 * 1024 * 1024;
const APP_ICON_UPLOAD_MIN_SIDE = 192;
const APP_ICON_UPLOAD_MAX_SIDE = 1024;
// variante => [tamanho, margem com logotipo, margem com icone proprio, icone generico]
const APP_ICON_VARIANTS = [
    '192' => [192, 0.10, 0.0, 'app-icon-192.png'],
    '512' => [512, 0.10, 0.0, 'app-icon-512.png'],
    // Android recorta os icones "maskable" num circulo: zona segura de 80%.
    'maskable-512' => [512, 0.20, 0.10, 'app-icon-maskable-512.png'],
    // iOS precisa de um icone opaco; arredonda os cantos sozinho.
    'apple-180' => [180, 0.12, 0.0, 'apple-touch-icon.png'],
    'favicon-32' => [32, 0.04, 0.0, 'favicon-32.png'],
];

/**
 * Fonte do icone da empresa da sessao: o icone proprio (app_icon) ou o
 * logotipo (app_logo). {kind: icon|logo, path, file, version} ou null.
 */
function appIconLogo(): ?array {
    foreach (['icon' => 'app_icon', 'logo' => 'app_logo'] as $kind => $setting) {
        try {
            $path = trim((string) getSetting($setting, ''));
        } catch (Throwable $e) {
            return null;
        }
        if ($path === '' || strpos($path, '..') !== false) {
            continue;
        }
        $file = __DIR__ . '/' . ltrim($path, '/');
        if (!is_file($file)) {
            continue;
        }
        // APP_ICON_RENDER_VERSION muda quando o desenho do icone muda (regenera a cache).
        $version = substr(md5($kind . '|' . $path . '|' . (string) @filemtime($file) . '|' . APP_ICON_RENDER_VERSION), 0, 10);
        return ['kind' => $kind, 'path' => $path, 'file' => $file, 'version' => $version];
    }
    return null;
}

/** A empresa da sessao tem um icone proprio (app_icon) carregado nas Definicoes. */
function appIconHasCustom(): bool {
    return (appIconLogo()['kind'] ?? '') === 'icon';
}

/**
 * Grava o icone proprio carregado nas Definicoes ($_FILES['app_icon']) em
 * uploads/{slug}/branding/AAAA/MM/ e devolve o caminho relativo para a
 * setting app_icon. PNG ou JPG, quadrado (ou quase), pelo menos 192 px;
 * reduzido a 1024 px. Lanca InvalidArgumentException com a mensagem para o
 * utilizador.
 */
function appIconSaveUpload(array $upload, string $slug): string {
    if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || empty($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
        throw new InvalidArgumentException('Falha no envio do ícone.');
    }
    if ((int) ($upload['size'] ?? 0) > APP_ICON_UPLOAD_MAX_BYTES) {
        throw new InvalidArgumentException('O ícone excede 2 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $source = match ($mime) {
        'image/png' => @imagecreatefrompng($upload['tmp_name']),
        'image/jpeg' => @imagecreatefromjpeg($upload['tmp_name']),
        default => false,
    };
    if ($source === false) {
        throw new InvalidArgumentException('Formato de ícone inválido: use PNG ou JPG.');
    }
    $w = imagesx($source);
    $h = imagesy($source);
    if (min($w, $h) < APP_ICON_UPLOAD_MIN_SIDE) {
        throw new InvalidArgumentException('O ícone tem de ter pelo menos ' . APP_ICON_UPLOAD_MIN_SIDE . '×' . APP_ICON_UPLOAD_MIN_SIDE . ' px (recomendado 512×512).');
    }
    if (abs($w - $h) > max($w, $h) * 0.05) {
        throw new InvalidArgumentException('O ícone tem de ser quadrado (recebido ' . $w . '×' . $h . ' px; recomendado 512×512).');
    }
    if (!imageistruecolor($source)) {
        imagepalettetotruecolor($source);
    }
    $side = min(APP_ICON_UPLOAD_MAX_SIDE, max($w, $h));
    $icon = imagecreatetruecolor($side, $side);
    imagealphablending($icon, false);
    imagesavealpha($icon, true);
    imagefill($icon, 0, 0, imagecolorallocatealpha($icon, 0, 0, 0, 127));
    imagecopyresampled($icon, $source, 0, 0, 0, 0, $side, $side, $w, $h);

    $relDir = 'uploads/' . $slug . '/branding/' . date('Y') . '/' . date('m');
    $dir = __DIR__ . '/' . $relDir;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new InvalidArgumentException('Não foi possível criar a pasta do ícone.');
    }
    $name = 'icon_' . bin2hex(random_bytes(12)) . '.png';
    if (!imagepng($icon, $dir . '/' . $name, 9)) {
        throw new InvalidArgumentException('Erro ao guardar o ícone.');
    }
    return $relDir . '/' . $name;
}

/**
 * URL do icone para a empresa da sessao. Sem logotipo, o icone generico.
 * $absolute para usar fora da pagina (payload das notificacoes push).
 */
function appIconUrl(string $variant, bool $absolute = false): string {
    $base = $absolute ? appAbsoluteBaseUrl() : BASE_URL;
    $generic = $base . 'assets/images/' . (APP_ICON_VARIANTS[$variant][3] ?? 'app-icon-192.png');
    if (!isset(APP_ICON_VARIANTS[$variant]) || !function_exists('imagecreatetruecolor')) {
        return $generic;
    }
    $slug = (string) ($_SESSION['company']['slug'] ?? '');
    $logo = appIconLogo();
    if ($slug === '' || preg_match('/^[A-Za-z0-9_-]+$/', $slug) !== 1 || $logo === null) {
        return $generic;
    }
    return $base . 'app-icon/' . rawurlencode($slug) . '/' . $variant . '.png?v=' . $logo['version'];
}

/** Gera (ou reutiliza da cache) o PNG da variante a partir do icone/logotipo. Devolve o caminho ou null. */
function appIconRender(string $slug, string $variant, array $logo): ?string {
    $ownIcon = ($logo['kind'] ?? 'logo') === 'icon';
    [$size, $logoPadding, $iconPadding] = APP_ICON_VARIANTS[$variant];
    $padding = $ownIcon ? $iconPadding : $logoPadding;
    // iOS e o maskable do Android precisam de um icone opaco.
    $needsOpaque = in_array($variant, ['apple-180', 'maskable-512'], true);
    $dir = __DIR__ . '/uploads/' . $slug . '/branding/pwa';
    $target = $dir . '/' . $variant . '-' . $logo['version'] . '.png';
    if (is_file($target)) {
        return $target;
    }
    $info = @getimagesize($logo['file']);
    $source = match ($info[2] ?? 0) {
        IMAGETYPE_PNG => @imagecreatefrompng($logo['file']),
        IMAGETYPE_JPEG => @imagecreatefromjpeg($logo['file']),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($logo['file']) : false,
        default => false,
    };
    if ($source === false) {
        return null;
    }
    // PNG de paleta: para truecolor com alfa (senao o recorte perde a transparencia).
    if (!imageistruecolor($source)) {
        imagepalettetotruecolor($source);
    }
    imagealphablending($source, false);
    imagesavealpha($source, true);
    // Fundo do icone = cor do canto do logotipo (para um logotipo com fundo
    // proprio nao ficar dentro de um retangulo); transparente => branco.
    $corner = imagecolorsforindex($source, imagecolorat($source, 0, 0));
    $transparent = ($corner['alpha'] ?? 0) > 63;
    $background = $transparent ? [255, 255, 255] : [(int) $corner['red'], (int) $corner['green'], (int) $corner['blue']];
    // Tira as margens vazias do logotipo (transparentes ou da cor dos cantos);
    // um icone proprio usa-se tal como foi desenhado.
    if (!$ownIcon) {
        $cropped = @imagecropauto($source, $transparent ? IMG_CROP_TRANSPARENT : IMG_CROP_SIDES);
        if ($cropped !== false && imagesx($cropped) > 4 && imagesy($cropped) > 4) {
            $source = $cropped;
        }
    }
    $sw = imagesx($source);
    $sh = imagesy($source);
    $box = $size * (1 - 2 * $padding);
    $ratio = min($box / $sw, $box / $sh);
    $dw = max(1, (int) round($sw * $ratio));
    $dh = max(1, (int) round($sh * $ratio));

    $icon = imagecreatetruecolor($size, $size);
    if ($ownIcon && $transparent && !$needsOpaque) {
        // Icone proprio com transparencia: mantem-se (favicon e icones normais).
        imagealphablending($icon, false);
        imagesavealpha($icon, true);
        imagefill($icon, 0, 0, imagecolorallocatealpha($icon, 0, 0, 0, 127));
    } else {
        imagefill($icon, 0, 0, imagecolorallocate($icon, $background[0], $background[1], $background[2]));
    }
    imagealphablending($icon, true);
    imagecopyresampled($icon, $source, (int) (($size - $dw) / 2), (int) (($size - $dh) / 2), 0, 0, $dw, $dh, $sw, $sh);

    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return null;
    }
    // Versoes antigas desta variante (logotipo anterior) deixam de ser usadas.
    foreach (glob($dir . '/' . $variant . '-*.png') ?: [] as $old) {
        @unlink($old);
    }
    return imagepng($icon, $target, 9) ? $target : null;
}

/** Serve o icone (rota app-icon/{slug}/{variante}.png). Corre sem sessao do browser. */
function appIconServe(string $slug, string $variant): void {
    require_once __DIR__ . '/memory-session.php';
    startMemorySession();
    require_once __DIR__ . '/functions.php';
    require_once __DIR__ . '/companies.php';

    if (!isset(APP_ICON_VARIANTS[$variant])) {
        http_response_code(404);
        exit;
    }
    $file = null;
    $company = getCompanyBySlug($slug);
    if (is_array($company) && function_exists('imagecreatetruecolor')) {
        $_SESSION['company'] = $company;
        $logo = appIconLogo();
        if ($logo !== null) {
            try {
                $file = appIconRender((string) $company['slug'], $variant, $logo);
            } catch (Throwable $e) {
                $file = null;
            }
        }
    }
    $file ??= __DIR__ . '/assets/images/' . APP_ICON_VARIANTS[$variant][3];
    header('Content-Type: image/png');
    header('Content-Length: ' . (string) filesize($file));
    // O URL muda com o logotipo (?v=), por isso pode ficar muito tempo em cache.
    header('Cache-Control: public, max-age=2592000');
    readfile($file);
    exit;
}
