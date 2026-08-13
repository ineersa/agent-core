<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Projection;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * One child row inside a parallel subagent_progress snapshot (canonical snake_case keys).
 */
final readonly class SubagentProgressChildRowDTO
{
    /**
     * @param list<string> $recentTools
     */
    public function __construct(
        public int $index,
        public string $label,
        #[SerializedName('agent_name')]
        public string $agentName,
        public string $status,
        #[SerializedName('artifact_id')]
        public string $artifactId,
        #[SerializedName('agent_run_id')]
        public string $agentRunId,
        #[SerializedName('task_summary')]
        public string $taskSummary,
        #[SerializedName('turn_no')]
        public int $turnNo,
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

    /**
     * Canonical parallel child row for RunEvent / transcript meta / JSONL.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $row = [
            'index' => $this->index,
            'label' => $this->label,
            'agent_name' => $this->agentName,
            'status' => $this->status,
            'artifact_id' => $this->artifactId,
            'agent_run_id' => $this->agentRunId,
            'task_summary' => $this->taskSummary,
            'turn_no' => $this->turnNo,
        ];

        return SubagentProgressSnapshotDTO::mergeEnrichmentFields($row, $this);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
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
            index: self::intVal($data['index'] ?? 0),
            label: self::stringVal($data['label'] ?? '', 'Step 0'),
            agentName: self::stringVal($data['agent_name'] ?? '', 'subagent'),
            status: self::stringVal($data['status'] ?? '', 'running'),
            artifactId: self::stringVal($data['artifact_id'] ?? '', ''),
            agentRunId: self::stringVal($data['agent_run_id'] ?? '', ''),
            taskSummary: self::stringVal($data['task_summary'] ?? '', ''),
            turnNo: self::intVal($data['turn_no'] ?? 0),
            toolCount: self::optionalInt($data, 'tool_count'),
            llmStepCount: self::optionalInt($data, 'llm_step_count'),
            inputTokens: self::optionalInt($data, 'input_tokens'),
            latestInputTokens: self::optionalInt($data, 'latest_input_tokens'),
            outputTokens: self::optionalInt($data, 'output_tokens'),
            reasoningTokens: self::optionalInt($data, 'reasoning_tokens'),
            totalTokens: self::optionalInt($data, 'total_tokens'),
            recentTools: $recentTools,
            cost: self::optionalFloat($data, 'cost'),
            model: self::optionalNonEmptyString($data['model'] ?? null),
            contextWindow: self::optionalPositiveInt($data, 'context_window'),
            provider: self::optionalNonEmptyString($data['provider'] ?? null),
            artifactPath: self::optionalNonEmptyString($data['artifact_path'] ?? null),
            assistantExcerpt: self::optionalNonEmptyString($data['assistant_excerpt'] ?? null),
            activeTool: self::optionalNonEmptyString($data['active_tool'] ?? null),
        );
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
    private static function optionalInt(array $data, string $key): ?int
    {
        if (!\array_key_exists($key, $data) || !is_numeric($data[$key])) {
            return null;
        }

        return (int) $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function optionalPositiveInt(array $data, string $key): ?int
    {
        $v = self::optionalInt($data, $key);

        return null !== $v && $v > 0 ? $v : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function optionalFloat(array $data, string $key): ?float
    {
        if (!\array_key_exists($key, $data) || !is_numeric($data[$key])) {
            return null;
        }
        $v = (float) $data[$key];

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
