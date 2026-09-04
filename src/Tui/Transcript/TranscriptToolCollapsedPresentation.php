<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

/**
 * Collapsed-view presentation policy for generic tool cards.
 *
 * Models how a tool exchange looks before Ctrl+O expands
 * {@see TranscriptDisplayState::$previewableBlocksExpanded}:
 *
 * - identifying arguments only (never a full YAML dump of nested payloads)
 * - result body: hide (read), tail preview (bash), or short head preview (other)
 *
 * Error/cancel/timeout full-render and expanded view stay outside this policy.
 */
final readonly class TranscriptToolCollapsedPresentation
{
    public const int RESULT_PREVIEW_LINES = 3;

    public const int ARGUMENT_PREVIEW_LINES = 4;

    /** @var list<string> */
    private const array READ_IDENTIFYING_KEYS = ['path', 'offset', 'limit'];

    public function isBashTool(mixed $toolName): bool
    {
        return \is_string($toolName) && 'bash' === $toolName;
    }

    public function isReadTool(mixed $toolName): bool
    {
        return \is_string($toolName) && 'read' === $toolName;
    }

    /**
     * Successful read results dump file contents; hide them until expanded.
     */
    public function shouldHideCollapsedResult(mixed $toolName): bool
    {
        return $this->isReadTool($toolName);
    }

    /**
     * Bash output is usually a long log; prefer the last lines when a preview remains.
     */
    public function shouldTailCollapsedResult(mixed $toolName): bool
    {
        return $this->isBashTool($toolName);
    }

    /**
     * Reduce call arguments to the identifying subset shown while collapsed.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public function collapsedArguments(mixed $toolName, array $arguments): array
    {
        if ($this->isReadTool($toolName)) {
            $out = [];
            foreach (self::READ_IDENTIFYING_KEYS as $key) {
                if (\array_key_exists($key, $arguments)) {
                    $out[$key] = $arguments[$key];
                }
            }

            return $out;
        }

        if ($this->isBashTool($toolName)) {
            $command = $arguments['command'] ?? null;
            if (\is_string($command) && '' !== $command) {
                return ['command' => $command];
            }

            return [];
        }

        return $this->compactScalarArguments($arguments);
    }

    /**
     * Drop nested structures and oversized values that dominate collapsed cards.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function compactScalarArguments(array $arguments): array
    {
        $out = [];
        foreach ($arguments as $key => $value) {
            if (!\is_string($key) || '' === $key) {
                continue;
            }
            if (\is_array($value) || \is_object($value)) {
                continue;
            }
            if (\is_string($value)) {
                if (substr_count($value, "\n") > 2) {
                    continue;
                }
                if (\strlen($value) > 200) {
                    $out[$key] = substr($value, 0, 197).'...';
                    continue;
                }
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
