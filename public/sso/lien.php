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

function base64Url(string $valeur): string
{
    return rtrim(strtr(base64_encode($valeur), '+/', '-_'), '=');
}

$cle = (string) ($_GET['cle'] ?? '');

if (!preg_match('/\A[A-Za-z0-9_-]{43}\z/', $cle)) {
    http_response_code(400);
    exit('Lien invalide.');
}

// Génération PKCE.
$verifier = base64Url(random_bytes(32));
$challenge = base64Url(hash('sha256', $verifier, true));
$state = base64Url(random_bytes(32));

$_SESSION['sso_photo_pending'] = [
    'verifier' => $verifier,
    'state' => $state,
];

$parametres = [
    'client_id' => 'photo',
    'redirect_uri' => 'https://photo.partitions.ovh/sso/callback.php',
    'response_type' => 'code',
    'code_challenge' => $challenge,
    'code_challenge_method' => 'S256',
    'state' => $state,
    'access_key' => $cle,
];

$url = 'https://compte.partitions.ovh/sso/authorize.php?'
    . http_build_query($parametres, '', '&', PHP_QUERY_RFC3986);

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('Location: ' . $url);
exit;
