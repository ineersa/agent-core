<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Runtime\TuiSessionState;

/**
 * Handler for the /compact slash command.
 *
 * Triggers conversation compaction through the runtime client.
 * Custom instructions (everything after /compact on the line) are
 * passed through exactly to the summarization model.
 *
 * @internal Registered by CompactCommandRegistrar
 */
final class CompactCommandHandler implements SlashCommandHandler
{
    public function __construct(
        private readonly AgentSessionClient $client,
        private readonly TuiSessionState $state,
    ) {
    }

    public function handle(SlashCommand $command): CommandResult
    {
        $runId = $this->state->handle?->runId;

        if (null === $runId || '' === $runId) {
            return new TranscriptMessage(
                'No active session to compact.',
                'system',
                'error',
            );
        }

        if ($this->state->isCompacting) {
            return new TranscriptMessage(
                'Compaction already in progress.',
                'system',
                'error',
            );
        }

        $customInstructions = '' !== $command->args ? $command->args : null;

        $this->client->compact($runId, $customInstructions);

        // Mark compacting state so repeated /compact commands are
        // rejected until the compaction completes or fails.
        $this->state->isCompacting = true;

        return new TranscriptMessage(
            'Compacting conversation...',
            'system',
        );
    }
}
