<?php
declare(strict_types=1);

/**
 * Adaptador MySQL del banco.
 *
 * La aplicación conserva su modelo interno en arreglos para no cambiar la
 * lógica ya probada, pero cada entidad vive en su propia tabla relacional.
 * Las escrituras se serializan con una fila de bloqueo y se confirman dentro
 * de una sola transacción InnoDB.
 */

function storage_uses_mysql(): bool
{
    return strtolower((string) app_config('storage_driver')) === 'mysql';
}

function mysql_storage_config(): array
{
    $config = (array) app_config('database');
    $required = ['host', 'name', 'username', 'password', 'table_prefix'];
    foreach ($required as $key) {
        if (!array_key_exists($key, $config)) {
            throw new RuntimeException('Falta la configuración MySQL requerida: ' . $key . '.');
        }
    }
    $prefix = (string) $config['table_prefix'];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $prefix) || strpos(strtolower($prefix), 'rokbank') === false) {
        throw new RuntimeException('El prefijo de tablas debe ser seguro e incluir la palabra rokbank.');
    }
    $config['port'] = max(1, min(65535, (int) ($config['port'] ?? 3306)));
    $config['charset'] = 'utf8mb4';
    return $config;
}

function mysql_table_name(string $suffix): string
{
    if (!preg_match('/^[a-z0-9_]+$/', $suffix)) {
        throw new InvalidArgumentException('Nombre de tabla no permitido.');
    }
    $config = mysql_storage_config();
    return '`' . (string) $config['table_prefix'] . $suffix . '`';
}

function mysql_table_suffixes(): array
{
    return [
        'meta',
        'admin',
        'settings',
        'event_winners',
        'players',
        'contributions',
        'disbursements',
        'disbursement_details',
        'audit_log',
        'events_archive',
        'login_attempts',
        'backups',
        'reserve_movements',
        'settlements',
        'pending_contributions',
        'announcements',
        'announcement_media',
        'announcement_media_links',
        'announcement_revisions',
    ];
}

function mysql_connection(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }
    if (!class_exists('PDO') || !in_array('mysql', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('El hosting necesita tener habilitada la extensión PDO MySQL de PHP.');
    }
    $config = mysql_storage_config();
    $dsn = 'mysql:host=' . (string) $config['host']
        . ';port=' . (int) $config['port']
        . ';dbname=' . (string) $config['name']
        . ';charset=utf8mb4';
    try {
        $connection = new PDO($dsn, (string) $config['username'], (string) $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
        ]);
        $connection->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        mysql_ensure_schema($connection);
        return $connection;
    } catch (Throwable $exception) {
        $connection = null;
        throw new RuntimeException('No se pudo conectar o preparar la base de datos MySQL. Revisa las credenciales y los permisos del usuario de la base de datos.', 0, $exception);
    }
}

function mysql_schema_statements(): array
{
    $meta = mysql_table_name('meta');
    $admin = mysql_table_name('admin');
    $settings = mysql_table_name('settings');
    $winners = mysql_table_name('event_winners');
    $players = mysql_table_name('players');
    $contributions = mysql_table_name('contributions');
    $disbursements = mysql_table_name('disbursements');
    $disbursementDetails = mysql_table_name('disbursement_details');
    $audit = mysql_table_name('audit_log');
    $archives = mysql_table_name('events_archive');
    $attempts = mysql_table_name('login_attempts');
    $backups = mysql_table_name('backups');
    $reserveMovements = mysql_table_name('reserve_movements');
    $settlements = mysql_table_name('settlements');
    $pending = mysql_table_name('pending_contributions');
    $announcements = mysql_table_name('announcements');
    $announcementMedia = mysql_table_name('announcement_media');
    $announcementMediaLinks = mysql_table_name('announcement_media_links');
    $announcementRevisions = mysql_table_name('announcement_revisions');
    $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    return [
        "CREATE TABLE IF NOT EXISTS {$meta} (
            `meta_key` VARCHAR(100) NOT NULL,
            `meta_value` LONGTEXT NOT NULL,
            `updated_at` VARCHAR(40) NOT NULL,
            PRIMARY KEY (`meta_key`)
        ){$tail}",
        "CREATE TABLE IF NOT EXISTS {$admin} (
            `id` TINYINT UNSIGNED NOT NULL,
            `username` VARCHAR(80) NOT NULL,
            `password_hash` VARCHAR(255) NOT NULL,
            `session_version` VARCHAR(100) NOT NULL,
            `created_at` VARCHAR(40) NOT NULL,
            `password_changed_at` VARCHAR(40) NULL,
            PRIMARY KEY (`id`)
        ){$tail}",
        "CREATE TABLE IF NOT EXISTS {$settings} (
            `id` TINYINT UNSIGNED NOT NULL,
            `event_name` VARCHAR(160) NOT NULL,
            `event_status` VARCHAR(20) NOT NULL,
            `custodian_name` VARCHAR(255) NOT NULL,
            `tax_rate` DECIMAL(7,4) NOT NULL,
            `qualification_basis` VARCHAR(40) NOT NULL,
            `deadline` VARCHAR(40) NULL,
            `prize` VARCHAR(500) NOT NULL,
            `winner_name` VARCHAR(255) NOT NULL,
            `reward_weight_first` DECIMAL(16,4) NOT NULL,
            `reward_weight_second` DECIMAL(16,4) NOT NULL,
            `reward_weight_third` DECIMAL(16,4) NOT NULL,
            `transport_capacity` BIGINT UNSIGNED NOT NULL,
            `threshold_food` BIGINT UNSIGNED NOT NULL,
            `threshold_wood` BIGINT UNSIGNED NOT NULL,
            `threshold_stone` BIGINT UNSIGNED NOT NULL,
            `threshold_gold` BIGINT UNSIGNED NOT NULL,
            `updated_at` VARCHAR(40) NULL,
            PRIMARY KEY (`id`)
        ){$tail}",
        "CREATE TABLE IF NOT EXISTS {$winners} (
            `rank_key` VARCHAR(20) NOT NULL,
            `position_number` TINYINT UNSIGNED NOT NULL,
            `player_name` VARCHAR(255) NOT NULL,
            `score` BIGINT UNSIGNED NOT NULL,
            `metric` VARCHAR(120) NOT NULL,
            `note` TEXT NOT NULL,
            `updated_at` VARCHAR(40) NULL,
            PRIMARY KEY (`rank_key`),
            UNIQUE KEY `uq_rokbank_winner_position` (`position_number`)
        ){$tail}",
        "CREATE TABLE IF NOT EXISTS {$players} (
            `id` VARCHAR(64) NOT NULL,
            `display_name` VARCHAR(255) NOT NULL,
            `match_key` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            `created_at` VARCHAR(40) NOT NULL,
            `updated_at` VARCHAR(40) NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_rokbank_player_match` (`match_key`),
            KEY `idx_rokbank_player_created` (`created_at`)
        ){$tail}",
        "CREATE TABLE IF NOT EXISTS {$contributions} (
            `id` VARCHAR(64) NOT NULL,
            `player_id` VARCHAR(64) NOT NULL,
            `player_name_snapshot` VARCHAR(255) NOT NULL,
            `food` BIGINT UNSIGNED NOT NULL,
            `wood` BIGINT UNSIGNED NOT NULL,
            `stone` BIGINT UNSIGNED NOT NULL,
            `gold` BIGINT UNSIGNED NOT NULL,
            `note` TEXT NOT NULL,
            `created_at` VARCHAR(40) NOT NULL,
            `updated_at` VARCHAR(40) NULL,
            PRIMARY KEY (`id`),
            KEY `idx_rokbank_contribution_player` (`player_id`),
            KEY `idx_rokbank_contribution_created` (`created_at`)
        ){$tail}",
        "CREATE TABLE IF NOT EXISTS {$disbursements} (
            `id` VARCHAR(64) NOT NULL,
            `recipient_name` VARCHAR(255) NOT NULL,
            `food` BIGINT UNSIGNED NOT NULL,
            `wood` BIGINT UNSIGNED NOT NULL,
            `stone` BIGINT UNSIGNED NOT NULL,
            `gold` BIGINT UNSIGNED NOT NULL,
            `tax_rate` DECIMAL(7,4) NOT NULL,
            `note` TEXT NOT NULL,
            `created_at` VARCHAR(40) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_rokbank_disbursement_created` (`created_at`)
        ){$tail}",
        "CREATE TABLE IF NOT EXISTS {$disbursementDetails} (
            `disbursement_id` VARCHAR(64) NOT NULL,
            `actual_food` BIGINT UNSIGNED NULL,
            `actual_wood` BIGINT UNSIGNED NULL,
            `actual_stone` BIGINT UNSIGNED NULL,
            `actual_gold` BIGINT UNSIGNED NULL,
            `transport_count` INT UNSIGNED NOT NULL,
            `evidence_note` TEXT NOT NULL,
            `updated_at` VARCHAR(40) NULL,
            PRIMARY KEY (`disbursement_id`)
        ){$tail}",
        "CREATE TABLE IF NOT EXISTS {$audit} (
            `id` VARCHAR(64) NOT NULL,
            `action` VARCHAR(60) NOT NULL,
            `entity_type` VARCHAR(60) NOT NULL,
            `entity_id` VARCHAR(64) NOT NULL,
            `description` TEXT NOT NULL,
            `created_at` VARCHAR(40) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_rokbank_audit_created` (`created_at`)
        ){$tail}",
        "CREATE TABLE IF NOT EXISTS {$archives} (
            `id` VARCHAR(64) NOT NULL,
            `event_name` VARCHAR(160) NOT NULL,
            `archive_json` LONGTEXT NOT NULL,
            `content_hash` CHAR(64) NOT NULL,
            `archived_at` VARCHAR(40) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_rokbank_archive_date` (`archived_at`)
        ){$tail}",
        "CREATE TABLE IF NOT EXISTS {$attempts} (
            `attempt_key` CHAR(64) NOT NULL,
            `attempt_count` INT UNSIGNED NOT NULL,
            `window_started` BIGINT UNSIGNED NOT NULL,
            `locked_until` BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (`attempt_key`),
            KEY `idx_rokbank_attempt_expiry` (`locked_until`)
        ){$tail}",
        "CREATE TABLE IF NOT EXISTS {$backups} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `reason` VARCHAR(100) NOT NULL,
            `snapshot_json` LONGTEXT NOT NULL,
            `created_at` VARCHAR(40) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_rokbank_backup_created` (`created_at`)
        ){$tail}",
        "CREATE TABLE IF NOT EXISTS {$reserveMovements} (
            `id` VARCHAR(64) NOT NULL,
            `event_id` VARCHAR(64) NOT NULL DEFAULT '',
            `resource_type` VARCHAR(16) NOT NULL,
            `movement_type` VARCHAR(40) NOT NULL,
            `amount_signed` BIGINT NOT NULL,
            `note` TEXT NOT NULL,
            `created_by` VARCHAR(80) NOT NULL,
            `created_at` VARCHAR(40) NOT NULL,
            `audit_reference` VARCHAR(64) NOT NULL DEFAULT '',
            `dedupe_key` VARCHAR(191) NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_rokbank_reserve_dedupe` (`dedupe_key`),
            KEY `idx_rokbank_reserve_event` (`event_id`, `resource_type`),
            KEY `idx_rokbank_reserve_created` (`created_at`)
        ){$tail}",
        "CREATE TABLE IF NOT EXISTS {$settlements} (
            `event_id` VARCHAR(64) NOT NULL,
            `status` VARCHAR(20) NOT NULL,
            `frozen_at` VARCHAR(40) NULL,
            `unfreeze_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `snapshot_json` LONGTEXT NOT NULL,
            `updated_at` VARCHAR(40) NOT NULL,
            PRIMARY KEY (`event_id`)
        ){$tail}",
        "CREATE TABLE IF NOT EXISTS {$pending} (
            `id` VARCHAR(64) NOT NULL,
            `player_name` VARCHAR(255) NOT NULL,
            `food` BIGINT UNSIGNED NOT NULL,
            `wood` BIGINT UNSIGNED NOT NULL,
            `stone` BIGINT UNSIGNED NOT NULL,
            `gold` BIGINT UNSIGNED NOT NULL,
            `note` TEXT NOT NULL,
            `created_at` VARCHAR(40) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_rokbank_pending_created` (`created_at`)
        ){$tail}",
        "CREATE TABLE IF NOT EXISTS {$announcements} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `slug` VARCHAR(160) NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `summary` VARCHAR(600) NOT NULL DEFAULT '',
            `content_json` LONGTEXT NOT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
            `is_pinned` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `related_event_id` VARCHAR(64) NOT NULL DEFAULT '',
            `category` VARCHAR(40) NOT NULL DEFAULT 'anuncio',
            `cover_media_id` BIGINT UNSIGNED NULL,
            `published_at` VARCHAR(40) NULL,
            `created_at` VARCHAR(40) NOT NULL,
            `updated_at` VARCHAR(40) NOT NULL,
            `created_by` VARCHAR(80) NOT NULL,
            `updated_by` VARCHAR(80) NOT NULL,
            `version` INT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_rokbank_announcement_slug` (`slug`),
            KEY `idx_rokbank_announcement_status` (`status`, `published_at`),
            KEY `idx_rokbank_announcement_pinned` (`is_pinned`)
        ){$tail}",
        "CREATE TABLE IF NOT EXISTS {$announcementMedia} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `announcement_id` BIGINT UNSIGNED NULL,
            `kind` VARCHAR(20) NOT NULL,
            `storage_name` VARCHAR(80) NOT NULL,
            `original_name` VARCHAR(255) NOT NULL,
            `mime_type` VARCHAR(100) NOT NULL,
            `size_bytes` BIGINT UNSIGNED NOT NULL,
            `width` INT UNSIGNED NULL,
            `height` INT UNSIGNED NULL,
            `duration_seconds` INT UNSIGNED NULL,
            `alt_text` VARCHAR(400) NOT NULL DEFAULT '',
            `caption` VARCHAR(400) NOT NULL DEFAULT '',
            `created_by` VARCHAR(80) NOT NULL,
            `created_at` VARCHAR(40) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_rokbank_media_storage` (`storage_name`),
            KEY `idx_rokbank_media_kind` (`kind`)
        ){$tail}",
        "CREATE TABLE IF NOT EXISTS {$announcementMediaLinks} (
            `announcement_id` BIGINT UNSIGNED NOT NULL,
            `media_id` BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (`announcement_id`, `media_id`),
            KEY `idx_rokbank_media_link_media` (`media_id`)
        ){$tail}",
        "CREATE TABLE IF NOT EXISTS {$announcementRevisions} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `announcement_id` BIGINT UNSIGNED NOT NULL,
            `version` INT UNSIGNED NOT NULL,
            `snapshot_json` LONGTEXT NOT NULL,
            `change_note` VARCHAR(400) NOT NULL DEFAULT '',
            `created_by` VARCHAR(80) NOT NULL,
            `created_at` VARCHAR(40) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_rokbank_revision_announcement` (`announcement_id`, `id`)
        ){$tail}",
    ];
}

/**
 * Columnas añadidas por la versión 3 del esquema. CREATE TABLE IF NOT EXISTS
 * no agrega columnas a una tabla que ya existe, por eso cada una se comprueba
 * en INFORMATION_SCHEMA antes de ejecutar su ALTER TABLE. El paso completo es
 * reanudable: volver a ejecutarlo no duplica ni pierde nada.
 */
function mysql_schema_v3_columns(): array
{
    return [
        'settings' => [
            'current_event_id' => "ADD COLUMN `current_event_id` VARCHAR(64) NOT NULL DEFAULT ''",
            'objective_title' => "ADD COLUMN `objective_title` VARCHAR(200) NOT NULL DEFAULT ''",
            'objective_description' => "ADD COLUMN `objective_description` TEXT NULL",
            'score_metric_label' => "ADD COLUMN `score_metric_label` VARCHAR(120) NOT NULL DEFAULT ''",
            'ranking_method' => "ADD COLUMN `ranking_method` VARCHAR(20) NOT NULL DEFAULT 'highest'",
            'rank_rule_first' => "ADD COLUMN `rank_rule_first` TEXT NULL",
            'rank_rule_second' => "ADD COLUMN `rank_rule_second` TEXT NULL",
            'rank_rule_third' => "ADD COLUMN `rank_rule_third` TEXT NULL",
            'starts_at' => "ADD COLUMN `starts_at` VARCHAR(40) NULL",
            'prize_pool_basis_points' => "ADD COLUMN `prize_pool_basis_points` INT UNSIGNED NOT NULL DEFAULT 10000",
            'reserve_draw_food' => "ADD COLUMN `reserve_draw_food` BIGINT UNSIGNED NOT NULL DEFAULT 0",
            'reserve_draw_wood' => "ADD COLUMN `reserve_draw_wood` BIGINT UNSIGNED NOT NULL DEFAULT 0",
            'reserve_draw_stone' => "ADD COLUMN `reserve_draw_stone` BIGINT UNSIGNED NOT NULL DEFAULT 0",
            'reserve_draw_gold' => "ADD COLUMN `reserve_draw_gold` BIGINT UNSIGNED NOT NULL DEFAULT 0",
            'settlement_status' => "ADD COLUMN `settlement_status` VARCHAR(20) NOT NULL DEFAULT 'open'",
            'frozen_at' => "ADD COLUMN `frozen_at` VARCHAR(40) NULL",
        ],
        'event_winners' => [
            'rank_rule' => "ADD COLUMN `rank_rule` TEXT NULL",
        ],
    ];
}

function mysql_table_has_column(PDO $connection, string $suffix, string $column): bool
{
    $config = mysql_storage_config();
    $table = (string) $config['table_prefix'] . $suffix;
    $statement = $connection->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $statement->execute([(string) $config['name'], $table, $column]);
    return (int) $statement->fetchColumn() > 0;
}

function mysql_add_missing_columns(PDO $connection): int
{
    $added = 0;
    foreach (mysql_schema_v3_columns() as $suffix => $columns) {
        $clauses = [];
        foreach ($columns as $column => $clause) {
            if (!mysql_table_has_column($connection, $suffix, $column)) {
                $clauses[] = $clause;
            }
        }
        if ($clauses === []) {
            continue;
        }
        $connection->exec('ALTER TABLE ' . mysql_table_name($suffix) . ' ' . implode(', ', $clauses));
        $added += count($clauses);
    }
    return $added;
}

function mysql_ensure_schema(PDO $connection): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    foreach (mysql_schema_statements() as $statement) {
        $connection->exec($statement);
    }
    // CREATE TABLE IF NOT EXISTS no agrega columnas a una tabla que ya existe.
    // Cada columna nueva se comprueba en INFORMATION_SCHEMA antes de su ALTER.
    mysql_add_missing_columns($connection);
    mysql_initialize_storage($connection);
    if ((int) (mysql_meta_get($connection, 'schema_version') ?? 0) < 2) {
        mysql_upgrade_to_schema_v2($connection);
    }
    if ((int) (mysql_meta_get($connection, 'schema_version') ?? 0) < 3) {
        mysql_upgrade_to_schema_v3($connection);
    }
    if ((int) (mysql_meta_get($connection, 'data_version') ?? 0) < 5) {
        mysql_meta_set($connection, 'data_version', '5');
    }
    $ready = true;
}

/**
 * Convierte los archivos históricos de la primera versión al formato
 * verificable. La copia previa incluye el JSON completo de cada evento para
 * que la migración también pueda deshacerse desde el panel.
 */
function mysql_upgrade_to_schema_v2(PDO $connection): void
{
    $connection->beginTransaction();
    try {
        mysql_lock_writes($connection);
        $data = mysql_read_state($connection, true);
        $archiveIds = [];
        foreach ($data['events_archive'] as $archive) {
            if (is_array($archive) && !empty($archive['id'])) {
                $archiveIds[] = (string) $archive['id'];
            }
        }
        mysql_create_backup($connection, $data, 'before-schema-v2', true, $archiveIds);
        $data['events_archive'] = archives_normalize_with_audit($data['events_archive'], $data['audit_log']);
        $data['version'] = 5;
        mysql_write_state($connection, $data, true);
        mysql_meta_set($connection, 'schema_version', '2');
        mysql_meta_set($connection, 'data_version', '5');
        mysql_meta_set($connection, 'schema_v2_migrated_at', gmdate('c'));
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
}

/**
 * Migración aditiva a la versión 3 del esquema: configuración dinámica del
 * evento, libro mayor de reservas, liquidación congelada y comunicados.
 *
 * Es idempotente y reanudable. MySQL confirma automáticamente cada ALTER
 * TABLE, por eso la migración no depende de una única transacción: cada paso
 * comprueba primero si ya está hecho.
 *
 * No se crea ningún movimiento de reserva inicial automático: el saldo que el
 * banco tiene hoy pertenece al evento activo y ya se cuenta como aporte del
 * evento. Crearlo además como reserva de apertura lo contaría dos veces. Si
 * alguna instalación necesita migrar una reserva real, el panel permite
 * registrarla a mano como movimiento «opening_migration».
 */
function mysql_upgrade_to_schema_v3(PDO $connection): void
{
    // 1. Copia de seguridad lógica antes de tocar nada.
    $before = mysql_read_state($connection, true);
    $archiveIds = [];
    foreach ($before['events_archive'] as $archive) {
        if (is_array($archive) && !empty($archive['id'])) {
            $archiveIds[] = (string) $archive['id'];
        }
    }
    $counts = [
        'players' => count((array) $before['players']),
        'contributions' => count((array) $before['contributions']),
        'disbursements' => count((array) $before['disbursements']),
        'events_archive' => count((array) $before['events_archive']),
        'audit_log' => count((array) $before['audit_log']),
    ];
    $totals = array_fill_keys(resource_keys(), 0);
    foreach ((array) $before['contributions'] as $record) {
        foreach (resource_keys() as $resource) {
            $totals[$resource] += (int) $record[$resource];
        }
    }
    if ((int) (mysql_meta_get($connection, 'schema_v3_backup_id') ?? 0) <= 0) {
        $backupId = mysql_create_backup($connection, $before, 'before-schema-v3', true, $archiveIds);
        mysql_meta_set($connection, 'schema_v3_backup_id', (string) ((int) $backupId));
    }

    // 2. Columnas nuevas, una por una y sólo si faltan.
    mysql_add_missing_columns($connection);

    // 3. Normalización del estado actual sin borrar registros.
    $connection->beginTransaction();
    try {
        mysql_lock_writes($connection);
        $data = mysql_read_state($connection, true);
        $settings = settings_normalize($data['settings']);
        if ((string) $settings['current_event_id'] === '') {
            $settings['current_event_id'] = new_id('evt');
        }
        $data['settings'] = settings_normalize($settings);
        mysql_write_state($connection, $data, true);
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }

    // 4. Comprobación de conteos y totales antes de marcar la versión.
    $after = mysql_read_state($connection, true);
    $afterCounts = [
        'players' => count((array) $after['players']),
        'contributions' => count((array) $after['contributions']),
        'disbursements' => count((array) $after['disbursements']),
        'events_archive' => count((array) $after['events_archive']),
        'audit_log' => count((array) $after['audit_log']),
    ];
    foreach ($counts as $key => $expected) {
        if ((int) $afterCounts[$key] !== (int) $expected) {
            throw new RuntimeException(
                'La migración se detuvo: ' . $key . ' pasó de ' . $expected . ' a ' . $afterCounts[$key]
                . ' registros. No se marcó la versión nueva y la copia previa sigue disponible.'
            );
        }
    }
    $afterTotals = array_fill_keys(resource_keys(), 0);
    foreach ((array) $after['contributions'] as $record) {
        foreach (resource_keys() as $resource) {
            $afterTotals[$resource] += (int) $record[$resource];
        }
    }
    foreach (resource_keys() as $resource) {
        if ((int) $afterTotals[$resource] !== (int) $totals[$resource]) {
            throw new RuntimeException(
                'La migración se detuvo: el total de ' . resource_label($resource) . ' pasó de '
                . $totals[$resource] . ' a ' . $afterTotals[$resource] . '.'
            );
        }
    }

    // 5. Sólo ahora se marca la versión nueva.
    mysql_meta_set($connection, 'schema_version', '3');
    mysql_meta_set($connection, 'schema_v3_migrated_at', gmdate('c'));
    mysql_meta_set($connection, 'schema_v3_contribution_count', (string) $afterCounts['contributions']);
    mysql_meta_set($connection, 'schema_v3_player_count', (string) $afterCounts['players']);
    mysql_meta_set($connection, 'schema_v3_resource_total', (string) array_sum($afterTotals));
}

function mysql_meta_set(PDO $connection, string $key, string $value): void
{
    $table = mysql_table_name('meta');
    $statement = $connection->prepare(
        "INSERT INTO {$table} (`meta_key`, `meta_value`, `updated_at`) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE `meta_value` = VALUES(`meta_value`), `updated_at` = VALUES(`updated_at`)"
    );
    $statement->execute([$key, $value, gmdate('c')]);
}

function mysql_meta_get(PDO $connection, string $key): ?string
{
    $table = mysql_table_name('meta');
    $statement = $connection->prepare("SELECT `meta_value` FROM {$table} WHERE `meta_key` = ? LIMIT 1");
    $statement->execute([$key]);
    $value = $statement->fetchColumn();
    return $value === false ? null : (string) $value;
}

function mysql_read_legacy_json(): array
{
    $path = (string) app_config('storage_file');
    if (!is_file($path)) {
        return ['data' => database_default(), 'imported' => false, 'hash' => null];
    }
    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        throw new RuntimeException('El archivo de datos anterior está vacío y no se importó.');
    }
    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('El archivo de datos anterior no es JSON válido. No se modificó la base de datos.');
    }
    return [
        'data' => database_normalize($decoded),
        'imported' => true,
        'hash' => hash('sha256', $contents),
    ];
}

function mysql_existing_state_rows(PDO $connection): int
{
    $count = 0;
    foreach (['admin', 'settings', 'event_winners', 'players', 'contributions', 'disbursements', 'disbursement_details', 'audit_log', 'events_archive'] as $suffix) {
        $count += (int) $connection->query('SELECT COUNT(*) FROM ' . mysql_table_name($suffix))->fetchColumn();
    }
    return $count;
}

function mysql_initialize_storage(PDO $connection): void
{
    $meta = mysql_table_name('meta');
    $connection->beginTransaction();
    try {
        $statement = $connection->prepare(
            "INSERT IGNORE INTO {$meta} (`meta_key`, `meta_value`, `updated_at`) VALUES ('storage_initialized', '0', ?)"
        );
        $statement->execute([gmdate('c')]);
        $row = $connection->query(
            "SELECT `meta_value` FROM {$meta} WHERE `meta_key` = 'storage_initialized' FOR UPDATE"
        )->fetchColumn();
        if ((string) $row !== '1') {
            if (mysql_existing_state_rows($connection) > 0) {
                throw new RuntimeException('Las tablas rokbank ya contienen datos pero no tienen una migración válida. No se sobrescribió ninguna fila.');
            }
            $legacy = mysql_read_legacy_json();
            $legacyData = (array) $legacy['data'];
            $legacyData['events_archive'] = archives_normalize_with_audit(
                (array) ($legacyData['events_archive'] ?? []),
                (array) ($legacyData['audit_log'] ?? [])
            );
            mysql_write_state($connection, $legacyData, true);
            mysql_meta_set($connection, 'schema_version', '2');
            mysql_meta_set($connection, 'data_version', '5');
            mysql_meta_set($connection, 'legacy_imported', !empty($legacy['imported']) ? '1' : '0');
            mysql_meta_set($connection, 'legacy_imported_at', !empty($legacy['imported']) ? gmdate('c') : '');
            mysql_meta_set($connection, 'legacy_source_hash', (string) ($legacy['hash'] ?? ''));
            mysql_meta_set($connection, 'legacy_player_count', (string) count((array) ($legacyData['players'] ?? [])));
            mysql_meta_set($connection, 'legacy_contribution_count', (string) count((array) ($legacyData['contributions'] ?? [])));
            mysql_meta_set($connection, 'legacy_archive_count', (string) count((array) ($legacyData['events_archive'] ?? [])));
            mysql_meta_set($connection, 'storage_initialized', '1');
        }
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
}

function mysql_read_state(PDO $connection, bool $includeArchives = false): array
{
    $data = database_default();
    $version = mysql_meta_get($connection, 'data_version');
    $data['version'] = $version === null ? 5 : max(5, (int) $version);

    $adminTable = mysql_table_name('admin');
    $admin = $connection->query("SELECT * FROM {$adminTable} WHERE `id` = 1 LIMIT 1")->fetch();
    if (is_array($admin)) {
        $data['admin'] = [
            'username' => (string) $admin['username'],
            'password_hash' => (string) $admin['password_hash'],
            'session_version' => (string) $admin['session_version'],
            'created_at' => (string) $admin['created_at'],
            'password_changed_at' => $admin['password_changed_at'] !== null ? (string) $admin['password_changed_at'] : null,
        ];
    }

    $settingsTable = mysql_table_name('settings');
    $settings = $connection->query("SELECT * FROM {$settingsTable} WHERE `id` = 1 LIMIT 1")->fetch();
    if (is_array($settings)) {
        $data['settings'] = [
            'event_name' => (string) $settings['event_name'],
            'event_status' => (string) $settings['event_status'],
            'custodian_name' => (string) $settings['custodian_name'],
            'tax_rate' => (float) $settings['tax_rate'],
            'qualification_basis' => (string) $settings['qualification_basis'],
            'deadline' => $settings['deadline'] !== null && $settings['deadline'] !== '' ? (string) $settings['deadline'] : null,
            'prize' => (string) $settings['prize'],
            'winner_name' => (string) $settings['winner_name'],
            'reward_weights' => [
                'first' => (float) $settings['reward_weight_first'],
                'second' => (float) $settings['reward_weight_second'],
                'third' => (float) $settings['reward_weight_third'],
            ],
            'transport_capacity' => (int) $settings['transport_capacity'],
            'current_event_id' => (string) ($settings['current_event_id'] ?? ''),
            'objective_title' => (string) ($settings['objective_title'] ?? ''),
            'objective_description' => (string) ($settings['objective_description'] ?? ''),
            'score_metric_label' => (string) ($settings['score_metric_label'] ?? ''),
            'ranking_method' => (string) ($settings['ranking_method'] ?? 'highest'),
            'rank_rule_first' => (string) ($settings['rank_rule_first'] ?? ''),
            'rank_rule_second' => (string) ($settings['rank_rule_second'] ?? ''),
            'rank_rule_third' => (string) ($settings['rank_rule_third'] ?? ''),
            'starts_at' => isset($settings['starts_at']) && $settings['starts_at'] !== '' ? (string) $settings['starts_at'] : null,
            'prize_pool_basis_points' => (int) ($settings['prize_pool_basis_points'] ?? 10000),
            'reserve_draw' => [
                'food' => (int) ($settings['reserve_draw_food'] ?? 0),
                'wood' => (int) ($settings['reserve_draw_wood'] ?? 0),
                'stone' => (int) ($settings['reserve_draw_stone'] ?? 0),
                'gold' => (int) ($settings['reserve_draw_gold'] ?? 0),
            ],
            'settlement_status' => (string) ($settings['settlement_status'] ?? 'open'),
            'frozen_at' => isset($settings['frozen_at']) && $settings['frozen_at'] !== '' ? (string) $settings['frozen_at'] : null,
            'thresholds' => [
                'food' => (int) $settings['threshold_food'],
                'wood' => (int) $settings['threshold_wood'],
                'stone' => (int) $settings['threshold_stone'],
                'gold' => (int) $settings['threshold_gold'],
            ],
            'updated_at' => $settings['updated_at'] !== null && $settings['updated_at'] !== '' ? (string) $settings['updated_at'] : null,
        ];
    }

    $winnersTable = mysql_table_name('event_winners');
    foreach ($connection->query("SELECT * FROM {$winnersTable} ORDER BY `position_number`")->fetchAll() as $row) {
        $data['winners'][] = [
            'rank' => (string) $row['rank_key'],
            'position' => (int) $row['position_number'],
            'player_name' => (string) $row['player_name'],
            'score' => (int) $row['score'],
            'metric' => (string) $row['metric'],
            'rank_rule' => (string) ($row['rank_rule'] ?? ''),
            'note' => (string) $row['note'],
            'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
        ];
    }

    $playersTable = mysql_table_name('players');
    foreach ($connection->query("SELECT * FROM {$playersTable} ORDER BY `created_at`, `id`")->fetchAll() as $row) {
        $data['players'][] = [
            'id' => (string) $row['id'],
            'display_name' => (string) $row['display_name'],
            'match_key' => (string) $row['match_key'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
        ];
    }

    $contributionsTable = mysql_table_name('contributions');
    foreach ($connection->query("SELECT * FROM {$contributionsTable} ORDER BY `created_at`, `id`")->fetchAll() as $row) {
        $data['contributions'][] = [
            'id' => (string) $row['id'],
            'player_id' => (string) $row['player_id'],
            'player_name_snapshot' => (string) $row['player_name_snapshot'],
            'food' => (int) $row['food'],
            'wood' => (int) $row['wood'],
            'stone' => (int) $row['stone'],
            'gold' => (int) $row['gold'],
            'note' => (string) $row['note'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
        ];
    }

    $disbursementsTable = mysql_table_name('disbursements');
    $disbursementIndexes = [];
    foreach ($connection->query("SELECT * FROM {$disbursementsTable} ORDER BY `created_at`, `id`")->fetchAll() as $row) {
        $disbursementIndexes[(string) $row['id']] = count($data['disbursements']);
        $data['disbursements'][] = [
            'id' => (string) $row['id'],
            'recipient_name' => (string) $row['recipient_name'],
            'food' => (int) $row['food'],
            'wood' => (int) $row['wood'],
            'stone' => (int) $row['stone'],
            'gold' => (int) $row['gold'],
            'tax_rate' => (float) $row['tax_rate'],
            'note' => (string) $row['note'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => null,
            'actual_received' => array_fill_keys(resource_keys(), null),
            'transport_count' => 0,
            'evidence_note' => '',
        ];
    }

    $detailsTable = mysql_table_name('disbursement_details');
    foreach ($connection->query("SELECT * FROM {$detailsTable}")->fetchAll() as $row) {
        $id = (string) $row['disbursement_id'];
        if (!isset($disbursementIndexes[$id])) {
            continue;
        }
        $index = $disbursementIndexes[$id];
        foreach (resource_keys() as $resource) {
            $value = $row['actual_' . $resource] ?? null;
            $data['disbursements'][$index]['actual_received'][$resource] = $value === null ? null : (int) $value;
        }
        $data['disbursements'][$index]['transport_count'] = (int) $row['transport_count'];
        $data['disbursements'][$index]['evidence_note'] = (string) $row['evidence_note'];
        $data['disbursements'][$index]['updated_at'] = $row['updated_at'] !== null ? (string) $row['updated_at'] : null;
    }

    $auditTable = mysql_table_name('audit_log');
    foreach ($connection->query("SELECT * FROM {$auditTable} ORDER BY `created_at`, `id`")->fetchAll() as $row) {
        $data['audit_log'][] = [
            'id' => (string) $row['id'],
            'action' => (string) $row['action'],
            'entity_type' => (string) $row['entity_type'],
            'entity_id' => (string) $row['entity_id'],
            'description' => (string) $row['description'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    if ($includeArchives) {
        $archivesTable = mysql_table_name('events_archive');
        foreach ($connection->query("SELECT `archive_json` FROM {$archivesTable} ORDER BY `archived_at`, `id`")->fetchAll() as $row) {
            $archive = json_decode((string) $row['archive_json'], true);
            if (!is_array($archive)) {
                throw new RuntimeException('Uno de los eventos archivados está dañado en MySQL.');
            }
            $data['events_archive'][] = $archive;
        }
    }

    $reserveTable = mysql_table_name('reserve_movements');
    foreach ($connection->query("SELECT * FROM {$reserveTable} ORDER BY `created_at`, `id`")->fetchAll() as $row) {
        $data['reserve_movements'][] = [
            'id' => (string) $row['id'],
            'event_id' => (string) $row['event_id'],
            'resource_type' => (string) $row['resource_type'],
            'movement_type' => (string) $row['movement_type'],
            'amount_signed' => (int) $row['amount_signed'],
            'note' => (string) $row['note'],
            'created_by' => (string) $row['created_by'],
            'created_at' => (string) $row['created_at'],
            'audit_reference' => (string) $row['audit_reference'],
            'dedupe_key' => $row['dedupe_key'] !== null ? (string) $row['dedupe_key'] : null,
        ];
    }

    $settlementsTable = mysql_table_name('settlements');
    foreach ($connection->query("SELECT * FROM {$settlementsTable} ORDER BY `event_id`")->fetchAll() as $row) {
        $snapshot = json_decode((string) $row['snapshot_json'], true);
        $data['settlements'][] = [
            'event_id' => (string) $row['event_id'],
            'status' => (string) $row['status'],
            'frozen_at' => $row['frozen_at'] !== null ? (string) $row['frozen_at'] : null,
            'unfreeze_count' => (int) $row['unfreeze_count'],
            'snapshot' => is_array($snapshot) ? $snapshot : [],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    $pendingTable = mysql_table_name('pending_contributions');
    foreach ($connection->query("SELECT * FROM {$pendingTable} ORDER BY `created_at`, `id`")->fetchAll() as $row) {
        $data['pending_contributions'][] = [
            'id' => (string) $row['id'],
            'player_name' => (string) $row['player_name'],
            'food' => (int) $row['food'],
            'wood' => (int) $row['wood'],
            'stone' => (int) $row['stone'],
            'gold' => (int) $row['gold'],
            'note' => (string) $row['note'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    $attemptsTable = mysql_table_name('login_attempts');
    foreach ($connection->query("SELECT * FROM {$attemptsTable}")->fetchAll() as $row) {
        $data['login_attempts'][(string) $row['attempt_key']] = [
            'count' => (int) $row['attempt_count'],
            'window_started' => (int) $row['window_started'],
            'locked_until' => (int) $row['locked_until'],
        ];
    }
    return database_normalize($data);
}

function mysql_json_encode(array $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('No se pudieron convertir los datos a JSON para MySQL.');
    }
    return $json;
}

function mysql_write_state(PDO $connection, array $input, bool $syncArchives = false): void
{
    $data = database_normalize($input);
    // Los intentos de acceso se actualizan por una ruta directa y no forman
    // parte de esta sincronización para evitar perder un bloqueo concurrente.
    $tablesToClear = ['audit_log', 'disbursement_details', 'disbursements', 'contributions', 'players', 'event_winners', 'settings', 'admin'];
    foreach ($tablesToClear as $suffix) {
        $connection->exec('DELETE FROM ' . mysql_table_name($suffix));
    }

    if (is_array($data['admin'])) {
        $table = mysql_table_name('admin');
        $statement = $connection->prepare(
            "INSERT INTO {$table} (`id`, `username`, `password_hash`, `session_version`, `created_at`, `password_changed_at`)
             VALUES (1, ?, ?, ?, ?, ?)"
        );
        $statement->execute([
            (string) $data['admin']['username'],
            (string) $data['admin']['password_hash'],
            (string) $data['admin']['session_version'],
            (string) $data['admin']['created_at'],
            $data['admin']['password_changed_at'] ?? null,
        ]);
    }

    $settings = settings_normalize($data['settings']);
    $settingsTable = mysql_table_name('settings');
    $statement = $connection->prepare(
        "INSERT INTO {$settingsTable} (
            `id`, `event_name`, `event_status`, `custodian_name`, `tax_rate`, `qualification_basis`,
            `deadline`, `prize`, `winner_name`, `reward_weight_first`, `reward_weight_second`,
            `reward_weight_third`, `transport_capacity`, `threshold_food`, `threshold_wood`,
            `threshold_stone`, `threshold_gold`, `updated_at`,
            `current_event_id`, `objective_title`, `objective_description`, `score_metric_label`,
            `ranking_method`, `rank_rule_first`, `rank_rule_second`, `rank_rule_third`, `starts_at`,
            `prize_pool_basis_points`, `reserve_draw_food`, `reserve_draw_wood`, `reserve_draw_stone`,
            `reserve_draw_gold`, `settlement_status`, `frozen_at`
        ) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $statement->execute([
        (string) $settings['event_name'],
        (string) $settings['event_status'],
        (string) $settings['custodian_name'],
        (float) $settings['tax_rate'],
        (string) $settings['qualification_basis'],
        $settings['deadline'],
        (string) $settings['prize'],
        (string) $settings['winner_name'],
        (float) $settings['reward_weights']['first'],
        (float) $settings['reward_weights']['second'],
        (float) $settings['reward_weights']['third'],
        (int) $settings['transport_capacity'],
        (int) $settings['thresholds']['food'],
        (int) $settings['thresholds']['wood'],
        (int) $settings['thresholds']['stone'],
        (int) $settings['thresholds']['gold'],
        $settings['updated_at'],
        (string) $settings['current_event_id'],
        (string) $settings['objective_title'],
        (string) $settings['objective_description'],
        (string) $settings['score_metric_label'],
        (string) $settings['ranking_method'],
        (string) $settings['rank_rule_first'],
        (string) $settings['rank_rule_second'],
        (string) $settings['rank_rule_third'],
        $settings['starts_at'],
        (int) $settings['prize_pool_basis_points'],
        (int) $settings['reserve_draw']['food'],
        (int) $settings['reserve_draw']['wood'],
        (int) $settings['reserve_draw']['stone'],
        (int) $settings['reserve_draw']['gold'],
        (string) $settings['settlement_status'],
        $settings['frozen_at'],
    ]);

    $winnersTable = mysql_table_name('event_winners');
    $statement = $connection->prepare(
        "INSERT INTO {$winnersTable} (`rank_key`, `position_number`, `player_name`, `score`, `metric`, `rank_rule`, `note`, `updated_at`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    foreach ($data['winners'] as $winner) {
        $statement->execute([
            (string) $winner['rank'], (int) $winner['position'], (string) $winner['player_name'],
            (int) $winner['score'], (string) $winner['metric'], (string) ($winner['rank_rule'] ?? ''),
            (string) $winner['note'], gmdate('c'),
        ]);
    }

    $playersTable = mysql_table_name('players');
    $statement = $connection->prepare(
        "INSERT INTO {$playersTable} (`id`, `display_name`, `match_key`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?)"
    );
    foreach ($data['players'] as $row) {
        $statement->execute([(string) $row['id'], (string) $row['display_name'], (string) $row['match_key'], (string) $row['created_at'], $row['updated_at']]);
    }

    $contributionsTable = mysql_table_name('contributions');
    $statement = $connection->prepare(
        "INSERT INTO {$contributionsTable} (`id`, `player_id`, `player_name_snapshot`, `food`, `wood`, `stone`, `gold`, `note`, `created_at`, `updated_at`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    foreach ($data['contributions'] as $row) {
        $statement->execute([
            (string) $row['id'], (string) $row['player_id'], (string) $row['player_name_snapshot'],
            (int) $row['food'], (int) $row['wood'], (int) $row['stone'], (int) $row['gold'],
            (string) $row['note'], (string) $row['created_at'], $row['updated_at'],
        ]);
    }

    $disbursementsTable = mysql_table_name('disbursements');
    $statement = $connection->prepare(
        "INSERT INTO {$disbursementsTable} (`id`, `recipient_name`, `food`, `wood`, `stone`, `gold`, `tax_rate`, `note`, `created_at`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    foreach ($data['disbursements'] as $row) {
        $statement->execute([
            (string) $row['id'], (string) $row['recipient_name'], (int) $row['food'], (int) $row['wood'],
            (int) $row['stone'], (int) $row['gold'], (float) $row['tax_rate'], (string) $row['note'], (string) $row['created_at'],
        ]);
    }

    $detailsTable = mysql_table_name('disbursement_details');
    $statement = $connection->prepare(
        "INSERT INTO {$detailsTable} (`disbursement_id`, `actual_food`, `actual_wood`, `actual_stone`, `actual_gold`, `transport_count`, `evidence_note`, `updated_at`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    foreach ($data['disbursements'] as $row) {
        $actual = isset($row['actual_received']) && is_array($row['actual_received'])
            ? $row['actual_received'] : [];
        $statement->execute([
            (string) $row['id'],
            $actual['food'] ?? null,
            $actual['wood'] ?? null,
            $actual['stone'] ?? null,
            $actual['gold'] ?? null,
            max(0, (int) ($row['transport_count'] ?? 0)),
            (string) ($row['evidence_note'] ?? ''),
            $row['updated_at'] ?? null,
        ]);
    }

    $auditTable = mysql_table_name('audit_log');
    $statement = $connection->prepare(
        "INSERT INTO {$auditTable} (`id`, `action`, `entity_type`, `entity_id`, `description`, `created_at`) VALUES (?, ?, ?, ?, ?, ?)"
    );
    foreach ($data['audit_log'] as $row) {
        $statement->execute([
            (string) $row['id'], (string) $row['action'], (string) $row['entity_type'],
            (string) $row['entity_id'], (string) $row['description'], (string) $row['created_at'],
        ]);
    }

    // El libro mayor de reservas y las liquidaciones sólo se anexan o
    // actualizan: nunca se borra una fila ya contabilizada.
    $reserveTable = mysql_table_name('reserve_movements');
    $statement = $connection->prepare(
        "INSERT INTO {$reserveTable} (`id`, `event_id`, `resource_type`, `movement_type`, `amount_signed`,
         `note`, `created_by`, `created_at`, `audit_reference`, `dedupe_key`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE `note` = VALUES(`note`), `audit_reference` = VALUES(`audit_reference`)"
    );
    foreach ($data['reserve_movements'] as $row) {
        $statement->execute([
            (string) $row['id'], (string) $row['event_id'], (string) $row['resource_type'],
            (string) $row['movement_type'], (int) $row['amount_signed'], (string) $row['note'],
            (string) $row['created_by'], (string) $row['created_at'], (string) $row['audit_reference'],
            $row['dedupe_key'],
        ]);
    }

    $settlementsTable = mysql_table_name('settlements');
    $statement = $connection->prepare(
        "INSERT INTO {$settlementsTable} (`event_id`, `status`, `frozen_at`, `unfreeze_count`, `snapshot_json`, `updated_at`)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE `status` = VALUES(`status`), `frozen_at` = VALUES(`frozen_at`),
         `unfreeze_count` = VALUES(`unfreeze_count`), `snapshot_json` = VALUES(`snapshot_json`),
         `updated_at` = VALUES(`updated_at`)"
    );
    foreach ($data['settlements'] as $row) {
        $statement->execute([
            (string) $row['event_id'], (string) $row['status'], $row['frozen_at'],
            (int) $row['unfreeze_count'], mysql_json_encode((array) $row['snapshot']), (string) $row['updated_at'],
        ]);
    }

    $pendingTable = mysql_table_name('pending_contributions');
    $connection->exec('DELETE FROM ' . $pendingTable);
    $statement = $connection->prepare(
        "INSERT INTO {$pendingTable} (`id`, `player_name`, `food`, `wood`, `stone`, `gold`, `note`, `created_at`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    foreach ($data['pending_contributions'] as $row) {
        $statement->execute([
            (string) $row['id'], (string) $row['player_name'], (int) $row['food'], (int) $row['wood'],
            (int) $row['stone'], (int) $row['gold'], (string) $row['note'], (string) $row['created_at'],
        ]);
    }

    if ($syncArchives) {
        mysql_sync_archives($connection, $data['events_archive']);
    }
    mysql_meta_set($connection, 'data_version', (string) $data['version']);
}

function mysql_sync_archives(PDO $connection, array $archives): void
{
    $table = mysql_table_name('events_archive');
    $existing = [];
    foreach ($connection->query("SELECT `id`, `content_hash` FROM {$table}")->fetchAll() as $row) {
        $existing[(string) $row['id']] = (string) $row['content_hash'];
    }
    $wanted = [];
    $statement = $connection->prepare(
        "INSERT INTO {$table} (`id`, `event_name`, `archive_json`, `content_hash`, `archived_at`) VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE `event_name` = VALUES(`event_name`), `archive_json` = VALUES(`archive_json`),
         `content_hash` = VALUES(`content_hash`), `archived_at` = VALUES(`archived_at`)"
    );
    foreach ($archives as $archive) {
        if (!is_array($archive) || empty($archive['id'])) {
            continue;
        }
        $id = (string) $archive['id'];
        $json = mysql_json_encode($archive);
        $hash = hash('sha256', $json);
        $wanted[$id] = true;
        if (($existing[$id] ?? null) === $hash) {
            continue;
        }
        $eventName = isset($archive['settings']['event_name']) ? (string) $archive['settings']['event_name'] : 'Evento archivado';
        $statement->execute([$id, $eventName, $json, $hash, (string) ($archive['archived_at'] ?? gmdate('c'))]);
    }
    $delete = $connection->prepare("DELETE FROM {$table} WHERE `id` = ?");
    foreach ($existing as $id => $hash) {
        if (!isset($wanted[$id])) {
            $delete->execute([$id]);
        }
    }
}

function mysql_lock_writes(PDO $connection): void
{
    $meta = mysql_table_name('meta');
    $statement = $connection->prepare(
        "INSERT IGNORE INTO {$meta} (`meta_key`, `meta_value`, `updated_at`) VALUES ('write_lock', '1', ?)"
    );
    $statement->execute([gmdate('c')]);
    $connection->query("SELECT `meta_value` FROM {$meta} WHERE `meta_key` = 'write_lock' FOR UPDATE")->fetchColumn();
}

function mysql_create_backup(PDO $connection, array $data, string $reason, bool $includesArchives, array $archivePayloadIds = []): ?int
{
    $state = database_normalize($data);
    $archiveIds = [];
    $archivePayloads = [];
    $payloadWanted = array_fill_keys(array_map('strval', $archivePayloadIds), true);
    if ($includesArchives) {
        foreach ($state['events_archive'] as $archive) {
            if (is_array($archive) && !empty($archive['id'])) {
                $archiveId = (string) $archive['id'];
                $archiveIds[] = $archiveId;
                if (isset($payloadWanted[$archiveId])) {
                    $archivePayloads[$archiveId] = $archive;
                }
            }
        }
    }
    $state['events_archive'] = [];
    $snapshot = [
        'format' => 'rokbank_snapshot_v1',
        'preserve_archives' => !$includesArchives,
        'archive_ids' => $archiveIds,
        'archive_payloads' => $archivePayloads,
        'state' => $state,
    ];
    $table = mysql_table_name('backups');
    $statement = $connection->prepare("INSERT INTO {$table} (`reason`, `snapshot_json`, `created_at`) VALUES (?, ?, ?)");
    $statement->execute([
        substr(trim((string) preg_replace('/[^a-z0-9_-]+/i', '-', $reason)), 0, 100) ?: 'change',
        mysql_json_encode($snapshot),
        gmdate('c'),
    ]);
    $id = (int) $connection->lastInsertId();
    $rows = $connection->query("SELECT `id` FROM {$table} ORDER BY `id` DESC")->fetchAll();
    $limit = max(1, (int) app_config('backup_limit'));
    $delete = $connection->prepare("DELETE FROM {$table} WHERE `id` = ?");
    foreach (array_slice($rows, $limit) as $row) {
        $delete->execute([(int) $row['id']]);
    }
    return $id;
}

function mysql_mutate(callable $callback, bool $createBackup, string $reason, bool $includeArchives = false, array $archivePayloadIds = [])
{
    $connection = mysql_connection();
    $connection->beginTransaction();
    try {
        mysql_lock_writes($connection);
        $data = mysql_read_state($connection, $includeArchives);
        if ($createBackup) {
            mysql_create_backup($connection, $data, $reason, $includeArchives, $archivePayloadIds);
        }
        $result = $callback($data);
        mysql_write_state($connection, $data, $includeArchives);
        $connection->commit();
        return $result;
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
}

function mysql_latest_backup_info(): ?array
{
    $connection = mysql_connection();
    $table = mysql_table_name('backups');
    $row = $connection->query("SELECT `id`, `reason`, `created_at` FROM {$table} ORDER BY `id` DESC LIMIT 1")->fetch();
    if (!is_array($row)) {
        return null;
    }
    return ['id' => (int) $row['id'], 'reason' => (string) $row['reason'], 'created_at' => (string) $row['created_at']];
}

function mysql_restore_latest_backup(): bool
{
    $connection = mysql_connection();
    $connection->beginTransaction();
    try {
        mysql_lock_writes($connection);
        $backupTable = mysql_table_name('backups');
        $row = $connection->query("SELECT * FROM {$backupTable} ORDER BY `id` DESC LIMIT 1 FOR UPDATE")->fetch();
        if (!is_array($row)) {
            $connection->rollBack();
            return false;
        }
        $decoded = json_decode((string) $row['snapshot_json'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('La última copia automática de MySQL no es válida.');
        }
        $current = mysql_read_state($connection, true);
        $archivesRemovedByRestore = [];
        if (($decoded['format'] ?? '') === 'rokbank_snapshot_v1' && empty($decoded['preserve_archives'])) {
            $targetIds = array_fill_keys(array_map('strval', (array) ($decoded['archive_ids'] ?? [])), true);
            foreach ($current['events_archive'] as $archive) {
                if (is_array($archive) && !empty($archive['id']) && !isset($targetIds[(string) $archive['id']])) {
                    $archivesRemovedByRestore[] = (string) $archive['id'];
                }
            }
        }
        mysql_create_backup($connection, $current, 'before-undo', true, $archivesRemovedByRestore);

        if (($decoded['format'] ?? '') === 'rokbank_snapshot_v1' && isset($decoded['state']) && is_array($decoded['state'])) {
            $restored = database_normalize($decoded['state']);
            if (!empty($decoded['preserve_archives'])) {
                $restored['events_archive'] = $current['events_archive'];
            } else {
                $byId = [];
                foreach ($current['events_archive'] as $archive) {
                    if (is_array($archive) && !empty($archive['id'])) {
                        $byId[(string) $archive['id']] = $archive;
                    }
                }
                foreach ((array) ($decoded['archive_payloads'] ?? []) as $id => $archive) {
                    if (is_array($archive) && !empty($archive['id']) && hash_equals((string) $archive['id'], (string) $id)) {
                        $byId[(string) $id] = $archive;
                    }
                }
                $restored['events_archive'] = [];
                foreach ((array) ($decoded['archive_ids'] ?? []) as $id) {
                    if (!isset($byId[(string) $id])) {
                        throw new RuntimeException('Falta un evento histórico necesario para restaurar la copia.');
                    }
                    $restored['events_archive'][] = $byId[(string) $id];
                }
            }
        } else {
            $restored = database_normalize($decoded);
        }
        $restored['admin'] = $current['admin'];
        $restored['login_attempts'] = $current['login_attempts'];
        audit_append($restored, 'undo', 'system', '', 'Se restauró la última copia automática de MySQL.');
        mysql_write_state($connection, $restored, true);
        $connection->commit();
        return true;
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
}

function mysql_login_lock_remaining(string $key): int
{
    $connection = mysql_connection();
    $table = mysql_table_name('login_attempts');
    $statement = $connection->prepare("SELECT `locked_until` FROM {$table} WHERE `attempt_key` = ? LIMIT 1");
    $statement->execute([$key]);
    $lockedUntil = $statement->fetchColumn();
    return $lockedUntil === false ? 0 : max(0, (int) $lockedUntil - time());
}

function mysql_record_login_failure(string $key): void
{
    $connection = mysql_connection();
    $table = mysql_table_name('login_attempts');
    $now = time();
    $connection->beginTransaction();
    try {
        $insert = $connection->prepare(
            "INSERT IGNORE INTO {$table} (`attempt_key`, `attempt_count`, `window_started`, `locked_until`) VALUES (?, 0, ?, 0)"
        );
        $insert->execute([$key, $now]);
        $select = $connection->prepare("SELECT * FROM {$table} WHERE `attempt_key` = ? FOR UPDATE");
        $select->execute([$key]);
        $row = $select->fetch();
        $count = is_array($row) ? (int) $row['attempt_count'] : 0;
        $windowStarted = is_array($row) ? (int) $row['window_started'] : $now;
        $lockedUntil = is_array($row) ? (int) $row['locked_until'] : 0;
        if ($now - $windowStarted > (int) app_config('login_window_seconds')) {
            $count = 0;
            $windowStarted = $now;
            $lockedUntil = 0;
        }
        $count++;
        if ($count >= (int) app_config('login_max_attempts')) {
            $lockedUntil = $now + (int) app_config('login_lock_seconds');
        }
        $update = $connection->prepare(
            "UPDATE {$table} SET `attempt_count` = ?, `window_started` = ?, `locked_until` = ? WHERE `attempt_key` = ?"
        );
        $update->execute([$count, $windowStarted, $lockedUntil, $key]);
        $cutoff = $now - 86400;
        $cleanup = $connection->prepare("DELETE FROM {$table} WHERE GREATEST(`window_started`, `locked_until`) < ?");
        $cleanup->execute([$cutoff]);
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
}

function mysql_clear_login_failure(string $key): void
{
    $connection = mysql_connection();
    $table = mysql_table_name('login_attempts');
    $statement = $connection->prepare("DELETE FROM {$table} WHERE `attempt_key` = ?");
    $statement->execute([$key]);
}

function database_status(): array
{
    if (!storage_uses_mysql()) {
        return [
            'driver' => 'json',
            'connected' => true,
            'database_name' => 'Archivo local',
            'table_prefix' => '',
            'table_count' => 0,
            'schema_version' => 0,
            'archive_count' => 0,
            'backup_count' => 0,
            'schema_migrated_at' => null,
            'legacy_imported_at' => null,
            'legacy_player_count' => 0,
            'legacy_contribution_count' => 0,
            'legacy_archive_count' => 0,
            'schema_v3_migrated_at' => null,
            'announcement_count' => 0,
            'media_count' => 0,
            'reserve_movement_count' => 0,
        ];
    }
    $connection = mysql_connection();
    $config = mysql_storage_config();
    $archives = (int) $connection->query('SELECT COUNT(*) FROM ' . mysql_table_name('events_archive'))->fetchColumn();
    $backups = (int) $connection->query('SELECT COUNT(*) FROM ' . mysql_table_name('backups'))->fetchColumn();
    $importedAt = mysql_meta_get($connection, 'legacy_imported_at');
    $migratedAt = mysql_meta_get($connection, 'schema_v2_migrated_at');
    $migratedV3At = mysql_meta_get($connection, 'schema_v3_migrated_at');
    $announcements = (int) $connection->query('SELECT COUNT(*) FROM ' . mysql_table_name('announcements'))->fetchColumn();
    $mediaFiles = (int) $connection->query('SELECT COUNT(*) FROM ' . mysql_table_name('announcement_media'))->fetchColumn();
    $reserveRows = (int) $connection->query('SELECT COUNT(*) FROM ' . mysql_table_name('reserve_movements'))->fetchColumn();
    return [
        'driver' => 'mysql',
        'connected' => true,
        'database_name' => (string) $config['name'],
        'table_prefix' => (string) $config['table_prefix'],
        'table_count' => count(mysql_table_suffixes()),
        'schema_version' => (int) (mysql_meta_get($connection, 'schema_version') ?? 1),
        'archive_count' => $archives,
        'backup_count' => $backups,
        'schema_migrated_at' => $migratedAt !== null && $migratedAt !== '' ? $migratedAt : null,
        'legacy_imported_at' => $importedAt !== null && $importedAt !== '' ? $importedAt : null,
        'legacy_player_count' => (int) (mysql_meta_get($connection, 'legacy_player_count') ?? 0),
        'legacy_contribution_count' => (int) (mysql_meta_get($connection, 'legacy_contribution_count') ?? 0),
        'legacy_archive_count' => (int) (mysql_meta_get($connection, 'legacy_archive_count') ?? 0),
        'schema_v3_migrated_at' => $migratedV3At !== null && $migratedV3At !== '' ? $migratedV3At : null,
        'announcement_count' => $announcements,
        'media_count' => $mediaFiles,
        'reserve_movement_count' => $reserveRows,
    ];
}
