<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\CodeMode;

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
        $reply = ['ok' => true, 'result' => 42];
        $this->assertSame($reply, $first->settle($workspace, 'saved', $request, static fn (): array => $reply));
        $this->assertSame($reply, $replacement->settle($workspace, 'saved', $request, fn (): never => $this->fail('Executed twice.')));

        $failure = new \RuntimeException('Stopped before reply persistence.');
        try {
            $first->settle($workspace, 'unknown', $request, static fn (): never => throw $failure);
            $this->fail('Expected original execution failure.');
        } catch (\RuntimeException $actual) {
            $this->assertSame($failure, $actual);
        }

        $never = fn (): never => $this->fail('Repeated an ambiguous effect.');
        $unknown = $replacement->settle($workspace, 'unknown', $request, $never);
        $this->assertSame(['ok' => false, 'error' => 'Code-mode call outcome is unknown; refusing to run it again.'], $unknown);
        $this->assertSame($unknown, $first->settle($workspace, 'unknown', $request, $never));

        $unencodable = $first->settle($workspace, 'unencodable', $request, static fn (): array => ['ok' => true, 'result' => \INF]);
        $this->assertFalse($unencodable['ok'] ?? null);
        $this->assertSame($unencodable, $replacement->settle($workspace, 'unencodable', $request, $never));

        $pending = $first->settle($workspace, 'nested', $request, function () use ($replacement, $workspace, $request, $never): array {
            $this->assertNull($replacement->childId($workspace, 'nested'));
            $replacement->reserveChild($workspace, 'nested', 'child-id');
            $this->assertSame('child-id', $replacement->childId($workspace, 'nested'));
            $this->assertNull($replacement->settle($workspace, 'nested', $request, $never));

            return ['child' => 'child-id'];
        });
        $this->assertSame(['child' => 'child-id'], $pending);
        $this->assertSame($pending, $replacement->settle($workspace, 'nested', $request, $never));
        $this->assertTrue($replacement->completeChild($workspace, 'nested', 'child-id', ['ok' => true, 'result' => \INF]));
        $this->assertSame($unencodable, $first->settle($workspace, 'nested', $request, $never));
    }
}
