<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Symfony\Component\Validator\Constraints as Assert;

/** Worker-local resolved invocation input. Never serialized into execution envelopes. */
final readonly class ToolLaunchContextDTO
{
    public const string KIND_FORK = 'fork';

    public const string KIND_SUBAGENT = 'subagent';

    /**
     * @param list<AgentMessage> $forkMessages Owner-prepared fork snapshot only (unsanitized); empty for subagent. Worker sanitizer/compaction run after dispatch.
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: [self::KIND_FORK, self::KIND_SUBAGENT])]
        public string $kind,
        #[Assert\NotBlank]
        public string $producingRunId,
        #[Assert\GreaterThanOrEqual(0)]
        public int $producingTurnNo,
        #[Assert\NotBlank]
        public string $producingModel,
        public string $agentsContext = '',
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
