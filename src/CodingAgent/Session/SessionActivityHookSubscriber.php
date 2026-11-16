<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\CodingAgent\Entity\HatfieldSessionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

/** Keeps resume recency current even when conversation activity changes no metadata. */
final readonly class SessionActivityHookSubscriber implements HookSubscriberInterface
{
    public function __construct(private HatfieldSessionRepository $sessions, private LoggerInterface $logger)
    {
    }

    public function handleAfterTurnCommit(AfterTurnCommitHookContext $context): AfterTurnCommitHookContext
    {
        if ([] === $context->events) {
            return $context;
        }

        try {
            $this->sessions->recordActivity($context->runId, Clock::get()->now());
        } catch (\Throwable $exception) {
            // Catalog recency is best-effort; canonical activity is already committed.
            $this->logger->warning('session.activity_update_failed', [
                'run_id' => $context->runId,
                'session_id' => $context->runId,
                'component' => 'session_activity',
                'event_type' => 'session.activity_update_failed',
                'exception_class' => $exception::class,
            ]);
        }

        return $context;
    }
}
