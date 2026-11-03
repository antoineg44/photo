<?php

declare(strict_types=1);
$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax',]);
session_start();
require_once '/var/www/compte/config/database.php';
require_once '/var/www/compte/config/services.php';
require_once __DIR__ . '/../app/includes/functions.php';
function downloadErreur(int $code, string $message): never
{
  http_response_code($code);
  exit($message);
} // Vérification de la connexion. 
if (!isset($_SESSION['utilisateur_id'])) {
  downloadErreur(403, 'Authentification nécessaire.');
} // Vérification des autorisations. 
try {
  if (!utilisateurAAccesService($pdo, (int) $_SESSION['utilisateur_id'], 'photo')) {
    downloadErreur(403, 'Accès non autorisé.');
  }
} catch (Throwable $e) {
  error_log('Erreur autorisation téléchargement : ' . $e->getMessage());
  downloadErreur(500, 'Erreur de vérification des autorisations.');
} // Vérification de la méthode. 
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  downloadErreur(405, 'Méthode non autorisée.');
} // Vérification CSRF. 
$csrf = $_POST['csrf_token'] ?? '';
if (!is_string($csrf) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
  downloadErreur(403, 'Jeton de sécurité invalide.');
} // Récupération du chemin. 
$file = $_POST['file'] ?? '';
if (!is_string($file) || $file === '') {
  downloadErreur(400, 'Chemin invalide.');
}
$path = photo_resolve($file);
if ($path === null || !file_exists($path)) {
  downloadErreur(404, 'Fichier ou dossier introuvable.');
}
$root = realpath(PHOTO_ROOT);
$realPath = realpath($path);
if ($root === false || $realPath === false || !str_starts_with($realPath, $root . DIRECTORY_SEPARATOR)) {
  downloadErreur(403, 'Chemin non autorisé.');
} // Téléchargement d'un fichier individuel. 
if (is_file($realPath)) {
  if (!is_readable($realPath)) {
    downloadErreur(403, 'Fichier inaccessible.');
  }
  $nom = basename($realPath);
  header('Content-Type: application/octet-stream');
  header('Content-Disposition: attachment; filename="' . rawurlencode($nom) . '"; filename*=UTF-8\'\'' . rawurlencode($nom));
  header('Content-Length: ' . filesize($realPath));
  header('X-Content-Type-Options: nosniff');
  header('Cache-Control: no-store');
  readfile($realPath);
  exit;
} // Téléchargement d'un dossier. 
if (!is_dir($realPath)) {
  downloadErreur(404, 'Élément introuvable.');
}
if (!class_exists(ZipArchive::class)) {
  downloadErreur(500, 'Extension PHP ZIP non installée.');
}
$zip = new ZipArchive();
$tempFile = tempnam(sys_get_temp_dir(), 'photo_zip_');
if ($tempFile === false) {
  downloadErreur(500, 'Impossible de créer une archive temporaire.');
}
if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
  @unlink($tempFile);
  downloadErreur(500, 'Impossible de créer l’archive ZIP.');
}
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($realPath, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
$nombreFichiers = 0;
$erreurArchive = false;
try {
  foreach ($iterator as $fichier) {
    if (!$fichier->isFile() || !$fichier->isReadable()) {
      continue;
    }
    $chemin = $fichier->getRealPath();
    if ($chemin === false) {
      continue;
    }
    $relatif = substr($chemin, strlen($realPath) + 1);
    if (!$zip->addFile($chemin, $relatif)) {
      $erreurArchive = true;
      break;
    }
    $nombreFichiers++;
  }
} catch (Throwable $e) {
  $erreurArchive = true;
  error_log('Erreur création ZIP photo : ' . $e->getMessage());
}
$zip->close();
if ($erreurArchive || $nombreFichiers === 0) {
  @unlink($tempFile);
  downloadErreur(500, 'Impossible de préparer le téléchargement.');
}
$nomZip = basename($realPath) . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . rawurlencode($nomZip) . '"; filename*=UTF-8\'\'' . rawurlencode($nomZip));
header('Content-Length: ' . filesize($tempFile));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
readfile($tempFile);
@unlink($tempFile);
exit;
