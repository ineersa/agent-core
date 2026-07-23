<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tool;

use HelgeSverre\Toon\Toon;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskInfo;

/**
 * Shared builder for Hatfield task-workflow tool return values.
 *
 * Hatfield's generic ToolExecutor JSON-encodes non-string handler results, so
 * this extension must return a top-level TOON string (not a Pi-shaped array).
 * The model/TUI therefore receives TOON text directly; Pi keeps native structured
 * `{content, details}` and is intentionally not mirrored here.
 */
final class ToolResult
{
    /**
     * Build a top-level TOON string with the human-readable message plus structured fields.
     *
     * @param array<string, mixed> $details Structured fields preserved under their original keys
     */
    public static function text(string $text, array $details = []): string
    {
        $payload = array_merge(
            ['message' => $text],
            self::normalizeDetails($details),
        );

        // Encode once at the shared builder so every task-workflow tool returns
        // a single TOON string that generic ToolExecutor passes through unchanged.
        return Toon::encode($payload);
    }

    /**
     * @param array<string, mixed> $details
     *
     * @return array<string, mixed>
     */
    private static function normalizeDetails(array $details): array
    {
        $normalized = [];
        foreach ($details as $key => $value) {
            $normalized[$key] = self::normalizeValue($value);
        }

        return $normalized;
    }

    private static function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof TaskInfo) {
            return [
                'status' => $value->status->value,
                'file' => $value->file,
                'path' => $value->path,
                'title' => $value->title,
                'branch' => $value->branch,
                'worktree' => $value->worktree,
                'prUrl' => $value->prUrl,
            ];
        }

        if (\is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = self::normalizeValue($item);
            }

            return $normalized;
        }

        return $value;
    }
}
