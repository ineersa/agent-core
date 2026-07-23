<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmConsumerSupervisor;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmPaths;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Hatfield registration surface for observational memory.
 *
 * Lifecycle uses native Symfony ConsoleEvents only — no custom Hatfield
 * RuntimeStarted/Stopping DTOs and no extension:run entrypoints.
 *
 * The extension-owned supervisor starts the package's own bin/console
 * messenger:consume process against private om.sqlite.
 */
final class ObservationalMemoryExtension implements HatfieldExtensionInterface, EventSubscriberInterface, LoggerAwareInterface
{
    private const float SUPERVISE_INTERVAL_SECONDS = 5.0;

    private ?ExtensionApiInterface $api = null;

    private ?OmConsumerSupervisor $supervisor = null;

    private ?string $superviseWatcherId = null;

    private ?LoggerInterface $logger = null;

    private bool $started = false;

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function register(ExtensionApiInterface $api): void
    {
        $this->api = $api;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // After ExtensionLoaderSubscriber (default priority 0) has loaded extensions.
            ConsoleEvents::COMMAND => ['onConsoleCommand', -32],
            ConsoleEvents::TERMINATE => 'onConsoleTerminate',
            ConsoleEvents::ERROR => 'onConsoleError',
        ];
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        if ($this->started) {
            return;
        }

        $command = $event->getCommand();
        if (null === $command) {
            return;
        }

        // Public console name only — no CodingAgent class/namespace coupling.
        if ('agent' !== $command->getName()) {
            return;
        }

        // Controllers and messenger workers also run `agent --controller` or other
        // processes; start only the interactive owning agent process. Controller
        // mode is selected via --controller; headless via --headless.
        $input = $event->getInput();
        if ($input->hasParameterOption(['--controller'], true)
            || $input->hasParameterOption(['--headless'], true)
        ) {
            return;
        }

        $logger = $this->requireLogger();
        $api = $this->api;
        if (null === $api) {
            $logger->warning('om.supervisor.skip_no_api', [
                'component' => 'observational_memory',
                'event_type' => 'om.supervisor.skip_no_api',
            ]);

            return;
        }

        $settings = OmSettings::fromApi($api);
        if (!$settings->enabled) {
            $logger->info('om.supervisor.disabled', [
                'component' => 'observational_memory',
                'event_type' => 'om.supervisor.disabled',
            ]);

            return;
        }

        $packageRoot = \dirname(__DIR__);
        $paths = OmPaths::fromSettings($settings, $api->getCwd(), $packageRoot);
        $sessionId = $this->resolveSessionId();

        $this->cancelSuperviseWatcher();
        $this->supervisor = new OmConsumerSupervisor($logger);

        try {
            $this->supervisor->start(
                consolePath: $paths->consolePath,
                packageRoot: $paths->packageRoot,
                sessionId: $sessionId,
                databasePath: $paths->databasePath,
            );
        } catch (\Throwable $e) {
            $logger->error('om.supervisor.start_failed', [
                'component' => 'observational_memory',
                'event_type' => 'om.supervisor.start_failed',
                'session_id' => $sessionId,
                'exception_class' => $e::class,
            ]);
            $this->supervisor = null;

            return;
        }

        $supervisor = $this->supervisor;
        $this->superviseWatcherId = EventLoop::repeat(
            self::SUPERVISE_INTERVAL_SECONDS,
            static function () use ($supervisor): void {
                $supervisor->supervise();
            },
        );
        $this->started = true;
    }

    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        unset($event);
        $this->shutdownSupervisor();
    }

    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        unset($event);
        $this->shutdownSupervisor();
    }

    private function shutdownSupervisor(): void
    {
        if (!$this->started && null === $this->supervisor) {
            return;
        }

        $this->cancelSuperviseWatcher();
        $this->supervisor?->stop();
        $this->supervisor = null;
        $this->started = false;
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

    private function resolveSessionId(): string
    {
        $fromEnv = $_ENV['HATFIELD_SESSION_ID'] ?? $_SERVER['HATFIELD_SESSION_ID'] ?? null;
        if (\is_string($fromEnv) && '' !== $fromEnv) {
            return $fromEnv;
        }

        return 'agent-'.getmypid();
    }
}
