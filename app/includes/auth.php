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

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once '/var/www/compte/config/database.php';
require_once '/var/www/compte/config/services.php';

require_once __DIR__ . '/../config/config.php';

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

$photoIdentifiant = (string) (
    $_SESSION['utilisateur_identifiant'] ?? ''
);

$csrfToken = $_SESSION['csrf_token'] ?? '';

if (!is_string($csrfToken) || $csrfToken === '') {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $csrfToken = $_SESSION['csrf_token'];
}

try {

    $photoAutorise = utilisateurAAccesService(
        $pdo,
        (int) $_SESSION['utilisateur_id'],
        PHOTO_SERVICE
    );

} catch (Throwable $exception) {

    error_log(
        'Erreur autorisation galerie photo : '
        . $exception->getMessage()
    );

    http_response_code(500);

    exit('Impossible de vérifier vos autorisations.');
}

if (!$photoAutorise) {

    http_response_code(403);

    exit('Accès non autorisé au service photo.');
}
