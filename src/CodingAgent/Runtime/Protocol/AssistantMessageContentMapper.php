<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Protocol;

use Symfony\AI\Platform\Bridge\OpenAIChatGPT\MessageItem;
use Symfony\AI\Platform\Message\Content\Text;

/** Maps native message boundaries to presentation data without exposing replay signatures. */
final class AssistantMessageContentMapper
{
    /** @param array<string, mixed> $item */
    public static function blockId(string $runId, ?string $stepId, array $item, ?int $index): string
    {
        $identity = $item['id'] ?? $index;

        return $runId.'_'.($stepId ?? '').'_text_'.$identity;
    }

    /**
     * Return ordered text/tool parts only for native ChatGPT messages. Other providers
     * keep the existing per-step text projection. Summary fragments share one native
     * output index until their reasoning signature closes the item.
     *
     * @param array<string, mixed> $message
     *
     * @return list<array<string, mixed>>|null
     */
    public static function map(array $message, string $runId, string $stepId): ?array
    {
        $parts = [];
        $native = false;
        $index = 0;
        foreach ($message['content'] ?? [] as $part) {
            if ('thinking' === ($part['type'] ?? null)) {
                if (isset($part['thinking_signature'])) {
                    ++$index;
                }
                continue;
            }
            if ('tool_call' === ($part['type'] ?? null)) {
                $call = $message['tool_calls'][$part['tool_call_index']] ?? null;
                if (\is_array($call)) {
                    $parts[] = ['type' => 'tool_call'] + $call;
                }
                ++$index;
                continue;
            }
            if ('text' !== ($part['type'] ?? null)) {
                continue;
            }
            $text = (string) ($part['text'] ?? '');
            $item = MessageItem::fromText(new Text($text, $part['text_signature'] ?? null));
            if (null !== $item) {
                $native = true;
                $parts[] = [
                    'type' => 'text',
                    'text' => $text,
                    'block_id' => self::blockId($runId, $stepId, $item, $index),
                    'item_id' => $item['id'] ?? null,
                    'output_index' => $index,
                    'phase' => $item['phase'] ?? null,
                ];
            } else {
                $parts[] = ['type' => 'text', 'text' => $text, 'block_id' => $runId.'_'.$stepId.'_text'];
            }
            ++$index;
        }

        return $native ? $parts : null;
    }
}
