<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

/**
 * Bounded, privacy-safe summary derived from a child agent's events and RunState.
 *
 * Never includes raw tool results, system/user-context/tool-role message bodies,
 * or full prompts. Intended for parent inline subagent_progress payloads only.
 *
 * Model and reasoning are concrete launch identity (non-empty).
 */
final readonly class SubagentChildProgressSummary
{
    public string $model;
    public string $reasoning;

    /**
     * @param list<string> $recentTools Safe display lines (e.g. read: path="…")
     */
    public function __construct(
        string $model,
        string $reasoning,
        public int $toolCount = 0,
        public int $llmStepCount = 0,
        public int $inputTokens = 0,
        public int $latestInputTokens = 0,
        public int $contextWindow = 0,
        public int $outputTokens = 0,
        public int $reasoningTokens = 0,
        public int $totalTokens = 0,
        public ?float $cost = null,
        public ?string $provider = null,
        public ?string $artifactPath = null,
        public ?string $assistantExcerpt = null,
        public array $recentTools = [],
        public ?string $activeToolLine = null,
    ) {
        $model = trim($model);
        $reasoning = trim($reasoning);
        if ('' === $model || '' === $reasoning) {
            throw new \InvalidArgumentException('Subagent child progress summary requires non-empty model and reasoning.');
        }
        $this->model = $model;
        $this->reasoning = $reasoning;
    }

    /**
     * @return array<string, mixed>
     */
    public function toProgressFields(): array
    {
        $fields = [
            'tool_count' => $this->toolCount,
            'llm_step_count' => $this->llmStepCount,
            'input_tokens' => $this->inputTokens,
            'latest_input_tokens' => $this->latestInputTokens,
            'output_tokens' => $this->outputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
            'total_tokens' => $this->totalTokens,
            'recent_tools' => $this->recentTools,
            'model' => $this->model,
            'reasoning' => $this->reasoning,
        ];

        if (null !== $this->cost && $this->cost > 0.0) {
            $fields['cost'] = $this->cost;
        }
        if ($this->contextWindow > 0) {
            $fields['context_window'] = $this->contextWindow;
        }
        if (null !== $this->provider && '' !== $this->provider) {
            $fields['provider'] = $this->provider;
        }
        if (null !== $this->artifactPath && '' !== $this->artifactPath) {
            $fields['artifact_path'] = $this->artifactPath;
        }
        if (null !== $this->assistantExcerpt && '' !== $this->assistantExcerpt) {
            $fields['assistant_excerpt'] = $this->assistantExcerpt;
        }
        if (null !== $this->activeToolLine && '' !== $this->activeToolLine) {
            $fields['active_tool'] = $this->activeToolLine;
        }

        return $fields;
    }
}
