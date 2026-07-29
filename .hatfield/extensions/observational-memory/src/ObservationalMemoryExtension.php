<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory;

use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Command\OmStatusCommandHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Command\OmViewCommandHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Compaction\BuildCompactionMemoryJobHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Compaction\OmBeforeCompactionHook;
use Ineersa\HatfieldExt\ObservationalMemory\Compaction\ReflectGenerationJobHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\ObserveBoundaryJobHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\ObserveBoundaryTerminalHook;
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmQueryService;
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmSessionContext;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Tool\RecallToolHandler;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Hatfield registration surface for observational memory.
 *
 * Registers:
 * - after-turn terminal detector that dispatches a scalar extension-agent job
 * - worker-local ObserveBoundaryJobHandler / BuildCompactionMemoryJobHandler
 * - public before-compaction hook (CompactRun only) for replacement summaries
 * - /om-status and /om-view local commands
 * - permanent ambient recall tool
 */
final class ObservationalMemoryExtension implements HatfieldExtensionInterface, TuiExtensionInterface, LoggerAwareInterface
{
    private LoggerInterface $logger;

    private OmSessionContext $sessionContext;

    public function __construct()
    {
        $this->logger = new NullLogger();
        $this->sessionContext = new OmSessionContext();
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function register(ExtensionApiInterface $api): void
    {
        // Presence on extensions.enabled is the sole enable switch.
        $settings = OmSettings::fromApi($api);
        $query = new OmQueryService($api, $settings, $this->logger);

        $api->registerExtensionAgentJobHandler(
            ObserveBoundaryTerminalHook::HANDLER_ID,
            new ObserveBoundaryJobHandler($this->logger),
        );
        $api->registerExtensionAgentJobHandler(
            BuildCompactionMemoryJobHandler::HANDLER_ID,
            new BuildCompactionMemoryJobHandler($this->logger),
        );
        $api->registerExtensionAgentJobHandler(
            ReflectGenerationJobHandler::HANDLER_ID,
            new ReflectGenerationJobHandler($this->logger),
        );

        $api->registerAfterTurnCommitHook(
            new ObserveBoundaryTerminalHook($api, $settings, $this->logger),
        );
        $api->registerBeforeCompactionHook(
            new OmBeforeCompactionHook($api, $settings, $this->logger),
        );

        $api->registerCommand(
            new CommandDefinitionDTO(
                name: 'om-status',
                aliases: [],
                description: 'Show observational memory status for the current session',
                usage: '/om-status',
                acceptsArguments: false,
            ),
            new OmStatusCommandHandler($query, $this->sessionContext, $this->logger),
        );
        $api->registerCommand(
            new CommandDefinitionDTO(
                name: 'om-view',
                aliases: [],
                description: 'Show active observational memory for the current session',
                usage: '/om-view',
                acceptsArguments: false,
            ),
            new OmViewCommandHandler($query, $this->sessionContext, $this->logger),
        );

        $api->registerTool(new ToolRegistrationDTO(
            name: 'recall',
            description: 'Recall exact source events for one observational-memory observation or reflection id from the current session.',
            parametersJsonSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['id'],
                'properties' => [
                    'id' => [
                        'type' => 'string',
                        'pattern' => '^[a-f0-9]{12,64}$',
                        'description' => 'Lowercase hex observation or reflection id (full 64-char SHA-256 or unique 12–64 char prefix).',
                    ],
                ],
            ],
            handler: new RecallToolHandler($query),
            promptSummary: 'recall: resolve exact OM observation/reflection source events by id or unique short prefix',
            promptGuidelines: [
                'Use recall only with a known observation or reflection id from active memory (full id or unique 12+ hex prefix).',
                'Do not use recall as broad search; request exact source context only when needed.',
            ],
        ));

        $this->logger->info('om.extension.registered', [
            'component' => 'observational_memory',
            'event_type' => 'om.extension.registered',
            'handler_id' => ObserveBoundaryTerminalHook::HANDLER_ID,
            'compaction_handler_id' => BuildCompactionMemoryJobHandler::HANDLER_ID,
            'reflect_handler_id' => ReflectGenerationJobHandler::HANDLER_ID,
        ]);
    }

    public function registerTui(TuiExtensionContextInterface $context): void
    {
        // Keep the live public context; resolve session id lazily on each command.
        $this->sessionContext->bindTui($context);
    }
}
