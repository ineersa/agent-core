<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred;

use Ineersa\AgentCore\Domain\Run\RunStatus;

/**
 * Compact durable child lifecycle projection for deferred child runs (Piece 3B1).
 */
final readonly class DeferredChildRunLifecycleProjectionDTO
{
    /**
     * @param list<string>                                            $recentTools
     * @param array<string, array{name: string, displayLine: string}> $pendingToolCalls
     */
    public function __construct(
        public RunStatus $childStatus,
        public int $childTurnNo,
        public int $lastCommittedSeq,
        public ?string $errorMessage = null,
        public ?string $assistantResultText = null,
        public ?string $assistantExcerpt = null,
        public int $toolCount = 0,
        public int $inputTokens = 0,
        public int $latestInputTokens = 0,
        public int $contextWindow = 0,
        public int $outputTokens = 0,
        public int $reasoningTokens = 0,
        public int $totalTokens = 0,
        public ?float $cost = null,
        public ?string $model = null,
        public ?string $provider = null,
        public array $recentTools = [],
        public ?string $activeToolLine = null,
        public array $pendingToolCalls = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'child_status' => $this->childStatus->value,
            'child_turn_no' => $this->childTurnNo,
            'last_committed_seq' => $this->lastCommittedSeq,
            'tool_count' => $this->toolCount,
            'input_tokens' => $this->inputTokens,
            'latest_input_tokens' => $this->latestInputTokens,
            'output_tokens' => $this->outputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
            'total_tokens' => $this->totalTokens,
            'recent_tools' => $this->recentTools,
            'pending_tool_calls' => $this->pendingToolCalls,
        ];
        if (null !== $this->errorMessage && '' !== $this->errorMessage) {
            $data['error_message'] = $this->errorMessage;
        }
        if (null !== $this->assistantResultText && '' !== $this->assistantResultText) {
            $data['assistant_result_text'] = $this->assistantResultText;
        }
        if (null !== $this->assistantExcerpt && '' !== $this->assistantExcerpt) {
            $data['assistant_excerpt'] = $this->assistantExcerpt;
        }
        if ($this->contextWindow > 0) {
            $data['context_window'] = $this->contextWindow;
        }
        if (null !== $this->cost && $this->cost > 0.0) {
            $data['cost'] = $this->cost;
        }
        if (null !== $this->model && '' !== $this->model) {
            $data['model'] = $this->model;
        }
        if (null !== $this->provider && '' !== $this->provider) {
            $data['provider'] = $this->provider;
        }
        if (null !== $this->activeToolLine && '' !== $this->activeToolLine) {
            $data['active_tool'] = $this->activeToolLine;
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $statusRaw = $data['child_status'] ?? 'running';
        $status = RunStatus::tryFrom(\is_string($statusRaw) ? $statusRaw : 'running') ?? RunStatus::Running;
        $recent = $data['recent_tools'] ?? [];
        if (!\is_array($recent)) {
            $recent = [];
        }

        return new self(
            childStatus: $status,
            childTurnNo: isset($data['child_turn_no']) && is_numeric($data['child_turn_no']) ? (int) $data['child_turn_no'] : 0,
            lastCommittedSeq: isset($data['last_committed_seq']) && is_numeric($data['last_committed_seq']) ? (int) $data['last_committed_seq'] : 0,
            errorMessage: \is_string($data['error_message'] ?? null) ? $data['error_message'] : null,
            assistantResultText: \is_string($data['assistant_result_text'] ?? null) ? $data['assistant_result_text'] : null,
            assistantExcerpt: \is_string($data['assistant_excerpt'] ?? null) ? $data['assistant_excerpt'] : null,
            toolCount: isset($data['tool_count']) && is_numeric($data['tool_count']) ? (int) $data['tool_count'] : 0,
            inputTokens: isset($data['input_tokens']) && is_numeric($data['input_tokens']) ? (int) $data['input_tokens'] : 0,
            latestInputTokens: isset($data['latest_input_tokens']) && is_numeric($data['latest_input_tokens']) ? (int) $data['latest_input_tokens'] : 0,
            contextWindow: isset($data['context_window']) && is_numeric($data['context_window']) ? (int) $data['context_window'] : 0,
            outputTokens: isset($data['output_tokens']) && is_numeric($data['output_tokens']) ? (int) $data['output_tokens'] : 0,
            reasoningTokens: isset($data['reasoning_tokens']) && is_numeric($data['reasoning_tokens']) ? (int) $data['reasoning_tokens'] : 0,
            totalTokens: isset($data['total_tokens']) && is_numeric($data['total_tokens']) ? (int) $data['total_tokens'] : 0,
            cost: isset($data['cost']) && is_numeric($data['cost']) ? (float) $data['cost'] : null,
            model: \is_string($data['model'] ?? null) ? $data['model'] : null,
            provider: \is_string($data['provider'] ?? null) ? $data['provider'] : null,
            recentTools: array_values(array_filter($recent, static fn ($line): bool => \is_string($line))),
            activeToolLine: \is_string($data['active_tool'] ?? null) ? $data['active_tool'] : null,
            pendingToolCalls: self::decodePendingToolCalls($data['pending_tool_calls'] ?? []),
        );
    }

    /**
     * @return array<string, array{name: string, displayLine: string}>
     */
    private static function decodePendingToolCalls(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $id => $entry) {
            if (!\is_string($id) || !\is_array($entry)) {
                continue;
            }
            $name = \is_string($entry['name'] ?? null) ? $entry['name'] : 'tool';
            $displayLine = \is_string($entry['displayLine'] ?? null)
                ? $entry['displayLine']
                : (\is_string($entry['display_line'] ?? null) ? $entry['display_line'] : $name);
            $out[$id] = ['name' => $name, 'displayLine' => $displayLine];
        }

        return $out;
    }
}
