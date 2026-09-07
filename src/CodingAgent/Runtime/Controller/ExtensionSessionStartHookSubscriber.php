<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\CodingAgent\Extension\ChildRunExtensionAllowlistReaderInterface;
use Ineersa\CodingAgent\Extension\ExtensionHookRegistry;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterSessionStartHookContextDTO;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Bridges controller session start to public ExtensionApi session-start hooks.
 *
 * Fires in the controller process (async extension_agent transport available)
 * before consumers launch and before any user turn.
 */
final readonly class ExtensionSessionStartHookSubscriber
{
    public function __construct(
        private ExtensionHookRegistry $hookRegistry,
        private LoggerInterface $logger,
        private ?ChildRunExtensionAllowlistReaderInterface $extensionAllowlistReader = null,
    ) {
    }

    #[AsEventListener(event: ControllerSessionStartingEvent::class)]
    public function onSessionStarting(ControllerSessionStartingEvent $event): void
    {
        $dto = new AfterSessionStartHookContextDTO(runId: $event->sessionId);
        $allowed = $this->extensionAllowlistReader?->readAllowedExtensions($event->sessionId);

        foreach ($this->hookRegistry->sessionStartHooks($allowed) as $hook) {
            try {
                $hook->onAfterSessionStart($dto);
            } catch (\Throwable $e) {
                $this->logger->warning('extension.session_start_hook_failed', [
                    'component' => 'extension_session_start_hook',
                    'event_type' => 'session_start_hook_failed',
                    'run_id' => $event->sessionId,
                    'hook' => $hook::class,
                    'exception_class' => $e::class,
                ]);
            }
        }
    }
}
