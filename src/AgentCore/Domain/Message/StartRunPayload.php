<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

use Ineersa\AgentCore\Domain\Run\RunMetadata;

final readonly class StartRunPayload
{
    /**
     * @param list<AgentMessage> $messages
     */
    public function __construct(
        public string $systemPrompt = '',
        public array $messages = [],
        public ?RunMetadata $metadata = null,
    ) {
    }
}
