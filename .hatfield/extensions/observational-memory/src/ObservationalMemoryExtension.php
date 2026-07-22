<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;
use Ineersa\Hatfield\ExtensionApi\Runtime\ExtensionEntrypointInterface;
use Ineersa\Hatfield\ExtensionApi\Runtime\RuntimeStartedEvent;
use Ineersa\Hatfield\ExtensionApi\Runtime\RuntimeStoppingEvent;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmConsumerEntrypoint;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmConsumerSupervisor;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmPaths;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Observational-memory extension registration surface.
 *
 * Host bootstrap only uses public Extension API + EventSubscriberInterface.
 * Messenger, Doctrine, and process supervision stay extension-local.
 */
final class ObservationalMemoryExtension implements HatfieldExtensionInterface, ExtensionEntrypointInterface, EventSubscriberInterface
{
    public const ENTRYPOINT_CONSUME = 'consume';

    private ?ExtensionApiInterface $api = null;

    private ?OmConsumerSupervisor $supervisor = null;

    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        // ExtensionManager instantiates extensions with new $className().
        // NullLogger is acceptable for the registration object; the consumer
        // entrypoint uses a privacy-safe stderr logger when run as a process.
        $this->logger = $logger ?? new NullLogger();
    }

    public function register(ExtensionApiInterface $api): void
    {
        $this->api = $api;
    }

    public static function entrypoints(): array
    {
        return [self::ENTRYPOINT_CONSUME];
    }

    public function runEntrypoint(string $entrypoint, ExtensionApiInterface $api): int
    {
        return match ($entrypoint) {
            self::ENTRYPOINT_CONSUME => (new OmConsumerEntrypoint($this->logger))->run($api),
            default => 1,
        };
    }

    public static function getSubscribedEvents(): array
    {
        return [
            RuntimeStartedEvent::class => 'onRuntimeStarted',
            RuntimeStoppingEvent::class => 'onRuntimeStopping',
        ];
    }

    public function onRuntimeStarted(RuntimeStartedEvent $event): void
    {
        if ($this->isConsumerProcess()) {
            return;
        }

        $api = $this->api;
        if (null === $api) {
            $this->logger->warning('om.supervisor.skip_no_api', [
                'component' => 'observational_memory',
                'event_type' => 'om.supervisor.skip_no_api',
                'session_id' => $event->sessionId,
            ]);

            return;
        }

        $settings = OmSettings::fromApi($api);
        if (!$settings->enabled) {
            $this->logger->info('om.supervisor.disabled', [
                'component' => 'observational_memory',
                'event_type' => 'om.supervisor.disabled',
                'session_id' => $event->sessionId,
            ]);

            return;
        }

        $paths = OmPaths::fromSettings($settings, $api->getCwd());
        $this->supervisor = new OmConsumerSupervisor($this->logger);
        $this->supervisor->start(
            applicationCommand: $event->applicationCommand,
            runtimeCwd: $event->runtimeCwd,
            sessionId: $event->sessionId,
            databasePath: $paths->databasePath,
        );
    }

    public function onRuntimeStopping(RuntimeStoppingEvent $event): void
    {
        if ($this->isConsumerProcess()) {
            return;
        }

        $this->supervisor?->stop($event->sessionId);
        $this->supervisor = null;
    }

    private function isConsumerProcess(): bool
    {
        $flag = $_ENV['HATFIELD_OM_CONSUMER'] ?? $_SERVER['HATFIELD_OM_CONSUMER'] ?? null;
        if (null === $flag || false === $flag || '' === $flag) {
            $env = getenv('HATFIELD_OM_CONSUMER');
            $flag = false === $env ? '0' : $env;
        }

        return '1' === (string) $flag || 'true' === strtolower((string) $flag);
    }
}
