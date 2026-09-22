<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$archives = all_archives();
$requestedId = trim((string) ($_GET['id'] ?? ''));
$selectedArchive = $requestedId !== '' ? find_archive($requestedId) : ($archives[0] ?? null);

if ($requestedId !== '' && $selectedArchive === null) {
    http_response_code(404);
}

render_page_start('Historial de eventos', 'history-page');
render_site_header('history');
?>
<main>
    <section class="history-hero">
        <div class="shell">
            <span class="eyebrow">Memoria verificable de ME58</span>
            <h1>Historial completo</h1>
            <p>Cada evento cerrado conserva participantes, movimientos, ganadores, puntuaciones y la conciliación entre lo enviado, el impuesto y lo recibido realmente.</p>
        </div>
    </section>

    <section class="content-section history-content">
        <div class="shell">
            <?php if ($archives === []): ?>
                <div class="history-empty"><h2>Todavía no hay eventos archivados</h2><p>Cuando termine el primer evento, su registro completo aparecerá aquí.</p></div>
            <?php elseif ($selectedArchive === null): ?>
                <div class="history-empty"><h2>Evento no encontrado</h2><p>El enlace no corresponde a ningún evento guardado.</p><a class="button button-secondary" href="history.php">Volver al historial</a></div>
            <?php else: ?>
                <?php
                $eventSettings = settings_normalize($selectedArchive['settings'] ?? []);
                $eventSummary = (array) ($selectedArchive['summary'] ?? []);
                $eventState = database_normalize([
                    'settings' => $eventSettings,
                    'winners' => $selectedArchive['winners'] ?? [],
                    'players' => $selectedArchive['players'] ?? [],
                    'contributions' => $selectedArchive['contributions'] ?? [],
                    'disbursements' => $selectedArchive['disbursements'] ?? [],
                ]);
                $eventDetail = bank_summary_from_data($eventState);
                $eventReconciliation = event_reconciliation_from_data($eventState);
                // Cada evento conserva la etiqueta que tenía al cerrarse: un
                // evento antiguo nunca se reinterpreta con la métrica actual.
                $eventMetric = (string) $eventSettings['score_metric_label'];
                $eventFund = is_array($selectedArchive['fund_breakdown'] ?? null)
                    ? (array) $selectedArchive['fund_breakdown'] : null;
                $eventSettlement = is_array($selectedArchive['settlement'] ?? null)
                    ? (array) $selectedArchive['settlement'] : null;
                $payoutsByRecipient = [];
                foreach ($selectedArchive['disbursements'] as $record) {
                    $key = normalize_player_name((string) $record['recipient_name']);
                    if (!isset($payoutsByRecipient[$key])) {
                        $payoutsByRecipient[$key] = [
                            'recipient_name' => (string) $record['recipient_name'],
                            'sent' => array_fill_keys(resource_keys(), 0),
                            'received' => array_fill_keys(resource_keys(), 0),
                            'tax' => array_fill_keys(resource_keys(), 0),
                            'transport_count' => 0,
                            'exact' => true,
                        ];
                    }
                    foreach (resource_keys() as $resource) {
                        $payoutsByRecipient[$key]['sent'][$resource] += (int) $record[$resource];
                        $payoutsByRecipient[$key]['received'][$resource] += disbursement_actual_received($record, $resource);
                        $payoutsByRecipient[$key]['tax'][$resource] += disbursement_tax_amount($record, $resource);
                    }
                    $payoutsByRecipient[$key]['transport_count'] += (int) ($record['transport_count'] ?? 0);
                    $payoutsByRecipient[$key]['exact'] = $payoutsByRecipient[$key]['exact'] && disbursement_has_exact_receipt($record);
                }
                ?>

                <nav class="history-selector" aria-label="Eventos archivados">
                    <?php foreach ($archives as $archive): ?>
                        <?php $archiveSettings = settings_normalize($archive['settings'] ?? []); ?>
                        <a class="<?= (string) $archive['id'] === (string) $selectedArchive['id'] ? 'is-active' : '' ?>" href="history.php?id=<?= h(rawurlencode((string) $archive['id'])) ?>"><strong><?= h((string) $archiveSettings['event_name']) ?></strong><small><?= h(format_datetime((string) ($archive['archived_at'] ?? ''))) ?></small></a>
                    <?php endforeach; ?>
                </nav>

                <article class="history-event-heading">
                    <div>
                        <span class="eyebrow">Evento cerrado</span>
                        <h2><?= h((string) $eventSettings['event_name']) ?></h2>
                        <p>Archivado el <?= h(format_datetime((string) ($selectedArchive['archived_at'] ?? ''))) ?>.</p>
                        <p class="history-objective">Objetivo: <strong><?= h((string) $eventSettings['objective_title']) ?></strong> · Clasificación por <strong><?= h($eventMetric) ?></strong> (<?= h(ranking_method_label((string) $eventSettings['ranking_method'])) ?>)</p>
                        <?php if ((string) $eventSettings['objective_description'] !== ''): ?><p class="history-objective-description"><?= h((string) $eventSettings['objective_description']) ?></p><?php endif; ?>
                    </div>
                    <span class="history-verified-badge <?= !empty($eventReconciliation['ready']) ? '' : 'history-review-badge' ?>"><?= !empty($eventReconciliation['ready']) ? 'Registro conciliado' : 'Requiere revisión' ?></span>
                </article>

                <?php if (empty($eventReconciliation['ready'])): ?>
                    <div class="history-review-notice" role="status"><strong>Este archivo tiene comprobaciones pendientes:</strong><ul><?php foreach ($eventReconciliation['checks'] as $check): ?><?php if (empty($check['passed'])): ?><li><?= h((string) $check['label']) ?></li><?php endif; ?><?php endforeach; ?></ul></div>
                <?php endif; ?>

                <section class="history-summary-grid public-history-summary" aria-label="Resumen del evento">
                    <div><span>Participantes</span><strong><?= h((string) ($eventSummary['player_count'] ?? 0)) ?></strong></div>
                    <div><span>Aportes recibidos</span><strong><?= h(format_integer((int) ($eventSummary['received_grand_total'] ?? 0))) ?></strong></div>
                    <div><span>Enviado bruto</span><strong><?= h(format_integer((int) ($eventSummary['paid_grand_total'] ?? 0))) ?></strong></div>
                    <div><span>Recibido por ganadores</span><strong><?= h(format_integer((int) ($eventSummary['winner_receives_grand_total'] ?? 0))) ?></strong></div>
                    <div><span>Impuesto de Lilith</span><strong><?= h(format_integer((int) ($eventSummary['outgoing_tax_grand_total'] ?? 0))) ?></strong></div>
                </section>

                <section class="history-explanation">
                    <div><span class="eyebrow">Por qué aparecen dos cifras</span><h2>Enviado no significa recibido</h2></div>
                    <p>“Enviado bruto” es lo que salió de la ciudad del custodio. Lilith retuvo el <?= h(number_format((float) $eventSettings['tax_rate'], 0, ',', '.')) ?>% durante los transportes. Por eso la clasificación de asistencia muestra <strong><?= h(format_integer((int) ($eventSummary['winner_receives_grand_total'] ?? 0))) ?></strong>, que es la suma que llegó realmente a los ganadores: <?= h(format_integer((int) ($eventSummary['paid_grand_total'] ?? 0))) ?> enviados menos <?= h(format_integer((int) ($eventSummary['outgoing_tax_grand_total'] ?? 0))) ?> de impuesto.</p>
                </section>

                <section class="history-section-block history-resource-ledger">
                    <div class="section-heading"><div><span class="eyebrow">Conciliación por recurso</span><h2>Del banco al ganador</h2></div></div>
                    <div class="table-shell"><table class="data-table"><thead><tr><th>Recurso</th><th>Recibido por el banco</th><th>Enviado bruto</th><th>Impuesto</th><th>Recibido por ganadores</th><th>Saldo</th></tr></thead><tbody>
                        <?php foreach (resource_keys() as $resource): ?><tr><th scope="row"><span class="history-resource-name"><?php render_resource_mark($resource); ?> <?= h(resource_title($resource)) ?></span></th><td><?= h(format_integer((int) ($eventSummary['received'][$resource] ?? 0))) ?></td><td><?= h(format_integer((int) ($eventSummary['paid'][$resource] ?? 0))) ?></td><td><?= h(format_integer((int) ($eventSummary['outgoing_tax'][$resource] ?? 0))) ?></td><td><?= h(format_integer((int) ($eventSummary['winner_receives'][$resource] ?? 0))) ?></td><td><?= h(format_integer((int) ($eventSummary['balance'][$resource] ?? 0))) ?></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                </section>

                <section class="history-section-block" id="reglas">
                    <div class="section-heading"><div><span class="eyebrow">Reglas congeladas de la semana</span><h2>Cuotas, reparto y reserva</h2></div><p>Estos valores son los que estaban vigentes al cerrar el evento. Cambiar la configuración de una semana futura no los modifica.</p></div>
                    <div class="history-rules-grid">
                        <div><span>Cuota de comida</span><strong><?= h(format_integer((int) $eventSettings['thresholds']['food'])) ?></strong></div>
                        <div><span>Cuota de madera</span><strong><?= h(format_integer((int) $eventSettings['thresholds']['wood'])) ?></strong></div>
                        <div><span>Cuota de piedra</span><strong><?= h(format_integer((int) $eventSettings['thresholds']['stone'])) ?></strong></div>
                        <div><span>Cuota de oro</span><strong><?= h(format_integer((int) $eventSettings['thresholds']['gold'])) ?></strong></div>
                        <div><span>Porcentaje repartido</span><strong><?= h(basis_points_to_text((int) $eventSettings['prize_pool_basis_points'])) ?>%</strong></div>
                        <div><span>Pesos de los puestos</span><strong><?= h(implode(' : ', array_map(static function ($weight): string { return rtrim(rtrim(number_format((float) $weight, 2, ',', ''), '0'), ','); }, (array) $eventSettings['reward_weights']))) ?></strong></div>
                        <div><span>Impuesto del juego</span><strong><?= h(number_format((float) $eventSettings['tax_rate'], 2, ',', '.')) ?>%</strong></div>
                        <div><span>Método de clasificación</span><strong><?= h(ranking_method_label((string) $eventSettings['ranking_method'])) ?></strong></div>
                    </div>

                    <?php if ($eventFund !== null): ?>
                        <div class="table-shell">
                            <table class="data-table fund-detail-table">
                                <caption>Fotografía contable por recurso guardada al cerrar el evento.</caption>
                                <thead><tr><th scope="col">Recurso</th><th scope="col">Reserva de apertura</th><th scope="col">Aportado</th><th scope="col">Elegible</th><th scope="col">Premio del evento</th><th scope="col">Retenido como reserva</th><th scope="col">Reserva utilizada</th><th scope="col">Fondo bruto</th><th scope="col">Reserva al cierre</th></tr></thead>
                                <tbody>
                                    <?php foreach (resource_keys() as $resource): ?>
                                        <tr>
                                            <th scope="row" data-label="Recurso"><span class="history-resource-name"><?php render_resource_mark($resource); ?> <?= h(resource_title($resource)) ?></span></th>
                                            <td data-label="Reserva de apertura"><?= h(format_integer((int) ($eventFund['reserve_opening'][$resource] ?? 0))) ?></td>
                                            <td data-label="Aportado"><?= h(format_integer((int) ($eventFund['contributed_total'][$resource] ?? 0))) ?></td>
                                            <td data-label="Elegible"><?= h(format_integer((int) ($eventFund['eligible_total'][$resource] ?? 0))) ?></td>
                                            <td data-label="Premio del evento"><?= h(format_integer((int) ($eventFund['prize_from_event'][$resource] ?? 0))) ?></td>
                                            <td data-label="Retenido como reserva"><?= h(format_integer((int) ($eventFund['retained_from_eligible'][$resource] ?? 0))) ?></td>
                                            <td data-label="Reserva utilizada"><?= h(format_integer((int) ($eventFund['reserve_draw'][$resource] ?? 0))) ?></td>
                                            <td data-label="Fondo bruto"><?= h(format_integer((int) ($eventFund['prize_fund_gross'][$resource] ?? 0))) ?></td>
                                            <td data-label="Reserva al cierre"><?= h(format_integer((int) ($eventFund['reserve_closing'][$resource] ?? 0))) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="panel-note">Este evento se cerró con una versión anterior del banco, por eso no guarda el desglose de reserva. Sus aportes, entregas, impuestos y ganadores se conservan intactos tal como se registraron.</p>
                    <?php endif; ?>

                    <?php
                    $eventRules = [];
                    foreach (reward_rank_keys() as $rank) {
                        if ((string) $eventSettings['rank_rule_' . $rank] !== '') {
                            $eventRules[$rank] = (string) $eventSettings['rank_rule_' . $rank];
                        }
                    }
                    ?>
                    <?php if ($eventRules !== []): ?>
                        <ul class="history-rank-rules">
                            <?php foreach ($eventRules as $rank => $rule): ?>
                                <li><strong><?= h(reward_rank_label((string) $rank)) ?></strong><span><?= h($rule) ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>

                <section class="history-section-block" id="ganadores">
                    <div class="section-heading"><div><span class="eyebrow">Resultado oficial</span><h2>Ganadores y premios</h2></div><p>Las cifras recibidas se verificaron con los informes de asistencia del juego.</p></div>
                    <div class="history-winner-grid">
                        <?php foreach (winners_normalize($selectedArchive['winners'] ?? [], $eventMetric) as $winner): ?>
                            <?php
                            $payout = $payoutsByRecipient[normalize_player_name((string) $winner['player_name'])] ?? null;
                            $sentTotal = $payout === null ? 0 : array_sum($payout['sent']);
                            $receivedTotal = $payout === null ? 0 : array_sum($payout['received']);
                            $taxTotal = $payout === null ? 0 : array_sum($payout['tax']);
                            ?>
                            <article class="history-winner-card history-rank-<?= h((string) $winner['position']) ?>">
                                <header><span><?= h((string) $winner['position']) ?>.º lugar</span><h3><?php render_player_name((string) $winner['player_name']); ?></h3><strong><?= h(format_integer((int) $winner['score'])) ?> <?= h(trim((string) $winner['metric']) !== '' ? (string) $winner['metric'] : $eventMetric) ?></strong><?php if (trim((string) ($winner['rank_rule'] ?? '')) !== ''): ?><small class="history-rank-rule"><?= h((string) $winner['rank_rule']) ?></small><?php endif; ?></header>
                                <dl class="winner-money-summary"><div><dt>Enviado</dt><dd><?= h(format_integer($sentTotal)) ?></dd></div><div><dt>Recibido</dt><dd><?= h(format_integer($receivedTotal)) ?></dd></div><div><dt>Impuesto</dt><dd><?= h(format_integer($taxTotal)) ?></dd></div><div><dt>Transportes</dt><dd><?= h((string) ($payout['transport_count'] ?? 0)) ?></dd></div></dl>
                                <?php if ($payout !== null): ?>
                                    <div class="winner-resource-breakdown">
                                        <?php foreach (resource_keys() as $resource): ?><div><span><?php render_resource_mark($resource); ?> <?= h(resource_title($resource)) ?></span><small>Enviado <?= h(format_integer((int) $payout['sent'][$resource])) ?></small><strong>Recibido <?= h(format_integer((int) $payout['received'][$resource])) ?></strong></div><?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="history-section-block" id="participantes">
                    <div class="section-heading"><div><span class="eyebrow">Aportes acumulados</span><h2>Participantes</h2></div><p><?= h((string) ($eventSummary['record_count'] ?? 0)) ?> movimientos originales, sin resumir ni borrar.</p></div>
                    <div class="table-shell"><table class="data-table history-detail-table"><caption>Bajo cada aporte se indica la cuota que regía esa semana y el faltante o el excedente frente a ella.</caption><thead><tr><th>Jugador</th><th>Comida</th><th>Madera</th><th>Piedra</th><th>Oro</th><th>Envíos</th><th>Estado</th></tr></thead><tbody>
                        <?php foreach ($eventDetail['players'] as $player): ?>
                            <tr>
                                <th scope="row" data-label="Jugador"><?php render_player_name((string) $player['name']); ?></th>
                                <?php foreach (resource_keys() as $resource): ?>
                                    <?php
                                    $given = (int) $player[$resource];
                                    $quota = (int) $eventSettings['thresholds'][$resource];
                                    $missing = (int) $player['remaining'][$resource];
                                    ?>
                                    <td data-label="<?= h(resource_title($resource)) ?>">
                                        <strong><?= h(format_integer($given)) ?></strong>
                                        <small>Cuota <?= h(format_integer($quota)) ?></small>
                                        <small class="<?= $missing === 0 ? 'quota-ok' : 'quota-missing' ?>"><?= $missing === 0 ? 'Excedente +' . h(format_integer($given - $quota)) : 'Faltaron ' . h(format_integer($missing)) ?></small>
                                    </td>
                                <?php endforeach; ?>
                                <td data-label="Envíos"><?= h((string) $player['records']) ?></td>
                                <td data-label="Estado"><span class="<?= !empty($player['eligible']) ? 'status-inline status-eligible' : 'status-inline status-pending' ?>"><?= !empty($player['eligible']) ? 'Completó' : 'Parcial' ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody></table></div>
                </section>

                <section class="history-section-block" id="movimientos">
                    <div class="section-heading"><div><span class="eyebrow">Evidencia contable</span><h2>Todos los movimientos</h2></div><p>Una fila por cada aporte o entrega guardada.</p></div>
                    <details class="history-movement-details"><summary>Mostrar <?= h((string) count($selectedArchive['contributions'])) ?> aportes individuales</summary><div class="table-shell"><table class="data-table history-detail-table"><thead><tr><th>Jugador</th><th>Comida</th><th>Madera</th><th>Piedra</th><th>Oro</th><th>Fecha</th><th>Nota</th></tr></thead><tbody>
                        <?php foreach ($selectedArchive['contributions'] as $record): ?><tr><th scope="row"><?php render_player_name((string) ($record['player_name_snapshot'] ?? '')); ?></th><?php foreach (resource_keys() as $resource): ?><td><?= h(format_integer((int) $record[$resource])) ?></td><?php endforeach; ?><td><?= h(format_datetime((string) $record['created_at'])) ?></td><td><?= h((string) ($record['note'] ?? '')) ?></td></tr><?php endforeach; ?>
                    </tbody></table></div></details>
                </section>

                <?php if (($selectedArchive['revision_log'] ?? []) !== []): ?>
                    <section class="history-section-block"><div class="section-heading"><div><span class="eyebrow">Transparencia</span><h2>Correcciones registradas</h2></div></div><div class="public-revision-list"><?php foreach (array_reverse((array) $selectedArchive['revision_log']) as $revision): ?><article><strong><?= h((string) ($revision['description'] ?? 'Corrección histórica')) ?></strong><span><?= h(format_datetime((string) ($revision['created_at'] ?? ''))) ?></span><p><?= h((string) ($revision['reason'] ?? '')) ?></p></article><?php endforeach; ?></div></section>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php render_site_footer(); ?>
