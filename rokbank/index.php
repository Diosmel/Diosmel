<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$summary = bank_summary();
$settings = $summary['settings'];
$rewardProjection = $summary['reward_projection'];
$fund = $summary['fund_breakdown'];
$metricLabel = (string) $settings['score_metric_label'];
$pinnedAnnouncement = announcements_available() ? announcement_pinned() : null;
$pinnedCover = $pinnedAnnouncement !== null && (int) $pinnedAnnouncement['cover_media_id'] > 0
    ? (media_records_by_ids([(int) $pinnedAnnouncement['cover_media_id']])[(int) $pinnedAnnouncement['cover_media_id']] ?? null)
    : null;
$projectedTaxShare = (int) $rewardProjection['eligible_total'] > 0
    ? (float) $rewardProjection['projected_tax_total'] / (int) $rewardProjection['eligible_total']
    : (float) $rewardProjection['tax_rate'] / 100;
$activity = public_activity();
$archives = all_archives();
$installed = is_installed();

render_page_start('Banco público', 'public-page');
render_site_header('public');
?>
<main>
    <section class="treasury-hero">
        <div class="hero-ornament hero-ornament-left" aria-hidden="true"></div>
        <div class="hero-ornament hero-ornament-right" aria-hidden="true"></div>
        <div class="shell">
            <?php render_flash_message(); ?>

            <?php if (!$installed): ?>
                <div class="setup-notice">
                    <div>
                        <strong>La instalación todavía no está terminada.</strong>
                        <span>El administrador debe crear su acceso privado antes de registrar aportes.</span>
                    </div>
                    <a class="button button-secondary" href="setup.php">Configurar acceso</a>
                </div>
            <?php endif; ?>

            <div class="hero-grid">
                <div class="hero-copy">
                    <div class="event-state-row">
                        <span class="live-pill <?= $settings['event_status'] === 'closed' ? 'is-closed' : '' ?>">
                            <i aria-hidden="true"></i><?= h(event_status_label((string) $settings['event_status'])) ?>
                        </span>
                        <span class="hero-alliance">Alianza <?= h((string) app_config('alliance_name')) ?></span>
                    </div>
                    <span class="eyebrow">Cámara del Tesoro</span>
                    <h1><?= h((string) $settings['event_name']) ?></h1>
                    <p class="hero-lead">Registro verificable de los recursos que los miembros han depositado bajo custodia para el evento.</p>

                    <div class="hero-facts">
                        <div>
                            <span>Custodio</span>
                            <?php render_player_name((string) $settings['custodian_name'], 'hero-fact-value'); ?>
                        </div>
                        <div>
                            <span>Última actualización</span>
                            <strong><?= h(format_datetime($summary['last_updated'])) ?></strong>
                        </div>
                        <?php if (!empty($settings['deadline'])): ?>
                            <div>
                                <span>Tiempo restante</span>
                                <strong data-countdown="<?= h((string) $settings['deadline']) ?>"><?= h(format_datetime((string) $settings['deadline'])) ?></strong>
                            </div>
                        <?php endif; ?>
                        <?php if ((string) $settings['prize'] !== ''): ?>
                            <div>
                                <span>Premio</span>
                                <strong><?= h((string) $settings['prize']) ?></strong>
                            </div>
                        <?php endif; ?>
                        <?php if ((string) $settings['winner_name'] !== ''): ?>
                            <div class="winner-fact">
                                <span>Ganador anunciado</span>
                                <?php render_player_name((string) $settings['winner_name'], 'hero-fact-value'); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="hero-crest" role="img" aria-label="Emblema del custodio <?= h((string) $settings['custodian_name']) ?>">
                    <span class="crest-ring crest-ring-outer" aria-hidden="true"></span>
                    <span class="crest-ring crest-ring-inner" aria-hidden="true"></span>
                    <img src="assets/images/freecuba-profile.webp" alt="Emblema de FreeCuba" width="900" height="900">
                    <div class="crest-plaque">
                        <span>Banco oficial</span>
                        <strong>ME58</strong>
                    </div>
                </div>
            </div>

            <div class="member-search" role="search" data-player-search-form>
                <span class="search-icon" aria-hidden="true"></span>
                <label for="player-search">Busca tu nombre</label>
                <input id="player-search" type="search" placeholder="Escribe exactamente o solo una parte…" autocomplete="off" data-player-search>
                <button type="button" data-clear-search hidden>Limpiar</button>
            </div>
        </div>
    </section>

    <section class="vault-overview" aria-labelledby="vault-title">
        <div class="shell">
            <div class="section-heading vault-heading">
                <div>
                    <span class="eyebrow">Saldo actual bajo custodia</span>
                    <h2 id="vault-title">Recursos disponibles en el banco</h2>
                </div>
                <p>Estas son las cantidades exactas que llegaron al banco. Aquí no se descuenta ningún impuesto adicional.</p>
            </div>

            <div class="resource-grid totals-grid">
                <?php foreach (resource_keys() as $resource): ?>
                    <article class="resource-card resource-card-<?= h($resource) ?>">
                        <?php render_resource_art($resource, 'resource-card-art'); ?>
                        <div class="resource-card-copy">
                            <span><?= h(resource_title($resource)) ?></span>
                            <strong data-counter data-target="<?= h((string) $summary['balance'][$resource]) ?>" data-final="<?= h(format_compact_amount((int) $summary['balance'][$resource])) ?>"><?= h(format_compact_amount((int) $summary['balance'][$resource])) ?></strong>
                            <small><?= h(format_integer((int) $summary['balance'][$resource])) ?> disponibles</small>
                        </div>
                        <dl class="resource-ledger-mini">
                            <div><dt>Recibido</dt><dd><?= h(format_compact_amount((int) $summary['received'][$resource])) ?></dd></div>
                            <div><dt>Enviado desde el banco</dt><dd><?= h(format_compact_amount((int) $summary['paid'][$resource])) ?></dd></div>
                        </dl>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="grand-vault-card">
                <div class="grand-vault-icon" aria-hidden="true"><span></span></div>
                <div>
                    <span>Total general disponible</span>
                    <strong data-counter data-target="<?= h((string) $summary['grand_total']) ?>" data-final="<?= h(format_compact_amount((int) $summary['grand_total'])) ?>"><?= h(format_compact_amount((int) $summary['grand_total'])) ?></strong>
                    <small><?= h(format_integer((int) $summary['grand_total'])) ?> recursos combinados</small>
                </div>
                <div class="grand-vault-stats">
                    <div><strong><?= h((string) $summary['player_count']) ?></strong><span>Miembros</span></div>
                    <div><strong><?= h((string) $summary['eligible_count']) ?></strong><span>Clasificados</span></div>
                    <div><strong><?= h((string) $summary['record_count']) ?></strong><span>Envíos</span></div>
                </div>
            </div>
        </div>
    </section>

    <?php if ($pinnedAnnouncement !== null): ?>
        <section class="content-section pinned-announcement-section" aria-labelledby="pinned-announcement-title">
            <div class="shell">
                <div class="section-heading">
                    <div><span class="eyebrow">Tablón oficial</span><h2 id="pinned-announcement-title">Comunicado fijado</h2></div>
                    <p><a class="text-link" href="announcements.php">Ver todos los comunicados</a></p>
                </div>
                <article class="pinned-announcement">
                    <?php if ($pinnedCover !== null): ?>
                        <a class="pinned-announcement-cover" href="<?= h(announcement_public_url($pinnedAnnouncement)) ?>">
                            <?= media_image_tag($pinnedCover, (string) $pinnedCover['alt_text']) ?>
                        </a>
                    <?php endif; ?>
                    <div>
                        <div class="announcement-meta">
                            <span class="announcement-pin">Fijado</span>
                            <span class="announcement-category"><?= h(announcement_categories()[(string) $pinnedAnnouncement['category']] ?? 'Anuncio') ?></span>
                            <time datetime="<?= h((string) ($pinnedAnnouncement['published_at'] ?? $pinnedAnnouncement['created_at'])) ?>"><?= h(announcement_display_date($pinnedAnnouncement)) ?></time>
                        </div>
                        <h3><?= h((string) $pinnedAnnouncement['title']) ?></h3>
                        <?php if ((string) $pinnedAnnouncement['summary'] !== ''): ?>
                            <p><?= h((string) $pinnedAnnouncement['summary']) ?></p>
                        <?php endif; ?>
                        <a class="button button-secondary" href="<?= h(announcement_public_url($pinnedAnnouncement)) ?>">Leer el comunicado</a>
                    </div>
                </article>
            </div>
        </section>
    <?php endif; ?>

    <section class="rewards-section content-section" id="rewards" aria-labelledby="rewards-title">
        <div class="shell">
            <div class="section-heading rewards-heading">
                <div>
                    <span class="eyebrow">Premios en tiempo real</span>
                    <h2 id="rewards-title">Lo que recibiría cada puesto hoy</h2>
                </div>
                <p>
                    Objetivo de la semana: <strong><?= h((string) $settings['objective_title']) ?></strong>.
                    Se clasifica por <strong><?= h($metricLabel) ?></strong> (<?= h(ranking_method_label((string) $settings['ranking_method'])) ?>),
                    contando únicamente a quienes ya completaron los cuatro requisitos.
                </p>
            </div>

            <?php if ((string) $settings['objective_description'] !== ''): ?>
                <p class="objective-description"><?= h((string) $settings['objective_description']) ?></p>
            <?php endif; ?>

            <div class="fund-breakdown-grid">
                <article class="fund-breakdown-card">
                    <span>Fondo aportado durante el evento</span>
                    <strong><?= h(format_compact_amount((int) $fund['contributed_total_grand'])) ?></strong>
                    <small><?= h(format_integer((int) $fund['contributed_total_grand'])) ?> recursos recibidos</small>
                </article>
                <article class="fund-breakdown-card">
                    <span>Fondo elegible</span>
                    <strong><?= h(format_compact_amount((int) $fund['eligible_total_grand'])) ?></strong>
                    <small>De <?= h((string) $rewardProjection['eligible_count']) ?> miembro<?= (int) $rewardProjection['eligible_count'] === 1 ? '' : 's' ?> que completaron los cuatro recursos</small>
                </article>
                <article class="fund-breakdown-card fund-breakdown-highlight">
                    <span>Porcentaje destinado a premios</span>
                    <strong><?= h(basis_points_to_text((int) $fund['basis_points'])) ?>%</strong>
                    <small>Se aplica sobre el fondo elegible, recurso por recurso</small>
                </article>
                <article class="fund-breakdown-card">
                    <span>Parte del evento retenida como reserva</span>
                    <strong><?= h(format_compact_amount((int) $fund['retained_from_eligible_grand'])) ?></strong>
                    <small>Guardada a propósito para eventos futuros, incluido el residuo del redondeo</small>
                </article>
                <article class="fund-breakdown-card">
                    <span>Pendiente porque el miembro no clasificó</span>
                    <strong><?= h(format_compact_amount((int) $fund['pending_unqualified_grand'])) ?></strong>
                    <small>Concepto distinto de la reserva: espera a que ese jugador complete los cuatro recursos</small>
                </article>
                <article class="fund-breakdown-card">
                    <span>Reserva anterior usada en este evento</span>
                    <strong><?= h(format_compact_amount((int) $fund['reserve_draw_grand'])) ?></strong>
                    <small><?= (int) $fund['reserve_draw_grand'] > 0 ? 'Autorizada expresamente para reforzar el premio' : 'Este evento no usa reserva anterior' ?></small>
                </article>
                <article class="fund-breakdown-card fund-breakdown-highlight">
                    <span>Fondo bruto total de premios</span>
                    <strong><?= h(format_compact_amount((int) $fund['prize_fund_gross_grand'])) ?></strong>
                    <small><?= h(format_integer((int) $fund['prize_fund_gross_grand'])) ?> recursos que pueden salir del banco</small>
                </article>
                <article class="fund-breakdown-card">
                    <span>Impuesto previsto</span>
                    <strong><?= h(format_compact_amount((int) $rewardProjection['projected_tax_total'])) ?></strong>
                    <small>Retención del juego al enviar los premios</small>
                </article>
                <article class="fund-breakdown-card">
                    <span>Total neto previsto para los ganadores</span>
                    <strong><?= h(format_compact_amount((int) $rewardProjection['projected_winner_total'])) ?></strong>
                    <small><?= h(format_integer((int) $rewardProjection['projected_winner_total'])) ?> recursos que llegarían de verdad</small>
                </article>
                <article class="fund-breakdown-card">
                    <span>Reserva de apertura</span>
                    <strong><?= h(format_compact_amount((int) $fund['reserve_opening_grand'])) ?></strong>
                    <small>Acumulada antes de empezar este evento</small>
                </article>
                <article class="fund-breakdown-card">
                    <span>Reserva estimada al cierre</span>
                    <strong><?= h(format_compact_amount((int) $fund['reserve_closing_grand'])) ?></strong>
                    <small>Continuará disponible para el evento siguiente</small>
                </article>
            </div>

            <div class="table-shell fund-detail-shell">
                <table class="data-table fund-detail-table">
                    <caption>Contabilidad por recurso. Nunca se compensa el faltante de un recurso con el sobrante de otro.</caption>
                    <thead><tr><th scope="col">Recurso</th><th scope="col">Reserva de apertura</th><th scope="col">Aportado</th><th scope="col">Elegible</th><th scope="col">Premio del evento</th><th scope="col">Reserva usada</th><th scope="col">Fondo bruto</th><th scope="col">Reserva al cierre</th></tr></thead>
                    <tbody>
                        <?php foreach (resource_keys() as $resource): ?>
                            <tr>
                                <th scope="row" data-label="Recurso"><span class="history-resource-name"><?php render_resource_mark($resource); ?> <?= h(resource_title($resource)) ?></span></th>
                                <td data-label="Reserva de apertura"><?= h(format_integer((int) $fund['reserve_opening'][$resource])) ?></td>
                                <td data-label="Aportado"><?= h(format_integer((int) $fund['contributed_total'][$resource])) ?></td>
                                <td data-label="Elegible"><?= h(format_integer((int) $fund['eligible_total'][$resource])) ?></td>
                                <td data-label="Premio del evento"><?= h(format_integer((int) $fund['prize_from_event'][$resource])) ?></td>
                                <td data-label="Reserva usada"><?= h(format_integer((int) $fund['reserve_draw'][$resource])) ?></td>
                                <td data-label="Fondo bruto"><?= h(format_integer((int) $fund['prize_fund_gross'][$resource])) ?></td>
                                <td data-label="Reserva al cierre"><?= h(format_integer((int) $fund['reserve_closing'][$resource])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="reward-live-summary">
                <article class="reward-fund-card reward-fund-active">
                    <span>Fondo habilitado para premios</span>
                    <strong><?= h(format_compact_amount((int) $rewardProjection['eligible_total'])) ?></strong>
                    <small><?= h(format_integer((int) $rewardProjection['eligible_total'])) ?> recursos de <?= h((string) $rewardProjection['eligible_count']) ?> clasificado<?= (int) $rewardProjection['eligible_count'] === 1 ? '' : 's' ?></small>
                </article>
                <article class="reward-fund-card">
                    <span>Saldo esperando clasificación</span>
                    <strong><?= h(format_compact_amount((int) $rewardProjection['waiting_total'])) ?></strong>
                    <small>No aumenta los premios hasta completar comida, madera, piedra y oro.</small>
                </article>
                <article class="reward-fund-card reward-formula-card">
                    <span>Resultado neto proyectado</span>
                    <strong>
                        <?php foreach (reward_rank_keys() as $index => $rank): ?><?= $index > 0 ? ' · ' : '' ?><?= h(reward_rank_label($rank)) ?> <?= $rank === 'third' ? '≈' : '' ?><?= h(number_format((float) $rewardProjection['effective_shares'][$rank] * 100, 2, ',', '.')) ?>%<?php endforeach; ?>
                    </strong>
                    <small>Lilith retendría ≈<?= h(number_format($projectedTaxShare * 100, 2, ',', '.')) ?>%. TOP 1 y TOP 2 quedan protegidos; TOP 3 recibe el restante.</small>
                </article>
            </div>

            <ul class="eligible-pool-strip">
                <?php foreach (resource_keys() as $resource): ?>
                    <li>
                        <?php render_resource_mark($resource); ?>
                        <span><?= h(resource_title($resource)) ?></span>
                        <strong><?= h(format_integer((int) $rewardProjection['eligible_balance'][$resource])) ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if (!$rewardProjection['ready_for_three_places']): ?>
                <div class="reward-status-notice" role="note">
                    <strong>Proyección visible, todavía no hay tres puestos confirmados.</strong>
                    <span>
                        Actualmente existen <?= h((string) $rewardProjection['eligible_count']) ?> jugadores clasificados. Para tener TOP 1, TOP 2 y TOP 3 reales hacen falta al menos tres.
                        Aun así el banco ya custodia <?= h(format_integer((int) $summary['grand_total'])) ?> recursos del evento, de los cuales
                        <?= h(format_integer((int) $fund['pending_unqualified_grand'])) ?> están pendientes de que su dueño complete los cuatro requisitos.
                    </span>
                </div>
            <?php endif; ?>

            <?php if (!$rewardProjection['guarantees_funded']): ?>
                <div class="reward-status-notice" role="alert">
                    <strong>La configuración actual no alcanza para garantizar TOP 1 y TOP 2.</strong>
                    <span>Aumenta el peso base de TOP 3 o reduce el impuesto desde la administración del evento.</span>
                </div>
            <?php endif; ?>

            <div class="reward-podium">
                <?php $rankNumbers = ['first' => '1', 'second' => '2', 'third' => '3']; ?>
                <?php foreach (reward_rank_keys() as $rank): $prize = $rewardProjection['prizes'][$rank]; ?>
                    <article class="reward-prize-card reward-prize-<?= h($rank) ?>">
                        <div class="reward-card-heading">
                            <span class="reward-rank-medal" aria-hidden="true"><?= h($rankNumbers[$rank]) ?></span>
                            <div>
                                <span><?= h((string) $prize['label']) ?></span>
                                <?php if ($prize['is_net_guaranteed']): ?>
                                    <strong><?= h(number_format((float) $prize['share'] * 100, 2, ',', '.')) ?>% neto garantizado</strong>
                                <?php else: ?>
                                    <strong>Remanente neto · ≈<?= h(number_format((float) $rewardProjection['effective_shares'][$rank] * 100, 2, ',', '.')) ?>%</strong>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ((string) $settings['rank_rule_' . $rank] !== ''): ?>
                            <p class="reward-rank-rule"><?= h((string) $settings['rank_rule_' . $rank]) ?></p>
                        <?php endif; ?>

                        <div class="reward-net-total">
                            <span><?= $prize['is_net_guaranteed'] ? 'Premio neto garantizado' : 'Remanente neto para el ganador' ?></span>
                            <strong><?= $prize['is_net_guaranteed'] ? '' : '≈' ?><?= h(format_compact_amount((int) $prize['received_total'])) ?></strong>
                            <?php if ($prize['is_net_guaranteed']): ?>
                                <small><?= h(format_integer((int) $prize['received_total'])) ?> deben llegar completos después del impuesto.</small>
                            <?php else: ?>
                                <small>Base <?= h(format_integer((int) $prize['nominal_total'])) ?> − ≈<?= h(format_integer((int) $prize['absorbed_tax_total'])) ?> de todos los impuestos.</small>
                            <?php endif; ?>
                        </div>

                        <dl class="reward-ledger">
                            <div><dt><?= $prize['is_net_guaranteed'] ? 'Sale del banco' : 'Sale como remanente' ?></dt><dd><?= h(format_integer((int) $prize['sent_total'])) ?></dd></div>
                            <div><dt><?= $prize['is_net_guaranteed'] ? 'Impuesto compensado' : 'Impuestos que absorbe' ?></dt><dd>≈<?= h(format_integer((int) ($prize['is_net_guaranteed'] ? $prize['tax_total'] : $prize['absorbed_tax_total']))) ?></dd></div>
                        </dl>

                        <div class="reward-resource-list">
                            <?php foreach (resource_keys() as $resource): ?>
                                <div class="reward-resource-row reward-row-<?= h($resource) ?>">
                                    <?php render_resource_art($resource, 'reward-resource-art'); ?>
                                    <div>
                                        <span><?= h(resource_title($resource)) ?> que recibe</span>
                                        <strong><?= $prize['is_net_guaranteed'] ? '' : '≈' ?><?= h(format_integer((int) $prize['received'][$resource])) ?></strong>
                                        <?php if ($prize['is_net_guaranteed']): ?>
                                            <small>Sale <?= h(format_integer((int) $prize['sent'][$resource])) ?> · Lilith retiene ≈<?= h(format_integer((int) $prize['tax'][$resource])) ?>, ya compensado.</small>
                                        <?php else: ?>
                                            <small>Base <?= h(format_integer((int) $prize['nominal'][$resource])) ?> · salen <?= h(format_integer((int) $prize['sent'][$resource])) ?> · absorbe ≈<?= h(format_integer((int) $prize['absorbed_tax'][$resource])) ?>.</small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="reward-trip-count">
                            <span><?= h((string) $prize['trips']) ?></span>
                            <div><strong>envío<?= (int) $prize['trips'] === 1 ? '' : 's' ?> de hasta <?= h(format_compact_amount((int) $rewardProjection['transport_capacity'])) ?></strong><small>Capacidad combinada estimada por viaje</small></div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="reward-method">
                <strong>Cálculo transparente y automático</strong>
                <p>Del fondo elegible se destina a premios el <?= h(basis_points_to_text((int) $fund['basis_points'])) ?>%; el resto queda guardado como reserva para eventos futuros. Sobre ese fondo se calcula la proporción base <?= h(implode(':', array_map(static function ($weight): string { return rtrim(rtrim(number_format((float) $weight, 2, ',', ''), '0'), ','); }, (array) $settings['reward_weights']))) ?>. TOP 1 y TOP 2 conservan completos sus importes: el banco añade lo necesario para compensar el <?= h(number_format((float) $rewardProjection['tax_rate'], 0, ',', '.')) ?>% de Lilith. TOP 3 recibe todo lo que queda y, por eso, su parte absorbe los impuestos de los tres premios. Del fondo completo, aproximadamente el <?= h(number_format((1 - $projectedTaxShare) * 100, 2, ',', '.')) ?>% llega a los ganadores, el <?= h(number_format($projectedTaxShare * 100, 2, ',', '.')) ?>% queda como impuesto y FreeCuba retiene 0%. Los viajes de hasta <?= h(format_compact_amount((int) $rewardProjection['transport_capacity'])) ?> solo cambian la cantidad de envíos.</p>
            </div>
        </div>
    </section>

    <section class="requirements-section content-section" id="requirements">
        <div class="shell">
            <div class="section-heading">
                <div>
                    <span class="eyebrow">Regla de clasificación</span>
                    <h2>Los cuatro requisitos son obligatorios</h2>
                </div>
                <p>La clasificación se calcula únicamente con los recursos que realmente llegaron al banco.</p>
            </div>
            <div class="requirement-grid">
                <?php foreach (resource_keys() as $resource): ?>
                    <article class="requirement-item requirement-<?= h($resource) ?>">
                        <?php render_resource_art($resource, 'requirement-art'); ?>
                        <div>
                            <span><?= h(resource_title($resource)) ?></span>
                            <strong><?= h(format_compact_amount((int) $settings['thresholds'][$resource])) ?></strong>
                            <small><?= h(format_integer((int) $settings['thresholds'][$resource])) ?> requeridos</small>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="tax-explainer">
                <span class="tax-number"><?= h(number_format((float) $settings['tax_rate'], 0, ',', '.')) ?>%</span>
                <div>
                    <strong>TOP 1 y TOP 2 reciben su premio completo; TOP 3 absorbe los impuestos</strong>
                    <p>Cada informe muestra exactamente lo que recibió el banco. Al pagar, FreeCuba envía recursos adicionales para que TOP 1 y TOP 2 reciban completos los importes publicados. TOP 3 obtiene el remanente después de todos los impuestos de transporte. Lilith retiene el <?= h(number_format((float) $settings['tax_rate'], 0, ',', '.')) ?>%; el custodio no retiene ninguna parte.</p>
                    <div class="tax-flow" role="group" aria-label="Distribución de premios e impuesto">
                        <span><b>TOP 1 y TOP 2</b> premios netos completos</span>
                        <i aria-hidden="true">→</i>
                        <span><b>≈<?= h(number_format((float) $settings['tax_rate'], 0, ',', '.')) ?>%</b> impuesto total de Lilith</span>
                        <i aria-hidden="true">→</i>
                        <span><b>TOP 3</b> recibe el restante neto</span>
                        <em>FreeCuba retiene 0%</em>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="players-section content-section" id="participants">
        <div class="shell">
            <div class="section-heading players-heading">
                <div>
                    <span class="eyebrow">Acumulado individual</span>
                    <h2>Miembros participantes</h2>
                </div>
                <p><span data-visible-player-count><?= h((string) $summary['player_count']) ?></span> visibles · <?= h((string) $summary['eligible_count']) ?> clasificados en total</p>
            </div>

            <div class="player-toolbar" <?= $summary['players'] === [] ? 'hidden' : '' ?>>
                <div class="filter-group" role="group" aria-label="Filtrar participantes">
                    <button class="filter-chip is-active" type="button" data-player-filter="all">Todos</button>
                    <button class="filter-chip" type="button" data-player-filter="eligible">Clasificados</button>
                    <button class="filter-chip" type="button" data-player-filter="pending">Pendientes</button>
                </div>
                <span>Selecciona un jugador para ver cada envío.</span>
            </div>

            <?php if ($summary['players'] === []): ?>
                <div class="empty-state vault-empty">
                    <div class="empty-vault" aria-hidden="true"><span></span></div>
                    <h3>La cámara espera su primer depósito</h3>
                    <p>Los miembros aparecerán aquí en cuanto se registre el primer envío recibido.</p>
                </div>
            <?php else: ?>
                <div class="player-card-grid" data-player-list>
                    <?php foreach ($summary['players'] as $player): ?>
                        <?php $dialogId = 'player-' . preg_replace('/[^a-z0-9_-]/i', '', (string) $player['id']); ?>
                        <article class="player-card <?= $player['eligible'] ? 'is-eligible' : 'is-pending' ?>" data-player-card data-player-name="<?= h((string) $player['name']) ?>" data-player-status="<?= $player['eligible'] ? 'eligible' : 'pending' ?>">
                            <div class="player-card-head">
                                <div class="player-avatar" aria-hidden="true"><?= h(function_exists('mb_substr') ? mb_substr((string) $player['name'], 0, 1, 'UTF-8') : substr((string) $player['name'], 0, 1)) ?></div>
                                <div>
                                    <?php render_player_name((string) $player['name'], 'player-card-name'); ?>
                                    <small><?= h((string) $player['records']) ?> envío<?= (int) $player['records'] === 1 ? '' : 's' ?> registrado<?= (int) $player['records'] === 1 ? '' : 's' ?></small>
                                </div>
                                <span class="status-seal <?= $player['eligible'] ? 'status-qualified' : 'status-waiting' ?>"><?= $player['eligible'] ? 'Clasificado' : 'Pendiente' ?></span>
                            </div>

                            <div class="overall-progress">
                                <div><span>Progreso general</span><strong><?= h((string) $player['overall_percent']) ?>%</strong></div>
                                <progress value="<?= h((string) $player['overall_percent']) ?>" max="100" aria-label="Progreso general <?= h((string) $player['overall_percent']) ?>%"></progress>
                            </div>

                            <div class="player-resources">
                                <?php foreach (resource_keys() as $resource): ?>
                                    <?php
                                    $comparison = (int) $player[$resource];
                                    $target = (int) $settings['thresholds'][$resource];
                                    $complete = (int) $player['remaining'][$resource] === 0;
                                    ?>
                                    <div class="player-resource <?= $complete ? 'is-complete' : '' ?>">
                                        <?php render_resource_mark($resource); ?>
                                        <div>
                                            <span><?= h(resource_title($resource)) ?></span>
                                            <strong><?= h(format_compact_amount($comparison)) ?></strong>
                                        </div>
                                        <small><?= $complete ? 'Listo' : '−' . h(format_compact_amount((int) $player['remaining'][$resource])) ?></small>
                                        <progress value="<?= h((string) min($comparison, $target)) ?>" max="<?= h((string) $target) ?>" aria-label="<?= h(resource_title($resource)) ?> <?= h((string) progress_percent($comparison, $target)) ?>%"></progress>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <button class="player-detail-button" type="button" data-open-dialog="<?= h($dialogId) ?>">Ver ficha completa</button>

                            <dialog class="player-dialog" id="<?= h($dialogId) ?>" data-player-dialog>
                                <div class="dialog-frame">
                                    <button class="dialog-close" type="button" data-close-dialog aria-label="Cerrar">×</button>
                                    <div class="dialog-title-row">
                                        <div>
                                            <span class="eyebrow">Ficha verificable</span>
                                            <?php render_player_name((string) $player['name'], 'dialog-player-name'); ?>
                                            <p><?= h((string) $player['records']) ?> envío<?= (int) $player['records'] === 1 ? '' : 's' ?> · actualizado <?= h(format_datetime((string) $player['latest_at'])) ?></p>
                                        </div>
                                        <span class="status-seal <?= $player['eligible'] ? 'status-qualified' : 'status-waiting' ?>"><?= $player['eligible'] ? 'Clasificado' : 'Pendiente' ?></span>
                                    </div>

                                    <div class="dialog-resource-grid">
                                        <?php foreach (resource_keys() as $resource): ?>
                                            <?php $comparison = (int) $player[$resource]; ?>
                                            <div>
                                                <?php render_resource_art($resource, 'dialog-resource-art'); ?>
                                                <span><?= h(resource_title($resource)) ?></span>
                                                <strong><?= h(format_integer($comparison)) ?></strong>
                                                <small><?= (int) $player['remaining'][$resource] === 0 ? 'Requisito completado' : 'Faltan ' . h(format_integer((int) $player['remaining'][$resource])) ?></small>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <div class="dialog-history">
                                        <h3>Historial individual</h3>
                                        <div class="table-shell">
                                            <table class="data-table compact-table">
                                                <thead><tr><th scope="col">Fecha</th><th scope="col">Comida</th><th scope="col">Madera</th><th scope="col">Piedra</th><th scope="col">Oro</th></tr></thead>
                                                <tbody>
                                                    <?php foreach ($player['history'] as $record): ?>
                                                        <tr>
                                                            <td><time datetime="<?= h((string) $record['created_at']) ?>"><?= h(format_datetime((string) $record['created_at'])) ?></time></td>
                                                            <?php foreach (resource_keys() as $resource): ?>
                                                                <td><?= h(format_integer((int) $record[$resource])) ?></td>
                                                            <?php endforeach; ?>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>

                                    <div class="dialog-actions">
                                        <button class="button button-primary" type="button" data-download-player-card
                                            data-player="<?= h((string) $player['name']) ?>"
                                            data-status="<?= $player['eligible'] ? 'Clasificado' : 'Pendiente' ?>"
                                            data-food="<?= h(format_integer((int) $player['food'])) ?>"
                                            data-wood="<?= h(format_integer((int) $player['wood'])) ?>"
                                            data-stone="<?= h(format_integer((int) $player['stone'])) ?>"
                                            data-gold="<?= h(format_integer((int) $player['gold'])) ?>">Descargar tarjeta</button>
                                        <button class="button button-secondary" type="button" data-share-player="<?= h((string) $player['name']) ?>">Compartir enlace</button>
                                    </div>
                                </div>
                            </dialog>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="empty-state search-empty" data-search-empty hidden>
                    <h3>No encontramos ese nombre</h3>
                    <p>Comprueba símbolos, espacios o prueba escribiendo solo una parte.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="activity-section content-section" id="activity">
        <div class="shell">
            <div class="section-heading">
                <div>
                    <span class="eyebrow">Transparencia contable</span>
                    <h2>Movimientos del banco</h2>
                </div>
                <p>En las salidas se distingue cuánto abandona el banco y cuánto recibe el ganador. Cuando existe un informe verificado se muestra la cifra exacta; mientras esté pendiente, se identifica como aproximación.</p>
            </div>
            <?php if ($activity === []): ?>
                <div class="empty-inline">No hay movimientos registrados.</div>
            <?php else: ?>
                <div class="table-shell activity-table-shell">
                    <table class="data-table history-table">
                        <thead>
                            <tr><th scope="col">Movimiento</th><th scope="col">Jugador</th><th scope="col">Comida</th><th scope="col">Madera</th><th scope="col">Piedra</th><th scope="col">Oro</th><th scope="col">Fecha</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activity as $record): ?>
                                <tr class="<?= $record['type'] === 'out' ? 'movement-out' : 'movement-in' ?>">
                                    <td data-label="Movimiento">
                                        <span class="movement-badge"><?= $record['type'] === 'out' ? 'Salida del banco' : 'Ingreso' ?></span>
                                        <?php if ($record['type'] === 'out'): ?><small class="movement-tax-label"><?= h(number_format((float) ($record['tax_rate'] ?? $settings['tax_rate']), 0, ',', '.')) ?>% para Lilith</small><?php endif; ?>
                                    </td>
                                    <th scope="row" data-label="Jugador"><?php render_player_name((string) $record['player_name']); ?></th>
                                    <?php foreach (resource_keys() as $resource): ?>
                                        <td data-label="<?= h(resource_title($resource)) ?>">
                                            <?php if ($record['type'] === 'out'): ?>
                                                <span class="outgoing-amount"><strong><?= (int) $record[$resource] > 0 ? '−' : '' ?><?= h(format_integer((int) $record[$resource])) ?></strong><?php if ((int) $record[$resource] > 0): ?><small>Ganador<?= !empty($record['receipt_is_exact']) ? '' : ' ≈' ?> <?= h(format_integer((int) ($record['winner_receives'][$resource] ?? 0))) ?></small><?php endif; ?></span>
                                            <?php else: ?>
                                                <?= h(format_integer((int) $record[$resource])) ?>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td data-label="Fecha"><time datetime="<?= h((string) $record['created_at']) ?>"><?= h(format_datetime((string) $record['created_at'])) ?></time></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($archives !== []): ?>
        <section class="archive-section content-section">
            <div class="shell">
                <div class="section-heading">
                    <div><span class="eyebrow">Memoria de la alianza</span><h2>Eventos anteriores</h2></div>
                    <p>Ningún evento cerrado desaparece del registro.</p>
                </div>
                <div class="archive-grid">
                    <?php foreach ($archives as $archive): ?>
                        <?php $archivedSettings = settings_normalize($archive['settings'] ?? []); $archivedSummary = is_array($archive['summary'] ?? null) ? $archive['summary'] : []; ?>
                        <article class="archive-card">
                            <span><?= h(format_datetime((string) ($archive['archived_at'] ?? ''))) ?></span>
                            <h3><?= h((string) $archivedSettings['event_name']) ?></h3>
                            <?php if (winners_normalize($archive['winners'] ?? []) !== []): ?><p>Ganadores: <?= h(winners_summary_text((array) $archive['winners'])) ?></p><?php elseif ($archivedSettings['winner_name'] !== ''): ?><p>Ganadores: <?= h((string) $archivedSettings['winner_name']) ?></p><?php endif; ?>
                            <dl>
                                <div><dt>Participantes</dt><dd><?= h((string) ($archivedSummary['player_count'] ?? 0)) ?></dd></div>
                                <div><dt>Clasificados</dt><dd><?= h((string) ($archivedSummary['eligible_count'] ?? 0)) ?></dd></div>
                            </dl>
                            <a class="button button-secondary archive-card-link" href="history.php?id=<?= h(rawurlencode((string) $archive['id'])) ?>">Ver registro completo</a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section class="rules-section content-section">
        <div class="shell rules-grid">
            <div>
                <span class="eyebrow">Cómo leer el registro</span>
                <h2>Claro incluso con muchos envíos</h2>
            </div>
            <ol>
                <li><strong>Cada fila es un envío.</strong><span>Un mismo miembro puede aparecer muchas veces en movimientos.</span></li>
                <li><strong>La ficha suma todos sus envíos.</strong><span>El nombre visible permanece exactamente como fue registrado.</span></li>
                <li><strong>Clasifica al completar los cuatro recursos.</strong><span>No basta compensar un recurso con otro.</span></li>
                <li><strong>TOP 1 y TOP 2 están protegidos del impuesto.</strong><span>Reciben completos los premios publicados; TOP 3 recibe el remanente después de todos los impuestos. Lilith retiene el <?= h(number_format((float) $settings['tax_rate'], 0, ',', '.')) ?>% y el custodio retiene 0%.</span></li>
            </ol>
        </div>
    </section>
</main>
<?php render_site_footer(); ?>
