<?php
declare(strict_types=1);

function app_config(?string $key = null)
{
    static $config = null;
    if ($config === null) {
        $config = require dirname(__DIR__) . '/config.php';
    }
    if ($key === null) {
        return $config;
    }
    if (!array_key_exists($key, $config)) {
        throw new RuntimeException('Configuración desconocida: ' . $key);
    }
    return $config[$key];
}

/**
 * Lee una opción de configuración sin fallar cuando la instalación todavía
 * usa un config.php anterior a esta versión. Cada opción nueva tiene aquí su
 * valor de reserva, de modo que actualizar el código nunca obliga a sustituir
 * el archivo de configuración del hosting.
 */
function app_config_value(string $key, $default)
{
    $config = app_config();
    return array_key_exists($key, $config) ? $config[$key] : $default;
}

require_once __DIR__ . '/mysql.php';
require_once __DIR__ . '/announcements.php';
require_once __DIR__ . '/media.php';

function app_is_https(): bool
{
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        return strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
    }
    return isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off';
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; img-src 'self' data: blob:; style-src 'self'; script-src 'self'; connect-src 'self'; font-src 'self'; media-src 'self' blob:; worker-src 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
    header('Cache-Control: no-store, private');
    if (app_is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_name('me58_resource_bank');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => app_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function app_boot(): void
{
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    date_default_timezone_set('UTC');
    set_exception_handler('handle_uncaught_exception');
    send_security_headers();
    start_secure_session();
    ensure_storage_directory();
}

function handle_uncaught_exception(Throwable $exception): void
{
    error_log('[Banco ME58] ' . get_class($exception) . ': ' . $exception->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        send_security_headers();
        header('Content-Type: text/html; charset=UTF-8');
    }
    render_error_page(
        'Base de datos temporalmente no disponible',
        'No se borró ningún registro. Comprueba la conexión MySQL o inténtalo nuevamente en unos minutos.'
    );
}

function ensure_storage_directory(): void
{
    $directory = dirname((string) app_config('storage_file'));
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('No se pudo crear la carpeta de datos.');
    }
    if (!is_writable($directory)) {
        throw new RuntimeException('La carpeta storage necesita permiso de escritura.');
    }
}

function resource_keys(): array
{
    return ['food', 'wood', 'stone', 'gold'];
}

function resource_label(string $key): string
{
    $labels = ['food' => 'comida', 'wood' => 'madera', 'stone' => 'piedra', 'gold' => 'oro'];
    return $labels[$key] ?? $key;
}

function resource_title(string $key): string
{
    return ucfirst(resource_label($key));
}

function reward_rank_keys(): array
{
    return ['first', 'second', 'third'];
}

function reward_rank_label(string $rank): string
{
    $labels = ['first' => 'TOP 1', 'second' => 'TOP 2', 'third' => 'TOP 3'];
    return $labels[$rank] ?? strtoupper($rank);
}

function reward_rank_number(string $rank): int
{
    $numbers = ['first' => 1, 'second' => 2, 'third' => 3];
    return $numbers[$rank] ?? 0;
}

function winners_normalize($winners, string $defaultMetric = ''): array
{
    $winners = is_array($winners) ? $winners : [];
    if ($defaultMetric === '') {
        $defaultMetric = 'Fuertes destruidos';
    }
    $byRank = [];
    foreach ($winners as $key => $winner) {
        if (!is_array($winner)) {
            continue;
        }
        $rank = (string) ($winner['rank'] ?? $key);
        if (ctype_digit($rank)) {
            $rank = ['1' => 'first', '2' => 'second', '3' => 'third'][$rank] ?? '';
        }
        if (!in_array($rank, reward_rank_keys(), true)) {
            continue;
        }
        $name = trim((string) ($winner['player_name'] ?? $winner['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $byRank[$rank] = [
            'rank' => $rank,
            'position' => reward_rank_number($rank),
            'player_name' => $name,
            'score' => max(0, (int) ($winner['score'] ?? 0)),
            'metric' => trim((string) ($winner['metric'] ?? '')) !== ''
                ? trim((string) $winner['metric'])
                : $defaultMetric,
            'rank_rule' => trim((string) ($winner['rank_rule'] ?? '')),
            'note' => trim((string) ($winner['note'] ?? '')),
        ];
    }
    $normalized = [];
    foreach (reward_rank_keys() as $rank) {
        if (isset($byRank[$rank])) {
            $normalized[] = $byRank[$rank];
        }
    }
    return $normalized;
}

function winners_from_legacy_summary(string $summary, string $defaultMetric = 'Fuertes destruidos'): array
{
    $summary = trim($summary);
    if ($summary === '') {
        return [];
    }
    $matches = [];
    preg_match_all('/([123])\s*\.\s*[º°]?\s*(.+?)\s*\(([0-9][0-9.,\s]*)\)/u', $summary, $matches, PREG_SET_ORDER);
    $winners = [];
    foreach ($matches as $match) {
        $rank = ['1' => 'first', '2' => 'second', '3' => 'third'][(string) $match[1]] ?? '';
        if ($rank === '') {
            continue;
        }
        $score = (int) preg_replace('/\D+/', '', (string) $match[3]);
        $winners[] = [
            'rank' => $rank,
            'player_name' => trim((string) $match[2]),
            'score' => $score,
            'metric' => $defaultMetric,
            'note' => '',
        ];
    }
    return winners_normalize($winners, $defaultMetric);
}

/**
 * Comprueba que el orden registrado coincida con el método de clasificación.
 * El modo manual no impone ningún orden: existe precisamente para los eventos
 * cuya regla la página no puede calcular.
 */
function winners_ranking_warning(array $winners, string $method, string $metricLabel): ?string
{
    if ($method === 'manual' || count($winners) < 2) {
        return null;
    }
    $scores = [];
    foreach ($winners as $winner) {
        $scores[(int) $winner['position']] = (int) $winner['score'];
    }
    ksort($scores);
    $ordered = array_values($scores);
    for ($index = 1; $index < count($ordered); $index++) {
        $previous = (int) $ordered[$index - 1];
        $current = (int) $ordered[$index];
        if ($method === 'highest' && $current > $previous) {
            return 'El orden no coincide con el método elegido: el puesto ' . ($index + 1)
                . ' tiene más ' . $metricLabel . ' (' . format_integer($current) . ') que el puesto ' . $index
                . ' (' . format_integer($previous) . ').';
        }
        if ($method === 'lowest' && $current < $previous) {
            return 'El orden no coincide con el método elegido: el puesto ' . ($index + 1)
                . ' tiene menos ' . $metricLabel . ' (' . format_integer($current) . ') que el puesto ' . $index
                . ' (' . format_integer($previous) . ').';
        }
    }
    return null;
}

function winners_summary_text(array $winners): string
{
    $parts = [];
    foreach (winners_normalize($winners) as $winner) {
        $position = (int) $winner['position'];
        $part = $position . '.º ' . (string) $winner['player_name'];
        if ((int) $winner['score'] > 0) {
            $part .= ' (' . (int) $winner['score'] . ')';
        }
        $parts[] = $part;
    }
    return implode(' · ', $parts);
}

function reward_weight_percentages(array $weights): array
{
    $normalized = [];
    $total = 0.0;
    foreach (reward_rank_keys() as $rank) {
        $normalized[$rank] = max(0.0, (float) ($weights[$rank] ?? 0));
        $total += $normalized[$rank];
    }
    if ($total <= 0) {
        return array_fill_keys(reward_rank_keys(), 0.0);
    }
    foreach ($normalized as $rank => $weight) {
        $normalized[$rank] = $weight / $total;
    }
    return $normalized;
}

/**
 * Valores de reserva de la configuración dinámica del evento. Reproducen
 * exactamente el comportamiento anterior a esta versión: objetivo de fuertes
 * bárbaros, métrica "Fuertes destruidos", 100 % del fondo repartido y ninguna
 * reserva utilizada.
 */
function event_settings_fallback(): array
{
    return [
        'objective_title' => 'Destrucción de fuertes bárbaros',
        'objective_description' => '',
        'score_metric_label' => 'Fuertes destruidos',
        'ranking_method' => 'highest',
        'rank_rule_first' => '',
        'rank_rule_second' => '',
        'rank_rule_third' => '',
        'starts_at' => null,
        'prize_pool_basis_points' => 10000,
        'reserve_draw' => ['food' => 0, 'wood' => 0, 'stone' => 0, 'gold' => 0],
        'settlement_status' => 'open',
        'frozen_at' => null,
        'current_event_id' => '',
    ];
}

function settings_default(): array
{
    $base = (array) app_config('default_settings');
    $base = array_merge(event_settings_fallback(), $base);
    $base['thresholds'] = (array) app_config('thresholds');
    $base['updated_at'] = null;
    return $base;
}

function ranking_method_keys(): array
{
    return ['highest', 'lowest', 'manual'];
}

function ranking_method_label(string $method): string
{
    $labels = [
        'highest' => 'Gana la puntuación más alta',
        'lowest' => 'Gana la puntuación más baja',
        'manual' => 'El administrador decide el orden',
    ];
    return $labels[$method] ?? $labels['highest'];
}

function settlement_status_keys(): array
{
    return ['preparation', 'open', 'frozen', 'closed'];
}

function settlement_status_label(string $status): string
{
    $labels = [
        'preparation' => 'En preparación',
        'open' => 'Liquidación abierta',
        'frozen' => 'Liquidación congelada',
        'closed' => 'Evento cerrado',
    ];
    return $labels[$status] ?? $labels['open'];
}

/**
 * Convierte un porcentaje escrito por el administrador a puntos básicos
 * enteros. 100 % = 10000, 72,50 % = 7250. Devuelve null si no es válido.
 */
function parse_basis_points(string $value): ?int
{
    $value = str_replace(',', '.', trim($value));
    if ($value === '') {
        return null;
    }
    if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $value)) {
        return null;
    }
    $points = (int) round(((float) $value) * 100);
    if ($points < 0 || $points > 10000) {
        return null;
    }
    return $points;
}

function basis_points_to_text(int $points): string
{
    $points = max(0, min(10000, $points));
    $text = number_format($points / 100, 2, '.', '');
    return rtrim(rtrim($text, '0'), '.') === '' ? '0' : rtrim(rtrim($text, '0'), '.');
}

/**
 * Aplica un porcentaje en puntos básicos usando aritmética entera. El residuo
 * de la división siempre se queda fuera del premio (va a la reserva), nunca
 * se redondea hacia arriba.
 */
function apply_basis_points(int $amount, int $points): int
{
    $amount = max(0, $amount);
    $points = max(0, min(10000, $points));
    if ($points === 10000) {
        return $amount;
    }
    if ($points === 0) {
        return 0;
    }
    return intdiv($amount * $points, 10000);
}

function text_limit(string $value, int $maximum): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_strlen($value, 'UTF-8') > $maximum ? mb_substr($value, 0, $maximum, 'UTF-8') : $value;
    }
    return strlen($value) > $maximum ? substr($value, 0, $maximum) : $value;
}

function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function settings_normalize($settings): array
{
    $default = settings_default();
    $settings = is_array($settings) ? array_merge($default, $settings) : $default;
    $settings['event_name'] = trim((string) ($settings['event_name'] ?? $default['event_name']));
    if ($settings['event_name'] === '') {
        $settings['event_name'] = $default['event_name'];
    }
    $settings['event_status'] = in_array((string) ($settings['event_status'] ?? ''), ['active', 'closed'], true)
        ? (string) $settings['event_status'] : 'active';

    // --- Identidad, objetivo y reglas del evento ---------------------------
    $eventId = trim((string) ($settings['current_event_id'] ?? ''));
    $settings['current_event_id'] = preg_match('/^evt_[a-f0-9]{8,40}$/', $eventId) ? $eventId : '';
    $settings['objective_title'] = text_limit((string) ($settings['objective_title'] ?? $default['objective_title']), 200);
    if ($settings['objective_title'] === '') {
        $settings['objective_title'] = (string) $default['objective_title'];
    }
    $settings['objective_description'] = text_limit((string) ($settings['objective_description'] ?? ''), 2000);
    $settings['score_metric_label'] = text_limit((string) ($settings['score_metric_label'] ?? $default['score_metric_label']), 120);
    if ($settings['score_metric_label'] === '') {
        $settings['score_metric_label'] = (string) $default['score_metric_label'];
    }
    $settings['ranking_method'] = in_array((string) ($settings['ranking_method'] ?? ''), ranking_method_keys(), true)
        ? (string) $settings['ranking_method'] : 'highest';
    foreach (reward_rank_keys() as $rank) {
        $settings['rank_rule_' . $rank] = text_limit((string) ($settings['rank_rule_' . $rank] ?? ''), 500);
    }
    $settings['starts_at'] = !empty($settings['starts_at']) ? (string) $settings['starts_at'] : null;

    // --- Porcentaje del fondo y reserva utilizada --------------------------
    $points = (int) ($settings['prize_pool_basis_points'] ?? 10000);
    $settings['prize_pool_basis_points'] = max(0, min(10000, $points));
    $savedDraw = isset($settings['reserve_draw']) && is_array($settings['reserve_draw']) ? $settings['reserve_draw'] : [];
    $settings['reserve_draw'] = [];
    foreach (resource_keys() as $resource) {
        $settings['reserve_draw'][$resource] = max(0, (int) ($savedDraw[$resource] ?? 0));
    }

    // --- Estado de la liquidación ------------------------------------------
    $settings['settlement_status'] = in_array((string) ($settings['settlement_status'] ?? ''), settlement_status_keys(), true)
        ? (string) $settings['settlement_status'] : 'open';
    if ($settings['event_status'] === 'closed' && $settings['settlement_status'] === 'open') {
        $settings['settlement_status'] = 'frozen';
    }
    $settings['frozen_at'] = !empty($settings['frozen_at']) ? (string) $settings['frozen_at'] : null;
    if ($settings['settlement_status'] !== 'frozen' && $settings['settlement_status'] !== 'closed') {
        $settings['frozen_at'] = null;
    }
    $settings['custodian_name'] = trim((string) ($settings['custodian_name'] ?? 'FreeCuba'));
    $settings['tax_rate'] = max(0.0, min(50.0, (float) ($settings['tax_rate'] ?? 8)));
    // Los aportes siempre se clasifican por lo que realmente llegó al banco.
    // Se conserva la clave para migrar instalaciones anteriores sin romper datos.
    $settings['qualification_basis'] = 'net_received';
    $settings['deadline'] = !empty($settings['deadline']) ? (string) $settings['deadline'] : null;
    $settings['prize'] = trim((string) ($settings['prize'] ?? ''));
    $settings['winner_name'] = trim((string) ($settings['winner_name'] ?? ''));
    $settings['updated_at'] = !empty($settings['updated_at']) ? (string) $settings['updated_at'] : null;
    $defaultWeights = isset($default['reward_weights']) && is_array($default['reward_weights'])
        ? $default['reward_weights'] : ['first' => 90, 'second' => 72, 'third' => 61];
    $savedWeights = isset($settings['reward_weights']) && is_array($settings['reward_weights'])
        ? $settings['reward_weights'] : [];
    $settings['reward_weights'] = [];
    foreach (reward_rank_keys() as $rank) {
        $fallback = max(0.01, (float) ($defaultWeights[$rank] ?? 1));
        $weight = (float) ($savedWeights[$rank] ?? $fallback);
        $settings['reward_weights'][$rank] = $weight > 0 ? min(1000000.0, $weight) : $fallback;
    }
    $settings['transport_capacity'] = max(1, (int) ($settings['transport_capacity'] ?? 10000000));
    $thresholds = isset($settings['thresholds']) && is_array($settings['thresholds']) ? $settings['thresholds'] : [];
    foreach (resource_keys() as $resource) {
        $fallback = (int) $default['thresholds'][$resource];
        $settings['thresholds'][$resource] = max(1, (int) ($thresholds[$resource] ?? $fallback));
    }
    return $settings;
}

function database_default(): array
{
    return [
        'version' => 5,
        'admin' => null,
        'settings' => settings_default(),
        'winners' => [],
        'players' => [],
        'contributions' => [],
        'disbursements' => [],
        'audit_log' => [],
        'events_archive' => [],
        'login_attempts' => [],
        'reserve_movements' => [],
        'settlements' => [],
        'pending_contributions' => [],
    ];
}

function reserve_movement_types(): array
{
    return [
        'opening_migration',
        'event_retained',
        'event_release',
        'manual_adjustment_in',
        'manual_adjustment_out',
        'correction',
    ];
}

function reserve_movement_label(string $type): string
{
    $labels = [
        'opening_migration' => 'Saldo inicial migrado',
        'event_retained' => 'Retención del evento',
        'event_release' => 'Reserva entregada al premio',
        'manual_adjustment_in' => 'Ajuste manual de entrada',
        'manual_adjustment_out' => 'Ajuste manual de salida',
        'correction' => 'Corrección contable',
    ];
    return $labels[$type] ?? $type;
}

/**
 * Los movimientos de reserva son un libro mayor de solo anexado: se validan y
 * se conservan tal cual, nunca se editan ni se borran. Una corrección siempre
 * es un movimiento compensatorio nuevo.
 */
function reserve_movements_normalize($movements): array
{
    $movements = is_array($movements) ? $movements : [];
    $normalized = [];
    $seen = [];
    foreach (array_values($movements) as $index => $movement) {
        if (!is_array($movement)) {
            continue;
        }
        $resource = (string) ($movement['resource_type'] ?? '');
        $type = (string) ($movement['movement_type'] ?? '');
        if (!in_array($resource, resource_keys(), true) || !in_array($type, reserve_movement_types(), true)) {
            continue;
        }
        $createdAt = (string) ($movement['created_at'] ?? gmdate('c'));
        $requestedId = (string) ($movement['id'] ?? '');
        $id = preg_match('/^rsv_[a-f0-9]{12,40}$/', $requestedId)
            ? $requestedId
            : stable_id('rsv', $resource . '|' . $type . '|' . $createdAt . '|' . (string) $index);
        if (isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $dedupe = trim((string) ($movement['dedupe_key'] ?? ''));
        $normalized[] = [
            'id' => $id,
            'event_id' => trim((string) ($movement['event_id'] ?? '')),
            'resource_type' => $resource,
            'movement_type' => $type,
            'amount_signed' => (int) ($movement['amount_signed'] ?? 0),
            'note' => text_limit((string) ($movement['note'] ?? ''), 500),
            'created_by' => text_limit((string) ($movement['created_by'] ?? 'admin'), 80),
            'created_at' => $createdAt,
            'audit_reference' => text_limit((string) ($movement['audit_reference'] ?? ''), 64),
            'dedupe_key' => $dedupe === '' ? null : text_limit($dedupe, 191),
        ];
    }
    usort($normalized, static function (array $left, array $right): int {
        $comparison = strcmp((string) $left['created_at'], (string) $right['created_at']);
        return $comparison !== 0 ? $comparison : strcmp((string) $left['id'], (string) $right['id']);
    });
    return $normalized;
}

/**
 * Aportes tardíos que el administrador decidió asignar al evento siguiente.
 * No tocan el premio ya anunciado; entran automáticamente al abrirse el
 * próximo evento.
 */
function pending_contributions_normalize($rows): array
{
    $rows = is_array($rows) ? $rows : [];
    $normalized = [];
    foreach (array_values($rows) as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string) ($row['player_name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $createdAt = (string) ($row['created_at'] ?? gmdate('c'));
        $requestedId = (string) ($row['id'] ?? '');
        $record = [
            'id' => preg_match('/^pen_[a-f0-9]{12,40}$/', $requestedId)
                ? $requestedId : stable_id('pen', $name . '|' . $createdAt . '|' . (string) $index),
            'player_name' => $name,
            'note' => text_limit((string) ($row['note'] ?? ''), 180),
            'created_at' => $createdAt,
        ];
        foreach (resource_keys() as $resource) {
            $record[$resource] = max(0, (int) ($row[$resource] ?? 0));
        }
        $normalized[] = $record;
    }
    return $normalized;
}

function settlements_normalize($settlements): array
{
    $settlements = is_array($settlements) ? $settlements : [];
    $normalized = [];
    foreach ($settlements as $settlement) {
        if (!is_array($settlement)) {
            continue;
        }
        $eventId = trim((string) ($settlement['event_id'] ?? ''));
        if ($eventId === '') {
            continue;
        }
        $snapshot = isset($settlement['snapshot']) && is_array($settlement['snapshot']) ? $settlement['snapshot'] : [];
        $normalized[$eventId] = [
            'event_id' => $eventId,
            'status' => in_array((string) ($settlement['status'] ?? ''), settlement_status_keys(), true)
                ? (string) $settlement['status'] : 'frozen',
            'frozen_at' => !empty($settlement['frozen_at']) ? (string) $settlement['frozen_at'] : null,
            'unfreeze_count' => max(0, (int) ($settlement['unfreeze_count'] ?? 0)),
            'snapshot' => $snapshot,
            'updated_at' => !empty($settlement['updated_at']) ? (string) $settlement['updated_at'] : gmdate('c'),
        ];
    }
    return array_values($normalized);
}

function new_id(string $prefix): string
{
    return $prefix . '_' . bin2hex(random_bytes(10));
}

function stable_id(string $prefix, string $seed): string
{
    return $prefix . '_' . substr(hash('sha256', $seed), 0, 20);
}

function normalize_player_name(string $name): string
{
    $name = trim((string) preg_replace('/\s+/u', ' ', $name));
    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($name, Normalizer::FORM_KC);
        if (is_string($normalized)) {
            $name = $normalized;
        }
    }
    return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
}

function database_normalize(array $data): array
{
    $default = database_default();
    $data = array_merge($default, $data);
    $data['version'] = 5;
    $data['settings'] = settings_normalize($data['settings'] ?? []);
    foreach (['winners', 'players', 'contributions', 'disbursements', 'audit_log', 'events_archive', 'login_attempts', 'reserve_movements', 'settlements', 'pending_contributions'] as $key) {
        if (!is_array($data[$key])) {
            $data[$key] = [];
        }
    }
    if ($data['admin'] !== null && !is_array($data['admin'])) {
        $data['admin'] = null;
    }

    $data['winners'] = winners_normalize($data['winners'], (string) $data['settings']['score_metric_label']);
    $data['reserve_movements'] = reserve_movements_normalize($data['reserve_movements']);
    $data['settlements'] = settlements_normalize($data['settlements']);
    $data['pending_contributions'] = pending_contributions_normalize($data['pending_contributions']);

    $players = [];
    $playerKeys = [];
    $playerAliases = [];
    foreach ($data['players'] as $savedPlayer) {
        if (!is_array($savedPlayer)) {
            continue;
        }
        $displayName = trim((string) ($savedPlayer['display_name'] ?? $savedPlayer['name'] ?? ''));
        if ($displayName === '') {
            continue;
        }
        $matchKey = normalize_player_name($displayName);
        $requestedId = (string) ($savedPlayer['id'] ?? '');
        $id = preg_match('/^ply_[a-f0-9]{12,40}$/', $requestedId) ? $requestedId : stable_id('ply', $matchKey);
        if (isset($playerKeys[$matchKey])) {
            $playerAliases[$id] = $playerKeys[$matchKey];
            continue;
        }
        $players[$id] = [
            'id' => $id,
            'display_name' => $displayName,
            'match_key' => $matchKey,
            'created_at' => (string) ($savedPlayer['created_at'] ?? gmdate('c')),
            'updated_at' => !empty($savedPlayer['updated_at']) ? (string) $savedPlayer['updated_at'] : null,
        ];
        $playerKeys[$matchKey] = $id;
    }

    $contributions = [];
    foreach (array_values($data['contributions']) as $index => $savedContribution) {
        if (!is_array($savedContribution)) {
            continue;
        }
        $snapshot = trim((string) ($savedContribution['player_name_snapshot'] ?? $savedContribution['player_name'] ?? ''));
        $playerId = (string) ($savedContribution['player_id'] ?? '');
        if (isset($playerAliases[$playerId])) {
            $playerId = $playerAliases[$playerId];
        }
        if (!isset($players[$playerId])) {
            if ($snapshot === '') {
                continue;
            }
            $key = normalize_player_name($snapshot);
            if (isset($playerKeys[$key])) {
                $playerId = $playerKeys[$key];
            } else {
                $playerId = stable_id('ply', $key);
                while (isset($players[$playerId])) {
                    $playerId = stable_id('ply', $key . ':' . (string) $index);
                }
                $players[$playerId] = [
                    'id' => $playerId,
                    'display_name' => $snapshot,
                    'match_key' => $key,
                    'created_at' => (string) ($savedContribution['created_at'] ?? gmdate('c')),
                    'updated_at' => null,
                ];
                $playerKeys[$key] = $playerId;
            }
        }
        if ($snapshot === '') {
            $snapshot = (string) $players[$playerId]['display_name'];
        }
        $requestedId = (string) ($savedContribution['id'] ?? '');
        $createdAt = (string) ($savedContribution['created_at'] ?? gmdate('c'));
        $id = preg_match('/^con_[a-f0-9]{12,40}$/', $requestedId)
            ? $requestedId : stable_id('con', $snapshot . '|' . $createdAt . '|' . (string) $index);
        $row = [
            'id' => $id,
            'player_id' => $playerId,
            'player_name_snapshot' => $snapshot,
            'note' => trim((string) ($savedContribution['note'] ?? '')),
            'created_at' => $createdAt,
            'updated_at' => !empty($savedContribution['updated_at']) ? (string) $savedContribution['updated_at'] : null,
        ];
        foreach (resource_keys() as $resource) {
            $row[$resource] = max(0, (int) ($savedContribution[$resource] ?? 0));
        }
        $contributions[] = $row;
    }

    $disbursements = [];
    foreach (array_values($data['disbursements']) as $index => $savedDisbursement) {
        if (!is_array($savedDisbursement)) {
            continue;
        }
        $recipient = trim((string) ($savedDisbursement['recipient_name'] ?? ''));
        if ($recipient === '') {
            continue;
        }
        $createdAt = (string) ($savedDisbursement['created_at'] ?? gmdate('c'));
        $requestedId = (string) ($savedDisbursement['id'] ?? '');
        $row = [
            'id' => preg_match('/^out_[a-f0-9]{12,40}$/', $requestedId)
                ? $requestedId : stable_id('out', $recipient . '|' . $createdAt . '|' . (string) $index),
            'recipient_name' => $recipient,
            'note' => trim((string) ($savedDisbursement['note'] ?? '')),
            'evidence_note' => trim((string) ($savedDisbursement['evidence_note'] ?? '')),
            'transport_count' => max(0, (int) ($savedDisbursement['transport_count'] ?? 0)),
            'tax_rate' => max(0.0, min(50.0, (float) ($savedDisbursement['tax_rate'] ?? $data['settings']['tax_rate']))),
            'created_at' => $createdAt,
            'updated_at' => !empty($savedDisbursement['updated_at']) ? (string) $savedDisbursement['updated_at'] : null,
            'actual_received' => [],
        ];
        $savedActual = isset($savedDisbursement['actual_received']) && is_array($savedDisbursement['actual_received'])
            ? $savedDisbursement['actual_received'] : [];
        foreach (resource_keys() as $resource) {
            $row[$resource] = max(0, (int) ($savedDisbursement[$resource] ?? 0));
            $flatKey = 'actual_' . $resource;
            $actual = array_key_exists($resource, $savedActual)
                ? $savedActual[$resource]
                : (array_key_exists($flatKey, $savedDisbursement) ? $savedDisbursement[$flatKey] : null);
            $row['actual_received'][$resource] = $actual === null || $actual === ''
                ? null : max(0, (int) $actual);
        }
        $disbursements[] = $row;
    }

    $data['players'] = array_values($players);
    $data['contributions'] = $contributions;
    $data['disbursements'] = $disbursements;
    $data['audit_log'] = array_values(array_filter($data['audit_log'], 'is_array'));
    $data['events_archive'] = array_values(array_filter($data['events_archive'], 'is_array'));
    return $data;
}

function database_read_unlocked(): array
{
    $path = (string) app_config('storage_file');
    if (!is_file($path)) {
        return database_default();
    }
    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return database_default();
    }
    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('El archivo de datos no es válido. Restaura una copia antes de continuar.');
    }
    return database_normalize($decoded);
}

function database_lock_handle()
{
    $path = dirname((string) app_config('storage_file')) . '/.bank.lock';
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('No se pudo bloquear el archivo de datos.');
    }
    return $handle;
}

function database_cache_reset(): void
{
    $GLOBALS['rokbank_database_cache'] = [];
}

function database_read(bool $includeArchives = false): array
{
    $cacheKey = $includeArchives ? 'full' : 'current';
    if (isset($GLOBALS['rokbank_database_cache'][$cacheKey]) && is_array($GLOBALS['rokbank_database_cache'][$cacheKey])) {
        return $GLOBALS['rokbank_database_cache'][$cacheKey];
    }
    if (storage_uses_mysql()) {
        $data = mysql_read_state(mysql_connection(), $includeArchives);
        $GLOBALS['rokbank_database_cache'][$cacheKey] = $data;
        if ($includeArchives) {
            $current = $data;
            $current['events_archive'] = [];
            $GLOBALS['rokbank_database_cache']['current'] = $current;
        }
        return $data;
    }
    $handle = database_lock_handle();
    if (!flock($handle, LOCK_SH)) {
        fclose($handle);
        throw new RuntimeException('No se pudo leer el archivo de datos.');
    }
    try {
        $data = database_read_unlocked();
        $GLOBALS['rokbank_database_cache']['full'] = $data;
        $GLOBALS['rokbank_database_cache']['current'] = $data;
        return $data;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function database_write_unlocked(array $data): void
{
    $path = (string) app_config('storage_file');
    $json = json_encode(database_normalize($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('No se pudieron preparar los datos para guardar.');
    }
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
    if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo escribir el archivo de datos.');
    }
    @chmod($temporary, 0600);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('No se pudo finalizar el guardado de datos.');
    }
    @chmod($path, 0600);
}

function backup_create_unlocked(array $data, string $reason = 'change'): ?string
{
    $directory = dirname((string) app_config('storage_file')) . '/backups';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        return null;
    }
    $safeReason = trim((string) preg_replace('/[^a-z0-9_-]+/i', '-', $reason), '-');
    $moment = microtime(true);
    $microseconds = (int) (($moment - floor($moment)) * 1000000);
    $filename = $directory . '/backup-' . gmdate('Ymd-His', (int) $moment) . '-'
        . str_pad((string) $microseconds, 6, '0', STR_PAD_LEFT) . '-'
        . substr(bin2hex(random_bytes(4)), 0, 8) . '-' . ($safeReason ?: 'change') . '.json';
    $json = json_encode(database_normalize($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($filename, $json . PHP_EOL, LOCK_EX) === false) {
        return null;
    }
    @chmod($filename, 0600);
    $files = glob($directory . '/backup-*.json');
    if (is_array($files) && count($files) > (int) app_config('backup_limit')) {
        usort($files, function (string $left, string $right): int {
            return strcmp(basename($left), basename($right));
        });
        $removeCount = count($files) - (int) app_config('backup_limit');
        for ($index = 0; $index < $removeCount; $index++) {
            if (isset($files[$index]) && strpos($files[$index], $directory . '/backup-') === 0) {
                @unlink($files[$index]);
            }
        }
    }
    return $filename;
}

function database_mutate(callable $callback, bool $createBackup = false, string $reason = 'change', bool $includeArchives = false, array $archivePayloadIds = [])
{
    database_cache_reset();
    if (storage_uses_mysql()) {
        try {
            return mysql_mutate($callback, $createBackup, $reason, $includeArchives, $archivePayloadIds);
        } finally {
            database_cache_reset();
        }
    }
    $handle = database_lock_handle();
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        throw new RuntimeException('No se pudo bloquear el archivo de datos para escribir.');
    }
    try {
        $data = database_read_unlocked();
        if ($createBackup) {
            backup_create_unlocked($data, $reason);
        }
        $result = $callback($data);
        database_write_unlocked($data);
        return $result;
    } finally {
        database_cache_reset();
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function latest_backup_info(): ?array
{
    if (storage_uses_mysql()) {
        return mysql_latest_backup_info();
    }
    $directory = dirname((string) app_config('storage_file')) . '/backups';
    $files = glob($directory . '/backup-*.json');
    if (!is_array($files) || $files === []) {
        return null;
    }
    usort($files, function (string $left, string $right): int {
        return strcmp(basename($right), basename($left));
    });
    return ['path' => $files[0], 'created_at' => gmdate('c', (int) @filemtime($files[0]))];
}

function restore_latest_backup(): bool
{
    if (storage_uses_mysql()) {
        try {
            return mysql_restore_latest_backup();
        } finally {
            database_cache_reset();
        }
    }
    $handle = database_lock_handle();
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        throw new RuntimeException('No se pudo bloquear el archivo de datos.');
    }
    try {
        $current = database_read_unlocked();
        $info = latest_backup_info();
        if ($info === null || !is_file((string) $info['path'])) {
            return false;
        }
        $contents = file_get_contents((string) $info['path']);
        $decoded = $contents === false ? null : json_decode($contents, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('La última copia de seguridad no es válida.');
        }
        $restored = database_normalize($decoded);
        backup_create_unlocked($current, 'before-undo');
        $restored['admin'] = $current['admin'];
        $restored['login_attempts'] = $current['login_attempts'];
        audit_append($restored, 'undo', 'system', '', 'Se restauró la última copia automática.');
        database_write_unlocked($restored);
        return true;
    } finally {
        database_cache_reset();
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $location): void
{
    header('Location: ' . $location, true, 303);
    exit;
}

function is_post(): bool
{
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $provided = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    $stored = isset($_SESSION['csrf_token']) ? (string) $_SESSION['csrf_token'] : '';
    if ($stored === '' || !hash_equals($stored, $provided)) {
        http_response_code(419);
        render_error_page('La sesión del formulario venció', 'Actualiza la página e inténtalo nuevamente.');
        exit;
    }
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function pull_flash(): ?array
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function admin_record(): ?array
{
    $data = database_read();
    return is_array($data['admin']) ? $data['admin'] : null;
}

function is_installed(): bool
{
    $admin = admin_record();
    return $admin !== null && !empty($admin['username']) && !empty($admin['password_hash']);
}

function admin_logged_in(): bool
{
    if (empty($_SESSION['admin_authenticated']) || empty($_SESSION['admin_session_version'])) {
        return false;
    }
    $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
    if ($lastActivity > 0 && time() - $lastActivity > (int) app_config('session_timeout_seconds')) {
        unset($_SESSION['admin_authenticated'], $_SESSION['admin_session_version'], $_SESSION['last_activity']);
        return false;
    }
    $admin = admin_record();
    if ($admin === null || empty($admin['session_version'])) {
        return false;
    }
    $valid = hash_equals((string) $admin['session_version'], (string) $_SESSION['admin_session_version']);
    if ($valid) {
        $_SESSION['last_activity'] = time();
    }
    return $valid;
}

function require_admin(): void
{
    if (!is_installed()) {
        redirect('setup.php');
    }
    if (!admin_logged_in()) {
        set_flash('info', 'Inicia sesión para abrir el panel administrativo.');
        redirect('login.php');
    }
}

function create_admin_account(string $setupKey, string $username, string $password, string $confirmation): array
{
    $errors = [];
    $username = trim($username);
    $usernameLength = function_exists('mb_strlen') ? mb_strlen($username, 'UTF-8') : strlen($username);
    if (!hash_equals((string) app_config('setup_key'), trim($setupKey))) {
        $errors[] = 'El código de instalación no es correcto.';
    }
    if ($usernameLength < 3 || $usernameLength > 40) {
        $errors[] = 'El usuario debe tener entre 3 y 40 caracteres.';
    }
    if (strlen($password) < (int) app_config('minimum_password_length')) {
        $errors[] = 'La contraseña debe tener al menos ' . (int) app_config('minimum_password_length') . ' caracteres.';
    }
    if ($password !== $confirmation) {
        $errors[] = 'Las contraseñas no coinciden.';
    }
    if ($errors !== []) {
        return $errors;
    }
    database_mutate(function (array &$data) use ($username, $password): void {
        if (is_array($data['admin'])) {
            throw new RuntimeException('La cuenta administrativa ya existe.');
        }
        $data['admin'] = [
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'created_at' => gmdate('c'),
            'password_changed_at' => null,
            'session_version' => bin2hex(random_bytes(24)),
        ];
        audit_append($data, 'setup', 'admin', '', 'Se creó el acceso administrativo.');
    });
    return [];
}

function login_rate_key(): string
{
    return hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function login_lock_remaining(): int
{
    if (storage_uses_mysql()) {
        return mysql_login_lock_remaining(login_rate_key());
    }
    $data = database_read();
    $attempt = $data['login_attempts'][login_rate_key()] ?? null;
    return is_array($attempt) ? max(0, (int) ($attempt['locked_until'] ?? 0) - time()) : 0;
}

function record_login_failure(): void
{
    if (storage_uses_mysql()) {
        mysql_record_login_failure(login_rate_key());
        return;
    }
    database_mutate(function (array &$data): void {
        $key = login_rate_key();
        $now = time();
        $attempt = isset($data['login_attempts'][$key]) && is_array($data['login_attempts'][$key])
            ? $data['login_attempts'][$key] : ['count' => 0, 'window_started' => $now, 'locked_until' => 0];
        if ($now - (int) ($attempt['window_started'] ?? 0) > (int) app_config('login_window_seconds')) {
            $attempt = ['count' => 0, 'window_started' => $now, 'locked_until' => 0];
        }
        $attempt['count'] = (int) ($attempt['count'] ?? 0) + 1;
        if ($attempt['count'] >= (int) app_config('login_max_attempts')) {
            $attempt['locked_until'] = $now + (int) app_config('login_lock_seconds');
        }
        $data['login_attempts'][$key] = $attempt;
        foreach ($data['login_attempts'] as $attemptKey => $savedAttempt) {
            $lastRelevant = max((int) ($savedAttempt['window_started'] ?? 0), (int) ($savedAttempt['locked_until'] ?? 0));
            if ($now - $lastRelevant > 86400) {
                unset($data['login_attempts'][$attemptKey]);
            }
        }
    });
}

function clear_login_failures(): void
{
    if (storage_uses_mysql()) {
        mysql_clear_login_failure(login_rate_key());
        return;
    }
    database_mutate(function (array &$data): void {
        unset($data['login_attempts'][login_rate_key()]);
    });
}

function attempt_admin_login(string $username, string $password): array
{
    $remaining = login_lock_remaining();
    if ($remaining > 0) {
        return ['success' => false, 'message' => 'Demasiados intentos. Espera ' . (int) ceil($remaining / 60) . ' minuto(s).'];
    }
    $admin = admin_record();
    $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    $hash = $admin !== null && !empty($admin['password_hash']) ? (string) $admin['password_hash'] : $dummyHash;
    $validPassword = password_verify($password, $hash);
    $validUsername = $admin !== null && hash_equals((string) $admin['username'], trim($username));
    if (!$validPassword || !$validUsername) {
        record_login_failure();
        usleep(random_int(120000, 260000));
        return ['success' => false, 'message' => 'Usuario o contraseña incorrectos.'];
    }
    clear_login_failures();
    session_regenerate_id(true);
    $_SESSION['admin_authenticated'] = true;
    $_SESSION['admin_session_version'] = (string) $admin['session_version'];
    $_SESSION['last_activity'] = time();
    return ['success' => true, 'message' => ''];
}

function logout_admin(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
}

function change_admin_password(string $current, string $password, string $confirmation): array
{
    $errors = [];
    $admin = admin_record();
    if ($admin === null || !password_verify($current, (string) $admin['password_hash'])) {
        $errors[] = 'La contraseña actual no es correcta.';
    }
    if (strlen($password) < (int) app_config('minimum_password_length')) {
        $errors[] = 'La nueva contraseña debe tener al menos ' . (int) app_config('minimum_password_length') . ' caracteres.';
    }
    if ($password !== $confirmation) {
        $errors[] = 'Las contraseñas nuevas no coinciden.';
    }
    if ($current === $password) {
        $errors[] = 'La nueva contraseña debe ser diferente.';
    }
    if ($errors !== []) {
        return $errors;
    }
    $sessionVersion = database_mutate(function (array &$data) use ($password): string {
        if (!is_array($data['admin'])) {
            throw new RuntimeException('No existe una cuenta administrativa.');
        }
        $data['admin']['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        $data['admin']['password_changed_at'] = gmdate('c');
        $data['admin']['session_version'] = bin2hex(random_bytes(24));
        audit_append($data, 'password', 'admin', '', 'Se cambió la contraseña administrativa.');
        return (string) $data['admin']['session_version'];
    }, true, 'password');
    session_regenerate_id(true);
    $_SESSION['admin_authenticated'] = true;
    $_SESSION['admin_session_version'] = $sessionVersion;
    $_SESSION['last_activity'] = time();
    return [];
}

function validate_player_name(string $name): ?string
{
    $name = trim($name);
    $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
    if ($length < 1 || $length > 80) {
        return 'El nombre del jugador debe tener entre 1 y 80 caracteres.';
    }
    return null;
}

function parse_resource_amount(string $value): ?int
{
    $value = strtoupper(trim($value));
    $value = (string) preg_replace('/\s+/u', '', $value);
    if ($value === '') {
        return 0;
    }
    $suffix = '';
    $last = substr($value, -1);
    if (in_array($last, ['K', 'M', 'B'], true)) {
        $suffix = $last;
        $value = substr($value, 0, -1);
    }
    if ($suffix !== '') {
        $normalized = str_replace(',', '.', $value);
        if (!preg_match('/^\d+(?:\.\d{1,3})?$/', $normalized)) {
            return null;
        }
        $multipliers = ['K' => 1000, 'M' => 1000000, 'B' => 1000000000];
        $amount = (float) $normalized * $multipliers[$suffix];
        if (!is_finite($amount) || $amount < 0 || $amount > 9000000000000000) {
            return null;
        }
        return (int) round($amount);
    }
    if (preg_match('/^\d+$/', $value)) {
        if (strlen($value) > 16 || (float) $value > 9000000000000000) {
            return null;
        }
        return (int) $value;
    }
    if (preg_match('/^\d{1,3}(?:[.,]\d{3})+$/', $value)) {
        $normalized = str_replace([',', '.'], '', $value);
        return strlen($normalized) > 16 ? null : (int) $normalized;
    }
    return null;
}

function contribution_input(array $source): array
{
    $errors = [];
    $playerId = trim((string) ($source['player_id'] ?? ''));
    $playerName = trim((string) ($source['player_name'] ?? ''));
    if ($playerId !== '' && !preg_match('/^ply_[a-f0-9]{12,40}$/', $playerId)) {
        $errors[] = 'La selección del jugador no es válida.';
    }
    if ($playerId === '') {
        $nameError = validate_player_name($playerName);
        if ($nameError !== null) {
            $errors[] = $nameError;
        }
    }
    $values = ['player_id' => $playerId, 'player_name' => $playerName, 'note' => trim((string) ($source['note'] ?? ''))];
    $hasAmount = false;
    foreach (resource_keys() as $resource) {
        $amount = parse_resource_amount((string) ($source[$resource] ?? ''));
        if ($amount === null) {
            $errors[] = 'La cantidad de ' . resource_label($resource) . ' no es válida.';
            $amount = 0;
        }
        $values[$resource] = $amount;
        $hasAmount = $hasAmount || $amount > 0;
    }
    if (!$hasAmount) {
        $errors[] = 'El aporte debe incluir al menos una cantidad mayor que cero.';
    }
    $noteLength = function_exists('mb_strlen') ? mb_strlen($values['note'], 'UTF-8') : strlen($values['note']);
    if ($noteLength > 180) {
        $errors[] = 'La nota no puede superar 180 caracteres.';
    }
    return ['errors' => array_values(array_unique($errors)), 'values' => $values];
}

function player_index_by_id(array $players, string $id): ?int
{
    foreach ($players as $index => $player) {
        if (is_array($player) && hash_equals((string) ($player['id'] ?? ''), $id)) {
            return (int) $index;
        }
    }
    return null;
}

function player_index_by_key(array $players, string $matchKey): ?int
{
    foreach ($players as $index => $player) {
        if (is_array($player) && hash_equals((string) ($player['match_key'] ?? ''), $matchKey)) {
            return (int) $index;
        }
    }
    return null;
}

function ensure_player_in_data(array &$data, string $playerId, string $displayName): array
{
    if ($playerId !== '') {
        $index = player_index_by_id($data['players'], $playerId);
        if ($index === null) {
            throw new InvalidArgumentException('El jugador seleccionado ya no existe.');
        }
        return $data['players'][$index];
    }
    $displayName = trim($displayName);
    $nameError = validate_player_name($displayName);
    if ($nameError !== null) {
        throw new InvalidArgumentException($nameError);
    }
    $matchKey = normalize_player_name($displayName);
    $existing = player_index_by_key($data['players'], $matchKey);
    if ($existing !== null) {
        return $data['players'][$existing];
    }
    $player = [
        'id' => new_id('ply'),
        'display_name' => $displayName,
        'match_key' => $matchKey,
        'created_at' => gmdate('c'),
        'updated_at' => null,
    ];
    $data['players'][] = $player;
    return $player;
}

function audit_append(array &$data, string $action, string $entityType, string $entityId, string $description): void
{
    $data['audit_log'][] = [
        'id' => new_id('log'),
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'description' => $description,
        'created_at' => gmdate('c'),
    ];
    $limit = (int) app_config('audit_limit');
    if (count($data['audit_log']) > $limit) {
        $data['audit_log'] = array_slice($data['audit_log'], -$limit);
    }
}

function player_name_from_data(array $data, string $playerId): string
{
    $index = player_index_by_id($data['players'], $playerId);
    return $index === null ? 'Jugador desconocido' : (string) $data['players'][$index]['display_name'];
}

function contribution_record_from_values(array &$data, array $values): array
{
    $player = ensure_player_in_data($data, (string) ($values['player_id'] ?? ''), (string) ($values['player_name'] ?? ''));
    $record = [
        'id' => new_id('con'),
        'player_id' => (string) $player['id'],
        'player_name_snapshot' => (string) $player['display_name'],
        'note' => trim((string) ($values['note'] ?? '')),
        'created_at' => gmdate('c'),
        'updated_at' => null,
    ];
    foreach (resource_keys() as $resource) {
        $record[$resource] = max(0, (int) ($values[$resource] ?? 0));
    }
    return $record;
}

function add_contribution(array $values): string
{
    return database_mutate(function (array &$data) use ($values): string {
        $record = contribution_record_from_values($data, $values);
        $data['contributions'][] = $record;
        $name = player_name_from_data($data, (string) $record['player_id']);
        audit_append($data, 'add', 'contribution', (string) $record['id'], 'Aporte recibido de ' . $name . '.');
        return (string) $record['id'];
    }, true, 'add-contribution');
}

function add_contributions_batch(array $rows): int
{
    return database_mutate(function (array &$data) use ($rows): int {
        $count = 0;
        foreach ($rows as $values) {
            if (!is_array($values)) {
                continue;
            }
            $record = contribution_record_from_values($data, $values);
            $data['contributions'][] = $record;
            $name = player_name_from_data($data, (string) $record['player_id']);
            audit_append($data, 'add', 'contribution', (string) $record['id'], 'Aporte recibido de ' . $name . ' mediante lote.');
            $count++;
        }
        return $count;
    }, true, 'batch-contributions');
}

function late_contribution_policies(): array
{
    return ['next_event', 'reserve'];
}

/**
 * Registra un aporte que llegó después de congelar la liquidación. Nunca
 * cambia el premio ya anunciado: el administrador elige explícitamente si el
 * aporte se guarda para el evento siguiente o entra directo a la reserva.
 */
function add_late_contribution(array $values, string $policy): array
{
    if (!in_array($policy, late_contribution_policies(), true)) {
        return ['Elige qué hacer con el aporte tardío: guardarlo para el evento siguiente o ingresarlo a la reserva.'];
    }
    $name = trim((string) ($values['player_name'] ?? ''));
    if ($name === '' && trim((string) ($values['player_id'] ?? '')) !== '') {
        $name = player_name_from_data(database_read(), trim((string) $values['player_id']));
    }
    if (validate_player_name($name) !== null) {
        return ['Escribe el nombre exacto del jugador que envió el aporte tardío.'];
    }
    try {
        database_mutate(function (array &$data) use ($values, $policy, $name): void {
            if ($policy === 'next_event') {
                $record = [
                    'id' => new_id('pen'),
                    'player_name' => $name,
                    'note' => text_limit((string) ($values['note'] ?? ''), 180),
                    'created_at' => gmdate('c'),
                ];
                foreach (resource_keys() as $resource) {
                    $record[$resource] = max(0, (int) ($values[$resource] ?? 0));
                }
                $data['pending_contributions'][] = $record;
                audit_append($data, 'add', 'pending_contribution', (string) $record['id'],
                    'Aporte tardío de ' . $name . ' reservado para el evento siguiente.');
                return;
            }
            $auditId = new_id('log');
            $total = 0;
            foreach (resource_keys() as $resource) {
                $amount = max(0, (int) ($values[$resource] ?? 0));
                if ($amount === 0) {
                    continue;
                }
                $total += $amount;
                reserve_movement_append(
                    $data,
                    '',
                    $resource,
                    'manual_adjustment_in',
                    $amount,
                    'Aporte tardío de ' . $name . ' ingresado directamente a la reserva.',
                    null,
                    $auditId
                );
            }
            if ($total === 0) {
                throw new InvalidArgumentException('El aporte tardío debe incluir al menos una cantidad mayor que cero.');
            }
            $data['audit_log'][] = [
                'id' => $auditId,
                'action' => 'reserve_adjustment',
                'entity_type' => 'reserve',
                'entity_id' => '',
                'description' => 'Aporte tardío de ' . $name . ' ingresado a la reserva ('
                    . format_integer($total) . ' recursos).',
                'created_at' => gmdate('c'),
            ];
        }, true, 'late-contribution');
    } catch (InvalidArgumentException $exception) {
        return [$exception->getMessage()];
    }
    return [];
}

function delete_pending_contribution(string $id): bool
{
    return database_mutate(function (array &$data) use ($id): bool {
        foreach ((array) $data['pending_contributions'] as $index => $record) {
            if (!hash_equals((string) ($record['id'] ?? ''), $id)) {
                continue;
            }
            $name = (string) ($record['player_name'] ?? '');
            array_splice($data['pending_contributions'], $index, 1);
            audit_append($data, 'delete', 'pending_contribution', $id, 'Se descartó el aporte tardío de ' . $name . '.');
            return true;
        }
        return false;
    }, true, 'delete-pending-contribution');
}

function all_pending_contributions(): array
{
    return (array) database_read()['pending_contributions'];
}

function all_players(): array
{
    $players = database_read()['players'];
    usort($players, function (array $left, array $right): int {
        return strcasecmp((string) $left['display_name'], (string) $right['display_name']);
    });
    return $players;
}

function all_contributions(): array
{
    $data = database_read();
    $records = $data['contributions'];
    foreach ($records as &$record) {
        $record['player_name'] = player_name_from_data($data, (string) ($record['player_id'] ?? ''));
    }
    unset($record);
    usort($records, function (array $left, array $right): int {
        return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
    });
    return $records;
}

function find_contribution(string $id): ?array
{
    foreach (all_contributions() as $record) {
        if (hash_equals((string) $record['id'], $id)) {
            return $record;
        }
    }
    return null;
}

function update_contribution(string $id, array $values): bool
{
    return database_mutate(function (array &$data) use ($id, $values): bool {
        foreach ($data['contributions'] as $index => $record) {
            if (!hash_equals((string) ($record['id'] ?? ''), $id)) {
                continue;
            }
            $player = ensure_player_in_data($data, (string) ($values['player_id'] ?? ''), (string) ($values['player_name'] ?? ''));
            $data['contributions'][$index]['player_id'] = (string) $player['id'];
            $data['contributions'][$index]['player_name_snapshot'] = (string) $player['display_name'];
            $data['contributions'][$index]['note'] = trim((string) ($values['note'] ?? ''));
            $data['contributions'][$index]['updated_at'] = gmdate('c');
            foreach (resource_keys() as $resource) {
                $data['contributions'][$index][$resource] = max(0, (int) ($values[$resource] ?? 0));
            }
            $financialErrors = financial_integrity_errors($data);
            if ($financialErrors !== []) {
                throw new InvalidArgumentException(implode(' ', $financialErrors));
            }
            audit_append($data, 'update', 'contribution', $id, 'Se corrigió un aporte de ' . (string) $player['display_name'] . '.');
            return true;
        }
        return false;
    }, true, 'update-contribution');
}

function delete_contribution(string $id): bool
{
    return database_mutate(function (array &$data) use ($id): bool {
        foreach ($data['contributions'] as $index => $record) {
            if (!hash_equals((string) ($record['id'] ?? ''), $id)) {
                continue;
            }
            $name = player_name_from_data($data, (string) ($record['player_id'] ?? ''));
            array_splice($data['contributions'], $index, 1);
            $financialErrors = financial_integrity_errors($data);
            if ($financialErrors !== []) {
                throw new InvalidArgumentException(implode(' ', $financialErrors));
            }
            audit_append($data, 'delete', 'contribution', $id, 'Se eliminó un aporte de ' . $name . '.');
            return true;
        }
        return false;
    }, true, 'delete-contribution');
}

function rename_player(string $id, string $newName): array
{
    $error = validate_player_name($newName);
    if ($error !== null) {
        return [$error];
    }
    $newName = trim($newName);
    $newKey = normalize_player_name($newName);
    try {
        database_mutate(function (array &$data) use ($id, $newName, $newKey): void {
            $index = player_index_by_id($data['players'], $id);
            if ($index === null) {
                throw new InvalidArgumentException('El jugador ya no existe.');
            }
            $duplicate = player_index_by_key($data['players'], $newKey);
            if ($duplicate !== null && $duplicate !== $index) {
                throw new InvalidArgumentException('Ya existe otro jugador con ese mismo nombre normalizado.');
            }
            $oldName = (string) $data['players'][$index]['display_name'];
            $data['players'][$index]['display_name'] = $newName;
            $data['players'][$index]['match_key'] = $newKey;
            $data['players'][$index]['updated_at'] = gmdate('c');
            audit_append($data, 'rename', 'player', $id, 'Se corrigió el nombre ' . $oldName . ' a ' . $newName . '.');
        }, true, 'rename-player');
    } catch (InvalidArgumentException $exception) {
        return [$exception->getMessage()];
    }
    return [];
}

function parse_batch_text(string $text): array
{
    $rows = [];
    $errors = [];
    $lines = preg_split('/\R/u', trim($text));
    if (!is_array($lines) || $lines === ['']) {
        return ['rows' => [], 'errors' => ['Pega al menos una fila para preparar el lote.']];
    }
    foreach ($lines as $offset => $line) {
        $lineNumber = $offset + 1;
        $line = trim((string) $line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        $delimiter = strpos($line, "\t") !== false ? "\t" : (strpos($line, '|') !== false ? '|' : ';');
        $parts = array_map('trim', explode($delimiter, $line));
        if (count($parts) < 5 || count($parts) > 6) {
            $errors[] = 'Línea ' . $lineNumber . ': usa Jugador | Comida | Madera | Piedra | Oro.';
            continue;
        }
        if ($lineNumber === 1 && in_array(normalize_player_name((string) $parts[0]), ['jugador', 'player', 'nombre'], true)) {
            continue;
        }
        $input = contribution_input([
            'player_name' => $parts[0],
            'food' => $parts[1],
            'wood' => $parts[2],
            'stone' => $parts[3],
            'gold' => $parts[4],
            'note' => $parts[5] ?? '',
        ]);
        if ($input['errors'] !== []) {
            foreach ($input['errors'] as $inputError) {
                $errors[] = 'Línea ' . $lineNumber . ': ' . $inputError;
            }
            continue;
        }
        $input['values']['source_line'] = $lineNumber;
        $rows[] = $input['values'];
    }
    if ($rows === [] && $errors === []) {
        $errors[] = 'No se encontró ninguna fila de aportes.';
    }
    return ['rows' => $rows, 'errors' => $errors];
}

function build_batch_preview(array $rows): array
{
    $data = database_read();
    $known = [];
    foreach ($data['players'] as $player) {
        $known[(string) $player['match_key']] = $player;
    }
    $preview = [];
    foreach ($rows as $row) {
        $key = normalize_player_name((string) $row['player_name']);
        $matched = $known[$key] ?? null;
        $preview[] = ['values' => $row, 'match' => $matched, 'is_new' => $matched === null];
        if ($matched === null) {
            $known[$key] = ['id' => '', 'display_name' => (string) $row['player_name'], 'match_key' => $key];
        }
    }
    return $preview;
}

function disbursement_input(array $source): array
{
    $errors = [];
    $recipient = trim((string) ($source['recipient_name'] ?? ''));
    if (validate_player_name($recipient) !== null) {
        $errors[] = 'El destinatario debe tener entre 1 y 80 caracteres.';
    }
    $transportRaw = trim((string) ($source['transport_count'] ?? ''));
    $transportCount = $transportRaw === '' ? 0 : filter_var($transportRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($transportCount === false) {
        $errors[] = 'La cantidad de transportes debe ser un número entero igual o mayor que cero.';
        $transportCount = 0;
    }
    $values = [
        'recipient_name' => $recipient,
        'note' => trim((string) ($source['note'] ?? '')),
        'evidence_note' => trim((string) ($source['evidence_note'] ?? '')),
        'transport_count' => (int) $transportCount,
        'actual_received' => [],
    ];
    $hasAmount = false;
    foreach (resource_keys() as $resource) {
        $amount = parse_resource_amount((string) ($source[$resource] ?? ''));
        if ($amount === null) {
            $errors[] = 'La cantidad de ' . resource_label($resource) . ' no es válida.';
            $amount = 0;
        }
        $values[$resource] = $amount;
        $hasAmount = $hasAmount || $amount > 0;
        $actualRaw = trim((string) ($source['actual_' . $resource] ?? ''));
        $actual = $actualRaw === '' ? null : parse_resource_amount($actualRaw);
        if ($actualRaw !== '' && $actual === null) {
            $errors[] = 'La cantidad realmente recibida de ' . resource_label($resource) . ' no es válida.';
            $actual = null;
        }
        if ($actual !== null && $actual > $amount) {
            $errors[] = 'Lo recibido de ' . resource_label($resource) . ' no puede superar lo enviado.';
        }
        $values['actual_received'][$resource] = $actual;
    }
    if (!$hasAmount) {
        $errors[] = 'La entrega debe incluir al menos una cantidad mayor que cero.';
    }
    return ['errors' => array_values(array_unique($errors)), 'values' => $values];
}

function add_disbursement(array $values): array
{
    try {
        database_mutate(function (array &$data) use ($values): void {
            $summary = bank_summary_from_data($data);
            foreach (resource_keys() as $resource) {
                $available = (int) $summary['balance'][$resource] + (int) $summary['fund_breakdown']['reserve_draw'][$resource];
                if ((int) ($values[$resource] ?? 0) > $available) {
                    throw new InvalidArgumentException('La entrega de ' . resource_label($resource) . ' ('
                        . format_integer((int) $values[$resource]) . ') supera lo disponible ('
                        . format_integer($available) . ', reserva autorizada incluida).');
                }
            }
            $record = [
                'id' => new_id('out'),
                'recipient_name' => trim((string) $values['recipient_name']),
                'note' => trim((string) ($values['note'] ?? '')),
                'evidence_note' => trim((string) ($values['evidence_note'] ?? '')),
                'transport_count' => max(0, (int) ($values['transport_count'] ?? 0)),
                'actual_received' => isset($values['actual_received']) && is_array($values['actual_received'])
                    ? $values['actual_received'] : array_fill_keys(resource_keys(), null),
                // Congela el impuesto vigente para que el historial no cambie
                // si la configuración de un evento futuro usa otra tasa.
                'tax_rate' => (float) $data['settings']['tax_rate'],
                'created_at' => gmdate('c'),
                'updated_at' => null,
            ];
            foreach (resource_keys() as $resource) {
                $record[$resource] = max(0, (int) ($values[$resource] ?? 0));
            }
            $data['disbursements'][] = $record;
            audit_append($data, 'add', 'disbursement', (string) $record['id'], 'Entrega del banco a ' . (string) $record['recipient_name'] . '.');
        }, true, 'add-disbursement');
    } catch (InvalidArgumentException $exception) {
        return [$exception->getMessage()];
    }
    return [];
}

function update_disbursement(string $id, array $values): array
{
    try {
        database_mutate(function (array &$data) use ($id, $values): void {
            foreach ($data['disbursements'] as $index => $record) {
                if (!hash_equals((string) ($record['id'] ?? ''), $id)) {
                    continue;
                }
                $summary = bank_summary_from_data($data);
                foreach (resource_keys() as $resource) {
                    $available = (int) $summary['balance'][$resource] + (int) ($record[$resource] ?? 0)
                        + (int) $summary['fund_breakdown']['reserve_draw'][$resource];
                    if ((int) ($values[$resource] ?? 0) > $available) {
                        throw new InvalidArgumentException('La entrega de ' . resource_label($resource) . ' ('
                            . format_integer((int) $values[$resource]) . ') supera lo disponible ('
                            . format_integer($available) . ', reserva autorizada incluida).');
                    }
                    $data['disbursements'][$index][$resource] = max(0, (int) ($values[$resource] ?? 0));
                }
                $data['disbursements'][$index]['recipient_name'] = trim((string) $values['recipient_name']);
                $data['disbursements'][$index]['note'] = trim((string) ($values['note'] ?? ''));
                $data['disbursements'][$index]['evidence_note'] = trim((string) ($values['evidence_note'] ?? ''));
                $data['disbursements'][$index]['transport_count'] = max(0, (int) ($values['transport_count'] ?? 0));
                $data['disbursements'][$index]['actual_received'] = isset($values['actual_received']) && is_array($values['actual_received'])
                    ? $values['actual_received'] : array_fill_keys(resource_keys(), null);
                $data['disbursements'][$index]['updated_at'] = gmdate('c');
                audit_append($data, 'update', 'disbursement', $id, 'Se corrigió una entrega del banco a ' . (string) $values['recipient_name'] . '.');
                return;
            }
            throw new InvalidArgumentException('La entrega que intentas editar ya no existe.');
        }, true, 'update-disbursement');
    } catch (InvalidArgumentException $exception) {
        return [$exception->getMessage()];
    }
    return [];
}

function delete_disbursement(string $id): bool
{
    return database_mutate(function (array &$data) use ($id): bool {
        foreach ($data['disbursements'] as $index => $record) {
            if (!hash_equals((string) ($record['id'] ?? ''), $id)) {
                continue;
            }
            $name = (string) ($record['recipient_name'] ?? '');
            array_splice($data['disbursements'], $index, 1);
            audit_append($data, 'delete', 'disbursement', $id, 'Se eliminó una entrega a ' . $name . '.');
            return true;
        }
        return false;
    }, true, 'delete-disbursement');
}

function find_disbursement(string $id): ?array
{
    foreach (all_disbursements() as $record) {
        if (hash_equals((string) ($record['id'] ?? ''), $id)) {
            return $record;
        }
    }
    return null;
}

function all_disbursements(): array
{
    $records = database_read()['disbursements'];
    foreach ($records as &$record) {
        $taxRate = (float) ($record['tax_rate'] ?? current_settings()['tax_rate']);
        $record['tax_rate'] = $taxRate;
        $record['tax_amounts'] = [];
        $record['winner_receives'] = [];
        $record['receipt_is_exact'] = disbursement_has_exact_receipt($record);
        foreach (resource_keys() as $resource) {
            $record['tax_amounts'][$resource] = disbursement_tax_amount($record, $resource);
            $record['winner_receives'][$resource] = disbursement_actual_received($record, $resource);
        }
    }
    unset($record);
    usort($records, function (array $left, array $right): int {
        return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
    });
    return $records;
}

function settings_input(array $source): array
{
    $errors = [];
    $currentData = database_read();
    $current = $currentData['settings'];
    $currentWinners = [];
    foreach (winners_normalize($currentData['winners'] ?? [], (string) $current['score_metric_label']) as $savedWinner) {
        $currentWinners[(string) $savedWinner['rank']] = $savedWinner;
    }
    $metricLabel = text_limit((string) ($source['score_metric_label'] ?? $current['score_metric_label']), 120);
    if ($metricLabel === '') {
        $metricLabel = (string) $current['score_metric_label'];
    }
    $objectiveTitle = text_limit((string) ($source['objective_title'] ?? $current['objective_title']), 200);
    if ($objectiveTitle === '') {
        $objectiveTitle = (string) $current['objective_title'];
    }
    $objectiveDescription = text_limit((string) ($source['objective_description'] ?? $current['objective_description']), 2000);
    $rankingMethod = in_array((string) ($source['ranking_method'] ?? ''), ranking_method_keys(), true)
        ? (string) $source['ranking_method'] : (string) $current['ranking_method'];
    $rankRules = [];
    foreach (reward_rank_keys() as $rank) {
        $rankRules[$rank] = text_limit((string) ($source['rank_rule_' . $rank] ?? $current['rank_rule_' . $rank]), 500);
    }
    $eventName = trim((string) ($source['event_name'] ?? $current['event_name']));
    $custodian = trim((string) ($source['custodian_name'] ?? $current['custodian_name']));
    $prize = trim((string) ($source['prize'] ?? $current['prize']));
    $winners = [];
    foreach (reward_rank_keys() as $rank) {
        $nameKey = 'winner_' . $rank . '_name';
        $scoreKey = 'winner_' . $rank . '_score';
        $name = array_key_exists($nameKey, $source)
            ? trim((string) $source[$nameKey])
            : trim((string) ($currentWinners[$rank]['player_name'] ?? ''));
        $scoreRaw = array_key_exists($scoreKey, $source)
            ? trim((string) $source[$scoreKey])
            : (string) ($currentWinners[$rank]['score'] ?? '');
        if ($name === '' && $scoreRaw === '') {
            continue;
        }
        if (validate_player_name($name) !== null) {
            $errors[] = 'El nombre de ' . reward_rank_label($rank) . ' debe tener entre 1 y 80 caracteres.';
        }
        $score = parse_resource_amount($scoreRaw);
        if ($score === null || $score < 0) {
            $errors[] = 'La puntuación de ' . reward_rank_label($rank) . ' no es válida.';
            $score = 0;
        }
        $winners[] = [
            'rank' => $rank,
            'player_name' => $name,
            'score' => $score,
            'metric' => $metricLabel,
            'rank_rule' => $rankRules[$rank],
            'note' => '',
        ];
    }
    $winners = winners_normalize($winners, $metricLabel);
    $rankingWarning = winners_ranking_warning($winners, $rankingMethod, $metricLabel);
    $winner = winners_summary_text($winners);
    if ($winner === '') {
        $legacyWinner = trim((string) ($source['winner_name'] ?? ''));
        $legacyParsed = winners_from_legacy_summary($legacyWinner, $metricLabel);
        if ($legacyParsed !== []) {
            $winners = $legacyParsed;
            $winner = winners_summary_text($winners);
        } else {
            $winner = $legacyWinner;
        }
    }
    $eventLength = function_exists('mb_strlen') ? mb_strlen($eventName, 'UTF-8') : strlen($eventName);
    $custodianLength = function_exists('mb_strlen') ? mb_strlen($custodian, 'UTF-8') : strlen($custodian);
    $prizeLength = function_exists('mb_strlen') ? mb_strlen($prize, 'UTF-8') : strlen($prize);
    $winnerLength = function_exists('mb_strlen') ? mb_strlen($winner, 'UTF-8') : strlen($winner);
    if ($eventLength < 1 || $eventLength > 100) {
        $errors[] = 'El nombre del evento debe tener entre 1 y 100 caracteres.';
    }
    if ($custodianLength < 1 || $custodianLength > 80) {
        $errors[] = 'El nombre público del custodio debe tener entre 1 y 80 caracteres.';
    }
    if ($prizeLength > 220) {
        $errors[] = 'La descripción del premio no puede superar 220 caracteres.';
    }
    if ($winnerLength > 255) {
        $errors[] = 'El resumen de ganadores no puede superar 255 caracteres.';
    }
    $status = in_array((string) ($source['event_status'] ?? ''), ['active', 'closed'], true)
        ? (string) $source['event_status'] : (string) $current['event_status'];
    $taxRaw = str_replace(',', '.', trim((string) ($source['tax_rate'] ?? $current['tax_rate'])));
    if (!is_numeric($taxRaw) || (float) $taxRaw < 0 || (float) $taxRaw > 50) {
        $errors[] = 'El impuesto de salida debe ser un número entre 0 y 50.';
    }
    $rewardWeights = [];
    foreach (reward_rank_keys() as $rank) {
        $rawWeight = str_replace(',', '.', trim((string) ($source['reward_weight_' . $rank] ?? $current['reward_weights'][$rank])));
        if (!is_numeric($rawWeight) || (float) $rawWeight <= 0 || (float) $rawWeight > 1000000) {
            $errors[] = 'El peso de ' . reward_rank_label($rank) . ' debe ser un número mayor que cero.';
            $rewardWeights[$rank] = (float) $current['reward_weights'][$rank];
        } else {
            $rewardWeights[$rank] = (float) $rawWeight;
        }
    }
    $weightTotal = array_sum($rewardWeights);
    if (is_numeric($taxRaw) && (float) $taxRaw >= 0 && (float) $taxRaw <= 50 && $weightTotal > 0) {
        $thirdShare = (float) $rewardWeights['third'] / $weightTotal;
        if ($thirdShare <= ((float) $taxRaw / 100)) {
            $errors[] = 'La participación base de TOP 3 debe superar el impuesto para que quede un premio después de compensar TOP 1 y TOP 2.';
        }
    }
    $basisPointsRaw = (string) ($source['prize_pool_percent'] ?? basis_points_to_text((int) $current['prize_pool_basis_points']));
    $basisPoints = parse_basis_points($basisPointsRaw);
    if ($basisPoints === null) {
        $errors[] = 'El porcentaje del fondo destinado a premios debe estar entre 0 y 100, con hasta dos decimales.';
        $basisPoints = (int) $current['prize_pool_basis_points'];
    }

    $reserveAvailable = reserve_balances_from_movements(
        (array) ($currentData['reserve_movements'] ?? []),
        event_current_id($current)
    );
    $reserveDraw = [];
    foreach (resource_keys() as $resource) {
        $raw = trim((string) ($source['reserve_draw_' . $resource] ?? $current['reserve_draw'][$resource]));
        $amount = $raw === '' ? 0 : parse_resource_amount($raw);
        if ($amount === null || $amount < 0) {
            $errors[] = 'La reserva de ' . resource_label($resource) . ' que se añade al premio no es válida.';
            $amount = 0;
        }
        if ($amount > (int) $reserveAvailable[$resource]) {
            $errors[] = 'La reserva de ' . resource_label($resource) . ' solicitada (' . format_integer((int) $amount)
                . ') supera el saldo disponible (' . format_integer((int) $reserveAvailable[$resource]) . ').';
            $amount = (int) $reserveAvailable[$resource];
        }
        $reserveDraw[$resource] = (int) $amount;
    }

    $startsAt = $current['starts_at'];
    $startsAtRaw = trim((string) ($source['starts_at'] ?? format_datetime_input($current['starts_at'])));
    if ($startsAtRaw === '') {
        $startsAt = null;
    }
    if ($startsAtRaw !== '') {
        $zone = new DateTimeZone((string) app_config('timezone'));
        $startDate = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $startsAtRaw, $zone);
        $startErrors = DateTimeImmutable::getLastErrors();
        if ($startDate === false || (is_array($startErrors) && ($startErrors['warning_count'] > 0 || $startErrors['error_count'] > 0))) {
            $errors[] = 'La fecha de inicio no es válida.';
        } else {
            $startsAt = $startDate->setTimezone(new DateTimeZone('UTC'))->format('c');
        }
    }

    $transportCapacity = parse_resource_amount((string) ($source['transport_capacity'] ?? $current['transport_capacity']));
    if ($transportCapacity === null || $transportCapacity <= 0) {
        $errors[] = 'La capacidad por envío debe ser mayor que cero.';
        $transportCapacity = (int) $current['transport_capacity'];
    }
    $thresholds = [];
    foreach (resource_keys() as $resource) {
        $amount = parse_resource_amount((string) ($source['threshold_' . $resource] ?? $current['thresholds'][$resource]));
        if ($amount === null || $amount <= 0) {
            $errors[] = 'El requisito de ' . resource_label($resource) . ' debe ser mayor que cero.';
            $amount = (int) $current['thresholds'][$resource];
        }
        $thresholds[$resource] = $amount;
    }
    $deadline = $current['deadline'];
    $deadlineRaw = trim((string) ($source['deadline'] ?? format_datetime_input($current['deadline'])));
    if ($deadlineRaw === '') {
        $deadline = null;
    }
    if ($deadlineRaw !== '') {
        $zone = new DateTimeZone((string) app_config('timezone'));
        $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $deadlineRaw, $zone);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($date === false || (is_array($dateErrors) && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
            $errors[] = 'La fecha límite no es válida.';
        } else {
            $deadline = $date->setTimezone(new DateTimeZone('UTC'))->format('c');
        }
    }
    return [
        'errors' => array_values(array_unique($errors)),
        'warnings' => $rankingWarning === null ? [] : [$rankingWarning],
        'values' => [
            'event_name' => $eventName,
            'event_status' => $status,
            'custodian_name' => $custodian,
            'current_event_id' => event_current_id($current),
            'objective_title' => $objectiveTitle,
            'objective_description' => $objectiveDescription,
            'score_metric_label' => $metricLabel,
            'ranking_method' => $rankingMethod,
            'rank_rule_first' => $rankRules['first'],
            'rank_rule_second' => $rankRules['second'],
            'rank_rule_third' => $rankRules['third'],
            'starts_at' => $startsAt,
            'prize_pool_basis_points' => (int) $basisPoints,
            'reserve_draw' => $reserveDraw,
            'settlement_status' => (string) $current['settlement_status'],
            'frozen_at' => $current['frozen_at'],
            'tax_rate' => is_numeric($taxRaw) ? (float) $taxRaw : 8,
            'qualification_basis' => 'net_received',
            'reward_weights' => $rewardWeights,
            'transport_capacity' => $transportCapacity,
            'deadline' => $deadline,
            'prize' => $prize,
            'winner_name' => $winner,
            '_winners' => $winners,
            'thresholds' => $thresholds,
        ],
    ];
}

/**
 * Campos que dejan de poder cambiarse en silencio una vez congelada la
 * liquidación. Para tocarlos hay que desbloquear explícitamente y dejar el
 * motivo en la auditoría.
 */
function settlement_locked_fields(): array
{
    return ['tax_rate', 'prize_pool_basis_points', 'reward_weights', 'reserve_draw', 'thresholds'];
}

function settlement_locked_differences(array $current, array $next): array
{
    $labels = [
        'tax_rate' => 'el impuesto del juego',
        'prize_pool_basis_points' => 'el porcentaje del fondo destinado a premios',
        'reward_weights' => 'los pesos de los puestos',
        'reserve_draw' => 'la reserva utilizada',
        'thresholds' => 'las cuotas requeridas',
    ];
    $changed = [];
    foreach (settlement_locked_fields() as $field) {
        $before = $current[$field] ?? null;
        $after = $next[$field] ?? null;
        if (is_array($before) || is_array($after)) {
            if (json_encode($before) !== json_encode($after)) {
                $changed[] = $labels[$field];
            }
            continue;
        }
        if ((string) $before !== (string) $after) {
            $changed[] = $labels[$field];
        }
    }
    return $changed;
}

function save_settings(array $values): array
{
    try {
        database_mutate(function (array &$data) use ($values): void {
            $metric = (string) ($values['score_metric_label'] ?? $data['settings']['score_metric_label']);
            $winners = winners_normalize($values['_winners'] ?? [], $metric);
            unset($values['_winners']);
            $current = settings_normalize($data['settings']);
            $candidate = settings_normalize(array_merge($current, $values));
            if ((string) $current['settlement_status'] === 'frozen') {
                $changed = settlement_locked_differences($current, $candidate);
                if ($changed !== []) {
                    throw new InvalidArgumentException(
                        'La liquidación está congelada. Para cambiar ' . implode(', ', $changed)
                        . ' primero ejecuta «Desbloquear liquidación» y explica el motivo.'
                    );
                }
            }
            $values['winner_name'] = winners_summary_text($winners) ?: (string) ($values['winner_name'] ?? '');
            $values['updated_at'] = gmdate('c');
            $data['settings'] = $candidate;
            $data['settings']['winner_name'] = (string) $values['winner_name'];
            $data['settings']['updated_at'] = (string) $values['updated_at'];
            $data['winners'] = $winners;
            audit_append($data, 'update', 'settings', '', 'Se actualizó la configuración del evento.');
        }, true, 'settings');
    } catch (InvalidArgumentException $exception) {
        return [$exception->getMessage()];
    }
    return [];
}

/* =========================================================================
 * Congelación de la liquidación
 * ========================================================================= */

/**
 * Fotografía inmutable del evento en el momento de congelar: participantes,
 * cuotas, objetivo, reglas, métrica, ganadores, impuesto, porcentaje del
 * fondo, pesos, reserva de apertura y utilizada, fondo exacto por recurso y
 * la proyección bruto/impuesto/neto de cada ganador.
 */
function settlement_snapshot_from_data(array $data): array
{
    $summary = bank_summary_from_data($data);
    $settings = $summary['settings'];
    $projection = $summary['reward_projection'];
    $winners = winners_normalize($data['winners'] ?? [], (string) $settings['score_metric_label']);
    $prizes = [];
    foreach (reward_rank_keys() as $rank) {
        $prize = $projection['prizes'][$rank];
        $prizes[$rank] = [
            'rank' => $rank,
            'label' => reward_rank_label($rank),
            'rule' => (string) $settings['rank_rule_' . $rank],
            'weight' => (float) $prize['weight'],
            'share' => (float) $prize['share'],
            'gross_sent' => $prize['sent'],
            'tax' => $prize['tax'],
            'net_received' => $prize['received'],
            'gross_sent_total' => (int) $prize['sent_total'],
            'tax_total' => (int) $prize['tax_total'],
            'net_received_total' => (int) $prize['received_total'],
            'trips' => (int) $prize['trips'],
        ];
    }
    return [
        'format' => 'rokbank_settlement_v1',
        'event_id' => event_current_id($settings),
        'event_name' => (string) $settings['event_name'],
        'objective_title' => (string) $settings['objective_title'],
        'objective_description' => (string) $settings['objective_description'],
        'score_metric_label' => (string) $settings['score_metric_label'],
        'ranking_method' => (string) $settings['ranking_method'],
        'rank_rules' => [
            'first' => (string) $settings['rank_rule_first'],
            'second' => (string) $settings['rank_rule_second'],
            'third' => (string) $settings['rank_rule_third'],
        ],
        'thresholds' => $settings['thresholds'],
        'tax_rate' => (float) $settings['tax_rate'],
        'prize_pool_basis_points' => (int) $settings['prize_pool_basis_points'],
        'reward_weights' => $settings['reward_weights'],
        'transport_capacity' => (int) $settings['transport_capacity'],
        'fund_breakdown' => $summary['fund_breakdown'],
        'winners' => $winners,
        'prizes' => $prizes,
        'players' => array_map(static function (array $player): array {
            $row = [
                'id' => (string) $player['id'],
                'name' => (string) $player['name'],
                'records' => (int) $player['records'],
                'eligible' => (bool) $player['eligible'],
                'remaining' => $player['remaining'],
            ];
            foreach (resource_keys() as $resource) {
                $row[$resource] = (int) $player[$resource];
            }
            return $row;
        }, $summary['players']),
        'received' => $summary['received'],
        'paid' => $summary['paid'],
        'balance' => $summary['balance'],
        'created_at' => gmdate('c'),
    ];
}

function settlement_record_for_event(array $data, string $eventId): ?array
{
    foreach ((array) ($data['settlements'] ?? []) as $settlement) {
        if (is_array($settlement) && (string) ($settlement['event_id'] ?? '') === $eventId) {
            return $settlement;
        }
    }
    return null;
}

function current_settlement(): ?array
{
    $data = database_read();
    return settlement_record_for_event($data, event_current_id($data['settings']));
}

/**
 * Comprobaciones previas a congelar. No se congela un plan que ya se sabe
 * incoherente: cada mensaje indica el recurso, el valor esperado y el observado.
 */
function settlement_freeze_checks(array $data): array
{
    $data = database_normalize($data);
    $settings = $data['settings'];
    $summary = bank_summary_from_data($data);
    $breakdown = $summary['fund_breakdown'];
    $winners = winners_normalize($data['winners'] ?? [], (string) $settings['score_metric_label']);
    $checks = [];

    $checks[] = [
        'key' => 'contributions',
        'label' => 'El evento tiene al menos un aporte registrado.',
        'passed' => $data['contributions'] !== [],
        'detail' => '',
    ];
    $checks[] = [
        'key' => 'winners',
        'label' => 'Los tres puestos tienen nombre y puntuación de «' . (string) $settings['score_metric_label'] . '».',
        'passed' => count($winners) === 3 && count(array_filter($winners, static function (array $winner): bool {
            return (int) $winner['score'] > 0;
        })) === 3,
        'detail' => count($winners) === 3 ? '' : 'Registrados ' . count($winners) . ' de 3.',
    ];
    $rankingWarning = winners_ranking_warning($winners, (string) $settings['ranking_method'], (string) $settings['score_metric_label']);
    $checks[] = [
        'key' => 'ranking_order',
        'label' => 'El orden de las puntuaciones respeta el método «' . ranking_method_label((string) $settings['ranking_method']) . '».',
        'passed' => $rankingWarning === null,
        'detail' => (string) $rankingWarning,
    ];

    $drawErrors = [];
    foreach (resource_keys() as $resource) {
        $requested = (int) $breakdown['reserve_draw_requested'][$resource];
        $available = (int) $breakdown['reserve_opening'][$resource];
        if ($requested > $available) {
            $drawErrors[] = resource_title($resource) . ': solicitado ' . format_integer($requested)
                . ', disponible ' . format_integer($available) . '.';
        }
    }
    $checks[] = [
        'key' => 'reserve_draw',
        'label' => 'La reserva solicitada no supera el saldo disponible en ningún recurso.',
        'passed' => $drawErrors === [],
        'detail' => implode(' ', $drawErrors),
    ];

    $fundErrors = [];
    foreach (resource_keys() as $resource) {
        $authorized = (int) $breakdown['prize_fund_gross'][$resource];
        $paid = (int) $summary['paid'][$resource];
        if ($paid > $authorized) {
            $fundErrors[] = resource_title($resource) . ': entregado ' . format_integer($paid)
                . ', autorizado ' . format_integer($authorized) . '.';
        }
    }
    $checks[] = [
        'key' => 'payouts_within_fund',
        'label' => 'Ninguna entrega ya registrada supera el fondo autorizado por recurso.',
        'passed' => $fundErrors === [],
        'detail' => implode(' ', $fundErrors),
    ];

    return [
        'ready' => count(array_filter($checks, static function (array $check): bool {
            return empty($check['passed']);
        })) === 0,
        'checks' => $checks,
        'summary' => $summary,
        'breakdown' => $breakdown,
        'winners' => $winners,
    ];
}

function current_settlement_freeze_checks(): array
{
    return settlement_freeze_checks(database_read());
}

function freeze_settlement(): array
{
    try {
        database_mutate(function (array &$data): void {
            $settings = settings_normalize($data['settings']);
            if ((string) $settings['settlement_status'] === 'frozen') {
                throw new InvalidArgumentException('La liquidación ya está congelada.');
            }
            if ((string) $settings['settlement_status'] === 'closed') {
                throw new InvalidArgumentException('El evento ya está cerrado.');
            }
            $result = settlement_freeze_checks($data);
            if (empty($result['ready'])) {
                $failed = [];
                foreach ($result['checks'] as $check) {
                    if (empty($check['passed'])) {
                        $failed[] = (string) $check['label'] . ' ' . (string) $check['detail'];
                    }
                }
                throw new InvalidArgumentException('No se puede congelar todavía: ' . trim(implode(' ', $failed)));
            }
            $eventId = event_current_id($settings);
            $snapshot = settlement_snapshot_from_data($data);
            $existing = settlement_record_for_event($data, $eventId);
            $settlements = [];
            foreach ((array) $data['settlements'] as $settlement) {
                if (is_array($settlement) && (string) ($settlement['event_id'] ?? '') !== $eventId) {
                    $settlements[] = $settlement;
                }
            }
            $settlements[] = [
                'event_id' => $eventId,
                'status' => 'frozen',
                'frozen_at' => gmdate('c'),
                'unfreeze_count' => (int) ($existing['unfreeze_count'] ?? 0),
                'snapshot' => $snapshot,
                'updated_at' => gmdate('c'),
            ];
            $data['settlements'] = $settlements;
            $settings['current_event_id'] = $eventId;
            $settings['settlement_status'] = 'frozen';
            $settings['frozen_at'] = gmdate('c');
            $settings['updated_at'] = gmdate('c');
            $data['settings'] = settings_normalize($settings);
            audit_append($data, 'freeze', 'settlement', $eventId, 'Se congeló la liquidación del evento ' . (string) $settings['event_name'] . '.');
        }, true, 'freeze-settlement');
    } catch (InvalidArgumentException $exception) {
        return [$exception->getMessage()];
    }
    return [];
}

function unfreeze_settlement(string $reason): array
{
    $reason = trim($reason);
    if (text_length($reason) < 8 || text_length($reason) > 500) {
        return ['Explica con entre 8 y 500 caracteres por qué hay que desbloquear la liquidación.'];
    }
    try {
        database_mutate(function (array &$data) use ($reason): void {
            $settings = settings_normalize($data['settings']);
            if ((string) $settings['settlement_status'] !== 'frozen') {
                throw new InvalidArgumentException('La liquidación no está congelada.');
            }
            $eventId = event_current_id($settings);
            $settlements = [];
            foreach ((array) $data['settlements'] as $settlement) {
                if (is_array($settlement) && (string) ($settlement['event_id'] ?? '') === $eventId) {
                    $settlement['status'] = 'open';
                    $settlement['unfreeze_count'] = (int) ($settlement['unfreeze_count'] ?? 0) + 1;
                    $settlement['updated_at'] = gmdate('c');
                }
                $settlements[] = $settlement;
            }
            $data['settlements'] = $settlements;
            $settings['settlement_status'] = 'open';
            $settings['frozen_at'] = null;
            $settings['updated_at'] = gmdate('c');
            $data['settings'] = settings_normalize($settings);
            audit_append($data, 'unfreeze', 'settlement', $eventId, 'Se desbloqueó la liquidación. Motivo: ' . $reason);
        }, true, 'unfreeze-settlement');
    } catch (InvalidArgumentException $exception) {
        return [$exception->getMessage()];
    }
    return [];
}

/**
 * Ajuste manual del libro mayor de reservas. Siempre exige justificación y
 * crea una entrada de auditoría; nunca edita ni borra un movimiento anterior.
 */
function reserve_manual_adjustment(string $resource, string $direction, string $amountRaw, string $reason): array
{
    $errors = [];
    if (!in_array($resource, resource_keys(), true)) {
        $errors[] = 'Elige un recurso válido.';
    }
    if (!in_array($direction, ['in', 'out', 'correction_in', 'correction_out'], true)) {
        $errors[] = 'Elige un tipo de movimiento válido.';
    }
    $amount = parse_resource_amount($amountRaw);
    if ($amount === null || $amount <= 0) {
        $errors[] = 'La cantidad del ajuste debe ser mayor que cero.';
        $amount = 0;
    }
    $reason = trim($reason);
    if (text_length($reason) < 8 || text_length($reason) > 500) {
        $errors[] = 'Explica el ajuste con entre 8 y 500 caracteres.';
    }
    if ($errors !== []) {
        return array_values(array_unique($errors));
    }
    try {
        database_mutate(function (array &$data) use ($resource, $direction, $amount, $reason): void {
            $eventId = event_current_id($data['settings']);
            $isOut = ($direction === 'out' || $direction === 'correction_out');
            $isCorrection = strpos($direction, 'correction') === 0;
            $type = $isCorrection ? 'correction' : ($isOut ? 'manual_adjustment_out' : 'manual_adjustment_in');
            $signed = $isOut ? -1 * (int) $amount : (int) $amount;
            $available = reserve_balances_from_movements((array) $data['reserve_movements']);
            if ($signed < 0 && (int) $available[$resource] + $signed < 0) {
                throw new InvalidArgumentException(
                    'El ajuste dejaría la reserva de ' . resource_label($resource) . ' en negativo: disponible '
                    . format_integer((int) $available[$resource]) . ', solicitado ' . format_integer((int) $amount) . '.'
                );
            }
            $auditId = new_id('log');
            reserve_movement_append(
                $data,
                $isCorrection ? $eventId : '',
                $resource,
                $type,
                $signed,
                $reason,
                null,
                $auditId
            );
            $data['audit_log'][] = [
                'id' => $auditId,
                'action' => 'reserve_adjustment',
                'entity_type' => 'reserve',
                'entity_id' => $resource,
                'description' => reserve_movement_label($type) . ' de ' . format_integer((int) $amount) . ' de '
                    . resource_label($resource) . '. Motivo: ' . $reason,
                'created_at' => gmdate('c'),
            ];
        }, true, 'reserve-adjustment');
    } catch (InvalidArgumentException $exception) {
        return [$exception->getMessage()];
    }
    return [];
}

function current_settings(): array
{
    return database_read()['settings'];
}

function legacy_archive_enrichment(array $archive): array
{
    $eventName = trim((string) (($archive['settings']['event_name'] ?? '')));
    if ($eventName !== 'Fuertes Bárbaros · Semana 21/09/2026') {
        return $archive;
    }

    if (winners_normalize($archive['winners'] ?? []) === []) {
        $archive['winners'] = winners_normalize([
            ['rank' => 'first', 'player_name' => 'ᴹᵉAstrielle', 'score' => 510, 'metric' => 'Fuertes destruidos'],
            ['rank' => 'second', 'player_name' => 'ᴹᵉ HadesWar', 'score' => 436, 'metric' => 'Fuertes destruidos'],
            ['rank' => 'third', 'player_name' => 'ᴹᵉ×Black DG', 'score' => 362, 'metric' => 'Fuertes destruidos'],
        ]);
    }

    $verified = [
        normalize_player_name('ᴹᵉAstrielle') => [
            'actual_received' => ['food' => 28163022, 'wood' => 26426890, 'stone' => 22358507, 'gold' => 16443020],
            'transport_count' => 13,
        ],
        normalize_player_name('ᴹᵉ HadesWar') => [
            'actual_received' => ['food' => 22530417, 'wood' => 21141512, 'stone' => 17886805, 'gold' => 13154416],
            'transport_count' => 8,
        ],
        normalize_player_name('ᴹᵉ×Black DG') => [
            'actual_received' => ['food' => 13505734, 'wood' => 12673163, 'stone' => 10722145, 'gold' => 7885342],
            'transport_count' => 5,
        ],
    ];
    foreach ((array) ($archive['disbursements'] ?? []) as $index => $record) {
        if (!is_array($record)) {
            continue;
        }
        $key = normalize_player_name((string) ($record['recipient_name'] ?? ''));
        if (!isset($verified[$key])) {
            continue;
        }
        $savedActual = isset($record['actual_received']) && is_array($record['actual_received'])
            ? $record['actual_received'] : [];
        foreach (resource_keys() as $resource) {
            if (!array_key_exists($resource, $savedActual) || $savedActual[$resource] === null || $savedActual[$resource] === '') {
                $archive['disbursements'][$index]['actual_received'][$resource] = $verified[$key]['actual_received'][$resource];
            }
        }
        if ((int) ($record['transport_count'] ?? 0) <= 0) {
            $archive['disbursements'][$index]['transport_count'] = $verified[$key]['transport_count'];
        }
        if (trim((string) ($record['evidence_note'] ?? '')) === '') {
            $archive['disbursements'][$index]['evidence_note'] = 'Verificado con los informes de asistencia del juego y la clasificación pública de 212.890.973 recursos.';
        }
    }
    return $archive;
}

function archive_normalize(array $archive): array
{
    $archive = legacy_archive_enrichment($archive);
    $historicMetric = trim((string) ($archive['settings']['score_metric_label'] ?? ''));
    if ($historicMetric === '') {
        // Los eventos anteriores a esta versión conservan su etiqueta original.
        $historicMetric = 'Fuertes destruidos';
        if (isset($archive['settings']) && is_array($archive['settings'])) {
            $archive['settings']['score_metric_label'] = $historicMetric;
        }
    }
    $state = database_normalize([
        'settings' => $archive['settings'] ?? [],
        'winners' => $archive['winners'] ?? winners_from_legacy_summary((string) (($archive['settings']['winner_name'] ?? '')), $historicMetric),
        'players' => $archive['players'] ?? [],
        'contributions' => $archive['contributions'] ?? [],
        'disbursements' => $archive['disbursements'] ?? [],
        'audit_log' => $archive['audit_log'] ?? [],
    ]);
    $state['settings']['event_status'] = 'closed';
    $state['settings']['settlement_status'] = 'closed';
    if ($state['winners'] === []) {
        $state['winners'] = winners_from_legacy_summary((string) $state['settings']['winner_name'], $historicMetric);
    }
    if ($state['winners'] !== []) {
        $state['settings']['winner_name'] = winners_summary_text($state['winners']);
    }
    $summary = bank_summary_from_data($state);
    return [
        'id' => (string) ($archive['id'] ?? stable_id('evt', (string) ($archive['archived_at'] ?? '') . '|' . (string) $state['settings']['event_name'])),
        'settings' => $state['settings'],
        'winners' => $state['winners'],
        'players' => $state['players'],
        'contributions' => $state['contributions'],
        'disbursements' => $state['disbursements'],
        'audit_log' => array_values(array_filter((array) ($archive['audit_log'] ?? []), 'is_array')),
        'summary' => [
            'received' => $summary['received'],
            'paid' => $summary['paid'],
            'outgoing_tax' => $summary['outgoing_tax'],
            'winner_receives' => $summary['winner_receives'],
            'balance' => $summary['balance'],
            'received_grand_total' => $summary['received_grand_total'],
            'paid_grand_total' => $summary['paid_grand_total'],
            'outgoing_tax_grand_total' => $summary['outgoing_tax_grand_total'],
            'winner_receives_grand_total' => $summary['winner_receives_grand_total'],
            'player_count' => $summary['player_count'],
            'eligible_count' => $summary['eligible_count'],
            'record_count' => $summary['record_count'],
            'disbursement_count' => $summary['disbursement_count'],
        ],
        'fund_breakdown' => isset($archive['fund_breakdown']) && is_array($archive['fund_breakdown'])
            ? $archive['fund_breakdown'] : null,
        'settlement' => isset($archive['settlement']) && is_array($archive['settlement'])
            ? $archive['settlement'] : null,
        'archived_at' => (string) ($archive['archived_at'] ?? gmdate('c')),
        'revision_log' => array_values(array_filter((array) ($archive['revision_log'] ?? []), 'is_array')),
    ];
}

function archives_normalize_with_audit(array $archives, array $globalAudit): array
{
    $archives = array_values(array_filter($archives, 'is_array'));
    usort($archives, static function (array $left, array $right): int {
        return strcmp((string) ($left['archived_at'] ?? ''), (string) ($right['archived_at'] ?? ''));
    });
    $previousArchivedAt = '';
    foreach ($archives as $index => $archive) {
        $archivedAt = (string) ($archive['archived_at'] ?? '');
        if (empty($archive['audit_log']) && $archivedAt !== '') {
            $archive['audit_log'] = array_values(array_filter($globalAudit, static function ($entry) use ($previousArchivedAt, $archivedAt): bool {
                if (!is_array($entry)) {
                    return false;
                }
                $createdAt = (string) ($entry['created_at'] ?? '');
                return $createdAt !== ''
                    && ($previousArchivedAt === '' || strcmp($createdAt, $previousArchivedAt) > 0)
                    && strcmp($createdAt, $archivedAt) <= 0;
            }));
        }
        $archives[$index] = archive_normalize($archive);
        $previousArchivedAt = max($previousArchivedAt, $archivedAt);
    }
    return $archives;
}

function event_audit_slice(array $data): array
{
    $lastArchiveAt = '';
    foreach ((array) ($data['events_archive'] ?? []) as $archive) {
        $lastArchiveAt = max($lastArchiveAt, (string) ($archive['archived_at'] ?? ''));
    }
    return array_values(array_filter((array) ($data['audit_log'] ?? []), static function ($entry) use ($lastArchiveAt): bool {
        return is_array($entry) && ($lastArchiveAt === '' || strcmp((string) ($entry['created_at'] ?? ''), $lastArchiveAt) > 0);
    }));
}

/**
 * Conciliación de cierre. Ya no exige saldo cero: un evento puede cerrar con
 * saldo físico distinto de cero siempre que coincida exactamente con la
 * reserva calculada. Cada comprobación que falla indica el recurso, el valor
 * esperado y el valor observado.
 */
function event_reconciliation_from_data(array $data): array
{
    $data = database_normalize($data);
    $settings = $data['settings'];
    $summary = bank_summary_from_data($data);
    $breakdown = $summary['fund_breakdown'];
    $metricLabel = (string) $settings['score_metric_label'];
    $winners = winners_normalize($data['winners'] ?? [], $metricLabel);
    if ($winners === []) {
        $winners = winners_from_legacy_summary((string) $settings['winner_name'], $metricLabel);
    }
    $isArchived = (string) $settings['settlement_status'] === 'closed';
    $checks = [];

    // 1. El evento está congelado.
    $checks[] = [
        'key' => 'frozen',
        'label' => 'La liquidación del evento está congelada.',
        'passed' => in_array((string) $settings['settlement_status'], ['frozen', 'closed'], true),
        'detail' => in_array((string) $settings['settlement_status'], ['frozen', 'closed'], true)
            ? '' : 'Estado actual: ' . settlement_status_label((string) $settings['settlement_status']) . '.',
    ];

    // 2. Ganadores y métricas completos.
    $winnersComplete = count($winners) === 3 && count(array_filter($winners, static function (array $winner): bool {
        return (int) $winner['score'] > 0 && trim((string) $winner['metric']) !== '';
    })) === 3;
    $checks[] = [
        'key' => 'winners',
        'label' => 'Los tres ganadores tienen nombre, puntuación y métrica «' . $metricLabel . '».',
        'passed' => $winnersComplete,
        'detail' => $winnersComplete ? '' : 'Registrados ' . count($winners) . ' de 3.',
    ];

    // 3. Los pagos registrados no superan el fondo autorizado.
    $overspent = [];
    foreach (resource_keys() as $resource) {
        $authorized = (int) $breakdown['prize_fund_gross'][$resource];
        $paid = (int) $summary['paid'][$resource];
        if ($paid > $authorized) {
            $overspent[] = resource_title($resource) . ': autorizado ' . format_integer($authorized)
                . ', entregado ' . format_integer($paid) . '.';
        }
    }
    $checks[] = [
        'key' => 'within_fund',
        'label' => 'Ninguna entrega supera el fondo bruto autorizado por recurso.',
        'passed' => $overspent === [],
        'detail' => implode(' ', $overspent),
    ];

    // 4. La suma bruta enviada coincide con el plan o existe una corrección.
    $planGaps = [];
    foreach (resource_keys() as $resource) {
        $planned = (int) $breakdown['prize_fund_gross'][$resource];
        $paid = (int) $summary['paid'][$resource];
        $correction = (int) $breakdown['reserve_corrections'][$resource];
        if ($paid !== $planned && $correction !== ($planned - $paid)) {
            $planGaps[] = resource_title($resource) . ': plan ' . format_integer($planned)
                . ', enviado ' . format_integer($paid) . ', corrección registrada '
                . format_integer($correction) . ' (se necesita ' . format_integer($planned - $paid) . ').';
        }
    }
    $checks[] = [
        'key' => 'plan_matches',
        'label' => 'La suma bruta enviada coincide con el plan o existe una corrección documentada.',
        'passed' => $planGaps === [],
        'detail' => implode(' ', $planGaps),
    ];

    // 5. Las cantidades netas realmente recibidas están registradas.
    $allReceiptsExact = true;
    $recipientKeys = [];
    foreach ($data['disbursements'] as $record) {
        $recipientKeys[normalize_player_name((string) $record['recipient_name'])] = true;
        $allReceiptsExact = $allReceiptsExact && disbursement_has_exact_receipt($record);
    }
    if ($data['disbursements'] === []) {
        $allReceiptsExact = false;
    }
    $checks[] = [
        'key' => 'exact_receipts',
        'label' => 'Todas las entregas indican cuánto recibió realmente el destinatario.',
        'passed' => $allReceiptsExact,
        'detail' => $data['disbursements'] === [] ? 'Todavía no hay ninguna entrega registrada.' : '',
    ];

    // 6. El impuesto es conciliable con bruto menos neto.
    $taxGaps = [];
    foreach (resource_keys() as $resource) {
        $expected = (int) $summary['paid'][$resource] - (int) $summary['winner_receives'][$resource];
        $recorded = (int) $summary['outgoing_tax'][$resource];
        if ($expected !== $recorded) {
            $taxGaps[] = resource_title($resource) . ': esperado ' . format_integer($expected)
                . ', registrado ' . format_integer($recorded) . '.';
        }
    }
    $checks[] = [
        'key' => 'tax_reconciles',
        'label' => 'El impuesto de cada recurso equivale exactamente a bruto menos neto.',
        'passed' => $taxGaps === [],
        'detail' => implode(' ', $taxGaps),
    ];

    // 7. El saldo físico restante coincide con R_cierre.
    $balanceGaps = [];
    foreach (resource_keys() as $resource) {
        $expected = (int) $breakdown['reserve_closing'][$resource];
        $observed = (int) $breakdown['physical_total'][$resource];
        if ($expected !== $observed) {
            $balanceGaps[] = resource_title($resource) . ': reserva calculada ' . format_integer($expected)
                . ', saldo físico ' . format_integer($observed)
                . ' (diferencia ' . format_integer($observed - $expected) . ').';
        }
    }
    $checks[] = [
        'key' => 'reserve_matches',
        'label' => 'El saldo físico restante coincide con la reserva de cierre calculada.',
        'passed' => $balanceGaps === [],
        'detail' => implode(' ', $balanceGaps),
    ];

    // 8. El movimiento de reserva del evento se contabiliza exactamente una vez.
    $alreadyBooked = false;
    foreach ((array) $data['reserve_movements'] as $movement) {
        if (is_array($movement)
            && (string) ($movement['event_id'] ?? '') === (string) $breakdown['event_id']
            && in_array((string) ($movement['movement_type'] ?? ''), ['event_retained', 'event_release'], true)) {
            $alreadyBooked = true;
            break;
        }
    }
    $checks[] = [
        'key' => 'reserve_once',
        'label' => $isArchived
            ? 'El movimiento de reserva del evento está contabilizado exactamente una vez.'
            : 'El movimiento de reserva de este evento todavía no está contabilizado.',
        'passed' => $isArchived ? $alreadyBooked : !$alreadyBooked,
        'detail' => (!$isArchived && $alreadyBooked)
            ? 'Ya existe un movimiento de reserva para este evento; cerrar otra vez no lo duplicará.' : '',
    ];

    // Comprobación heredada que se conserva.
    $allWinnersPaid = count($winners) === 3;
    foreach ($winners as $winner) {
        $allWinnersPaid = $allWinnersPaid && isset($recipientKeys[normalize_player_name((string) $winner['player_name'])]);
    }
    $checks[] = [
        'key' => 'winner_payouts',
        'label' => 'Cada ganador tiene al menos una entrega registrada.',
        'passed' => $allWinnersPaid,
        'detail' => '',
    ];
    $checks[] = [
        'key' => 'financial_integrity',
        'label' => 'Ningún recurso enviado supera lo que el banco puede gastar.',
        'passed' => financial_integrity_errors($data) === [],
        'detail' => implode(' ', financial_integrity_errors($data)),
    ];

    return [
        'ready' => count(array_filter($checks, static function (array $check): bool {
            return empty($check['passed']);
        })) === 0,
        'checks' => $checks,
        'summary' => $summary,
        'breakdown' => $breakdown,
        'winners' => $winners,
    ];
}

function current_event_reconciliation(): array
{
    return event_reconciliation_from_data(database_read());
}

function archive_current_event(string $nextEventName, string $confirmation): array
{
    $nextEventName = trim($nextEventName);
    $nameLength = function_exists('mb_strlen') ? mb_strlen($nextEventName, 'UTF-8') : strlen($nextEventName);
    if ($confirmation !== 'ARCHIVAR') {
        return ['Escribe ARCHIVAR exactamente para confirmar.'];
    }
    if ($nameLength < 1 || $nameLength > 100) {
        return ['Escribe un nombre válido para el próximo evento.'];
    }
    try {
        database_mutate(function (array &$data) use ($nextEventName): void {
            $reconciliation = event_reconciliation_from_data($data);
            if (empty($reconciliation['ready'])) {
                $failed = array_values(array_map(static function (array $check): string {
                    return (string) $check['label'];
                }, array_filter($reconciliation['checks'], static function (array $check): bool {
                    return empty($check['passed']);
                })));
                throw new InvalidArgumentException('No se puede archivar todavía: ' . implode(' ', $failed));
            }
            $summary = $reconciliation['summary'];
            $breakdown = $reconciliation['breakdown'];
            $eventId = (string) $breakdown['event_id'];
            $archiveId = $eventId;

            // El movimiento de reserva del evento se contabiliza exactamente una
            // vez gracias a la clave de deduplicación: reintentar el cierre no lo
            // duplica nunca.
            foreach (resource_keys() as $resource) {
                $release = (int) $breakdown['reserve_draw'][$resource];
                if ($release > 0) {
                    reserve_movement_append(
                        $data,
                        $eventId,
                        $resource,
                        'event_release',
                        -1 * $release,
                        'Reserva entregada al premio de ' . (string) $data['settings']['event_name'] . '.',
                        'evt:' . $eventId . ':' . $resource . ':release'
                    );
                }
                $retained = (int) $breakdown['contributed_total'][$resource] - (int) $breakdown['prize_from_event'][$resource];
                if ($retained !== 0) {
                    reserve_movement_append(
                        $data,
                        $eventId,
                        $resource,
                        'event_retained',
                        $retained,
                        'Retención del evento ' . (string) $data['settings']['event_name']
                        . ' (aportes no elegibles, porcentaje no repartido y residuo de redondeo).',
                        'evt:' . $eventId . ':' . $resource . ':retained'
                    );
                }
            }

            audit_append($data, 'archive', 'event', $archiveId, 'Se archivó el evento y se inició ' . $nextEventName . '.');
            $archivedSettings = $data['settings'];
            $archivedSettings['event_status'] = 'closed';
            $archivedSettings['settlement_status'] = 'closed';
            $archivedSettings['current_event_id'] = $eventId;
            $archivedSettings['winner_name'] = winners_summary_text($reconciliation['winners']);
            $settlementRecord = settlement_record_for_event($data, $eventId);
            $settlements = [];
            foreach ((array) $data['settlements'] as $settlement) {
                if (is_array($settlement) && (string) ($settlement['event_id'] ?? '') === $eventId) {
                    $settlement['status'] = 'closed';
                    $settlement['updated_at'] = gmdate('c');
                }
                $settlements[] = $settlement;
            }
            $data['settlements'] = $settlements;
            $data['events_archive'][] = [
                'id' => $archiveId,
                'settings' => $archivedSettings,
                'winners' => $reconciliation['winners'],
                'players' => $data['players'],
                'contributions' => $data['contributions'],
                'disbursements' => $data['disbursements'],
                'audit_log' => event_audit_slice($data),
                'summary' => [
                    'received' => $summary['received'],
                    'paid' => $summary['paid'],
                    'outgoing_tax' => $summary['outgoing_tax'],
                    'winner_receives' => $summary['winner_receives'],
                    'balance' => $summary['balance'],
                    'received_grand_total' => $summary['received_grand_total'],
                    'paid_grand_total' => $summary['paid_grand_total'],
                    'outgoing_tax_grand_total' => $summary['outgoing_tax_grand_total'],
                    'winner_receives_grand_total' => $summary['winner_receives_grand_total'],
                    'player_count' => $summary['player_count'],
                    'eligible_count' => $summary['eligible_count'],
                    'record_count' => $summary['record_count'],
                    'disbursement_count' => $summary['disbursement_count'],
                ],
                'fund_breakdown' => $breakdown,
                'settlement' => is_array($settlementRecord) ? $settlementRecord : null,
                'archived_at' => gmdate('c'),
                'revision_log' => [],
            ];

            // Solo se reinician los datos del evento. La reserva acumulada, el
            // libro mayor y los comunicados continúan intactos.
            $preserved = $data['settings'];
            $preserved['event_name'] = $nextEventName;
            $preserved['event_status'] = 'active';
            $preserved['deadline'] = null;
            $preserved['starts_at'] = null;
            $preserved['prize'] = '';
            $preserved['winner_name'] = '';
            $preserved['current_event_id'] = new_id('evt');
            $preserved['settlement_status'] = 'open';
            $preserved['frozen_at'] = null;
            $preserved['reserve_draw'] = array_fill_keys(resource_keys(), 0);
            $preserved['updated_at'] = gmdate('c');
            $data['settings'] = settings_normalize($preserved);
            $data['winners'] = [];
            $data['players'] = [];
            $data['contributions'] = [];
            $data['disbursements'] = [];

            // Los aportes tardíos asignados al evento siguiente entran ahora.
            $pending = (array) $data['pending_contributions'];
            $data['pending_contributions'] = [];
            foreach ($pending as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $record = contribution_record_from_values($data, [
                    'player_id' => '',
                    'player_name' => (string) $row['player_name'],
                    'note' => (string) ($row['note'] ?? ''),
                    'food' => (int) $row['food'],
                    'wood' => (int) $row['wood'],
                    'stone' => (int) $row['stone'],
                    'gold' => (int) $row['gold'],
                ]);
                $record['created_at'] = (string) $row['created_at'];
                $data['contributions'][] = $record;
                audit_append($data, 'add', 'contribution', (string) $record['id'],
                    'Aporte tardío de ' . (string) $row['player_name'] . ' incorporado al evento nuevo.');
            }
        }, true, 'archive-event', true);
    } catch (InvalidArgumentException $exception) {
        return [$exception->getMessage()];
    }
    return [];
}

function all_archives(): array
{
    $data = database_read(true);
    $archives = archives_normalize_with_audit((array) $data['events_archive'], (array) $data['audit_log']);
    usort($archives, function (array $left, array $right): int {
        return strcmp((string) ($right['archived_at'] ?? ''), (string) ($left['archived_at'] ?? ''));
    });
    return $archives;
}

function find_archive(string $id): ?array
{
    foreach (all_archives() as $archive) {
        if (hash_equals((string) $archive['id'], $id)) {
            return $archive;
        }
    }
    return null;
}

function archive_revision_reason(string $reason): array
{
    $reason = trim($reason);
    $length = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
    if ($length < 8 || $length > 500) {
        return ['error' => 'Explica el motivo de la corrección con entre 8 y 500 caracteres.', 'value' => $reason];
    }
    return ['error' => null, 'value' => $reason];
}

function archive_revision_append(array &$archive, string $reason, string $description): void
{
    if (!isset($archive['revision_log']) || !is_array($archive['revision_log'])) {
        $archive['revision_log'] = [];
    }
    $archive['revision_log'][] = [
        'id' => new_id('rev'),
        'description' => $description,
        'reason' => $reason,
        'created_at' => gmdate('c'),
    ];
}

function financial_integrity_errors(array $data): array
{
    $received = array_fill_keys(resource_keys(), 0);
    $paid = array_fill_keys(resource_keys(), 0);
    foreach ((array) ($data['contributions'] ?? []) as $record) {
        if (!is_array($record)) {
            continue;
        }
        foreach (resource_keys() as $resource) {
            $received[$resource] += max(0, (int) ($record[$resource] ?? 0));
        }
    }
    foreach ((array) ($data['disbursements'] ?? []) as $record) {
        if (!is_array($record)) {
            continue;
        }
        foreach (resource_keys() as $resource) {
            $paid[$resource] += max(0, (int) ($record[$resource] ?? 0));
        }
    }
    // El banco puede gastar los aportes del evento y, además, la reserva
    // anterior que el administrador haya autorizado expresamente para él.
    $draw = array_fill_keys(resource_keys(), 0);
    if (isset($data['settings']['reserve_draw']) && is_array($data['settings']['reserve_draw'])) {
        foreach (resource_keys() as $resource) {
            $draw[$resource] = max(0, (int) ($data['settings']['reserve_draw'][$resource] ?? 0));
        }
    }
    $errors = [];
    foreach (resource_keys() as $resource) {
        $spendable = $received[$resource] + $draw[$resource];
        if ($paid[$resource] > $spendable) {
            $errors[] = 'Las entregas de ' . resource_label($resource) . ' (' . format_integer($paid[$resource])
                . ') no pueden superar lo que el banco puede gastar en el evento (' . format_integer($spendable) . ').';
        }
    }
    return $errors;
}

function archive_financial_errors(array $archive): array
{
    return financial_integrity_errors(archive_normalize($archive));
}

function update_archived_event(string $archiveId, array $source): array
{
    $reasonResult = archive_revision_reason((string) ($source['correction_reason'] ?? ''));
    $errors = $reasonResult['error'] === null ? [] : [(string) $reasonResult['error']];
    $eventName = trim((string) ($source['event_name'] ?? ''));
    $prize = trim((string) ($source['prize'] ?? ''));
    if ($eventName === '' || (function_exists('mb_strlen') ? mb_strlen($eventName, 'UTF-8') : strlen($eventName)) > 100) {
        $errors[] = 'El nombre del evento debe tener entre 1 y 100 caracteres.';
    }
    if ((function_exists('mb_strlen') ? mb_strlen($prize, 'UTF-8') : strlen($prize)) > 220) {
        $errors[] = 'La descripción del premio no puede superar 220 caracteres.';
    }
    $historicMetric = text_limit((string) ($source['score_metric_label'] ?? ''), 120);
    if ($historicMetric === '') {
        $historicMetric = 'Fuertes destruidos';
    }
    $winners = [];
    foreach (reward_rank_keys() as $rank) {
        $name = trim((string) ($source['winner_' . $rank . '_name'] ?? ''));
        $score = parse_resource_amount((string) ($source['winner_' . $rank . '_score'] ?? ''));
        if (validate_player_name($name) !== null || $score === null || $score <= 0) {
            $errors[] = 'Revisa el nombre y la puntuación de ' . reward_rank_label($rank) . '.';
            continue;
        }
        $winners[] = ['rank' => $rank, 'player_name' => $name, 'score' => $score, 'metric' => $historicMetric, 'note' => ''];
    }
    if (count($winners) !== 3) {
        $errors[] = 'El historial debe conservar los tres puestos.';
    }
    if ($errors !== []) {
        return array_values(array_unique($errors));
    }
    try {
        database_mutate(function (array &$data) use ($archiveId, $eventName, $prize, $winners, $reasonResult, $historicMetric): void {
            foreach ($data['events_archive'] as $index => $archive) {
                if (!is_array($archive) || !hash_equals((string) ($archive['id'] ?? ''), $archiveId)) {
                    continue;
                }
                $archive = archive_normalize($archive);
                $archive['settings']['event_name'] = $eventName;
                $archive['settings']['prize'] = $prize;
                $archive['settings']['score_metric_label'] = $historicMetric;
                $archive['winners'] = winners_normalize($winners, $historicMetric);
                $archive['settings']['winner_name'] = winners_summary_text($archive['winners']);
                archive_revision_append($archive, (string) $reasonResult['value'], 'Se corrigieron los datos generales y los ganadores del evento.');
                $data['events_archive'][$index] = archive_normalize($archive);
                audit_append($data, 'update', 'event_archive', $archiveId, 'Se corrigieron los datos del evento archivado ' . $eventName . '.');
                return;
            }
            throw new InvalidArgumentException('No se encontró el evento archivado.');
        }, true, 'update-archive-event', true, [$archiveId]);
    } catch (InvalidArgumentException $exception) {
        return [$exception->getMessage()];
    }
    return [];
}

function update_archived_contribution(string $archiveId, string $recordId, array $source): array
{
    $reasonResult = archive_revision_reason((string) ($source['correction_reason'] ?? ''));
    $errors = $reasonResult['error'] === null ? [] : [(string) $reasonResult['error']];
    $playerName = trim((string) ($source['player_name'] ?? ''));
    $nameError = validate_player_name($playerName);
    if ($nameError !== null) {
        $errors[] = $nameError;
    }
    $amounts = [];
    foreach (resource_keys() as $resource) {
        $amount = parse_resource_amount((string) ($source[$resource] ?? ''));
        if ($amount === null) {
            $errors[] = 'La cantidad de ' . resource_label($resource) . ' no es válida.';
            $amount = 0;
        }
        $amounts[$resource] = $amount;
    }
    if ($errors !== []) {
        return array_values(array_unique($errors));
    }
    try {
        database_mutate(function (array &$data) use ($archiveId, $recordId, $source, $amounts, $playerName, $reasonResult): void {
            foreach ($data['events_archive'] as $archiveIndex => $rawArchive) {
                if (!is_array($rawArchive) || !hash_equals((string) ($rawArchive['id'] ?? ''), $archiveId)) {
                    continue;
                }
                $archive = archive_normalize($rawArchive);
                foreach ($archive['contributions'] as $recordIndex => $record) {
                    if (!hash_equals((string) ($record['id'] ?? ''), $recordId)) {
                        continue;
                    }
                    $playerId = (string) ($record['player_id'] ?? '');
                    $newKey = normalize_player_name($playerName);
                    foreach ($archive['players'] as $playerIndex => $player) {
                        if ((string) ($player['id'] ?? '') !== $playerId && normalize_player_name((string) ($player['display_name'] ?? '')) === $newKey) {
                            throw new InvalidArgumentException('Ya existe otro participante histórico con ese nombre.');
                        }
                        if ((string) ($player['id'] ?? '') === $playerId) {
                            $archive['players'][$playerIndex]['display_name'] = $playerName;
                            $archive['players'][$playerIndex]['match_key'] = $newKey;
                            $archive['players'][$playerIndex]['updated_at'] = gmdate('c');
                        }
                    }
                    foreach ($archive['contributions'] as $samePlayerIndex => $samePlayerRecord) {
                        if ((string) ($samePlayerRecord['player_id'] ?? '') === $playerId) {
                            $archive['contributions'][$samePlayerIndex]['player_name_snapshot'] = $playerName;
                        }
                    }
                    foreach (resource_keys() as $resource) {
                        $archive['contributions'][$recordIndex][$resource] = $amounts[$resource];
                    }
                    $archive['contributions'][$recordIndex]['note'] = trim((string) ($source['note'] ?? ''));
                    $archive['contributions'][$recordIndex]['updated_at'] = gmdate('c');
                    $financialErrors = archive_financial_errors($archive);
                    if ($financialErrors !== []) {
                        throw new InvalidArgumentException(implode(' ', $financialErrors));
                    }
                    archive_revision_append($archive, (string) $reasonResult['value'], 'Se corrigió un aporte histórico de ' . $playerName . '.');
                    $data['events_archive'][$archiveIndex] = archive_normalize($archive);
                    audit_append($data, 'update', 'archive_contribution', $recordId, 'Se corrigió un aporte dentro de un evento archivado.');
                    return;
                }
                throw new InvalidArgumentException('No se encontró el aporte histórico.');
            }
            throw new InvalidArgumentException('No se encontró el evento archivado.');
        }, true, 'update-archive-contribution', true, [$archiveId]);
    } catch (InvalidArgumentException $exception) {
        return [$exception->getMessage()];
    }
    return [];
}

function update_archived_disbursement(string $archiveId, string $recordId, array $source): array
{
    $reasonResult = archive_revision_reason((string) ($source['correction_reason'] ?? ''));
    $input = disbursement_input($source);
    $errors = $input['errors'];
    if (!disbursement_has_exact_receipt($input['values'])) {
        $errors[] = 'Un evento cerrado debe conservar la cantidad exacta que recibió el ganador en cada recurso enviado.';
    }
    if ($reasonResult['error'] !== null) {
        $errors[] = (string) $reasonResult['error'];
    }
    if ($errors !== []) {
        return array_values(array_unique($errors));
    }
    try {
        database_mutate(function (array &$data) use ($archiveId, $recordId, $input, $reasonResult): void {
            foreach ($data['events_archive'] as $archiveIndex => $rawArchive) {
                if (!is_array($rawArchive) || !hash_equals((string) ($rawArchive['id'] ?? ''), $archiveId)) {
                    continue;
                }
                $archive = archive_normalize($rawArchive);
                foreach ($archive['disbursements'] as $recordIndex => $record) {
                    if (!hash_equals((string) ($record['id'] ?? ''), $recordId)) {
                        continue;
                    }
                    $values = $input['values'];
                    foreach (resource_keys() as $resource) {
                        $archive['disbursements'][$recordIndex][$resource] = (int) $values[$resource];
                    }
                    $archive['disbursements'][$recordIndex]['recipient_name'] = (string) $values['recipient_name'];
                    $archive['disbursements'][$recordIndex]['note'] = (string) $values['note'];
                    $archive['disbursements'][$recordIndex]['evidence_note'] = (string) $values['evidence_note'];
                    $archive['disbursements'][$recordIndex]['transport_count'] = (int) $values['transport_count'];
                    $archive['disbursements'][$recordIndex]['actual_received'] = $values['actual_received'];
                    $archive['disbursements'][$recordIndex]['updated_at'] = gmdate('c');
                    $financialErrors = archive_financial_errors($archive);
                    if ($financialErrors !== []) {
                        throw new InvalidArgumentException(implode(' ', $financialErrors));
                    }
                    archive_revision_append($archive, (string) $reasonResult['value'], 'Se corrigió la entrega histórica a ' . (string) $values['recipient_name'] . '.');
                    $data['events_archive'][$archiveIndex] = archive_normalize($archive);
                    audit_append($data, 'update', 'archive_disbursement', $recordId, 'Se corrigió una entrega dentro de un evento archivado.');
                    return;
                }
                throw new InvalidArgumentException('No se encontró la entrega histórica.');
            }
            throw new InvalidArgumentException('No se encontró el evento archivado.');
        }, true, 'update-archive-disbursement', true, [$archiveId]);
    } catch (InvalidArgumentException $exception) {
        return [$exception->getMessage()];
    }
    return [];
}

function outgoing_tax_amount(int $sent, float $taxRate): int
{
    $safeSent = max(0, $sent);
    $safeRate = max(0.0, min(100.0, $taxRate));
    return min($safeSent, (int) round($safeSent * ($safeRate / 100)));
}

function outgoing_recipient_amount(int $sent, float $taxRate): int
{
    $safeSent = max(0, $sent);
    return max(0, $safeSent - outgoing_tax_amount($safeSent, $taxRate));
}

function disbursement_actual_received(array $record, string $resource): int
{
    $sent = max(0, (int) ($record[$resource] ?? 0));
    $actual = isset($record['actual_received']) && is_array($record['actual_received'])
        ? ($record['actual_received'][$resource] ?? null) : null;
    if ($actual !== null && $actual !== '') {
        return min($sent, max(0, (int) $actual));
    }
    return outgoing_recipient_amount($sent, (float) ($record['tax_rate'] ?? 0));
}

function disbursement_tax_amount(array $record, string $resource): int
{
    $sent = max(0, (int) ($record[$resource] ?? 0));
    return max(0, $sent - disbursement_actual_received($record, $resource));
}

function disbursement_has_exact_receipt(array $record): bool
{
    $actual = isset($record['actual_received']) && is_array($record['actual_received'])
        ? $record['actual_received'] : [];
    foreach (resource_keys() as $resource) {
        if ((int) ($record[$resource] ?? 0) > 0 && !array_key_exists($resource, $actual)) {
            return false;
        }
        if ((int) ($record[$resource] ?? 0) > 0 && ($actual[$resource] === null || $actual[$resource] === '')) {
            return false;
        }
    }
    return true;
}

/**
 * Devuelve la cantidad mínima que debe salir del banco para que al
 * destinatario le llegue exactamente el objetivo indicado después del
 * impuesto. La búsqueda usa la misma regla de redondeo que los movimientos
 * reales, por lo que TOP 1 y TOP 2 no pierden recursos por aproximaciones.
 */
function outgoing_amount_for_exact_recipient(int $recipientTarget, float $taxRate): int
{
    $target = max(0, $recipientTarget);
    $safeRate = max(0.0, min(99.0, $taxRate));
    if ($target === 0 || $safeRate <= 0.0) {
        return $target;
    }

    $retainedFraction = 1.0 - ($safeRate / 100);
    $lower = $target;
    $upper = max($lower, (int) ceil($target / $retainedFraction) + 2);

    while ($lower < $upper) {
        $middle = $lower + intdiv($upper - $lower, 2);
        if (outgoing_recipient_amount($middle, $safeRate) >= $target) {
            $upper = $middle;
        } else {
            $lower = $middle + 1;
        }
    }

    return $lower;
}

function split_amount_by_reward_weights(int $amount, array $weights): array
{
    $amount = max(0, $amount);
    $shares = reward_weight_percentages($weights);
    $allocations = array_fill_keys(reward_rank_keys(), 0);
    $remainders = [];
    $allocated = 0;

    foreach (reward_rank_keys() as $rank) {
        $raw = $amount * (float) ($shares[$rank] ?? 0);
        $whole = (int) floor($raw);
        $allocations[$rank] = $whole;
        $remainders[$rank] = $raw - $whole;
        $allocated += $whole;
    }

    $rankOrder = reward_rank_keys();
    usort($rankOrder, function (string $left, string $right) use ($remainders): int {
        $comparison = (float) $remainders[$right] <=> (float) $remainders[$left];
        if ($comparison !== 0) {
            return $comparison;
        }
        return array_search($left, reward_rank_keys(), true) <=> array_search($right, reward_rank_keys(), true);
    });
    $remaining = $amount - $allocated;
    for ($index = 0; $index < $remaining; $index++) {
        $rank = $rankOrder[$index % count($rankOrder)];
        $allocations[$rank]++;
    }
    return $allocations;
}


/* =========================================================================
 * Libro mayor de reservas y contabilidad por recurso
 * =========================================================================
 * Toda la contabilidad se realiza por separado para comida, madera, piedra y
 * oro. Nunca se compensa el faltante de un recurso con el sobrante de otro.
 *
 * Por cada recurso:
 *   R_apertura  reserva acumulada disponible al comenzar el evento
 *   C_total     todos los aportes registrados durante el evento
 *   C_elegible  aportes que cumplen las reglas del fondo elegible
 *   p           porcentaje del fondo destinado a premios (puntos básicos)
 *   P_evento    floor(C_elegible x p)
 *   D_reserva   cantidad autorizada que se toma de reservas anteriores
 *   P_total     P_evento + D_reserva
 *   R_cierre    R_apertura - D_reserva + C_total - P_evento + correcciones
 *
 * El residuo del redondeo nunca se gasta: queda dentro de R_cierre.
 * ========================================================================= */

function event_current_id(array $settings): string
{
    $eventId = trim((string) ($settings['current_event_id'] ?? ''));
    if ($eventId !== '') {
        return $eventId;
    }
    return stable_id('evt', 'current|' . (string) ($settings['event_name'] ?? ''));
}

/**
 * Saldo del libro mayor de reservas. Con $excludeEventId se obtiene la
 * reserva de apertura del evento indicado: la que existía antes de contabilizar
 * cualquier movimiento propio de ese evento.
 */
function reserve_balances_from_movements(array $movements, ?string $excludeEventId = null, ?array $onlyTypes = null): array
{
    $balances = array_fill_keys(resource_keys(), 0);
    foreach ($movements as $movement) {
        if (!is_array($movement)) {
            continue;
        }
        $resource = (string) ($movement['resource_type'] ?? '');
        if (!isset($balances[$resource])) {
            continue;
        }
        $eventId = (string) ($movement['event_id'] ?? '');
        if ($excludeEventId !== null && $excludeEventId !== '' && $eventId === $excludeEventId) {
            continue;
        }
        if ($onlyTypes !== null && !in_array((string) ($movement['movement_type'] ?? ''), $onlyTypes, true)) {
            continue;
        }
        $balances[$resource] += (int) ($movement['amount_signed'] ?? 0);
    }
    return $balances;
}

function reserve_event_movements(array $movements, string $eventId, ?array $onlyTypes = null): array
{
    $totals = array_fill_keys(resource_keys(), 0);
    if ($eventId === '') {
        return $totals;
    }
    foreach ($movements as $movement) {
        if (!is_array($movement) || (string) ($movement['event_id'] ?? '') !== $eventId) {
            continue;
        }
        $resource = (string) ($movement['resource_type'] ?? '');
        if (!isset($totals[$resource])) {
            continue;
        }
        if ($onlyTypes !== null && !in_array((string) ($movement['movement_type'] ?? ''), $onlyTypes, true)) {
            continue;
        }
        $totals[$resource] += (int) ($movement['amount_signed'] ?? 0);
    }
    return $totals;
}

function reserve_balances(): array
{
    $data = database_read();
    return reserve_balances_from_movements((array) $data['reserve_movements']);
}

function reserve_has_dedupe_key(array $data, string $dedupeKey): bool
{
    foreach ((array) ($data['reserve_movements'] ?? []) as $movement) {
        if (is_array($movement) && (string) ($movement['dedupe_key'] ?? '') === $dedupeKey && $dedupeKey !== '') {
            return true;
        }
    }
    return false;
}

/**
 * Anexa un movimiento al libro mayor. Si el movimiento trae una clave de
 * deduplicación que ya existe, no se contabiliza otra vez: así cerrar o
 * reintentar el cierre de un evento nunca duplica la reserva.
 */
function reserve_movement_append(array &$data, string $eventId, string $resource, string $type, int $amountSigned, string $note, ?string $dedupeKey = null, string $auditReference = ''): bool
{
    if (!in_array($resource, resource_keys(), true) || !in_array($type, reserve_movement_types(), true)) {
        throw new InvalidArgumentException('Movimiento de reserva no válido.');
    }
    if ($dedupeKey !== null && $dedupeKey !== '' && reserve_has_dedupe_key($data, $dedupeKey)) {
        return false;
    }
    if ($amountSigned === 0) {
        return false;
    }
    $data['reserve_movements'][] = [
        'id' => new_id('rsv'),
        'event_id' => $eventId,
        'resource_type' => $resource,
        'movement_type' => $type,
        'amount_signed' => $amountSigned,
        'note' => text_limit($note, 500),
        'created_by' => 'admin',
        'created_at' => gmdate('c'),
        'audit_reference' => $auditReference,
        'dedupe_key' => $dedupeKey === '' ? null : $dedupeKey,
    ];
    return true;
}

/**
 * Desglose contable completo del evento actual, recurso por recurso.
 */
function event_fund_breakdown(array $data, array $eligibleReceived, array $paid, array $balance): array
{
    $settings = $data['settings'];
    $eventId = event_current_id($settings);
    $movements = (array) ($data['reserve_movements'] ?? []);
    $opening = reserve_balances_from_movements($movements, $eventId);
    $corrections = reserve_event_movements($movements, $eventId, ['correction']);
    $alreadyBooked = reserve_event_movements($movements, $eventId, ['event_retained', 'event_release']);
    $points = (int) $settings['prize_pool_basis_points'];

    $contributed = array_fill_keys(resource_keys(), 0);
    foreach ((array) ($data['contributions'] ?? []) as $record) {
        if (!is_array($record)) {
            continue;
        }
        foreach (resource_keys() as $resource) {
            $contributed[$resource] += max(0, (int) ($record[$resource] ?? 0));
        }
    }

    $breakdown = [
        'event_id' => $eventId,
        'basis_points' => $points,
        'reserve_opening' => [],
        'reserve_corrections' => $corrections,
        'reserve_event_booked' => $alreadyBooked,
        'contributed_total' => $contributed,
        'eligible_total' => [],
        'pending_unqualified' => [],
        'prize_from_event' => [],
        'retained_from_eligible' => [],
        'reserve_draw' => [],
        'reserve_draw_requested' => [],
        'prize_fund_gross' => [],
        'reserve_closing' => [],
        'physical_total' => [],
        'variance' => [],
    ];

    foreach (resource_keys() as $resource) {
        $openingAmount = max(0, (int) $opening[$resource]);
        $total = (int) $contributed[$resource];
        $eligible = max(0, (int) ($eligibleReceived[$resource] ?? 0));
        $requestedDraw = max(0, (int) $settings['reserve_draw'][$resource]);
        $draw = min($requestedDraw, $openingAmount);
        $prizeFromEvent = apply_basis_points($eligible, $points);
        $breakdown['reserve_opening'][$resource] = $openingAmount;
        $breakdown['eligible_total'][$resource] = $eligible;
        $breakdown['pending_unqualified'][$resource] = max(0, $total - $eligible);
        $breakdown['prize_from_event'][$resource] = $prizeFromEvent;
        $breakdown['retained_from_eligible'][$resource] = max(0, $eligible - $prizeFromEvent);
        $breakdown['reserve_draw_requested'][$resource] = $requestedDraw;
        $breakdown['reserve_draw'][$resource] = $draw;
        $breakdown['prize_fund_gross'][$resource] = $prizeFromEvent + $draw;
        $breakdown['reserve_closing'][$resource] = $openingAmount - $draw + $total - $prizeFromEvent + (int) $corrections[$resource];
        $breakdown['physical_total'][$resource] = $openingAmount + $total - max(0, (int) ($paid[$resource] ?? 0));
        $breakdown['variance'][$resource] = $breakdown['physical_total'][$resource] - $breakdown['reserve_closing'][$resource];
    }

    foreach (['reserve_opening', 'contributed_total', 'eligible_total', 'pending_unqualified', 'prize_from_event',
        'retained_from_eligible', 'reserve_draw', 'prize_fund_gross', 'reserve_closing', 'physical_total'] as $key) {
        $breakdown[$key . '_grand'] = array_sum($breakdown[$key]);
    }
    $breakdown['reserve_draw_exceeds_balance'] = $breakdown['reserve_draw'] !== $breakdown['reserve_draw_requested'];
    return $breakdown;
}

function reward_projection_from_data(array $data, array $playerList, array $paid, array $balance): array
{
    $settings = $data['settings'];
    $eligibleIds = [];
    foreach ($playerList as $player) {
        if (!empty($player['eligible'])) {
            $eligibleIds[(string) $player['id']] = true;
        }
    }

    $eligibleReceived = array_fill_keys(resource_keys(), 0);
    foreach ($data['contributions'] as $record) {
        if (!isset($eligibleIds[(string) ($record['player_id'] ?? '')])) {
            continue;
        }
        foreach (resource_keys() as $resource) {
            $eligibleReceived[$resource] += max(0, (int) ($record[$resource] ?? 0));
        }
    }

    // El fondo de premios ya no es el saldo completo: primero se aplica el
    // porcentaje configurado sobre el fondo elegible y después se suma la
    // reserva anterior que el administrador haya autorizado para este evento.
    $breakdown = event_fund_breakdown($data, $eligibleReceived, $paid, $balance);
    $eligibleBalance = [];
    $waitingBalance = [];
    foreach (resource_keys() as $resource) {
        // Las salidas ya registradas reducen primero el fondo habilitado. El
        // resultado nunca puede superar lo que el banco puede gastar: su saldo
        // físico del evento más la reserva autorizada.
        $afterPayouts = max(0, (int) $breakdown['prize_fund_gross'][$resource] - (int) ($paid[$resource] ?? 0));
        $spendable = (int) ($balance[$resource] ?? 0) + (int) $breakdown['reserve_draw'][$resource];
        $eligibleBalance[$resource] = min($spendable, $afterPayouts);
        $waitingBalance[$resource] = max(0, (int) ($balance[$resource] ?? 0) - $eligibleBalance[$resource]);
    }

    $weights = (array) $settings['reward_weights'];
    $shares = reward_weight_percentages($weights);
    $taxRate = (float) $settings['tax_rate'];
    $capacity = max(1, (int) $settings['transport_capacity']);
    $prizes = [];
    foreach (reward_rank_keys() as $rank) {
        $prizes[$rank] = [
            'rank' => $rank,
            'label' => reward_rank_label($rank),
            'weight' => (float) ($weights[$rank] ?? 0),
            'share' => (float) ($shares[$rank] ?? 0),
            'is_net_guaranteed' => $rank !== 'third',
            'nominal' => array_fill_keys(resource_keys(), 0),
            'sent' => array_fill_keys(resource_keys(), 0),
            'tax' => array_fill_keys(resource_keys(), 0),
            'received' => array_fill_keys(resource_keys(), 0),
            'absorbed_tax' => array_fill_keys(resource_keys(), 0),
            'guarantee_shortfall' => array_fill_keys(resource_keys(), 0),
            'nominal_total' => 0,
            'sent_total' => 0,
            'tax_total' => 0,
            'received_total' => 0,
            'absorbed_tax_total' => 0,
            'guarantee_shortfall_total' => 0,
            'trips' => 0,
        ];
    }

    foreach (resource_keys() as $resource) {
        $available = (int) $eligibleBalance[$resource];
        $split = split_amount_by_reward_weights($available, $weights);
        foreach (reward_rank_keys() as $rank) {
            $nominal = (int) $split[$rank];
            $prizes[$rank]['nominal'][$resource] = $nominal;
            $prizes[$rank]['nominal_total'] += $nominal;
        }

        // TOP 1 y TOP 2 son objetivos netos garantizados. El banco añade la
        // cantidad necesaria para compensar lo que Lilith retendrá.
        $remaining = $available;
        foreach (['first', 'second'] as $rank) {
            $target = (int) $split[$rank];
            $required = outgoing_amount_for_exact_recipient($target, $taxRate);
            $sent = min($remaining, $required);
            $tax = outgoing_tax_amount($sent, $taxRate);
            $receivedByWinner = max(0, $sent - $tax);
            $prizes[$rank]['sent'][$resource] = $sent;
            $prizes[$rank]['tax'][$resource] = $tax;
            $prizes[$rank]['received'][$resource] = $receivedByWinner;
            $prizes[$rank]['guarantee_shortfall'][$resource] = max(0, $target - $receivedByWinner);
            $prizes[$rank]['sent_total'] += $sent;
            $prizes[$rank]['tax_total'] += $tax;
            $prizes[$rank]['received_total'] += $receivedByWinner;
            $prizes[$rank]['guarantee_shortfall_total'] += $prizes[$rank]['guarantee_shortfall'][$resource];
            $remaining -= $sent;
        }

        // TOP 3 recibe todo el remanente físico. Su diferencia frente a la
        // parte nominal equivale a los impuestos de los tres premios.
        $thirdSent = max(0, $remaining);
        $thirdTax = outgoing_tax_amount($thirdSent, $taxRate);
        $thirdReceived = max(0, $thirdSent - $thirdTax);
        $prizes['third']['sent'][$resource] = $thirdSent;
        $prizes['third']['tax'][$resource] = $thirdTax;
        $prizes['third']['received'][$resource] = $thirdReceived;
        $prizes['third']['absorbed_tax'][$resource] = max(0, (int) $split['third'] - $thirdReceived);
        $prizes['third']['sent_total'] += $thirdSent;
        $prizes['third']['tax_total'] += $thirdTax;
        $prizes['third']['received_total'] += $thirdReceived;
        $prizes['third']['absorbed_tax_total'] += $prizes['third']['absorbed_tax'][$resource];
    }

    $guaranteesFunded = true;
    $projectedTaxTotal = 0;
    $projectedWinnerTotal = 0;
    foreach (reward_rank_keys() as $rank) {
        $sentTotal = (int) $prizes[$rank]['sent_total'];
        $prizes[$rank]['trips'] = $sentTotal > 0 ? (int) ceil($sentTotal / $capacity) : 0;
        $projectedTaxTotal += (int) $prizes[$rank]['tax_total'];
        $projectedWinnerTotal += (int) $prizes[$rank]['received_total'];
        if ((int) $prizes[$rank]['guarantee_shortfall_total'] > 0) {
            $guaranteesFunded = false;
        }
    }

    $eligibleTotal = array_sum($eligibleBalance);
    $effectiveShares = array_fill_keys(reward_rank_keys(), 0.0);
    foreach (reward_rank_keys() as $rank) {
        $effectiveShares[$rank] = $eligibleTotal > 0
            ? (float) $prizes[$rank]['received_total'] / $eligibleTotal
            : ($rank === 'third'
                ? max(0.0, (float) ($shares['third'] ?? 0) - ($taxRate / 100))
                : (float) ($shares[$rank] ?? 0));
    }

    return [
        'eligible_count' => count($eligibleIds),
        'ready_for_three_places' => count($eligibleIds) >= 3,
        'breakdown' => $breakdown,
        'basis_points' => (int) $breakdown['basis_points'],
        'eligible_received' => $eligibleReceived,
        'eligible_balance' => $eligibleBalance,
        'waiting_balance' => $waitingBalance,
        'eligible_total' => $eligibleTotal,
        'waiting_total' => array_sum($waitingBalance),
        'tax_rate' => $taxRate,
        'transport_capacity' => $capacity,
        'weights' => $weights,
        'shares' => $shares,
        'effective_shares' => $effectiveShares,
        'guarantees_funded' => $guaranteesFunded,
        'projected_tax_total' => $projectedTaxTotal,
        'projected_winner_total' => $projectedWinnerTotal,
        'prizes' => $prizes,
    ];
}

function progress_percent(int $amount, int $threshold): int
{
    if ($threshold <= 0) {
        return 100;
    }
    return max(0, min(100, (int) floor(($amount / $threshold) * 100)));
}

function bank_summary_from_data(array $data): array
{
    $data = database_normalize($data);
    $settings = $data['settings'];
    $received = array_fill_keys(resource_keys(), 0);
    $paid = array_fill_keys(resource_keys(), 0);
    $outgoingTax = array_fill_keys(resource_keys(), 0);
    $winnerReceives = array_fill_keys(resource_keys(), 0);
    $players = [];
    $lastUpdated = $settings['updated_at'];
    foreach ($data['contributions'] as $record) {
        $playerId = (string) $record['player_id'];
        if (!isset($players[$playerId])) {
            $players[$playerId] = [
                'id' => $playerId,
                'name' => player_name_from_data($data, $playerId),
                'records' => 0,
                'latest_at' => null,
                'history' => [],
            ];
            foreach (resource_keys() as $resource) {
                $players[$playerId][$resource] = 0;
            }
        }
        $players[$playerId]['records']++;
        $players[$playerId]['latest_at'] = max((string) ($players[$playerId]['latest_at'] ?? ''), (string) $record['created_at']);
        $players[$playerId]['history'][] = $record;
        foreach (resource_keys() as $resource) {
            $amount = max(0, (int) $record[$resource]);
            $received[$resource] += $amount;
            $players[$playerId][$resource] += $amount;
        }
        $lastUpdated = max((string) ($lastUpdated ?? ''), (string) ($record['updated_at'] ?? ''), (string) $record['created_at']);
    }
    foreach ($data['disbursements'] as $record) {
        foreach (resource_keys() as $resource) {
            $sent = max(0, (int) $record[$resource]);
            $paid[$resource] += $sent;
            $outgoingTax[$resource] += disbursement_tax_amount($record, $resource);
            $winnerReceives[$resource] += disbursement_actual_received($record, $resource);
        }
        $lastUpdated = max((string) ($lastUpdated ?? ''), (string) $record['created_at']);
    }
    $balance = [];
    foreach (resource_keys() as $resource) {
        $balance[$resource] = max(0, $received[$resource] - $paid[$resource]);
    }
    foreach ($players as &$player) {
        $player['remaining'] = [];
        $player['completed_resources'] = 0;
        $progressSum = 0;
        foreach (resource_keys() as $resource) {
            $comparison = (int) $player[$resource];
            $target = (int) $settings['thresholds'][$resource];
            $remaining = max(0, $target - $comparison);
            $player['remaining'][$resource] = $remaining;
            if ($remaining === 0) {
                $player['completed_resources']++;
            }
            $progressSum += min(100, progress_percent($comparison, $target));
        }
        $player['eligible'] = $player['completed_resources'] === 4;
        $player['overall_percent'] = (int) floor($progressSum / 4);
        usort($player['history'], function (array $left, array $right): int {
            return strcmp((string) $right['created_at'], (string) $left['created_at']);
        });
    }
    unset($player);
    $playerList = array_values($players);
    usort($playerList, function (array $left, array $right): int {
        if ((bool) $left['eligible'] !== (bool) $right['eligible']) {
            return $left['eligible'] ? -1 : 1;
        }
        if ((int) $left['overall_percent'] !== (int) $right['overall_percent']) {
            return (int) $right['overall_percent'] <=> (int) $left['overall_percent'];
        }
        return strcasecmp((string) $left['name'], (string) $right['name']);
    });
    $eligibleCount = count(array_filter($playerList, function (array $player): bool {
        return (bool) $player['eligible'];
    }));
    $rewardProjection = reward_projection_from_data($data, $playerList, $paid, $balance);
    return [
        'settings' => $settings,
        'received' => $received,
        'paid' => $paid,
        'balance' => $balance,
        'totals' => $balance,
        'outgoing_tax' => $outgoingTax,
        'winner_receives' => $winnerReceives,
        'grand_total' => array_sum($balance),
        'received_grand_total' => array_sum($received),
        'paid_grand_total' => array_sum($paid),
        'outgoing_tax_grand_total' => array_sum($outgoingTax),
        'winner_receives_grand_total' => array_sum($winnerReceives),
        'player_count' => count($playerList),
        'eligible_count' => $eligibleCount,
        'record_count' => count($data['contributions']),
        'disbursement_count' => count($data['disbursements']),
        'reward_projection' => $rewardProjection,
        'fund_breakdown' => $rewardProjection['breakdown'],
        'reserve_opening' => $rewardProjection['breakdown']['reserve_opening'],
        'reserve_closing' => $rewardProjection['breakdown']['reserve_closing'],
        'players' => $playerList,
        'last_updated' => $lastUpdated ?: null,
    ];
}

function bank_summary(?array $contributions = null): array
{
    if ($contributions === null) {
        return bank_summary_from_data(database_read());
    }
    $data = database_read();
    $data['contributions'] = $contributions;
    return bank_summary_from_data($data);
}

function public_activity(): array
{
    $activity = [];
    foreach (all_contributions() as $record) {
        $record['type'] = 'in';
        $activity[] = $record;
    }
    foreach (all_disbursements() as $record) {
        $record['type'] = 'out';
        $record['player_name'] = (string) $record['recipient_name'];
        $activity[] = $record;
    }
    usort($activity, function (array $left, array $right): int {
        return strcmp((string) $right['created_at'], (string) $left['created_at']);
    });
    return $activity;
}

function all_audit_entries(): array
{
    $entries = database_read()['audit_log'];
    usort($entries, function (array $left, array $right): int {
        return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
    });
    return $entries;
}

function format_integer(int $amount): string
{
    return number_format($amount, 0, ',', '.');
}

function format_compact_amount(int $amount): string
{
    $absolute = abs($amount);
    $units = [1000000000 => 'B', 1000000 => 'M', 1000 => 'K'];
    foreach ($units as $divider => $suffix) {
        if ($absolute >= $divider) {
            $number = $amount / $divider;
            $numberAbsolute = abs($number);
            $decimals = $numberAbsolute >= 100 ? 0 : ($numberAbsolute >= 10 ? 1 : 2);
            if ($numberAbsolute >= 999 && $numberAbsolute < 1000) {
                $decimals = 1;
            }
            $formatted = number_format($number, $decimals, ',', '');
            if (strpos($formatted, ',') !== false) {
                $formatted = rtrim(rtrim($formatted, '0'), ',');
            }
            return $formatted . $suffix;
        }
    }
    return format_integer($amount);
}

function format_datetime(?string $iso): string
{
    if ($iso === null || trim($iso) === '') {
        return 'Sin movimientos';
    }
    try {
        $date = (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone((string) app_config('timezone')));
        return $date->format('d/m/Y · g:i A');
    } catch (Throwable $exception) {
        return 'Fecha no disponible';
    }
}

function format_datetime_input(?string $iso): string
{
    if ($iso === null || trim($iso) === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone((string) app_config('timezone')))->format('Y-m-d\TH:i');
    } catch (Throwable $exception) {
        return '';
    }
}

function event_status_label(string $status): string
{
    return $status === 'closed' ? 'Evento cerrado' : 'Evento activo';
}

function qualification_basis_label(string $basis): string
{
    return 'recursos que llegaron al banco';
}

function render_error_page(string $title, string $message): void
{
    ?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?></title>
    <link rel="stylesheet" href="assets/styles.css?v=6">
</head>
<body class="auth-page">
<main class="centered-page">
    <section class="auth-card">
        <span class="eyebrow">Banco ME58</span>
        <h1><?= h($title) ?></h1>
        <p><?= h($message) ?></p>
        <a class="button button-primary" href="index.php">Volver al inicio</a>
    </section>
</main>
</body>
</html><?php
}

app_boot();
