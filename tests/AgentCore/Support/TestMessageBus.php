<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Collecting MessageBusInterface implementation for tests.
 *
 * Records every dispatched message object in a public array and returns a
 * plain Envelope. Use this instead of defining per-file collecting bus
 * classes that all implement the same three-line dispatch method.
 *
 * Usage:
 *   $bus = new TestMessageBus();
 *   $sut->dispatch($bus, $message);
 *   self::assertCount(1, $bus->messages);
 *   self::assertInstanceOf(ExpectedMessage::class, $bus->messages[0]);
 */
final class TestMessageBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    /** @var list<Envelope> */
    public array $envelopes = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message;
        $envelope = new Envelope($message, $stamps);
        $this->envelopes[] = $envelope;

        return $envelope;
    }
}
