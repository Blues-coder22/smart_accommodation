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

    return $filename;
}
?>
