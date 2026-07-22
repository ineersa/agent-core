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
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Observational-memory extension registration surface.
 *
 * Host bootstrap only uses public Extension API + EventSubscriberInterface.
 * Messenger, Doctrine, and process supervision stay extension-local.
 *
 * Logger injection: ExtensionManager calls setLogger() with the process-local
 * host logger before register() for LoggerAwareInterface extensions.
 */
final class ObservationalMemoryExtension implements HatfieldExtensionInterface, ExtensionEntrypointInterface, EventSubscriberInterface, LoggerAwareInterface
{
    public const ENTRYPOINT_CONSUME = 'consume';

    private const float SUPERVISE_INTERVAL_SECONDS = 5.0;

    private ?ExtensionApiInterface $api = null;

    private ?OmConsumerSupervisor $supervisor = null;

    private ?string $superviseWatcherId = null;

    private ?LoggerInterface $logger = null;

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
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
            self::ENTRYPOINT_CONSUME => (new OmConsumerEntrypoint($this->requireLogger()))->run($api),
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
        // Consumer process must never start a nested supervisor/watcher.
        if ($this->isConsumerProcess()) {
            return;
        }

        $logger = $this->requireLogger();
        $api = $this->api;
        if (null === $api) {
            $logger->warning('om.supervisor.skip_no_api', [
                'component' => 'observational_memory',
                'event_type' => 'om.supervisor.skip_no_api',
                'session_id' => $event->sessionId,
            ]);

            return;
        }

        $settings = OmSettings::fromApi($api);
        if (!$settings->enabled) {
            $logger->info('om.supervisor.disabled', [
                'component' => 'observational_memory',
                'event_type' => 'om.supervisor.disabled',
                'session_id' => $event->sessionId,
            ]);

            return;
        }

        $paths = OmPaths::fromSettings($settings, $api->getCwd());
        $this->cancelSuperviseWatcher();
        $this->supervisor = new OmConsumerSupervisor($logger);
        $this->supervisor->start(
            applicationCommand: $event->applicationCommand,
            runtimeCwd: $event->runtimeCwd,
            sessionId: $event->sessionId,
            databasePath: $paths->databasePath,
        );

        // Periodic restart/health checks on the owning controller Revolt loop.
        // No host OM-specific polling: the extension registers its own watcher.
        $supervisor = $this->supervisor;
        $this->superviseWatcherId = EventLoop::repeat(
            self::SUPERVISE_INTERVAL_SECONDS,
            static function () use ($supervisor): void {
                $supervisor->supervise();
            },
        );
    }

    public function onRuntimeStopping(RuntimeStoppingEvent $event): void
    {
        if ($this->isConsumerProcess()) {
            return;
        }

        $this->cancelSuperviseWatcher();
        $this->supervisor?->stop();
        $this->supervisor = null;
    }

    private function cancelSuperviseWatcher(): void
    {
        if (null === $this->superviseWatcherId) {
            return;
        }

        EventLoop::cancel($this->superviseWatcherId);
        $this->superviseWatcherId = null;
    }

    private function requireLogger(): LoggerInterface
    {
        if (null === $this->logger) {
            throw new \LogicException('ObservationalMemoryExtension requires setLogger() before use. ExtensionManager must inject LoggerAwareInterface loggers before register().');
        }

        return $this->logger;
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
