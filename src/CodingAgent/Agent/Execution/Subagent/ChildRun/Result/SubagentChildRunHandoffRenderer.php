<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Result;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;

/**
 * Builds handoff markdown and user-visible result strings for foreground child runs.
 */
final class SubagentChildRunHandoffRenderer
{
    public function buildHandoffMarkdown(
        AgentArtifactStatusEnum $status,
        ?string $summary,
        ?string $failureReason,
        ?string $needsClarification,
        ?string $artifactId = null,
        ?string $agentName = null,
        ?string $agentRunId = null,
        ?RunState $childState = null,
    ): string {
        if (AgentArtifactStatusEnum::Cancelled === $status) {
            return $this->buildCancelledHandoffMarkdown(
                artifactId: $artifactId,
                agentName: $agentName,
                agentRunId: $agentRunId,
                summary: $summary,
                childState: $childState,
            );
        }

        if (AgentArtifactStatusEnum::Failed === $status) {
            return $this->buildFailedHandoffMarkdown(
                artifactId: $artifactId,
                agentName: $agentName,
                agentRunId: $agentRunId,
                summary: $summary,
                failureReason: $failureReason,
                needsClarification: $needsClarification,
                childState: $childState,
            );
        }

        $lines = [
            '# Subagent handoff',
            '',
            'Status: '.$status->value,
        ];

        if (null !== $summary) {
            $lines[] = '';
            $lines[] = '## Result';
            $lines[] = '';
            $lines[] = $summary;
        }

        if (null !== $failureReason) {
            $lines[] = '';
            $lines[] = '## Failure reason';
            $lines[] = '';
            $lines[] = $failureReason;
        }

        if (null !== $needsClarification) {
            $lines[] = '';
            $lines[] = '## Needs clarification';
            $lines[] = '';
            $lines[] = $needsClarification;
        }

        return implode("\n", $lines)."\n";
    }

    public function formatParentCancelledSingleMessage(string $displayName, string $artifactId): string
    {
        $template = <<<'TXT'
{headline}
Artifact: {artifact_id}
Status: cancelled
Use agent_retrieve (metadata/events/history) for partial child details.
TXT;

        return strtr($template, [
            '{headline}' => \sprintf('Subagent %s cancelled by parent run.', $displayName),
            '{artifact_id}' => $artifactId,
        ]);
    }

    public function formatChildCancelledMessage(string $displayName, string $artifactId): string
    {
        $template = <<<'TXT'
{headline}
Artifact: {artifact_id}
Status: cancelled
Use agent_retrieve (metadata/events/history) for partial child details.
TXT;

        return strtr($template, [
            '{headline}' => \sprintf('Subagent %s was cancelled.', $displayName),
            '{artifact_id}' => $artifactId,
        ]);
    }

    public function formatCompletedResult(string $displayName, string $artifactId, string $finalMessages): string
    {
        return \sprintf(
            "Subagent %s completed.\nArtifact: %s\n\nHandoff:\n\n%s",
            $displayName,
            $artifactId,
            $finalMessages,
        );
    }

    public function formatFailedResult(string $displayName, string $artifactId, string $errorMsg): string
    {
        return \sprintf("Subagent %s failed: %s\nArtifact: %s",
            $displayName, $errorMsg, $artifactId);
    }

    public function formatTimeoutResult(string $displayName, int $timeoutSeconds, string $taskSummary, string $artifactId): string
    {
        return \sprintf("Subagent %s timed out after %d seconds. Task: %s\nArtifact: %s",
            $displayName, $timeoutSeconds, $taskSummary, $artifactId);
    }

    public function extractLastMessage(RunState $state): string
    {
        $lastText = '';
        foreach (array_reverse($state->messages) as $message) {
            if ('assistant' !== $message->role) {
                continue;
            }
            foreach ($message->content as $block) {
                if ('text' === ($block['type'] ?? '') && isset($block['text'])) {
                    $lastText = (string) $block['text'];
                    break 2;
                }
            }
        }

        if ('' === $lastText) {
            $lastText = \sprintf('%s with status %s.', $state->status->name, $state->status->value);
        }

        return $lastText;
    }

    private function buildCancelledHandoffMarkdown(
        ?string $artifactId,
        ?string $agentName,
        ?string $agentRunId,
        ?string $summary,
        ?RunState $childState,
    ): string {
        $template = <<<'MD'
# Subagent handoff

Status: cancelled
{artifact_line}{agent_line}{agent_run_line}
## Cancellation

{summary_text}
{partial_context_block}{retrieval_hint}
MD;

        $summaryText = null !== $summary ? trim($summary) : '';

        return $this->renderTerminalHandoffMarkdown(
            template: $template,
            artifactId: $artifactId,
            agentName: $agentName,
            agentRunId: $agentRunId,
            bodyText: '' !== $summaryText ? $summaryText : 'Child run was cancelled.',
            childState: $childState,
        );
    }

    private function buildFailedHandoffMarkdown(
        ?string $artifactId,
        ?string $agentName,
        ?string $agentRunId,
        ?string $summary,
        ?string $failureReason,
        ?string $needsClarification,
        ?RunState $childState,
    ): string {
        // Session 37: durable child state existed after Codex WebSocket send failure,
        // but failed handoff only kept the generic transport error. Reuse cancelled
        // partial-context rendering so retained work is visible without reloading state.
        $template = <<<'MD'
# Subagent handoff

Status: failed
{artifact_line}{agent_line}{agent_run_line}
## Result

{result_text}

## Failure reason

{failure_text}
{needs_clarification_block}{partial_context_block}{retrieval_hint}
MD;

        $resultText = null !== $summary ? trim($summary) : '';
        $failureText = null !== $failureReason ? trim($failureReason) : '';
        if ('' === $failureText) {
            $failureText = '' !== $resultText ? $resultText : 'Run failed without error message.';
        }
        if ('' === $resultText) {
            $resultText = $failureText;
        }

        $needsBlock = '';
        if (null !== $needsClarification && '' !== trim($needsClarification)) {
            $needsBlock = "\n## Needs clarification\n\n".trim($needsClarification)."\n";
        }

        return $this->renderTerminalHandoffMarkdown(
            template: $template,
            artifactId: $artifactId,
            agentName: $agentName,
            agentRunId: $agentRunId,
            bodyText: $resultText,
            childState: $childState,
            extraReplacements: [
                '{result_text}' => $resultText,
                '{failure_text}' => $failureText,
                '{needs_clarification_block}' => $needsBlock,
            ],
        );
    }

    /**
     * @param array<string, string> $extraReplacements
     */
    private function renderTerminalHandoffMarkdown(
        string $template,
        ?string $artifactId,
        ?string $agentName,
        ?string $agentRunId,
        string $bodyText,
        ?RunState $childState,
        array $extraReplacements = [],
    ): string {
        $replacements = [
            '{artifact_line}' => (null !== $artifactId && '' !== $artifactId) ? 'Artifact: {artifact_id}'."\n" : '',
            '{agent_line}' => (null !== $agentName && '' !== $agentName) ? 'Agent: {agent_name}'."\n" : '',
            '{agent_run_line}' => (null !== $agentRunId && '' !== $agentRunId) ? 'Agent run: {agent_run_id}'."\n" : '',
            '{summary_text}' => $bodyText,
            '{partial_context_block}' => '',
            '{retrieval_hint}' => '',
        ] + $extraReplacements;

        if (null !== $childState) {
            $lastActivity = $this->summarizeLastKnownActivity($childState);
            $excerpt = $this->extractLastMessage($childState);
            $includeExcerpt = '' !== trim($excerpt) && !str_starts_with($excerpt, $childState->status->name);
            $partial = <<<'MD'

## Partial context

- turn_no: {turn_no}
- last_seq: {last_seq}
- message_count: {message_count}
- pending_tool_calls: {pending_tool_calls}
{last_activity_line}{assistant_excerpt_block}
MD;
            $partialReplacements = [
                '{turn_no}' => (string) $childState->turnNo,
                '{last_seq}' => (string) $childState->lastSeq,
                '{message_count}' => (string) \count($childState->messages),
                '{pending_tool_calls}' => (string) \count($childState->pendingToolCalls),
                '{last_activity_line}' => '' !== $lastActivity ? '- last_known_activity: {last_activity}'."\n" : '',
                '{assistant_excerpt_block}' => $includeExcerpt ? "\n## Last assistant excerpt\n\n{assistant_excerpt}\n" : '',
            ];
            $partial = strtr($partial, $partialReplacements);
            if ('' !== $lastActivity) {
                $partial = strtr($partial, ['{last_activity}' => $lastActivity]);
            }
            if ($includeExcerpt) {
                $partial = strtr($partial, ['{assistant_excerpt}' => $this->truncateHandoffText($excerpt, 800)]);
            }
            $replacements['{partial_context_block}'] = $partial;
            $replacements['{retrieval_hint}'] = "\nUse agent_retrieve (metadata/events/history) for more child details.\n";
        }

        $markdown = strtr($template, $replacements);
        $valueMap = [
            '{artifact_id}' => $artifactId ?? '',
            '{agent_name}' => $agentName ?? '',
            '{agent_run_id}' => $agentRunId ?? '',
        ];

        return strtr($markdown, $valueMap);
    }

    private function summarizeLastKnownActivity(RunState $state): string
    {
        if ([] !== $state->pendingToolCalls) {
            $pendingIds = array_keys($state->pendingToolCalls);
            $firstId = '' !== ($pendingIds[0] ?? '') ? (string) $pendingIds[0] : 'tool_call';

            return 'pending tool_call: '.$this->truncateHandoffText($firstId, 120);
        }

        foreach (array_reverse($state->messages) as $message) {
            if ('assistant' === $message->role) {
                return 'assistant message at turn '.$state->turnNo;
            }
        }

        return 'run status '.$state->status->value;
    }

    private function truncateHandoffText(string $text, int $maxLen): string
    {
        $trimmed = trim($text);
        if (\strlen($trimmed) <= $maxLen) {
            return $trimmed;
        }

        return substr($trimmed, 0, $maxLen - 3).'...';
    }
}
