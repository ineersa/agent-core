<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\CodeMode;

use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\CodeMode\DTO\CodeModeCallRequestDTO;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\UuidV7;

/** @covers \Ineersa\CodingAgent\Tool\CodeMode\CodeModeCallRequestHandler */
final class CodeModeCallRequestHandlerTest extends IsolatedKernelTestCase
{
    public function testRoutesToSharedReceiverAndRefusesUnknownInvocation(): void
    {
        $bus = self::getContainer()->get('agent.execution.bus');
        $transport = self::getContainer()->get('messenger.transport.code_mode_requests');
        $this->assertInstanceOf(InMemoryTransport::class, $transport);
        $request = new CodeModeCallRequestDTO((string) new UuidV7(), '1234567890abcdef', 'read', ['path' => 'README.md']);
        $bus->dispatch($request);
        $this->assertCount(1, $transport->getSent());
        $this->assertEquals($request, $transport->getSent()[0]->getMessage());

        try {
            $bus->dispatch(new Envelope($request, [new ReceivedStamp('code_mode_requests')]));
            $this->fail('Unknown invocations must not reach the toolbox.');
        } catch (HandlerFailedException $exception) {
            $this->assertInstanceOf(UnrecoverableMessageHandlingException::class, $exception->getPrevious());
            $this->assertSame('Code-mode invocation is not ready.', $exception->getPrevious()->getMessage());
        }
    }
}
