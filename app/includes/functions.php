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
function photo_thumbnail(string $path): ?string
{
    /*
    |--------------------------------------------------------------------------
    | VÉRIFICATIONS
    |--------------------------------------------------------------------------
    */

    if (!is_file($path) || !is_readable($path)) {
        return null;
    }

    if (!extension_loaded('gd')) {
        return null;
    }

    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    $fonctions = [
        'jpg'  => 'imagecreatefromjpeg',
        'jpeg' => 'imagecreatefromjpeg',
        'png'  => 'imagecreatefrompng',
        'gif'  => 'imagecreatefromgif',
        'webp' => 'imagecreatefromwebp',
        'bmp'  => 'imagecreatefrombmp',
    ];

    if (!isset($fonctions[$extension])) {
        return null;
    }

    $fonction = $fonctions[$extension];

    if (!function_exists($fonction)) {
        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | IDENTIFICATION DU FICHIER
    |--------------------------------------------------------------------------
    */

    $mtime = filemtime($path);
    $size = filesize($path);

    if ($mtime === false || $size === false) {
        return null;
    }

    $identifiant = hash(
        'sha256',
        $path . '|' . $mtime . '|' . $size
    );

    /*
    |--------------------------------------------------------------------------
    | ORGANISATION DU CACHE
    |--------------------------------------------------------------------------
    */

    $cacheRoot = PHOTO_CACHE;

    $sousDossier = substr($identifiant, 0, 2);

    $cacheDirectory = $cacheRoot
        . DIRECTORY_SEPARATOR
        . $sousDossier;

    $thumbnail = $cacheDirectory
        . DIRECTORY_SEPARATOR
        . $identifiant
        . '.jpg';

    /*
    |--------------------------------------------------------------------------
    | CACHE EXISTANT
    |--------------------------------------------------------------------------
    */

    if (
        is_file($thumbnail)
        && filesize($thumbnail) > 0
    ) {
        return $thumbnail;
    }

    /*
    |--------------------------------------------------------------------------
    | CRÉATION DU DOSSIER
    |--------------------------------------------------------------------------
    */

    if (!is_dir($cacheDirectory)) {

        if (!mkdir($cacheDirectory, 0750, true) && !is_dir($cacheDirectory)) {
            error_log(
                'Photo : impossible de créer le cache : '
                . $cacheDirectory
            );

            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | VERROUILLAGE
    |--------------------------------------------------------------------------
    */

    $lockFile = $thumbnail . '.lock';

    $lock = fopen($lockFile, 'c');

    if ($lock === false) {
        return null;
    }

    if (!flock($lock, LOCK_EX)) {
        fclose($lock);
        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | NOUVELLE VÉRIFICATION APRÈS VERROUILLAGE
    |--------------------------------------------------------------------------
    */

    if (
        is_file($thumbnail)
        && filesize($thumbnail) > 0
    ) {
        flock($lock, LOCK_UN);
        fclose($lock);

        return $thumbnail;
    }

    /*
    |--------------------------------------------------------------------------
    | CHARGEMENT DE L'IMAGE
    |--------------------------------------------------------------------------
    */

    try {

        $image = @$fonction($path);

        if ($image === false) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | DIMENSIONS
        |--------------------------------------------------------------------------
        */

        $largeur = imagesx($image);
        $hauteur = imagesy($image);

        if ($largeur <= 0 || $hauteur <= 0) {
            imagedestroy($image);
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | CALCUL DES PROPORTIONS
        |--------------------------------------------------------------------------
        */

        $tailleMax = (int) PHOTO_MAX_THUMBNAIL_SIZE;

        $ratio = min(
            $tailleMax / $largeur,
            $tailleMax / $hauteur,
            1
        );

        $nouvelleLargeur = max(
            1,
            (int) round($largeur * $ratio)
        );

        $nouvelleHauteur = max(
            1,
            (int) round($hauteur * $ratio)
        );

        /*
        |--------------------------------------------------------------------------
        | CRÉATION DE LA MINIATURE
        |--------------------------------------------------------------------------
        */

        $miniature = imagecreatetruecolor(
            $nouvelleLargeur,
            $nouvelleHauteur
        );

        if ($miniature === false) {
            imagedestroy($image);
            return null;
        }

        $blanc = imagecolorallocate(
            $miniature,
            255,
            255,
            255
        );

        imagefill($miniature, 0, 0, $blanc);

        imagecopyresampled(
            $miniature,
            $image,
            0,
            0,
            0,
            0,
            $nouvelleLargeur,
            $nouvelleHauteur,
            $largeur,
            $hauteur
        );

        /*
        |--------------------------------------------------------------------------
        | FICHIER TEMPORAIRE
        |--------------------------------------------------------------------------
        */

        $temporaire = $thumbnail
            . '.'
            . bin2hex(random_bytes(8))
            . '.tmp';

        /*
        |--------------------------------------------------------------------------
        | ENREGISTREMENT JPEG
        |--------------------------------------------------------------------------
        */

        $succes = imagejpeg(
            $miniature,
            $temporaire,
            85
        );

        imagedestroy($image);
        imagedestroy($miniature);

        if (!$succes) {
            @unlink($temporaire);
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | INSTALLATION ATOMIQUE DU CACHE
        |--------------------------------------------------------------------------
        */

        if (!rename($temporaire, $thumbnail)) {
            @unlink($temporaire);
            return null;
        }

        chmod($thumbnail, 0640);

        return $thumbnail;

    } catch (Throwable $exception) {

        error_log(
            'Erreur génération miniature : '
            . $exception->getMessage()
        );

        return null;

    } finally {

        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
