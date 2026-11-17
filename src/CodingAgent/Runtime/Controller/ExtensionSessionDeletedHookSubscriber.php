<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\CodingAgent\Extension\ExtensionHookRegistry;
use Ineersa\CodingAgent\Session\Event\SessionDeletedEvent;
use Ineersa\Hatfield\ExtensionApi\DTO\AfterSessionDeletedHookContextDTO;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class ExtensionSessionDeletedHookSubscriber
{
    public function __construct(private ExtensionHookRegistry $hookRegistry, private LoggerInterface $logger)
    {
    }

    #[AsEventListener]
    public function onSessionDeleted(SessionDeletedEvent $event): void
    {
        $context = new AfterSessionDeletedHookContextDTO($event->runId, $event->cwd, $event->parentRunId);
        foreach ($this->hookRegistry->sessionDeletedHooks() as $hook) {
            try {
                $hook->onAfterSessionDeleted($context);
            } catch (\Throwable $error) {
                $this->logger->warning('extension.session_deleted_hook_failed', [
                    'component' => 'extension_session_deleted_hook', 'event_type' => 'session_deleted_hook_failed',
                    'run_id' => $event->runId, 'session_id' => $event->runId,
                    'hook' => $hook::class, 'exception_class' => $error::class,
                ]);
            }
        }
    }
}
