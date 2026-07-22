<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Runtime;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Messenger\OmMessengerRuntime;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmDatabase;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmSchemaMigrator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;

/**
 * Process entrypoint for the OM consumer (`extension:run … consume`).
 *
 * Boots private DB + Messenger Worker. Uses process-local ExtensionApi for
 * future Observer/Reflector model calls (OM-03+); this preview does not invoke models.
 */
final class OmConsumerEntrypoint
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(ExtensionApiInterface $api): int
    {
        $settings = OmSettings::fromApi($api);
        $paths = OmPaths::fromSettings($settings, $api->getCwd());

        $override = $_ENV['HATFIELD_OM_DATABASE_PATH'] ?? $_SERVER['HATFIELD_OM_DATABASE_PATH'] ?? null;
        if (\is_string($override) && '' !== $override) {
            $paths = new OmPaths($override, \dirname($override));
        }

        $this->logger->info('om.consumer.start', [
            'component' => 'observational_memory',
            'event_type' => 'om.consumer.start',
            // Path only — never log message bodies or observation content.
            'database_basename' => basename($paths->databasePath),
        ]);

        try {
            $database = OmDatabase::connect($paths->databasePath);
            (new OmSchemaMigrator($database->connection(), $this->logger))->migrate();
            $runtime = OmMessengerRuntime::create($database, $api, $this->logger);
            $runtime->run();
        } catch (\Throwable $e) {
            $this->logger->error('om.consumer.failed', [
                'component' => 'observational_memory',
                'event_type' => 'om.consumer.failed',
                'exception_class' => $e::class,
            ]);

            return Command::FAILURE;
        }

        $this->logger->info('om.consumer.stopped', [
            'component' => 'observational_memory',
            'event_type' => 'om.consumer.stopped',
        ]);

        return Command::SUCCESS;
    }
}
