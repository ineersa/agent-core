<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

final readonly class MessageBag
{
    /**
     * @param list<object> $messages
     */
    public function __construct(public array $messages)
    {
    }

    /**
     * @return list<object>
     */
    public function all(): array
    {
        return $this->messages;
    }

    public static function empty(): self
    {
        return new self([]);
    }
}
