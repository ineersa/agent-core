<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;

/** A claimed call is never executed again, even when its outcome was not recorded. */
final readonly class CodeModeCallJournal
{
    public function __construct(private LockFactory $locks, private Filesystem $filesystem = new Filesystem())
    {
    }

    /**
     * @param array<string, mixed>             $request
     * @param callable(): array<string, mixed> $execute returns a terminal success or error reply
     *
     * @return array<string, mixed>
     */
    public function settle(string $workspace, string $callId, array $request, callable $execute): array
    {
        $path = $workspace.'/call-'.hash('sha256', $callId).'.json';
        $fingerprint = hash('sha256', CodeModeIpc::encode($request));
        $lock = $this->locks->createLock('code-mode.call.'.hash('sha256', $path));
        if (!$lock->acquire()) {
            throw new ToolCallException('Code-mode call is already being executed.', retryable: false);
        }
        try {
            if (is_file($path)) {
                $record = json_decode($this->filesystem->readFile($path), true, flags: \JSON_THROW_ON_ERROR);
                if (!\is_array($record) || ($record['request'] ?? null) !== $fingerprint) {
                    throw new ToolCallException('Code-mode call ID was reused for a different request.', retryable: false);
                }
                $reply = $record['reply'] ?? null;
                if (!\is_array($reply)) {
                    throw new ToolCallException('Code-mode call outcome is unknown; refusing to run it again.', retryable: false);
                }

                /* @var array<string, mixed> $reply */
                return $reply;
            }

            $this->filesystem->dumpFile($path, json_encode(['request' => $fingerprint], \JSON_THROW_ON_ERROR));
            $reply = $execute();
            // Keep the claim if execution, encoding, or persistence fails. A
            // replacement cannot infer that the effect did not happen.
            CodeModeIpc::encode($reply);
            $this->filesystem->dumpFile($path, json_encode(['request' => $fingerprint, 'reply' => $reply], \JSON_THROW_ON_ERROR));

            return $reply;
        } finally {
            $lock->release();
        }
    }

    /** @param array<string, mixed> $reply */
    public function completeChild(string $workspace, string $callId, string $childId, array $reply): bool
    {
        $path = $workspace.'/call-'.hash('sha256', $callId).'.json';
        $lock = $this->locks->createLock('code-mode.call.'.hash('sha256', $path));
        if (!$lock->acquire()) {
            return false;
        }
        try {
            if (!is_file($path)) {
                return false;
            }
            $record = json_decode($this->filesystem->readFile($path), true, flags: \JSON_THROW_ON_ERROR);
            if (($record['settledChild'] ?? null) === $childId) {
                return false;
            }
            if (($record['reply']['child'] ?? null) !== $childId) {
                return false;
            }
            CodeModeIpc::encode($reply);
            $record['reply'] = $reply;
            $record['settledChild'] = $childId;
            $this->filesystem->dumpFile($path, json_encode($record, \JSON_THROW_ON_ERROR));

            return true;
        } finally {
            $lock->release();
        }
    }
}
