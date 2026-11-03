<?php
declare(strict_types=1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
/*
|--------------------------------------------------------------------------
| AUTHENTIFICATION SSO
|--------------------------------------------------------------------------
*/

$isHttps = isset($_SERVER['HTTPS'])
    && $_SERVER['HTTPS'] !== 'off';

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

require_once '/var/www/compte/config/database.php';
require_once '/var/www/compte/config/services.php';

if (!isset($_SESSION['utilisateur_id'])) {
    http_response_code(403);
    exit('Authentification nécessaire.');
}

try {
    $autorise = utilisateurAAccesService(
        $pdo,
        (int) $_SESSION['utilisateur_id'],
        'photo'
    );

} catch (Throwable $exception) {

    error_log(
        'Erreur autorisation média photo : '
        . $exception->getMessage()
    );

    http_response_code(500);
    exit('Impossible de vérifier vos autorisations.');
}

if (!$autorise) {
    http_response_code(403);
    exit('Accès non autorisé.');
}

/*
|--------------------------------------------------------------------------
| FONCTIONS
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../app/includes/functions.php';

/*
|--------------------------------------------------------------------------
| RÉCUPÉRATION DU FICHIER
|--------------------------------------------------------------------------
*/

$file = (string) ($_GET['file'] ?? '');
$type = (string) ($_GET['type'] ?? '');

$path = photo_resolve($file);

if ($path === null || !is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit('Fichier introuvable.');
}

$extension = strtolower(
    pathinfo($path, PATHINFO_EXTENSION)
);

/*
|--------------------------------------------------------------------------
| TYPES MIME
|--------------------------------------------------------------------------
*/

$mimeTypes = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'bmp'  => 'image/bmp',
    'mp4'  => 'video/mp4',
    'webm' => 'video/webm',
    'ogv'  => 'video/ogg',
];

if (!isset($mimeTypes[$extension])) {
    http_response_code(415);
    exit('Format non pris en charge.');
}

$isImage = photo_is_image($path);
$isVideo = photo_is_video($path);

/*
|--------------------------------------------------------------------------
| PLAYER
|--------------------------------------------------------------------------
*/

if ($type === 'player') {

    if ($isImage) {

        header(
            'Location: media.php?type=view&file='
            . rawurlencode($file)
        );

        exit;
    }

    if (!$isVideo) {
        http_response_code(415);
        exit('Format non pris en charge.');
    }

    $source = 'media.php?type=view&file='
        . rawurlencode($file);

    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');

    ?>

    <!DOCTYPE html>
    <html lang="fr">

    <head>

        <meta charset="UTF-8">

        <meta name="viewport"
              content="width=device-width, initial-scale=1">

        <title>Lecture vidéo</title>

        <style>

            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                background: #111827;
                color: white;
                font-family: Arial, sans-serif;
                display: flex;
                flex-direction: column;
                align-items: center;
                min-height: 100vh;
            }

            h2 {
                font-size: 16px;
                padding: 15px;
                overflow-wrap: anywhere;
            }

            video {
                width: 95%;
                max-height: 80vh;
                background: black;
            }

            a {
                color: white;
                margin: 20px;
            }

        </style>

    </head>

    <body>

        <h2><?= photo_e(basename($file)) ?></h2>

        <video controls autoplay playsinline>

            <source
                src="<?= photo_e($source) ?>"
                type="<?= photo_e($mimeTypes[$extension]) ?>"
            >

            Votre navigateur ne peut pas lire cette vidéo.

        </video>

        <a href="javascript:history.back()">
            Retour à la galerie
        </a>

    </body>

    </html>

    <?php

    exit;
}

/*
|--------------------------------------------------------------------------
| MINIATURE
|--------------------------------------------------------------------------
*/

if ($type === 'thumb') {

    if (!$isImage) {
        http_response_code(415);
        exit;
    }

    $thumbnail = photo_thumbnail($path);

    if ($thumbnail !== null && is_file($thumbnail)) {

        header('Content-Type: image/jpeg');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=86400');

        header('Content-Length: ' . filesize($thumbnail));

        if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
            readfile($thumbnail);
        }

        exit;
    }

    // Secours : afficher l'image originale.
    $type = 'view';
}

/*
|--------------------------------------------------------------------------
| VALIDATION DU TYPE
|--------------------------------------------------------------------------
*/

if ($type !== 'view') {
    http_response_code(400);
    exit('Type de média invalide.');
}

/*
|--------------------------------------------------------------------------
| EN-TÊTES COMMUNS
|--------------------------------------------------------------------------
*/

$size = filesize($path);

if ($size === false || $size <= 0) {
    http_response_code(500);
    exit('Impossible de lire le fichier.');
}

$mime = $mimeTypes[$extension];

header('Content-Type: ' . $mime);
header('Content-Disposition: inline');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

/*
|--------------------------------------------------------------------------
| AFFICHAGE DES IMAGES
|--------------------------------------------------------------------------
*/

if ($isImage) {

    header('Content-Length: ' . $size);

    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
        readfile($path);
    }

    exit;
}

/*
|--------------------------------------------------------------------------
| LECTURE VIDÉO HTTP RANGE
|--------------------------------------------------------------------------
*/

if (!$isVideo) {
    http_response_code(415);
    exit;
}

header('Accept-Ranges: bytes');

$start = 0;
$end = $size - 1;

$range = $_SERVER['HTTP_RANGE'] ?? '';

if ($range !== '') {

    if (!preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches)) {

        http_response_code(416);

        header('Content-Range: bytes */' . $size);

        exit;
    }

    if ($matches[1] === '' && $matches[2] !== '') {

        $suffix = (int) $matches[2];

        if ($suffix <= 0) {

            http_response_code(416);

            header('Content-Range: bytes */' . $size);

            exit;
        }

        $start = max(0, $size - $suffix);

    } else {

        $start = (int) $matches[1];

        if ($matches[2] !== '') {
            $end = (int) $matches[2];
        }
    }

    if (
        $start >= $size
        || $end < $start
        || $end >= $size
    ) {

        http_response_code(416);

        header('Content-Range: bytes */' . $size);

        exit;
    }

    http_response_code(206);

    header(
        "Content-Range: bytes $start-$end/$size"
    );
}

$length = $end - $start + 1;

header('Content-Length: ' . $length);

/*
|--------------------------------------------------------------------------
| REQUÊTE HEAD
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
    exit;
}

/*
|--------------------------------------------------------------------------
| OUVERTURE DU FICHIER
|--------------------------------------------------------------------------
*/

$handle = fopen($path, 'rb');

if ($handle === false) {
    http_response_code(500);
    exit('Impossible d' . "'" . 'ouvrir la vidéo.');
}

if (fseek($handle, $start) !== 0) {

    fclose($handle);

    http_response_code(500);

    exit('Impossible de positionner la lecture.');
}

/*
|--------------------------------------------------------------------------
| TRANSMISSION PAR BLOCS
|--------------------------------------------------------------------------
*/

while ($length > 0 && !feof($handle)) {

    $chunkSize = min(1024 * 1024, $length);

    $buffer = fread($handle, $chunkSize);

    if ($buffer === false || $buffer === '') {
        break;
    }

    echo $buffer;

    $length -= strlen($buffer);

    flush();

    if (connection_aborted()) {
        break;
    }
}

fclose($handle);

exit;
