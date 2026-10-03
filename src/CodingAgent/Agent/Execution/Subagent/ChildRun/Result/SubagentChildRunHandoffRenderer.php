<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Result;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Session\History\RunPresentationDTO;

use function Symfony\Component\String\u;

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
        ?RunPresentationDTO $childPresentation = null,
    ): string {
        if (AgentArtifactStatusEnum::Cancelled === $status) {
            return $this->buildCancelledHandoffMarkdown(
                artifactId: $artifactId,
                agentName: $agentName,
                agentRunId: $agentRunId,
                summary: $summary,
                childPresentation: $childPresentation,
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
                childPresentation: $childPresentation,
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
        $text = \sprintf(
            "Subagent %s completed.\nArtifact: %s\n\nHandoff:\n\n%s",
            $displayName,
            $artifactId,
            $finalMessages,
        );

        return $this->limitInlineHandoff($text, $artifactId, 'completed');
    }

    public function formatFailedResult(string $displayName, string $artifactId, string $errorMsg): string
    {
        $text = \sprintf("Subagent %s failed: %s\nArtifact: %s",
            $displayName, $errorMsg, $artifactId);

        return $this->limitInlineHandoff($text, $artifactId, 'failed');
    }

    public function formatTimeoutResult(string $displayName, int $timeoutSeconds, string $taskSummary, string $artifactId): string
    {
        $text = \sprintf("Subagent %s timed out after %d seconds. Task: %s\nArtifact: %s",
            $displayName, $timeoutSeconds, $taskSummary, $artifactId);

        return $this->limitInlineHandoff($text, $artifactId, 'timed out');
    }

    private function limitInlineHandoff(string $text, string $artifactId, string $status): string
    {
        if (u($text)->length() <= 50000) {
            return $text;
        }

        return \sprintf(
            "Subagent %s. Response exceeds 50,000 characters. Inline handoff omitted.\nArtifact: %s\nUse agent_retrieve with this artifact_id to inspect the handoff.",
            $status,
            $artifactId,
        );
    }

    private function buildCancelledHandoffMarkdown(
        ?string $artifactId,
        ?string $agentName,
        ?string $agentRunId,
        ?string $summary,
        ?RunPresentationDTO $childPresentation,
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
            childPresentation: $childPresentation,
        );
    }

    private function buildFailedHandoffMarkdown(
        ?string $artifactId,
        ?string $agentName,
        ?string $agentRunId,
        ?string $summary,
        ?string $failureReason,
        ?string $needsClarification,
        ?RunPresentationDTO $childPresentation,
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
            childPresentation: $childPresentation,
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
        ?RunPresentationDTO $childPresentation,
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

        if (null !== $childPresentation) {
            $lastActivity = $this->summarizeLastKnownActivity($childPresentation);
            $excerpt = $childPresentation->assistantExcerpt;
            $includeExcerpt = $childPresentation->includeAssistantExcerpt;
            $partial = <<<'MD'

## Partial context

- turn_no: {turn_no}
- last_seq: {last_seq}
- message_count: {message_count}
- pending_tool_calls: {pending_tool_calls}
{last_activity_line}{assistant_excerpt_block}
MD;
            $partialReplacements = [
                '{turn_no}' => (string) $childPresentation->turnNo,
                '{last_seq}' => (string) $childPresentation->lastSeq,
                '{message_count}' => (string) $childPresentation->messageCount,
                '{pending_tool_calls}' => (string) $childPresentation->pendingToolCallCount,
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

    private function summarizeLastKnownActivity(RunPresentationDTO $state): string
    {
        if ($state->pendingToolCallCount > 0) {
            $firstId = null !== $state->firstPendingToolCallId && '' !== $state->firstPendingToolCallId ? $state->firstPendingToolCallId : 'tool_call';

            return 'pending tool_call: '.$this->truncateHandoffText($firstId, 120);
        }

        if ($state->hasAssistantMessage) {
            return 'assistant message at turn '.$state->turnNo;
        }

        return 'run status '.$state->status->value;
    }

    private function truncateHandoffText(string $text, int $maxLen): string
    {
        $trimmed = trim($text);

        return u($trimmed)->truncate($maxLen, '...')->toString();
    }
}
