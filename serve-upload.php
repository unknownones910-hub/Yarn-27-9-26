<?php
/**
 * Serve an uploaded WebP as a JPEG.
 *
 * Shared hosts often have no WebP MIME map. Combined with nosniff on
 * /uploads, the browser treats the file as unknown and shows a broken-image
 * icon. This script reads the WebP and outputs image/jpeg so the type no
 * longer depends on Apache.
 *
 * dir= and file= are separate query params so the path slash never has to
 * travel in the query string (that 404s on some hosts).
 */
declare(strict_types=1);

$dir  = (string) ($_GET['dir']  ?? '');
$file = (string) ($_GET['file'] ?? '');

if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $dir)
    || !preg_match('/^[A-Za-z0-9_.-]+\.webp$/i', $file)
    || str_contains($file, '..')) {
    http_response_code(404);
    exit;
}

$uploads = __DIR__ . '/uploads';
$src     = $uploads . '/' . $dir . '/' . $file;

if (!is_file($src) || !is_readable($src)) {
    http_response_code(404);
    exit;
}

$realUploads = realpath($uploads);
$realFile    = realpath($src);
if ($realUploads === false || $realFile === false
    || !str_starts_with($realFile, $realUploads . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit;
}

$jpgPath = preg_replace('/\.webp$/i', '.jpg', $src);

if (!is_file($jpgPath) && function_exists('imagecreatefromwebp')) {
    $im = @imagecreatefromwebp($src);
    if ($im) {
        $w = imagesx($im);
        $h = imagesy($im);
        $out = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($out, 255, 255, 255);
        imagefilledrectangle($out, 0, 0, $w, $h, $white);
        imagecopy($out, $im, 0, 0, 0, 0, $w, $h);
        imagejpeg($out, $jpgPath, 82);
        @chmod($jpgPath, 0644);
        imagedestroy($out);
        imagedestroy($im);
    }
}

if (is_file($jpgPath)) {
    header('Content-Type: image/jpeg');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Content-Length: ' . (string) filesize($jpgPath));
    readfile($jpgPath);
    exit;
}

header('Content-Type: image/webp');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=31536000, immutable');
header('Content-Length: ' . (string) filesize($src));
readfile($src);
