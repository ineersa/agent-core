<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunMetadata;
use Ineersa\AgentCore\Domain\Run\StartRunInput;

/**
 * Composes the initial message list for a fork child run.
 *
 * Takes a loaded ForkSessionSnapshotDTO (from the parent) and a fresh
 * system prompt (built externally via SystemPromptBuilder for the child
 * CWD) and composes messages suitable for StartRunInput:
 *
 *   1. Fresh system prompt with forkSystemPromptAppend appended.
 *   2. Strip parent prologue messages from the snapshot history (system,
 *      user-context messages that were only relevant in the parent's context).
 *   3. Keep the remaining historical user/assistant/tool messages.
 *   4. Append forkTaskUserMessage as the final user message.
 *
 * The system prompt is passed as a parameter rather than depending on
 * SystemPromptBuilder directly, because SystemPromptBuilder is a final
 * class with many injected dependencies (ToolRegistry, SettingsPathResolver,
 * AppConfig, template renderer, etc.) and the caller (AgentCommand fork mode)
 * already has access to it via the service container.
 *
 * Does NOT mutate the snapshot — always produces new message arrays.
 */
final readonly class ForkChildMessageComposer
{
    /**
     * Compose the StartRunInput for a fork child run.
     *
     * @param ForkSessionSnapshotDTO $snapshot          The loaded parent snapshot
     * @param string                 $childRunId        The child's agent run ID
     * @param string                 $freshSystemPrompt Fresh system prompt built for the child CWD
     *                                                  (without fork append; we add it here)
     *
     * @return StartRunInput Configured run input ready for AgentRunner::start()
     */
    public function compose(
        ForkSessionSnapshotDTO $snapshot,
        string $childRunId,
        string $freshSystemPrompt,
    ): StartRunInput {
        // 1. Append fork system prompt to the fresh system prompt.
        $combinedSystemPrompt = $freshSystemPrompt."\n\n".$snapshot->forkSystemPromptAppend;

        // 2. Strip parent prologue from snapshot history.
        $historicalMessages = $this->stripPrologue($snapshot->messages);

        // 3. Filter system messages from the snapshot history that made it
        //    through stripPrologue (defensive — the prologue strip should
        //    have removed them, but we also add the fresh system message in
        //    the system prompt, so snapshot-originated system messages would
        //    be confusing).
        $historicalMessages = $this->filterSystemMessages($historicalMessages);

        // 4. Build the seed message list:
        //    - System message with combined system prompt
        //    - Historical messages from snapshot
        //    - Fork task user message as final message
        $messages = [];
        $messages[] = new AgentMessage(
            role: 'system',
            content: [['type' => 'text', 'text' => $combinedSystemPrompt]],
        );

        foreach ($historicalMessages as $msg) {
            $messages[] = $msg;
        }

        $messages[] = new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => $snapshot->forkTaskUserMessage]],
        );

        // 5. Build child RunMetadata for fork provenance (session metadata
        //    filled by caller via StartRunInput's metadata).
        $childMetadata = new RunMetadata(
            session: [
                'kind' => 'fork_child',
                'parent_run_id' => '',
                'artifact_id' => '',
            ],
            model: $snapshot->resolvedModel,
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

    /**
     * Remove any remaining system messages from the historical list.
     *
     * After prologue stripping, any system messages that appear later in
     * the history are potentially confusing if the child has its own fresh
     * system prompt. Compact summary messages (user role with
     * compact_summary=true metadata) are preserved.
     *
     * @param list<AgentMessage> $messages
     *
     * @return list<AgentMessage>
     */
    private function filterSystemMessages(array $messages): array
    {
        return array_values(
            array_filter(
                $messages,
                static fn (AgentMessage $msg): bool => 'system' !== $msg->role,
            ),
        );
    }
}
