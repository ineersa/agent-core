<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Support;

use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\TuiConfig;

final class ContextUsageTestAppConfig
{
    public static function withContextWindow(int $contextWindow = 272_000): AppConfig
    {
        $ai = AiConfig::fromArray([
            'default_model' => 'deepseek/deepseek-v4-flash',
            'default_reasoning' => 'medium',
            'providers' => [
                'deepseek' => [
                    'type' => 'openai',
                    'base_url' => 'http://example.invalid/v1',
                    'models' => [
                        'deepseek-v4-flash' => [
                            'context_window' => $contextWindow,
                        ],
                    ],
                ],
            ],
        ]);

        return new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            sessions: new SessionsConfig(),
            ai: $ai,
            catalog: new HatfieldModelCatalog($ai),
        );
    }
}
