<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\CodeMode;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeCallJournal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/** @covers \Ineersa\CodingAgent\Tool\CodeMode\CodeModeCallJournal */
final class CodeModeCallJournalTest extends TestCase
{
    public function testReplacementReadsSavedReplyAndNeverRepeatsUnknownEffects(): void
    {
        $workspace = TestDirectoryIsolation::createProjectTempDir('code-mode-journal');
        try {
            $first = new CodeModeCallJournal(new LockFactory(new FlockStore($workspace)));
            $replacement = new CodeModeCallJournal(new LockFactory(new FlockStore($workspace)));
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

            $this->expectException(ToolCallException::class);
            $this->expectExceptionMessage('outcome is unknown');
            $replacement->settle($workspace, 'unknown', $request, fn (): never => $this->fail('Repeated an ambiguous effect.'));
        } finally {
            TestDirectoryIsolation::removeDirectory($workspace);
        }
    }
}
