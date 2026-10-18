<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Ineersa\CodingAgent\Runtime\Messenger\SerializedBrokerSendersLocator;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\SqliteQueue\Messenger\TransportFactory;
use Ineersa\SqliteQueue\Protocol\Frame;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

use function Amp\async;
use function Amp\Future\awaitAll;
use function Amp\Socket\listen;

final class SerializedBrokerSendersLocatorTest extends TestCase
{
    public static function pendingReplies(): iterable
    {
        yield 'pending HELLO' => ['HELLO', false];
        yield 'pending SEND' => ['SEND', false];
        yield 'failed HELLO releases lock' => ['HELLO', true];
        yield 'uncertain SEND releases lock without retry' => ['SEND', true];
    }

    #[DataProvider('pendingReplies')]
    public function testConcurrentPublishersWaitForWholeExchange(string $phase, bool $fail): void
    {
        $dir = TestDirectoryIsolation::createOsTempDir('sq');
        $server = listen('unix://'.$dir.'/s');
        $transport = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($dir.'/s'), [], new PhpSerializer());
        $locator = new SerializedBrokerSendersLocator(new SendersLocator(['*' => ['queue']], new ServiceLocator(['queue' => static fn () => $transport])));
        $firstEnvelope = new Envelope((object) ['text' => 'first']);
        $secondEnvelope = new Envelope((object) ['text' => 'second']);
        $sender = iterator_to_array($locator->getSenders($firstEnvelope))['queue'];
        $this->assertSame($sender, iterator_to_array($locator->getSenders($secondEnvelope))['queue']);
        $pending = new DeferredFuture();
        $release = new DeferredFuture();
        $entered = new DeferredFuture();
        $requests = [];
        $cancel = new TimeoutCancellation(2);
        $peer = async(static function () use ($server, $pending, $release, $phase, $fail, &$requests, $cancel): void {
            $socket = $server->accept($cancel);
            try {
                $hello = Frame::read($socket, $cancel);
                if ('HELLO' === $phase) {
                    $pending->complete();
                    $release->getFuture()->await($cancel);
                    if ($fail) {
                        return;
                    }
                }
                $socket->write((new Frame(['v' => 1, 'id' => $hello->control['id'], 'ok' => true, 'result' => ['max_payload' => 1040000, 'max_connections' => 64]]))->encode());
                for ($id = 1; $id <= 2; ++$id) {
                    $request = Frame::read($socket, $cancel);
                    $requests[] = $request;
                    if ('SEND' === $phase && 1 === $id) {
                        $pending->complete();
                        $release->getFuture()->await($cancel);
                        if ($fail) {
                            return;
                        }
                    }
                    $socket->write((new Frame(['v' => 1, 'id' => $request->control['id'], 'ok' => true, 'result' => $id]))->encode());
                }
            } finally {
                $socket->close();
            }
        });
        try {
            $first = async(static fn () => $sender->send($firstEnvelope));
            $pending->getFuture()->await($cancel);
            $second = async(static function () use ($entered, $sender, $secondEnvelope): Envelope {
                $entered->complete();

                return $sender->send($secondEnvelope);
            });
            $entered->getFuture()->await($cancel);
            $this->assertFalse($first->isComplete());
            $this->assertFalse($second->isComplete(), 'The second publisher must wait, not reject concurrent acquisition or exchange.');
            $release->complete();
            [$errors, $results] = awaitAll([$first, $second], $cancel);
            $peer->await($cancel);
            if ($fail) {
                $this->assertCount(2, $errors);
                foreach ($errors as $error) {
                    $this->assertInstanceOf(TransportException::class, $error);
                }
                $this->assertCount('HELLO' === $phase ? 0 : 1, $requests, 'Uncertain sends must not be replayed.');
            } else {
                $this->assertSame([], $errors);
                $this->assertSame([1, 2], array_map(static fn (Envelope $envelope) => $envelope->last(TransportMessageIdStamp::class)->getId(), $results));
                $this->assertCount(2, $requests);
                $this->assertSame(['send', 'send'], array_column(array_column($requests, 'control'), 'op'));
                $this->assertStringContainsString('first', $requests[0]->body);
                $this->assertStringContainsString('second', $requests[1]->body);
            }
        } finally {
            $transport->close();
            $server->close();
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    public function testNonBrokerSendersRemainUntouched(): void
    {
        $transport = new InMemoryTransport();
        $locator = new SerializedBrokerSendersLocator(new SendersLocator(['*' => ['queue']], new ServiceLocator(['queue' => static fn () => $transport])));
        $this->assertSame($transport, iterator_to_array($locator->getSenders(new Envelope(new \stdClass())))['queue']);
    }
}
