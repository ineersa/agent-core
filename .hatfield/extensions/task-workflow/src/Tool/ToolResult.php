<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tool;

use HelgeSverre\Toon\Toon;

final class ToolResult
{
    /**
     * Build a tool handler result with human-readable text content and TOON-encoded details.
     *
     * @param array<string, mixed> $details Structured payload encoded with {@see Toon::encode()}
     *
     * @return array{content: list<array{type: string, text: string}>, details: string}
     */
    public static function text(string $text, array $details = []): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            // Encode once at the shared builder so every task-workflow tool returns
            // decodable TOON details while content[].text stays plain human text.
            'details' => Toon::encode($details),
        ];
    }
}
