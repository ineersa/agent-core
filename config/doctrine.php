<?php

declare(strict_types=1);

return [
    'doctrine' => [[
        'dbal' => [
            'types' => [],
        ],
    ]],
    'doctrine_migrations' => [[
        'migrations_paths' => [
            'Ineersa\\AgentCore\\Infrastructure\\Doctrine\\Migrations' => \dirname(__DIR__).'/src/Infrastructure/Doctrine/Migrations',
        ],
        'all_or_nothing' => true,
        'check_database_platform' => false,
    ]],
];
