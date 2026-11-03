<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/auth.php';
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

                <a class="element dossier"
                   href="?dossier=<?= rawurlencode($item['path']) ?>">

                    <div class="icone">📁</div>

                    <div class="nom">
                        <?= photo_e($item['name']) ?>
                    </div>

                </a>

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

</body>
</html>
