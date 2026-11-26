<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory;

use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\ConfigurableExtensionInterface;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Command\OmViewCommandHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Compaction\OmBeforeCompactionHook;
use Ineersa\HatfieldExt\ObservationalMemory\Librarian\LibrarianProjectPolicy;
use Ineersa\HatfieldExt\ObservationalMemory\Librarian\OmSessionDeletedHook;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\ObserveBoundaryJobHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\ObserveBoundaryTerminalHook;
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmQueryService;
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmSessionContext;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\ObservationalMemoryConfiguration;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Tool\OmToolRegistrationFactory;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/** Thin client registration: async boundary signals, projection, remote retrieval and /om-view. */
final class ObservationalMemoryExtension implements ConfigurableExtensionInterface, LoggerAwareInterface
{
    private LoggerInterface $logger;

    public function __construct()
    {
        $this->logger = new NullLogger();
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
        $rawSettings = $api->getSettings(OmSettings::SETTINGS_KEY);
        $settings = OmSettings::fromArray($rawSettings);
        $exec = $api->exec();
        // Deletion targets carry their own project identity, independent of the active checkout.
        $api->registerSessionDeletedHook(new OmSessionDeletedHook($settings->librarian, $exec, $this->logger));
        $projectAllowed = (new LibrarianProjectPolicy($exec, $this->logger))->allows($api->getCwd());
        if (!$projectAllowed) {
            return;
        }
        // Selection enables registration; each runtime entry point enforces main-only OM.
        $obsoleteKeys = array_values(array_intersect(ObservationalMemoryConfiguration::OBSOLETE_KEYS, array_keys($rawSettings)));
        if ([] !== $obsoleteKeys) {
            $this->logger->warning('om.settings.obsolete_keys_ignored: Librarian owns memory storage and processing; these keys are ignored and can be removed.', [
                'component' => 'observational_memory',
                'event_type' => 'om.settings.obsolete_keys_ignored',
                'run_id' => null, 'session_id' => null,
                'obsolete_keys' => $obsoleteKeys,
            ]);
        }
        $query = new OmQueryService($exec, $api->getCwd(), $api->isChildRun(...), $settings, $this->logger);

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
            new OmViewCommandHandler($query, new OmSessionContext($api->session()), $this->logger),
        );

        $api->registerTool(OmToolRegistrationFactory::search($query));
        $api->registerTool(OmToolRegistrationFactory::recall($query));

        $this->logger->info('om.extension.registered', [
            'component' => 'observational_memory',
            'event_type' => 'om.extension.registered',
            'handler_id' => ObserveBoundaryTerminalHook::HANDLER_ID,
        ]);
    }
}
