<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function render_page_start(string $title, string $bodyClass = ''): void
{
    $fullTitle = $title === '' ? (string) app_config('app_name') : $title . ' · ' . (string) app_config('app_name');
    $description = 'Cámara del tesoro y registro público de recursos de la alianza ' . (string) app_config('alliance_name') . '.';
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= h($description) ?>">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#050a12">
    <title><?= h($fullTitle) ?></title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="preload" href="assets/images/freecuba-profile.webp" as="image" type="image/webp">
    <script src="assets/boot.js"></script>
    <link rel="stylesheet" href="assets/styles.css?v=6">
    <script src="assets/app.js?v=6" defer></script>
</head>
<body class="<?= h($bodyClass) ?>">
    <div class="intro-splash" data-intro-splash aria-hidden="true">
        <div class="intro-aura"></div>
        <div class="intro-emblem">
            <span class="intro-ring intro-ring-one"></span>
            <span class="intro-ring intro-ring-two"></span>
            <img src="assets/images/freecuba-profile.webp" alt="" width="900" height="900">
        </div>
        <div class="intro-copy">
            <span>Alianza ME58</span>
            <strong>Cámara del Tesoro</strong>
            <small>Verificando el registro del banco</small>
        </div>
        <span class="intro-progress"><i></i></span>
    </div>
<?php
}

function render_site_header(string $active = 'public'): void
{
    $loggedIn = admin_logged_in();
    ?>
<header class="site-header">
    <div class="shell header-inner">
        <a class="brand" href="index.php" aria-label="Ir al registro público">
            <span class="brand-portrait" aria-hidden="true">
                <img src="assets/images/freecuba-profile.webp" alt="" width="900" height="900">
            </span>
            <span class="brand-copy">
                <strong><?= h((string) app_config('alliance_name')) ?></strong>
                <small>Cámara del Tesoro</small>
            </span>
        </a>
        <nav class="main-nav" aria-label="Navegación principal">
            <a class="<?= $active === 'public' ? 'is-active' : '' ?>" href="index.php">Banco público</a>
            <a class="<?= $active === 'announcements' ? 'is-active' : '' ?>" href="announcements.php">Comunicados</a>
            <a class="<?= $active === 'history' ? 'is-active' : '' ?>" href="history.php">Historial</a>
            <?php if ($loggedIn): ?>
                <a class="<?= $active === 'admin' ? 'is-active' : '' ?>" href="admin.php">Administrar</a>
                <a href="logout.php">Salir</a>
            <?php else: ?>
                <a class="<?= $active === 'login' ? 'is-active' : '' ?>" href="login.php">Acceso privado</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<?php
}

function render_flash_message(): void
{
    $flash = pull_flash();
    if ($flash === null) {
        return;
    }
    $type = in_array((string) ($flash['type'] ?? ''), ['success', 'error', 'info'], true)
        ? (string) $flash['type'] : 'info';
    ?>
<div class="flash flash-<?= h($type) ?>" role="status">
    <span><?= h((string) ($flash['message'] ?? '')) ?></span>
    <button type="button" class="flash-close" data-dismiss-flash aria-label="Cerrar aviso">×</button>
</div>
<?php
}

function render_errors(array $errors): void
{
    if ($errors === []) {
        return;
    }
    ?>
<div class="form-errors" role="alert">
    <strong>Revisa lo siguiente:</strong>
    <ul>
        <?php foreach ($errors as $error): ?>
            <li><?= h((string) $error) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php
}

function render_resource_art(string $resource, string $className = ''): void
{
    if (!in_array($resource, resource_keys(), true)) {
        return;
    }
    ?>
<span class="resource-art <?= h($className) ?>" aria-hidden="true">
    <img src="assets/images/resource-<?= h($resource) ?>.webp" alt="" width="640" height="640">
</span>
<?php
}

function render_resource_mark(string $resource): void
{
    $letters = ['food' => 'C', 'wood' => 'M', 'stone' => 'P', 'gold' => 'O'];
    ?>
<span class="resource-mark resource-<?= h($resource) ?>" aria-hidden="true"><?= h($letters[$resource] ?? '?') ?></span>
<?php
}

function render_resource_input(string $resource, $value = '', bool $required = false): void
{
    ?>
<label class="field resource-field">
    <span class="field-label"><?php render_resource_mark($resource); ?> <?= h(resource_title($resource)) ?></span>
    <input
        type="text"
        name="<?= h($resource) ?>"
        value="<?= h((string) $value) ?>"
        inputmode="decimal"
        autocomplete="off"
        placeholder="0 o 1.2M"
        <?= $required ? 'required' : '' ?>
    >
</label>
<?php
}

function render_player_name(string $name, string $className = 'player-name'): void
{
    ?><span class="<?= h($className) ?> notranslate" translate="no" lang="zxx"><?= h($name) ?></span><?php
}

function render_site_footer(): void
{
    ?>
<footer class="site-footer">
    <div class="shell footer-inner">
        <p><strong><?= h((string) app_config('alliance_name')) ?></strong> · Transparencia pública, administración privada.</p>
        <p>Fechas en hora de Florida · Nombres de juego protegidos contra traducción.</p>
    </div>
</footer>
</body>
</html>
<?php
}

/**
 * Editor de un bloque de comunicado.
 *
 * El mismo generador produce los bloques ya guardados y las plantillas que el
 * JavaScript clona al añadir uno nuevo, de modo que no puede haber dos
 * estructuras distintas. Todos los campos son controles HTML normales, con
 * etiquetas inequívocas y atributos data-testid estables: una persona con
 * lector de pantalla o una inteligencia artificial que controle el navegador
 * pueden rellenarlos igual de bien.
 */
function render_announcement_block_editor(int $index, array $block, array $mediaByKind, bool $isTemplate = false): void
{
    $type = (string) ($block['type'] ?? 'paragraph');
    if (!in_array($type, announcement_block_types(), true)) {
        return;
    }
    $key = $isTemplate ? '__IDX__' : (string) $index;
    $name = 'blocks[' . $key . ']';
    $testPrefix = 'block-' . $key . '-';
    $align = (string) ($block['align'] ?? 'left');
    $source = (string) ($block['source'] ?? '');
    ?>
<fieldset class="an-block" data-an-block data-block-type="<?= h($type) ?>">
    <legend><?= h(announcement_block_label($type)) ?></legend>
    <input type="hidden" name="<?= h($name) ?>[type]" value="<?= h($type) ?>" data-an-field="type">

    <?php if (in_array($type, ['heading', 'paragraph', 'list', 'quote', 'callout'], true)): ?>
        <div class="an-format-bar" role="group" aria-label="Formato del texto">
            <button class="small-button" type="button" data-an-wrap="**">Negrita</button>
            <button class="small-button" type="button" data-an-wrap="//">Cursiva</button>
            <button class="small-button" type="button" data-an-wrap="__">Subrayado</button>
            <button class="small-button" type="button" data-an-wrap="~~">Tachado</button>
            <label class="an-format-select"><span>Color</span>
                <select data-an-color>
                    <option value="">—</option>
                    <?php foreach (announcement_text_palette() as $value => $label): ?><option value="<?= h($value) ?>"><?= h($label) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="an-format-select"><span>Resaltado</span>
                <select data-an-highlight>
                    <option value="">—</option>
                    <?php foreach (announcement_highlight_palette() as $value => $label): ?><option value="<?= h($value) ?>"><?= h($label) ?></option><?php endforeach; ?>
                </select>
            </label>
            <button class="small-button" type="button" data-an-link>Enlace</button>
        </div>
    <?php endif; ?>

    <?php if ($type === 'heading'): ?>
        <label class="field"><span class="field-label">Texto del título</span><input type="text" name="<?= h($name) ?>[source]" data-an-text data-testid="<?= h($testPrefix) ?>source" value="<?= h($source) ?>" maxlength="200"></label>
        <div class="an-block-row">
            <label class="field"><span class="field-label">Nivel</span><select name="<?= h($name) ?>[level]"><?php foreach ([2, 3, 4] as $level): ?><option value="<?= $level ?>" <?= (int) ($block['level'] ?? 2) === $level ? 'selected' : '' ?>>H<?= $level ?></option><?php endforeach; ?></select></label>
            <label class="field"><span class="field-label">Alineación</span><select name="<?= h($name) ?>[align]"><?php foreach (announcement_alignments() as $value => $label): ?><option value="<?= h($value) ?>" <?= $align === $value ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
        </div>
    <?php elseif ($type === 'paragraph'): ?>
        <label class="field"><span class="field-label">Texto del párrafo</span><textarea name="<?= h($name) ?>[source]" data-an-text data-testid="<?= h($testPrefix) ?>source" rows="4" maxlength="20000" aria-describedby="announcement-markup-help"><?= h($source) ?></textarea></label>
        <label class="field"><span class="field-label">Alineación</span><select name="<?= h($name) ?>[align]"><?php foreach (announcement_alignments() as $value => $label): ?><option value="<?= h($value) ?>" <?= $align === $value ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
    <?php elseif ($type === 'list'): ?>
        <label class="field"><span class="field-label">Un elemento por línea</span><textarea name="<?= h($name) ?>[source]" data-an-text data-testid="<?= h($testPrefix) ?>source" rows="4" maxlength="20000"><?= h($source) ?></textarea></label>
        <label class="field checkbox-field"><input type="checkbox" name="<?= h($name) ?>[ordered]" value="1" <?= !empty($block['ordered']) ? 'checked' : '' ?>><span>Lista numerada</span></label>
    <?php elseif ($type === 'quote'): ?>
        <label class="field"><span class="field-label">Texto de la cita</span><textarea name="<?= h($name) ?>[source]" data-an-text data-testid="<?= h($testPrefix) ?>source" rows="3" maxlength="4000"><?= h($source) ?></textarea></label>
        <label class="field"><span class="field-label">Autor o fuente</span><input type="text" name="<?= h($name) ?>[cite]" value="<?= h((string) ($block['cite'] ?? '')) ?>" maxlength="120"></label>
    <?php elseif ($type === 'callout'): ?>
        <div class="an-block-row">
            <label class="field"><span class="field-label">Tono</span><select name="<?= h($name) ?>[tone]"><?php foreach (announcement_callout_tones() as $value => $label): ?><option value="<?= h($value) ?>" <?= (string) ($block['tone'] ?? 'info') === $value ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span class="field-label">Título de la caja</span><input type="text" name="<?= h($name) ?>[title]" value="<?= h((string) ($block['title'] ?? '')) ?>" maxlength="160"></label>
        </div>
        <label class="field"><span class="field-label">Texto de la caja</span><textarea name="<?= h($name) ?>[source]" data-an-text data-testid="<?= h($testPrefix) ?>source" rows="3" maxlength="6000"><?= h($source) ?></textarea></label>
    <?php elseif ($type === 'divider'): ?>
        <p class="panel-note">Una línea separadora. No necesita configuración.</p>
    <?php elseif ($type === 'button'): ?>
        <div class="an-block-row">
            <label class="field"><span class="field-label">Texto del botón</span><input type="text" name="<?= h($name) ?>[title]" data-testid="<?= h($testPrefix) ?>title" value="<?= h((string) ($block['title'] ?? '')) ?>" maxlength="80"></label>
            <label class="field"><span class="field-label">Enlace seguro</span><input type="text" name="<?= h($name) ?>[href]" data-testid="<?= h($testPrefix) ?>href" value="<?= h((string) ($block['href'] ?? '')) ?>" maxlength="600" placeholder="https://..."><small>Sólo https, http, mailto o una página del propio sitio.</small></label>
            <label class="field"><span class="field-label">Alineación</span><select name="<?= h($name) ?>[align]"><?php foreach (announcement_alignments() as $value => $label): ?><option value="<?= h($value) ?>" <?= $align === $value ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
        </div>
    <?php elseif ($type === 'image'): ?>
        <div class="an-block-row">
            <label class="field"><span class="field-label">Imagen</span><select name="<?= h($name) ?>[media_id]" data-testid="<?= h($testPrefix) ?>media" required>
                <option value="0">— Elige una imagen de la biblioteca —</option>
                <?php foreach ((array) ($mediaByKind['image'] ?? []) as $mediaRow): ?><option value="<?= (int) $mediaRow['id'] ?>" <?= (int) ($block['media_id'] ?? 0) === (int) $mediaRow['id'] ? 'selected' : '' ?>>#<?= (int) $mediaRow['id'] ?> · <?= h((string) $mediaRow['original_name']) ?></option><?php endforeach; ?>
            </select></label>
            <label class="field"><span class="field-label">Texto alternativo</span><input type="text" name="<?= h($name) ?>[alt]" data-testid="<?= h($testPrefix) ?>alt" value="<?= h((string) ($block['alt'] ?? '')) ?>" maxlength="300"></label>
            <label class="field"><span class="field-label">Pie de imagen</span><input type="text" name="<?= h($name) ?>[caption]" value="<?= h((string) ($block['caption'] ?? '')) ?>" maxlength="300"></label>
            <label class="field"><span class="field-label">Alineación</span><select name="<?= h($name) ?>[align]"><?php foreach (announcement_alignments() as $value => $label): ?><option value="<?= h($value) ?>" <?= $align === $value ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
        </div>
    <?php elseif ($type === 'gallery'): ?>
        <?php $selected = []; foreach ((array) ($block['items'] ?? []) as $item) { $selected[(int) ($item['media_id'] ?? 0)] = true; } ?>
        <label class="field"><span class="field-label">Imágenes de la galería (mantén Ctrl o Cmd para elegir varias)</span>
            <select name="<?= h($name) ?>[gallery_ids][]" data-testid="<?= h($testPrefix) ?>gallery" multiple size="6">
                <?php foreach ((array) ($mediaByKind['image'] ?? []) as $mediaRow): ?><option value="<?= (int) $mediaRow['id'] ?>" <?= isset($selected[(int) $mediaRow['id']]) ? 'selected' : '' ?>>#<?= (int) $mediaRow['id'] ?> · <?= h((string) $mediaRow['original_name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label class="field"><span class="field-label">Pie de la galería</span><input type="text" name="<?= h($name) ?>[caption]" value="<?= h((string) ($block['caption'] ?? '')) ?>" maxlength="300"></label>
    <?php elseif ($type === 'video'): ?>
        <div class="an-block-row">
            <label class="field"><span class="field-label">Video</span><select name="<?= h($name) ?>[media_id]" data-testid="<?= h($testPrefix) ?>media" required>
                <option value="0">— Elige un video de la biblioteca —</option>
                <?php foreach ((array) ($mediaByKind['video'] ?? []) as $mediaRow): ?><option value="<?= (int) $mediaRow['id'] ?>" <?= (int) ($block['media_id'] ?? 0) === (int) $mediaRow['id'] ? 'selected' : '' ?>>#<?= (int) $mediaRow['id'] ?> · <?= h((string) $mediaRow['original_name']) ?></option><?php endforeach; ?>
            </select></label>
            <label class="field"><span class="field-label">Imagen de portada</span><select name="<?= h($name) ?>[poster_media_id]">
                <option value="0">— Sin portada —</option>
                <?php foreach ((array) ($mediaByKind['image'] ?? []) as $mediaRow): ?><option value="<?= (int) $mediaRow['id'] ?>" <?= (int) ($block['poster_media_id'] ?? 0) === (int) $mediaRow['id'] ? 'selected' : '' ?>>#<?= (int) $mediaRow['id'] ?> · <?= h((string) $mediaRow['original_name']) ?></option><?php endforeach; ?>
            </select></label>
            <label class="field"><span class="field-label">Pie del video</span><input type="text" name="<?= h($name) ?>[caption]" value="<?= h((string) ($block['caption'] ?? '')) ?>" maxlength="300"></label>
            <label class="field"><span class="field-label">Subtítulos (.vtt)</span><select name="<?= h($name) ?>[subtitle_media_id]" data-testid="<?= h($testPrefix) ?>subtitle">
                <option value="0">— Sin subtítulos —</option>
                <?php foreach ((array) ($mediaByKind['subtitle'] ?? []) as $mediaRow): ?><option value="<?= (int) $mediaRow['id'] ?>" <?= (int) ($block['subtitle_media_id'] ?? 0) === (int) $mediaRow['id'] ? 'selected' : '' ?>>#<?= (int) $mediaRow['id'] ?> · <?= h((string) $mediaRow['original_name']) ?></option><?php endforeach; ?>
            </select><small>Sube primero el archivo WebVTT a la biblioteca.</small></label>
            <label class="field"><span class="field-label">Nombre de la pista</span><input type="text" name="<?= h($name) ?>[subtitle_label]" value="<?= h((string) ($block['subtitle_label'] ?? 'Español')) ?>" maxlength="60"></label>
            <label class="field"><span class="field-label">Idioma de la pista</span><input type="text" name="<?= h($name) ?>[subtitle_language]" value="<?= h((string) ($block['subtitle_language'] ?? 'es')) ?>" maxlength="12" placeholder="es"></label>
        </div>
    <?php elseif ($type === 'audio'): ?>
        <div class="an-block-row">
            <label class="field"><span class="field-label">Audio</span><select name="<?= h($name) ?>[media_id]" data-testid="<?= h($testPrefix) ?>media" required>
                <option value="0">— Elige un audio de la biblioteca —</option>
                <?php foreach ((array) ($mediaByKind['audio'] ?? []) as $mediaRow): ?><option value="<?= (int) $mediaRow['id'] ?>" <?= (int) ($block['media_id'] ?? 0) === (int) $mediaRow['id'] ? 'selected' : '' ?>>#<?= (int) $mediaRow['id'] ?> · <?= h((string) $mediaRow['original_name']) ?></option><?php endforeach; ?>
            </select></label>
            <label class="field"><span class="field-label">Pie del audio</span><input type="text" name="<?= h($name) ?>[caption]" value="<?= h((string) ($block['caption'] ?? '')) ?>" maxlength="300"></label>
        </div>
    <?php endif; ?>

    <div class="an-block-actions">
        <button class="small-button" type="button" data-an-move="up">↑ Subir</button>
        <button class="small-button" type="button" data-an-move="down">↓ Bajar</button>
        <button class="small-button button-danger" type="button" data-an-remove>Quitar bloque</button>
    </div>
</fieldset>
    <?php
}

function render_announcement_block_templates(array $mediaByKind): void
{
    foreach (announcement_block_types() as $type) {
        ?><template data-an-template="<?= h($type) ?>"><?php
        render_announcement_block_editor(0, ['type' => $type], $mediaByKind, true);
        ?></template><?php
    }
}
