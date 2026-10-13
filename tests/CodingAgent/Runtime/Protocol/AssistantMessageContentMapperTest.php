<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Protocol;

use Ineersa\CodingAgent\Runtime\Protocol\AssistantMessageContentMapper;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\MessageItem;

final class AssistantMessageContentMapperTest extends TestCase
{
    public function testUnphasedProvidersKeepTheirAggregatedProjection(): void
    {
        $this->assertNull(AssistantMessageContentMapper::map(['content' => [['type' => 'text', 'text' => 'one'], ['type' => 'text', 'text' => 'two', 'text_signature' => 'opaque']]], 'run', 'step'));
    }

    public function testMissingMessageIdUsesNativeIndexNotSummaryFragmentIndex(): void
    {
        $item = ['type' => 'message', 'role' => 'assistant', 'phase' => 'commentary', 'content' => [['type' => 'output_text', 'text' => 'Checking']]];
        $signed = MessageItem::toText($item);
        $parts = AssistantMessageContentMapper::map(['content' => [
            ['type' => 'thinking', 'text' => 'First summary'],
            ['type' => 'thinking', 'text' => 'Second summary', 'thinking_signature' => '{"type":"reasoning"}'],
            ['type' => 'text', 'text' => 'Checking', 'text_signature' => $signed->getSignature()],
        ]], 'run', 'step');
        $this->assertNotNull($parts);
        $this->assertSame(1, $parts[0]['output_index']);
        $this->assertSame(AssistantMessageContentMapper::blockId('run', 'step', $item, 1), $parts[0]['block_id']);
        $this->assertSame('commentary', $parts[0]['phase']);
        $this->assertArrayNotHasKey('text_signature', $parts[0]);
    }
}
