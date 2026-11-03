<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$directory = $argv[1] ?? '';

if ($directory === '' || !is_dir($directory)) {
    exit(1);
}

$directory = realpath($directory);

if ($directory === false) {
    exit(1);
}

$jobFile = $directory . '/job.json';

function zipUpdate(array $job): void
{
    global $jobFile;

    $temporary = $jobFile . '.tmp';

    file_put_contents(
        $temporary,
        json_encode($job, JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );

    rename($temporary, $jobFile);
}

function zipCancelled(): bool
{
    global $directory;

    return is_file($directory . '/cancel');
}

$job = json_decode(
    (string) file_get_contents($jobFile),
    true
);

if (!is_array($job)) {
    exit(1);
}

try {

    $source = photo_resolve($job['relative_path']);

    if ($source === null || !is_dir($source)) {
        throw new RuntimeException('Dossier source introuvable.');
    }

    $archive = $directory . '/archive.zip';

    $zip = new ZipArchive();

    if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Impossible de créer le ZIP.');
    }

    /*
    |--------------------------------------------------------------------------
    | PREMIÈRE PASSE : COMPTAGE
    |--------------------------------------------------------------------------
    */

    $total = 0;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $source,
            FilesystemIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $file) {

        if (zipCancelled()) {
            throw new RuntimeException('Annulation demandée.');
        }

        if ($file->isFile() && !$file->isLink()) {
            $total++;
        }
    }

    $job['files_total'] = $total;
    $job['message'] = 'Création de l’archive...';

    zipUpdate($job);

    /*
    |--------------------------------------------------------------------------
    | DEUXIÈME PASSE : CRÉATION DU ZIP
    |--------------------------------------------------------------------------
    */

    $done = 0;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $source,
            FilesystemIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $file) {

        if (zipCancelled()) {
            throw new RuntimeException('Annulation demandée.');
        }

        if (!$file->isFile() || !$file->isReadable() || $file->isLink()) {
            continue;
        }

        $real = $file->getRealPath();

        if ($real === false) {
            continue;
        }

        $relative = substr(
            $real,
            strlen($source) + 1
        );

        if (!$zip->addFile($real, $relative)) {
            throw new RuntimeException('Erreur ajout fichier ZIP.');
        }

        $done++;

        $job['files_done'] = $done;

        $job['progress'] = $total > 0
            ? (int) floor(($done / $total) * 100)
            : 100;

        if ($done % 10 === 0 || $done === $total) {
            $job['message'] = 'Création de l’archive...';
            zipUpdate($job);
        }
    }

    if (zipCancelled()) {
        throw new RuntimeException('Annulation demandée.');
    }

    $zip->close();

    if ($done === 0) {
        throw new RuntimeException('Le dossier ne contient aucun fichier.');
    }

    $job['status'] = 'ready';
    $job['progress'] = 100;
    $job['files_done'] = $done;
    $job['message'] = 'Archive prête.';

    zipUpdate($job);

} catch (Throwable $e) {

    if (isset($zip) && $zip instanceof ZipArchive) {
        @$zip->close();
    }

    @unlink($directory . '/archive.zip');

    if (zipCancelled()) {

        $job['status'] = 'cancelled';
        $job['message'] = 'Création annulée.';

    } else {

        $job['status'] = 'error';
        $job['message'] = 'Erreur : ' . $e->getMessage();

        error_log('Erreur ZIP photo : ' . $e->getMessage());
    }

    zipUpdate($job);
}
