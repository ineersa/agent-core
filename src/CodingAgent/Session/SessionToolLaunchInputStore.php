<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Contract\Tool\ToolLaunchInputStoreInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Tool\ToolLaunchContextDTO;
use Ineersa\AgentCore\Domain\Tool\ToolLaunchInputReferenceDTO;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Serializer\SerializerInterface;

/** Sealed, per-invocation files separate from mutable batch snapshots. */
final readonly class SessionToolLaunchInputStore implements ToolLaunchInputStoreInterface
{
    public function __construct(
        private ToolBatchRunStoragePathsInterface $paths,
        private LockFactory $lockFactory,
        private SerializerInterface $serializer,
        private Filesystem $filesystem,
    ) {
    }

    public function publish(string $kind, string $runId, int $turnNo, string $stepId, string $toolCallId, string $model, string $agentsContext, iterable $messages): ToolLaunchInputReferenceDTO
    {
        $path = $this->path($runId, $toolCallId);
        $lock = $this->lockFactory->createLock('tool-launch-input.'.hash('sha256', $runId.'|'.$toolCallId));
        $lock->acquire(true);
        try {
            $this->filesystem->mkdir(\dirname($path), 0700);
            $staging = $this->filesystem->tempnam(\dirname($path), '.staging-');
            try {
                $header = compact('kind', 'runId', 'turnNo', 'stepId', 'toolCallId', 'model', 'agentsContext');
                $this->filesystem->appendToFile($staging, json_encode($header, \JSON_THROW_ON_ERROR)."\n");
                foreach ($messages as $message) {
                    $this->filesystem->appendToFile($staging, $this->serializer->serialize($message, 'json')."\n");
                }
                $bytes = filesize($staging);
                $sha256 = hash_file('sha256', $staging);
                if (false === $bytes || false === $sha256) {
                    throw new \RuntimeException('Cannot seal tool launch input.');
                }
                if (is_link($path)) {
                    throw new \RuntimeException('Tool launch input cannot be a symbolic link.');
                }
                if (is_file($path)) {
                    if ($sha256 !== hash_file('sha256', $path)) {
                        throw new \RuntimeException('Conflicting immutable tool launch input.');
                    }
                } else {
                    $stream = fopen($staging, 'rb');
                    if (false === $stream) {
                        throw new \RuntimeException('Cannot open staged tool launch input.');
                    }
                    try {
                        $this->filesystem->dumpFile($path, $stream);
                        $this->filesystem->chmod($path, 0600);
                    } finally {
                        fclose($stream);
                    }
                }

                return new ToolLaunchInputReferenceDTO($kind, $runId, $turnNo, $stepId, $toolCallId, $model, $sha256, $bytes);
            } finally {
                $this->filesystem->remove($staging);
            }
        } finally {
            $lock->release();
        }
    }

    public function read(ToolLaunchInputReferenceDTO $reference): ToolLaunchContextDTO
    {
        $lock = $this->lockFactory->createLock('tool-launch-input.'.hash('sha256', $reference->producingRunId.'|'.$reference->toolCallId));
        $lock->acquire(true);
        try {
            return $this->readFile($reference);
        } finally {
            $lock->release();
        }
    }

    public function delete(string $runId, string $toolCallId): void
    {
        $lock = $this->lockFactory->createLock('tool-launch-input.'.hash('sha256', $runId.'|'.$toolCallId));
        $lock->acquire(true);
        try {
            $this->filesystem->remove($this->path($runId, $toolCallId));
        } finally {
            $lock->release();
        }
    }

    public function deleteAllForRun(string $runId): void
    {
        $directory = \dirname($this->path($runId, 'cleanup'));
        if (is_dir($directory)) {
            $this->filesystem->remove($directory);
        }
    }

    private function readFile(ToolLaunchInputReferenceDTO $reference): ToolLaunchContextDTO
    {
        $path = $this->path($reference->producingRunId, $reference->toolCallId);
        if (!is_file($path) || is_link($path)) {
            throw new \RuntimeException('Missing or corrupt tool launch input.');
        }
        $stream = fopen($path, 'rb');
        if (false === $stream) {
            throw new \RuntimeException('Cannot open tool launch input.');
        }
        try {
            $stat = fstat($stream);
            $hash = hash_init('sha256');
            $bytes = hash_update_stream($hash, $stream);
            if (false === $stat || $stat['size'] !== $reference->bytes || $bytes !== $reference->bytes
                || hash_final($hash) !== $reference->sha256 || !rewind($stream)) {
                throw new \RuntimeException('Missing or corrupt tool launch input.');
            }
            $line = fgets($stream);
            $header = false !== $line ? json_decode($line, true, flags: \JSON_THROW_ON_ERROR) : null;
            if (!\is_array($header) || ($header['kind'] ?? null) !== $reference->kind
                || ($header['runId'] ?? null) !== $reference->producingRunId
                || ($header['turnNo'] ?? null) !== $reference->producingTurnNo
                || ($header['stepId'] ?? null) !== $reference->producingStepId
                || ($header['toolCallId'] ?? null) !== $reference->toolCallId
                || ($header['model'] ?? null) !== $reference->producingModel) {
                throw new \RuntimeException('Tool launch input ownership mismatch.');
            }
            $messages = [];
            while (false !== ($line = fgets($stream))) {
                $messages[] = $this->serializer->deserialize($line, AgentMessage::class, 'json');
            }
            if (!feof($stream)) {
                throw new \RuntimeException('Incomplete tool launch input read.');
            }

            return new ToolLaunchContextDTO($reference->kind, $reference->producingRunId, $reference->producingTurnNo, $reference->producingModel, $header['agentsContext'], $messages);
        } finally {
            fclose($stream);
        }
    }

    private function path(string $runId, string $toolCallId): string
    {
        if ('' === $runId || str_contains($runId, '/') || str_contains($runId, '\\') || '.' === $runId || '..' === $runId || '' === $toolCallId) {
            throw new \InvalidArgumentException('Invalid tool launch input identity.');
        }

        return \dirname($this->paths->resolveToolBatchesDirectory($runId)).'/tool-launch-inputs/'.hash('sha256', $toolCallId).'.jsonl';
    }
}
