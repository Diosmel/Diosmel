<?php
declare(strict_types=1);

define('ROKBANK_ADMIN_CONTEXT', true);

require_once __DIR__ . '/includes/layout.php';
require_admin();

$validViews = ['dashboard', 'batch', 'payouts', 'players', 'event', 'prize', 'reserve', 'settlement', 'announcements', 'history', 'audit', 'security'];
$view = (string) ($_GET['view'] ?? 'dashboard');
if (!in_array($view, $validViews, true)) {
    $view = 'dashboard';
}

$errors = [];
$newForm = ['player_id' => '', 'player_name' => '', 'food' => '', 'wood' => '', 'stone' => '', 'gold' => '', 'note' => ''];
$payoutForm = [
    'recipient_name' => '', 'food' => '', 'wood' => '', 'stone' => '', 'gold' => '',
    'actual_food' => '', 'actual_wood' => '', 'actual_stone' => '', 'actual_gold' => '',
    'transport_count' => '', 'note' => '', 'evidence_note' => '',
];
$batchText = '';
$batchPreview = null;
$settingsWarnings = [];
$settingsPreview = null;
$settingsPreviewSummary = null;
$announcementForm = null;
$announcementPreview = null;
$announcementEditId = (int) ($_GET['announcement_id'] ?? 0);
$announcementRevisions = [];
$lateContributionForm = ['player_name' => '', 'food' => '', 'wood' => '', 'stone' => '', 'gold' => '', 'note' => '', 'policy' => 'next_event'];
$editForm = null;
$editId = trim((string) ($_GET['id'] ?? ''));
$payoutEditId = trim((string) ($_GET['payout_id'] ?? ''));
$archiveId = trim((string) ($_GET['archive_id'] ?? $_POST['archive_id'] ?? ''));
$archiveContributionId = trim((string) ($_GET['archive_contribution_id'] ?? ''));
$archiveDisbursementId = trim((string) ($_GET['archive_disbursement_id'] ?? ''));
$settingsForm = current_settings();

if (is_post()) {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'add') {
            $view = 'dashboard';
            $newForm = array_merge($newForm, array_intersect_key($_POST, $newForm));
            $input = contribution_input($_POST);
            $errors = $input['errors'];
            if ($errors === []) {
                add_contribution($input['values']);
                set_flash('success', 'Envío añadido. El acumulado del jugador y el saldo del banco fueron actualizados.');
                redirect('admin.php');
            }
        } elseif ($action === 'update') {
            $view = 'dashboard';
            $editId = trim((string) ($_POST['id'] ?? ''));
            $input = contribution_input($_POST);
            $errors = $input['errors'];
            $editForm = array_merge($input['values'], ['id' => $editId, 'created_at' => (string) ($_POST['created_at'] ?? '')]);
            if ($errors === []) {
                if (!update_contribution($editId, $input['values'])) {
                    $errors[] = 'El envío que intentas editar ya no existe.';
                } else {
                    set_flash('success', 'Envío corregido y totales recalculados.');
                    redirect('admin.php');
                }
            }
        } elseif ($action === 'delete') {
            $deleted = delete_contribution((string) ($_POST['id'] ?? ''));
            set_flash($deleted ? 'success' : 'error', $deleted ? 'Envío eliminado y totales recalculados.' : 'El envío ya no existe.');
            redirect('admin.php');
        } elseif ($action === 'batch_preview') {
            $view = 'batch';
            $batchText = (string) ($_POST['batch_text'] ?? '');
            if (strlen($batchText) > 120000) {
                $errors[] = 'El lote es demasiado grande. Divídelo en varias partes.';
            } else {
                $parsed = parse_batch_text($batchText);
                $errors = $parsed['errors'];
                if ($errors === []) {
                    $token = bin2hex(random_bytes(24));
                    $_SESSION['batch_preview'] = ['token' => $token, 'rows' => $parsed['rows'], 'created_at' => time()];
                    $batchPreview = ['token' => $token, 'items' => build_batch_preview($parsed['rows'])];
                }
            }
        } elseif ($action === 'batch_confirm') {
            $view = 'batch';
            $saved = $_SESSION['batch_preview'] ?? null;
            $token = (string) ($_POST['batch_token'] ?? '');
            if (!is_array($saved) || empty($saved['token']) || !hash_equals((string) $saved['token'], $token) || time() - (int) ($saved['created_at'] ?? 0) > 1800) {
                $errors[] = 'La vista previa venció. Prepara el lote nuevamente.';
            } else {
                $count = add_contributions_batch((array) $saved['rows']);
                unset($_SESSION['batch_preview']);
                set_flash('success', $count . ' envío' . ($count === 1 ? '' : 's') . ' registrado' . ($count === 1 ? '' : 's') . ' correctamente.');
                redirect('admin.php?view=batch');
            }
        } elseif ($action === 'batch_cancel') {
            unset($_SESSION['batch_preview']);
            redirect('admin.php?view=batch');
        } elseif ($action === 'add_disbursement') {
            $view = 'payouts';
            $payoutForm = array_merge($payoutForm, array_intersect_key($_POST, $payoutForm));
            $input = disbursement_input($_POST);
            $errors = $input['errors'];
            if ($errors === []) {
                $errors = add_disbursement($input['values']);
            }
            if ($errors === []) {
                set_flash('success', 'Salida registrada. Se descontó del banco la cantidad enviada y se calculó aparte el impuesto aplicado por Lilith al ganador.');
                redirect('admin.php?view=payouts');
            }
        } elseif ($action === 'update_disbursement') {
            $view = 'payouts';
            $payoutEditId = trim((string) ($_POST['id'] ?? ''));
            $payoutForm = array_merge($payoutForm, array_intersect_key($_POST, $payoutForm));
            $input = disbursement_input($_POST);
            $errors = $input['errors'];
            if ($errors === []) {
                $errors = update_disbursement($payoutEditId, $input['values']);
            }
            if ($errors === []) {
                set_flash('success', 'Entrega corregida. Los totales bruto, neto e impuesto fueron recalculados.');
                redirect('admin.php?view=payouts');
            }
        } elseif ($action === 'delete_disbursement') {
            $view = 'payouts';
            $errors = delete_disbursement((string) ($_POST['id'] ?? ''));
            if ($errors === []) {
                set_flash('success', 'Entrega eliminada y saldo restaurado.');
                redirect('admin.php?view=payouts');
            }
        } elseif ($action === 'rename_player') {
            $view = 'players';
            $errors = rename_player((string) ($_POST['player_id'] ?? ''), (string) ($_POST['new_name'] ?? ''));
            if ($errors === []) {
                set_flash('success', 'Nombre corregido en todo el evento sin alterar sus envíos.');
                redirect('admin.php?view=players');
            }
        } elseif ($action === 'preview_settings') {
            $view = in_array((string) ($_POST['return_view'] ?? ''), ['event', 'prize'], true)
                ? (string) $_POST['return_view'] : 'prize';
            $input = settings_input($_POST);
            $errors = $input['errors'];
            $settingsWarnings = (array) ($input['warnings'] ?? []);
            $settingsForm = $input['values'];
            if ($errors === []) {
                $settingsPreview = settings_change_preview(current_settings(), settings_normalize(
                    array_merge(current_settings(), $input['values'])
                ));
                $settingsPreviewSummary = settings_projection_preview($input['values']);
            }
        } elseif ($action === 'save_settings') {
            $view = in_array((string) ($_POST['return_view'] ?? ''), ['event', 'prize'], true)
                ? (string) $_POST['return_view'] : 'event';
            $input = settings_input($_POST);
            $errors = $input['errors'];
            $settingsWarnings = (array) ($input['warnings'] ?? []);
            $settingsForm = $input['values'];
            if ($errors === []) {
                $errors = save_settings($input['values']);
            }
            if ($errors === []) {
                $message = 'Configuración del evento actualizada.';
                if ($settingsWarnings !== []) {
                    $message .= ' Aviso: ' . implode(' ', $settingsWarnings);
                }
                set_flash($settingsWarnings === [] ? 'success' : 'info', $message);
                redirect('admin.php?view=' . $view);
            }
        } elseif ($action === 'freeze_settlement') {
            $view = 'settlement';
            $errors = freeze_settlement();
            if ($errors === []) {
                set_flash('success', 'Liquidación congelada. Los valores financieros quedaron protegidos hasta desbloquearla.');
                redirect('admin.php?view=settlement');
            }
        } elseif ($action === 'unfreeze_settlement') {
            $view = 'settlement';
            $errors = unfreeze_settlement((string) ($_POST['unfreeze_reason'] ?? ''));
            if ($errors === []) {
                set_flash('info', 'Liquidación desbloqueada. El motivo quedó registrado en la auditoría.');
                redirect('admin.php?view=settlement');
            }
        } elseif ($action === 'reserve_adjustment') {
            $view = 'reserve';
            $errors = reserve_manual_adjustment(
                (string) ($_POST['resource'] ?? ''),
                (string) ($_POST['direction'] ?? ''),
                (string) ($_POST['amount'] ?? ''),
                (string) ($_POST['reason'] ?? '')
            );
            if ($errors === []) {
                set_flash('success', 'Movimiento de reserva registrado. El libro mayor sólo admite movimientos nuevos.');
                redirect('admin.php?view=reserve');
            }
        } elseif ($action === 'add_late_contribution') {
            $view = 'dashboard';
            $lateContributionForm = array_merge($lateContributionForm, array_intersect_key($_POST, $lateContributionForm));
            $input = contribution_input($_POST);
            $errors = $input['errors'];
            if ($errors === []) {
                $errors = add_late_contribution(
                    array_merge($input['values'], ['player_name' => (string) $lateContributionForm['player_name']]),
                    (string) ($_POST['policy'] ?? '')
                );
            }
            if ($errors === []) {
                set_flash('success', 'Aporte tardío registrado sin modificar el premio ya congelado.');
                redirect('admin.php');
            }
        } elseif ($action === 'delete_pending_contribution') {
            delete_pending_contribution((string) ($_POST['id'] ?? ''));
            set_flash('success', 'Aporte tardío descartado.');
            redirect('admin.php');
        } elseif (strpos($action, 'announcement_') === 0 || strpos($action, 'media_') === 0) {
            $view = 'announcements';
            require __DIR__ . '/includes/admin_announcements.php';
        } elseif ($action === 'archive_event') {
            $view = 'event';
            $errors = archive_current_event((string) ($_POST['next_event_name'] ?? ''), (string) ($_POST['archive_confirmation'] ?? ''));
            if ($errors === []) {
                set_flash('success', 'Evento archivado. El nuevo evento está listo y el historial anterior permanece guardado.');
                redirect('admin.php?view=event');
            }
        } elseif ($action === 'update_archived_event') {
            $view = 'history';
            $archiveId = trim((string) ($_POST['archive_id'] ?? ''));
            $errors = update_archived_event($archiveId, $_POST);
            if ($errors === []) {
                set_flash('success', 'Evento histórico corregido. La versión anterior quedó protegida en una copia automática.');
                redirect('admin.php?view=history&archive_id=' . rawurlencode($archiveId));
            }
        } elseif ($action === 'update_archived_contribution') {
            $view = 'history';
            $archiveId = trim((string) ($_POST['archive_id'] ?? ''));
            $recordId = trim((string) ($_POST['record_id'] ?? ''));
            $errors = update_archived_contribution($archiveId, $recordId, $_POST);
            if ($errors === []) {
                set_flash('success', 'Aporte histórico corregido y totales recalculados.');
                redirect('admin.php?view=history&archive_id=' . rawurlencode($archiveId));
            }
            $archiveContributionId = $recordId;
        } elseif ($action === 'update_archived_disbursement') {
            $view = 'history';
            $archiveId = trim((string) ($_POST['archive_id'] ?? ''));
            $recordId = trim((string) ($_POST['record_id'] ?? ''));
            $errors = update_archived_disbursement($archiveId, $recordId, $_POST);
            if ($errors === []) {
                set_flash('success', 'Entrega histórica corregida y conciliación recalculada.');
                redirect('admin.php?view=history&archive_id=' . rawurlencode($archiveId));
            }
            $archiveDisbursementId = $recordId;
        } elseif ($action === 'undo') {
            if (restore_latest_backup()) {
                set_flash('success', 'Último cambio deshecho mediante la copia automática.');
            } else {
                set_flash('error', 'Todavía no existe una copia automática para restaurar.');
            }
            redirect('admin.php');
        } elseif ($action === 'change_password') {
            $view = 'security';
            $errors = change_admin_password(
                (string) ($_POST['current_password'] ?? ''),
                (string) ($_POST['new_password'] ?? ''),
                (string) ($_POST['new_password_confirmation'] ?? '')
            );
            if ($errors === []) {
                set_flash('success', 'Contraseña cambiada. Las demás sesiones quedaron cerradas.');
                redirect('admin.php?view=security');
            }
        } else {
            $errors[] = 'La acción solicitada no es válida.';
        }
    } catch (Throwable $exception) {
        $errors[] = 'No se pudo guardar el cambio: ' . $exception->getMessage();
    }
}

if ($editId !== '' && $editForm === null) {
    $record = find_contribution($editId);
    if ($record === null) {
        set_flash('error', 'No se encontró el envío solicitado.');
        redirect('admin.php');
    }
    $editForm = $record;
    $view = 'dashboard';
}

if ($payoutEditId !== '' && !is_post()) {
    $record = find_disbursement($payoutEditId);
    if ($record === null) {
        set_flash('error', 'No se encontró la entrega solicitada.');
        redirect('admin.php?view=payouts');
    }
    $payoutForm = array_merge($payoutForm, [
        'recipient_name' => (string) $record['recipient_name'],
        'food' => (string) $record['food'], 'wood' => (string) $record['wood'],
        'stone' => (string) $record['stone'], 'gold' => (string) $record['gold'],
        'actual_food' => (string) ($record['actual_received']['food'] ?? ''),
        'actual_wood' => (string) ($record['actual_received']['wood'] ?? ''),
        'actual_stone' => (string) ($record['actual_received']['stone'] ?? ''),
        'actual_gold' => (string) ($record['actual_received']['gold'] ?? ''),
        'transport_count' => (string) ($record['transport_count'] ?? ''),
        'note' => (string) ($record['note'] ?? ''),
        'evidence_note' => (string) ($record['evidence_note'] ?? ''),
    ]);
    $view = 'payouts';
}

$summary = bank_summary();
$settings = $summary['settings'];
$contributions = all_contributions();
$disbursements = all_disbursements();
$players = all_players();
$archives = $view === 'history' ? all_archives() : [];
$selectedArchive = $archiveId !== '' ? find_archive($archiveId) : null;
$currentMetricLabel = (string) ($settingsForm['score_metric_label'] ?? $settings['score_metric_label']);
$currentWinners = winners_normalize($settingsForm['_winners'] ?? (database_read()['winners'] ?? []), $currentMetricLabel);
if ($currentWinners === []) {
    $currentWinners = winners_from_legacy_summary((string) ($settingsForm['winner_name'] ?? ''), $currentMetricLabel);
}
$currentWinnersByRank = [];
foreach ($currentWinners as $winner) {
    $currentWinnersByRank[(string) $winner['rank']] = $winner;
}
$archiveContributionEdit = null;
$archiveDisbursementEdit = null;
if ($selectedArchive !== null) {
    foreach ($selectedArchive['contributions'] as $record) {
        if ($archiveContributionId !== '' && hash_equals((string) ($record['id'] ?? ''), $archiveContributionId)) {
            $archiveContributionEdit = $record;
            break;
        }
    }
    foreach ($selectedArchive['disbursements'] as $record) {
        if ($archiveDisbursementId !== '' && hash_equals((string) ($record['id'] ?? ''), $archiveDisbursementId)) {
            $archiveDisbursementEdit = $record;
            break;
        }
    }
}
$eventReconciliation = in_array($view, ['event', 'prize', 'settlement'], true) ? current_event_reconciliation() : null;
$freezeChecks = $view === 'settlement' ? current_settlement_freeze_checks() : null;
$fundBreakdown = $summary['fund_breakdown'];
$reserveBalances = reserve_balances_from_movements((array) database_read()['reserve_movements']);
$reserveOpening = $fundBreakdown['reserve_opening'];
$reserveLedger = $view === 'reserve' ? array_reverse((array) database_read()['reserve_movements']) : [];
$pendingContributions = all_pending_contributions();
$settlementFrozen = in_array((string) $settings['settlement_status'], ['frozen', 'closed'], true);
$announcementsReady = announcements_available();
$announcementStatusFilter = (string) ($_GET['status'] ?? '');
if (!in_array($announcementStatusFilter, announcement_status_keys(), true)) {
    $announcementStatusFilter = '';
}
$announcementList = ($view === 'announcements' && $announcementsReady)
    ? announcement_list($announcementStatusFilter, 200) : [];
$mediaLibrary = ($view === 'announcements' && $announcementsReady) ? media_list(200) : [];
$mediaLimits = media_limit_report();
if ($view === 'announcements' && $announcementsReady && $announcementForm === null && $announcementEditId > 0) {
    $existingAnnouncement = announcement_find($announcementEditId);
    if ($existingAnnouncement !== null) {
        $announcementForm = $existingAnnouncement;
        $announcementRevisions = announcement_revisions($announcementEditId, 30);
    }
}
if ($view === 'announcements' && $announcementsReady && $announcementForm !== null && (int) ($announcementForm['id'] ?? 0) > 0) {
    $announcementRevisions = announcement_revisions((int) $announcementForm['id'], 30);
}
$auditEntries = $view === 'audit' ? all_audit_entries() : [];
$admin = admin_record();
$backup = latest_backup_info();
$databaseStatus = database_status();

render_page_start('Administrar', 'admin-page');
render_site_header('admin');
?>
<main class="admin-main">
    <div class="shell">
        <?php render_flash_message(); ?>

        <div class="admin-topbar">
            <div>
                <span class="eyebrow">Centro de control privado</span>
                <h1>Administración del banco</h1>
                <p><?= h((string) $settings['event_name']) ?> · <?= h(event_phase_label(event_phase($settings))) ?></p>
            </div>
            <a class="button button-secondary" href="index.php">Ver página pública</a>
        </div>

        <nav class="admin-tabs" aria-label="Secciones administrativas">
            <?php
            $tabs = [
                'dashboard' => 'Envíos',
                'batch' => 'Importar lote',
                'payouts' => 'Entregas',
                'players' => 'Jugadores',
                'event' => 'Evento',
                'prize' => 'Premio y reserva',
                'settlement' => 'Liquidación',
                'reserve' => 'Libro de reservas',
                'announcements' => 'Comunicados',
                'history' => 'Historial',
                'audit' => 'Actividad',
                'security' => 'Seguridad',
            ];
            foreach ($tabs as $tabKey => $tabLabel):
            ?>
                <a class="<?= $view === $tabKey ? 'is-active' : '' ?>" href="admin.php<?= $tabKey === 'dashboard' ? '' : '?view=' . h($tabKey) ?>"><?= h($tabLabel) ?></a>
            <?php endforeach; ?>
        </nav>

        <?php render_errors($errors); ?>

        <?php if ($settingsWarnings !== []): ?>
            <div class="form-errors form-warnings" role="status">
                <strong>Aviso sobre la clasificación:</strong>
                <ul><?php foreach ($settingsWarnings as $warning): ?><li><?= h((string) $warning) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <?php if ($settingsPreview !== null): ?>
            <section class="admin-panel settings-preview-panel" data-testid="settings-change-preview">
                <div class="panel-heading"><div><span class="eyebrow">Revisión antes de guardar</span><h2>Qué cambiaría exactamente</h2></div><span class="record-count"><?= h((string) count(array_filter($settingsPreview, static function (array $row): bool { return !empty($row['changed']); }))) ?> cambios</span></div>
                <p class="panel-intro">Nada se ha guardado todavía. Compara los valores y pulsa Guardar en el formulario de abajo si estás de acuerdo.</p>
                <div class="table-shell"><table class="data-table admin-table"><thead><tr><th>Ajuste</th><th>Valor actual</th><th>Valor nuevo</th></tr></thead><tbody>
                    <?php foreach ($settingsPreview as $row): ?>
                        <tr class="<?= !empty($row['changed']) ? 'settings-row-changed' : '' ?>">
                            <th scope="row" data-label="Ajuste"><?= h((string) $row['label']) ?></th>
                            <td data-label="Valor actual"><?= h((string) $row['before']) ?></td>
                            <td data-label="Valor nuevo"><strong><?= h((string) $row['after']) ?></strong><?= !empty($row['changed']) ? ' <span class="settings-changed-mark">cambia</span>' : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody></table></div>

                <?php if ($settingsPreviewSummary !== null): ?>
                    <?php $previewFund = $settingsPreviewSummary['fund_breakdown']; $previewPrizes = $settingsPreviewSummary['reward_projection']['prizes']; ?>
                    <h3 class="settings-subtitle">Cómo quedaría el reparto</h3>
                    <div class="table-shell"><table class="data-table admin-table"><thead><tr><th>Recurso</th><th>Fondo bruto ahora</th><th>Fondo bruto nuevo</th><th>Reserva al cierre ahora</th><th>Reserva al cierre nueva</th></tr></thead><tbody>
                        <?php foreach (resource_keys() as $resource): ?>
                            <tr>
                                <th scope="row" data-label="Recurso"><?= h(resource_title($resource)) ?></th>
                                <td data-label="Fondo bruto ahora"><?= h(format_integer((int) $fundBreakdown['prize_fund_gross'][$resource])) ?></td>
                                <td data-label="Fondo bruto nuevo"><strong><?= h(format_integer((int) $previewFund['prize_fund_gross'][$resource])) ?></strong></td>
                                <td data-label="Reserva al cierre ahora"><?= h(format_integer((int) $fundBreakdown['reserve_closing'][$resource])) ?></td>
                                <td data-label="Reserva al cierre nueva"><strong><?= h(format_integer((int) $previewFund['reserve_closing'][$resource])) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody></table></div>
                    <div class="table-shell"><table class="data-table admin-table"><thead><tr><th>Puesto</th><th>Bruto previsto</th><th>Impuesto previsto</th><th>Neto previsto</th></tr></thead><tbody>
                        <?php foreach (reward_rank_keys() as $rank): ?>
                            <tr>
                                <th scope="row" data-label="Puesto"><?= h(reward_rank_label($rank)) ?></th>
                                <td data-label="Bruto previsto"><?= h(format_integer((int) $previewPrizes[$rank]['sent_total'])) ?></td>
                                <td data-label="Impuesto previsto"><?= h(format_integer((int) $previewPrizes[$rank]['tax_total'])) ?></td>
                                <td data-label="Neto previsto"><?= h(format_integer((int) $previewPrizes[$rank]['received_total'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($settlementFrozen && $view !== 'settlement'): ?>
            <div class="settlement-banner" role="status">
                <strong>Liquidación congelada<?= $settings['frozen_at'] !== null ? ' el ' . h(format_datetime((string) $settings['frozen_at'])) : '' ?>.</strong>
                <span>Los valores financieros están protegidos. Un aporte que llegue ahora no cambia el premio ya anunciado.</span>
                <a class="small-button" href="admin.php?view=settlement">Abrir liquidación</a>
            </div>
        <?php endif; ?>

        <?php if ($view === 'dashboard'): ?>
            <section class="admin-metrics">
                <?php foreach (resource_keys() as $resource): ?>
                    <div>
                        <?php render_resource_art($resource, 'admin-metric-art'); ?>
                        <span><?= h(resource_title($resource)) ?> disponible</span>
                        <strong><?= h(format_compact_amount((int) $summary['balance'][$resource])) ?></strong>
                    </div>
                <?php endforeach; ?>
            </section>

            <?php if ($editForm !== null): ?>
                <section class="admin-panel narrow-panel">
                    <div class="panel-heading">
                        <div><span class="eyebrow">Corrección</span><h2>Editar envío</h2></div>
                        <a class="text-link" href="admin.php">Cancelar</a>
                    </div>
                    <form method="post" action="admin.php" class="contribution-form">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= h((string) $editForm['id']) ?>">
                        <input type="hidden" name="created_at" value="<?= h((string) ($editForm['created_at'] ?? '')) ?>">
                        <label class="field">
                            <span class="field-label">Asignar al jugador</span>
                            <select name="player_id" required>
                                <?php foreach ($players as $player): ?>
                                    <option translate="no" value="<?= h((string) $player['id']) ?>" <?= (string) $player['id'] === (string) ($editForm['player_id'] ?? '') ? 'selected' : '' ?>><?= h((string) $player['display_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <input type="hidden" name="player_name" value="">
                        <div class="resource-input-grid">
                            <?php foreach (resource_keys() as $resource): ?><?php render_resource_input($resource, (string) $editForm[$resource]); ?><?php endforeach; ?>
                        </div>
                        <label class="field"><span class="field-label">Nota opcional</span><input type="text" name="note" value="<?= h((string) ($editForm['note'] ?? '')) ?>" maxlength="180"></label>
                        <div class="form-actions">
                            <button class="button button-primary" type="submit">Guardar corrección</button>
                            <a class="button button-secondary" href="admin.php">Cancelar</a>
                        </div>
                    </form>
                    <p class="panel-note">Registrado originalmente: <?= h(format_datetime((string) ($editForm['created_at'] ?? ''))) ?>.</p>
                </section>
            <?php else: ?>
                <div class="admin-overview-grid">
                    <section class="admin-panel entry-panel">
                        <div class="panel-heading">
                            <div><span class="eyebrow">Nuevo movimiento</span><h2>Registrar envío recibido</h2></div>
                            <span class="net-pill">Cantidad exacta que llegó al banco</span>
                        </div>
                        <form id="add-contribution-form" method="post" action="admin.php" class="contribution-form" data-webmcp-contribution-form>
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="add">
                            <div class="player-picker" data-player-picker>
                                <label class="field">
                                    <span class="field-label">Jugador existente</span>
                                    <select name="player_id" data-existing-player>
                                        <option value="">— Crear o detectar por nombre —</option>
                                        <?php foreach ($players as $player): ?>
                                            <option translate="no" value="<?= h((string) $player['id']) ?>"><?= h((string) $player['display_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <span class="picker-or">o</span>
                                <label class="field">
                                    <span class="field-label">Nombre exacto visto en el informe</span>
                                    <input class="notranslate" translate="no" type="text" name="player_name" value="<?= h((string) $newForm['player_name']) ?>" maxlength="80" autocomplete="off" data-new-player-name>
                                    <small>Si coincide con uno existente, se acumula automáticamente.</small>
                                </label>
                            </div>
                            <div class="resource-input-grid">
                                <?php foreach (resource_keys() as $resource): ?><?php render_resource_input($resource, (string) $newForm[$resource]); ?><?php endforeach; ?>
                            </div>
                            <label class="field"><span class="field-label">Nota opcional</span><input type="text" name="note" value="<?= h((string) $newForm['note']) ?>" maxlength="180" placeholder="Ej.: captura 2, fila 8"></label>
                            <button class="button button-primary button-wide-mobile" type="submit" data-webmcp-submit>Registrar envío</button>
                            <p class="input-hint">Formatos válidos: 1200000, 1.200.000, 1,200,000 o 1.2M.</p>
                        </form>
                    </section>

                    <aside class="admin-panel command-panel">
                        <div class="panel-heading"><div><span class="eyebrow">Control rápido</span><h2>Estado del registro</h2></div></div>
                        <dl class="command-stats">
                            <div><dt>Saldo combinado</dt><dd><?= h(format_integer((int) $summary['grand_total'])) ?></dd></div>
                            <div><dt>Jugadores</dt><dd><?= h((string) $summary['player_count']) ?></dd></div>
                            <div><dt>Clasificados</dt><dd><?= h((string) $summary['eligible_count']) ?></dd></div>
                            <div><dt>Envíos</dt><dd><?= h((string) $summary['record_count']) ?></dd></div>
                        </dl>
                        <a class="button button-secondary button-wide" href="admin.php?view=batch">Importar muchos envíos</a>
                        <?php if ($backup !== null): ?>
                            <form method="post" action="admin.php" data-confirm="¿Deshacer el último cambio de datos? La cuenta y la contraseña no cambiarán.">
                                <?= csrf_input() ?><input type="hidden" name="action" value="undo">
                                <button class="button button-quiet button-wide" type="submit">Deshacer último cambio</button>
                                <small class="backup-note">Copia disponible: <?= h(format_datetime((string) $backup['created_at'])) ?></small>
                            </form>
                        <?php endif; ?>
                    </aside>
                </div>
            <?php endif; ?>

            <?php if ($settlementFrozen): ?>
                <section class="admin-panel" data-testid="late-contribution-panel">
                    <div class="panel-heading"><div><span class="eyebrow">Aporte tardío</span><h2>Llegó después de congelar</h2></div></div>
                    <p class="panel-intro">Un aporte registrado después de congelar la liquidación no debe cambiar el premio ya anunciado. Elige explícitamente qué hacer con él. Si todavía no has pagado y quieres recalcularlo todo, <a class="text-link" href="admin.php?view=settlement">desbloquea la liquidación</a> primero.</p>
                    <form method="post" action="admin.php" class="contribution-form">
                        <?= csrf_input() ?><input type="hidden" name="action" value="add_late_contribution">
                        <label class="field"><span class="field-label">Nombre exacto del jugador</span><input class="notranslate" translate="no" type="text" name="player_name" data-testid="late-player-name" value="<?= h((string) $lateContributionForm['player_name']) ?>" maxlength="80" required></label>
                        <div class="resource-input-grid">
                            <?php foreach (resource_keys() as $resource): ?><?php render_resource_input($resource, (string) $lateContributionForm[$resource]); ?><?php endforeach; ?>
                        </div>
                        <label class="field"><span class="field-label">Qué hacer con este aporte</span><select name="policy" data-testid="late-policy" required>
                            <option value="next_event">Asignarlo al evento siguiente</option>
                            <option value="reserve">Ingresarlo directamente a la reserva</option>
                        </select></label>
                        <label class="field"><span class="field-label">Nota opcional</span><input type="text" name="note" value="<?= h((string) $lateContributionForm['note']) ?>" maxlength="180"></label>
                        <button class="button button-primary" type="submit">Registrar aporte tardío</button>
                    </form>
                </section>
            <?php endif; ?>

            <?php if ($pendingContributions !== []): ?>
                <section class="admin-panel records-panel" data-testid="pending-contributions">
                    <div class="panel-heading"><div><span class="eyebrow">Guardados para el evento siguiente</span><h2>Aportes tardíos en espera</h2></div><span class="record-count"><?= h((string) count($pendingContributions)) ?></span></div>
                    <p class="panel-intro">Entrarán automáticamente como aportes en cuanto se abra el próximo evento.</p>
                    <div class="table-shell"><table class="data-table admin-table"><thead><tr><th>Jugador</th><?php foreach (resource_keys() as $resource): ?><th><?= h(resource_title($resource)) ?></th><?php endforeach; ?><th>Fecha</th><th>Acción</th></tr></thead><tbody>
                        <?php foreach ($pendingContributions as $record): ?>
                            <tr>
                                <th scope="row" data-label="Jugador"><?php render_player_name((string) $record['player_name']); ?></th>
                                <?php foreach (resource_keys() as $resource): ?><td data-label="<?= h(resource_title($resource)) ?>"><?= h(format_integer((int) $record[$resource])) ?></td><?php endforeach; ?>
                                <td data-label="Fecha"><?= h(format_datetime((string) $record['created_at'])) ?></td>
                                <td data-label="Acción">
                                    <form method="post" action="admin.php" data-confirm="¿Descartar este aporte tardío?">
                                        <?= csrf_input() ?><input type="hidden" name="action" value="delete_pending_contribution"><input type="hidden" name="id" value="<?= h((string) $record['id']) ?>">
                                        <button class="small-button button-danger" type="submit">Descartar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody></table></div>
                </section>
            <?php endif; ?>

            <section class="admin-panel records-panel">
                <div class="panel-heading">
                    <div><span class="eyebrow">Historial editable</span><h2>Envíos recibidos</h2></div>
                    <span class="record-count"><?= h((string) $summary['record_count']) ?> registro<?= (int) $summary['record_count'] === 1 ? '' : 's' ?></span>
                </div>
                <?php if ($contributions === []): ?>
                    <div class="empty-inline">Todavía no hay envíos registrados.</div>
                <?php else: ?>
                    <div class="table-shell">
                        <table class="data-table admin-table">
                            <thead><tr><th>Jugador y fecha</th><th>Comida</th><th>Madera</th><th>Piedra</th><th>Oro</th><th>Acciones</th></tr></thead>
                            <tbody>
                                <?php foreach ($contributions as $record): ?>
                                    <tr>
                                        <th scope="row" data-label="Jugador"><?php render_player_name((string) $record['player_name']); ?><small><?= h(format_datetime((string) $record['created_at'])) ?></small></th>
                                        <?php foreach (resource_keys() as $resource): ?><td data-label="<?= h(resource_title($resource)) ?>"><?= h(format_integer((int) $record[$resource])) ?></td><?php endforeach; ?>
                                        <td data-label="Acciones" class="row-actions">
                                            <a class="small-button" href="admin.php?id=<?= h((string) $record['id']) ?>">Editar</a>
                                            <form method="post" action="admin.php" data-confirm="¿Eliminar este envío? Los totales se recalcularán.">
                                                <?= csrf_input() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= h((string) $record['id']) ?>">
                                                <button class="small-button danger-button" type="submit">Eliminar</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

        <?php elseif ($view === 'batch'): ?>
            <section class="admin-panel batch-panel">
                <div class="panel-heading">
                    <div><span class="eyebrow">Capturas y videos</span><h2>Importación masiva con revisión</h2></div>
                    <span class="security-tag">Nada se guarda sin confirmar</span>
                </div>
                <p class="panel-intro">Una fila por envío, aunque un nombre se repita. El sistema detecta al mismo jugador y acumula todas sus filas.</p>
                <div class="format-example notranslate" translate="no">
                    <code>Jugador | Comida | Madera | Piedra | Oro</code>
                    <code>ᴹᵉEjemplo愛 | 300K | 0 | 250K | 100K</code>
                </div>
                <?php if ($batchPreview === null): ?>
                    <form method="post" action="admin.php?view=batch" class="stacked-form">
                        <?= csrf_input() ?><input type="hidden" name="action" value="batch_preview">
                        <label class="field">
                            <span class="field-label">Filas preparadas desde el informe</span>
                            <textarea class="notranslate" translate="no" name="batch_text" rows="13" maxlength="120000" data-batch-text required><?= h($batchText) ?></textarea>
                            <small><span data-batch-lines>0</span> líneas detectadas. También acepta tabulaciones o punto y coma.</small>
                        </label>
                        <button class="button button-primary" type="submit">Analizar y mostrar vista previa</button>
                    </form>
                <?php else: ?>
                    <div class="preview-banner"><strong><?= h((string) count($batchPreview['items'])) ?> envíos listos</strong><span>Revisa nombres y cifras antes de registrarlos.</span></div>
                    <div class="table-shell">
                        <table class="data-table batch-preview-table">
                            <thead><tr><th>Línea</th><th>Nombre exacto</th><th>Coincidencia</th><th>Comida</th><th>Madera</th><th>Piedra</th><th>Oro</th></tr></thead>
                            <tbody>
                                <?php foreach ($batchPreview['items'] as $item): $row = $item['values']; ?>
                                    <tr>
                                        <td><?= h((string) ($row['source_line'] ?? '')) ?></td>
                                        <th scope="row"><?php render_player_name((string) $row['player_name']); ?></th>
                                        <td>
                                            <?php if ($item['is_new']): ?><span class="match-badge match-new">Jugador nuevo</span>
                                            <?php elseif (empty($item['match']['id'])): ?><span class="match-badge">Mismo nuevo del lote</span>
                                            <?php else: ?><span class="match-badge match-existing">Se acumula a <?php render_player_name((string) $item['match']['display_name'], 'inline-player-name'); ?></span><?php endif; ?>
                                        </td>
                                        <?php foreach (resource_keys() as $resource): ?><td><?= h(format_integer((int) $row[$resource])) ?></td><?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="preview-actions">
                        <form method="post" action="admin.php?view=batch">
                            <?= csrf_input() ?><input type="hidden" name="action" value="batch_confirm"><input type="hidden" name="batch_token" value="<?= h((string) $batchPreview['token']) ?>">
                            <button class="button button-primary" type="submit">Confirmar todos los envíos</button>
                        </form>
                        <form method="post" action="admin.php?view=batch">
                            <?= csrf_input() ?><input type="hidden" name="action" value="batch_cancel">
                            <button class="button button-secondary" type="submit">Cancelar lote</button>
                        </form>
                    </div>
                <?php endif; ?>
            </section>

        <?php elseif ($view === 'payouts'): ?>
            <div class="admin-overview-grid">
                <section class="admin-panel entry-panel">
                    <div class="panel-heading"><div><span class="eyebrow"><?= $payoutEditId !== '' ? 'Corrección verificable' : 'Premio al ganador' ?></span><h2><?= $payoutEditId !== '' ? 'Editar entrega' : 'Registrar salida del banco' ?></h2></div><span class="net-pill"><?= h(number_format((float) $settings['tax_rate'], 0, ',', '.')) ?>% al enviarlo</span></div>
                    <div class="payout-explainer">
                        <strong>Escribe la cantidad completa que sale de tu ciudad.</strong>
                        <p>El saldo bajará por esa cantidad. Durante el transporte, Lilith retendrá el <?= h(number_format((float) $settings['tax_rate'], 0, ',', '.')) ?>% y el ganador recibirá aproximadamente el <?= h(number_format(100 - (float) $settings['tax_rate'], 0, ',', '.')) ?>%. Tú retienes 0%.</p>
                        <p class="payout-example">Ejemplo: si salen 1.000.000, Lilith retiene ≈<?= h(format_integer(outgoing_tax_amount(1000000, (float) $settings['tax_rate']))) ?> y al ganador llegan ≈<?= h(format_integer(outgoing_recipient_amount(1000000, (float) $settings['tax_rate']))) ?>.</p>
                        <p class="payout-example">Para TOP 1 y TOP 2 copia la cifra <strong>“Sale del banco”</strong> de la <a class="text-link" href="index.php#rewards" target="_blank" rel="noopener">proyección pública</a>; ya incluye lo necesario para que el premio publicado llegue completo. Para TOP 3 registra el remanente indicado.</p>
                    </div>
                    <form method="post" action="admin.php?view=payouts" class="contribution-form">
                        <?= csrf_input() ?><input type="hidden" name="action" value="<?= $payoutEditId !== '' ? 'update_disbursement' : 'add_disbursement' ?>">
                        <?php if ($payoutEditId !== ''): ?><input type="hidden" name="id" value="<?= h($payoutEditId) ?>"><?php endif; ?>
                        <label class="field"><span class="field-label">Destinatario o ganador</span><input class="notranslate" translate="no" type="text" name="recipient_name" value="<?= h((string) $payoutForm['recipient_name']) ?>" maxlength="80" required></label>
                        <h3 class="settings-subtitle">Cantidad descontada del banco</h3>
                        <div class="resource-input-grid"><?php foreach (resource_keys() as $resource): ?><?php render_resource_input($resource, (string) $payoutForm[$resource]); ?><?php endforeach; ?></div>
                        <h3 class="settings-subtitle">Cantidad que recibió realmente el ganador</h3>
                        <p class="panel-intro">Copia los números exactos de los informes del juego. Déjalos vacíos únicamente mientras la entrega siga pendiente de verificación.</p>
                        <div class="resource-input-grid">
                            <?php foreach (resource_keys() as $resource): ?>
                                <label class="field resource-field"><span class="field-label"><?php render_resource_mark($resource); ?> <?= h(resource_title($resource)) ?> recibida</span><input type="text" name="actual_<?= h($resource) ?>" value="<?= h((string) $payoutForm['actual_' . $resource]) ?>" inputmode="decimal" placeholder="Cantidad exacta"></label>
                            <?php endforeach; ?>
                        </div>
                        <label class="field"><span class="field-label">Cantidad de transportes</span><input type="number" name="transport_count" value="<?= h((string) $payoutForm['transport_count']) ?>" min="0" step="1" placeholder="Ej.: 5"></label>
                        <label class="field"><span class="field-label">Motivo</span><input type="text" name="note" value="<?= h((string) $payoutForm['note']) ?>" maxlength="180" placeholder="Ej.: premio entregado al ganador"></label>
                        <label class="field"><span class="field-label">Referencia de evidencia</span><input type="text" name="evidence_note" value="<?= h((string) $payoutForm['evidence_note']) ?>" maxlength="500" placeholder="Ej.: informes 19:27–19:29 y clasificación pública"></label>
                        <div class="form-actions">
                            <button class="button button-primary" type="submit"><?= $payoutEditId !== '' ? 'Guardar corrección' : 'Registrar salida completa del banco' ?></button>
                            <?php if ($payoutEditId !== ''): ?><a class="button button-secondary" href="admin.php?view=payouts">Cancelar</a><?php endif; ?>
                        </div>
                    </form>
                </section>
                <aside class="admin-panel command-panel">
                    <div class="panel-heading"><div><span class="eyebrow">Disponible ahora</span><h2>Límite de entrega</h2></div></div>
                    <div class="custody-list"><?php foreach (resource_keys() as $resource): ?><div><span><?php render_resource_mark($resource); ?> <?= h(resource_title($resource)) ?></span><strong><?= h(format_integer((int) $summary['balance'][$resource])) ?></strong></div><?php endforeach; ?></div>
                    <?php if ((int) $summary['paid_grand_total'] > 0): ?>
                        <dl class="command-stats payout-summary">
                            <div><dt>Total salido del banco</dt><dd><?= h(format_integer((int) $summary['paid_grand_total'])) ?></dd></div>
                            <div><dt>Retenido por Lilith</dt><dd><?= h(format_integer((int) $summary['outgoing_tax_grand_total'])) ?></dd></div>
                            <div><dt>Llegado a ganadores</dt><dd><?= h(format_integer((int) $summary['winner_receives_grand_total'])) ?></dd></div>
                        </dl>
                    <?php endif; ?>
                </aside>
            </div>
            <section class="admin-panel records-panel">
                <div class="panel-heading"><div><span class="eyebrow">Recursos salientes</span><h2>Entregas registradas</h2></div></div>
                <?php if ($disbursements === []): ?><div class="empty-inline">No se ha descontado ningún recurso del banco.</div>
                <?php else: ?><div class="table-shell"><table class="data-table"><thead><tr><th>Destinatario</th><th>Comida</th><th>Madera</th><th>Piedra</th><th>Oro</th><th>Acción</th></tr></thead><tbody>
                    <?php foreach ($disbursements as $record): ?><tr>
                        <th scope="row"><?php render_player_name((string) $record['recipient_name']); ?><small><?= h(format_datetime((string) $record['created_at'])) ?> · <?= h(number_format((float) $record['tax_rate'], 0, ',', '.')) ?>% Lilith</small><small><?= !empty($record['receipt_is_exact']) ? 'Recepción exacta verificada' : 'Recepción todavía aproximada' ?><?= (int) ($record['transport_count'] ?? 0) > 0 ? ' · ' . h((string) $record['transport_count']) . ' transportes' : '' ?></small></th>
                        <?php foreach (resource_keys() as $resource): ?><td><span class="payout-ledger-cell"><strong>Sale <?= h(format_integer((int) $record[$resource])) ?></strong><?php if ((int) $record[$resource] > 0): ?><small>Llega<?= !empty($record['receipt_is_exact']) ? '' : ' ≈' ?> <?= h(format_integer((int) $record['winner_receives'][$resource])) ?></small><?php endif; ?></span></td><?php endforeach; ?>
                        <td><div class="row-actions"><a class="small-button" href="admin.php?view=payouts&amp;payout_id=<?= h(rawurlencode((string) $record['id'])) ?>">Editar</a><form method="post" action="admin.php?view=payouts" data-confirm="¿Eliminar esta entrega? El saldo volverá a aumentar."><?= csrf_input() ?><input type="hidden" name="action" value="delete_disbursement"><input type="hidden" name="id" value="<?= h((string) $record['id']) ?>"><button class="small-button danger-button" type="submit">Eliminar</button></form></div></td>
                    </tr><?php endforeach; ?>
                </tbody></table></div><?php endif; ?>
            </section>

        <?php elseif ($view === 'players'): ?>
            <section class="admin-panel">
                <div class="panel-heading"><div><span class="eyebrow">Identidad exacta</span><h2>Directorio de jugadores</h2></div><span class="record-count"><?= h((string) count($players)) ?> nombres</span></div>
                <p class="panel-intro">Corregir aquí un nombre lo actualiza en toda la página, pero no modifica las cifras ni el historial.</p>
                <?php if ($players === []): ?><div class="empty-inline">No existen jugadores todavía.</div>
                <?php else: ?><div class="player-admin-grid">
                    <?php foreach ($players as $player): ?>
                        <form method="post" action="admin.php?view=players" class="player-rename-card">
                            <?= csrf_input() ?><input type="hidden" name="action" value="rename_player"><input type="hidden" name="player_id" value="<?= h((string) $player['id']) ?>">
                            <span>Nombre público exacto</span>
                            <input class="notranslate" translate="no" type="text" name="new_name" value="<?= h((string) $player['display_name']) ?>" maxlength="80" required>
                            <button class="small-button" type="submit">Guardar nombre</button>
                        </form>
                    <?php endforeach; ?>
                </div><?php endif; ?>
            </section>

        <?php elseif ($view === 'event'): ?>
            <section class="admin-panel event-settings-panel">
                <div class="panel-heading"><div><span class="eyebrow">Control sin editar código</span><h2>Configuración del evento actual</h2></div><span class="record-count" data-testid="event-id">ID <?= h((string) $fundBreakdown['event_id']) ?></span></div>
                <p class="panel-intro">Todo lo que aparece aquí se publica tal cual en la portada, el historial y las exportaciones. Ningún texto visible queda fijado en el código.</p>
                <form method="post" action="admin.php?view=event" class="settings-form" data-testid="event-settings-form">
                    <?= csrf_input() ?><input type="hidden" name="return_view" value="event">

                    <h3 class="settings-subtitle">Identidad y fechas</h3>
                    <div class="settings-grid">
                        <label class="field"><span class="field-label">Nombre del evento</span><input type="text" name="event_name" data-testid="event-name" value="<?= h((string) $settingsForm['event_name']) ?>" maxlength="100" required></label>
                        <label class="field"><span class="field-label">Estado del evento</span><select name="event_status" data-testid="event-status">
                            <?php foreach (event_status_keys() as $statusKey): ?>
                                <option value="<?= h($statusKey) ?>" <?= (string) $settingsForm['event_status'] === $statusKey ? 'selected' : '' ?>><?= h(event_status_label($statusKey)) ?></option>
                            <?php endforeach; ?>
                        </select><small>Fase real ahora mismo: <strong><?= h(event_phase_label(event_phase($settings))) ?></strong>. La fase de liquidación congelada se activa desde la pestaña Liquidación.</small></label>
                        <label class="field"><span class="field-label">Fecha y hora de inicio, hora de Florida</span><input type="datetime-local" name="starts_at" data-testid="event-starts-at" value="<?= h(format_datetime_input($settingsForm['starts_at'] ?? null)) ?>"></label>
                        <label class="field"><span class="field-label">Fecha y hora límite, hora de Florida</span><input type="datetime-local" name="deadline" data-testid="event-deadline" value="<?= h(format_datetime_input($settingsForm['deadline'] ?? null)) ?>"></label>
                        <label class="field"><span class="field-label">Custodio público</span><input class="notranslate" translate="no" type="text" name="custodian_name" data-testid="event-custodian" value="<?= h((string) $settingsForm['custodian_name']) ?>" maxlength="80" required></label>
                        <label class="field settings-span-2"><span class="field-label">Premio o explicación breve</span><input type="text" name="prize" data-testid="event-prize" value="<?= h((string) $settingsForm['prize']) ?>" maxlength="220"></label>
                    </div>

                    <h3 class="settings-subtitle">Objetivo y reglas de la semana</h3>
                    <div class="settings-grid">
                        <label class="field settings-span-2"><span class="field-label">Título del objetivo</span><input type="text" name="objective_title" data-testid="objective-title" value="<?= h((string) $settingsForm['objective_title']) ?>" maxlength="200" required><small>Sustituye cualquier texto fijo. Ejemplos: «Destrucción de fuertes bárbaros», «Duelo de honor», «Ayudas a la alianza».</small></label>
                        <label class="field settings-span-2"><span class="field-label">Explicación general de lo que hay que hacer</span><textarea name="objective_description" data-testid="objective-description" maxlength="2000" rows="3"><?= h((string) $settingsForm['objective_description']) ?></textarea></label>
                        <label class="field"><span class="field-label">Nombre de la cifra que clasifica</span><input type="text" name="score_metric_label" data-testid="score-metric-label" value="<?= h((string) $settingsForm['score_metric_label']) ?>" maxlength="120" required><small>Ejemplos: Fuertes destruidos, Puntos de honor, Muertes T4/T5, Ayudas realizadas.</small></label>
                        <label class="field"><span class="field-label">Método de clasificación</span><select name="ranking_method" data-testid="ranking-method">
                            <?php foreach (ranking_method_keys() as $method): ?>
                                <option value="<?= h($method) ?>" <?= (string) $settingsForm['ranking_method'] === $method ? 'selected' : '' ?>><?= h(ranking_method_label($method)) ?></option>
                            <?php endforeach; ?>
                        </select><small>Los modos máximo y mínimo avisan si el orden registrado no coincide. El modo manual no impone ningún orden.</small></label>
                        <?php foreach (reward_rank_keys() as $rank): ?>
                            <label class="field settings-span-2"><span class="field-label">Cómo se obtiene <?= h(reward_rank_label($rank)) ?></span><textarea name="rank_rule_<?= h($rank) ?>" data-testid="rank-rule-<?= h($rank) ?>" maxlength="500" rows="2"><?= h((string) $settingsForm['rank_rule_' . $rank]) ?></textarea></label>
                        <?php endforeach; ?>
                    </div>

                    <h3 class="settings-subtitle">Cuotas requeridas por miembro</h3>
                    <p class="panel-intro">Al guardar se recalcula la clasificación del evento activo. Estas cuotas quedan dentro de la fotografía histórica: cambiarlas nunca altera un evento ya archivado.</p>
                    <div class="resource-input-grid">
                        <?php foreach (resource_keys() as $resource): ?><label class="field resource-field"><span class="field-label"><?php render_resource_mark($resource); ?> <?= h(resource_title($resource)) ?></span><input type="text" name="threshold_<?= h($resource) ?>" data-testid="threshold-<?= h($resource) ?>" value="<?= h(format_integer((int) $settingsForm['thresholds'][$resource])) ?>" inputmode="decimal" required></label><?php endforeach; ?>
                    </div>

                    <h3 class="settings-subtitle">Resultado oficial</h3>
                    <p class="panel-intro">Registra los tres nombres exactamente como aparecen en el juego y su cantidad de <strong><?= h((string) $settingsForm['score_metric_label']) ?></strong>. Estos datos quedarán dentro del historial del evento.</p>
                    <div class="winner-settings-grid">
                        <?php foreach (reward_rank_keys() as $rank): ?>
                            <?php $savedWinner = $currentWinnersByRank[$rank] ?? []; ?>
                            <div class="winner-setting-row">
                                <strong><?= h(reward_rank_label($rank)) ?></strong>
                                <label class="field"><span class="field-label">Jugador</span><input class="notranslate" translate="no" type="text" name="winner_<?= h($rank) ?>_name" data-testid="winner-<?= h($rank) ?>-name" value="<?= h((string) ($savedWinner['player_name'] ?? '')) ?>" maxlength="80"></label>
                                <label class="field"><span class="field-label"><?= h((string) $settingsForm['score_metric_label']) ?></span><input type="text" name="winner_<?= h($rank) ?>_score" data-testid="winner-<?= h($rank) ?>-score" value="<?= h((string) ($savedWinner['score'] ?? '')) ?>" inputmode="numeric"></label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="settings-readonly-rule reward-rule-note">
                        <span class="field-label">Base para clasificar</span>
                        <strong>Lo que realmente llegó al banco</strong>
                        <small>Esta regla es fija para evitar descontar el impuesto dos veces.</small>
                    </div>

                    <div class="form-actions">
                        <button class="button button-secondary" type="submit" name="action" value="preview_settings" data-testid="preview-event-settings">Ver qué cambiaría</button>
                        <button class="button button-primary" type="submit" name="action" value="save_settings" data-testid="save-event-settings">Guardar configuración del evento</button>
                    </div>
                </form>
            </section>

            <section class="admin-panel">
                <div class="panel-heading"><div><span class="eyebrow">Clasificación recalculada</span><h2>Cuota vigente, faltante y estado</h2></div><span class="record-count"><?= h((string) $summary['eligible_count']) ?> de <?= h((string) $summary['player_count']) ?> clasificados</span></div>
                <?php if ($summary['players'] === []): ?>
                    <div class="empty-inline">Todavía no hay aportes en este evento.</div>
                <?php else: ?>
                    <div class="table-shell"><table class="data-table admin-table"><thead><tr><th>Jugador</th><?php foreach (resource_keys() as $resource): ?><th><?= h(resource_title($resource)) ?></th><?php endforeach; ?><th>Estado</th></tr></thead><tbody>
                        <?php foreach ($summary['players'] as $player): ?>
                            <tr>
                                <th scope="row" data-label="Jugador"><?php render_player_name((string) $player['name']); ?></th>
                                <?php foreach (resource_keys() as $resource): ?>
                                    <?php $missing = (int) $player['remaining'][$resource]; $quota = (int) $settings['thresholds'][$resource]; ?>
                                    <td data-label="<?= h(resource_title($resource)) ?>">
                                        <strong><?= h(format_integer((int) $player[$resource])) ?></strong>
                                        <small>Cuota <?= h(format_integer($quota)) ?></small>
                                        <small class="<?= $missing === 0 ? 'quota-ok' : 'quota-missing' ?>"><?= $missing === 0 ? 'Excedente +' . h(format_integer((int) $player[$resource] - $quota)) : 'Faltan ' . h(format_integer($missing)) ?></small>
                                    </td>
                                <?php endforeach; ?>
                                <td data-label="Estado"><span class="<?= !empty($player['eligible']) ? 'status-inline status-eligible' : 'status-inline status-pending' ?>"><?= !empty($player['eligible']) ? 'Clasificado' : 'Pendiente' ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?>
            </section>

            <section class="admin-panel">
                <div class="panel-heading"><div><span class="eyebrow">Copias externas</span><h2>Exportar datos</h2></div></div>
                <p class="panel-intro">Descarga una copia cuando quieras. Ningún servicio externo recibe información.</p>
                <div class="form-actions"><a class="button button-secondary" href="export.php?format=csv">Descargar CSV</a><a class="button button-secondary" href="export.php?format=json">Descargar JSON completo</a></div>
            </section>

        <?php elseif ($view === 'prize'): ?>
            <?php
            $currentPoints = (int) $settingsForm['prize_pool_basis_points'];
            $shares = reward_weight_percentages((array) $settingsForm['reward_weights']);
            $weightTotal = array_sum(array_map('floatval', (array) $settingsForm['reward_weights']));
            $projection = $summary['reward_projection'];
            ?>
            <section class="admin-panel event-settings-panel">
                <div class="panel-heading"><div><span class="eyebrow">Configuración del premio</span><h2>Porcentaje repartido, pesos y reserva</h2></div><span class="record-count"><?= h(basis_points_to_text($currentPoints)) ?>% del fondo elegible</span></div>
                <?php if ($settlementFrozen): ?>
                    <div class="settlement-banner" role="alert"><strong>La liquidación está congelada.</strong><span>Los campos financieros no se pueden cambiar en silencio. Desbloquea primero la liquidación y deja el motivo.</span><a class="small-button" href="admin.php?view=settlement">Ir a liquidación</a></div>
                <?php endif; ?>
                <form method="post" action="admin.php?view=prize" class="settings-form" data-testid="prize-settings-form">
                    <?= csrf_input() ?><input type="hidden" name="return_view" value="prize">

                    <h3 class="settings-subtitle">Porcentaje del fondo destinado a premios</h3>
                    <p class="panel-intro">Este porcentaje decide cuánto del fondo elegible se entrega esta semana. Lo que no se entrega queda guardado como reserva para eventos futuros. No sustituye a los pesos: primero se obtiene el fondo de premios y después se reparte entre los tres puestos.</p>
                    <div class="settings-grid">
                        <label class="field"><span class="field-label">Porcentaje del fondo destinado a premios (%)</span><input type="number" name="prize_pool_percent" data-testid="prize-pool-percent" value="<?= h(basis_points_to_text($currentPoints)) ?>" min="0" max="100" step="0.01" required <?= $settlementFrozen ? 'readonly' : '' ?>><small>Se guarda como <?= h((string) $currentPoints) ?> puntos básicos para no perder precisión. 100 % = 10000.</small></label>
                        <label class="field"><span class="field-label">Impuesto al enviar el premio (%)</span><input type="number" name="tax_rate" data-testid="tax-rate" value="<?= h((string) $settingsForm['tax_rate']) ?>" min="0" max="50" step="0.01" required <?= $settlementFrozen ? 'readonly' : '' ?>><small>No modifica los aportes recibidos ni la clasificación.</small></label>
                    </div>

                    <h3 class="settings-subtitle">Peso relativo de cada puesto</h3>
                    <p class="panel-intro">Son pesos relativos, no porcentajes directos del banco. Se aplican dentro del fondo de premios ya calculado.</p>
                    <div class="settings-grid reward-settings-grid">
                        <?php foreach (reward_rank_keys() as $rank): ?>
                            <label class="field">
                                <span class="field-label">Peso relativo de <?= h(reward_rank_label($rank)) ?></span>
                                <input type="number" name="reward_weight_<?= h($rank) ?>" data-testid="reward-weight-<?= h($rank) ?>" value="<?= h((string) $settingsForm['reward_weights'][$rank]) ?>" min="0.01" max="1000000" step="0.01" required <?= $settlementFrozen ? 'readonly' : '' ?>>
                                <small>Equivale al <?= h(number_format((float) $shares[$rank] * 100, 2, ',', '.')) ?>% del fondo de premios.</small>
                            </label>
                        <?php endforeach; ?>
                        <label class="field">
                            <span class="field-label">Capacidad total por envío</span>
                            <input type="text" name="transport_capacity" data-testid="transport-capacity" value="<?= h(format_integer((int) $settingsForm['transport_capacity'])) ?>" inputmode="decimal" required>
                            <small>Actualmente <?= h(format_compact_amount((int) $settingsForm['transport_capacity'])) ?> por viaje.</small>
                        </label>
                    </div>

                    <h3 class="settings-subtitle">Reserva anterior que se añade al premio</h3>
                    <p class="panel-intro">Opcional y sólo para eventos especiales. Cada cantidad se valida contra la reserva realmente disponible.</p>
                    <div class="resource-input-grid">
                        <?php foreach (resource_keys() as $resource): ?>
                            <label class="field resource-field">
                                <span class="field-label"><?php render_resource_mark($resource); ?> <?= h(resource_title($resource)) ?></span>
                                <input type="text" name="reserve_draw_<?= h($resource) ?>" data-testid="reserve-draw-<?= h($resource) ?>" value="<?= h(format_integer((int) $settingsForm['reserve_draw'][$resource])) ?>" inputmode="decimal" <?= $settlementFrozen ? 'readonly' : '' ?>>
                                <small>Saldo antes: <?= h(format_integer((int) $reserveOpening[$resource])) ?> · después: <?= h(format_integer((int) $reserveOpening[$resource] - (int) $fundBreakdown['reserve_draw'][$resource])) ?></small>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="form-actions">
                        <button class="button button-secondary" type="submit" name="action" value="preview_settings" data-testid="preview-prize-settings">Ver qué cambiaría</button>
                        <button class="button button-primary" type="submit" name="action" value="save_settings" data-testid="save-prize-settings" <?= $settlementFrozen ? 'disabled' : '' ?>>Guardar configuración del premio</button>
                    </div>
                </form>
            </section>

            <section class="admin-panel">
                <div class="panel-heading"><div><span class="eyebrow">Sólo lectura</span><h2>Vista previa del reparto</h2></div><span class="record-count">Suma de pesos: <?= h(number_format($weightTotal, 2, ',', '.')) ?></span></div>
                <div class="table-shell"><table class="data-table admin-table" data-testid="prize-preview-table"><thead><tr><th>Puesto</th><th>Peso</th><th>% del fondo</th><th>Proyección bruta</th><th>Impuesto previsto</th><th>Neto previsto</th><th>Envíos</th></tr></thead><tbody>
                    <?php foreach (reward_rank_keys() as $rank): $prize = $projection['prizes'][$rank]; ?>
                        <tr>
                            <th scope="row" data-label="Puesto"><?= h(reward_rank_label($rank)) ?></th>
                            <td data-label="Peso"><?= h(number_format((float) $prize['weight'], 2, ',', '.')) ?></td>
                            <td data-label="% del fondo"><?= h(number_format((float) $prize['share'] * 100, 2, ',', '.')) ?>%</td>
                            <td data-label="Proyección bruta"><?= h(format_integer((int) $prize['sent_total'])) ?></td>
                            <td data-label="Impuesto previsto"><?= h(format_integer((int) $prize['tax_total'])) ?></td>
                            <td data-label="Neto previsto"><?= h(format_integer((int) $prize['received_total'])) ?></td>
                            <td data-label="Envíos"><?= h((string) $prize['trips']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody></table></div>

                <div class="table-shell"><table class="data-table admin-table fund-detail-table"><caption>Contabilidad por recurso del evento actual</caption><thead><tr><th>Recurso</th><th>R. apertura</th><th>Aportado</th><th>Elegible</th><th>Sin clasificar</th><th>Premio del evento</th><th>Retenido</th><th>Reserva usada</th><th>Fondo bruto</th><th>R. cierre</th></tr></thead><tbody>
                    <?php foreach (resource_keys() as $resource): ?>
                        <tr>
                            <th scope="row" data-label="Recurso"><?= h(resource_title($resource)) ?></th>
                            <td data-label="R. apertura"><?= h(format_integer((int) $fundBreakdown['reserve_opening'][$resource])) ?></td>
                            <td data-label="Aportado"><?= h(format_integer((int) $fundBreakdown['contributed_total'][$resource])) ?></td>
                            <td data-label="Elegible"><?= h(format_integer((int) $fundBreakdown['eligible_total'][$resource])) ?></td>
                            <td data-label="Sin clasificar"><?= h(format_integer((int) $fundBreakdown['pending_unqualified'][$resource])) ?></td>
                            <td data-label="Premio del evento"><?= h(format_integer((int) $fundBreakdown['prize_from_event'][$resource])) ?></td>
                            <td data-label="Retenido"><?= h(format_integer((int) $fundBreakdown['retained_from_eligible'][$resource])) ?></td>
                            <td data-label="Reserva usada"><?= h(format_integer((int) $fundBreakdown['reserve_draw'][$resource])) ?></td>
                            <td data-label="Fondo bruto"><?= h(format_integer((int) $fundBreakdown['prize_fund_gross'][$resource])) ?></td>
                            <td data-label="R. cierre"><?= h(format_integer((int) $fundBreakdown['reserve_closing'][$resource])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody></table></div>
            </section>

        <?php elseif ($view === 'reserve'): ?>
            <section class="admin-metrics">
                <?php foreach (resource_keys() as $resource): ?>
                    <div>
                        <?php render_resource_art($resource, 'admin-metric-art'); ?>
                        <span>Reserva de <?= h(resource_label($resource)) ?></span>
                        <strong data-testid="reserve-balance-<?= h($resource) ?>"><?= h(format_compact_amount((int) $reserveBalances[$resource])) ?></strong>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="admin-panel">
                <div class="panel-heading"><div><span class="eyebrow">Ajuste auditado</span><h2>Registrar movimiento de reserva</h2></div></div>
                <p class="panel-intro">El saldo se deriva siempre de la suma del libro mayor. Un movimiento contabilizado nunca se edita ni se borra: las correcciones se hacen con un movimiento compensatorio y una justificación obligatoria.</p>
                <form method="post" action="admin.php?view=reserve" class="settings-form" data-testid="reserve-adjustment-form">
                    <?= csrf_input() ?><input type="hidden" name="action" value="reserve_adjustment">
                    <div class="settings-grid">
                        <label class="field"><span class="field-label">Recurso</span><select name="resource" data-testid="reserve-resource" required><?php foreach (resource_keys() as $resource): ?><option value="<?= h($resource) ?>"><?= h(resource_title($resource)) ?></option><?php endforeach; ?></select></label>
                        <label class="field"><span class="field-label">Tipo de movimiento</span><select name="direction" data-testid="reserve-direction" required>
                            <option value="in">Ajuste manual de entrada (+)</option>
                            <option value="out">Ajuste manual de salida (−)</option>
                            <option value="correction_in">Corrección a favor de la reserva (+)</option>
                            <option value="correction_out">Corrección en contra de la reserva (−)</option>
                        </select><small>Las correcciones quedan vinculadas al evento actual y sirven para cuadrar el cierre cuando lo entregado no coincide con el plan.</small></label>
                        <label class="field"><span class="field-label">Cantidad</span><input type="text" name="amount" data-testid="reserve-amount" inputmode="decimal" placeholder="0 o 1.2M" required></label>
                        <label class="field settings-span-2"><span class="field-label">Justificación obligatoria</span><textarea name="reason" data-testid="reserve-reason" maxlength="500" rows="2" required placeholder="Explica qué evidencia respalda este movimiento."></textarea></label>
                    </div>
                    <button class="button button-primary" type="submit">Registrar movimiento</button>
                </form>
            </section>

            <section class="admin-panel records-panel">
                <div class="panel-heading"><div><span class="eyebrow">Libro mayor</span><h2>Movimientos de reserva</h2></div><span class="record-count"><?= h((string) count($reserveLedger)) ?> movimientos</span></div>
                <?php if ($reserveLedger === []): ?>
                    <div class="empty-inline">Todavía no hay movimientos. La reserva se generará al cerrar el primer evento con un porcentaje menor al 100 %.</div>
                <?php else: ?>
                    <div class="table-shell"><table class="data-table admin-table"><thead><tr><th>Fecha</th><th>Recurso</th><th>Movimiento</th><th>Cantidad</th><th>Evento</th><th>Motivo</th></tr></thead><tbody>
                        <?php foreach ($reserveLedger as $movement): ?>
                            <tr>
                                <th scope="row" data-label="Fecha"><?= h(format_datetime((string) $movement['created_at'])) ?><small><?= h((string) $movement['created_by']) ?></small></th>
                                <td data-label="Recurso"><?= h(resource_title((string) $movement['resource_type'])) ?></td>
                                <td data-label="Movimiento"><?= h(reserve_movement_label((string) $movement['movement_type'])) ?></td>
                                <td data-label="Cantidad" class="<?= (int) $movement['amount_signed'] < 0 ? 'ledger-out' : 'ledger-in' ?>"><?= (int) $movement['amount_signed'] > 0 ? '+' : '' ?><?= h(format_integer((int) $movement['amount_signed'])) ?></td>
                                <td data-label="Evento"><?= h((string) $movement['event_id'] !== '' ? (string) $movement['event_id'] : '—') ?></td>
                                <td data-label="Motivo"><?= h((string) $movement['note']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?>
            </section>

        <?php elseif ($view === 'settlement'): ?>
            <section class="admin-panel">
                <div class="panel-heading">
                    <div><span class="eyebrow">Paso 1</span><h2>Congelar liquidación</h2></div>
                    <span class="check-pill <?= $settlementFrozen ? 'check-pass' : 'check-pending' ?>" data-testid="settlement-status"><?= h(settlement_status_label((string) $settings['settlement_status'])) ?></span>
                </div>
                <p class="panel-intro">Congelar crea una fotografía inmutable del evento: participantes, aportes, cuotas, clasificación, objetivo, reglas, métrica, ganadores, impuesto, porcentaje repartido, pesos, reserva de apertura y utilizada, fondo exacto por recurso y la proyección bruto/impuesto/neto de cada ganador.</p>

                <?php if (!$settlementFrozen): ?>
                    <ul class="reconciliation-list">
                        <?php foreach ((array) ($freezeChecks['checks'] ?? []) as $check): ?>
                            <li class="<?= !empty($check['passed']) ? 'is-passed' : 'is-pending' ?>"><span aria-hidden="true"><?= !empty($check['passed']) ? '✓' : '!' ?></span><span><?= h((string) $check['label']) ?><?php if (!empty($check['detail'])): ?> <em><?= h((string) $check['detail']) ?></em><?php endif; ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <form method="post" action="admin.php?view=settlement" data-confirm="¿Congelar la liquidación? Después no podrás cambiar los valores financieros sin desbloquearla y dejar un motivo.">
                        <?= csrf_input() ?><input type="hidden" name="action" value="freeze_settlement">
                        <button class="button button-primary" type="submit" data-testid="freeze-settlement" <?= empty($freezeChecks['ready']) ? 'disabled' : '' ?>>Congelar liquidación</button>
                    </form>
                <?php else: ?>
                    <p class="panel-note">Congelada el <?= h(format_datetime((string) $settings['frozen_at'])) ?>.</p>
                    <form method="post" action="admin.php?view=settlement" class="stacked-form" data-confirm="Desbloquear la liquidación permite volver a cambiar cifras ya anunciadas. ¿Continuar?">
                        <?= csrf_input() ?><input type="hidden" name="action" value="unfreeze_settlement">
                        <div class="settlement-banner" role="alert"><strong>Advertencia.</strong><span>Al desbloquear podrás modificar el premio ya anunciado. El motivo quedará en la auditoría y sólo debe hacerse antes de pagar.</span></div>
                        <label class="field"><span class="field-label">Motivo obligatorio del desbloqueo</span><textarea name="unfreeze_reason" data-testid="unfreeze-reason" maxlength="500" rows="2" required></textarea></label>
                        <button class="button button-danger" type="submit" data-testid="unfreeze-settlement">Desbloquear liquidación</button>
                    </form>
                <?php endif; ?>
            </section>

            <section class="admin-panel archive-danger-panel">
                <div class="panel-heading"><div><span class="eyebrow">Paso 2 · Conciliación obligatoria</span><h2>Cerrar el evento</h2></div><span class="<?= !empty($eventReconciliation['ready']) ? 'check-pill check-pass' : 'check-pill check-pending' ?>"><?= !empty($eventReconciliation['ready']) ? 'Listo' : 'Pendiente' ?></span></div>
                <p class="panel-intro">El evento ya no necesita terminar en saldo cero. Puede cerrarse con saldo distinto de cero siempre que coincida exactamente con la reserva calculada.</p>
                <ul class="reconciliation-list" data-testid="close-checklist">
                    <?php foreach ((array) ($eventReconciliation['checks'] ?? []) as $check): ?>
                        <li class="<?= !empty($check['passed']) ? 'is-passed' : 'is-pending' ?>"><span aria-hidden="true"><?= !empty($check['passed']) ? '✓' : '!' ?></span><span><?= h((string) $check['label']) ?><?php if (!empty($check['detail'])): ?> <em><?= h((string) $check['detail']) ?></em><?php endif; ?></span></li>
                    <?php endforeach; ?>
                </ul>
                <dl class="reconciliation-totals">
                    <div><dt>Recibido por el banco</dt><dd><?= h(format_integer((int) ($eventReconciliation['summary']['received_grand_total'] ?? 0))) ?></dd></div>
                    <div><dt>Enviado a ganadores</dt><dd><?= h(format_integer((int) ($eventReconciliation['summary']['paid_grand_total'] ?? 0))) ?></dd></div>
                    <div><dt>Recibido realmente</dt><dd><?= h(format_integer((int) ($eventReconciliation['summary']['winner_receives_grand_total'] ?? 0))) ?></dd></div>
                    <div><dt>Impuesto comprobado</dt><dd><?= h(format_integer((int) ($eventReconciliation['summary']['outgoing_tax_grand_total'] ?? 0))) ?></dd></div>
                    <div><dt>Reserva al cierre</dt><dd><?= h(format_integer((int) $fundBreakdown['reserve_closing_grand'])) ?></dd></div>
                </dl>
                <div class="table-shell"><table class="data-table admin-table"><thead><tr><th>Recurso</th><th>Reserva calculada</th><th>Saldo físico</th><th>Diferencia</th></tr></thead><tbody>
                    <?php foreach (resource_keys() as $resource): $gap = (int) $fundBreakdown['variance'][$resource]; ?>
                        <tr><th scope="row" data-label="Recurso"><?= h(resource_title($resource)) ?></th><td data-label="Reserva calculada"><?= h(format_integer((int) $fundBreakdown['reserve_closing'][$resource])) ?></td><td data-label="Saldo físico"><?= h(format_integer((int) $fundBreakdown['physical_total'][$resource])) ?></td><td data-label="Diferencia" class="<?= $gap === 0 ? 'quota-ok' : 'quota-missing' ?>"><?= $gap > 0 ? '+' : '' ?><?= h(format_integer($gap)) ?></td></tr>
                    <?php endforeach; ?>
                </tbody></table></div>
                <form method="post" action="admin.php?view=settlement" class="stacked-form" data-confirm="¿Cerrar el evento actual e iniciar uno nuevo? La reserva acumulada continuará intacta.">
                    <?= csrf_input() ?><input type="hidden" name="action" value="archive_event">
                    <label class="field"><span class="field-label">Nombre del próximo evento</span><input type="text" name="next_event_name" data-testid="next-event-name" maxlength="100" required></label>
                    <label class="field"><span class="field-label">Escribe ARCHIVAR</span><input type="text" name="archive_confirmation" data-testid="archive-confirmation" autocomplete="off" required></label>
                    <button class="button button-danger" type="submit" data-testid="archive-event" <?= empty($eventReconciliation['ready']) ? 'disabled' : '' ?>>Cerrar evento e iniciar el siguiente</button>
                </form>
            </section>

        <?php elseif ($view === 'announcements'): ?>
            <?php if (!$announcementsReady): ?>
                <section class="admin-panel"><div class="empty-inline">Los comunicados necesitan el almacenamiento MySQL del banco.</div></section>
            <?php else: ?>
                <?php
                $editing = $announcementForm !== null;
                $editingId = $editing ? (int) ($announcementForm['id'] ?? 0) : 0;
                $editingStatus = $editing ? (string) ($announcementForm['status'] ?? 'draft') : 'draft';
                $editingBlocks = $editing ? (array) ($announcementForm['content']['blocks'] ?? []) : [];
                $mediaByKind = ['image' => [], 'video' => [], 'audio' => []];
                foreach ($mediaLibrary as $mediaRow) {
                    $mediaByKind[(string) $mediaRow['kind']][] = $mediaRow;
                }
                ?>

                <section class="admin-panel">
                    <div class="panel-heading"><div><span class="eyebrow">Tablón oficial</span><h2>Comunicados</h2></div><a class="button button-secondary" href="admin.php?view=announcements&amp;announcement_id=0" data-testid="new-announcement">Crear comunicado</a></div>
                    <div class="filter-group" role="group" aria-label="Filtrar comunicados por estado">
                        <a class="filter-chip <?= $announcementStatusFilter === '' ? 'is-active' : '' ?>" href="admin.php?view=announcements">Todos</a>
                        <?php foreach (announcement_status_keys() as $statusKey): ?>
                            <a class="filter-chip <?= $announcementStatusFilter === $statusKey ? 'is-active' : '' ?>" href="admin.php?view=announcements&amp;status=<?= h($statusKey) ?>"><?= h(announcement_status_label($statusKey)) ?></a>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($announcementList === []): ?>
                        <div class="empty-inline">Todavía no hay comunicados. Crea el primero con el editor de abajo.</div>
                    <?php else: ?>
                        <div class="table-shell"><table class="data-table admin-table" data-testid="announcement-list"><thead><tr><th>Título</th><th>Estado</th><th>Categoría</th><th>Fecha</th><th>Acciones</th></tr></thead><tbody>
                            <?php foreach ($announcementList as $item): ?>
                                <tr>
                                    <th scope="row" data-label="Título"><?= h((string) $item['title']) ?><small><?= h((string) $item['slug']) ?> · v<?= h((string) $item['version']) ?><?= !empty($item['is_pinned']) ? ' · fijado' : '' ?></small></th>
                                    <td data-label="Estado"><span class="status-inline status-<?= h((string) $item['status']) ?>"><?= h(announcement_status_label((string) $item['status'])) ?></span></td>
                                    <td data-label="Categoría"><?= h(announcement_categories()[(string) $item['category']] ?? 'Anuncio') ?></td>
                                    <td data-label="Fecha"><?= h(announcement_display_date($item)) ?></td>
                                    <td data-label="Acciones" class="row-actions">
                                        <a class="small-button" href="admin.php?view=announcements&amp;announcement_id=<?= (int) $item['id'] ?>">Editar</a>
                                        <?php if ((string) $item['status'] === 'published'): ?>
                                            <a class="small-button" href="<?= h(announcement_public_url($item)) ?>" target="_blank" rel="noopener">Ver</a>
                                        <?php endif; ?>
                                        <form method="post" action="admin.php?view=announcements"><?= csrf_input() ?><input type="hidden" name="action" value="announcement_duplicate"><input type="hidden" name="announcement_id" value="<?= (int) $item['id'] ?>"><button class="small-button" type="submit">Duplicar</button></form>
                                        <?php if ((string) $item['status'] !== 'published'): ?>
                                            <form method="post" action="admin.php?view=announcements" data-confirm="¿Publicar este comunicado para toda la alianza?"><?= csrf_input() ?><input type="hidden" name="action" value="announcement_publish"><input type="hidden" name="announcement_id" value="<?= (int) $item['id'] ?>"><button class="small-button" type="submit">Publicar</button></form>
                                        <?php else: ?>
                                            <form method="post" action="admin.php?view=announcements"><?= csrf_input() ?><input type="hidden" name="action" value="announcement_unpublish"><input type="hidden" name="announcement_id" value="<?= (int) $item['id'] ?>"><button class="small-button" type="submit">Retirar</button></form>
                                            <form method="post" action="admin.php?view=announcements"><?= csrf_input() ?><input type="hidden" name="action" value="<?= !empty($item['is_pinned']) ? 'announcement_unpin' : 'announcement_pin' ?>"><input type="hidden" name="announcement_id" value="<?= (int) $item['id'] ?>"><button class="small-button" type="submit"><?= !empty($item['is_pinned']) ? 'Desfijar' : 'Fijar' ?></button></form>
                                        <?php endif; ?>
                                        <?php if ((string) $item['status'] !== 'archived'): ?>
                                            <form method="post" action="admin.php?view=announcements"><?= csrf_input() ?><input type="hidden" name="action" value="announcement_archive"><input type="hidden" name="announcement_id" value="<?= (int) $item['id'] ?>"><button class="small-button" type="submit">Archivar</button></form>
                                        <?php endif; ?>
                                        <form method="post" action="admin.php?view=announcements" data-confirm="¿Eliminar este comunicado? Los archivos compartidos con otros comunicados se conservarán."><?= csrf_input() ?><input type="hidden" name="action" value="announcement_delete"><input type="hidden" name="announcement_id" value="<?= (int) $item['id'] ?>"><button class="small-button button-danger" type="submit">Eliminar</button></form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody></table></div>
                    <?php endif; ?>
                </section>

                <?php if ($announcementPreview !== null): ?>
                    <section class="admin-panel" data-testid="announcement-preview">
                        <div class="panel-heading"><div><span class="eyebrow">Vista previa exacta</span><h2><?= h((string) $announcementPreview['title']) ?></h2></div><span class="record-count">Generada por el mismo motor que la página pública</span></div>
                        <?php if ((string) $announcementPreview['summary'] !== ''): ?><p class="announcement-summary"><?= h((string) $announcementPreview['summary']) ?></p><?php endif; ?>
                        <div class="announcement-body announcement-preview-body"><?= announcement_render_blocks((array) $announcementPreview['content']) ?></div>
                    </section>
                <?php endif; ?>

                <section class="admin-panel">
                    <div class="panel-heading"><div><span class="eyebrow">Biblioteca</span><h2>Imágenes, videos y audios</h2></div><span class="record-count"><?= h((string) count($mediaLibrary)) ?> archivos</span></div>
                    <p class="panel-intro">
                        Límite efectivo por archivo: imagen <?= h(media_format_bytes((int) $mediaLimits['image']['effective'])) ?>,
                        audio <?= h(media_format_bytes((int) $mediaLimits['audio']['effective'])) ?>,
                        video <?= h(media_format_bytes((int) $mediaLimits['video']['effective'])) ?>.
                        Es el valor más bajo entre la configuración de ROKBANK, <code>upload_max_filesize</code> (<?= h((string) $mediaLimits['upload_max_filesize']) ?>)
                        y <code>post_max_size</code> (<?= h((string) $mediaLimits['post_max_size']) ?>). Formatos admitidos: JPEG, PNG, WebP, GIF, MP4, WebM, MP3, M4A, OGG, WAV y subtítulos WebVTT (.vtt).
                        SVG se rechaza por seguridad y toda imagen necesita texto alternativo: sin él la carga se rechaza.
                    </p>
                    <form method="post" action="admin.php?view=announcements" enctype="multipart/form-data" class="settings-form" data-testid="media-upload-form">
                        <?= csrf_input() ?><input type="hidden" name="action" value="media_upload"><input type="hidden" name="announcement_id" value="<?= (int) $editingId ?>">
                        <div class="settings-grid">
                            <label class="field settings-span-2"><span class="field-label">Archivos (puedes elegir varios a la vez)</span><input type="file" name="media_files[]" data-testid="media-files" multiple accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,audio/mpeg,audio/mp4,audio/ogg,audio/wav"></label>
                            <label class="field"><span class="field-label">Texto alternativo (obligatorio para imágenes)</span><input type="text" name="alt_text" data-testid="media-alt" maxlength="300"></label>
                            <label class="field"><span class="field-label">Pie de contenido</span><input type="text" name="caption" data-testid="media-caption" maxlength="300"></label>
                        </div>
                        <button class="button button-secondary" type="submit">Subir archivos</button>
                    </form>
                    <?php if ($mediaLibrary !== []): ?>
                        <div class="table-shell"><table class="data-table admin-table"><thead><tr><th>Archivo</th><th>Tipo</th><th>Tamaño</th><th>Usos</th><th>Descripción</th></tr></thead><tbody>
                            <?php foreach ($mediaLibrary as $mediaRow): ?>
                                <tr>
                                    <th scope="row" data-label="Archivo">#<?= (int) $mediaRow['id'] ?> <?= h((string) $mediaRow['original_name']) ?><small><?= h((string) $mediaRow['mime_type']) ?><?= (int) $mediaRow['width'] > 0 ? ' · ' . (int) $mediaRow['width'] . '×' . (int) $mediaRow['height'] : '' ?></small></th>
                                    <td data-label="Tipo"><?= h(media_kinds()[(string) $mediaRow['kind']] ?? '') ?></td>
                                    <td data-label="Tamaño"><?= h(media_format_bytes((int) $mediaRow['size_bytes'])) ?></td>
                                    <td data-label="Usos"><?= h((string) media_reference_count((int) $mediaRow['id'])) ?></td>
                                    <td data-label="Descripción">
                                        <form method="post" action="admin.php?view=announcements" class="media-inline-form">
                                            <?= csrf_input() ?><input type="hidden" name="action" value="media_update"><input type="hidden" name="media_id" value="<?= (int) $mediaRow['id'] ?>">
                                            <input type="text" name="alt_text" value="<?= h((string) $mediaRow['alt_text']) ?>" maxlength="300" placeholder="Texto alternativo" aria-label="Texto alternativo de #<?= (int) $mediaRow['id'] ?>">
                                            <input type="text" name="caption" value="<?= h((string) $mediaRow['caption']) ?>" maxlength="300" placeholder="Pie" aria-label="Pie de #<?= (int) $mediaRow['id'] ?>">
                                            <button class="small-button" type="submit">Guardar</button>
                                        </form>
                                        <form method="post" action="admin.php?view=announcements" data-confirm="¿Eliminar este archivo de la biblioteca?">
                                            <?= csrf_input() ?><input type="hidden" name="action" value="media_delete"><input type="hidden" name="media_id" value="<?= (int) $mediaRow['id'] ?>">
                                            <button class="small-button button-danger" type="submit">Eliminar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody></table></div>
                    <?php endif; ?>
                </section>

                <section class="admin-panel" id="editor">
                    <div class="panel-heading"><div><span class="eyebrow"><?= $editingId > 0 ? 'Editar comunicado' : 'Comunicado nuevo' ?></span><h2><?= $editingId > 0 ? h((string) $announcementForm['title']) : 'Redactar comunicado' ?></h2></div><span class="record-count"><?= h(announcement_status_label($editingStatus)) ?></span></div>

                    <form method="post" action="admin.php?view=announcements" class="settings-form announcement-editor" data-announcement-editor data-testid="announcement-editor-form">
                        <?= csrf_input() ?>
                        <input type="hidden" name="announcement_id" value="<?= (int) $editingId ?>">
                        <input type="hidden" name="current_status" value="<?= h($editingStatus) ?>">

                        <div class="settings-grid">
                            <label class="field settings-span-2"><span class="field-label">Título</span><input type="text" name="title" data-testid="announcement-title" value="<?= h((string) ($announcementForm['title'] ?? '')) ?>" maxlength="200" required></label>
                            <label class="field"><span class="field-label">Dirección corta (slug)</span><input type="text" name="slug" data-testid="announcement-slug" value="<?= h((string) ($announcementForm['slug'] ?? '')) ?>" maxlength="160" placeholder="se genera desde el título"><small>Sólo minúsculas, números y guiones. Debe ser única.</small></label>
                            <label class="field"><span class="field-label">Categoría</span><select name="category" data-testid="announcement-category">
                                <?php foreach (announcement_categories() as $key => $label): ?>
                                    <option value="<?= h($key) ?>" <?= (string) ($announcementForm['category'] ?? 'anuncio') === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select></label>
                            <label class="field settings-span-2"><span class="field-label">Resumen opcional</span><textarea name="summary" data-testid="announcement-summary" maxlength="400" rows="2"><?= h((string) ($announcementForm['summary'] ?? '')) ?></textarea></label>
                            <label class="field"><span class="field-label">Imagen de portada</span><select name="cover_media_id" data-testid="announcement-cover">
                                <option value="0">— Sin portada —</option>
                                <?php foreach ($mediaByKind['image'] as $mediaRow): ?>
                                    <option value="<?= (int) $mediaRow['id'] ?>" <?= (int) ($announcementForm['cover_media_id'] ?? 0) === (int) $mediaRow['id'] ? 'selected' : '' ?>>#<?= (int) $mediaRow['id'] ?> · <?= h((string) $mediaRow['original_name']) ?></option>
                                <?php endforeach; ?>
                            </select></label>
                            <label class="field"><span class="field-label">Evento relacionado</span><select name="related_event_id" data-testid="announcement-event">
                                <option value="">— Ninguno —</option>
                                <?php foreach (all_archives() as $archiveOption): ?>
                                    <?php $optionSettings = settings_normalize($archiveOption['settings'] ?? []); ?>
                                    <option value="<?= h((string) $archiveOption['id']) ?>" <?= (string) ($announcementForm['related_event_id'] ?? '') === (string) $archiveOption['id'] ? 'selected' : '' ?>><?= h((string) $optionSettings['event_name']) ?></option>
                                <?php endforeach; ?>
                            </select></label>
                            <label class="field checkbox-field"><input type="checkbox" name="is_pinned" value="1" data-testid="announcement-pinned" <?= !empty($announcementForm['is_pinned']) ? 'checked' : '' ?>><span>Fijar en la portada</span></label>
                            <label class="field"><span class="field-label">Nota del cambio (para las revisiones)</span><input type="text" name="change_note" maxlength="300"></label>
                        </div>

                        <h3 class="settings-subtitle">Contenido por bloques</h3>
                        <p class="panel-intro" id="announcement-markup-help">
                            Dentro de los textos puedes usar <code>**negrita**</code>, <code>//cursiva//</code>, <code>__subrayado__</code>,
                            <code>~~tachado~~</code>, <code>[texto](https://enlace)</code>, <code>{c:rojo}color{/c}</code> y <code>{f:amarillo}resaltado{/f}</code>.
                            Los emojis y cualquier alfabeto Unicode se conservan exactamente. Por seguridad no se acepta HTML: el servidor genera el
                            diseño a partir de una lista de bloques y colores aprobados.
                        </p>
                        <p class="panel-intro">Colores de texto: <?= h(implode(', ', array_keys(announcement_text_palette()))) ?>. Resaltados: <?= h(implode(', ', array_keys(announcement_highlight_palette()))) ?>.</p>

                        <div class="an-block-toolbar">
                            <?php foreach (announcement_block_types() as $blockType): ?>
                                <button class="small-button" type="button" data-an-add="<?= h($blockType) ?>" data-testid="add-block-<?= h($blockType) ?>">+ <?= h(announcement_block_label($blockType)) ?></button>
                            <?php endforeach; ?>
                        </div>

                        <div class="an-blocks" data-an-blocks data-testid="announcement-blocks">
                            <?php foreach ($editingBlocks as $index => $block): ?>
                                <?php render_announcement_block_editor((int) $index, (array) $block, $mediaByKind); ?>
                            <?php endforeach; ?>
                        </div>
                        <p class="empty-inline an-blocks-empty" data-an-empty <?= $editingBlocks === [] ? '' : 'hidden' ?>>Añade el primer bloque con los botones de arriba.</p>
                        <?php render_announcement_block_templates($mediaByKind); ?>

                        <div class="form-actions announcement-actions">
                            <button class="button button-secondary" type="submit" name="action" value="announcement_preview" data-testid="announcement-preview-button">Vista previa</button>
                            <button class="button button-primary" type="submit" name="action" value="announcement_save" data-testid="announcement-save-button">Guardar borrador</button>
                            <?php if ($editingId > 0): ?>
                                <button class="button button-primary" type="submit" name="action" value="announcement_publish" data-testid="announcement-publish-button" formnovalidate>Publicar</button>
                            <?php else: ?>
                                <span class="panel-note">Guarda el borrador antes de publicarlo. La publicación siempre es una acción explícita.</span>
                            <?php endif; ?>
                        </div>
                    </form>
                </section>

                <?php if ($announcementRevisions !== []): ?>
                    <section class="admin-panel records-panel">
                        <div class="panel-heading"><div><span class="eyebrow">Trazabilidad editorial</span><h2>Revisiones anteriores</h2></div><span class="record-count"><?= h((string) count($announcementRevisions)) ?></span></div>
                        <div class="table-shell"><table class="data-table admin-table"><thead><tr><th>Versión</th><th>Fecha</th><th>Autor</th><th>Nota</th><th>Acción</th></tr></thead><tbody>
                            <?php foreach ($announcementRevisions as $revision): ?>
                                <tr>
                                    <th scope="row" data-label="Versión">v<?= (int) $revision['version'] ?></th>
                                    <td data-label="Fecha"><?= h(format_datetime((string) $revision['created_at'])) ?></td>
                                    <td data-label="Autor"><?= h((string) $revision['created_by']) ?></td>
                                    <td data-label="Nota"><?= h((string) $revision['change_note']) ?></td>
                                    <td data-label="Acción">
                                        <form method="post" action="admin.php?view=announcements">
                                            <?= csrf_input() ?><input type="hidden" name="action" value="announcement_restore_revision"><input type="hidden" name="revision_id" value="<?= (int) $revision['id'] ?>">
                                            <button class="small-button" type="submit">Restaurar como borrador nuevo</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody></table></div>
                    </section>
                <?php endif; ?>
            <?php endif; ?>

        <?php elseif ($view === 'history'): ?>
            <section class="admin-panel history-browser-panel">
                <div class="panel-heading"><div><span class="eyebrow">Archivo permanente</span><h2>Eventos cerrados</h2></div><span class="record-count"><?= h((string) count($archives)) ?> evento<?= count($archives) === 1 ? '' : 's' ?></span></div>
                <p class="panel-intro">Selecciona un evento para ver todos sus participantes, aportes, premios, impuestos y revisiones. Las correcciones crean una copia automática y exigen un motivo.</p>
                <?php if ($archives === []): ?>
                    <div class="empty-inline">Todavía no hay eventos archivados.</div>
                <?php else: ?>
                    <div class="history-event-list">
                        <?php foreach ($archives as $archive): ?>
                            <?php $itemSettings = settings_normalize($archive['settings'] ?? []); $itemSummary = (array) ($archive['summary'] ?? []); ?>
                            <a class="history-event-link <?= $selectedArchive !== null && (string) $selectedArchive['id'] === (string) $archive['id'] ? 'is-active' : '' ?>" href="admin.php?view=history&amp;archive_id=<?= h(rawurlencode((string) $archive['id'])) ?>">
                                <span><?= h(format_datetime((string) ($archive['archived_at'] ?? ''))) ?></span>
                                <strong><?= h((string) $itemSettings['event_name']) ?></strong>
                                <small><?= h((string) ($itemSummary['player_count'] ?? 0)) ?> participantes · <?= h(format_integer((int) ($itemSummary['winner_receives_grand_total'] ?? 0))) ?> recibidos por ganadores</small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($archiveId !== '' && $selectedArchive === null): ?>
                <div class="form-errors" role="alert">No se encontró el evento histórico solicitado.</div>
            <?php elseif ($selectedArchive !== null): ?>
                <?php
                $archiveSettings = settings_normalize($selectedArchive['settings'] ?? []);
                $archiveSummary = (array) ($selectedArchive['summary'] ?? []);
                $archiveMetric = (string) $archiveSettings['score_metric_label'];
                $archiveWinners = winners_normalize($selectedArchive['winners'] ?? [], $archiveMetric);
                $archiveWinnersByRank = [];
                foreach ($archiveWinners as $winner) {
                    $archiveWinnersByRank[(string) $winner['rank']] = $winner;
                }
                ?>
                <section class="admin-panel records-panel">
                    <div class="panel-heading"><div><span class="eyebrow">Datos generales</span><h2><?= h((string) $archiveSettings['event_name']) ?></h2></div><a class="button button-secondary" href="history.php?id=<?= h(rawurlencode((string) $selectedArchive['id'])) ?>" target="_blank" rel="noopener">Vista pública</a></div>
                    <form method="post" action="admin.php?view=history&amp;archive_id=<?= h(rawurlencode((string) $selectedArchive['id'])) ?>" class="settings-form">
                        <?= csrf_input() ?><input type="hidden" name="action" value="update_archived_event"><input type="hidden" name="archive_id" value="<?= h((string) $selectedArchive['id']) ?>">
                        <div class="settings-grid">
                            <label class="field"><span class="field-label">Nombre del evento</span><input type="text" name="event_name" value="<?= h((string) $archiveSettings['event_name']) ?>" maxlength="100" required></label>
                            <label class="field"><span class="field-label">Premio o explicación</span><input type="text" name="prize" value="<?= h((string) $archiveSettings['prize']) ?>" maxlength="220"></label>
                            <label class="field"><span class="field-label">Métrica histórica de este evento</span><input type="text" name="score_metric_label" value="<?= h($archiveMetric) ?>" maxlength="120" required><small>Se conserva tal como estaba al cerrar. No se convierte a la métrica del evento actual.</small></label>
                        </div>
                        <div class="winner-settings-grid archive-winner-settings">
                            <?php foreach (reward_rank_keys() as $rank): ?>
                                <?php $winner = $archiveWinnersByRank[$rank] ?? []; ?>
                                <div class="winner-setting-row"><strong><?= h(reward_rank_label($rank)) ?></strong><label class="field"><span class="field-label">Jugador</span><input class="notranslate" translate="no" type="text" name="winner_<?= h($rank) ?>_name" value="<?= h((string) ($winner['player_name'] ?? '')) ?>" maxlength="80" required></label><label class="field"><span class="field-label"><?= h($archiveMetric) ?></span><input type="text" name="winner_<?= h($rank) ?>_score" value="<?= h((string) ($winner['score'] ?? '')) ?>" inputmode="numeric" required></label></div>
                            <?php endforeach; ?>
                        </div>
                        <label class="field correction-reason"><span class="field-label">Motivo obligatorio de la corrección</span><textarea name="correction_reason" maxlength="500" required placeholder="Explica qué evidencia justifica el cambio."></textarea></label>
                        <button class="button button-primary" type="submit">Guardar corrección histórica</button>
                    </form>
                </section>

                <section class="history-summary-grid">
                    <div><span>Recibido por el banco</span><strong><?= h(format_integer((int) ($archiveSummary['received_grand_total'] ?? 0))) ?></strong></div>
                    <div><span>Enviado bruto</span><strong><?= h(format_integer((int) ($archiveSummary['paid_grand_total'] ?? 0))) ?></strong></div>
                    <div><span>Recibido por ganadores</span><strong><?= h(format_integer((int) ($archiveSummary['winner_receives_grand_total'] ?? 0))) ?></strong></div>
                    <div><span>Impuesto de Lilith</span><strong><?= h(format_integer((int) ($archiveSummary['outgoing_tax_grand_total'] ?? 0))) ?></strong></div>
                </section>

                <?php if ($archiveContributionEdit !== null): ?>
                    <section class="admin-panel records-panel narrow-panel">
                        <div class="panel-heading"><div><span class="eyebrow">Corrección histórica</span><h2>Editar aporte</h2></div><a class="text-link" href="admin.php?view=history&amp;archive_id=<?= h(rawurlencode((string) $selectedArchive['id'])) ?>">Cancelar</a></div>
                        <form method="post" class="contribution-form" action="admin.php?view=history&amp;archive_id=<?= h(rawurlencode((string) $selectedArchive['id'])) ?>">
                            <?= csrf_input() ?><input type="hidden" name="action" value="update_archived_contribution"><input type="hidden" name="archive_id" value="<?= h((string) $selectedArchive['id']) ?>"><input type="hidden" name="record_id" value="<?= h((string) $archiveContributionEdit['id']) ?>">
                            <label class="field"><span class="field-label">Jugador (cambia todas sus filas históricas)</span><input class="notranslate" translate="no" type="text" name="player_name" value="<?= h((string) ($archiveContributionEdit['player_name_snapshot'] ?? '')) ?>" maxlength="80" required></label>
                            <div class="resource-input-grid"><?php foreach (resource_keys() as $resource): ?><?php render_resource_input($resource, format_integer((int) $archiveContributionEdit[$resource]), true); ?><?php endforeach; ?></div>
                            <label class="field"><span class="field-label">Nota</span><input type="text" name="note" value="<?= h((string) ($archiveContributionEdit['note'] ?? '')) ?>" maxlength="180"></label>
                            <label class="field"><span class="field-label">Motivo obligatorio</span><textarea name="correction_reason" maxlength="500" required></textarea></label>
                            <button class="button button-primary" type="submit">Guardar aporte corregido</button>
                        </form>
                    </section>
                <?php endif; ?>

                <?php if ($archiveDisbursementEdit !== null): ?>
                    <section class="admin-panel records-panel narrow-panel">
                        <div class="panel-heading"><div><span class="eyebrow">Corrección histórica</span><h2>Editar entrega</h2></div><a class="text-link" href="admin.php?view=history&amp;archive_id=<?= h(rawurlencode((string) $selectedArchive['id'])) ?>">Cancelar</a></div>
                        <form method="post" class="contribution-form" action="admin.php?view=history&amp;archive_id=<?= h(rawurlencode((string) $selectedArchive['id'])) ?>">
                            <?= csrf_input() ?><input type="hidden" name="action" value="update_archived_disbursement"><input type="hidden" name="archive_id" value="<?= h((string) $selectedArchive['id']) ?>"><input type="hidden" name="record_id" value="<?= h((string) $archiveDisbursementEdit['id']) ?>">
                            <label class="field"><span class="field-label">Destinatario</span><input class="notranslate" translate="no" type="text" name="recipient_name" value="<?= h((string) $archiveDisbursementEdit['recipient_name']) ?>" maxlength="80" required></label>
                            <h3 class="settings-subtitle">Enviado bruto</h3><div class="resource-input-grid"><?php foreach (resource_keys() as $resource): ?><?php render_resource_input($resource, format_integer((int) $archiveDisbursementEdit[$resource]), true); ?><?php endforeach; ?></div>
                            <h3 class="settings-subtitle">Recibido realmente</h3><div class="resource-input-grid"><?php foreach (resource_keys() as $resource): ?><label class="field resource-field"><span class="field-label"><?php render_resource_mark($resource); ?> <?= h(resource_title($resource)) ?></span><input type="text" name="actual_<?= h($resource) ?>" value="<?= h(format_integer((int) ($archiveDisbursementEdit['actual_received'][$resource] ?? 0))) ?>" inputmode="decimal" required></label><?php endforeach; ?></div>
                            <div class="settings-grid"><label class="field"><span class="field-label">Transportes</span><input type="number" name="transport_count" value="<?= h((string) ($archiveDisbursementEdit['transport_count'] ?? 0)) ?>" min="0" required></label><label class="field"><span class="field-label">Motivo de la entrega</span><input type="text" name="note" value="<?= h((string) ($archiveDisbursementEdit['note'] ?? '')) ?>" maxlength="180"></label></div>
                            <label class="field"><span class="field-label">Referencia de evidencia</span><input type="text" name="evidence_note" value="<?= h((string) ($archiveDisbursementEdit['evidence_note'] ?? '')) ?>" maxlength="500"></label>
                            <label class="field"><span class="field-label">Motivo obligatorio de la corrección</span><textarea name="correction_reason" maxlength="500" required></textarea></label>
                            <button class="button button-primary" type="submit">Guardar entrega corregida</button>
                        </form>
                    </section>
                <?php endif; ?>

                <section class="admin-panel records-panel">
                    <div class="panel-heading"><div><span class="eyebrow">Registro completo</span><h2>Participantes y aportes</h2></div><span class="record-count"><?= h((string) count($selectedArchive['contributions'])) ?> filas</span></div>
                    <div class="table-shell"><table class="data-table admin-table"><thead><tr><th>Jugador</th><th>Comida</th><th>Madera</th><th>Piedra</th><th>Oro</th><th>Fecha</th><th>Acción</th></tr></thead><tbody>
                        <?php foreach ($selectedArchive['contributions'] as $record): ?><tr><th scope="row"><?php render_player_name((string) ($record['player_name_snapshot'] ?? '')); ?></th><?php foreach (resource_keys() as $resource): ?><td><?= h(format_integer((int) $record[$resource])) ?></td><?php endforeach; ?><td><?= h(format_datetime((string) $record['created_at'])) ?></td><td><a class="small-button" href="admin.php?view=history&amp;archive_id=<?= h(rawurlencode((string) $selectedArchive['id'])) ?>&amp;archive_contribution_id=<?= h(rawurlencode((string) $record['id'])) ?>">Editar</a></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                </section>

                <section class="admin-panel records-panel">
                    <div class="panel-heading"><div><span class="eyebrow">Premios conciliados</span><h2>Entregas a ganadores</h2></div><span class="record-count"><?= h((string) count($selectedArchive['disbursements'])) ?> filas</span></div>
                    <div class="table-shell"><table class="data-table admin-table"><thead><tr><th>Ganador</th><th>Enviado bruto</th><th>Recibido real</th><th>Impuesto</th><th>Transportes</th><th>Acción</th></tr></thead><tbody>
                        <?php foreach ($selectedArchive['disbursements'] as $record): ?><?php $sentTotal = 0; $actualTotal = 0; foreach (resource_keys() as $resource) { $sentTotal += (int) $record[$resource]; $actualTotal += disbursement_actual_received($record, $resource); } ?><tr><th scope="row"><?php render_player_name((string) $record['recipient_name']); ?></th><td><?= h(format_integer($sentTotal)) ?></td><td><?= h(format_integer($actualTotal)) ?></td><td><?= h(format_integer($sentTotal - $actualTotal)) ?></td><td><?= h((string) ($record['transport_count'] ?? 0)) ?></td><td><a class="small-button" href="admin.php?view=history&amp;archive_id=<?= h(rawurlencode((string) $selectedArchive['id'])) ?>&amp;archive_disbursement_id=<?= h(rawurlencode((string) $record['id'])) ?>">Editar</a></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                </section>

                <section class="admin-panel records-panel">
                    <div class="panel-heading"><div><span class="eyebrow">Trazabilidad histórica</span><h2>Revisiones</h2></div><div class="form-actions"><a class="button button-secondary" href="export.php?format=archive-csv&amp;id=<?= h(rawurlencode((string) $selectedArchive['id'])) ?>">CSV</a><a class="button button-secondary" href="export.php?format=archive-json&amp;id=<?= h(rawurlencode((string) $selectedArchive['id'])) ?>">JSON</a></div></div>
                    <?php if (($selectedArchive['revision_log'] ?? []) === []): ?><div class="empty-inline">El evento conserva su versión original; todavía no tiene correcciones posteriores.</div><?php else: ?><div class="audit-list"><?php foreach (array_reverse((array) $selectedArchive['revision_log']) as $revision): ?><article><span class="audit-dot" aria-hidden="true"></span><div><strong><?= h((string) ($revision['description'] ?? 'Corrección histórica')) ?></strong><small><?= h(format_datetime((string) ($revision['created_at'] ?? ''))) ?> · <?= h((string) ($revision['reason'] ?? '')) ?></small></div></article><?php endforeach; ?></div><?php endif; ?>
                    <details class="archive-audit-details"><summary>Mostrar <?= h((string) count((array) ($selectedArchive['audit_log'] ?? []))) ?> acciones originales del evento</summary><?php if (($selectedArchive['audit_log'] ?? []) === []): ?><div class="empty-inline">El formato anterior no conservó una copia separada de la actividad.</div><?php else: ?><div class="audit-list"><?php foreach (array_reverse((array) $selectedArchive['audit_log']) as $entry): ?><article><span class="audit-dot" aria-hidden="true"></span><div><strong><?= h((string) ($entry['description'] ?? 'Actividad')) ?></strong><small><?= h(format_datetime((string) ($entry['created_at'] ?? ''))) ?> · <?= h((string) ($entry['action'] ?? '')) ?></small></div></article><?php endforeach; ?></div><?php endif; ?></details>
                </section>
            <?php endif; ?>

        <?php elseif ($view === 'audit'): ?>
            <section class="admin-panel">
                <div class="panel-heading"><div><span class="eyebrow">Trazabilidad</span><h2>Registro de actividad</h2></div><span class="security-tag">Solo lectura</span></div>
                <?php if ($auditEntries === []): ?><div class="empty-inline">Todavía no hay actividad registrada.</div>
                <?php else: ?><div class="audit-list">
                    <?php foreach ($auditEntries as $entry): ?><article><span class="audit-dot" aria-hidden="true"></span><div><strong><?= h((string) $entry['description']) ?></strong><small><?= h(format_datetime((string) $entry['created_at'])) ?> · <?= h((string) $entry['action']) ?></small></div></article><?php endforeach; ?>
                </div><?php endif; ?>
            </section>

        <?php elseif ($view === 'security'): ?>
            <div class="security-grid">
                <section class="admin-panel">
                    <div class="panel-heading"><div><span class="eyebrow">Cuenta <?= h((string) ($admin['username'] ?? '')) ?></span><h2>Cambiar contraseña</h2></div></div>
                    <form method="post" action="admin.php?view=security" class="stacked-form">
                        <?= csrf_input() ?><input type="hidden" name="action" value="change_password">
                        <div class="field"><label class="field-label" for="current-password">Contraseña actual</label><span class="password-control"><input id="current-password" type="password" name="current_password" autocomplete="current-password" required><button type="button" data-toggle-password="current-password">Ver</button></span></div>
                        <div class="field"><label class="field-label" for="new-password">Nueva contraseña</label><span class="password-control"><input id="new-password" type="password" name="new_password" autocomplete="new-password" minlength="<?= h((string) app_config('minimum_password_length')) ?>" required><button type="button" data-toggle-password="new-password">Ver</button></span></div>
                        <div class="field"><label class="field-label" for="new-password-confirmation">Repite la contraseña</label><span class="password-control"><input id="new-password-confirmation" type="password" name="new_password_confirmation" autocomplete="new-password" minlength="<?= h((string) app_config('minimum_password_length')) ?>" required><button type="button" data-toggle-password="new-password-confirmation">Ver</button></span></div>
                        <button class="button button-primary" type="submit">Actualizar contraseña</button>
                    </form>
                    <div class="security-summary"><strong>Protecciones activas</strong><ul><li>Contraseña cifrada</li><li>Bloqueo por intentos fallidos</li><li>Sesión privada con vencimiento</li><li>Formularios protegidos contra solicitudes falsas</li><li>Datos y copias inaccesibles desde la web</li></ul></div>
                </section>

                <section class="admin-panel database-panel">
                    <div class="panel-heading">
                        <div><span class="eyebrow">Almacenamiento permanente</span><h2>Base de datos MySQL</h2></div>
                        <span class="database-online"><i aria-hidden="true"></i>Conectada</span>
                    </div>
                    <p class="panel-intro">Los jugadores, envíos, entregas, eventos antiguos, auditoría y copias automáticas se guardan en tablas independientes.</p>
                    <dl class="command-stats database-stats">
                        <div><dt>Base de datos</dt><dd><?= h((string) ($databaseStatus['database_name'] ?? 'MySQL')) ?></dd></div>
                        <div><dt>Tablas administradas</dt><dd><?= h((string) $databaseStatus['table_count']) ?></dd></div>
                        <div><dt>Prefijo obligatorio</dt><dd><?= h((string) $databaseStatus['table_prefix']) ?></dd></div>
                        <div><dt>Eventos archivados</dt><dd><?= h((string) $databaseStatus['archive_count']) ?></dd></div>
                        <div><dt>Copias automáticas</dt><dd><?= h((string) $databaseStatus['backup_count']) ?></dd></div>
                        <div><dt>Versión del esquema</dt><dd><?= h((string) $databaseStatus['schema_version']) ?></dd></div>
                        <?php if (!empty($databaseStatus['schema_migrated_at'])): ?><div><dt>Historial ampliado</dt><dd><?= h(format_datetime((string) $databaseStatus['schema_migrated_at'])) ?></dd></div><?php endif; ?>
                    </dl>
                    <?php if (!empty($databaseStatus['legacy_imported_at'])): ?>
                        <div class="database-migration-note" role="status">
                            <strong>Datos anteriores importados correctamente</strong>
                            <span><?= h(format_datetime((string) $databaseStatus['legacy_imported_at'])) ?> · <?= h((string) $databaseStatus['legacy_player_count']) ?> jugadores · <?= h((string) $databaseStatus['legacy_contribution_count']) ?> envíos · <?= h((string) $databaseStatus['legacy_archive_count']) ?> eventos anteriores. El archivo original permanece intacto como respaldo.</span>
                        </div>
                    <?php else: ?>
                        <div class="database-migration-note" role="status">
                            <strong>Almacenamiento MySQL inicializado</strong>
                            <span>Esta instalación comenzó directamente en la base de datos.</span>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php render_site_footer(); ?>
