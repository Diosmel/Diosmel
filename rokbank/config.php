<?php
declare(strict_types=1);

/**
 * Configuración base del Banco de Recursos ME58.
 *
 * IMPORTANTE SOBRE LAS CREDENCIALES
 * ---------------------------------
 * Este archivo viaja dentro del paquete y puede acabar en un repositorio
 * público, por eso NO contiene la contraseña real de MySQL ni el código de
 * instalación definitivo. Los valores reales se leen, en este orden:
 *
 *   1. Variables de entorno (ROKBANK_DB_HOST, ROKBANK_DB_NAME, ...).
 *   2. El archivo privado `config.local.php`, que devuelve un arreglo con las
 *      claves que quieras sobrescribir y NUNCA debe subirse a un repositorio.
 *   3. Los valores de reserva escritos aquí abajo.
 *
 * Si actualizas una instalación que ya funciona, lo más cómodo es NO
 * sobrescribir tu `config.php` actual, o crear `config.local.php` con:
 *
 *   <?php return ['database' => ['name' => '...', 'username' => '...', 'password' => '...'], 'setup_key' => '...'];
 *
 * Todas las opciones nuevas de esta versión tienen un valor de reserva dentro
 * del código, así que un `config.php` antiguo sigue funcionando sin tocarlo.
 */

$rokbank_config = [
    'app_name' => 'Banco de Recursos ME58',
    'alliance_name' => 'ME58',
    'timezone' => 'America/New_York',
    'setup_key' => (string) (getenv('ROKBANK_SETUP_KEY') ?: 'CAMBIA-ESTE-CODIGO-DE-INSTALACION'),
    'minimum_password_length' => 12,
    'storage_driver' => (string) (getenv('ROKBANK_STORAGE_DRIVER') ?: 'mysql'),
    'database' => [
        'host' => (string) (getenv('ROKBANK_DB_HOST') ?: 'localhost'),
        'port' => (int) (getenv('ROKBANK_DB_PORT') ?: 3306),
        'name' => (string) (getenv('ROKBANK_DB_NAME') ?: 'cambia_el_nombre'),
        'username' => (string) (getenv('ROKBANK_DB_USER') ?: 'cambia_el_usuario'),
        'password' => (string) (getenv('ROKBANK_DB_PASSWORD') ?: ''),
        'charset' => 'utf8mb4',
        'table_prefix' => (string) (getenv('ROKBANK_DB_PREFIX') ?: 'rokbank_'),
    ],
    'thresholds' => [
        'food' => 1200000,
        'wood' => 1200000,
        'stone' => 1000000,
        'gold' => 600000,
    ],
    'storage_file' => __DIR__ . '/storage/.bank-data.json',
    'media_directory' => __DIR__ . '/storage/media',
    'login_max_attempts' => 5,
    'login_window_seconds' => 900,
    'login_lock_seconds' => 900,
    'session_timeout_seconds' => 7200,
    'backup_limit' => 30,
    'audit_limit' => 750,

    // Límites de carga de los comunicados. El panel muestra siempre el límite
    // efectivo más bajo entre estos valores y los del servidor PHP.
    'media_limits' => [
        'image' => 15 * 1024 * 1024,
        'audio' => 50 * 1024 * 1024,
        'video' => 250 * 1024 * 1024,
    ],
    'announcement_max_characters' => 100000,

    'default_settings' => [
        'event_name' => 'Banco del evento ME58',
        'event_status' => 'active',
        'custodian_name' => 'FreeCuba',
        'tax_rate' => 8,
        'qualification_basis' => 'net_received',
        // La proporción 90:72:61 reproduce el valor combinado de los premios
        // originales: TOP 1 (90M), TOP 2 (72M) y TOP 3 (61M).
        'reward_weights' => [
            'first' => 90,
            'second' => 72,
            'third' => 61,
        ],
        'transport_capacity' => 10000000,
        'deadline' => null,
        'prize' => '',
        'winner_name' => '',

        // Configuración dinámica del evento. Los valores iniciales reproducen
        // exactamente el comportamiento de la versión anterior.
        'objective_title' => 'Destrucción de fuertes bárbaros',
        'objective_description' => '',
        'score_metric_label' => 'Fuertes destruidos',
        'ranking_method' => 'highest',
        'rank_rule_first' => '',
        'rank_rule_second' => '',
        'rank_rule_third' => '',
        'starts_at' => null,
        'prize_pool_basis_points' => 10000,
        'reserve_draw' => [
            'food' => 0,
            'wood' => 0,
            'stone' => 0,
            'gold' => 0,
        ],
        'settlement_status' => 'open',
        'frozen_at' => null,
    ],
];

$rokbank_local = __DIR__ . '/config.local.php';
if (is_file($rokbank_local)) {
    /** @var mixed $rokbank_overrides */
    $rokbank_overrides = require $rokbank_local;
    if (is_array($rokbank_overrides)) {
        foreach ($rokbank_overrides as $rokbank_key => $rokbank_value) {
            if (is_array($rokbank_value) && isset($rokbank_config[$rokbank_key]) && is_array($rokbank_config[$rokbank_key])) {
                $rokbank_config[$rokbank_key] = array_replace($rokbank_config[$rokbank_key], $rokbank_value);
                continue;
            }
            $rokbank_config[$rokbank_key] = $rokbank_value;
        }
    }
}

return $rokbank_config;
