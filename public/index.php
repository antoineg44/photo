<?php
declare(strict_types=1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);


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
    header('Location: connexion.php');
    exit;
}

function photo_e(string $valeur): string
{
    return htmlspecialchars(
        $valeur,
        ENT_QUOTES,
        'UTF-8'
    );
}

function photo_bouton_telecharger(
    string $chemin,
    string $csrfToken
): void {
    ?>
    <form method="POST" action="download.php" class="form-telecharger">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= photo_e($csrfToken) ?>"
        >

        <input
            type="hidden"
            name="file"
            value="<?= photo_e($chemin) ?>"
        >

        <button type="submit" class="bouton-telecharger">
            ⬇ Télécharger
        </button>

    </form>
    <?php
}


$identifiant = (string) (
    $_SESSION['utilisateur_identifiant'] ?? ''
);
$photoIdentifiant = $identifiant;

$csrfToken = $_SESSION['csrf_token'] ?? '';

if (!is_string($csrfToken) || $csrfToken === '') {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $csrfToken = $_SESSION['csrf_token'];
}

try {
    $autorise = utilisateurAAccesService(
        $pdo,
        (int) $_SESSION['utilisateur_id'],
        'photo'
    );

} catch (Throwable $exception) {

    error_log(
        'Erreur de vérification des droits photo : '
        . $exception->getMessage()
    );

    http_response_code(500);
    exit('Impossible de vérifier vos autorisations.');
}

if (!$autorise) {
    http_response_code(403);
    exit('Accès non autorisé au service photo.');
}

require_once __DIR__ . '/../app/includes/functions.php';

$folder = (string) ($_GET['dossier'] ?? '');

$directory = photo_resolve($folder);

if ($directory === null || !is_dir($directory)) {
    http_response_code(404);
    exit('Répertoire introuvable.');
}

$items = photo_list($folder);

$parts = $folder === ''
    ? []
    : explode('/', $folder);

?>
<!DOCTYPE html>
<html lang="fr">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1">

<title>Galerie Photos</title>

<link rel="stylesheet"
      href="assets/css/style.css">

</head>

<body>

<header>

    <div class="logo">
        📷 Ma galerie
    </div>

    <div class="utilisateur">

        <?= photo_e($photoIdentifiant) ?>

        <form method="POST"
              action="<?= photo_e(PHOTO_LOGOUT_URL) ?>">

            <input type="hidden"
                   name="csrf_token"
                   value="<?= photo_e($csrfToken) ?>">

            <button type="submit">
                Déconnexion
            </button>

        </form>

    </div>

</header>

<main>

    <nav class="breadcrumb">

        <a href="index.php">Accueil</a>

        <?php $current = ''; ?>

        <?php foreach ($parts as $part): ?>

            <?php $current .= ($current === '' ? '' : '/') . $part; ?>

            <span> / </span>

            <a href="?dossier=<?= rawurlencode($current) ?>">
                <?= photo_e($part) ?>
            </a>

        <?php endforeach; ?>

    </nav>

    <section class="galerie">

        <?php if (empty($items)): ?>

            <p class="vide">
                Aucun média dans ce répertoire.
            </p>

        <?php endif; ?>

        <?php foreach ($items as $item): ?>

            <?php if ($item['type'] === 'directory'): ?>

    <div class="element dossier">

        <a href="?dossier=<?= rawurlencode($item['path']) ?>"
           class="media-link">

            <div class="icone">📁</div>

            <div class="nom">
                <?= photo_e($item['name']) ?>
            </div>

        </a>

        <button
            type="button"
            class="download-folder"
            data-file="<?= photo_e($item['path']) ?>">
            ⬇ Télécharger le dossier
        </button>

    </div>


    <div class="element dossier">

        <a href="?dossier=<?= rawurlencode($item['path']) ?>">

            <div class="icone">📁</div>

            <div class="nom">
                <?= photo_e($item['name']) ?>
            </div>

        </a>

        <?php
        photo_bouton_telecharger(
            $item['path'],
            $csrfToken
        );
        ?>

    </div>


            <?php else: ?>

                <div class="element media">

                    <a href="media.php?type=player&amp;file=<?= rawurlencode($item['path']) ?>"
                       class="media-link"
                       <?= $item['type'] === 'image'
                           ? 'data-image="1"'
                           : '' ?>>

                        <?php if ($item['type'] === 'image'): ?>

                            <img
                                src="media.php?type=thumb&amp;file=<?= rawurlencode($item['path']) ?>"
                                loading="lazy"
                                alt="<?= photo_e($item['name']) ?>"
                            >

                        <?php else: ?>

                            <div class="video-preview">
                                <span>▶</span>
                                <small>VIDÉO</small>
                            </div>

                        <?php endif; ?>

                    </a>

                    <div class="nom">
    <?= photo_e($item['name']) ?>
</div>

<?php
photo_bouton_telecharger(
    $item['path'],
    $csrfToken
);
?>


                </div>

            <?php endif; ?>

        <?php endforeach; ?>

    </section>

</main>

<div id="lightbox" class="lightbox">

    <button id="fermer">×</button>

    <button id="precedent">❮</button>

    <img id="imageGrande" src="" alt="">

    <button id="suivant">❯</button>

</div>

<script src="assets/js/app.js"></script>

    <div id="zipProgress" class="zip-progress" hidden>

    <h3>📦 Création de l'archive</h3>

    <p id="zipMessage">Préparation...</p>

    <progress id="zipBar" value="0" max="100"></progress>

    <p id="zipPercent">0 %</p>

    <p id="zipFiles">0 fichier</p>

    <button type="button" id="cancelZip">
        ✖ Annuler
    </button>

    <a id="downloadZip" hidden>
        ⬇ Télécharger le ZIP
    </a>

</div>

<style>
.zip-progress {
    position: fixed;
    bottom: 20px;
    right: 20px;
    z-index: 9999;
    width: min(350px, calc(100% - 40px));
    padding: 20px;
    background: #1f2937;
    color: white;
    border-radius: 12px;
    box-shadow: 0 5px 25px #0005;
}

.zip-progress progress {
    width: 100%;
    height: 18px;
    margin: 12px 0;
}

.zip-progress button,
.zip-progress a {
    display: inline-block;
    padding: 10px 15px;
    margin-top: 10px;
    border: 0;
    border-radius: 6px;
    cursor: pointer;
    background: #ef4444;
    color: white;
    text-decoration: none;
}

.zip-progress a {
    background: #10b981;
}
</style>

</body>
</html>
