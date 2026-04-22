<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

final readonly class MessageBag
{
    /**
     * Initializes the message bag with the provided array of messages.
     *
     * @param list<object> $messages
     */
    public function __construct(public array $messages)
    {
    }

    /**
     * Returns the complete array of stored messages.
     *
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
