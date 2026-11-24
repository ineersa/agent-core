<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Extension\ExtensionManager;
use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiBackgroundSnapshotDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiBackgroundJobObserverInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiBackgroundSnapshotObserverInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiRunObserverInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiSessionCloseObserverInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiSessionObserverInterface;
use Ineersa\Tui\Runtime\BridgeTuiExtensionContext;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionLifecycleEventTypeEnum;
use Psr\Log\LoggerInterface;

final class TuiProjectExtensionRegistrar implements TuiListenerRegistrar
{
    public function __construct(
        private readonly ExtensionManager $extensionManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $bridge = new BridgeTuiExtensionContext($context, $this->logger);
        $context->lifecycle->subscribeBackgroundSnapshot($bridge->rememberBackgroundSample(...));
        foreach ($this->extensionManager->getTuiExtensions() as $extension) {
            if ($extension instanceof TuiExtensionInterface) {
                $extension->registerTui($bridge);
            }
            if ($extension instanceof TuiBackgroundJobObserverInterface) {
                $context->sessionServices->parentPoller->observeBackgroundJob($extension);
            }
            if ($extension instanceof TuiBackgroundSnapshotObserverInterface) {
                $context->lifecycle->subscribeBackgroundSnapshot(function (TuiBackgroundSnapshotDTO $snapshot) use ($extension): void {
                    try {
                        $extension->onTuiBackgroundSnapshot($snapshot);
                    } catch (\Throwable $exception) {
                        $this->logger->warning('extension.tui_background_snapshot_observer_failed', [
                            'component' => 'tui_extension', 'event_type' => 'tui_background_snapshot_observer_failed',
                            'run_id' => $snapshot->sessionId, 'session_id' => $snapshot->sessionId, 'extension' => $extension::class, 'exception_class' => $exception::class,
                        ]);
                    }
                });
            }
            if ($extension instanceof TuiRunObserverInterface) {
                $context->sessionServices->parentPoller->observeRun($extension);
            }
            if ($extension instanceof TuiSessionCloseObserverInterface) {
                $context->lifecycle->subscribe(function (TuiSessionLifecycleEventTypeEnum $event) use ($bridge, $extension): void {
                    if (TuiSessionLifecycleEventTypeEnum::SessionGracefullyClosing !== $event || '' === ($sessionId = $bridge->getSessionId())) {
                        return;
                    }
                    try {
                        $extension->onTuiSessionClosing($sessionId);
                    } catch (\Throwable $exception) {
                        $this->logger->warning('extension.tui_session_close_observer_failed', [
                            'component' => 'tui_extension', 'event_type' => 'tui_session_close_observer_failed',
                            'run_id' => $sessionId, 'session_id' => $sessionId,
                            'extension' => $extension::class, 'exception_class' => $exception::class,
                        ]);
                    }
                });
            }
            if ($extension instanceof TuiSessionObserverInterface) {
                $context->lifecycle->subscribe(function (TuiSessionLifecycleEventTypeEnum $event) use ($bridge, $extension): void {
                    if (!\in_array($event, [TuiSessionLifecycleEventTypeEnum::SessionStarted, TuiSessionLifecycleEventTypeEnum::SessionResumed], true)
                        || '' === ($sessionId = $bridge->getSessionId())) {
                        return;
                    }

                    try {
                        $extension->onTuiSessionSelected($sessionId, TuiSessionLifecycleEventTypeEnum::SessionResumed === $event);
                    } catch (\Throwable $exception) {
                        $this->logger->warning('extension.tui_session_observer_failed', [
                            'component' => 'tui_extension', 'event_type' => 'tui_session_observer_failed',
                            'run_id' => $sessionId, 'session_id' => $sessionId,
                            'extension' => $extension::class, 'exception_class' => $exception::class,
                        ]);
                    }
                });
            }
        }
    }
}
