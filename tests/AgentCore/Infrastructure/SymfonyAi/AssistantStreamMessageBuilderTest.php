<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Infrastructure\SymfonyAi\AssistantStreamMessageBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\MessageItem;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Result\Stream\Delta\MessageComplete;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Result\Stream\Delta\MessageStart;
use Symfony\AI\Platform\Message\Content\Thinking;
use Symfony\AI\Platform\Result\Stream\Delta\CommentaryComplete;
use Symfony\AI\Platform\Result\Stream\Delta\CommentaryDelta;
use Symfony\AI\Platform\Result\Stream\Delta\CommentaryStart;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\ToolCall;

final class AssistantStreamMessageBuilderTest extends TestCase
{
    public function testNativeCommentaryKeepsPartialTextAndReplaysExactlyOnce(): void
    {
        $builder = new AssistantStreamMessageBuilder();
        $header = ['type' => 'message', 'id' => 'comment', 'role' => 'assistant', 'phase' => 'commentary'];
        $builder->accumulate(new MessageStart($header, 0));
        $builder->accumulate(new CommentaryStart());
        $builder->accumulate(new CommentaryDelta('Checking'));
        $this->assertSame('Checking', $builder->getAssistantMessage()?->asText());
        $builder->accumulate(new CommentaryComplete('Checking'));
        $native = MessageItem::toText($header + ['content' => [['type' => 'output_text', 'text' => 'Checking']]]);
        $builder->accumulate(new MessageComplete($native, 0));
        $this->assertSame([$native], $builder->getAssistantMessage()?->getContent());
        $this->assertSame($header + ['content' => [['type' => 'output_text', 'text' => 'Checking']]], MessageItem::fromText($native));
        $builder->accumulate(new TextDelta('Final'));
        $this->assertSame('CheckingFinal', $builder->getAssistantMessage()?->asText());
    }

    public function testIncrementalSnapshotsDoNotRetainDeltasOrMutateEarlierMessages(): void
    {
        $builder = new AssistantStreamMessageBuilder();
        $this->assertNull($builder->getAssistantMessage());
        $delta = new TextDelta('part');
        $reference = \WeakReference::create($delta);
        $builder->accumulate($delta);
        unset($delta);
        $this->assertNull($reference->get());
        $partial = $builder->getAssistantMessage();
        $builder->accumulate(new TextDelta('ial'));
        $this->assertSame('part', $partial?->asText());
        $this->assertSame('partial', $builder->getAssistantMessage()?->asText());
        $this->assertSame('partial', $builder->getAssistantMessage()?->asText());
    }

    public function testDelayedToolCompletionKeepsItsOwnerAcrossNativeMessageBoundary(): void
    {
        $builder = new AssistantStreamMessageBuilder();
        $builder->accumulate(new ThinkingComplete('plan', 'reasoning-signature'));
        $builder->accumulate(new ToolCallStart('call', 'read'));
        $header = ['type' => 'message', 'id' => 'msg', 'role' => 'assistant', 'phase' => 'final_answer'];
        $builder->accumulate(new MessageStart($header, 2));
        $builder->accumulate(new TextDelta('partial'));
        $this->assertSame('partial', $builder->getAssistantMessage()?->asText());
        $native = MessageItem::toText($header + ['content' => [['type' => 'output_text', 'text' => 'final']]]);
        $builder->accumulate(new MessageComplete($native, 2));
        $call = new ToolCall('call', 'read', ['path' => 'generated.txt']);
        $builder->accumulate(new ToolCallComplete([$call]));
        $content = $builder->getAssistantMessage()?->getContent();
        $this->assertCount(3, $content ?? []);
        $this->assertInstanceOf(Thinking::class, $content[0]);
        $this->assertSame('reasoning-signature', $content[0]->getSignature());
        $this->assertSame($call, $content[1]);
        $this->assertSame($native, $content[2]);
        $this->assertSame($header + ['content' => [['type' => 'output_text', 'text' => 'final']]], MessageItem::fromText($content[2]));
    }

    public function testTerminalOnlyMessagesUseOutputIndexesAcrossSignedThinkingGroups(): void
    {
        $builder = new AssistantStreamMessageBuilder();
        $builder->accumulate(new ThinkingComplete('first', 'sig-one'));
        $builder->accumulate(new ThinkingComplete('second', 'sig-two'));
        $last = MessageItem::toText(['type' => 'message', 'id' => 'last', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'last']]]);
        $first = MessageItem::toText(['type' => 'message', 'id' => 'first', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'first']]]);
        $builder->accumulate(new MessageComplete($last, 3));
        $builder->accumulate(new MessageComplete($first, 0));
        $content = $builder->getAssistantMessage()?->getContent();
        $this->assertCount(4, $content ?? []);
        $this->assertSame($first, $content[0]);
        $this->assertInstanceOf(Thinking::class, $content[1]);
        $this->assertSame('sig-one', $content[1]->getSignature());
        $this->assertInstanceOf(Thinking::class, $content[2]);
        $this->assertSame('sig-two', $content[2]->getSignature());
        $this->assertSame($last, $content[3]);
    }
}
