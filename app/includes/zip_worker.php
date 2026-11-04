<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/*
|--------------------------------------------------------------------------
| INITIALISATION
|--------------------------------------------------------------------------
*/

$directoryArgument = $argv[1] ?? '';

if ($directoryArgument === '' || !is_dir($directoryArgument)) {
    exit(1);
}

$directory = realpath($directoryArgument);

if ($directory === false) {
    exit(1);
}

$jobFile = $directory . '/job.json';
$cancelFile = $directory . '/cancel';
$archive = $directory . '/archive.zip';

/*
|--------------------------------------------------------------------------
| MISE À JOUR DU JOB
|--------------------------------------------------------------------------
*/

function zipUpdate(array $job): void
{
    global $jobFile;

    $job['updated_at'] = time();

    $json = json_encode(
        $job,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        throw new RuntimeException(
            'Impossible de générer le statut JSON.'
        );
    }

    $temporary = $jobFile . '.' . bin2hex(random_bytes(5)) . '.tmp';

    if (file_put_contents($temporary, $json, LOCK_EX) === false) {
        throw new RuntimeException(
            'Impossible d’écrire le statut.'
        );
    }

    if (!rename($temporary, $jobFile)) {
        @unlink($temporary);

        throw new RuntimeException(
            'Impossible de mettre à jour le statut.'
        );
    }
}


/*
|--------------------------------------------------------------------------
| VÉRIFICATION DE L'ANNULATION
|--------------------------------------------------------------------------
*/

function zipCancelled(): bool
{
    global $cancelFile;

    return is_file($cancelFile);
}

/*
|--------------------------------------------------------------------------
| INFORMATIONS DE DIAGNOSTIC
|--------------------------------------------------------------------------
*/

function zipDiagnostic(
    array &$job,
    string $etape,
    string $message
): void {

    global $archive;

    $job['step'] = $etape;
    $job['message'] = $message;
    $job['last_activity'] = time();

    clearstatcache(true, $archive);

    $job['archive_size'] = is_file($archive)
        ? filesize($archive)
        : 0;

    $job['memory_usage'] = memory_get_usage(true);
    $job['peak_memory'] = memory_get_peak_usage(true);

    $job['process_id'] = getmypid();
$job['diagnostic_time'] = date('Y-m-d H:i:s');


    zipUpdate($job);
}


/*
|--------------------------------------------------------------------------
| CHARGEMENT DU JOB
|--------------------------------------------------------------------------
*/

$job = json_decode(
    (string) file_get_contents($jobFile),
    true
);

if (!is_array($job)) {
    exit(1);
}

$zip = null;
$zipOpen = false;

try {

    /*
    |--------------------------------------------------------------------------
    | VÉRIFICATION INITIALE
    |--------------------------------------------------------------------------
    */

    if (zipCancelled()) {
        throw new RuntimeException('Annulation demandée.');
    }

    $source = photo_resolve(
        (string) ($job['relative_path'] ?? '')
    );

    if ($source === null || !is_dir($source)) {
        throw new RuntimeException('Dossier source introuvable.');
    }

    $source = realpath($source);

    if ($source === false) {
        throw new RuntimeException('Chemin source invalide.');
    }

    /*
    |--------------------------------------------------------------------------
    | PREMIÈRE PASSE : COMPTAGE DES FICHIERS
    |--------------------------------------------------------------------------
    */

    $job['progress'] = 0;
    $job['files_done'] = 0;
    $job['files_total'] = 0;
    $job['message'] = 'Analyse du dossier...';

    zipUpdate($job);

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

        if (
            $file->isFile()
            && $file->isReadable()
            && !$file->isLink()
        ) {
            $total++;
        }
    }

    $job['files_total'] = $total;
    $job['message'] = 'Préparation de l’archive...';

    zipUpdate($job);

    if ($total === 0) {
        throw new RuntimeException(
            'Le dossier ne contient aucun fichier téléchargeable.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | OUVERTURE DU ZIP
    |--------------------------------------------------------------------------
    */

    if (zipCancelled()) {
        throw new RuntimeException('Annulation demandée.');
    }

    $zip = new ZipArchive();

    $result = $zip->open(
        $archive,
        ZipArchive::CREATE | ZipArchive::OVERWRITE
    );

    if ($result !== true) {
        throw new RuntimeException(
            'Impossible de créer le ZIP. Code : ' . $result
        );
    }

    $zipOpen = true;

    /*
    |--------------------------------------------------------------------------
    | DEUXIÈME PASSE : AJOUT DES FICHIERS
    |--------------------------------------------------------------------------
    */

    $done = 0;

    $job['progress'] = 1;
    $job['message'] = 'Ajout des fichiers...';

    zipUpdate($job);

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

        if (
            !$file->isFile()
            || !$file->isReadable()
            || $file->isLink()
        ) {
            continue;
        }

        $real = $file->getRealPath();

        if ($real === false) {
            continue;
        }

        // Vérification que le fichier reste dans le dossier source.
        if (!str_starts_with(
            $real,
            $source . DIRECTORY_SEPARATOR
        )) {
            continue;
        }

        $relative = substr(
            $real,
            strlen($source) + 1
        );

        if (!$zip->addFile($real, $relative)) {
            throw new RuntimeException(
                'Impossible d’ajouter : ' . $relative
            );
        }

        $done++;

        /*
        |--------------------------------------------------------------------------
        | PROGRESSION DE 1 À 89 %
        |--------------------------------------------------------------------------
        */

        $job['files_done'] = $done;

        $job['progress'] = min(
            89,
            1 + (int) floor(($done / $total) * 88)
        );

        if ($done % 5 === 0 || $done === $total) {

            $job['message'] = 'Ajout des fichiers...';

            zipUpdate($job);
        }
    }

    if (zipCancelled()) {
        throw new RuntimeException('Annulation demandée.');
    }

    if ($done === 0) {
        throw new RuntimeException(
            'Aucun fichier n’a pu être ajouté.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | FINALISATION DU ZIP : 90 À 99 %
    |--------------------------------------------------------------------------
    */
/*
FINALISATION DU ZIP : DIAGNOSTIC DÉTAILLÉ
--------------------------------------------------------------------------
*/

$debutFinalisation = microtime(true);

$job['progress'] = 90;

zipDiagnostic( $job, 'finalization_start', 'Début de la finalisation de l’archive.' );

/*
CALLBACK DE PROGRESSION
--------------------------------------------------------------------------
*/

if (method_exists($zip, 'registerProgressCallback')) {

$dernierPourcentage = 90;

$zip->registerProgressCallback(
    0.01,
    function (float $progress) use (
        &$job,
        &$dernierPourcentage,
        $debutFinalisation
    ): void {

        $progress = max(0, min(1, $progress));

        $pourcentage = min(
            99,
            90 + (int) floor($progress * 9)
        );

        $job['finalization_progress'] = $progress;

        $job['finalization_elapsed'] = round(
            microtime(true) - $debutFinalisation,
            2
        );

        if ($pourcentage > $dernierPourcentage) {

            $dernierPourcentage = $pourcentage;

            $job['progress'] = $pourcentage;

            $job['message'] = 'Finalisation ZIP : '
                . $pourcentage . '%';

            zipUpdate($job);
        }
    }
);


}

/*
CALLBACK D'ANNULATION
--------------------------------------------------------------------------
*/

if (method_exists($zip, 'registerCancelCallback')) {

$zip->registerCancelCallback(
    function (): int {
        return zipCancelled() ? 1 : 0;
    }
);


}

/*
AVANT FERMETURE
--------------------------------------------------------------------------
*/

zipDiagnostic( $job, 'zip_close_started', 'Appel de ZipArchive::close().' );

$debutClose = microtime(true);

/*
FERMETURE RÉELLE DU ZIP
--------------------------------------------------------------------------
*/

$success = $zip->close();

$dureeClose = round( microtime(true) - $debutClose, 2 );

$zipOpen = false;

/*
INFORMATIONS APRÈS FERMETURE
--------------------------------------------------------------------------
*/

$job['zip_close_success'] = $success;

$job['zip_close_duration'] = $dureeClose;

$job['finalization_elapsed'] = round( microtime(true) - $debutFinalisation, 2 );

zipDiagnostic( $job, 'zip_close_returned', 'ZipArchive::close() terminé en ' . $dureeClose . ' secondes.' );

if (!$success) {

throw new RuntimeException(
    'Échec de ZipArchive::close().'
);


}

/*
VÉRIFICATION DE L'ANNULATION
--------------------------------------------------------------------------
*/

if (zipCancelled()) { throw new RuntimeException('Annulation demandée.'); }

/*
VÉRIFICATION PHYSIQUE DU FICHIER
--------------------------------------------------------------------------
*/

zipDiagnostic( $job, 'archive_verification', 'Vérification physique de l’archive.' );

clearstatcache(true, $archive);

if (!is_file($archive)) { throw new RuntimeException('Le fichier ZIP est introuvable.'); }

$tailleArchive=filesize(archive);

if ($tailleArchive === false || $tailleArchive === 0) { throw new RuntimeException('Le fichier ZIP est vide.'); }

$job['archive_size'] = $tailleArchive;

$job['step'] = 'archive_verified';

zipUpdate($job);

zipDiagnostic( $job, 'completed', 'Archive vérifiée et prête.' );




    /*
    |--------------------------------------------------------------------------
    | DERNIÈRE VÉRIFICATION DE L'ANNULATION
    |--------------------------------------------------------------------------
    */

    if (zipCancelled()) {
        throw new RuntimeException('Annulation demandée.');
    }

    /*
    |--------------------------------------------------------------------------
    | VÉRIFICATION DE L'ARCHIVE
    |--------------------------------------------------------------------------
    */

    clearstatcache(true, $archive);

    if (
        !is_file($archive)
        || filesize($archive) === false
        || filesize($archive) === 0
    ) {
        throw new RuntimeException(
            'L’archive ZIP créée est vide ou inaccessible.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ARCHIVE TERMINÉE
    |--------------------------------------------------------------------------
    */

    $job['status'] = 'ready';
    $job['step'] = 'completed';
$job['completed_at'] = time();
$job['archive_size'] = filesize($archive);

    $job['progress'] = 100;
    $job['files_done'] = $done;
    $job['files_total'] = $total;
    $job['message'] = 'Archive prête au téléchargement.';

    zipUpdate($job);

} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | FERMETURE EN CAS D'ERREUR
    |--------------------------------------------------------------------------
    */

    if ($zipOpen && $zip instanceof ZipArchive) {

        $zipOpen = false;

        @$zip->close();
    }

    /*
    |--------------------------------------------------------------------------
    | SUPPRESSION DU ZIP INCOMPLET
    |--------------------------------------------------------------------------
    */

    @unlink($archive);

    /*
    |--------------------------------------------------------------------------
    | STATUT FINAL
    |--------------------------------------------------------------------------
    */

    if (zipCancelled()) {

        $job['status'] = 'cancelled';
        $job['progress'] = 0;
        $job['message'] = 'Création annulée.';

    } else {

        $job['status'] = 'error';
        $job['message'] = 'Erreur : ' . $e->getMessage();

        error_log(
            'Erreur ZIP photo : ' . $e->getMessage()
        );
    }

    try {
        zipUpdate($job);
    } catch (Throwable $statusError) {
        error_log(
            'Impossible de mettre à jour le job ZIP : '
            . $statusError->getMessage()
        );
    }
}
