<?php
declare(strict_types=1);

/**
 * Comunicados de la alianza.
 *
 * DECISIÓN DE SEGURIDAD
 * ---------------------
 * Nunca se almacena ni se publica HTML escrito por una persona. El editor
 * guarda un JSON estructurado con bloques y marcas permitidas; el servidor
 * valida ese JSON y genera el HTML con plantillas conocidas. Así se puede
 * escribir con colores, resaltados, emojis y medios sin aceptar <script>,
 * atributos on*, URLs javascript:, iframes arbitrarios ni estilos capaces de
 * romper u ocultar la página.
 *
 * Los colores no se guardan como valores libres sino como nombres de una
 * paleta aprobada que se traduce a clases CSS. Esto evita por completo los
 * atributos style= en línea, que la Content Security Policy del sitio bloquea.
 */

function announcement_status_keys(): array
{
    return ['draft', 'published', 'archived'];
}

function announcement_status_label(string $status): string
{
    $labels = ['draft' => 'Borrador', 'published' => 'Publicado', 'archived' => 'Archivado'];
    return $labels[$status] ?? $status;
}

function announcement_categories(): array
{
    return [
        'anuncio' => 'Anuncio',
        'reglas' => 'Reglas',
        'resultados' => 'Resultados',
        'banco' => 'Banco',
        'recordatorio' => 'Recordatorio',
        'otra' => 'Otra',
    ];
}

/** Paleta aprobada de color de texto. */
function announcement_text_palette(): array
{
    return [
        'rojo' => 'Rojo', 'naranja' => 'Naranja', 'ambar' => 'Ámbar', 'verde' => 'Verde',
        'esmeralda' => 'Esmeralda', 'cian' => 'Cian', 'azul' => 'Azul', 'indigo' => 'Índigo',
        'violeta' => 'Violeta', 'rosa' => 'Rosa', 'oro' => 'Oro', 'gris' => 'Gris', 'blanco' => 'Blanco',
    ];
}

/** Paleta aprobada de resaltado o color de fondo. */
function announcement_highlight_palette(): array
{
    return [
        'amarillo' => 'Amarillo', 'verde' => 'Verde', 'cian' => 'Cian', 'azul' => 'Azul',
        'rosa' => 'Rosa', 'naranja' => 'Naranja', 'rojo' => 'Rojo', 'gris' => 'Gris',
    ];
}

function announcement_alignments(): array
{
    return ['left' => 'Izquierda', 'center' => 'Centro', 'right' => 'Derecha'];
}

function announcement_callout_tones(): array
{
    return ['info' => 'Información', 'success' => 'Confirmación', 'warning' => 'Aviso', 'danger' => 'Urgente'];
}

function announcement_block_types(): array
{
    return ['heading', 'paragraph', 'list', 'quote', 'callout', 'divider', 'button', 'image', 'gallery', 'video', 'audio'];
}

function announcement_block_label(string $type): string
{
    $labels = [
        'heading' => 'Título', 'paragraph' => 'Párrafo', 'list' => 'Lista', 'quote' => 'Cita',
        'callout' => 'Caja destacada', 'divider' => 'Separador', 'button' => 'Botón o enlace',
        'image' => 'Imagen', 'gallery' => 'Galería', 'video' => 'Video', 'audio' => 'Audio',
    ];
    return $labels[$type] ?? $type;
}

/* =========================================================================
 * Texto enriquecido: marcado seguro -> estructura -> HTML
 * =========================================================================
 * El administrador escribe en un campo de texto normal, accesible y
 * compatible con cualquier alfabeto Unicode:
 *
 *   **negrita**   //cursiva//   __subrayado__   ~~tachado~~
 *   [texto visible](https://enlace)
 *   {c:rojo}texto de color{/c}        {f:amarillo}texto resaltado{/f}
 *
 * El resultado se guarda como una lista de fragmentos con marcas booleanas.
 * ========================================================================= */

function announcement_inline_pattern(): string
{
    return '/'
        . '\[([^\]\n]{1,300})\]\(([^)\s]{1,600})\)'      // 1,2  enlace
        . '|\*\*((?:(?!\*\*).)+)\*\*'                    // 3    negrita
        . '|\/\/((?:(?!\/\/).)+)\/\/'                    // 4    cursiva
        . '|__((?:(?!__).)+)__'                          // 5    subrayado
        . '|~~((?:(?!~~).)+)~~'                          // 6    tachado
        . '|\{c:([a-z]{2,20})\}(.*?)\{\/c\}'             // 7,8  color de texto
        . '|\{f:([a-z]{2,20})\}(.*?)\{\/f\}'             // 9,10 resaltado
        . '/us';
}

/**
 * Sólo se aceptan protocolos seguros. Un enlace relativo dentro del propio
 * sitio también es válido, pero nunca "javascript:", "data:" ni "//otro.sitio".
 */
function announcement_safe_url(string $url): ?string
{
    $url = trim($url);
    if ($url === '' || text_length($url) > 600) {
        return null;
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
        return null;
    }
    if (preg_match('#^(https?://|mailto:)#i', $url)) {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https', 'mailto'], true)) {
            return null;
        }
        return $url;
    }
    if (strpos($url, '//') === 0 || strpos($url, ':') !== false || strpos($url, '\\') !== false) {
        return null;
    }
    // Enlace relativo al propio sitio, por ejemplo "history.php?id=evt_1".
    if (preg_match('#^[A-Za-z0-9._~\-/]+(?:\?[A-Za-z0-9._~\-/=&%+]*)?(?:\#[A-Za-z0-9._~\-]*)?$#', $url)) {
        return $url;
    }
    return null;
}

function announcement_parse_inline(string $text, array $marks = []): array
{
    $spans = [];
    $offset = 0;
    $matches = [];
    if (preg_match(announcement_inline_pattern(), $text, $matches, PREG_OFFSET_CAPTURE)) {
        $start = (int) $matches[0][1];
        $length = strlen((string) $matches[0][0]);
        if ($start > 0) {
            $spans = array_merge($spans, announcement_leaf_spans(substr($text, 0, $start), $marks));
        }
        if (isset($matches[1]) && $matches[1][1] !== -1) {
            $href = announcement_safe_url((string) $matches[2][0]);
            $inner = (string) $matches[1][0];
            $childMarks = $marks;
            if ($href !== null) {
                $childMarks['href'] = $href;
            }
            $spans = array_merge($spans, announcement_parse_inline($inner, $childMarks));
        } elseif (isset($matches[3]) && $matches[3][1] !== -1) {
            $spans = array_merge($spans, announcement_parse_inline((string) $matches[3][0], array_merge($marks, ['bold' => true])));
        } elseif (isset($matches[4]) && $matches[4][1] !== -1) {
            $spans = array_merge($spans, announcement_parse_inline((string) $matches[4][0], array_merge($marks, ['italic' => true])));
        } elseif (isset($matches[5]) && $matches[5][1] !== -1) {
            $spans = array_merge($spans, announcement_parse_inline((string) $matches[5][0], array_merge($marks, ['underline' => true])));
        } elseif (isset($matches[6]) && $matches[6][1] !== -1) {
            $spans = array_merge($spans, announcement_parse_inline((string) $matches[6][0], array_merge($marks, ['strike' => true])));
        } elseif (isset($matches[7]) && $matches[7][1] !== -1) {
            $color = (string) $matches[7][0];
            $childMarks = $marks;
            if (isset(announcement_text_palette()[$color])) {
                $childMarks['color'] = $color;
            }
            $spans = array_merge($spans, announcement_parse_inline((string) $matches[8][0], $childMarks));
        } elseif (isset($matches[9]) && $matches[9][1] !== -1) {
            $highlight = (string) $matches[9][0];
            $childMarks = $marks;
            if (isset(announcement_highlight_palette()[$highlight])) {
                $childMarks['highlight'] = $highlight;
            }
            $spans = array_merge($spans, announcement_parse_inline((string) $matches[10][0], $childMarks));
        }
        $offset = $start + $length;
        $rest = substr($text, $offset);
        if ($rest !== '' && $rest !== false) {
            $spans = array_merge($spans, announcement_parse_inline($rest, $marks));
        }
        return $spans;
    }
    return announcement_leaf_spans($text, $marks);
}

function announcement_leaf_spans(string $text, array $marks): array
{
    if ($text === '') {
        return [];
    }
    $span = ['text' => $text];
    foreach (['bold', 'italic', 'underline', 'strike'] as $flag) {
        if (!empty($marks[$flag])) {
            $span[$flag] = true;
        }
    }
    if (!empty($marks['color']) && isset(announcement_text_palette()[(string) $marks['color']])) {
        $span['color'] = (string) $marks['color'];
    }
    if (!empty($marks['highlight']) && isset(announcement_highlight_palette()[(string) $marks['highlight']])) {
        $span['highlight'] = (string) $marks['highlight'];
    }
    if (!empty($marks['href'])) {
        $span['href'] = (string) $marks['href'];
    }
    return [$span];
}

function announcement_render_spans(array $spans): string
{
    $html = '';
    foreach ($spans as $span) {
        if (!is_array($span) || !isset($span['text'])) {
            continue;
        }
        $text = h((string) $span['text']);
        $text = str_replace("\n", '<br>', $text);
        if (!empty($span['bold'])) {
            $text = '<strong>' . $text . '</strong>';
        }
        if (!empty($span['italic'])) {
            $text = '<em>' . $text . '</em>';
        }
        if (!empty($span['underline'])) {
            $text = '<u>' . $text . '</u>';
        }
        if (!empty($span['strike'])) {
            $text = '<s>' . $text . '</s>';
        }
        $classes = [];
        if (!empty($span['color']) && isset(announcement_text_palette()[(string) $span['color']])) {
            $classes[] = 'an-c-' . (string) $span['color'];
        }
        if (!empty($span['highlight']) && isset(announcement_highlight_palette()[(string) $span['highlight']])) {
            $classes[] = 'an-h-' . (string) $span['highlight'];
        }
        if ($classes !== []) {
            $text = '<span class="' . h(implode(' ', $classes)) . '">' . $text . '</span>';
        }
        if (!empty($span['href'])) {
            $href = announcement_safe_url((string) $span['href']);
            if ($href !== null) {
                $external = (bool) preg_match('#^https?://#i', $href);
                $text = '<a class="an-link" href="' . h($href) . '"'
                    . ($external ? ' target="_blank" rel="noopener noreferrer nofollow"' : '')
                    . '>' . $text . '</a>';
            }
        }
        $html .= $text;
    }
    return $html;
}

function announcement_spans_plain_text(array $spans): string
{
    $text = '';
    foreach ($spans as $span) {
        if (is_array($span) && isset($span['text'])) {
            $text .= (string) $span['text'];
        }
    }
    return $text;
}

/* =========================================================================
 * Validación de los bloques que llegan del editor
 * ========================================================================= */

function announcement_normalize_blocks($blocks): array
{
    $blocks = is_array($blocks) ? $blocks : [];
    // Tope defensivo: un comunicado legítimo no necesita más bloques y así una
    // petición manipulada no puede agotar la memoria del servidor.
    if (count($blocks) > 300) {
        $blocks = array_slice($blocks, 0, 300);
    }
    $normalized = [];
    foreach ($blocks as $block) {
        if (!is_array($block)) {
            continue;
        }
        $type = (string) ($block['type'] ?? '');
        if (!in_array($type, announcement_block_types(), true)) {
            continue;
        }
        $align = in_array((string) ($block['align'] ?? ''), array_keys(announcement_alignments()), true)
            ? (string) $block['align'] : 'left';
        $source = (string) ($block['source'] ?? '');
        $clean = ['type' => $type];

        if ($type === 'heading') {
            $clean['level'] = in_array((int) ($block['level'] ?? 2), [2, 3, 4], true) ? (int) $block['level'] : 2;
            $clean['align'] = $align;
            $clean['source'] = text_limit(str_replace(["\r", "\n"], ' ', $source), 200);
            $clean['spans'] = announcement_parse_inline($clean['source']);
        } elseif ($type === 'paragraph') {
            $clean['align'] = $align;
            $clean['source'] = text_limit(str_replace("\r", '', $source), 20000);
            $clean['spans'] = announcement_parse_inline($clean['source']);
        } elseif ($type === 'list') {
            $clean['ordered'] = !empty($block['ordered']);
            $clean['source'] = text_limit(str_replace("\r", '', $source), 20000);
            $clean['items'] = [];
            foreach (preg_split('/\n/u', $clean['source']) as $line) {
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }
                $line = (string) preg_replace('/^[-*\x{2022}]\s*/u', '', $line);
                $clean['items'][] = announcement_parse_inline($line);
            }
        } elseif ($type === 'quote') {
            $clean['source'] = text_limit(str_replace("\r", '', $source), 4000);
            $clean['spans'] = announcement_parse_inline($clean['source']);
            $clean['cite'] = text_limit((string) ($block['cite'] ?? ''), 120);
        } elseif ($type === 'callout') {
            $clean['tone'] = in_array((string) ($block['tone'] ?? ''), array_keys(announcement_callout_tones()), true)
                ? (string) $block['tone'] : 'info';
            $clean['title'] = text_limit((string) ($block['title'] ?? ''), 160);
            $clean['source'] = text_limit(str_replace("\r", '', $source), 6000);
            $clean['spans'] = announcement_parse_inline($clean['source']);
        } elseif ($type === 'divider') {
            $clean = ['type' => 'divider'];
        } elseif ($type === 'button') {
            $label = text_limit((string) ($block['title'] ?? $source), 80);
            $href = announcement_safe_url((string) ($block['href'] ?? ''));
            if ($label === '' || $href === null) {
                continue;
            }
            $clean['title'] = $label;
            $clean['href'] = $href;
            $clean['align'] = $align;
        } elseif ($type === 'image') {
            $mediaId = (int) ($block['media_id'] ?? 0);
            if ($mediaId <= 0) {
                continue;
            }
            $clean['media_id'] = $mediaId;
            $clean['alt'] = text_limit((string) ($block['alt'] ?? ''), 300);
            $clean['caption'] = text_limit((string) ($block['caption'] ?? ''), 300);
            $clean['align'] = $align;
        } elseif ($type === 'gallery') {
            $items = [];
            // El editor envía la galería como una lista de identificadores
            // seleccionados; el formato guardado siempre es una lista de
            // elementos con su texto alternativo y su pie.
            foreach ((array) ($block['gallery_ids'] ?? []) as $galleryId) {
                $galleryId = (int) $galleryId;
                if ($galleryId > 0) {
                    $items[] = ['media_id' => $galleryId, 'alt' => '', 'caption' => ''];
                }
            }
            foreach ((array) ($block['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $mediaId = (int) ($item['media_id'] ?? 0);
                if ($mediaId <= 0) {
                    continue;
                }
                $items[] = [
                    'media_id' => $mediaId,
                    'alt' => text_limit((string) ($item['alt'] ?? ''), 300),
                    'caption' => text_limit((string) ($item['caption'] ?? ''), 300),
                ];
            }
            if ($items === []) {
                continue;
            }
            $unique = [];
            foreach ($items as $item) {
                $unique[(int) $item['media_id']] = $item;
            }
            $clean['items'] = array_values($unique);
            $clean['caption'] = text_limit((string) ($block['caption'] ?? ''), 300);
        } elseif ($type === 'video' || $type === 'audio') {
            $mediaId = (int) ($block['media_id'] ?? 0);
            if ($mediaId <= 0) {
                continue;
            }
            $clean['media_id'] = $mediaId;
            $clean['caption'] = text_limit((string) ($block['caption'] ?? ''), 300);
            if ($type === 'video') {
                $poster = (int) ($block['poster_media_id'] ?? 0);
                $clean['poster_media_id'] = $poster > 0 ? $poster : 0;
                $subtitle = (int) ($block['subtitle_media_id'] ?? 0);
                $clean['subtitle_media_id'] = $subtitle > 0 ? $subtitle : 0;
                $clean['subtitle_label'] = text_limit((string) ($block['subtitle_label'] ?? 'Español'), 60);
                $language = strtolower(trim((string) ($block['subtitle_language'] ?? 'es')));
                $clean['subtitle_language'] = preg_match('/^[a-z]{2}(?:-[a-z0-9]{2,8})?$/', $language) ? $language : 'es';
            }
        }
        $normalized[] = $clean;
    }
    return $normalized;
}

function announcement_content_plain_text(array $content): string
{
    $text = '';
    foreach ((array) ($content['blocks'] ?? []) as $block) {
        if (!is_array($block)) {
            continue;
        }
        if (isset($block['spans']) && is_array($block['spans'])) {
            $text .= announcement_spans_plain_text($block['spans']) . "\n";
        }
        foreach (['title', 'caption', 'alt', 'cite'] as $key) {
            if (!empty($block[$key])) {
                $text .= (string) $block[$key] . "\n";
            }
        }
        foreach ((array) ($block['items'] ?? []) as $item) {
            if (is_array($item) && isset($item['media_id'])) {
                $text .= (string) ($item['caption'] ?? '') . "\n";
            } elseif (is_array($item)) {
                $text .= announcement_spans_plain_text($item) . "\n";
            }
        }
    }
    return trim($text);
}

function announcement_media_ids_in_content(array $content): array
{
    $ids = [];
    foreach ((array) ($content['blocks'] ?? []) as $block) {
        if (!is_array($block)) {
            continue;
        }
        if (!empty($block['media_id'])) {
            $ids[(int) $block['media_id']] = true;
        }
        if (!empty($block['poster_media_id'])) {
            $ids[(int) $block['poster_media_id']] = true;
        }
        if (!empty($block['subtitle_media_id'])) {
            $ids[(int) $block['subtitle_media_id']] = true;
        }
        foreach ((array) ($block['items'] ?? []) as $item) {
            if (is_array($item) && !empty($item['media_id'])) {
                $ids[(int) $item['media_id']] = true;
            }
        }
    }
    return array_keys($ids);
}

/* =========================================================================
 * Plantillas de salida
 * ========================================================================= */

function announcement_render_blocks(array $content): string
{
    $html = '';
    $mediaIds = announcement_media_ids_in_content($content);
    $media = $mediaIds === [] ? [] : media_records_by_ids($mediaIds);
    foreach ((array) ($content['blocks'] ?? []) as $block) {
        if (!is_array($block)) {
            continue;
        }
        $type = (string) ($block['type'] ?? '');
        $alignClass = isset($block['align']) && $block['align'] !== 'left'
            ? ' an-align-' . h((string) $block['align']) : '';
        if ($type === 'heading') {
            $level = (int) ($block['level'] ?? 2);
            $tag = 'h' . max(2, min(4, $level));
            $html .= '<' . $tag . ' class="an-heading' . $alignClass . '">'
                . announcement_render_spans((array) $block['spans']) . '</' . $tag . '>';
        } elseif ($type === 'paragraph') {
            $html .= '<p class="an-paragraph' . $alignClass . '">'
                . announcement_render_spans((array) $block['spans']) . '</p>';
        } elseif ($type === 'list') {
            $tag = !empty($block['ordered']) ? 'ol' : 'ul';
            $html .= '<' . $tag . ' class="an-list">';
            foreach ((array) ($block['items'] ?? []) as $item) {
                $html .= '<li>' . announcement_render_spans((array) $item) . '</li>';
            }
            $html .= '</' . $tag . '>';
        } elseif ($type === 'quote') {
            $html .= '<blockquote class="an-quote"><p>' . announcement_render_spans((array) $block['spans']) . '</p>';
            if (!empty($block['cite'])) {
                $html .= '<cite>' . h((string) $block['cite']) . '</cite>';
            }
            $html .= '</blockquote>';
        } elseif ($type === 'callout') {
            $tone = (string) ($block['tone'] ?? 'info');
            $html .= '<aside class="an-callout an-callout-' . h($tone) . '">';
            if (!empty($block['title'])) {
                $html .= '<strong>' . h((string) $block['title']) . '</strong>';
            }
            $html .= '<p>' . announcement_render_spans((array) $block['spans']) . '</p></aside>';
        } elseif ($type === 'divider') {
            $html .= '<hr class="an-divider">';
        } elseif ($type === 'button') {
            $href = announcement_safe_url((string) $block['href']);
            if ($href === null) {
                continue;
            }
            $external = (bool) preg_match('#^https?://#i', $href);
            $html .= '<p class="an-button-row' . $alignClass . '"><a class="button button-primary an-button" href="'
                . h($href) . '"' . ($external ? ' target="_blank" rel="noopener noreferrer nofollow"' : '') . '>'
                . h((string) $block['title']) . '</a></p>';
        } elseif ($type === 'image') {
            $record = $media[(int) $block['media_id']] ?? null;
            if ($record === null) {
                continue;
            }
            $blockAlt = trim((string) ($block['alt'] ?? ''));
            if ($blockAlt === '') {
                $blockAlt = (string) $record['alt_text'];
            }
            $html .= '<figure class="an-figure' . $alignClass . '">' . media_image_tag($record, $blockAlt);
            if (!empty($block['caption'])) {
                $html .= '<figcaption>' . h((string) $block['caption']) . '</figcaption>';
            }
            $html .= '</figure>';
        } elseif ($type === 'gallery') {
            $html .= '<div class="an-gallery">';
            foreach ((array) $block['items'] as $item) {
                $record = $media[(int) $item['media_id']] ?? null;
                if ($record === null) {
                    continue;
                }
                $itemAlt = trim((string) ($item['alt'] ?? ''));
                if ($itemAlt === '') {
                    $itemAlt = (string) $record['alt_text'];
                }
                $html .= '<figure>' . media_image_tag($record, $itemAlt);
                if (!empty($item['caption'])) {
                    $html .= '<figcaption>' . h((string) $item['caption']) . '</figcaption>';
                }
                $html .= '</figure>';
            }
            $html .= '</div>';
            if (!empty($block['caption'])) {
                $html .= '<p class="an-gallery-caption">' . h((string) $block['caption']) . '</p>';
            }
        } elseif ($type === 'video') {
            $record = $media[(int) $block['media_id']] ?? null;
            if ($record === null) {
                continue;
            }
            $poster = !empty($block['poster_media_id']) ? ($media[(int) $block['poster_media_id']] ?? null) : null;
            $subtitle = !empty($block['subtitle_media_id']) ? ($media[(int) $block['subtitle_media_id']] ?? null) : null;
            $html .= '<figure class="an-media"><video class="an-video" controls preload="metadata" playsinline'
                . ($poster !== null ? ' poster="' . h(media_public_url($poster)) . '"' : '') . '>'
                . '<source src="' . h(media_public_url($record)) . '" type="' . h((string) $record['mime_type']) . '">';
            if ($subtitle !== null && (string) $subtitle['kind'] === 'subtitle') {
                $html .= '<track kind="subtitles" default src="' . h(media_public_url($subtitle))
                    . '" srclang="' . h((string) ($block['subtitle_language'] ?? 'es'))
                    . '" label="' . h((string) ($block['subtitle_label'] ?? 'Español')) . '">';
            }
            $html .= 'Tu navegador no puede reproducir este video.</video>';
            if (!empty($block['caption'])) {
                $html .= '<figcaption>' . h((string) $block['caption']) . '</figcaption>';
            }
            $html .= '</figure>';
        } elseif ($type === 'audio') {
            $record = $media[(int) $block['media_id']] ?? null;
            if ($record === null) {
                continue;
            }
            $html .= '<figure class="an-media"><audio class="an-audio" controls preload="metadata">'
                . '<source src="' . h(media_public_url($record)) . '" type="' . h((string) $record['mime_type']) . '">'
                . 'Tu navegador no puede reproducir este audio.</audio>';
            if (!empty($block['caption'])) {
                $html .= '<figcaption>' . h((string) $block['caption']) . '</figcaption>';
            }
            $html .= '</figure>';
        }
    }
    return $html;
}

/* =========================================================================
 * Slug y persistencia
 * ========================================================================= */

function announcement_slugify(string $value): string
{
    $value = trim($value);
    if (class_exists('Transliterator')) {
        $transliterator = Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
        if ($transliterator !== null) {
            $converted = $transliterator->transliterate($value);
            if (is_string($converted)) {
                $value = $converted;
            }
        }
    } elseif (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        if (is_string($converted)) {
            $value = $converted;
        }
    }
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $value = (string) preg_replace('/[^a-z0-9]+/u', '-', $value);
    $value = trim($value, '-');
    $value = (string) preg_replace('/-{2,}/', '-', $value);
    if ($value === '') {
        $value = 'comunicado';
    }
    return substr($value, 0, 120);
}

function announcements_available(): bool
{
    return storage_uses_mysql();
}

function announcement_table(string $suffix): string
{
    return mysql_table_name($suffix);
}

function announcement_row_to_array(array $row): array
{
    $content = json_decode((string) $row['content_json'], true);
    if (!is_array($content)) {
        $content = ['version' => 1, 'blocks' => []];
    }
    return [
        'id' => (int) $row['id'],
        'slug' => (string) $row['slug'],
        'title' => (string) $row['title'],
        'summary' => (string) $row['summary'],
        'content' => $content,
        'status' => (string) $row['status'],
        'is_pinned' => (int) $row['is_pinned'] === 1,
        'related_event_id' => (string) ($row['related_event_id'] ?? ''),
        'category' => (string) ($row['category'] ?? 'anuncio'),
        'cover_media_id' => $row['cover_media_id'] === null ? 0 : (int) $row['cover_media_id'],
        'published_at' => $row['published_at'] !== null && $row['published_at'] !== '' ? (string) $row['published_at'] : null,
        'created_at' => (string) $row['created_at'],
        'updated_at' => (string) $row['updated_at'],
        'created_by' => (string) $row['created_by'],
        'updated_by' => (string) $row['updated_by'],
        'version' => (int) $row['version'],
    ];
}

function announcement_find(int $id): ?array
{
    if (!announcements_available() || $id <= 0) {
        return null;
    }
    $table = announcement_table('announcements');
    $statement = mysql_connection()->prepare("SELECT * FROM {$table} WHERE `id` = ? LIMIT 1");
    $statement->execute([$id]);
    $row = $statement->fetch();
    return is_array($row) ? announcement_row_to_array($row) : null;
}

function announcement_find_by_slug(string $slug, bool $publishedOnly = true): ?array
{
    if (!announcements_available() || $slug === '') {
        return null;
    }
    $table = announcement_table('announcements');
    $sql = "SELECT * FROM {$table} WHERE `slug` = ?" . ($publishedOnly ? " AND `status` = 'published'" : '') . ' LIMIT 1';
    $statement = mysql_connection()->prepare($sql);
    $statement->execute([$slug]);
    $row = $statement->fetch();
    return is_array($row) ? announcement_row_to_array($row) : null;
}

function announcement_list(string $status = '', int $limit = 100, bool $pinnedFirst = true): array
{
    if (!announcements_available()) {
        return [];
    }
    $table = announcement_table('announcements');
    $limit = max(1, min(500, $limit));
    $order = ($pinnedFirst ? '`is_pinned` DESC, ' : '')
        . 'COALESCE(NULLIF(`published_at`, \'\'), `created_at`) DESC, `id` DESC';
    if ($status !== '' && in_array($status, announcement_status_keys(), true)) {
        $statement = mysql_connection()->prepare("SELECT * FROM {$table} WHERE `status` = ? ORDER BY {$order} LIMIT {$limit}");
        $statement->execute([$status]);
    } else {
        $statement = mysql_connection()->query("SELECT * FROM {$table} ORDER BY {$order} LIMIT {$limit}");
    }
    $rows = [];
    foreach ($statement->fetchAll() as $row) {
        $rows[] = announcement_row_to_array($row);
    }
    return $rows;
}

function announcement_pinned(): ?array
{
    if (!announcements_available()) {
        return null;
    }
    $table = announcement_table('announcements');
    $row = mysql_connection()->query(
        "SELECT * FROM {$table} WHERE `status` = 'published' AND `is_pinned` = 1
         ORDER BY COALESCE(NULLIF(`published_at`, ''), `created_at`) DESC, `id` DESC LIMIT 1"
    )->fetch();
    return is_array($row) ? announcement_row_to_array($row) : null;
}

function announcement_unique_slug(string $slug, int $ignoreId = 0): string
{
    $table = announcement_table('announcements');
    $connection = mysql_connection();
    $candidate = $slug;
    $suffix = 2;
    while (true) {
        $statement = $connection->prepare("SELECT `id` FROM {$table} WHERE `slug` = ? AND `id` <> ? LIMIT 1");
        $statement->execute([$candidate, $ignoreId]);
        if ($statement->fetchColumn() === false) {
            return $candidate;
        }
        $candidate = substr($slug, 0, 110) . '-' . $suffix;
        $suffix++;
        if ($suffix > 200) {
            return substr($slug, 0, 100) . '-' . bin2hex(random_bytes(4));
        }
    }
}

function announcement_input(array $source): array
{
    $errors = [];
    $title = text_limit((string) ($source['title'] ?? ''), 200);
    if (text_length($title) < 3) {
        $errors[] = 'El título del comunicado debe tener al menos 3 caracteres.';
    }
    $slugRaw = trim((string) ($source['slug'] ?? ''));
    $slug = announcement_slugify($slugRaw !== '' ? $slugRaw : $title);
    if ($slugRaw !== '' && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slugRaw)) {
        $errors[] = 'La dirección corta sólo admite minúsculas, números y guiones. Se corrigió automáticamente a «' . $slug . '».';
    }
    $summary = text_limit((string) ($source['summary'] ?? ''), 400);
    $category = in_array((string) ($source['category'] ?? ''), array_keys(announcement_categories()), true)
        ? (string) $source['category'] : 'anuncio';
    $relatedEvent = text_limit((string) ($source['related_event_id'] ?? ''), 64);
    $coverMediaId = (int) ($source['cover_media_id'] ?? 0);

    $blocksRaw = $source['blocks'] ?? [];
    if (is_string($blocksRaw)) {
        $decoded = json_decode($blocksRaw, true);
        $blocksRaw = is_array($decoded) ? $decoded : [];
    }
    $blocks = announcement_normalize_blocks($blocksRaw);
    $content = ['version' => 1, 'blocks' => $blocks];
    $plain = announcement_content_plain_text($content);
    $maximum = (int) app_config_value('announcement_max_characters', 100000);
    if (text_length($plain) > $maximum) {
        $errors[] = 'El comunicado supera el límite de ' . format_integer($maximum) . ' caracteres.';
    }
    if ($blocks === []) {
        $errors[] = 'Añade al menos un bloque de contenido.';
    }

    return [
        'errors' => array_values(array_unique($errors)),
        'values' => [
            'title' => $title,
            'slug' => $slug,
            'summary' => $summary,
            'category' => $category,
            'related_event_id' => $relatedEvent,
            'cover_media_id' => $coverMediaId > 0 ? $coverMediaId : 0,
            'content' => $content,
            'is_pinned' => !empty($source['is_pinned']),
        ],
    ];
}

function announcement_sync_media_links(PDO $connection, int $announcementId, array $content, int $coverMediaId): void
{
    $table = announcement_table('announcement_media_links');
    $ids = announcement_media_ids_in_content($content);
    if ($coverMediaId > 0) {
        $ids[] = $coverMediaId;
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $delete = $connection->prepare("DELETE FROM {$table} WHERE `announcement_id` = ?");
    $delete->execute([$announcementId]);
    if ($ids === []) {
        return;
    }
    $insert = $connection->prepare(
        "INSERT IGNORE INTO {$table} (`announcement_id`, `media_id`) VALUES (?, ?)"
    );
    foreach ($ids as $mediaId) {
        $insert->execute([$announcementId, $mediaId]);
    }
    $claim = $connection->prepare(
        'UPDATE ' . announcement_table('announcement_media')
        . ' SET `announcement_id` = ? WHERE `id` = ? AND `announcement_id` IS NULL'
    );
    foreach ($ids as $mediaId) {
        $claim->execute([$announcementId, $mediaId]);
    }
}

function announcement_store_revision(PDO $connection, array $announcement, string $changeNote): void
{
    $table = announcement_table('announcement_revisions');
    $statement = $connection->prepare(
        "INSERT INTO {$table} (`announcement_id`, `version`, `snapshot_json`, `change_note`, `created_by`, `created_at`)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $snapshot = [
        'title' => (string) $announcement['title'],
        'slug' => (string) $announcement['slug'],
        'summary' => (string) $announcement['summary'],
        'category' => (string) $announcement['category'],
        'related_event_id' => (string) $announcement['related_event_id'],
        'cover_media_id' => (int) $announcement['cover_media_id'],
        'is_pinned' => (bool) $announcement['is_pinned'],
        'status' => (string) $announcement['status'],
        'content' => $announcement['content'],
    ];
    $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $statement->execute([
        (int) $announcement['id'],
        (int) $announcement['version'],
        $json === false ? '{}' : $json,
        text_limit($changeNote, 300),
        announcement_current_author(),
        gmdate('c'),
    ]);
}

function announcement_current_author(): string
{
    $admin = admin_record();
    return $admin === null ? 'admin' : text_limit((string) ($admin['username'] ?? 'admin'), 80);
}

function announcement_audit(string $action, int $id, string $description): void
{
    database_mutate(function (array &$data) use ($action, $id, $description): void {
        audit_append($data, $action, 'announcement', (string) $id, $description);
    });
}

function announcement_save(array $values, int $id = 0, string $changeNote = ''): array
{
    if (!announcements_available()) {
        return ['errors' => ['Los comunicados necesitan el almacenamiento MySQL.'], 'id' => 0];
    }
    $connection = mysql_connection();
    $table = announcement_table('announcements');
    $now = gmdate('c');
    $author = announcement_current_author();
    $contentJson = json_encode($values['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($contentJson === false) {
        return ['errors' => ['No se pudo preparar el contenido del comunicado.'], 'id' => 0];
    }
    $isNew = $id <= 0;
    $connection->beginTransaction();
    try {
        if ($id > 0) {
            $existing = announcement_find($id);
            if ($existing === null) {
                throw new InvalidArgumentException('El comunicado ya no existe.');
            }
            announcement_store_revision($connection, $existing, $changeNote !== '' ? $changeNote : 'Edición del comunicado.');
            $slug = announcement_unique_slug((string) $values['slug'], $id);
            $statement = $connection->prepare(
                "UPDATE {$table} SET `slug` = ?, `title` = ?, `summary` = ?, `content_json` = ?, `category` = ?,
                 `related_event_id` = ?, `cover_media_id` = ?, `is_pinned` = ?, `updated_at` = ?, `updated_by` = ?,
                 `version` = `version` + 1 WHERE `id` = ?"
            );
            $statement->execute([
                $slug, (string) $values['title'], (string) $values['summary'], $contentJson, (string) $values['category'],
                (string) $values['related_event_id'], $values['cover_media_id'] > 0 ? (int) $values['cover_media_id'] : null,
                !empty($values['is_pinned']) ? 1 : 0, $now, $author, $id,
            ]);
        } else {
            $slug = announcement_unique_slug((string) $values['slug'], 0);
            $statement = $connection->prepare(
                "INSERT INTO {$table} (`slug`, `title`, `summary`, `content_json`, `status`, `is_pinned`,
                 `related_event_id`, `category`, `cover_media_id`, `published_at`, `created_at`, `updated_at`,
                 `created_by`, `updated_by`, `version`)
                 VALUES (?, ?, ?, ?, 'draft', ?, ?, ?, ?, NULL, ?, ?, ?, ?, 1)"
            );
            $statement->execute([
                $slug, (string) $values['title'], (string) $values['summary'], $contentJson,
                !empty($values['is_pinned']) ? 1 : 0, (string) $values['related_event_id'], (string) $values['category'],
                $values['cover_media_id'] > 0 ? (int) $values['cover_media_id'] : null,
                $now, $now, $author, $author,
            ]);
            $id = (int) $connection->lastInsertId();
        }
        if (!empty($values['is_pinned'])) {
            $unpin = $connection->prepare("UPDATE {$table} SET `is_pinned` = 0 WHERE `id` <> ?");
            $unpin->execute([$id]);
        }
        announcement_sync_media_links($connection, $id, (array) $values['content'], (int) $values['cover_media_id']);
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        return ['errors' => [$exception->getMessage()], 'id' => 0];
    }
    announcement_audit($isNew ? 'add' : 'update', $id,
        ($isNew ? 'Se creó' : 'Se actualizó') . ' el comunicado «' . (string) $values['title'] . '».');
    return ['errors' => [], 'id' => $id];
}

function announcement_set_status(int $id, string $status): array
{
    if (!in_array($status, announcement_status_keys(), true)) {
        return ['Estado no válido.'];
    }
    $announcement = announcement_find($id);
    if ($announcement === null) {
        return ['El comunicado ya no existe.'];
    }
    $connection = mysql_connection();
    $table = announcement_table('announcements');
    $now = gmdate('c');
    $publishedAt = $announcement['published_at'];
    if ($status === 'published' && ($publishedAt === null || $publishedAt === '')) {
        $publishedAt = $now;
    }
    $connection->beginTransaction();
    try {
        announcement_store_revision($connection, $announcement, 'Cambio de estado a ' . announcement_status_label($status) . '.');
        $statement = $connection->prepare(
            "UPDATE {$table} SET `status` = ?, `published_at` = ?, `is_pinned` = CASE WHEN ? = 'published' THEN `is_pinned` ELSE 0 END,
             `updated_at` = ?, `updated_by` = ?, `version` = `version` + 1 WHERE `id` = ?"
        );
        $statement->execute([$status, $publishedAt, $status, $now, announcement_current_author(), $id]);
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        return [$exception->getMessage()];
    }
    announcement_audit('status', $id, 'El comunicado «' . (string) $announcement['title'] . '» pasó a ' . announcement_status_label($status) . '.');
    return [];
}

function announcement_set_pinned(int $id, bool $pinned): array
{
    $announcement = announcement_find($id);
    if ($announcement === null) {
        return ['El comunicado ya no existe.'];
    }
    $connection = mysql_connection();
    $table = announcement_table('announcements');
    $connection->beginTransaction();
    try {
        if ($pinned) {
            $connection->exec("UPDATE {$table} SET `is_pinned` = 0");
        }
        $statement = $connection->prepare("UPDATE {$table} SET `is_pinned` = ?, `updated_at` = ? WHERE `id` = ?");
        $statement->execute([$pinned ? 1 : 0, gmdate('c'), $id]);
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        return [$exception->getMessage()];
    }
    announcement_audit('pin', $id, ($pinned ? 'Se fijó' : 'Se desfijó') . ' el comunicado «' . (string) $announcement['title'] . '» en la portada.');
    return [];
}

function announcement_duplicate(int $id): array
{
    $announcement = announcement_find($id);
    if ($announcement === null) {
        return ['errors' => ['El comunicado ya no existe.'], 'id' => 0];
    }
    $values = [
        'title' => text_limit('Copia de ' . (string) $announcement['title'], 200),
        'slug' => announcement_slugify('copia-de-' . (string) $announcement['slug']),
        'summary' => (string) $announcement['summary'],
        'category' => (string) $announcement['category'],
        'related_event_id' => (string) $announcement['related_event_id'],
        'cover_media_id' => (int) $announcement['cover_media_id'],
        'content' => $announcement['content'],
        'is_pinned' => false,
    ];
    return announcement_save($values, 0, 'Duplicado de otro comunicado.');
}

function announcement_revisions(int $id, int $limit = 50): array
{
    if (!announcements_available()) {
        return [];
    }
    $table = announcement_table('announcement_revisions');
    $limit = max(1, min(200, $limit));
    $statement = mysql_connection()->prepare(
        "SELECT * FROM {$table} WHERE `announcement_id` = ? ORDER BY `id` DESC LIMIT {$limit}"
    );
    $statement->execute([$id]);
    $rows = [];
    foreach ($statement->fetchAll() as $row) {
        $snapshot = json_decode((string) $row['snapshot_json'], true);
        $rows[] = [
            'id' => (int) $row['id'],
            'announcement_id' => (int) $row['announcement_id'],
            'version' => (int) $row['version'],
            'snapshot' => is_array($snapshot) ? $snapshot : [],
            'change_note' => (string) $row['change_note'],
            'created_by' => (string) $row['created_by'],
            'created_at' => (string) $row['created_at'],
        ];
    }
    return $rows;
}

function announcement_restore_revision(int $revisionId): array
{
    if (!announcements_available()) {
        return ['errors' => ['Los comunicados necesitan el almacenamiento MySQL.'], 'id' => 0];
    }
    $table = announcement_table('announcement_revisions');
    $statement = mysql_connection()->prepare("SELECT * FROM {$table} WHERE `id` = ? LIMIT 1");
    $statement->execute([$revisionId]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        return ['errors' => ['La revisión solicitada ya no existe.'], 'id' => 0];
    }
    $snapshot = json_decode((string) $row['snapshot_json'], true);
    if (!is_array($snapshot)) {
        return ['errors' => ['La revisión guardada no es válida.'], 'id' => 0];
    }
    $content = isset($snapshot['content']) && is_array($snapshot['content'])
        ? $snapshot['content'] : ['version' => 1, 'blocks' => []];
    $content['blocks'] = announcement_normalize_blocks($content['blocks'] ?? []);
    $values = [
        'title' => text_limit('Restauración de ' . (string) ($snapshot['title'] ?? 'comunicado'), 200),
        'slug' => announcement_slugify('restauracion-' . (string) ($snapshot['slug'] ?? 'comunicado')),
        'summary' => text_limit((string) ($snapshot['summary'] ?? ''), 400),
        'category' => (string) ($snapshot['category'] ?? 'anuncio'),
        'related_event_id' => text_limit((string) ($snapshot['related_event_id'] ?? ''), 64),
        'cover_media_id' => (int) ($snapshot['cover_media_id'] ?? 0),
        'content' => $content,
        'is_pinned' => false,
    ];
    return announcement_save($values, 0, 'Restauración de la revisión ' . (int) $row['version'] . '.');
}

function announcement_delete(int $id): array
{
    $announcement = announcement_find($id);
    if ($announcement === null) {
        return ['El comunicado ya no existe.'];
    }
    // Copia de seguridad antes de un borrado importante, igual que en los
    // cierres y las correcciones financieras.
    database_mutate(function (array &$data) use ($announcement): void {
        audit_append($data, 'backup', 'announcement', (string) $announcement['id'],
            'Copia automática antes de eliminar el comunicado «' . (string) $announcement['title'] . '».');
    }, true, 'before-announcement-delete');
    $connection = mysql_connection();
    $connection->beginTransaction();
    try {
        $mediaIds = announcement_media_ids_in_content($announcement['content']);
        if ((int) $announcement['cover_media_id'] > 0) {
            $mediaIds[] = (int) $announcement['cover_media_id'];
        }
        $connection->prepare('DELETE FROM ' . announcement_table('announcement_media_links') . ' WHERE `announcement_id` = ?')
            ->execute([$id]);
        $connection->prepare('DELETE FROM ' . announcement_table('announcement_revisions') . ' WHERE `announcement_id` = ?')
            ->execute([$id]);
        $connection->prepare('DELETE FROM ' . announcement_table('announcements') . ' WHERE `id` = ?')
            ->execute([$id]);
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        return [$exception->getMessage()];
    }
    // Sólo se borra del disco el archivo que ya no usa ningún otro comunicado.
    media_cleanup_orphans(array_values(array_unique(array_map('intval', $mediaIds))));
    announcement_audit('delete', $id, 'Se eliminó el comunicado «' . (string) $announcement['title'] . '».');
    return [];
}

function announcement_public_url(array $announcement): string
{
    return 'announcement.php?slug=' . rawurlencode((string) $announcement['slug']);
}

function announcement_display_date(array $announcement): string
{
    $date = $announcement['published_at'];
    if ($date === null || $date === '') {
        $date = (string) $announcement['created_at'];
    }
    return format_datetime((string) $date);
}
