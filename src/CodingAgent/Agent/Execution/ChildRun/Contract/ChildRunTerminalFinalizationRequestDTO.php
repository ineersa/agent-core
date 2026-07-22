<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;

/**
 * Typed terminal finalization request: artifact outcome to persist plus optional run/timeout context for child-kind presentation.
 */
final readonly class ChildRunTerminalFinalizationRequestDTO
{
    private function __construct(
        public ChildRunTerminalFinalizationKindEnum $kind,
        public ChildRunTerminalOutcomeDTO $artifactOutcome,
        public ?RunState $childRunState = null,
        public ?int $timeoutSeconds = null,
    ) {
    }

    public static function persistOnly(ChildRunTerminalOutcomeDTO $artifactOutcome): self
    {
        return new self(ChildRunTerminalFinalizationKindEnum::PersistOnly, $artifactOutcome);
    }

    public static function singleCompleted(ChildRunIdentityDTO $identity, RunState $state): self
    {
        return new self(
            ChildRunTerminalFinalizationKindEnum::SingleCompleted,
            new ChildRunTerminalOutcomeDTO(
                identity: $identity,
                status: AgentArtifactStatusEnum::Completed,
                summary: null,
            ),
            childRunState: $state,
        );
    }

    public static function singleFailed(ChildRunIdentityDTO $identity, RunState $state): self
    {
        $errorMsg = $state->errorMessage ?? 'Run failed without error message.';

        return new self(
            ChildRunTerminalFinalizationKindEnum::SingleFailed,
            new ChildRunTerminalOutcomeDTO(
                identity: $identity,
                status: AgentArtifactStatusEnum::Failed,
                failureReason: $errorMsg,
                summary: $errorMsg,
            ),
            childRunState: $state,
        );
    }

    public static function singleChildCancelled(ChildRunIdentityDTO $identity, RunState $state): self
    {
        return new self(
            ChildRunTerminalFinalizationKindEnum::SingleChildCancelled,
            new ChildRunTerminalOutcomeDTO(
                identity: $identity,
                status: AgentArtifactStatusEnum::Cancelled,
                summary: 'Child run was cancelled.',
                childState: $state,
            ),
            childRunState: $state,
        );
    }

    public static function parallelRunTerminal(ChildRunIdentityDTO $identity, RunState $state): self
    {
        return new self(
            ChildRunTerminalFinalizationKindEnum::ParallelRunTerminal,
            new ChildRunTerminalOutcomeDTO(
                identity: $identity,
                status: AgentArtifactStatusEnum::Completed,
                summary: null,
            ),
            childRunState: $state,
        );
    }

    public static function singleTimeout(ChildRunIdentityDTO $identity, int $timeoutSeconds, ChildRunTerminalOutcomeDTO $artifactOutcome): self
    {
        return new self(
            ChildRunTerminalFinalizationKindEnum::SingleTimeout,
            $artifactOutcome,
            timeoutSeconds: $timeoutSeconds,
        );
    }
}
