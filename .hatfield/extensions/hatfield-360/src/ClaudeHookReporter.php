<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Hatfield360;

use Ineersa\HatfieldExt\Hatfield360\DTO\SessionCheckpointDTO;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockExceptionInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Uid\Uuid;

final class ClaudeHookReporter
{
    private const array CAPABILITIES = ['session-end', 'waits'];

    /** @param array<array-key, mixed> $hook */
    public function report(StatusClient $client, array $hook): bool
    {
        $session = $hook['session_id'] ?? null;
        $event = $hook['hook_event_name'] ?? null;
        if (!\is_string($session) || !EventSchema::id($session) || isset($hook['agent_id'])
            || !\in_array($event, ['SessionStart', 'SessionEnd', 'UserPromptSubmit', 'Stop', 'StopFailure', 'PreToolUse', 'Elicitation', 'ElicitationResult'], true)) {
            return false;
        }

        $endpoint = getenv('H360_STATUS_ENDPOINT');
        $launch = getenv('H360_STATUS_LAUNCH_ID');
        if (!\is_string($endpoint) || !\is_string($launch) || !Uuid::isValid($launch)) {
            return false;
        }
        $directory = \dirname($endpoint);
        $stat = @lstat($directory);
        // State must live in the app's private socket directory, not a shared or symlinked directory.
        if (false === $stat || 0o040700 !== ($stat['mode'] & 0o177777) || posix_geteuid() !== $stat['uid']) {
            return false;
        }

        try {
            $lock = (new LockFactory(new FlockStore($directory)))->createLock('claude-status-'.$launch);
            if (!$lock->acquire()) {
                return false;
            }
            try {
                return $this->reportLocked($client, $directory.'/claude-status-'.$launch.'.json', $session, $event, $hook);
            } finally {
                $lock->release();
            }
        } catch (IOException|LockExceptionInterface|\JsonException) {
            HookDiagnostic::write('hook_state_failed');

            return false;
        }
    }

    /** @param array<array-key, mixed> $hook */
    private function reportLocked(StatusClient $client, string $path, string $session, string $event, array $hook): bool
    {
        $previous = file_exists($path) ? $this->readState($path) : [];
        if (null === $previous) {
            return false;
        }
        $sequence = ($previous['sequence'] ?? 0) + 1;
        if (!EventSchema::sequence($sequence)) {
            return false;
        }
        $epoch = $previous['epoch'] ?? 0;
        $prompt = null;
        $turn = null;
        $fields = [];
        $waits = $previous['waits'] ?? null;
        if ('SessionStart' === $event) {
            $source = $hook['source'] ?? null;
            // Compaction doesn't select a session. A fork may be a background copy.
            // Also reject a delayed startup after another selection was observed.
            if (!\in_array($source, ['startup', 'resume', 'clear'], true) || ('startup' === $source && [] !== $previous)) {
                return false;
            }
            $state = [
                'sequence' => $sequence, 'epoch' => $sequence, 'session' => $session,
                'ended' => false, 'prompt' => null, 'turn' => null, 'waits' => [],
                'checkpoint' => ['eventId' => (string) Uuid::v7(), 'capabilities' => self::CAPABILITIES],
            ];
            $this->writeState($path, $state);
            if (!$this->ensure($client, $session, $sequence, $state['checkpoint'])) {
                return false;
            }
            $state['checkpoint'] = null;
            $this->writeState($path, $state);

            return true;
        }

        if (($previous['session'] ?? null) !== $session || ($previous['ended'] ?? true)) {
            return false;
        }
        if ('SessionEnd' === $event) {
            // Selection hooks establish the replacement epoch. An old switch-end
            // hook cannot disambiguate two epochs using the same provider ID.
            if (!\in_array($hook['reason'] ?? null, ['logout', 'prompt_input_exit', 'other'], true)) {
                return false;
            }
            $waits = null === $waits ? null : [];
            $kind = 'session.ended';
        } else {
            $promptId = $hook['prompt_id'] ?? null;
            if (!\is_string($promptId) || !Uuid::isValid($promptId)) {
                return false;
            }
            $fields = ['actorId' => 'main', 'actorStart' => $epoch, 'turnId' => $promptId];
            if ('UserPromptSubmit' === $event) {
                if ($promptId === ($previous['prompt'] ?? null)) {
                    return false;
                }
                $prompt = $promptId;
                $turn = $sequence;
                $waits = null === $waits ? null : [];
                $kind = 'turn.started';
            } else {
                if ($promptId !== ($previous['prompt'] ?? null) || !isset($previous['turn'])) {
                    return false;
                }
                $prompt = $promptId;
                $turn = $previous['turn'];
                $fields['turnStart'] = $turn;
                if ('PreToolUse' === $event) {
                    if (!EventSchema::id($hook['tool_name'] ?? null) || !EventSchema::id($hook['tool_use_id'] ?? null)) {
                        return false;
                    }
                    // MCP and plugin tool names are caller-defined; do not forward them.
                    $kind = 'activity.changed';
                    $fields['activity'] = 'working';
                } elseif ('Stop' === $event || 'StopFailure' === $event) {
                    if ('Stop' === $event ? false !== ($hook['stop_hook_active'] ?? null) : !\is_string($hook['error'] ?? null)) {
                        return false;
                    }
                    $fields['outcome'] = 'Stop' === $event ? 'unknown' : 'failed';
                    $kind = 'turn.ended';
                    $turn = null;
                    $waits = null === $waits ? null : [];
                } else {
                    $server = $hook['mcp_server_name'] ?? null;
                    $id = $hook['elicitation_id'] ?? null;
                    if (null === $waits || !EventSchema::id($server) || !EventSchema::id($id)) {
                        return false;
                    }
                    $key = hash('sha256', $server."\0".$id);
                    $fields['waitId'] = $key;
                    if ('Elicitation' === $event) {
                        if (isset($waits[$key]) || \count($waits) >= 16) {
                            return false;
                        }
                        $waits[$key] = $sequence;
                        $fields['reason'] = 'external-input';
                        $kind = 'wait.started';
                    } else {
                        $start = $waits[$key] ?? 0;
                        if ($start <= 0 || !\in_array($hook['action'] ?? null, ['accept', 'decline', 'cancel'], true)) {
                            return false;
                        }
                        // Keep a bounded tombstone through this prompt so a replayed
                        // request cannot pair with a delayed result for the same ID.
                        $waits[$key] = -$start;
                        $fields['waitStart'] = $start;
                        $kind = 'wait.resolved';
                    }
                }
            }
        }

        $checkpoint = $previous['checkpoint'] ?? null;
        if (null !== $checkpoint && !$this->ensure($client, $session, $epoch, $checkpoint)) {
            return false;
        }
        // Reserve before sending: a lost reply must not cause sequence reuse. The
        // lock also holds through the checkpoint acknowledgement and dependent write.
        $this->writeState($path, [
            'sequence' => $sequence, 'epoch' => $epoch, 'session' => $session,
            'ended' => 'session.ended' === $kind, 'prompt' => $prompt, 'turn' => $turn, 'waits' => $waits, 'checkpoint' => null,
        ]);

        return $client->send($kind, $session, $sequence, $epoch, $fields);
    }

    /** @param array{eventId: string, capabilities: list<string>} $checkpoint */
    private function ensure(StatusClient $client, string $session, int $epoch, array $checkpoint): bool
    {
        return $client->ensureSession(new SessionCheckpointDTO($session, $epoch, $checkpoint['eventId'], 'claude', $checkpoint['capabilities']));
    }

    /** @param array{sequence: int, epoch: int, session: string, ended: bool, prompt: ?string, turn: ?int, waits: ?array<string, int>, checkpoint: ?array{eventId: string, capabilities: list<string>}} $state */
    private function writeState(string $path, array $state): void
    {
        (new Filesystem())->dumpFile($path, json_encode($state, \JSON_THROW_ON_ERROR));
    }

    /** @return array{sequence: int, epoch: int, session: string, ended: bool, prompt: ?string, turn: ?int, waits: ?array<string, int>, checkpoint: ?array{eventId: string, capabilities: list<string>}}|null */
    private function readState(string $path): ?array
    {
        if (is_link($path) || !is_file($path)) {
            return null;
        }
        $json = @file_get_contents($path, length: 4096);
        $state = false === $json ? null : json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($state) || !\is_int($state['sequence'] ?? null) || !EventSchema::sequence($state['sequence'])
            || !\is_int($state['epoch'] ?? null) || !EventSchema::sequence($state['epoch']) || $state['epoch'] > $state['sequence']
            || !\is_string($state['session'] ?? null) || !EventSchema::id($state['session']) || !\is_bool($state['ended'] ?? null)) {
            return null;
        }
        $prompt = $state['prompt'] ?? null;
        $turn = $state['turn'] ?? null;
        if ((null !== $prompt && (!\is_string($prompt) || !Uuid::isValid($prompt)))
            || (null !== $turn && (null === $prompt || !\is_int($turn) || $turn <= $state['epoch'] || $turn > $state['sequence']))) {
            return null;
        }
        $waits = $state['waits'] ?? null;
        if (null !== $waits) {
            if (!\is_array($waits) || \count($waits) > 16) {
                return null;
            }
            foreach ($waits as $key => $start) {
                if (!\is_string($key) || 1 !== preg_match('/\A[a-f0-9]{64}\z/', $key) || null === $turn
                    || !\is_int($start) || abs($start) <= $turn || abs($start) > $state['sequence']) {
                    return null;
                }
            }
        }

        $checkpoint = $state['checkpoint'] ?? null;
        if (null !== $checkpoint) {
            $eventId = \is_array($checkpoint) ? ($checkpoint['eventId'] ?? null) : null;
            $capabilities = \is_array($checkpoint) ? ($checkpoint['capabilities'] ?? null) : null;
            if (!\is_string($eventId) || !Uuid::isValid($eventId) || !\is_array($capabilities) || !array_is_list($capabilities)
                || !array_all($capabilities, static fn (mixed $value): bool => \is_string($value))
                || !EventSchema::accepts('session.started', $state['session'], $state['epoch'], $state['epoch'], ['provider' => 'claude', 'capabilities' => $capabilities])) {
                return null;
            }
            $checkpoint = ['eventId' => $eventId, 'capabilities' => $capabilities];
        }

        return ['sequence' => $state['sequence'], 'epoch' => $state['epoch'], 'session' => $state['session'],
            'ended' => $state['ended'], 'prompt' => $prompt, 'turn' => $turn, 'waits' => $waits, 'checkpoint' => $checkpoint];
    }
}
