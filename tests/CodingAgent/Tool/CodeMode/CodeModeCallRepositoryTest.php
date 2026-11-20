<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\CodeMode;

use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Ineersa\CodingAgent\Entity\CodeModeCallRepository;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

/** @covers \Ineersa\CodingAgent\Entity\CodeModeCallRepository */
final class CodeModeCallRepositoryTest extends IsolatedKernelTestCase
{
    public function testReplacementReadsSavedReplyAndNeverRepeatsUnknownEffects(): void
    {
        $workspace = 'invocation';
        $first = self::getContainer()->get(CodeModeCallRepository::class);
        $replacement = $first;
        $request = ['name' => 'read', 'arguments' => ['path' => 'README.md']];
        $reply = new ToolPayloadReferenceDTO('result', 'run', 1, 'step', 1, 'key', 'call', 0, null, str_repeat('a', 64), 42);
        $error = new ToolPayloadReferenceDTO('result', 'run', 1, 'step', 1, 'key', 'call', 0, null, str_repeat('b', 64), 80);
        $fallback = static fn (): ToolPayloadReferenceDTO => $error;
        $this->assertEquals($reply, $first->settle($workspace, 'saved', $request, static fn (): ToolPayloadReferenceDTO => $reply, $fallback));
        $this->assertEquals($reply, $replacement->settle($workspace, 'saved', $request, fn (): never => $this->fail('Executed twice.'), $fallback));

        $failure = new \RuntimeException('Stopped before reply persistence.');
        try {
            $first->settle($workspace, 'unknown', $request, static fn (): never => throw $failure, $fallback);
            $this->fail('Expected original execution failure.');
        } catch (\RuntimeException $actual) {
            $this->assertSame($failure, $actual);
        }

        $never = fn (): never => $this->fail('Repeated an ambiguous effect.');
        $unknown = $replacement->settle($workspace, 'unknown', $request, $never, $fallback);
        $this->assertEquals($error, $unknown);
        $this->assertEquals($unknown, $first->settle($workspace, 'unknown', $request, $never, $fallback));

        $pending = $first->settle($workspace, 'nested', $request, function () use ($replacement, $workspace, $request, $never, $fallback): string {
            $this->assertNull($replacement->childId($workspace, 'nested'));
            $replacement->reserveChild($workspace, 'nested', 'child-id');
            $this->assertSame('child-id', $replacement->childId($workspace, 'nested'));
            $this->assertNull($replacement->settle($workspace, 'nested', $request, $never, $fallback));

            return 'child-id';
        }, $fallback);
        $this->assertSame('child-id', $pending);
        $this->assertSame($pending, $replacement->settle($workspace, 'nested', $request, $never, $fallback));
        $this->assertTrue($replacement->completeChild($workspace, 'nested', 'child-id', $error));
        $this->assertEquals($error, $first->settle($workspace, 'nested', $request, $never, $fallback));
    }
}
