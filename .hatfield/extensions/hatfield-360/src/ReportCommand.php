<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Hatfield360;

use Amp\ByteStream\StreamException;
use Amp\CancelledException;
use Amp\TimeoutCancellation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;

use function Amp\ByteStream\buffer;
use function Amp\ByteStream\getStdin;

#[AsCommand(name: 'report', description: 'Report one Hatfield 360 event from JSON on stdin')]
final class ReportCommand
{
    public function __invoke(#[Option(description: 'Read a Claude Code hook payload and fail open')] bool $claudeHook = false, #[Option(description: 'Send title/body JSON to the current Claude selection')] bool $claudeNotify = false): int
    {
        if ($claudeHook && $claudeNotify) {
            return Command::INVALID;
        }
        $client = StatusClient::fromEnvironment();
        if (null === $client) {
            return $claudeHook ? Command::SUCCESS : Command::FAILURE;
        }

        try {
            // Hook stdin may contain a large prompt; the wire frame is capped separately.
            $json = buffer(getStdin(), new TimeoutCancellation(0.25), $claudeHook ? 1_048_576 : StatusClient::MAX_FRAME_BYTES);
            $decoded = json_decode($json, depth: 16, flags: \JSON_THROW_ON_ERROR);
        } catch (StreamException|CancelledException|\JsonException) {
            if ($claudeHook) {
                HookDiagnostic::write('hook_input_failed');
            }

            return $claudeHook ? Command::SUCCESS : Command::INVALID;
        }
        if ($claudeHook) {
            if ($decoded instanceof \stdClass) {
                (new ClaudeHookReporter())->report($client, get_object_vars($decoded));
            }

            return Command::SUCCESS;
        }
        if (!$decoded instanceof \stdClass) {
            return Command::INVALID;
        }
        $fields = get_object_vars($decoded);
        if ($claudeNotify) {
            if (!EventSchema::acceptsNotification($fields)) {
                return Command::INVALID;
            }

            return (new ClaudeHookReporter())->notify($client, $fields) ? Command::SUCCESS : Command::FAILURE;
        }
        $kind = $fields['kind'] ?? null;
        $session = $fields['sessionId'] ?? null;
        $sequence = $fields['sequence'] ?? null;
        $epoch = $fields['sessionEpoch'] ?? null;
        unset($fields['kind'], $fields['sessionId'], $fields['sequence'], $fields['sessionEpoch']);
        if (!\is_string($kind) || !\is_string($session) || !\is_int($sequence) || !\is_int($epoch)
            || !EventSchema::accepts($kind, $session, $sequence, $epoch, $fields)) {
            return Command::INVALID;
        }

        return $client->send($kind, $session, $sequence, $epoch, $fields) ? Command::SUCCESS : Command::FAILURE;
    }
}
