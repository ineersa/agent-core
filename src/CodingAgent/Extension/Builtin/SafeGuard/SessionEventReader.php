<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\SafeGuard;

/**
 * Reads approval answers from the session's shared events.jsonl file.
 *
 * When SafeGuard requests approval via the HITL interrupt flow, the tool
 * worker process blocks until the LLM retries. During that pause, the
 * human's answer is persisted to events.jsonl by the controller process.
 * This reader scans the event file to find the answer.
 *
 * This avoids the cross-process callback problem: instead of routing
 * answers through the DI container (which is a different process in
 * async mode), the tool worker reads the shared event store directly.
 *
 * @internal SafeGuard internal, not part of the public ExtensionApi
 */
final readonly class SessionEventReader
{
    public function __construct(
        private string $cwd,
    ) {
    }

    /**
     * Find a human_response answer from the session events file.
     *
     * Scans backwards from the end of the file for an agent_command_applied
     * event with kind=human_response matching the given question_id.
     *
     * @return string|null the answer text, or null if not found
     */
    public function findAnswer(string $sessionId, string $questionId): ?string
    {
        $path = $this->cwd.'/.hatfield/sessions/'.$sessionId.'/events.jsonl';

        if (!file_exists($path)) {
            return null;
        }

        $handle = @fopen($path, 'r');

        if (false === $handle) {
            return null;
        }

        try {
            return $this->scanBackwards($handle, $questionId);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Scan the file backwards from the end, looking for a matching answer.
     *
     * Reads chunks from the end of the file, decodes JSON lines, and checks
     * for agent_command_applied events with kind=human_response matching
     * the question_id.
     *
     * @param resource $handle
     */
    private function scanBackwards($handle, string $questionId): ?string
    {
        fseek($handle, 0, \SEEK_END);
        $position = ftell($handle);

        if (0 === $position) {
            return null;
        }

        // Read in 8KB chunks from the end
        $chunkSize = 8192;
        $buffer = '';

        while ($position > 0) {
            $readSize = min($chunkSize, $position);
            $position -= $readSize;
            fseek($handle, $position);
            $chunk = fread($handle, $readSize);

            if (false === $chunk) {
                return null;
            }

            $buffer = $chunk.$buffer;

            // Split on newlines and process complete lines
            $lines = explode("\n", $buffer);

            // Keep the first (potentially partial) line in the buffer
            $buffer = array_shift($lines);

            // Process lines in reverse order (newest first)
            foreach (array_reverse($lines) as $line) {
                $line = trim($line);

                if ('' === $line) {
                    continue;
                }

                $event = json_decode($line, true);

                if (!\is_array($event)) {
                    continue;
                }

                if ('agent_command_applied' !== ($event['type'] ?? '')) {
                    continue;
                }

                $payload = $event['payload'] ?? [];

                if (!\is_array($payload)) {
                    continue;
                }

                if ('human_response' !== ($payload['kind'] ?? '')) {
                    continue;
                }

                $eventQuestionId = (string) ($payload['question_id'] ?? '');

                if ($eventQuestionId !== $questionId) {
                    continue;
                }

                $answer = $payload['answer'] ?? null;

                return \is_string($answer) && '' !== $answer ? $answer : null;
            }
        }

        return null;
    }
}
