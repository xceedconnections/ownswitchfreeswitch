<?php

declare(strict_types=1);

use Ownswitch\Support\Env;

return [
    'name'     => Env::get('APP_NAME', 'Ownswitch'),
    'env'      => Env::get('APP_ENV', 'production'),
    'debug'    => (bool) Env::get('APP_DEBUG', false),
    'key'      => (string) Env::get('APP_KEY', ''),
    'timezone' => Env::get('APP_TIMEZONE', 'UTC'),

    // Defaults used when system_settings has no value (BILLING_RULES.md section 3, global defaults).
    'defaults' => [
        'currency'                 => 'USD',
        'customer_initial'         => 6,
        'customer_subsequent'      => 6,
        'carrier_initial'          => 6,
        'carrier_subsequent'       => 6,
        'prefer_portal_rating'     => true,
        'charge_connect_on_zero'   => false,
        'prepaid_hard_cap_seconds' => 7200,
        'media_policy'             => 'relay',
        'codecs'                   => 'PCMU,PCMA,G729,GSM,telephone-event',
    ],

    'integrations' => [
        'opensips' => [
            // Accept 1/true/yes; fall back to enabled when OpenSIPS DB password exists (legacy installs).
            'enabled'    => (bool) Env::get('OPENSIPS_ENABLED', Env::get('OPENSIPS_DB_PASSWORD', '') !== ''),
            'sip_domain' => (string) (Env::get('OPENSIPS_SIP_DOMAIN')
                ?: (parse_url((string) Env::get('APP_URL', ''), PHP_URL_HOST) ?: 'localhost')),
            'public_ip'  => (string) (Env::get('SIP_PUBLIC_IP') ?: Env::get('PUBLIC_IP', '')),
            'mi_url'     => (string) (Env::get('OPENSIPS_MI_URL')
                ?: Env::get('OPENSIPS_MI_HTTP_URL', 'http://127.0.0.1:8888/mi')),
            'mi_fifo'    => (string) Env::get('OPENSIPS_MI_FIFO', '/run/opensips/opensips_fifo'),
            'db' => [
                'host'     => Env::get('OPENSIPS_DB_HOST', '127.0.0.1'),
                'port'     => (int) Env::get('OPENSIPS_DB_PORT', 3306),
                'database' => Env::get('OPENSIPS_DB_DATABASE', Env::get('OPENSIPS_DB_NAME', 'opensips')),
                'username' => Env::get('OPENSIPS_DB_USERNAME', Env::get('OPENSIPS_DB_USER', '')),
                'password' => Env::get('OPENSIPS_DB_PASSWORD', ''),
            ],
        ],
        'cgrates' => [
            'url'      => Env::get('CGRATES_URL', ''),
            'user'     => Env::get('CGRATES_USER', ''),
            'password' => Env::get('CGRATES_PASSWORD', ''),
            'tenant'   => Env::get('CGRATES_TENANT', 'cgrates.org'),
        ],
        'rtpengine' => [
            'host' => Env::get('RTPENGINE_HOST', ''),
            'port' => (int) Env::get('RTPENGINE_PORT', 22222),
        ],
        'freeswitch' => (static function (): array {
            $host = trim((string) (Env::get('FREESWITCH_ESL_HOST') ?: Env::get('FREESWITCH_HOST', '') ?: ''));
            $pass = trim((string) (Env::get('FREESWITCH_ESL_PASSWORD') ?: Env::get('FREESWITCH_PASSWORD', '') ?: ''));
            return [
                'host'       => $host,
                'port'       => (int) (Env::get('FREESWITCH_ESL_PORT') ?: Env::get('FREESWITCH_PORT', 8021)),
                'password'   => $pass,
                'xml_secret' => trim((string) Env::get('FS_XML_SECRET', '')),
                'conf_dir'   => (string) Env::get('FREESWITCH_CONF', '/usr/local/freeswitch/conf'),
                // Must be readable by FreeSWITCH (sofia include) AND writable by PHP-FPM.
                // Default: conf/sip_profiles/external (not OWN_VAR — freeswitch often cannot traverse /var/lib/ownswitch).
                'gateway_dir' => (static function (): string {
                    $explicit = trim((string) Env::get('FREESWITCH_GATEWAY_DIR', ''));
                    if ($explicit !== '') {
                        return rtrim($explicit, '/');
                    }
                    $conf = rtrim((string) Env::get('FREESWITCH_CONF', '/usr/local/freeswitch/conf'), '/');
                    return $conf . '/sip_profiles/external';
                })(),
            ];
        })(),
        // Portal / OpenSIPS DNC hot cache (Redis SET). DB index must not collide with CGRateS (usually 10).
        'redis' => [
            'enabled'  => (bool) Env::get('REDIS_HOST', '127.0.0.1'),
            'host'     => (string) Env::get('REDIS_HOST', '127.0.0.1'),
            'port'     => (int) Env::get('REDIS_PORT', 6379),
            'password' => (string) Env::get('REDIS_PASSWORD', ''),
            'db'       => (int) Env::get('REDIS_PORTAL_DB', 1),
        ],
    ],

    'recordings_dir' => Env::get('RECORDINGS_DIR', ''),
];
