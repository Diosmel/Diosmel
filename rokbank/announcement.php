<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$slug = trim((string) ($_GET['slug'] ?? ''));
// Un borrador jamás es accesible públicamente, aunque se adivine su dirección.
$announcement = announcements_available() ? announcement_find_by_slug($slug, true) : null;

if ($announcement === null) {
    http_response_code(404);
    render_page_start('Comunicado no encontrado', 'announcements-page');
    render_site_header('announcements');
    ?>
    <main>
        <section class="content-section">
            <div class="shell">
                <div class="empty-state">
                    <h3>Este comunicado no está disponible</h3>
                    <p>Puede que todavía sea un borrador, que se haya retirado de la publicación o que la dirección no sea correcta.</p>
                    <a class="button button-secondary" href="announcements.php">Ver todos los comunicados</a>
                </div>
            </div>
        </section>
    </main>
    <?php
    render_site_footer();
    exit;
}

$cover = (int) $announcement['cover_media_id'] > 0
    ? (media_records_by_ids([(int) $announcement['cover_media_id']])[(int) $announcement['cover_media_id']] ?? null)
    : null;
$relatedArchive = (string) $announcement['related_event_id'] !== ''
    ? find_archive((string) $announcement['related_event_id']) : null;

render_page_start((string) $announcement['title'], 'announcements-page announcement-detail-page');
render_site_header('announcements');
?>
<main>
    <article class="announcement-article">
        <header class="announcement-article-head">
            <div class="shell">
                <?php render_flash_message(); ?>
                <a class="text-link" href="announcements.php">← Todos los comunicados</a>
                <div class="announcement-meta">
                    <span class="announcement-category"><?= h(announcement_categories()[(string) $announcement['category']] ?? 'Anuncio') ?></span>
                    <time datetime="<?= h((string) ($announcement['published_at'] ?? $announcement['created_at'])) ?>"><?= h(announcement_display_date($announcement)) ?></time>
                </div>
                <h1><?= h((string) $announcement['title']) ?></h1>
                <?php if ((string) $announcement['summary'] !== ''): ?>
                    <p class="announcement-summary"><?= h((string) $announcement['summary']) ?></p>
                <?php endif; ?>
            </div>
        </header>

        <?php if ($cover !== null): ?>
            <div class="shell announcement-hero-media"><?= media_image_tag($cover, (string) $cover['alt_text']) ?></div>
        <?php endif; ?>

        <div class="shell announcement-body" data-announcement-body>
            <?= announcement_render_blocks((array) $announcement['content']) ?>
        </div>

        <?php if ($relatedArchive !== null): ?>
            <div class="shell">
                <aside class="an-callout an-callout-info announcement-related">
                    <strong>Evento relacionado</strong>
                    <p><a class="an-link" href="history.php?id=<?= h(rawurlencode((string) $relatedArchive['id'])) ?>">Ver el registro completo de <?= h((string) settings_normalize($relatedArchive['settings'])['event_name']) ?></a></p>
                </aside>
            </div>
        <?php endif; ?>
    </article>
</main>
<?php render_site_footer(); ?>
