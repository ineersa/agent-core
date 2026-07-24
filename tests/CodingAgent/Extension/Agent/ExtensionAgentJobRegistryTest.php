<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Agent;

use Ineersa\CodingAgent\Extension\Agent\ExtensionAgentJobDispatcher;
use Ineersa\CodingAgent\Extension\Agent\ExtensionAgentJobMessage;
use Ineersa\CodingAgent\Extension\Agent\ExtensionAgentJobRegistry;
use Ineersa\CodingAgent\Extension\Agent\ExtensionAgentJobWorker;
use Ineersa\CodingAgent\Tests\Extension\InMemoryExtensionApiBridge;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobRequestDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Thesis: generic extension-agent registry rejects duplicates, worker fails unknown handlers permanently,
 * and sync:// transport is fail-closed before bus dispatch.
 */
final class ExtensionAgentJobRegistryTest extends TestCase
{
    public function testRegisterRejectsDuplicateHandlerIds(): void
    {
        $registry = new ExtensionAgentJobRegistry();
        $handler = $this->noopHandler();
        $registry->register('ext.job', $handler);

        $this->expectException(\InvalidArgumentException::class);
        $registry->register('ext.job', $handler);
    }

    public function testDispatcherSendsSerializableMessageOnAsyncTransport(): void
    {
        $captured = null;
        $bus = new class($captured) implements MessageBusInterface {
            public function __construct(private mixed &$captured)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->captured = $message;

                return new Envelope($message);
            }
        };

        $dispatcher = new ExtensionAgentJobDispatcher($bus, new NullLogger(), 'in-memory://extension_agent');
        $dispatcher->dispatch(new ExtensionAgentJobRequestDTO(
            handlerId: 'ext.job',
            payload: ['run_id' => 'r1', 'n' => 1],
            jobId: 'job-1',
            correlationId: 'corr-1',
        ));

        $this->assertInstanceOf(ExtensionAgentJobMessage::class, $captured);
        $this->assertSame('ext.job', $captured->handlerId);
        $this->assertSame(['run_id' => 'r1', 'n' => 1], $captured->payload);
    }

    public function testDispatcherRefusesSyncTransportWithoutInvokingBus(): void
    {
        $bus = new class implements MessageBusInterface {
            public bool $invoked = false;

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->invoked = true;

                return new Envelope($message);
            }
        };

        $dispatcher = new ExtensionAgentJobDispatcher($bus, new NullLogger(), 'sync://');

        try {
            $dispatcher->dispatch(new ExtensionAgentJobRequestDTO(
                handlerId: 'ext.job',
                payload: ['run_id' => 'r1'],
                jobId: 'job-1',
            ));
            $this->fail('Expected sync:// transport to be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('sync://', $e->getMessage());
        }

        $this->assertFalse($bus->invoked);
    }

    public function testDispatcherAllowsDoctrineTransport(): void
    {
        $captured = null;
        $bus = new class($captured) implements MessageBusInterface {
            public function __construct(private mixed &$captured)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->captured = $message;

                return new Envelope($message);
            }
        };

        $dispatcher = new ExtensionAgentJobDispatcher(
            $bus,
            new NullLogger(),
            'doctrine://messenger_transport?queue_name=extension_agent',
        );
        $dispatcher->dispatch(new ExtensionAgentJobRequestDTO(
            handlerId: 'ext.job',
            payload: ['ok' => true],
        ));

        $this->assertInstanceOf(ExtensionAgentJobMessage::class, $captured);
    }

    public function testWorkerInvokesRegisteredHandlerAndFailsUnknown(): void
    {
        $registry = new ExtensionAgentJobRegistry();
        $seen = null;
        $registry->register('ext.job', new class($seen) implements ExtensionAgentJobHandlerInterface {
            public function __construct(private mixed &$seen)
            {
            }

            public function handle(ExtensionApiInterface $api, array $payload, ?string $jobId, ?string $correlationId): void
            {
                $this->seen = [$payload, $jobId, $correlationId];
            }
        });

        $api = new InMemoryExtensionApiBridge('/tmp');
        $worker = new ExtensionAgentJobWorker($registry, $api, new NullLogger());
        $worker(new ExtensionAgentJobMessage('ext.job', ['a' => 1], 'j1', 'c1'));
        $this->assertSame([['a' => 1], 'j1', 'c1'], $seen);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $worker(new ExtensionAgentJobMessage('missing.handler', []));
    }

    private function noopHandler(): ExtensionAgentJobHandlerInterface
    {
        return new class implements ExtensionAgentJobHandlerInterface {
            public function handle(ExtensionApiInterface $api, array $payload, ?string $jobId, ?string $correlationId): void
            {
            }
        };
    }
}
