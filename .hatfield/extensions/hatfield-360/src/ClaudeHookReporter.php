<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Hatfield360;

use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockExceptionInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Uid\Uuid;

final class ClaudeHookReporter
{
    private const int MAX_SWITCHES = 128;

    /** @param array<array-key, mixed> $hook */
    public function report(?StatusClient $client, array $hook): bool
    {
        $session = $hook['session_id'] ?? null;
        $event = $hook['hook_event_name'] ?? null;
        if (!\is_string($session) || !EventSchema::id($session)
            || !\in_array($event, ['SessionStart', 'SessionEnd', 'UserPromptSubmit', 'Stop', 'StopFailure', 'Elicitation', 'ElicitationResult', 'PermissionRequest', 'PreToolUse', 'PostToolUse', 'PostToolUseFailure', 'SubagentStart', 'SubagentStop'], true)) {
            return false;
        }

        return $this->withLaunchLock($client, fn (string $path, \Closure $authorize): bool => $this->reportLocked($authorize, $path, $session, $event, $hook));
    }

    /** @param array<array-key, mixed> $fields */
    public function notify(?StatusClient $client, array $fields): bool
    {
        if (!EventSchema::acceptsNotification($fields)) {
            return false;
        }

        return $this->withLaunchLock($client, function (string $path, \Closure $authorize) use ($fields): bool {
            $state = $this->readState($path);
            if (null === $state || $state['ended'] || !EventSchema::sequence($state['sequence'] + 1)) {
                return false;
            }
            ++$state['sequence'];
            (new Filesystem())->dumpFile($path, json_encode($state, \JSON_THROW_ON_ERROR));

            return $authorize()?->send('notification', $state['session'], $state['sequence'], $state['epoch'], $fields) ?? false;
        });
    }

    /** @param \Closure(string, \Closure(): ?StatusClient): bool $callback */
    private function withLaunchLock(?StatusClient $client, \Closure $callback): bool
    {
        $endpoint = getenv('H360_STATUS_ENDPOINT');
        if (!\is_string($endpoint) || !str_starts_with($endpoint, '/') || \strlen($endpoint) > 103) {
            return false;
        }
        $directory = \dirname($endpoint);
        $stat = @lstat($directory);
        // State must live in the app's private socket directory, not a shared or symlinked directory.
        if (false === $stat || 0o040700 !== ($stat['mode'] & 0o177777) || posix_geteuid() !== $stat['uid']) {
            return false;
        }

        $managed = null === $client;
        if ($managed) {
            $bootstrap = getenv('H360_STATUS_BOOTSTRAP_TOKEN');
            if (false !== getenv('H360_STATUS_TOKEN') || false !== getenv('H360_STATUS_LAUNCH_ID')
                || !\is_string($bootstrap) || 1 !== preg_match('/\A[0-9a-f]{64}\z/', $bootstrap)
                || null === ($owner = ClaudeProcessIdentityProvider::find())) {
                return false;
            }
            // A stable per-owner instance survives a lost registration reply,
            // while a different pane bootstrap cannot share the local state.
            $instance = Uuid::v5(Uuid::fromString('f7ddf8d2-76b9-4e45-9ab0-c13ce270fb4f'), hash_hmac('sha256', implode(':', $owner), $bootstrap))->toRfc4122();
            $name = 'claude-managed-'.$instance;
            $authorize = static fn (): ?StatusClient => ClaudeProcessIdentityProvider::find() === $owner
                ? StatusClient::registerManual('claude', $instance, $owner['pid'], $owner['pidNamespace'], $owner['startTicks']) : null;
            // One nonblocking lock bounds the total managed state admitted to
            // this app-private directory without a persistent bearer cache.
            $lockName = 'claude-managed';
        } else {
            $launch = getenv('H360_STATUS_LAUNCH_ID');
            if (!\is_string($launch) || !Uuid::isValid($launch)) {
                return false;
            }
            $name = $lockName = 'claude-status-'.$launch;
            $authorize = static fn (): StatusClient => $client;
        }
        $path = $directory.'/'.$name.'.json';

        try {
            $lock = (new LockFactory(new FlockStore($directory)))->createLock($lockName);
            if (!$lock->acquire()) {
                return false;
            }
            try {
                if ($managed && !file_exists($path)) {
                    $files = glob($directory.'/claude-managed-*.json', \GLOB_NOSORT);
                    if (false === $files || \count($files) >= 64) {
                        return false;
                    }
                }

                return $callback($path, $authorize);
            } finally {
                $lock->release();
            }
        } catch (IOException|LockExceptionInterface|\JsonException) {
            HookDiagnostic::write('hook_state_failed');

            return false;
        }
    }

    /**
     * @param \Closure(): ?StatusClient $authorize
     * @param array<array-key, mixed>   $hook
     */
    private function reportLocked(\Closure $authorize, string $path, string $session, string $event, array $hook): bool
    {
        $previous = file_exists($path) ? $this->readState($path) : [];
        if (null === $previous) {
            return false;
        }
        $sequence = ($previous['sequence'] ?? 0) + 1;
        if (!EventSchema::sequence($sequence)) {
            return false;
        }
        $kind = 'session.snapshot';
        $state = $previous;
        if ('SessionStart' === $event) {
            $source = $hook['source'] ?? null;
            if (isset($hook['agent_id']) || !self::acceptsSelection($previous, $source, $hook['prompt_id'] ?? null)) {
                return false;
            }
            $state = [
                'epoch' => $sequence, 'session' => $session, 'ended' => false, 'switch' => null, 'boundaries' => $previous['boundaries'] ?? [],
                'turn' => null, 'waits' => [], 'tools' => [], 'scripts' => null, 'agents' => 'resume' === $source ? null : [],
            ];
        } elseif (($state['session'] ?? null) !== $session || ($state['ended'] ?? true)) {
            return false;
        } elseif ('SubagentStart' === $event || 'SubagentStop' === $event) {
            $agent = $hook['agent_id'] ?? null;
            if (!\is_string($agent) || !EventSchema::id($agent) || null === $state['agents']) {
                return false;
            }
            $id = hash('sha256', $agent);
            if ('SubagentStart' === $event) {
                if (isset($state['agents'][$id])) {
                    return false;
                }
                if (\count($state['agents']) >= 1024) {
                    $state['agents'] = null;
                } else {
                    $state['agents'][$id] = true;
                }
            } elseif (isset($state['agents'][$id])) {
                unset($state['agents'][$id]);
            } else {
                return false;
            }
        } elseif (isset($hook['agent_id'])) {
            return false;
        } elseif ('SessionEnd' === $event) {
            $reason = $hook['reason'] ?? null;
            if ('resume' === $reason || 'clear' === $reason) {
                $prompt = $hook['prompt_id'] ?? null;
                // A repeated outgoing boundary must not arm a switch after
                // returning to the same conversation in a later epoch.
                if (!\is_string($prompt) || !Uuid::isValid($prompt) || isset($state['boundaries'][strtolower($prompt)])
                    || \count($state['boundaries']) >= self::MAX_SWITCHES) {
                    return false;
                }
                $state['switch'] = $reason;
                $state['boundaries'][strtolower($prompt)] = true;
            } elseif (!\in_array($reason, ['logout', 'prompt_input_exit', 'other'], true)) {
                return false;
            }
            $state['ended'] = true;
            $state['waits'] = [];
            $state['tools'] = [];
            $kind = 'session.ended';
        } else {
            $prompt = $hook['prompt_id'] ?? null;
            if (!\is_string($prompt) || !Uuid::isValid($prompt)) {
                return false;
            }
            $turn = $state['turn'];
            if ('UserPromptSubmit' === $event) {
                if ($prompt === ($turn['id'] ?? null)) {
                    return false;
                }
                $state['turn'] = ['id' => $prompt, 'status' => 'working'];
                $state['waits'] = [];
                $state['tools'] = [];
            } elseif (null === $turn || $prompt !== $turn['id']) {
                return false;
            } elseif ('working' !== $turn['status']) {
                return false;
            } elseif ('Stop' === $event && false === ($hook['stop_hook_active'] ?? null)) {
                $state['scripts'] = self::scriptCount($hook['background_tasks'] ?? null);
                $state['turn']['status'] = 'completed';
                $state['waits'] = [];
                $state['tools'] = [];
            } elseif ('StopFailure' === $event && \is_string($hook['error'] ?? null)) {
                $state['turn']['status'] = 'failed';
                $state['waits'] = [];
                $state['tools'] = [];
            } elseif (\in_array($event, ['PermissionRequest', 'PreToolUse', 'PostToolUse', 'PostToolUseFailure'], true)) {
                if (!self::observeTool($state['tools'], $event, $hook, $sequence)) {
                    return false;
                }
            } else {
                $server = $hook['mcp_server_name'] ?? null;
                $id = $hook['elicitation_id'] ?? null;
                if (!\is_string($server) || !\is_string($id) || !EventSchema::id($server) || !EventSchema::id($id)) {
                    return false;
                }
                $key = hash('sha256', $server."\0".$id);
                if ('Elicitation' === $event) {
                    if (isset($state['waits'][$key]) || \count($state['waits']) >= 16) {
                        return false;
                    }
                    $state['waits'][$key] = $sequence;
                } else {
                    $start = $state['waits'][$key] ?? 0;
                    if ($start <= 0 || !\in_array($hook['action'] ?? null, ['accept', 'decline', 'cancel'], true)) {
                        return false;
                    }
                    // Keep a bounded tombstone so repeated hooks cannot reopen the wait.
                    $state['waits'][$key] = -$start;
                }
            }
        }
        $state['sequence'] = $sequence;
        $wait = array_find($state['waits'], static fn (int $start): bool => $start > 0);
        $attention = null === $wait ? null : ['id' => (string) $wait, 'reason' => 'external-input'];
        foreach ($state['tools'] as $tool) {
            if (null !== $tool['wait'] && (null === $attention || $tool['wait'] < (int) $attention['id'])) {
                $attention = ['id' => (string) $tool['wait'], 'reason' => 'AskUserQuestion' === $tool['name'] ? 'question' : 'permission'];
            }
        }
        $fields = 'session.snapshot' === $kind ? [
            'provider' => 'claude', 'turn' => $state['turn'],
            'attention' => $attention,
            'activeSubagents' => null === $state['agents'] ? null : \count($state['agents']),
            'backgroundScripts' => $state['scripts'], 'tool' => $state['tools'][array_key_last($state['tools']) ?? '']['name'] ?? null,
        ] : [];
        // Persist the observation and reserve its sequence before I/O. A missed
        // reply does not discard local state or cause historical replay.
        (new Filesystem())->dumpFile($path, json_encode($state, \JSON_THROW_ON_ERROR));

        return $authorize()?->send($kind, $session, $sequence, $state['epoch'], $fields) ?? false;
    }

    /**
     * @param array<string, array{name: string, fingerprint: ?string, wait: ?int}> $tools
     * @param array<array-key, mixed>                                              $hook
     */
    private static function observeTool(array &$tools, string $event, array $hook, int $sequence): bool
    {
        $name = $hook['tool_name'] ?? null;
        if (!\is_string($name) || !\in_array($name, ['Agent', 'AskUserQuestion', 'Bash', 'Edit', 'Glob', 'Grep', 'Read', 'Write'], true)) {
            return false;
        }
        if ('PermissionRequest' === $event) {
            $fingerprint = self::fingerprint($hook['tool_input'] ?? null);
            if (null === $fingerprint) {
                return false;
            }
            // PermissionRequest omits tool_use_id. Never choose between two
            // outstanding calls with the same tool name and input.
            $matches = array_keys(array_filter($tools, static fn (array $tool): bool => $tool['name'] === $name && $tool['fingerprint'] === $fingerprint));
            if (1 !== \count($matches) || null !== $tools[$matches[0]]['wait']) {
                return false;
            }
            $tools[$matches[0]]['wait'] = $sequence;

            return true;
        }
        $id = $hook['tool_use_id'] ?? null;
        if (!\is_string($id) || !EventSchema::id($id)) {
            return false;
        }
        if ('PreToolUse' === $event) {
            if (isset($tools[$id]) || \count($tools) >= 32) {
                return false;
            }
            $tools[$id] = ['name' => $name, 'fingerprint' => self::fingerprint($hook['tool_input'] ?? null), 'wait' => 'AskUserQuestion' === $name ? $sequence : null];
        } elseif (($tools[$id]['name'] ?? null) === $name) {
            unset($tools[$id]);
        } else {
            return false;
        }

        return true;
    }

    private static function fingerprint(mixed $input): ?string
    {
        $token = getenv('H360_STATUS_TOKEN');
        if (false === $token) {
            $token = getenv('H360_STATUS_BOOTSTRAP_TOKEN');
        }
        if (!$input instanceof \stdClass || false === $token) {
            return null;
        }
        // Exact representation only: an edited or reordered input safely does
        // not match. Key the digest so persisted state cannot reveal guesses.
        $json = json_encode($input, \JSON_THROW_ON_ERROR);

        return \strlen($json) <= 16_384 ? hash_hmac('sha256', $json, $token) : null;
    }

    private static function scriptCount(mixed $tasks): ?int
    {
        if (!\is_array($tasks) || !array_is_list($tasks) || \count($tasks) > 1024) {
            return null;
        }
        $shells = [];
        foreach ($tasks as $task) {
            if (!$task instanceof \stdClass || !EventSchema::id($task->id ?? null) || !\is_string($task->type ?? null) || !\is_string($task->status ?? null)
                || '' === $task->type || '' === $task->status) {
                return null;
            }
            if ('shell' === $task->type && 'running' === $task->status) {
                $shells[$task->id] = true;
            }
        }

        return \count($shells);
    }

    /** @param array<string, mixed> $previous */
    private static function acceptsSelection(array $previous, mixed $source, mixed $prompt): bool
    {
        // Compaction and background forks do not select a conversation.
        if ([] === $previous) {
            return \in_array($source, ['startup', 'resume', 'clear'], true);
        }
        if (!\in_array($source, ['resume', 'clear'], true) || $source !== $previous['switch']) {
            return false;
        }

        // Claude 2.1.330 omits prompt_id on the incoming clear hook.
        return ('clear' === $source && null === $prompt)
            || (\is_string($prompt) && strtolower($prompt) === array_key_last($previous['boundaries']));
    }

    /** @return array{sequence: int, epoch: int, session: string, ended: bool, switch: 'resume'|'clear'|null, boundaries: array<string, true>, turn: array{id: string, status: string}|null, waits: array<string, int>, tools: array<string, array{name: string, fingerprint: ?string, wait: ?int}>, scripts: ?int, agents: array<string, true>|null}|null */
    private function readState(string $path): ?array
    {
        if (is_link($path) || !is_file($path)) {
            return null;
        }
        $json = @file_get_contents($path, length: 80_000);
        $state = false === $json ? null : json_decode($json, true, depth: 8, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($state) || !EventSchema::sequence($state['sequence'] ?? null) || !EventSchema::sequence($state['epoch'] ?? null)
            || $state['epoch'] > $state['sequence'] || !EventSchema::id($state['session'] ?? null) || !\is_bool($state['ended'] ?? null)
            || !\array_key_exists('turn', $state) || !\array_key_exists('agents', $state) || !\array_key_exists('scripts', $state) || !\is_array($state['waits'] ?? null) || !\is_array($state['tools'] ?? null)) {
            return null;
        }
        if (!\array_key_exists('switch', $state) || !\is_array($state['boundaries'] ?? null) || \count($state['boundaries']) > self::MAX_SWITCHES
            || !array_all($state['boundaries'], static fn (mixed $value, mixed $key): bool => true === $value && \is_string($key) && Uuid::isValid($key))
            || (null !== $state['switch'] && (!$state['ended'] || [] === $state['boundaries'] || !\in_array($state['switch'], ['clear', 'resume'], true)))) {
            return null;
        }
        $agents = $state['agents'];
        $waits = $state['waits'];
        if (\count($state['tools']) > 32 || !array_all($state['tools'], static fn (mixed $tool): bool => \is_array($tool) && \is_string($tool['name'] ?? null)
            && \array_key_exists('wait', $tool) && (null === $tool['wait'] || EventSchema::sequence($tool['wait']))
            && \array_key_exists('fingerprint', $tool) && (null === $tool['fingerprint'] || (\is_string($tool['fingerprint']) && 1 === preg_match('/\A[a-f0-9]{64}\z/', $tool['fingerprint']))))
            || \count($waits) > 16 || !array_all($waits, static fn (mixed $value): bool => \is_int($value) && 0 !== $value)
            || (null !== $agents && (!\is_array($agents) || \count($agents) > 1024 || !array_all($agents, static fn (mixed $value): bool => true === $value)))
            || !EventSchema::accepts('session.snapshot', $state['session'], $state['sequence'], $state['epoch'], [
                'provider' => 'claude', 'turn' => $state['turn'], 'attention' => null, 'activeSubagents' => null, 'backgroundScripts' => $state['scripts'], 'tool' => null,
            ])) {
            return null;
        }

        return $state;
    }
}
