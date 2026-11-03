<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

/**
 * Résout un chemin sans autoriser de sortie du répertoire photo.
 */
function photo_resolve(string $relative): ?string
{
    $relative = str_replace('\\', '/', $relative);

    if (
        str_contains($relative, "\0")
        || str_starts_with($relative, '/')
    ) {
        return null;
    }

    $root = realpath(PHOTO_ROOT);

    if ($root === false) {
        return null;
    }

    $candidate = realpath(
        $root . '/' . $relative
    );

    if ($candidate === false) {
        return null;
    }

    if (
        $candidate !== $root
        && !str_starts_with(
            $candidate,
            $root . DIRECTORY_SEPARATOR
        )
    ) {
        return null;
    }

    if (!is_file($candidate) && !is_dir($candidate)) {
        return null;
    }

    return $candidate;
}

/**
 * Vérifie si un fichier est une image.
 */
function photo_is_image(string $file): bool
{
    $extension = strtolower(
        pathinfo($file, PATHINFO_EXTENSION)
    );

    return in_array(
        $extension,
        PHOTO_IMAGE_EXTENSIONS,
        true
    );
}

/**
 * Vérifie si un fichier est une vidéo.
 */
function photo_is_video(string $file): bool
{
    $extension = strtolower(
        pathinfo($file, PATHINFO_EXTENSION)
    );

    return in_array(
        $extension,
        PHOTO_VIDEO_EXTENSIONS,
        true
    );
}

/**
 * Retourne le contenu d'un dossier.
 */
function photo_list(string $relative): array
{
    $directory = photo_resolve($relative);

    if ($directory === null || !is_dir($directory)) {
        return [];
    }

    $items = [];

    foreach (scandir($directory) ?: [] as $name) {

        if ($name === '.' || $name === '..') {
            continue;
        }

        $childRelative = ltrim(
            $relative . '/' . $name,
            '/'
        );

        $path = photo_resolve($childRelative);

        if ($path === null) {
            continue;
        }

        if (is_dir($path)) {

            $items[] = [
                'name' => $name,
                'path' => $childRelative,
                'type' => 'directory',
            ];

        } elseif (
            is_file($path)
            && (
                photo_is_image($name)
                || photo_is_video($name)
            )
        ) {

            $items[] = [
                'name' => $name,
                'path' => $childRelative,
                'type' => photo_is_video($name)
                    ? 'video'
                    : 'image',
                'size' => filesize($path),
            ];
        }
    }

    usort($items, static function ($a, $b) {

        if ($a['type'] === 'directory'
            && $b['type'] !== 'directory') {
            return -1;
        }

        if ($b['type'] === 'directory'
            && $a['type'] !== 'directory') {
            return 1;
        }

        return strnatcasecmp(
            $a['name'],
            $b['name']
        );
    });

    return $items;
}

/**
 * Génération d'une miniature JPEG.
 */
function photo_thumbnail(string $source): ?string
{
    if (!extension_loaded('gd')) {
        return null;
    }

    $info = @getimagesize($source);

    if ($info === false) {
        return null;
    }

    $mime = $info['mime'] ?? '';

    $loaders = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png'  => 'imagecreatefrompng',
        'image/gif'  => 'imagecreatefromgif',
        'image/webp' => 'imagecreatefromwebp',
    ];

    if (!isset($loaders[$mime])) {
        return null;
    }

    if (!function_exists($loaders[$mime])) {
        return null;
    }

    $image = @$loaders[$mime]($source);

    if (!$image) {
        return null;
    }

    $width = imagesx($image);
    $height = imagesy($image);

    if ($width <= 0 || $height <= 0) {
        imagedestroy($image);
        return null;
    }

    $max = PHOTO_MAX_THUMBNAIL_SIZE;

    $ratio = min(
        $max / $width,
        $max / $height,
        1
    );

    $newWidth = max(1, (int) ($width * $ratio));
    $newHeight = max(1, (int) ($height * $ratio));

    $thumbnail = imagecreatetruecolor(
        $newWidth,
        $newHeight
    );

    $background = imagecolorallocate(
        $thumbnail,
        255,
        255,
        255
    );

    imagefill(
        $thumbnail,
        0,
        0,
        $background
    );

    imagecopyresampled(
        $thumbnail,
        $image,
        0,
        0,
        0,
        0,
        $newWidth,
        $newHeight,
        $width,
        $height
    );

    if (!is_dir(PHOTO_CACHE)) {
        mkdir(PHOTO_CACHE, 0750, true);
    }

    $cacheFile = PHOTO_CACHE . '/'
        . hash('sha256', $source . ':' . filemtime($source))
        . '.jpg';

    $success = imagejpeg(
        $thumbnail,
        $cacheFile,
        85
    );

    imagedestroy($image);
    imagedestroy($thumbnail);

    return $success ? $cacheFile : null;
}
