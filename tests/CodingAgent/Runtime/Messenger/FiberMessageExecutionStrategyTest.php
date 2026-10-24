<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\CodingAgent\Runtime\Messenger\FiberMessageExecutionStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\AckStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;

use function Amp\delay;

/**
 * @covers \Ineersa\CodingAgent\Runtime\Messenger\FiberMessageExecutionStrategy
 */
final class FiberMessageExecutionStrategyTest extends TestCase
{
    public function testRejectsNonPositiveConcurrencyLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FiberMessageExecutionStrategy($this->createStub(MessageBusInterface::class), 0);
    }

    public function testAdmitsUpToCapacityThenPausesAndDrainsDistinctResults(): void
    {
        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $envelope = Envelope::wrap($message, $stamps);
                /** @var AckStamp|null $ack */
                $ack = $envelope->last(AckStamp::class);
                $payload = $envelope->getMessage();
                if (!$payload instanceof DelayProbeMessage) {
                    throw new \LogicException('Unexpected message type.');
                }
                delay($payload->delaySeconds);
                $handled = $envelope->with(new HandledStamp($payload->id, 'test'));
                $ack?->ack($handled);

                return $handled;
            }
        };

        $strategy = new FiberMessageExecutionStrategy($bus, 2);
        $handled = [];
        $onHandled = static function (Envelope $envelope, string $transportName, bool $acked, ?\Throwable $error) use (&$handled): void {
            self::assertTrue($acked);
            self::assertNull($error);
            self::assertSame('llm', $transportName);
            $message = $envelope->getMessage();
            self::assertInstanceOf(DelayProbeMessage::class, $message);
            $handled[] = $message->id;
        };

        $started = hrtime(true);
        $strategy->execute(new Envelope(new DelayProbeMessage('a', 0.05)), 'llm', $onHandled);
        $strategy->execute(new Envelope(new DelayProbeMessage('b', 0.05)), 'llm', $onHandled);
        $this->assertTrue($strategy->shouldPauseConsumption());
        $this->assertSame(2, $strategy->pendingCount());
        $this->assertSame(2, $strategy->peakInFlight());

        $this->assertTrue($strategy->wait($onHandled, 0.2));
        $this->assertFalse($strategy->shouldPauseConsumption());

        $strategy->execute(new Envelope(new DelayProbeMessage('c', 0.05)), 'llm', $onHandled);
        $this->assertSame(2, $strategy->peakInFlight());

        while ($strategy->pendingCount() > 0) {
            $this->assertTrue($strategy->wait($onHandled, 0.2));
        }

        $elapsedMs = (hrtime(true) - $started) / 1e6;
        sort($handled);
        $this->assertSame(['a', 'b', 'c'], $handled);
        $this->assertLessThan(160.0, $elapsedMs, 'Three 50ms overlapping handlers must not serialize fully.');
        $strategy->shutdown();
        $this->assertSame(0, $strategy->pendingCount());
    }
}

final readonly class DelayProbeMessage
{
    public function __construct(
        public string $id,
        public float $delaySeconds,
    ) {
    }
}
