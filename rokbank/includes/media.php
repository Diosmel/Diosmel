<?php
declare(strict_types=1);

/**
 * Archivos de los comunicados: imágenes, videos y audios.
 *
 * Reglas de seguridad aplicadas a cada carga:
 *  1. Se comprueba con is_uploaded_file que el archivo venga de una carga real.
 *  2. El tipo se detecta con finfo leyendo el contenido; nunca se confía en el
 *     nombre ni en el tipo declarado por el navegador.
 *  3. El nombre de almacenamiento lo genera el servidor al azar.
 *  4. La extensión se deduce del MIME realmente detectado.
 *  5. El archivo se guarda en storage/media/, fuera de cualquier ruta
 *     ejecutable y con acceso directo denegado.
 *  6. Se registran tamaño, MIME, nombre original, fecha, autor, texto
 *     alternativo y pie de contenido.
 *
 * SVG se rechaza siempre: cambiarle la extensión no lo hace seguro y este
 * proyecto no incorpora un sanitizador de SVG.
 */

function media_kinds(): array
{
    return ['image' => 'Imagen', 'video' => 'Video', 'audio' => 'Audio', 'subtitle' => 'Subtítulos'];
}

/** MIME realmente admitidos y la extensión que el servidor les asigna. */
function media_allowed_types(): array
{
    return [
        'image' => [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ],
        'video' => [
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
        ],
        'audio' => [
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/x-m4a' => 'm4a',
            'audio/ogg' => 'ogg',
            'audio/wav' => 'wav',
            'audio/x-wav' => 'wav',
            'audio/wave' => 'wav',
        ],
        // Pista de subtítulos WebVTT para los videos. Es texto plano y se
        // sirve como text/vtt, nunca como HTML.
        'subtitle' => [
            'text/vtt' => 'vtt',
            'text/plain' => 'vtt',
        ],
    ];
}

function media_kind_for_mime(string $mime): ?string
{
    foreach (media_allowed_types() as $kind => $types) {
        if (isset($types[$mime])) {
            return $kind;
        }
    }
    return null;
}

function media_directory(): string
{
    return (string) app_config_value('media_directory', dirname((string) app_config('storage_file')) . '/media');
}

/**
 * Contenido del .htaccess de storage/media. Las directivas php_flag sólo
 * existen con mod_php, por eso van dentro de <IfModule>: en un hosting con
 * PHP-FPM una directiva desconocida devolvería error 500.
 */
function media_htaccess_contents(): string
{
    return "Options -Indexes\n"
        . "<IfModule mod_php7.c>\n    php_flag engine off\n</IfModule>\n"
        . "<IfModule mod_php.c>\n    php_flag engine off\n</IfModule>\n"
        . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
}

function media_ensure_directory(): string
{
    $directory = media_directory();
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('No se pudo crear la carpeta storage/media.');
    }
    // Protección redundante por si el ZIP se extrajo sin los archivos ocultos.
    $htaccess = $directory . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, media_htaccess_contents());
    }
    $index = $directory . '/index.php';
    if (!is_file($index)) {
        @file_put_contents($index, "<?php\nhttp_response_code(403);\nexit;\n");
    }
    return $directory;
}

function media_ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $number = (float) $value;
    if ($unit === 'g') {
        return (int) ($number * 1024 * 1024 * 1024);
    }
    if ($unit === 'm') {
        return (int) ($number * 1024 * 1024);
    }
    if ($unit === 'k') {
        return (int) ($number * 1024);
    }
    return (int) $number;
}

/**
 * Límite real de carga: el más bajo entre la configuración de ROKBANK,
 * upload_max_filesize y post_max_size. El panel nunca promete más de esto.
 */
function media_effective_limit(string $kind): int
{
    $limits = (array) app_config_value('media_limits', ['image' => 15728640, 'audio' => 52428800, 'video' => 262144000, 'subtitle' => 1048576]);
    $configured = (int) ($limits[$kind] ?? ($kind === 'subtitle' ? 1048576 : 15728640));
    $candidates = [$configured];
    $upload = media_ini_bytes((string) ini_get('upload_max_filesize'));
    $post = media_ini_bytes((string) ini_get('post_max_size'));
    if ($upload > 0) {
        $candidates[] = $upload;
    }
    if ($post > 0) {
        $candidates[] = $post;
    }
    return max(0, (int) min($candidates));
}

function media_limit_report(): array
{
    $report = [];
    foreach (array_keys(media_kinds()) as $kind) {
        $limits = (array) app_config_value('media_limits', []);
        $report[$kind] = [
            'configured' => (int) ($limits[$kind] ?? 0),
            'effective' => media_effective_limit($kind),
        ];
    }
    $report['upload_max_filesize'] = (string) ini_get('upload_max_filesize');
    $report['post_max_size'] = (string) ini_get('post_max_size');
    return $report;
}

function media_format_bytes(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2, ',', '.') . ' GiB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', '.') . ' MiB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0, ',', '.') . ' KiB';
    }
    return $bytes . ' B';
}

function media_table(): string
{
    return mysql_table_name('announcement_media');
}

function media_row_to_array(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'announcement_id' => $row['announcement_id'] === null ? 0 : (int) $row['announcement_id'],
        'kind' => (string) $row['kind'],
        'storage_name' => (string) $row['storage_name'],
        'original_name' => (string) $row['original_name'],
        'mime_type' => (string) $row['mime_type'],
        'size_bytes' => (int) $row['size_bytes'],
        'width' => $row['width'] === null ? 0 : (int) $row['width'],
        'height' => $row['height'] === null ? 0 : (int) $row['height'],
        'duration_seconds' => $row['duration_seconds'] === null ? 0 : (int) $row['duration_seconds'],
        'alt_text' => (string) $row['alt_text'],
        'caption' => (string) $row['caption'],
        'created_by' => (string) $row['created_by'],
        'created_at' => (string) $row['created_at'],
    ];
}

function media_find(int $id): ?array
{
    if (!announcements_available() || $id <= 0) {
        return null;
    }
    $table = media_table();
    $statement = mysql_connection()->prepare("SELECT * FROM {$table} WHERE `id` = ? LIMIT 1");
    $statement->execute([$id]);
    $row = $statement->fetch();
    return is_array($row) ? media_row_to_array($row) : null;
}

function media_records_by_ids(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
        return $id > 0;
    })));
    if ($ids === [] || !announcements_available()) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $table = media_table();
    $statement = mysql_connection()->prepare("SELECT * FROM {$table} WHERE `id` IN ({$placeholders})");
    $statement->execute($ids);
    $records = [];
    foreach ($statement->fetchAll() as $row) {
        $record = media_row_to_array($row);
        $records[(int) $record['id']] = $record;
    }
    return $records;
}

function media_list(int $limit = 120, string $kind = ''): array
{
    if (!announcements_available()) {
        return [];
    }
    $table = media_table();
    $limit = max(1, min(500, $limit));
    if ($kind !== '' && isset(media_kinds()[$kind])) {
        $statement = mysql_connection()->prepare("SELECT * FROM {$table} WHERE `kind` = ? ORDER BY `id` DESC LIMIT {$limit}");
        $statement->execute([$kind]);
    } else {
        $statement = mysql_connection()->query("SELECT * FROM {$table} ORDER BY `id` DESC LIMIT {$limit}");
    }
    $rows = [];
    foreach ($statement->fetchAll() as $row) {
        $rows[] = media_row_to_array($row);
    }
    return $rows;
}

function media_storage_path(array $record): string
{
    // El nombre viene siempre del servidor; aun así se valida para que una
    // fila manipulada no pueda salir de la carpeta de medios.
    $name = (string) $record['storage_name'];
    if (!preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/', $name)) {
        throw new RuntimeException('El nombre de archivo guardado no es válido.');
    }
    return media_directory() . '/' . $name;
}

function media_public_url(array $record): string
{
    return 'media.php?id=' . (int) $record['id'];
}

function media_image_tag(array $record, string $alt): string
{
    $width = (int) $record['width'];
    $height = (int) $record['height'];
    return '<img class="an-image" src="' . h(media_public_url($record)) . '" alt="' . h($alt) . '"'
        . ($width > 0 ? ' width="' . $width . '"' : '')
        . ($height > 0 ? ' height="' . $height . '"' : '')
        . ' loading="lazy" decoding="async">';
}

/**
 * Un archivo es público únicamente cuando algún comunicado publicado lo usa.
 * Los archivos de un borrador sólo se sirven a una sesión administrativa.
 */
function media_is_public(int $id): bool
{
    if (!announcements_available() || $id <= 0) {
        return false;
    }
    $links = mysql_table_name('announcement_media_links');
    $announcements = mysql_table_name('announcements');
    $statement = mysql_connection()->prepare(
        "SELECT 1 FROM {$links} AS l INNER JOIN {$announcements} AS a ON a.`id` = l.`announcement_id`
         WHERE l.`media_id` = ? AND a.`status` = 'published' LIMIT 1"
    );
    $statement->execute([$id]);
    return $statement->fetchColumn() !== false;
}

function media_reference_count(int $id): int
{
    if (!announcements_available() || $id <= 0) {
        return 0;
    }
    $links = mysql_table_name('announcement_media_links');
    $statement = mysql_connection()->prepare("SELECT COUNT(*) FROM {$links} WHERE `media_id` = ?");
    $statement->execute([$id]);
    return (int) $statement->fetchColumn();
}

function media_upload_error_message(int $code): string
{
    $messages = [
        UPLOAD_ERR_INI_SIZE => 'El archivo supera el límite de upload_max_filesize del servidor.',
        UPLOAD_ERR_FORM_SIZE => 'El archivo supera el límite indicado por el formulario.',
        UPLOAD_ERR_PARTIAL => 'La carga se interrumpió antes de terminar.',
        UPLOAD_ERR_NO_FILE => 'No se seleccionó ningún archivo.',
        UPLOAD_ERR_NO_TMP_DIR => 'Al servidor le falta la carpeta temporal de PHP.',
        UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo escribir el archivo en disco.',
        UPLOAD_ERR_EXTENSION => 'Una extensión de PHP detuvo la carga.',
    ];
    return $messages[$code] ?? 'No se pudo cargar el archivo.';
}

/**
 * Procesa una carga individual. Devuelve ['errors' => [], 'id' => int].
 */
function media_store_upload(array $file, string $altText = '', string $caption = ''): array
{
    if (!announcements_available()) {
        return ['errors' => ['Los comunicados necesitan el almacenamiento MySQL.'], 'id' => 0];
    }
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        return ['errors' => [media_upload_error_message($error)], 'id' => 0];
    }
    $temporary = (string) ($file['tmp_name'] ?? '');
    if ($temporary === '' || !is_uploaded_file($temporary)) {
        return ['errors' => ['El archivo recibido no procede de una carga válida.'], 'id' => 0];
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        @unlink($temporary);
        return ['errors' => ['El archivo está vacío.'], 'id' => 0];
    }

    if (!class_exists('finfo')) {
        @unlink($temporary);
        return ['errors' => ['El servidor necesita la extensión fileinfo de PHP para validar las cargas.'], 'id' => 0];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($temporary);
    if (stripos($mime, 'svg') !== false || $mime === 'image/svg+xml') {
        @unlink($temporary);
        return ['errors' => ['Los archivos SVG no se admiten por seguridad.'], 'id' => 0];
    }
    // text/plain sólo se acepta cuando el archivo es realmente un WebVTT.
    if ($mime === 'text/plain' || $mime === 'text/vtt') {
        $head = (string) @file_get_contents($temporary, false, null, 0, 64);
        if (strpos(ltrim($head, "\xEF\xBB\xBF \t\r\n"), 'WEBVTT') !== 0) {
            @unlink($temporary);
            return ['errors' => ['Un archivo de texto sólo se admite si es una pista de subtítulos WebVTT que empiece por «WEBVTT».'], 'id' => 0];
        }
        $mime = 'text/vtt';
    }
    $kind = media_kind_for_mime($mime);
    if ($kind === null) {
        @unlink($temporary);
        return ['errors' => ['El contenido real del archivo (' . $mime . ') no corresponde a una imagen, video o audio admitido.'], 'id' => 0];
    }
    // Espacio disponible: se exige el doble del archivo como margen para que
    // una carga grande no llene el disco del hosting a medias.
    $free = @disk_free_space(dirname(media_directory()));
    if (is_float($free) && $free > 0 && $free < ($size * 2)) {
        @unlink($temporary);
        return ['errors' => [
            'No hay espacio suficiente en el servidor: quedan ' . media_format_bytes((int) $free)
            . ' y el archivo ocupa ' . media_format_bytes($size) . '.',
        ], 'id' => 0];
    }
    $limit = media_effective_limit($kind);
    if ($limit > 0 && $size > $limit) {
        @unlink($temporary);
        return ['errors' => [
            'El archivo pesa ' . media_format_bytes($size) . ' y el límite efectivo para ' . strtolower(media_kinds()[$kind])
            . ' es ' . media_format_bytes($limit) . '.',
        ], 'id' => 0];
    }

    $width = 0;
    $height = 0;
    if ($kind === 'image') {
        $dimensions = @getimagesize($temporary);
        if (!is_array($dimensions) || (int) $dimensions[0] <= 0) {
            @unlink($temporary);
            return ['errors' => ['El archivo dice ser una imagen pero no se pudo leer su contenido.'], 'id' => 0];
        }
        $width = (int) $dimensions[0];
        $height = (int) $dimensions[1];
        // Toda imagen necesita texto alternativo para que la página sea
        // legible con lector de pantalla y para que se entienda sin verla.
        if (trim($altText) === '') {
            @unlink($temporary);
            return ['errors' => ['Escribe el texto alternativo antes de subir una imagen: describe brevemente lo que se ve.'], 'id' => 0];
        }
    }

    $extension = media_allowed_types()[$kind][$mime];
    $directory = media_ensure_directory();
    $storageName = bin2hex(random_bytes(16)) . '.' . $extension;
    $destination = $directory . '/' . $storageName;
    if (!move_uploaded_file($temporary, $destination)) {
        return ['errors' => ['No se pudo guardar el archivo en storage/media. Revisa los permisos y el espacio disponible.'], 'id' => 0];
    }
    @chmod($destination, 0640);

    $originalName = text_limit((string) ($file['name'] ?? 'archivo'), 255);
    $originalName = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $originalName);
    $table = media_table();
    try {
        $statement = mysql_connection()->prepare(
            "INSERT INTO {$table} (`announcement_id`, `kind`, `storage_name`, `original_name`, `mime_type`,
             `size_bytes`, `width`, `height`, `duration_seconds`, `alt_text`, `caption`, `created_by`, `created_at`)
             VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?)"
        );
        $statement->execute([
            $kind, $storageName, $originalName, $mime, $size,
            $width > 0 ? $width : null, $height > 0 ? $height : null,
            text_limit($altText, 300), text_limit($caption, 300),
            announcement_current_author(), gmdate('c'),
        ]);
        $id = (int) mysql_connection()->lastInsertId();
    } catch (Throwable $exception) {
        @unlink($destination);
        return ['errors' => ['No se pudo registrar el archivo: ' . $exception->getMessage()], 'id' => 0];
    }
    media_cleanup_temporary_files();
    announcement_audit('upload', $id, 'Se cargó ' . strtolower(media_kinds()[$kind]) . ' «' . $originalName . '» (' . media_format_bytes($size) . ').');
    return ['errors' => [], 'id' => $id];
}

/**
 * Acepta varios archivos del mismo campo de formulario.
 */
function media_store_uploads(array $files, string $altText = '', string $caption = ''): array
{
    $errors = [];
    $ids = [];
    $names = isset($files['name']) && is_array($files['name']) ? $files['name'] : [];
    foreach (array_keys($names) as $index) {
        if ((int) $files['error'][$index] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $result = media_store_upload([
            'name' => $files['name'][$index],
            'type' => $files['type'][$index] ?? '',
            'tmp_name' => $files['tmp_name'][$index],
            'error' => $files['error'][$index],
            'size' => $files['size'][$index],
        ], $altText, $caption);
        if ($result['errors'] !== []) {
            $errors = array_merge($errors, array_map(static function (string $message) use ($files, $index): string {
                return text_limit((string) $files['name'][$index], 80) . ': ' . $message;
            }, $result['errors']));
            continue;
        }
        $ids[] = (int) $result['id'];
    }
    return ['errors' => array_values(array_unique($errors)), 'ids' => $ids];
}

/**
 * Limpieza de restos de cargas interrumpidas: archivos temporales propios que
 * lleven más de un día en la carpeta de medios.
 */
function media_cleanup_temporary_files(): int
{
    $files = glob(media_directory() . '/*.tmp-*');
    if (!is_array($files)) {
        return 0;
    }
    $removed = 0;
    foreach ($files as $file) {
        if (is_file($file) && time() - (int) @filemtime($file) > 86400) {
            @unlink($file);
            $removed++;
        }
    }
    return $removed;
}

function media_update_description(int $id, string $altText, string $caption): array
{
    $record = media_find($id);
    if ($record === null) {
        return ['El archivo ya no existe.'];
    }
    $table = media_table();
    $statement = mysql_connection()->prepare("UPDATE {$table} SET `alt_text` = ?, `caption` = ? WHERE `id` = ?");
    $statement->execute([text_limit($altText, 300), text_limit($caption, 300), $id]);
    announcement_audit('update', $id, 'Se actualizó la descripción del archivo «' . (string) $record['original_name'] . '».');
    return [];
}

/**
 * Borra del disco y de la tabla los archivos indicados que ya no estén
 * referenciados por ningún comunicado. Un archivo compartido nunca se borra.
 */
function media_cleanup_orphans(array $ids): int
{
    if (!announcements_available()) {
        return 0;
    }
    $removed = 0;
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id <= 0 || media_reference_count($id) > 0) {
            continue;
        }
        $record = media_find($id);
        if ($record === null) {
            continue;
        }
        try {
            $path = media_storage_path($record);
            if (is_file($path)) {
                @unlink($path);
            }
        } catch (Throwable $exception) {
            error_log('[Banco ME58] No se pudo borrar un archivo de medios: ' . $exception->getMessage());
        }
        $statement = mysql_connection()->prepare('DELETE FROM ' . media_table() . ' WHERE `id` = ?');
        $statement->execute([$id]);
        $removed++;
    }
    return $removed;
}

function media_delete(int $id): array
{
    $record = media_find($id);
    if ($record === null) {
        return ['El archivo ya no existe.'];
    }
    if (media_reference_count($id) > 0) {
        return ['Este archivo se usa en un comunicado. Quítalo primero de ese comunicado.'];
    }
    media_cleanup_orphans([$id]);
    announcement_audit('delete', $id, 'Se eliminó el archivo «' . (string) $record['original_name'] . '».');
    return [];
}
