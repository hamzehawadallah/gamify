<?php
/**
 * Image uploads for poll options. Images are checked, rotated upright, scaled
 * down and re-encoded (WebP when available) so they load fast on phones.
 */
declare(strict_types=1);

const GM_UPLOAD_MAX_BYTES = 12 * 1024 * 1024;
const GM_UPLOAD_MAX_SIDE = 1600;

function uploads_dir(): string
{
    return GM_ROOT . '/uploads';
}

function upload_name_valid(string $name): bool
{
    return (bool) preg_match('/^[a-f0-9]{24}\.(webp|jpg|png|gif)$/', $name);
}

function upload_exists(string $name): bool
{
    return upload_name_valid($name) && is_file(uploads_dir() . '/' . $name);
}

/** Store an uploaded image and return its file name. */
function upload_store_image(array $file): string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new UserError('That image is too large. Please use one under 12 MB.', 413, 'too_large');
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new UserError('The image didn’t upload. Please try again.', 400, 'upload_failed');
    }
    if ((int) $file['size'] > GM_UPLOAD_MAX_BYTES) {
        throw new UserError('That image is too large. Please use one under 12 MB.', 413, 'too_large');
    }

    $size = @getimagesize($file['tmp_name']);
    $mime = class_exists('finfo')
        ? (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name'])
        : ($size['mime'] ?? '');
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($types[$mime]) || $size === false) {
        throw new UserError('Please choose a JPG, PNG, WebP or GIF image.', 415, 'bad_type');
    }

    $dir = uploads_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        throw new RuntimeException('Upload folder is missing and could not be created.');
    }
    $base = random_token(12);

    // GIFs may be animated: keep them as they are.
    if ($mime === 'image/gif' || !extension_loaded('gd')) {
        $name = $base . '.' . $types[$mime];
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
            throw new RuntimeException('Could not save the uploaded image.');
        }
        return $name;
    }

    if (function_exists('ini_set')) {
        @ini_set('memory_limit', '384M');
    }
    $img = upload_load_gd($file['tmp_name'], $mime);
    if (!$img) {
        throw new UserError('That image couldn’t be read. Try another file.', 415, 'bad_image');
    }
    if (!imageistruecolor($img)) {
        // Palette images (optimised PNGs) can't be written as WebP; convert first.
        imagepalettetotruecolor($img);
        imagealphablending($img, false);
        imagesavealpha($img, true);
    }
    if ($mime === 'image/jpeg') {
        $img = upload_fix_orientation($img, $file['tmp_name']);
    }

    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1, GM_UPLOAD_MAX_SIDE / max($w, $h));
    if ($scale < 1) {
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $resized = imagecreatetruecolor($nw, $nh);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $img = $resized;
    }

    if (function_exists('imagewebp')) {
        imagesavealpha($img, true);
        $name = $base . '.webp';
        $ok = imagewebp($img, $dir . '/' . $name, 84);
    } elseif ($mime === 'image/png') {
        imagesavealpha($img, true);
        $name = $base . '.png';
        $ok = imagepng($img, $dir . '/' . $name, 7);
    } else {
        $name = $base . '.jpg';
        $ok = imagejpeg($img, $dir . '/' . $name, 85);
    }
    imagedestroy($img);
    clearstatcache(true, $dir . '/' . $name);
    if (!$ok || !is_file($dir . '/' . $name) || filesize($dir . '/' . $name) === 0) {
        @unlink($dir . '/' . $name);
        throw new UserError('That image couldn’t be processed. Try saving it as a JPG first.', 415, 'bad_image');
    }
    return $name;
}

function upload_load_gd(string $path, string $mime)
{
    switch ($mime) {
        case 'image/jpeg':
            return @imagecreatefromjpeg($path);
        case 'image/png':
            $img = @imagecreatefrompng($path);
            if ($img) {
                imagealphablending($img, false);
                imagesavealpha($img, true);
            }
            return $img;
        case 'image/webp':
            return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false;
    }
    return false;
}

/** Phone photos are often stored sideways with an EXIF hint; rotate them upright. */
function upload_fix_orientation($img, string $path)
{
    if (!function_exists('exif_read_data')) {
        return $img;
    }
    $exif = @exif_read_data($path);
    $orientation = (int) ($exif['Orientation'] ?? 1);
    $angles = [3 => 180, 6 => -90, 8 => 90];
    if (isset($angles[$orientation])) {
        $rotated = imagerotate($img, $angles[$orientation], 0);
        if ($rotated) {
            imagedestroy($img);
            return $rotated;
        }
    }
    return $img;
}

/** Delete image files that no option uses any more (duplicates share images). */
function upload_delete_unused(array $names): void
{
    foreach (array_unique(array_filter($names)) as $name) {
        if (!upload_name_valid($name)) {
            continue;
        }
        if (!q_val('SELECT 1 FROM gm_options WHERE image = ? LIMIT 1', [$name])) {
            @unlink(uploads_dir() . '/' . $name);
        }
    }
}
