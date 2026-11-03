<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AUTHENTIFICATION
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
| FONCTIONS DE LA GALERIE
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../app/includes/functions.php';


$file = (string) ($_GET['file'] ?? '');
$type = (string) ($_GET['type'] ?? '');

$path = photo_resolve($file);

if ($path === null || !is_file($path)) {
    http_response_code(404);
    exit;
}

if ($type === 'player') {

    if (!photo_is_video($path)) {
        http_response_code(415);
        exit;
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
                type="<?= photo_e([
                    'mp4' => 'video/mp4',
                    'webm' => 'video/webm',
                    'ogv' => 'video/ogg'
                ][strtolower(pathinfo($path, PATHINFO_EXTENSION))]) ?>"
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

if ($type === 'thumb') {

    if (!photo_is_image($path)) {
        http_response_code(415);
        exit;
    }

    $thumbnail = photo_thumbnail($path);

    if ($thumbnail !== null) {

        header('Content-Type: image/jpeg');
        header('Cache-Control: private, max-age=86400');

        readfile($thumbnail);
        exit;
    }

    // Solution de secours si GD n'est pas disponible.
    $type = 'view';
}

if ($type !== 'view') {
    http_response_code(400);
    exit;
}

$extension = strtolower(
    pathinfo($path, PATHINFO_EXTENSION)
);

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
    exit;
}

$isVideo = photo_is_video($path);

$size = filesize($path);

if ($size === false) {
    http_response_code(500);
    exit;
}

$mime = $mimeTypes[$extension];

header('Content-Type: ' . $mime);
header('Content-Disposition: inline');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

if (!$isVideo) {

    header('Content-Length: ' . $size);

    readfile($path);
    exit;
}

// Lecture vidéo avec prise en charge HTTP Range.

header('Accept-Ranges: bytes');

$start = 0;
$end = $size - 1;

$range = $_SERVER['HTTP_RANGE'] ?? '';

if ($range !== '') {

    if (
        !preg_match(
            '/^bytes=(\d*)-(\d*)$/',
            $range,
            $matches
        )
    ) {
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

if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
    exit;
}

$handle = fopen($path, 'rb');

if ($handle === false) {
    http_response_code(500);
    exit;
}

if (fseek($handle, $start) !== 0) {
    fclose($handle);
    http_response_code(500);
    exit;
}

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
