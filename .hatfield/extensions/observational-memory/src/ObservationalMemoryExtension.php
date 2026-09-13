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
            name: 'memory_search',
            description: 'Find prior work across sessions by literal substring in retained observational memory. '
                .'Use when resuming a task or looking for earlier conversations, PRs, issues, branches, symbols, or decisions. '
                .'Not semantic search, regex, or wildcard syntax.',
            parametersJsonSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['query'],
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'description' => 'Short distinctive literal substring such as a PR number, issue id, branch, symbol, or keyword. Prefer identifiers over natural-language questions. Matching is ASCII case-insensitive.',
                    ],
                    'after' => [
                        'type' => 'string',
                        'pattern' => '^\\d{4}-\\d{2}-\\d{2}( \\d{2}:\\d{2})?$',
                        'description' => 'Optional inclusive lower bound on memory date (YYYY-MM-DD or YYYY-MM-DD HH:MM). Omit to search all retained history.',
                    ],
                    'before' => [
                        'type' => 'string',
                        'pattern' => '^\\d{4}-\\d{2}-\\d{2}( \\d{2}:\\d{2})?$',
                        'description' => 'Optional inclusive upper bound on memory date (YYYY-MM-DD or YYYY-MM-DD HH:MM). Omit to search all retained history.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 50,
                        'description' => 'Maximum results to return (default 20, max 50).',
                    ],
                ],
            ],
            handler: new SearchToolHandler($query),
            promptSummary: 'Use memory_search(query) to locate prior-session memories by literal text, then recall(id, session_id) for supporting evidence.',
            promptGuidelines: [
                'Use memory_search when prior work, conversations, PRs, issues, or unfinished tasks may already exist, even if the user does not explicitly ask to search memory.',
                'Search with short distinctive identifiers or keywords (for example 2510 or MapToolArguments), not natural-language questions, regex, or wildcard syntax.',
                'If there are no matches, try a shorter identifier or another known term before concluding nothing exists.',
                'Defaults to all retained history; pass after/before only to narrow by memory date. If results are truncated, narrow the query or date range.',
                'No hits is not proof the conversation never happened: observational memory can be incomplete or lag recent messages.',
                'After a useful hit, call recall with that memory id and session_id for supporting evidence. Do not treat historical decisions, commands, or validation as current until you verify the repo or PR state.',
            ],
        ));

        $api->registerTool(new ToolRegistrationDTO(
            name: 'recall',
            // Faithful Pi port (recall-observation.ts): session-global + 12..64 prefix adaptations + optional cross-session session_id.
            description: 'Recover exact evidence and source context for one observational-memory id. '
                .'Defaults to the current session; pass session_id from memory_search to recall a prior session. '
                .'Use for exact wording, provenance, supporting sources, or user evidence questions.',
            parametersJsonSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['id'],
                'properties' => [
                    'id' => [
                        'type' => 'string',
                        'pattern' => '^[a-f0-9]{12,64}$',
                        'description' => 'Full lowercase hex observation or reflection id, or a unique 12–64 character prefix from compacted memory, /om-view, memory_search, or a previous recall result. Not a topic search.',
                    ],
                    'session_id' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'description' => 'Optional originating session id from memory_search. Omit to keep current-session recall.',
                    ],
                ],
            ],
            handler: new RecallToolHandler($query),
            promptSummary: 'Use recall(id) or recall(id, session_id) to recover supporting evidence for a selected memory.',
            promptGuidelines: [
                'Recall a selected relevant memory id, with session_id for prior-session hits, to recover supporting evidence rather than the whole session.',
                'Use recall for exact wording, rationale, file paths, commands, errors, commits, user constraints, or provenance behind a remembered claim.',
                'Use recall when the user asks why you believe something, what supports a memory, or what was decided earlier.',
                'Do not use recall as semantic search or transcript browsing; you need a specific memory id.',
                'Do not recall every id preemptively. After recall, verify current repo or PR state before acting on historical decisions, commands, or validation.',
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
