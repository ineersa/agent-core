<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Owner-prepared immutable invocation input for child-launching tools.
 *
 * Attached only to fork/subagent ExecuteToolCall envelopes. Ordinary tools keep
 * a null launch context so their wire/batch payload stays lean. The producing
 * run/turn/model and optional fork message snapshot remain fixed after dispatch
 * even if the parent later changes.
 */
final readonly class ToolLaunchContextDTO
{
    public const string KIND_FORK = 'fork';

    public const string KIND_SUBAGENT = 'subagent';

    /**
     * @param list<AgentMessage> $forkMessages Owner-prepared fork snapshot only (unsanitized); empty for subagent. Worker sanitizer/compaction run after dispatch.
     */
    public function __construct(
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        #[Assert\NotBlank]
        #[Assert\Choice(choices: [self::KIND_FORK, self::KIND_SUBAGENT])]
        public string $kind,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        #[Assert\NotBlank]
        public string $producingRunId,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        #[Assert\GreaterThanOrEqual(0)]
        public int $producingTurnNo,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        #[Assert\NotBlank]
        public string $producingModel,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public string $agentsContext = '',
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        #[Assert\Valid]
        public array $forkMessages = [],
    ) {
    }

    public function isFork(): bool
    {
        return self::KIND_FORK === $this->kind;
    }

    public function isSubagent(): bool
    {
        return self::KIND_SUBAGENT === $this->kind;
    }
}
