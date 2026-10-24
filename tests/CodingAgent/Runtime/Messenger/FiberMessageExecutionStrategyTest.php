<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Amp\DeferredFuture;
use Ineersa\CodingAgent\Runtime\Messenger\FiberMessageExecutionStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\AckStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;

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
            /** @var array<string, DeferredFuture> */
            public array $started = [];
            /** @var array<string, DeferredFuture> */
            public array $release = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $envelope = Envelope::wrap($message, $stamps);
                $id = $envelope->getMessage()->id;
                $this->started[$id]->complete();
                $this->release[$id]->getFuture()->await();
                $handled = $envelope->with(new HandledStamp($id, 'test'));
                $envelope->last(AckStamp::class)?->ack($handled);

                return $handled;
            }
        };
        foreach (['a', 'b', 'c'] as $id) {
            $bus->started[$id] = new DeferredFuture();
            $bus->release[$id] = new DeferredFuture();
        }
        $strategy = new FiberMessageExecutionStrategy($bus, 2);
        $handled = [];
        $onHandled = static function (Envelope $envelope, string $transportName, bool $acked, ?\Throwable $error) use (&$handled): void {
            self::assertTrue($acked);
            self::assertNull($error);
            self::assertSame('llm', $transportName);
            $handled[] = $envelope->getMessage()->id;
        };
        try {
            $strategy->execute(new Envelope((object) ['id' => 'a']), 'llm', $onHandled);
            $strategy->execute(new Envelope((object) ['id' => 'b']), 'llm', $onHandled);
            // Both handlers must enter before either is released. This proves
            // overlap without elapsed-time assertions or delayed fixtures.
            $bus->started['a']->getFuture()->await();
            $bus->started['b']->getFuture()->await();
            $this->assertTrue($strategy->shouldPauseConsumption());
            $this->assertSame(2, $strategy->pendingCount());
            $this->assertSame([], $handled);

            $bus->release['a']->complete();
            $this->assertTrue($strategy->wait($onHandled));
            $this->assertFalse($strategy->shouldPauseConsumption());
            $this->assertSame(['a'], $handled);

            $strategy->execute(new Envelope((object) ['id' => 'c']), 'llm', $onHandled);
            $bus->started['c']->getFuture()->await();
            $this->assertTrue($strategy->shouldPauseConsumption());
            $bus->release['b']->complete();
            $bus->release['c']->complete();
            $strategy->flush($onHandled, true);
            sort($handled);
            $this->assertSame(['a', 'b', 'c'], $handled);
            $this->assertSame(0, $strategy->pendingCount());
        } finally {
            $strategy->shutdown();
        }
    }
}
