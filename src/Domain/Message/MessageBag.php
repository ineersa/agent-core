<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * MessageBag is a readonly value object that encapsulates a collection of domain messages. It provides a simple interface to retrieve the contained messages or instantiate an empty instance.
 */
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

    /**
     * Creates and returns a new empty MessageBag instance.
     */
    public static function empty(): self
    {
        return new self([]);
    }
}
