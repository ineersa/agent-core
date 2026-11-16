<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\CodingAgent\Entity\HatfieldSessionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

/** Tracks accepted user input, not the model/tool transitions it triggers. */
final readonly class SessionActivityHookSubscriber implements HookSubscriberInterface
{
    public function __construct(private HatfieldSessionRepository $sessions, private LoggerInterface $logger)
    {
    }

    public function handleAfterTurnCommit(AfterTurnCommitHookContext $context): AfterTurnCommitHookContext
    {
        if (!array_any($context->events, $this->isUserInput(...))) {
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

    private function isUserInput(AfterTurnCommitEventSummary $event): bool
    {
        if (RunEventTypeEnum::RunStarted->value === $event->type) {
            return true;
        }

        $kind = $event->payload['kind'] ?? null;
        if (RunEventTypeEnum::AgentCommandApplied->value === $event->type) {
            return \in_array($kind, [CoreCommandKind::HumanResponse, 'shell_command'], true);
        }
        if (RunEventTypeEnum::AgentCommandQueued->value !== $event->type
            || !\in_array($kind, [CoreCommandKind::Steer, CoreCommandKind::FollowUp, CoreCommandKind::AppendMessage], true)) {
            return false;
        }

        // Count mailbox acceptance once, not its later application. A user-role
        // message may be automatic traffic: use host provenance, never its text.
        $metadata = $event->payload['message']['metadata'] ?? [];
        foreach (['agent_communication', 'background_completion', 'agent_task'] as $key) {
            if (\is_array($event->payload[$key] ?? null) || \is_array($metadata[$key] ?? null)) {
                return false;
            }
        }

        return true !== ($metadata['system_reminder'] ?? null);
    }
}
