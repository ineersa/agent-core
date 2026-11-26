<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Session;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\Hatfield\ExtensionApi\Session\Event\AssistantMessageCompletedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\BackgroundJobEndedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\BackgroundJobStartedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\ChildExecutionEndedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\ChildExecutionStartedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\InputRequestedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\InputRequestTypeEnum;
use Ineersa\Hatfield\ExtensionApi\Session\Event\InputResolvedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\ToolFinishedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\ToolStartedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\TurnCancelledEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\TurnCompletedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\TurnFailedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\TurnStartedEvent;

/** Maps one runtime event of a session's main run to its public extension event. */
final class ExtensionSessionEventMapper
{
    public static function map(RuntimeEvent $event): ?object
    {
        $session = $event->runId;
        $payload = $event->payload;
        if (0 === $event->seq) {
            return self::mapTransient($event);
        }

        return match ($event->type) {
            RuntimeEventTypeEnum::TurnStarted->value => new TurnStartedEvent($session, $event->seq, self::id($payload['trigger'] ?? null)),
            RuntimeEventTypeEnum::RunCompleted->value => new TurnCompletedEvent($session, $event->seq),
            RuntimeEventTypeEnum::RunFailed->value, RuntimeEventTypeEnum::TurnFailed->value => new TurnFailedEvent($session, $event->seq),
            RuntimeEventTypeEnum::RunCancelled->value, RuntimeEventTypeEnum::TurnCancelled->value => new TurnCancelledEvent($session, $event->seq),
            RuntimeEventTypeEnum::AssistantMessageCompleted->value => new AssistantMessageCompletedEvent($session, $event->seq, self::text($payload)),
            RuntimeEventTypeEnum::HumanInputRequested->value => isset($payload['approval_context'])
                ? self::requested($event, self::id($payload['request_id'] ?? $payload['question_id'] ?? null), InputRequestTypeEnum::Approval)
                : self::requested($event, self::id($payload['question_id'] ?? null), InputRequestTypeEnum::Question),
            RuntimeEventTypeEnum::ApprovalRequested->value => self::requested($event, self::id($payload['request_id'] ?? $payload['question_id'] ?? null), InputRequestTypeEnum::Approval),
            RuntimeEventTypeEnum::HumanInputAnswered->value, RuntimeEventTypeEnum::ApprovalApproved->value, RuntimeEventTypeEnum::ApprovalRejected->value => null === ($request = self::id($payload['request_id'] ?? $payload['question_id'] ?? null))
                ? null : new InputResolvedEvent($session, $event->seq, $request),
            RuntimeEventTypeEnum::ToolExecutionStarted->value => null === ($call = self::id($payload['tool_call_id'] ?? null))
                ? null : new ToolStartedEvent($session, $event->seq, $call, \is_string($payload['tool_name'] ?? null) ? $payload['tool_name'] : null),
            RuntimeEventTypeEnum::ToolExecutionCompleted->value, RuntimeEventTypeEnum::ToolExecutionFailed->value,
            RuntimeEventTypeEnum::ToolExecutionCancelled->value, RuntimeEventTypeEnum::ToolCallAcknowledged->value => null === ($call = self::id($payload['tool_call_id'] ?? null))
                ? null : new ToolFinishedEvent($session, $event->seq, $call),
            default => null,
        };
    }

    private static function mapTransient(RuntimeEvent $event): ?object
    {
        $payload = $event->payload;
        if (\in_array($event->type, [RuntimeEventTypeEnum::BackgroundJobStarted->value, RuntimeEventTypeEnum::BackgroundJobEnded->value], true)) {
            $owner = self::id($payload['owner_run_id'] ?? null);
            $job = $payload['job_id'] ?? null;
            if (null === $owner || !\is_string($job) || 1 !== preg_match('/\A[a-zA-Z0-9_.:-]{1,128}\z/', $job)) {
                return null;
            }

            return RuntimeEventTypeEnum::BackgroundJobStarted->value === $event->type
                ? new BackgroundJobStartedEvent($event->runId, $owner, $job) : new BackgroundJobEndedEvent($event->runId, $owner, $job);
        }
        $child = self::id($payload['child_run_id'] ?? null);
        $sequence = $payload['child_sequence'] ?? null;
        if (null === $child || $child === $event->runId || !\is_int($sequence) || $sequence <= 0) {
            return null;
        }
        $request = self::id($payload['request_id'] ?? null);

        return match ($event->type) {
            RuntimeEventTypeEnum::ChildExecutionStarted->value => new ChildExecutionStartedEvent($event->runId, $sequence, $child),
            RuntimeEventTypeEnum::ChildExecutionEnded->value => new ChildExecutionEndedEvent($event->runId, $sequence, $child),
            RuntimeEventTypeEnum::ChildInputObserved->value => null === $request ? null : match ($payload['phase'] ?? null) {
                'permission' => new InputRequestedEvent($event->runId, $sequence, $request, InputRequestTypeEnum::Approval, $child),
                'question' => new InputRequestedEvent($event->runId, $sequence, $request, InputRequestTypeEnum::Question, $child),
                'resolved' => new InputResolvedEvent($event->runId, $sequence, $request, $child),
                default => null,
            },
            default => null,
        };
    }

    private static function requested(RuntimeEvent $event, ?string $request, InputRequestTypeEnum $type): ?InputRequestedEvent
    {
        return null === $request ? null : new InputRequestedEvent($event->runId, $event->seq, $request, $type);
    }

    private static function id(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * Native messages carry ordered parts instead of flat text. The transcript
     * shows separate text parts as separate blocks, so they join with a blank line.
     *
     * @param array<string, mixed> $payload
     */
    private static function text(array $payload): string
    {
        $text = \is_string($payload['text'] ?? null) ? $payload['text'] : '';
        if ('' !== $text || !\is_array($payload['content'] ?? null)) {
            return $text;
        }
        $parts = [];
        foreach ($payload['content'] as $part) {
            if (\is_array($part) && 'text' === ($part['type'] ?? null) && \is_string($part['text'] ?? null) && '' !== $part['text']) {
                $parts[] = $part['text'];
            }
        }

        return implode("\n\n", $parts);
    }
}
