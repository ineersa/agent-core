<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunMetadata;
use Ineersa\AgentCore\Domain\Run\StartRunInput;

/**
 * Composes the message list for a fork child run.
 *
 * Takes a loaded ForkSessionSnapshotDTO (from the parent) and fresh
 * system + context messages built by normal Hatfield startup (the fork
 * controller bootstrap), then:
 *
 *   1. Appends forkSystemPromptAppend to the fresh system prompt.
 *   2. Keeps fresh user-context messages (AGENTS.md, skills, agents definitions).
 *   3. Strips parent prologue messages from the snapshot history.
 *   4. Appends remaining historical user/assistant/tool messages.
 *   5. Appends forkTaskUserMessage as the final user message.
 *   6. Sets RunMetadata with fork provenance (parent_run_id, artifact_id).
 *
 * Does NOT mutate the snapshot — always produces new message arrays.
 */
final readonly class ForkChildMessageComposer
{
    /**
     * Compose messages from fresh context + fork snapshot.
     *
     * @param ForkSessionSnapshotDTO $snapshot          The loaded parent snapshot
     * @param string                 $childRunId        The child's agent run ID
     * @param string                 $freshSystemPrompt Fresh system prompt built for the child CWD
     *                                                  (without fork append; we add it here)
     * @param list<AgentMessage>     $freshContextMsgs  Fresh user-context messages built for child CWD
     *                                                  (AGENTS.md, skills, agents definitions)
     * @param string                 $parentRunId       Parent session run ID for fork provenance
     * @param string                 $artifactId        Artifact ID within parent scope
     * @param string|null            $resolvedModel     Resolved model identifier
     *
     * @return StartRunInput Configured run input ready for AgentRunner::start()
     */
    public function compose(
        ForkSessionSnapshotDTO $snapshot,
        string $childRunId,
        string $freshSystemPrompt,
        array $freshContextMsgs = [],
        string $parentRunId = '',
        string $artifactId = '',
        ?string $resolvedModel = null,
    ): StartRunInput {
        // 1. Append fork system prompt to the fresh system prompt.
        $combinedSystemPrompt = $freshSystemPrompt."\n\n".$snapshot->forkSystemPromptAppend;

        // 2. Build the seed message list:
        //    a. Fresh system message with combined system prompt
        //    b. Fresh user-context messages (AGENTS.md, skills, agents)
        //    c. Historical messages from snapshot (prologue stripped)
        //    d. Fork task user message as final message
        $messages = [];

        // System message.
        $messages[] = new AgentMessage(
            role: 'system',
            content: [['type' => 'text', 'text' => $combinedSystemPrompt]],
        );

        // Fresh context messages (user-context role).
        foreach ($freshContextMsgs as $ctxMsg) {
            $messages[] = $ctxMsg;
        }

        // Strip parent prologue from snapshot and append remaining history.
        $historicalMessages = $this->stripPrologue($snapshot->messages);

        // Filter out any remaining system messages from snapshot history
        // (the child has its own fresh system prompt).
        foreach ($historicalMessages as $histMsg) {
            if ('system' !== $histMsg->role) {
                $messages[] = $histMsg;
            }
        }

        // Append fork task as final user message.
        $messages[] = new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => $snapshot->forkTaskUserMessage]],
        );

        // 3. Build child RunMetadata with fork provenance.
        $sessionMeta = [];
        if ('' !== $parentRunId) {
            $sessionMeta['parent_run_id'] = $parentRunId;
        }
        if ('' !== $artifactId) {
            $sessionMeta['artifact_id'] = $artifactId;
        }
        $sessionMeta['kind'] = 'fork_child';

        $childMetadata = new RunMetadata(
            session: $sessionMeta,
            model: $resolvedModel ?? $snapshot->resolvedModel,
        );

        return new StartRunInput(
            systemPrompt: $combinedSystemPrompt,
            messages: $messages,
            runId: $childRunId,
            metadata: $childMetadata,
        );
    }

    /**
     * Strip parent prologue messages from snapshot history.
     *
     * Leading system and user-context messages are parent-side artifacts
     * (injected AGENTS.md, skills context, etc.) that should not be
     * inherited by the child. The child has its own fresh system prompt
     * built from its own CWD.
     *
     * @param list<AgentMessage> $messages Snapshot messages
     *
     * @return list<AgentMessage> Messages with prologue removed
     */
    private function stripPrologue(array $messages): array
    {
        $firstNonPrologue = 0;
        foreach ($messages as $i => $msg) {
            if ('system' === $msg->role || 'user-context' === $msg->role) {
                $firstNonPrologue = $i + 1;
            } else {
                break;
            }
        }

        return \array_slice($messages, $firstNonPrologue);
    }
}
