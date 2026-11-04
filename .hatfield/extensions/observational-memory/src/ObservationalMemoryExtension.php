<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory;

use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\ConfigurableExtensionInterface;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
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
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\ObservationalMemoryConfiguration;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmPaths;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Semantic\SemanticIndexJobHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Semantic\SemanticIndexStartupHook;
use Ineersa\HatfieldExt\ObservationalMemory\Tool\RecallToolHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Tool\SearchToolHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Tui\OmBackgroundStatusPoller;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Config\Definition\ConfigurationInterface;

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
final class ObservationalMemoryExtension implements ConfigurableExtensionInterface, TuiExtensionInterface, LoggerAwareInterface
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

    public function getConfiguration(): ConfigurationInterface
    {
        return new ObservationalMemoryConfiguration();
    }

    public function register(ExtensionApiInterface $api): void
    {
        // Presence on extensions.enabled is the sole enable switch.
        $settings = OmSettings::fromApi($api);
        $this->databasePath = OmPaths::fromSettings($settings, $api->getCwd())->databasePath;
        $query = new OmQueryService($api, $settings, $this->logger);
        $hybrid = null !== $settings->semantic;
        if ($hybrid) {
            $api->registerExtensionAgentJobHandler(SemanticIndexJobHandler::HANDLER_ID, new SemanticIndexJobHandler($this->logger));
            $api->registerSessionStartHook(new SemanticIndexStartupHook($api, $settings));
        }

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
            description: ($hybrid ? 'Find prior work across sessions using hybrid BM25 keyword and semantic vector search. partial: true means the index is still catching up and newer memories may be missing.' : 'Find prior work across sessions by one contiguous literal substring. Not semantic search, regex, or wildcard syntax.')
                .' Searches retained memory content, not raw transcript events.',
            parametersJsonSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['query'],
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'description' => $hybrid ? 'Keywords, identifiers, concepts, paraphrases, or natural-language descriptions of prior work. Phrases are search hints, not exact-match constraints.' : 'One contiguous literal substring such as a PR number, issue id, branch, symbol, or exact phrase. Multi-word queries match that exact phrase, not AND of separate words. Prefer a single identifier. Matching is ASCII case-insensitive.',
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
                        'description' => $hybrid ? 'Maximum relevance-ranked results (default 20, max 50), not newest first. Retrieval uses bounded candidates; truncated: true means a candidate or result limit was reached, not that more relevant memories exist. No total or pagination.' : 'Maximum results (default 20, max 50), newest first. truncated: true means older matches were omitted; narrow the query or date range. No total or pagination.',
                    ],
                ],
            ],
            handler: new SearchToolHandler($query),
            promptSummary: 'memory_search query [after] [before] [limit=20] — search retained memories across sessions',
            promptGuidelines: [
                'Use memory_search when prior work, conversations, PRs, issues, or unfinished tasks may already exist, even if the user does not explicitly ask to search memory.',
                $hybrid ? 'A ranked hit may be unrelated; judge its content before using it as evidence. If there are no useful matches, reformulate the concept or add another known identifier or relevant detail.' : 'Inspect hits before using them as evidence. If there are no matches, try one word or another known term.',
                'No hits is not proof the conversation never happened: observational memory can be incomplete or lag recent messages.',
                'Observation results include importance assigned when the memory was recorded; it is not a query match score or ranking signal.',
                'Use recall for source context behind useful hits.',
            ],
        ));

        $api->registerTool(new ToolRegistrationDTO(
            name: 'recall',
            // Faithful Pi port (recall-observation.ts): session-global + 12..64 prefix adaptations + optional cross-session session_id.
            description: 'Recover exact evidence and source context for one selected memory, not the whole session. Not semantic search or transcript browsing.',
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
                        'description' => 'Originating session_id from memory_search for prior-session hits. Omit to recall from the current session.',
                    ],
                ],
            ],
            handler: new RecallToolHandler($query),
            promptSummary: 'recall id [session_id] — retrieve source context for a selected memory',
            promptGuidelines: [
                'Use recall for exact wording, rationale, file paths, commands, errors, commits, user constraints, or provenance behind a remembered claim.',
                'Use recall when the user asks why you believe something, what supports a memory, or what was decided earlier.',
                'Recall useful evidence, not every id preemptively. Verify current repo or PR state before acting on historical decisions, commands, or validation.',
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
