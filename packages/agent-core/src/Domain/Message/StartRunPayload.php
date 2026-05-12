<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

use Ineersa\AgentCore\Domain\Run\RunMetadata;
use Symfony\Component\Serializer\Attribute\SerializedName;

final readonly class StartRunPayload
{
    /**
     * @param list<AgentMessage> $messages
     */
    public function __construct(
        #[SerializedName('system_prompt')]
        public string $systemPrompt = '',
        public array $messages = [],
        public ?RunMetadata $metadata = null,
    ) {
    }
}
