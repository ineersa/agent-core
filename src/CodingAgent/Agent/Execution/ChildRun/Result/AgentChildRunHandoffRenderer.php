<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Result;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalOutcomeDTO;

/**
 * Builds handoff markdown and user-visible result strings for foreground child runs.
 */
final class AgentChildRunHandoffRenderer
{
    public function buildHandoffMarkdown(ChildRunTerminalOutcomeDTO $outcome): string
    {
        $identity = $outcome->identity;
        $kind = $identity->artifactKind;

        if (AgentArtifactStatusEnum::Cancelled === $outcome->status) {
            return $this->buildCancelledHandoffMarkdown(
                identity: $identity,
                summary: $outcome->summary,
                childState: $outcome->childState,
            );
        }

        $lines = [
            '# '.$this->handoffHeading($kind),
            '',
            'Status: '.$outcome->status->value,
        ];

        if (null !== $outcome->summary) {
            $lines[] = '';
            $lines[] = '## Result';
            $lines[] = '';
            $lines[] = $outcome->summary;
        }

        if (null !== $outcome->failureReason) {
            $lines[] = '';
            $lines[] = '## Failure reason';
            $lines[] = '';
            $lines[] = $outcome->failureReason;
        }

        if (null !== $outcome->needsClarification) {
            $lines[] = '';
            $lines[] = '## Needs clarification';
            $lines[] = '';
            $lines[] = $outcome->needsClarification;
        }

        return implode("\n", $lines)."\n";
    }

    public function formatParentCancelledSingleMessage(ChildRunIdentityDTO $identity): string
    {
        $template = <<<'TXT'
{headline}
Artifact: {artifact_id}
Status: cancelled
Use agent_retrieve (metadata/events/history) for partial child details.
TXT;

        return strtr($template, [
            '{headline}' => \sprintf('%s %s cancelled by parent run.', $this->kindLabel($identity->artifactKind), $identity->displayName),
            '{artifact_id}' => $identity->artifactId,
        ]);
    }

    public function formatChildCancelledMessage(ChildRunIdentityDTO $identity): string
    {
        $template = <<<'TXT'
{headline}
Artifact: {artifact_id}
Status: cancelled
Use agent_retrieve (metadata/events/history) for partial child details.
TXT;

        return strtr($template, [
            '{headline}' => \sprintf('%s %s was cancelled.', $this->kindLabel($identity->artifactKind), $identity->displayName),
            '{artifact_id}' => $identity->artifactId,
        ]);
    }

    public function formatCompletedResult(ChildRunIdentityDTO $identity, string $finalMessages): string
    {
        return \sprintf(
            "%s %s completed.\nArtifact: %s\n\nFull handoff is included below (agent_retrieve is optional for single-mode success; use it only for metadata/history/debug or if you need to re-read this artifact).\n\n%s",
            $this->kindLabel($identity->artifactKind),
            $identity->displayName,
            $identity->artifactId,
            $finalMessages,
        );
    }

    public function formatFailedResult(ChildRunIdentityDTO $identity, string $errorMsg): string
    {
        return \sprintf("%s %s failed: %s\nArtifact: %s",
            $this->kindLabel($identity->artifactKind),
            $identity->displayName,
            $errorMsg,
            $identity->artifactId);
    }

    public function formatTimeoutResult(ChildRunIdentityDTO $identity, int $timeoutSeconds): string
    {
        return \sprintf("%s %s timed out after %d seconds. Task: %s\nArtifact: %s",
            $this->kindLabel($identity->artifactKind),
            $identity->displayName,
            $timeoutSeconds,
            $identity->taskSummary,
            $identity->artifactId);
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
        ChildRunIdentityDTO $identity,
        ?string $summary,
        ?RunState $childState,
    ): string {
        $kind = $identity->artifactKind;
        $artifactId = $identity->artifactId;
        $agentName = $identity->displayName;
        $agentRunId = $identity->childRunId;

        $template = <<<'MD'
# {handoff_heading}

Status: cancelled
{artifact_line}{agent_line}{agent_run_line}
## Cancellation

{summary_text}
{partial_context_block}{retrieval_hint}
MD;

        $summaryText = null !== $summary ? trim($summary) : '';
        $replacements = [
            '{artifact_line}' => ('' !== $artifactId) ? 'Artifact: {artifact_id}'.'
' : '',
            '{agent_line}' => ('' !== $agentName) ? 'Agent: {agent_name}'.'
' : '',
            '{agent_run_line}' => ('' !== $agentRunId) ? 'Agent run: {agent_run_id}'.'
' : '',
            '{summary_text}' => '' !== $summaryText ? $summaryText : 'Child run was cancelled.',
            '{partial_context_block}' => '',
            '{retrieval_hint}' => '',
        ];

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
                '{last_activity_line}' => '' !== $lastActivity ? '- last_known_activity: {last_activity}'.'
' : '',
                '{assistant_excerpt_block}' => $includeExcerpt ? '
## Last assistant excerpt'.'

{assistant_excerpt}'.'
' : '',
            ];
            $partial = strtr($partial, $partialReplacements);
            if ('' !== $lastActivity) {
                $partial = strtr($partial, ['{last_activity}' => $lastActivity]);
            }
            if ($includeExcerpt) {
                $partial = strtr($partial, ['{assistant_excerpt}' => $this->truncateHandoffText($excerpt, 800)]);
            }
            $replacements['{partial_context_block}'] = $partial;
            $replacements['{retrieval_hint}'] = '
Use agent_retrieve (metadata/events/history) for more child details.'.'
';
        }

        $markdown = strtr($template, $replacements);
        $valueMap = [
            '{handoff_heading}' => $this->handoffHeading($kind),
            '{artifact_id}' => $artifactId,
            '{agent_name}' => $agentName,
            '{agent_run_id}' => $agentRunId,
        ];

        return strtr($markdown, $valueMap);
    }

    private function kindLabel(AgentArtifactKindEnum $kind): string
    {
        return match ($kind) {
            AgentArtifactKindEnum::Fork => 'Fork',
            AgentArtifactKindEnum::Subagent => 'Subagent',
        };
    }

    private function handoffHeading(AgentArtifactKindEnum $kind): string
    {
        return match ($kind) {
            AgentArtifactKindEnum::Fork => 'Fork handoff',
            AgentArtifactKindEnum::Subagent => 'Subagent handoff',
        };
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
