<?php
declare(strict_types=1);

/**
 * Entrega controlada de imágenes, videos y audios.
 *
 * - Los archivos viven en storage/media, con acceso directo denegado.
 * - Aquí nunca se acepta una ruta enviada por el usuario: sólo un identificador
 *   numérico que se traduce a un nombre generado por el servidor.
 * - Un archivo de borrador sólo se sirve a una sesión administrativa.
 * - Se admiten solicitudes HTTP Range para poder avanzar dentro de un video o
 *   un audio sin descargarlo entero.
 */

require_once __DIR__ . '/includes/bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
$record = $id > 0 ? media_find($id) : null;

if ($record === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Archivo no encontrado.');
}

$isPublic = media_is_public($id);
if (!$isPublic && !admin_logged_in()) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Archivo no encontrado.');
}

try {
    $path = media_storage_path($record);
} catch (Throwable $exception) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}
if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Archivo no encontrado.');
}

$size = (int) filesize($path);
$modified = (int) filemtime($path);
$etag = '"' . md5((string) $record['id'] . ':' . $record['storage_name'] . ':' . $modified . ':' . $size) . '"';

if (!headers_sent()) {
    header_remove('Cache-Control');
    header('Content-Type: ' . (string) $record['mime_type']);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $record['original_name']) . '"');
    header('Accept-Ranges: bytes');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Referrer-Policy: no-referrer');
    // Sólo los medios ya publicados se guardan en caché pública.
    header($isPublic ? 'Cache-Control: public, max-age=604800, immutable' : 'Cache-Control: private, no-store');
}

$ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($ifNoneMatch !== '' && strpos($ifNoneMatch, $etag) !== false) {
    http_response_code(304);
    exit;
}
$ifModifiedSince = trim((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
if ($ifModifiedSince !== '' && @strtotime($ifModifiedSince) >= $modified) {
    http_response_code(304);
    exit;
}

$start = 0;
$end = $size - 1;
$range = trim((string) ($_SERVER['HTTP_RANGE'] ?? ''));
$isPartial = false;

if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches) === 1) {
    $rawStart = (string) $matches[1];
    $rawEnd = (string) $matches[2];
    if ($rawStart === '' && $rawEnd === '') {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    if ($rawStart === '') {
        // Sufijo: los últimos N bytes.
        $length = (int) $rawEnd;
        if ($length <= 0) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        $start = max(0, $size - $length);
    } else {
        $start = (int) $rawStart;
        if ($rawEnd !== '') {
            $end = (int) $rawEnd;
        }
    }
    if ($end >= $size) {
        $end = $size - 1;
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    $isPartial = true;
}

$length = $end - $start + 1;
if ($isPartial) {
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}
header('Content-Length: ' . $length);

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
    exit;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

$handle = fopen($path, 'rb');
if ($handle === false) {
    http_response_code(500);
    exit;
}
if ($start > 0) {
    fseek($handle, $start);
}
$remaining = $length;
$chunkSize = 262144;
while ($remaining > 0 && !feof($handle)) {
    $chunk = fread($handle, (int) min($chunkSize, $remaining));
    if ($chunk === false || $chunk === '') {
        break;
    }
    echo $chunk;
    $remaining -= strlen($chunk);
    if (connection_aborted() === 1) {
        break;
    }
    flush();
}
fclose($handle);
