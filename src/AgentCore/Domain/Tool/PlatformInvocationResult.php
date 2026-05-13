<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;

final readonly class PlatformInvocationResult
{
    /**
     * @param list<DeltaInterface>      $deltas
     * @param array<string, int|float>  $usage
     * @param array<string, mixed>|null $error
     */
    public function __construct(
        public ?AssistantMessage $assistantMessage,
        public array $deltas = [],
        public array $usage = [],
        public ?string $stopReason = null,
        public ?array $error = null,
    ) {
    }

    /**
     * @return list<DeltaInterface>
     */
    public function deltas(): array
    {
        return $this->deltas;
    }
}
