<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\History;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Session\History\PresentationMessageAccumulator;
use PHPUnit\Framework\TestCase;

final class PresentationMessageAccumulatorTest extends TestCase
{
    public function testRepeatedEmptyRefreshRemovesOnlyCurrentLeadingSystemAndGeneratedContext(): void
    {
        $messages = new PresentationMessageAccumulator(2, 240);
        $messages->add(new AgentMessage('system', [['type' => 'text', 'text' => 'first']]));
        $messages->add(new AgentMessage('user-context', [], metadata: ['source' => 'skills_context']));
        $messages->add(new AgentMessage('system', [['type' => 'text', 'text' => 'second']]));
        $messages->add(new AgentMessage('user', [['type' => 'text', 'text' => 'retained']]));
        $messages->refresh(new PresentationMessageAccumulator(2, 240));
        $this->assertSame(2, $messages->messageCount);
        $messages->refresh(new PresentationMessageAccumulator(2, 240));
        $this->assertSame(1, $messages->messageCount);
        $this->assertSame(1, $messages->eligibleCount);
        $this->assertSame(['- role=user — retained'], $messages->lines);
    }

    public function testHistoryMetadataAndExcerptSemanticsDoNotRetainMessageGraph(): void
    {
        $messages = new PresentationMessageAccumulator(1, 240);
        $message = new AgentMessage('assistant', [['type' => 'text', 'text' => '  Completed response  ']], toolCallId: 'call', toolName: 'read', isError: true, details: ['thinking' => 'REASONING_SECRET']);
        $reference = \WeakReference::create($message);
        $messages->add($message);
        unset($message);
        $this->assertNull($reference->get());
        $this->assertSame('Completed response', $messages->excerpt);
        $this->assertStringStartsWith('  Completed', $messages->excerptRawPrefix);
        $this->assertSame(['- role=assistant tool=read tool_call_id=call error=yes — Completed response'], $messages->lines);
        $messages->add(new AgentMessage('assistant', []));
        $this->assertSame('Completed response', $messages->excerpt, 'Tool-only assistant does not erase the last text-bearing assistant.');
        $messages->add(new AgentMessage('assistant', [['type' => 'text', 'text' => '']]));
        $this->assertSame('', $messages->excerpt, 'An explicit empty first text block triggers terminal fallback instead of an older excerpt.');
    }
}
