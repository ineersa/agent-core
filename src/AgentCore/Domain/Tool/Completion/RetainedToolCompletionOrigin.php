<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool\Completion;

/** Runtime-owned origin identity for one retained tool_completion message. */
final readonly class RetainedToolCompletionOrigin
{
    public function __construct(
        public string $controllerRunId,
        public string $sourceRunId,
        public int $originTurnNo,
        public string $originStepId,
        public string $toolCallId,
        public string $invocationId,
        public string $executionOwnerKind,
        public ?RetainedToolCompletionSourceEventReference $acceptedSourceEventReference = null,
    ) {
        foreach ([
            'controllerRunId' => $this->controllerRunId,
            'sourceRunId' => $this->sourceRunId,
            'originStepId' => $this->originStepId,
            'toolCallId' => $this->toolCallId,
            'invocationId' => $this->invocationId,
            'executionOwnerKind' => $this->executionOwnerKind,
        ] as $field => $value) {
            if ('' === trim($value)) {
                throw new \InvalidArgumentException(\sprintf('Retained origin %s must be nonblank.', $field));
            }
        }
        if ($this->originTurnNo < 0) {
            throw new \InvalidArgumentException('Retained origin turn must be non-negative.');
        }
    }

    public function withAcceptedSourceEventReference(RetainedToolCompletionSourceEventReference $reference): self
    {
        return new self(
            controllerRunId: $this->controllerRunId,
            sourceRunId: $this->sourceRunId,
            originTurnNo: $this->originTurnNo,
            originStepId: $this->originStepId,
            toolCallId: $this->toolCallId,
            invocationId: $this->invocationId,
            executionOwnerKind: $this->executionOwnerKind,
            acceptedSourceEventReference: $reference,
        );
    }

    /**
     * Semantic fingerprint origin excludes accepted_source_event_reference.
     */
    public function withoutAcceptedSourceEventReference(): self
    {
        if (null === $this->acceptedSourceEventReference) {
            return $this;
        }

        return new self(
            controllerRunId: $this->controllerRunId,
            sourceRunId: $this->sourceRunId,
            originTurnNo: $this->originTurnNo,
            originStepId: $this->originStepId,
            toolCallId: $this->toolCallId,
            invocationId: $this->invocationId,
            executionOwnerKind: $this->executionOwnerKind,
        );
    }
}
