<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/functions.php';

$file = (string) ($_GET['file'] ?? '');
$type = (string) ($_GET['type'] ?? '');

$path = photo_resolve($file);

if ($path === null || !is_file($path)) {
    http_response_code(404);
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
