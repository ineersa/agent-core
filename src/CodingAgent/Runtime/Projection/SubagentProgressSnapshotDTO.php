<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Projection;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Typed parent subagent_progress snapshot (single or parallel).
 *
 * Canonical snake_case keys are produced by {@see toArray()} for RunEvent /
 * RuntimeEvent / transcript metadata boundaries. Internal code should pass this
 * object, not associative arrays.
 */
final readonly class SubagentProgressSnapshotDTO
{
    /**
     * @param list<SubagentProgressChildRowDTO> $children
     * @param list<string>|null                 $recentTools
     */
    public function __construct(
        public string $mode,
        public string $status,
        #[SerializedName('elapsed_ms')]
        public int $elapsedMs = 0,
        #[SerializedName('agent_name')]
        public ?string $agentName = null,
        #[SerializedName('artifact_id')]
        public ?string $artifactId = null,
        #[SerializedName('agent_run_id')]
        public ?string $agentRunId = null,
        #[SerializedName('task_summary')]
        public ?string $taskSummary = null,
        #[SerializedName('turn_no')]
        public ?int $turnNo = null,
        #[SerializedName('completed_count')]
        public ?int $completedCount = null,
        #[SerializedName('total_count')]
        public ?int $totalCount = null,
        public array $children = [],
        #[SerializedName('tool_count')]
        public ?int $toolCount = null,
        #[SerializedName('llm_step_count')]
        public ?int $llmStepCount = null,
        #[SerializedName('input_tokens')]
        public ?int $inputTokens = null,
        #[SerializedName('latest_input_tokens')]
        public ?int $latestInputTokens = null,
        #[SerializedName('output_tokens')]
        public ?int $outputTokens = null,
        #[SerializedName('reasoning_tokens')]
        public ?int $reasoningTokens = null,
        #[SerializedName('total_tokens')]
        public ?int $totalTokens = null,
        #[SerializedName('recent_tools')]
        public ?array $recentTools = null,
        public ?float $cost = null,
        public ?string $model = null,
        #[SerializedName('context_window')]
        public ?int $contextWindow = null,
        public ?string $provider = null,
        #[SerializedName('artifact_path')]
        public ?string $artifactPath = null,
        #[SerializedName('assistant_excerpt')]
        public ?string $assistantExcerpt = null,
        #[SerializedName('active_tool')]
        public ?string $activeTool = null,
    ) {
    }

    public function isParallel(): bool
    {
        return 'parallel' === $this->mode;
    }

    /**
     * Canonical payload for event/transcript/JSONL boundaries.
     *
     * Optional enrichment keys are omitted when unset (same as historical
     * toProgressFields semantics).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->isParallel()) {
            $payload = [
                'mode' => 'parallel',
                'status' => $this->status,
                'completed_count' => $this->completedCount ?? 0,
                'total_count' => $this->totalCount ?? 0,
                'elapsed_ms' => max(0, $this->elapsedMs),
                'children' => array_map(
                    static fn (SubagentProgressChildRowDTO $child): array => $child->toArray(),
                    $this->children,
                ),
                'tool_count' => $this->toolCount ?? 0,
                'input_tokens' => $this->inputTokens ?? 0,
                'output_tokens' => $this->outputTokens ?? 0,
                'reasoning_tokens' => $this->reasoningTokens ?? 0,
                'total_tokens' => $this->totalTokens ?? 0,
            ];
            if (null !== $this->cost && $this->cost > 0.0) {
                $payload['cost'] = $this->cost;
            }

            return $payload;
        }

        $payload = [
            'mode' => 'single',
            'status' => $this->status,
            'agent_name' => $this->agentName ?? 'subagent',
            'artifact_id' => $this->artifactId ?? '',
            'agent_run_id' => $this->agentRunId ?? '',
            'task_summary' => $this->taskSummary ?? '',
            'turn_no' => $this->turnNo ?? 0,
            'elapsed_ms' => max(0, $this->elapsedMs),
        ];

        return self::mergeEnrichmentFields($payload, $this);
    }

    /**
     * Trust-boundary denormalization from event/transcript meta arrays.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $mode = \is_string($data['mode'] ?? null) ? $data['mode'] : 'single';
        if ('parallel' === $mode) {
            $childrenRaw = $data['children'] ?? [];
            $children = [];
            if (\is_array($childrenRaw)) {
                foreach ($childrenRaw as $child) {
                    if (\is_array($child)) {
                        $children[] = SubagentProgressChildRowDTO::fromArray($child);
                    }
                }
            }

            return new self(
                mode: 'parallel',
                status: self::stringVal($data['status'] ?? null, 'running'),
                elapsedMs: max(0, self::intVal($data['elapsed_ms'] ?? 0)),
                completedCount: self::intVal($data['completed_count'] ?? 0),
                totalCount: self::intVal($data['total_count'] ?? 0),
                children: $children,
                toolCount: self::intVal($data['tool_count'] ?? 0),
                inputTokens: self::intVal($data['input_tokens'] ?? 0),
                outputTokens: self::intVal($data['output_tokens'] ?? 0),
                reasoningTokens: self::intVal($data['reasoning_tokens'] ?? 0),
                totalTokens: self::intVal($data['total_tokens'] ?? 0),
                cost: self::optionalPositiveFloat($data['cost'] ?? null),
            );
        }

        $recent = $data['recent_tools'] ?? null;
        $recentTools = null;
        if (\is_array($recent)) {
            $lines = [];
            foreach ($recent as $line) {
                if (\is_string($line) && '' !== $line) {
                    $lines[] = $line;
                }
            }
            $recentTools = $lines;
        }

        return new self(
            mode: 'single',
            status: self::stringVal($data['status'] ?? null, 'running'),
            elapsedMs: max(0, self::intVal($data['elapsed_ms'] ?? 0)),
            agentName: self::stringVal($data['agent_name'] ?? null, 'subagent'),
            artifactId: self::stringVal($data['artifact_id'] ?? null, ''),
            agentRunId: self::stringVal($data['agent_run_id'] ?? null, ''),
            taskSummary: self::stringVal($data['task_summary'] ?? null, ''),
            turnNo: self::intVal($data['turn_no'] ?? 0),
            toolCount: self::optionalIntKey($data, 'tool_count'),
            llmStepCount: self::optionalIntKey($data, 'llm_step_count'),
            inputTokens: self::optionalIntKey($data, 'input_tokens'),
            latestInputTokens: self::optionalIntKey($data, 'latest_input_tokens'),
            outputTokens: self::optionalIntKey($data, 'output_tokens'),
            reasoningTokens: self::optionalIntKey($data, 'reasoning_tokens'),
            totalTokens: self::optionalIntKey($data, 'total_tokens'),
            recentTools: $recentTools,
            cost: self::optionalPositiveFloat($data['cost'] ?? null),
            model: self::optionalNonEmptyString($data['model'] ?? null),
            contextWindow: self::optionalPositiveIntKey($data, 'context_window'),
            provider: self::optionalNonEmptyString($data['provider'] ?? null),
            artifactPath: self::optionalNonEmptyString($data['artifact_path'] ?? null),
            assistantExcerpt: self::optionalNonEmptyString($data['assistant_excerpt'] ?? null),
            activeTool: self::optionalNonEmptyString($data['active_tool'] ?? null),
        );
    }

    /**
     * Merge enrichment fields with historical omission rules.
     *
     * @param array<string, mixed>                                    $base
     * @param SubagentProgressSnapshotDTO|SubagentProgressChildRowDTO $source
     *
     * @return array<string, mixed>
     */
    public static function mergeEnrichmentFields(array $base, object $source): array
    {
        $hasMetrics = null !== $source->toolCount
            || null !== $source->llmStepCount
            || null !== $source->inputTokens
            || null !== $source->latestInputTokens
            || null !== $source->outputTokens
            || null !== $source->reasoningTokens
            || null !== $source->totalTokens
            || null !== $source->recentTools;

        if ($hasMetrics) {
            $base['tool_count'] = $source->toolCount ?? 0;
            $base['llm_step_count'] = $source->llmStepCount ?? 0;
            $base['input_tokens'] = $source->inputTokens ?? 0;
            $base['latest_input_tokens'] = $source->latestInputTokens ?? 0;
            $base['output_tokens'] = $source->outputTokens ?? 0;
            $base['reasoning_tokens'] = $source->reasoningTokens ?? 0;
            $base['total_tokens'] = $source->totalTokens ?? 0;
            $base['recent_tools'] = $source->recentTools ?? [];
        }

        if (null !== $source->cost && $source->cost > 0.0) {
            $base['cost'] = $source->cost;
        }
        if (null !== $source->model && '' !== $source->model) {
            $base['model'] = $source->model;
        }
        if (null !== $source->contextWindow && $source->contextWindow > 0) {
            $base['context_window'] = $source->contextWindow;
        }
        if (null !== $source->provider && '' !== $source->provider) {
            $base['provider'] = $source->provider;
        }
        if (null !== $source->artifactPath && '' !== $source->artifactPath) {
            $base['artifact_path'] = $source->artifactPath;
        }
        if (null !== $source->assistantExcerpt && '' !== $source->assistantExcerpt) {
            $base['assistant_excerpt'] = $source->assistantExcerpt;
        }
        if (null !== $source->activeTool && '' !== $source->activeTool) {
            $base['active_tool'] = $source->activeTool;
        }

        return $base;
    }

    private static function stringVal(mixed $value, string $default): string
    {
        return \is_string($value) && '' !== $value ? $value : $default;
    }

    private static function intVal(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function optionalIntKey(array $data, string $key): ?int
    {
        if (!\array_key_exists($key, $data) || !is_numeric($data[$key])) {
            return null;
        }

        return (int) $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function optionalPositiveIntKey(array $data, string $key): ?int
    {
        $v = self::optionalIntKey($data, $key);

        return null !== $v && $v > 0 ? $v : null;
    }

    private static function optionalPositiveFloat(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        $v = (float) $value;

        return $v > 0.0 ? $v : null;
    }

    private static function optionalNonEmptyString(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return '' !== $trimmed ? $trimmed : null;
    }
}
