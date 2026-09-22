<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$available = announcements_available();
$announcements = $available ? announcement_list('published', 100) : [];
$coverIds = [];
foreach ($announcements as $announcement) {
    if ((int) $announcement['cover_media_id'] > 0) {
        $coverIds[] = (int) $announcement['cover_media_id'];
    }
}
$covers = $coverIds === [] ? [] : media_records_by_ids($coverIds);

render_page_start('Comunicados', 'announcements-page');
render_site_header('announcements');
?>
<main>
    <section class="history-hero">
        <div class="shell">
            <?php render_flash_message(); ?>
            <span class="eyebrow">Tablón oficial de <?= h((string) app_config('alliance_name')) ?></span>
            <h1>Comunicados</h1>
            <p>Reglas, resultados, avisos del banco y recordatorios de la alianza, sin los límites del correo del juego.</p>
        </div>
    </section>

    <section class="content-section">
        <div class="shell">
            <?php if (!$available): ?>
                <div class="empty-state"><h3>Los comunicados todavía no están disponibles</h3><p>Esta sección necesita el almacenamiento MySQL del banco.</p></div>
            <?php elseif ($announcements === []): ?>
                <div class="empty-state">
                    <h3>Todavía no hay comunicados publicados</h3>
                    <p>Cuando el administrador publique el primero aparecerá aquí para toda la alianza.</p>
                </div>
            <?php else: ?>
                <div class="announcement-grid">
                    <?php foreach ($announcements as $announcement): ?>
                        <?php $cover = $covers[(int) $announcement['cover_media_id']] ?? null; ?>
                        <article class="announcement-card <?= !empty($announcement['is_pinned']) ? 'is-pinned' : '' ?>">
                            <?php if ($cover !== null): ?>
                                <a class="announcement-cover" href="<?= h(announcement_public_url($announcement)) ?>">
                                    <?= media_image_tag($cover, (string) $cover['alt_text']) ?>
                                </a>
                            <?php endif; ?>
                            <div class="announcement-card-body">
                                <div class="announcement-meta">
                                    <?php if (!empty($announcement['is_pinned'])): ?><span class="announcement-pin">Fijado</span><?php endif; ?>
                                    <span class="announcement-category"><?= h(announcement_categories()[(string) $announcement['category']] ?? 'Anuncio') ?></span>
                                    <time datetime="<?= h((string) ($announcement['published_at'] ?? $announcement['created_at'])) ?>"><?= h(announcement_display_date($announcement)) ?></time>
                                </div>
                                <h2><a href="<?= h(announcement_public_url($announcement)) ?>"><?= h((string) $announcement['title']) ?></a></h2>
                                <?php if ((string) $announcement['summary'] !== ''): ?>
                                    <p><?= h((string) $announcement['summary']) ?></p>
                                <?php endif; ?>
                                <a class="text-link" href="<?= h(announcement_public_url($announcement)) ?>">Leer el comunicado completo</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php render_site_footer(); ?>
