<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

final readonly class ModelInvocationInput
{
    /**
     * @param list<AgentMessage>|null $messages
     */
    public function __construct(
        public ?string $runId = null,
        public ?int $turnNo = null,
        public ?string $stepId = null,
        public ?string $contextRef = null,
        public ?string $toolsRef = null,
        public ?array $messages = null,
    ) {
    }
}
