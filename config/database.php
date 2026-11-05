<?php

declare(strict_types=1);

use Ownswitch\Support\Env;

return [
    'default' => [
        'driver'   => 'mysql',
        'host'     => Env::get('DB_HOST', '127.0.0.1'),
        'port'     => (int) Env::get('DB_PORT', 3306),
        // Accept both portal (.env.example) and installer (/etc/ownswitch/ownswitch.env) names.
        'database' => Env::get('DB_DATABASE', Env::get('DB_NAME', 'ownswitch')),
        'username' => Env::get('DB_USERNAME', Env::get('DB_USER', 'root')),
        'password' => Env::get('DB_PASSWORD', ''),
        'charset'  => Env::get('DB_CHARSET', 'utf8mb4'),
    ],
];
