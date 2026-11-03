<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AUTHENTIFICATION
|--------------------------------------------------------------------------
*/

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

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
require_once __DIR__ . '/../app/includes/functions.php';

function downloadErreur(int $code, string $message): never
{
    http_response_code($code);
    exit($message);
}

function downloadJson(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['utilisateur_id'])) {
    downloadErreur(403, 'Authentification nécessaire.');
}

try {
    $autorise = utilisateurAAccesService(
        $pdo,
        (int) $_SESSION['utilisateur_id'],
        'photo'
    );

    if (!$autorise) {
        downloadErreur(403, 'Accès non autorisé.');
    }

} catch (Throwable $e) {

    error_log('Erreur autorisation téléchargement : ' . $e->getMessage());

    downloadErreur(500, 'Erreur de vérification des autorisations.');
}

$userId = (int) $_SESSION['utilisateur_id'];

$csrf = $_SESSION['csrf_token'] ?? '';

session_write_close();

/*
|--------------------------------------------------------------------------
| DOSSIER DES TÉLÉCHARGEMENTS
|--------------------------------------------------------------------------
*/

$jobsDirectory = PHOTO_CACHE . '/downloads';

if (!is_dir($jobsDirectory)) {
    mkdir($jobsDirectory, 0750, true);
}

if (!is_dir($jobsDirectory) || !is_writable($jobsDirectory)) {
    downloadErreur(500, 'Le dossier des téléchargements est inaccessible.');
}

/*
|--------------------------------------------------------------------------
| RÉCUPÉRATION D'UN JOB
|--------------------------------------------------------------------------
*/

function downloadGetJob(string $id, int $userId): array
{
    global $jobsDirectory;

    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        downloadErreur(400, 'Identifiant invalide.');
    }

    $directory = $jobsDirectory . '/' . $id;
    $file = $directory . '/job.json';

    if (!is_file($file)) {
        downloadErreur(404, 'Téléchargement introuvable.');
    }

    $job = json_decode(
        (string) file_get_contents($file),
        true
    );

    if (
        !is_array($job)
        || (int) ($job['user_id'] ?? 0) !== $userId
    ) {
        downloadErreur(403, 'Accès interdit.');
    }

    return [$directory, $job];
}

/*
|--------------------------------------------------------------------------
| ACTION : SUIVI DE LA PROGRESSION
|--------------------------------------------------------------------------
*/

if (($_GET['action'] ?? '') === 'status') {

    [$directory, $job] = downloadGetJob(
        (string) ($_GET['id'] ?? ''),
        $userId
    );

    downloadJson([
        'status' => $job['status'],
        'progress' => (int) $job['progress'],
        'files_done' => (int) $job['files_done'],
        'files_total' => (int) $job['files_total'],
        'message' => $job['message'] ?? '',
    ]);
}

/*
|--------------------------------------------------------------------------
| ACTION : TÉLÉCHARGEMENT DU ZIP
|--------------------------------------------------------------------------
*/

if (($_GET['action'] ?? '') === 'download') {

    [$directory, $job] = downloadGetJob(
        (string) ($_GET['id'] ?? ''),
        $userId
    );

    if ($job['status'] !== 'ready') {
        downloadErreur(409, 'L’archive n’est pas encore prête.');
    }

    $zipFile = $directory . '/archive.zip';

    if (!is_file($zipFile)) {
        downloadErreur(404, 'Archive introuvable.');
    }

    $nom = basename($job['name']) . '.zip';

    header('Content-Type: application/zip');

    header(
        'Content-Disposition: attachment; filename="'
        . rawurlencode($nom)
        . '"; filename*=UTF-8\'\''
        . rawurlencode($nom)
    );

    header('Content-Length: ' . filesize($zipFile));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    readfile($zipFile);
    exit;
}

/*
|--------------------------------------------------------------------------
| ACTIONS POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    downloadErreur(405, 'Méthode non autorisée.');
}

$postToken = $_POST['csrf_token'] ?? '';

if (
    !is_string($postToken)
    || !is_string($csrf)
    || $csrf === ''
    || !hash_equals($csrf, $postToken)
) {
    downloadErreur(403, 'Jeton CSRF invalide.');
}

$action = $_POST['action'] ?? '';

/*
|--------------------------------------------------------------------------
| ANNULATION
|--------------------------------------------------------------------------
*/

if ($action === 'cancel') {

    [$directory, $job] = downloadGetJob(
        (string) ($_POST['id'] ?? ''),
        $userId
    );

    if ($job['status'] === 'processing') {

        file_put_contents(
            $directory . '/cancel',
            '1'
        );
    }

    downloadJson([
        'success' => true,
        'message' => 'Annulation demandée.'
    ]);
}

/*
|--------------------------------------------------------------------------
| TÉLÉCHARGEMENT DIRECT D'UN FICHIER
|--------------------------------------------------------------------------
*/

if ($action === 'file') {

    $file = $_POST['file'] ?? '';

    if (!is_string($file) || $file === '') {
        downloadErreur(400, 'Chemin invalide.');
    }

    $path = photo_resolve($file);

    if ($path === null || !is_file($path)) {
        downloadErreur(404, 'Fichier introuvable.');
    }

    if (!is_readable($path)) {
        downloadErreur(403, 'Fichier inaccessible.');
    }

    $nom = basename($path);

    header('Content-Type: application/octet-stream');

    header(
        'Content-Disposition: attachment; filename="'
        . rawurlencode($nom)
        . '"; filename*=UTF-8\'\''
        . rawurlencode($nom)
    );

    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    readfile($path);
    exit;
}

/*
|--------------------------------------------------------------------------
| CRÉATION D'UNE ARCHIVE ZIP
|--------------------------------------------------------------------------
*/

if ($action !== 'start') {
    downloadErreur(400, 'Action inconnue.');
}

$file = $_POST['file'] ?? '';

if (!is_string($file) || $file === '') {
    downloadErreur(400, 'Chemin invalide.');
}

$path = photo_resolve($file);

if ($path === null || !is_dir($path)) {
    downloadErreur(404, 'Dossier introuvable.');
}

if (!class_exists(ZipArchive::class)) {
    downloadErreur(500, 'Extension PHP ZIP non installée.');
}

/*
|--------------------------------------------------------------------------
| CRÉATION DU JOB
|--------------------------------------------------------------------------
*/

$id = bin2hex(random_bytes(16));

$directory = $jobsDirectory . '/' . $id;

if (!mkdir($directory, 0750, true)) {
    downloadErreur(500, 'Impossible de créer le téléchargement.');
}

$job = [
    'user_id' => $userId,
    'name' => basename($path),
    'relative_path' => $file,
    'status' => 'processing',
    'progress' => 0,
    'files_done' => 0,
    'files_total' => 0,
    'message' => 'Préparation de l’archive...',
    'created_at' => time(),
];

file_put_contents(
    $directory . '/job.json',
    json_encode($job, JSON_UNESCAPED_UNICODE),
    LOCK_EX
);

/*
|--------------------------------------------------------------------------
| LANCEMENT DU PROCESSUS EN ARRIÈRE-PLAN
|--------------------------------------------------------------------------
*/

$worker = __DIR__ . '/../app/includes/zip_worker.php';

if (!is_file($worker) || !function_exists('exec')) {
    downloadErreur(500, 'Impossible de lancer le processus ZIP.');
}

$command = escapeshellarg(PHP_BINARY)
    . ' '
    . escapeshellarg($worker)
    . ' '
    . escapeshellarg($directory);

exec(
    $command . ' > /dev/null 2>&1 &'
);

downloadJson([
    'success' => true,
    'id' => $id,
    'message' => 'Création du ZIP lancée.'
]);
