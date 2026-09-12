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
use Ineersa\HatfieldExt\ObservationalMemory\Compaction\OmBeforeCompactionHook;
use Ineersa\HatfieldExt\ObservationalMemory\Compaction\ReflectGenerationJobHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\ObserveBoundaryJobHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\ObserveBoundaryTerminalHook;
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmQueryService;
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmSessionContext;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmPaths;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Tool\RecallToolHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Tool\SearchToolHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Tui\OmBackgroundStatusPoller;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Hatfield registration surface for observational memory.
 *
 * Registers:
 * - after-turn terminal detector that dispatches a scalar extension-agent job
 * - worker-local ObserveBoundaryJobHandler / ReflectGenerationJobHandler
 * - public CompactRun + snapshot before-compaction hooks: instant durable-memory projection
 * - /om-status and /om-view local commands
 * - permanent ambient search and recall tools
 * - TUI status-row poller for live Observer/Reflector/Dropper notices
 */
final class ObservationalMemoryExtension implements HatfieldExtensionInterface, TuiExtensionInterface, LoggerAwareInterface
{
    private LoggerInterface $logger;

    private OmSessionContext $sessionContext;

    private ?string $databasePath = null;

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
        $this->databasePath = OmPaths::fromSettings($settings, $api->getCwd())->databasePath;
        $query = new OmQueryService($api, $settings, $this->logger);

        $api->registerExtensionAgentJobHandler(
            ObserveBoundaryTerminalHook::HANDLER_ID,
            new ObserveBoundaryJobHandler($this->logger),
        );
        $api->registerExtensionAgentJobHandler(
            ReflectGenerationJobHandler::HANDLER_ID,
            new ReflectGenerationJobHandler($this->logger),
        );

        $api->registerAfterTurnCommitHook(
            new ObserveBoundaryTerminalHook($api, $settings, $this->logger),
        );
        // One public hook for CompactRun (watermark) and snapshot/fork (null watermark).
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
            name: 'om_search',
            description: 'Exact text search over retained observational-memory observations and reflections across sessions in the configured OM database. '
                .'Use to locate prior-session work by PR numbers, URLs, branches, symbols, or remembered phrases. Not semantic search.',
            parametersJsonSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['query'],
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'description' => 'Exact substring to find in observation or reflection content (case-sensitive SQLite LIKE). Prefer identifiers such as PR numbers, URLs, branch names, or symbols.',
                    ],
                    'after' => [
                        'type' => 'string',
                        'pattern' => '^\\d{4}-\\d{2}-\\d{2}( \\d{2}:\\d{2})?$',
                        'description' => 'Optional inclusive lower bound on memory date. Use YYYY-MM-DD or YYYY-MM-DD HH:MM. Filters observation timestamp and reflection created_at.',
                    ],
                    'before' => [
                        'type' => 'string',
                        'pattern' => '^\\d{4}-\\d{2}-\\d{2}( \\d{2}:\\d{2})?$',
                        'description' => 'Optional inclusive upper bound on memory date. Use YYYY-MM-DD or YYYY-MM-DD HH:MM. Filters observation timestamp and reflection created_at.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 50,
                        'description' => 'Maximum number of results to return (default 20, max 50).',
                    ],
                ],
            ],
            handler: new SearchToolHandler($query),
            promptSummary: 'Use om_search(query) to find retained OM memories across sessions by exact text, then recall(id, session_id) for source context.',
            promptGuidelines: [
                'Use om_search for PR numbers, issue ids, URLs, branch names, symbols, or exact phrases remembered from earlier sessions.',
                'Search defaults to all retained history in the configured OM database; pass after/before only to narrow by memory date.',
                'Results are bounded and include session_id, memory id, timestamp, and matching content. They are not injected into context automatically.',
                'OM may omit details or lag recent messages; canonical session events remain the source of truth after recall.',
                'Do not use om_search as semantic/topic search. Prefer exact identifiers when available.',
            ],
        ));

        $api->registerTool(new ToolRegistrationDTO(
            name: 'recall',
            // Faithful Pi port (recall-observation.ts): session-global + 12..64 prefix adaptations + optional cross-session session_id.
            description: 'Recover exact evidence and source context behind an observational-memory observation or reflection id. '
                .'Defaults to the current session; pass session_id from om_search to recall a prior session. '
                .'Use when compressed memory is important and original source context is needed before acting.',
            parametersJsonSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['id'],
                'properties' => [
                    'id' => [
                        'type' => 'string',
                        'pattern' => '^[a-f0-9]{12,64}$',
                        'description' => 'Full lowercase hex observation or reflection id, or a unique 12–64 character prefix, shown in compacted memory, /om-view, om_search, or a previous recall result. Must be a specific id; this tool does not search by topic.',
                    ],
                    'session_id' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'description' => 'Optional originating session/run id from om_search. Omit to keep current-session recall behavior.',
                    ],
                ],
            ],
            handler: new RecallToolHandler($query),
            promptSummary: 'Use recall(id) or recall(id, session_id) to recover exact source context behind OM memories when precision matters.',
            promptGuidelines: [
                'Use recall when you need exact wording, rationale, file paths, commands, errors, commits, user constraints, or provenance behind a remembered claim.',
                'Use recall when a broad reflection is relevant but you need its supporting observations or raw sources to continue safely.',
                'Use recall when the user asks why you believe something, what supports a memory, or what was decided earlier.',
                'When recalling a search hit from another session, pass that result session_id explicitly. Omitting session_id keeps current-session behavior.',
                'Do not use recall as semantic search or transcript browsing; you must already have a specific full id or unique lowercase 12–64 hex memory id.',
                'Do not recall every id preemptively. Recall only when exact source context will materially improve the next action.',
            ],
        ));

        $this->logger->info('om.extension.registered', [
            'component' => 'observational_memory',
            'event_type' => 'om.extension.registered',
            'handler_id' => ObserveBoundaryTerminalHook::HANDLER_ID,
            'reflect_handler_id' => ReflectGenerationJobHandler::HANDLER_ID,
        ]);
    }

    public function registerTui(TuiExtensionContextInterface $context): void
    {
        // Keep the live public context; resolve session id lazily on each command/poll.
        $this->sessionContext->bindTui($context);

        $databasePath = $this->databasePath;
        if (null === $databasePath || '' === $databasePath) {
            // register() always runs before registerTui when the extension is enabled.
            return;
        }

        $poller = new OmBackgroundStatusPoller($context, $databasePath, $this->logger);
        $context->onTick(static function () use ($poller): void {
            $poller->tick();
        });
    }
}
