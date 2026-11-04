<?php
declare(strict_types=1);

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

if (!isset($_SESSION['utilisateur_id'])) {
    header('Location: connexion.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1">

<title>Ma galerie Photos</title>

<style>

* {
    box-sizing: border-box;
}

html,
body {
    width: 100%;
    height: 100%;

    margin: 0;
    padding: 0;

    overflow: hidden;

    font-family: Arial, sans-serif;
    background: #f5f5f5;
}

/* IFRAME */

#galerieFrame {
    display: block;

    width: 100%;
    height: 100%;

    border: none;

    opacity: 0;

    transition: opacity 0.3s ease;
}

#galerieFrame.loaded {
    opacity: 1;
}

/* LOADER */

#loader {
    position: fixed;

    inset: 0;

    z-index: 9999;

    display: flex;
    flex-direction: column;

    justify-content: center;
    align-items: center;

    background: #f5f5f5;

    transition: opacity 0.3s ease;
}

.spinner {
    width: 55px;
    height: 55px;

    border: 5px solid #ddd;
    border-top-color: #2563eb;

    border-radius: 50%;

    animation: rotation 0.8s linear infinite;
}

@keyframes rotation {

    to {
        transform: rotate(360deg);
    }

}

.loader-text {
    margin-top: 20px;

    font-size: 15px;

    color: #555;
}

/* ERREUR */

#loaderError {
    display: none;

    margin-top: 20px;

    text-align: center;
}

#loaderError button {
    padding: 10px 18px;

    border: none;
    border-radius: 6px;

    background: #2563eb;
    color: white;

    cursor: pointer;
}

#loader.hidden {
    opacity: 0;
    pointer-events: none;
}

</style>

</head>

<body>

<!-- LOADER -->

<div id="loader">

    <div class="spinner"></div>

    <div class="loader-text" id="loaderText">
        Chargement de votre galerie...
    </div>

    <div id="loaderError">

        <p>Impossible de charger la galerie.</p>

        <button onclick="chargerGalerie()">
            Réessayer
        </button>

    </div>

</div>


<!-- GALERIE INDÉPENDANTE -->

<iframe
    id="galerieFrame"
    src="galerie.php"
    title="Galerie photos"
    loading="eager"
></iframe>


<script>

const frame = document.getElementById('galerieFrame');
const loader = document.getElementById('loader');

const loaderText = document.getElementById('loaderText');
const loaderError = document.getElementById('loaderError');

let timeoutChargement;

let charge = false;


/*
|--------------------------------------------------------------------------
| CHARGEMENT TERMINÉ
|--------------------------------------------------------------------------
*/

function galerieChargee() {

    charge = true;

    clearTimeout(timeoutChargement);

    frame.classList.add('loaded');

    loader.classList.add('hidden');

    setTimeout(function () {

        loader.style.display = 'none';

    }, 350);

}


/*
|--------------------------------------------------------------------------
| ERREUR DE CHARGEMENT
|--------------------------------------------------------------------------
*/

function erreurChargement() {

    if (charge) {
        return;
    }

    clearTimeout(timeoutChargement);

    loaderText.style.display = 'none';

    loaderError.style.display = 'block';

}


/*
|--------------------------------------------------------------------------
| NOUVEAU CHARGEMENT
|--------------------------------------------------------------------------
*/

function chargerGalerie() {

    charge = false;

    loader.style.display = 'flex';
    loader.classList.remove('hidden');

    loaderText.style.display = 'block';
    loaderText.textContent = 'Chargement de votre galerie...';

    loaderError.style.display = 'none';

    frame.classList.remove('loaded');

    clearTimeout(timeoutChargement);

    timeoutChargement = setTimeout(
        erreurChargement,
        60000
    );

    frame.src = 'galerie.php?t=' + Date.now();

}


/*
|--------------------------------------------------------------------------
| ÉVÉNEMENTS
|--------------------------------------------------------------------------
*/

frame.addEventListener('load', galerieChargee);

frame.addEventListener('error', erreurChargement);


/*
|--------------------------------------------------------------------------
| DÉLAI MAXIMUM
|--------------------------------------------------------------------------
*/

timeoutChargement = setTimeout(
    erreurChargement,
    60000
);

</script>

</body>
</html>
