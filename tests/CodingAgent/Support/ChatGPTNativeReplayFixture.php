<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

final class ChatGPTNativeReplayFixture
{
    /** @return list<array<string, mixed>> */
    public static function items(): array
    {
        $reasoning = static fn (string $id): array => ['type' => 'reasoning', 'id' => $id, 'encrypted_content' => 'encrypted-'.$id, 'summary' => []];
        $call = static fn (string $id): array => ['arguments' => '{}', 'call_id' => $id, 'name' => 'read_file', 'type' => 'function_call', 'id' => 'fc_'.$id];
        $message = static fn (string $id, string $phase, string $text): array => ['type' => 'message', 'id' => $id, 'role' => 'assistant', 'phase' => $phase, 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []], ['type' => 'output_text', 'text' => '', 'annotations' => []]]];

        return [$reasoning('rs_one'), $call('call_one'), $reasoning('rs_two'), $call('call_two'), $message('msg_commentary', 'commentary', 'Checking.'), $message('msg_final', 'final_answer', 'Done.')];
    }

    public static function response(bool $native = true): MockResponse
    {
        $body = '';
        $items = $native ? self::items() : [];
        foreach ($items as $index => $item) {
            foreach (['response.output_item.added', 'response.output_item.done'] as $type) {
                $body .= 'data: '.json_encode(['type' => $type, 'output_index' => $index, 'item' => $item], \JSON_THROW_ON_ERROR)."\n\n";
            }
        }
        if (!$native) {
            $body .= "data: {\"type\":\"response.output_text.delta\",\"delta\":\"done\"}\n\n";
        }
        $body .= 'data: '.json_encode(['type' => 'response.completed', 'response' => ['status' => 'completed', 'output' => $items]], \JSON_THROW_ON_ERROR)."\n\n";

        return new MockResponse($body, ['response_headers' => ['content-type: text/event-stream']]);
    }

    /** @param array<string, mixed> $body
     * @return list<array<string, mixed>>
     */
    public static function replayedItems(array $body): array
    {
        return array_values(array_filter($body['input'], static fn (array $item): bool => \in_array($item['type'] ?? null, ['reasoning', 'function_call', 'message'], true) && 'user' !== ($item['role'] ?? null)));
    }
}
