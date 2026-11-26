<?php
/**
 * Store a validated uploaded image inside the project's uploads directory.
 * Returns the generated filename on success, or false on failure.
 * Allowed MIME types are mapped to extensions rather than trusting client filenames.
 */
function saveUploadedImage(string $temporaryPath, string $mimeType, string $subdirectory, string $prefix, int $maxBytes) {
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($extensions[$mimeType]) || !is_file($temporaryPath)) {
        return false;
    }
    if (filesize($temporaryPath) > $maxBytes) {
        return false;
    }

    $safeDirectory = trim($subdirectory, '/\\');
    if ($safeDirectory === '' || strpos($safeDirectory, '..') !== false) {
        return false;
    }

    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads'
        . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $safeDirectory);
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        return false;
    }

    $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $prefix)
        . '_' . bin2hex(random_bytes(12)) . '.' . $extensions[$mimeType];
    $destination = $directory . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($temporaryPath, $destination)) {
        return false;
    }
    optimizeUploadedImage($destination);   // v9.33: compress and make a thumbnail

    return $filename;
}

if (!function_exists('optimizeUploadedImage')) {
    /**
     * v9.33: shrink an already-validated image in place (longest side max $maxSide, re-encoded) and write a thumbnail
     * "thumb_<name>" next to it. Phones upload 4-8 MB photos; listing cards only need about 480 px. The original is kept
     * untouched if it is already small, if GD is missing, or if re-encoding would make it larger. Never throws.
     * @return bool true when a thumbnail was written
     */
    function optimizeUploadedImage($path, $maxSide = 1600, $quality = 82, $thumbSide = 480) {
        try {
            if (!function_exists('imagecreatetruecolor') || !is_file($path)) return false;
            $info = @getimagesize($path);
            if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) return false;
            [$w, $h] = $info;
            if ($w * $h > 40000000) return false;                                   // refuse absurd dimensions (memory)
            $img = $info[2] === IMAGETYPE_JPEG ? @imagecreatefromjpeg($path) : ($info[2] === IMAGETYPE_PNG ? @imagecreatefrompng($path) : @imagecreatefromwebp($path));
            if (!$img) return false;
            if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {   // honour the camera's rotation
                $ex = @exif_read_data($path);
                $o = (int)($ex['Orientation'] ?? 1);
                $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
                if ($rot) { $r = imagerotate($img, $rot, 0); if ($r) { imagedestroy($img); $img = $r; [$w, $h] = [imagesx($img), imagesy($img)]; } }
            }
            $save = function ($im, $file) use ($info, $quality) {
                imagealphablending($im, false); imagesavealpha($im, true);
                if ($info[2] === IMAGETYPE_JPEG) return imagejpeg($im, $file, $quality);
                if ($info[2] === IMAGETYPE_PNG)  return imagepng($im, $file, 7);
                return imagewebp($im, $file, $quality);
            };
            $scale = function ($im, $side) { $iw = imagesx($im); $ih = imagesy($im); if (max($iw, $ih) <= $side) return $im; $r = $side / max($iw, $ih); return imagescale($im, max(1, (int)round($iw * $r)), max(1, (int)round($ih * $r)), IMG_BICUBIC); };
            // main image: replace only if smaller
            $main = $scale($img, $maxSide);
            if ($main !== $img || (filesize($path) > 600 * 1024)) {
                $tmp = $path . '.opt';
                if ($save($main, $tmp) && is_file($tmp) && filesize($tmp) > 0 && filesize($tmp) < filesize($path)) rename($tmp, $path); else @unlink($tmp);
            }
            $thumb = $scale($img, $thumbSide);
            $ok = $save($thumb, dirname($path) . DIRECTORY_SEPARATOR . 'thumb_' . basename($path));
            if ($main !== $img) imagedestroy($main);
            if ($thumb !== $img && $thumb !== $main) imagedestroy($thumb);
            imagedestroy($img);
            return (bool)$ok;
        } catch (Throwable $e) { error_log('optimizeUploadedImage: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('thumbUrlIfExists')) {
    /** Given a site-relative image URL like ../uploads/accommodations/x.jpg, return the thumbnail URL when it exists on disk. */
    function thumbUrlIfExists($url) {
        if (!preg_match('~^(\.\./)?(uploads/[^?#]*/)([^/?#]+)$~', (string)$url, $m)) return $url;
        $disk = dirname(__DIR__) . '/' . $m[2] . 'thumb_' . rawurldecode($m[3]);
        return is_file($disk) ? $m[1] . $m[2] . 'thumb_' . $m[3] : $url;
    }
}

?>
