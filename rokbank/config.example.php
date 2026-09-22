<?php
declare(strict_types=1);

/**
 * Copia este archivo como `config.local.php` en la misma carpeta y escribe
 * dentro tus credenciales reales. `config.local.php` está bloqueado por
 * .htaccess y excluido del repositorio: nunca debe compartirse.
 *
 * Sólo hace falta incluir las claves que quieras cambiar.
 */

return [
    'database' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'nombre_de_tu_base_de_datos',
        'username' => 'usuario_de_mysql',
        'password' => 'contraseña_de_mysql',
    ],
    'setup_key' => 'CODIGO-DE-INSTALACION-PRIVADO',

    // Opcional: límites de carga de los comunicados, en bytes.
    // 'media_limits' => [
    //     'image' => 15 * 1024 * 1024,
    //     'audio' => 50 * 1024 * 1024,
    //     'video' => 250 * 1024 * 1024,
    // ],
];
