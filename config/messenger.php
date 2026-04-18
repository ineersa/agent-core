<?php

declare(strict_types=1);

use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\CollectToolBatch;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ProjectJsonlOutbox;
use Ineersa\AgentCore\Domain\Message\ProjectMercureOutbox;
use Ineersa\AgentCore\Domain\Message\StartRun;

return [
    'framework' => [[
        'messenger' => [
            'transports' => [
                'agent_loop.command' => 'in-memory://',
                'agent_loop.execution' => 'in-memory://',
                'agent_loop.publisher' => 'in-memory://',
            ],
            'buses' => [
                'agent.command.bus' => [
                    'default_middleware' => [
                        'enabled' => true,
                        'allow_no_handlers' => false,
                    ],
                    'middleware' => [
                        'dispatch_after_current_bus',
                    ],
                ],
                'agent.execution.bus' => [
                    'default_middleware' => [
                        'enabled' => true,
                        'allow_no_handlers' => true,
                    ],
                ],
                'agent.publisher.bus' => [
                    'default_middleware' => [
                        'enabled' => true,
                        'allow_no_handlers' => true,
                    ],
                ],
            ],
            'routing' => [
                StartRun::class => ['agent_loop.command'],
                ApplyCommand::class => ['agent_loop.command'],
                AdvanceRun::class => ['agent_loop.command'],
                ExecuteLlmStep::class => ['agent_loop.execution'],
                ExecuteToolCall::class => ['agent_loop.execution'],
                CollectToolBatch::class => ['agent_loop.execution'],
                ProjectJsonlOutbox::class => ['agent_loop.publisher'],
                ProjectMercureOutbox::class => ['agent_loop.publisher'],
            ],
        ],
    ]],
];
