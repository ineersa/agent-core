<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tool;

final class ToolResult
{
    /**
     * @param array<string, mixed> $details
     *
     * @return array{content: list<array{type: string, text: string}>, details: array<string, mixed>}
     */
    public static function text(string $text, array $details = []): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'details' => $details,
        ];
    }
}
