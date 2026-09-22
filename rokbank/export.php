<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$format = strtolower((string) ($_GET['format'] ?? 'csv'));
$date = gmdate('Y-m-d');

if (in_array($format, ['archive-json', 'archive-csv'], true)) {
    $archive = find_archive(trim((string) ($_GET['id'] ?? '')));
    if ($archive === null) {
        http_response_code(404);
        exit('Evento histórico no encontrado.');
    }
    $archiveId = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) $archive['id']);
    if ($format === 'archive-json') {
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="ME58-evento-' . $archiveId . '.json"');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($archive, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo PHP_EOL;
        exit;
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="ME58-evento-' . $archiveId . '.csv"');
    header('X-Content-Type-Options: nosniff');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        exit;
    }
    fwrite($output, "\xEF\xBB\xBF");
    $archiveSettings = settings_normalize($archive['settings'] ?? []);
    $archiveMetric = (string) $archiveSettings['score_metric_label'];
    $archiveFund = is_array($archive['fund_breakdown'] ?? null) ? (array) $archive['fund_breakdown'] : null;
    fputcsv($output, ['EVENTO', (string) $archiveSettings['event_name']]);
    fputcsv($output, ['ARCHIVADO', format_datetime((string) ($archive['archived_at'] ?? ''))]);
    fputcsv($output, ['OBJETIVO', (string) $archiveSettings['objective_title']]);
    fputcsv($output, ['DESCRIPCIÓN DEL OBJETIVO', (string) $archiveSettings['objective_description']]);
    fputcsv($output, ['MÉTRICA DE CLASIFICACIÓN', $archiveMetric]);
    fputcsv($output, ['MÉTODO DE CLASIFICACIÓN', ranking_method_label((string) $archiveSettings['ranking_method'])]);
    fputcsv($output, ['PORCENTAJE REPARTIDO (%)', basis_points_to_text((int) $archiveSettings['prize_pool_basis_points'])]);
    fputcsv($output, ['IMPUESTO DEL JUEGO (%)', (float) $archiveSettings['tax_rate']]);
    fputcsv($output, ['PESO TOP 1', (float) $archiveSettings['reward_weights']['first']]);
    fputcsv($output, ['PESO TOP 2', (float) $archiveSettings['reward_weights']['second']]);
    fputcsv($output, ['PESO TOP 3', (float) $archiveSettings['reward_weights']['third']]);
    foreach (resource_keys() as $resource) {
        fputcsv($output, ['CUOTA REQUERIDA ' . strtoupper(resource_label($resource)), (int) $archiveSettings['thresholds'][$resource]]);
    }
    fputcsv($output, ['TOTAL RECIBIDO POR EL BANCO', (int) ($archive['summary']['received_grand_total'] ?? 0)]);
    fputcsv($output, ['TOTAL ENVIADO BRUTO', (int) ($archive['summary']['paid_grand_total'] ?? 0)]);
    fputcsv($output, ['TOTAL RECIBIDO POR GANADORES', (int) ($archive['summary']['winner_receives_grand_total'] ?? 0)]);
    fputcsv($output, ['TOTAL IMPUESTO', (int) ($archive['summary']['outgoing_tax_grand_total'] ?? 0)]);
    fputcsv($output, []);
    fputcsv($output, ['REGLAS DE CADA PUESTO']);
    fputcsv($output, ['Puesto', 'Regla']);
    foreach (reward_rank_keys() as $rank) {
        fputcsv($output, [reward_rank_label($rank), (string) $archiveSettings['rank_rule_' . $rank]]);
    }
    fputcsv($output, []);
    fputcsv($output, ['CONTABILIDAD POR RECURSO']);
    fputcsv($output, ['Recurso', 'Reserva de apertura', 'Aportado', 'Elegible', 'Premio del evento', 'Retenido como reserva', 'Reserva utilizada', 'Fondo bruto', 'Reserva al cierre']);
    foreach (resource_keys() as $resource) {
        fputcsv($output, [
            resource_title($resource),
            $archiveFund === null ? '' : (int) ($archiveFund['reserve_opening'][$resource] ?? 0),
            $archiveFund === null ? (int) ($archive['summary']['received'][$resource] ?? 0) : (int) ($archiveFund['contributed_total'][$resource] ?? 0),
            $archiveFund === null ? '' : (int) ($archiveFund['eligible_total'][$resource] ?? 0),
            $archiveFund === null ? '' : (int) ($archiveFund['prize_from_event'][$resource] ?? 0),
            $archiveFund === null ? '' : (int) ($archiveFund['retained_from_eligible'][$resource] ?? 0),
            $archiveFund === null ? '' : (int) ($archiveFund['reserve_draw'][$resource] ?? 0),
            $archiveFund === null ? '' : (int) ($archiveFund['prize_fund_gross'][$resource] ?? 0),
            $archiveFund === null ? '' : (int) ($archiveFund['reserve_closing'][$resource] ?? 0),
        ]);
    }
    fputcsv($output, []);
    fputcsv($output, ['GANADORES']);
    fputcsv($output, ['Puesto', 'Jugador', 'Puntuación', 'Métrica', 'Regla del puesto']);
    foreach (winners_normalize($archive['winners'] ?? [], $archiveMetric) as $winner) {
        fputcsv($output, [(int) $winner['position'], (string) $winner['player_name'], (int) $winner['score'], (string) $winner['metric'], (string) ($winner['rank_rule'] ?? '')]);
    }
    fputcsv($output, []);
    fputcsv($output, ['APORTES']);
    fputcsv($output, ['ID', 'Jugador', 'Comida', 'Madera', 'Piedra', 'Oro', 'Fecha ISO', 'Fecha Florida', 'Nota']);
    foreach ($archive['contributions'] as $record) {
        fputcsv($output, [(string) $record['id'], (string) ($record['player_name_snapshot'] ?? ''), (int) $record['food'], (int) $record['wood'], (int) $record['stone'], (int) $record['gold'], (string) $record['created_at'], format_datetime((string) $record['created_at']), (string) ($record['note'] ?? '')]);
    }
    fputcsv($output, []);
    fputcsv($output, ['ENTREGAS']);
    fputcsv($output, ['ID', 'Ganador', 'Comida bruta', 'Madera bruta', 'Piedra bruta', 'Oro bruto', 'Comida neta', 'Madera neta', 'Piedra neta', 'Oro neto', 'Impuesto comida', 'Impuesto madera', 'Impuesto piedra', 'Impuesto oro', 'Transportes', 'Evidencia']);
    foreach ($archive['disbursements'] as $record) {
        fputcsv($output, [(string) $record['id'], (string) $record['recipient_name'], (int) $record['food'], (int) $record['wood'], (int) $record['stone'], (int) $record['gold'], disbursement_actual_received($record, 'food'), disbursement_actual_received($record, 'wood'), disbursement_actual_received($record, 'stone'), disbursement_actual_received($record, 'gold'), disbursement_tax_amount($record, 'food'), disbursement_tax_amount($record, 'wood'), disbursement_tax_amount($record, 'stone'), disbursement_tax_amount($record, 'gold'), (int) ($record['transport_count'] ?? 0), (string) ($record['evidence_note'] ?? '')]);
    }
    fputcsv($output, []);
    fputcsv($output, ['CORRECCIONES HISTÓRICAS']);
    fputcsv($output, ['Fecha ISO', 'Descripción', 'Motivo']);
    foreach ((array) ($archive['revision_log'] ?? []) as $revision) {
        fputcsv($output, [(string) ($revision['created_at'] ?? ''), (string) ($revision['description'] ?? ''), (string) ($revision['reason'] ?? '')]);
    }
    fputcsv($output, []);
    fputcsv($output, ['AUDITORÍA ORIGINAL']);
    fputcsv($output, ['Fecha ISO', 'Acción', 'Entidad', 'Descripción']);
    foreach ((array) ($archive['audit_log'] ?? []) as $entry) {
        fputcsv($output, [(string) ($entry['created_at'] ?? ''), (string) ($entry['action'] ?? ''), (string) ($entry['entity_type'] ?? ''), (string) ($entry['description'] ?? '')]);
    }
    fclose($output);
    exit;
}

if ($format === 'json') {
    $data = database_read(true);
    $data['events_archive'] = all_archives();
    $summary = bank_summary();
    $data['current_event'] = [
        'event_id' => event_current_id($data['settings']),
        'fund_breakdown' => $summary['fund_breakdown'],
        'reward_projection' => $summary['reward_projection'],
        'reconciliation' => current_event_reconciliation()['checks'],
    ];
    $data['reserve_balances'] = reserve_balances_from_movements((array) $data['reserve_movements']);
    if (announcements_available()) {
        $data['announcements'] = array_map(static function (array $announcement): array {
            return [
                'id' => (int) $announcement['id'],
                'slug' => (string) $announcement['slug'],
                'title' => (string) $announcement['title'],
                'summary' => (string) $announcement['summary'],
                'status' => (string) $announcement['status'],
                'category' => (string) $announcement['category'],
                'is_pinned' => (bool) $announcement['is_pinned'],
                'related_event_id' => (string) $announcement['related_event_id'],
                'published_at' => $announcement['published_at'],
                'created_at' => (string) $announcement['created_at'],
                'updated_at' => (string) $announcement['updated_at'],
                'version' => (int) $announcement['version'],
                'content' => $announcement['content'],
            ];
        }, announcement_list('', 500, false));
        $data['announcement_media'] = media_list(500);
    }
    unset($data['admin'], $data['login_attempts']);
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="ME58-banco-' . $date . '.json"');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo PHP_EOL;
    exit;
}

if ($format !== 'csv') {
    http_response_code(400);
    exit('Formato no válido.');
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="ME58-aportes-' . $date . '.csv"');
header('X-Content-Type-Options: nosniff');

$output = fopen('php://output', 'wb');
if ($output === false) {
    exit;
}

fwrite($output, "\xEF\xBB\xBF");
$currentSummary = bank_summary();
$currentFund = $currentSummary['fund_breakdown'];
$currentSettings = $currentSummary['settings'];
fputcsv($output, ['EVENTO', (string) $currentSettings['event_name']]);
fputcsv($output, ['OBJETIVO', (string) $currentSettings['objective_title']]);
fputcsv($output, ['MÉTRICA', (string) $currentSettings['score_metric_label']]);
fputcsv($output, ['PORCENTAJE REPARTIDO (%)', basis_points_to_text((int) $currentSettings['prize_pool_basis_points'])]);
fputcsv($output, ['ESTADO DE LA LIQUIDACIÓN', settlement_status_label((string) $currentSettings['settlement_status'])]);
fputcsv($output, []);
fputcsv($output, ['CONTABILIDAD POR RECURSO']);
fputcsv($output, ['Recurso', 'Reserva de apertura', 'Aportado', 'Elegible', 'Pendiente sin clasificar', 'Premio del evento', 'Retenido como reserva', 'Reserva utilizada', 'Fondo bruto', 'Entregado bruto', 'Impuesto', 'Neto recibido', 'Reserva al cierre']);
foreach (resource_keys() as $resource) {
    fputcsv($output, [
        resource_title($resource),
        (int) $currentFund['reserve_opening'][$resource],
        (int) $currentFund['contributed_total'][$resource],
        (int) $currentFund['eligible_total'][$resource],
        (int) $currentFund['pending_unqualified'][$resource],
        (int) $currentFund['prize_from_event'][$resource],
        (int) $currentFund['retained_from_eligible'][$resource],
        (int) $currentFund['reserve_draw'][$resource],
        (int) $currentFund['prize_fund_gross'][$resource],
        (int) $currentSummary['paid'][$resource],
        (int) $currentSummary['outgoing_tax'][$resource],
        (int) $currentSummary['winner_receives'][$resource],
        (int) $currentFund['reserve_closing'][$resource],
    ]);
}
fputcsv($output, []);
fputcsv($output, ['LIBRO MAYOR DE RESERVAS']);
fputcsv($output, ['Fecha ISO', 'Evento', 'Recurso', 'Movimiento', 'Cantidad con signo', 'Autor', 'Motivo']);
foreach ((array) database_read()['reserve_movements'] as $movement) {
    fputcsv($output, [
        (string) $movement['created_at'], (string) $movement['event_id'], resource_title((string) $movement['resource_type']),
        reserve_movement_label((string) $movement['movement_type']), (int) $movement['amount_signed'],
        (string) $movement['created_by'], (string) $movement['note'],
    ]);
}
fputcsv($output, []);
fputcsv($output, ['APORTES']);
fputcsv($output, ['ID', 'Jugador', 'Comida recibida', 'Madera recibida', 'Piedra recibida', 'Oro recibido', 'Fecha ISO', 'Fecha Florida', 'Nota']);
foreach (all_contributions() as $record) {
    fputcsv($output, [
        (string) $record['id'],
        (string) $record['player_name'],
        (int) $record['food'],
        (int) $record['wood'],
        (int) $record['stone'],
        (int) $record['gold'],
        (string) $record['created_at'],
        format_datetime((string) $record['created_at']),
        (string) ($record['note'] ?? ''),
    ]);
}
fclose($output);
