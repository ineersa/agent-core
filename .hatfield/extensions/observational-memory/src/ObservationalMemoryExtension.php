<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory;

use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\ConfigurableExtensionInterface;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Command\OmViewCommandHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Compaction\OmBeforeCompactionHook;
use Ineersa\HatfieldExt\ObservationalMemory\Librarian\LibrarianProjectPolicy;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\ObserveBoundaryJobHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\ObserveBoundaryTerminalHook;
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmQueryService;
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmSessionContext;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\ObservationalMemoryConfiguration;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Tool\RecallToolHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Tool\SearchToolHandler;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/** Thin client registration: async boundary signals, projection, remote retrieval and /om-view. */
final class ObservationalMemoryExtension implements ConfigurableExtensionInterface, TuiExtensionInterface, LoggerAwareInterface
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

    public function getConfiguration(): ConfigurationInterface
    {
        return new ObservationalMemoryConfiguration();
    }

    public function register(ExtensionApiInterface $api): void
    {
        $projectAllowed = (new LibrarianProjectPolicy($api->exec(), $this->logger))->allows($api->getCwd());
        if (!$projectAllowed) {
            return;
        }
        // Selection enables registration; each runtime entry point enforces main-only OM.
        $rawSettings = $api->getSettings(OmSettings::SETTINGS_KEY);
        $settings = OmSettings::fromArray($rawSettings);
        $obsoleteKeys = array_values(array_intersect(ObservationalMemoryConfiguration::OBSOLETE_KEYS, array_keys($rawSettings)));
        if ([] !== $obsoleteKeys) {
            $this->logger->warning('om.settings.obsolete_keys_ignored: Librarian owns memory storage and processing; these keys are ignored and can be removed.', [
                'component' => 'observational_memory',
                'event_type' => 'om.settings.obsolete_keys_ignored',
                'run_id' => null, 'session_id' => null,
                'obsolete_keys' => $obsoleteKeys,
            ]);
        }
        $query = new OmQueryService($api, $settings, $this->logger);

        $api->registerExtensionAgentJobHandler(
            ObserveBoundaryTerminalHook::HANDLER_ID,
            new ObserveBoundaryJobHandler($this->logger),
        );

        $api->registerAfterTurnCommitHook(
            new ObserveBoundaryTerminalHook($api, $this->logger, $projectAllowed),
        );
        // One public hook for CompactRun (watermark) and snapshot/fork (null watermark).
        $api->registerBeforeCompactionHook(
            new OmBeforeCompactionHook($query, $this->logger),
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
            description: 'Find prior work across sessions using project-scoped hybrid keyword and semantic search through Librarian. partial: true means the index is still catching up and newer memories may be missing.'
                .' Searches retained memory content, not raw transcript events.',
            parametersJsonSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['query'],
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'description' => 'Keywords, identifiers, concepts, paraphrases, or natural-language descriptions of prior work. Phrases are search hints, not exact-match constraints.',
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
                        'description' => 'Maximum relevance-ranked results (default 20, max 50), not newest first. Retrieval uses bounded candidates; truncated: true means a candidate or result limit was reached, not that more relevant memories exist. No total or pagination.',
                    ],
                ],
            ],
            handler: new SearchToolHandler($query),
            mainOnly: true,
            promptSummary: 'memory_search query [after] [before] [limit=20] — search retained memories across sessions',
            promptGuidelines: [
                'Use memory_search when prior work, conversations, PRs, issues, or unfinished tasks may already exist, even if the user does not explicitly ask to search memory.',
                'A ranked hit may be unrelated; judge its content before using it as evidence. If there are no useful matches, reformulate the concept or add another known identifier or relevant detail.',
                'No hits is not proof the conversation never happened: observational memory can be incomplete or lag recent messages.',
                'Observation results include importance assigned when the memory was recorded; it is not a query match score or ranking signal.',
                'Use recall for source context behind useful hits.',
            ],
        ));

        $api->registerTool(new ToolRegistrationDTO(
            name: 'recall',
            // IDs resolve across the selected project, not just the current session.
            description: 'Recover exact evidence and source context for one selected memory across this project; the matched memory determines its source session. Ambiguous IDs return full IDs and memory content for a full-ID retry. Not semantic search or transcript browsing.',
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
                ],
            ],
            handler: new RecallToolHandler($query),
            mainOnly: true,
            promptSummary: 'recall id — retrieve source context for a selected memory',
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
        ]);
    }

    public function registerTui(TuiExtensionContextInterface $context): void
    {
        // Keep the live public context; resolve session id lazily on each command.
        $this->sessionContext->bindTui($context);
    }
}
