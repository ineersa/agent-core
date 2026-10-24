<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\CodeMode;

use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Ineersa\CodingAgent\Session\Exception\ToolPayloadException;
use Ineersa\CodingAgent\Session\SessionToolPayloadStore;
use Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Raw structured value and diagnostic evidence only. Processed terminals live in shared payload storage. */
final readonly class CodeModeResultArtifactStore
{
    public function __construct(private ToolBatchRunStoragePathsInterface $paths, private SerializerInterface&NormalizerInterface&DenormalizerInterface $serializer, private ValidatorInterface $validator, private Filesystem $filesystem, private LockFactory $locks)
    {
    }

    public function publishRaw(ToolCallResult $result): ToolPayloadReferenceDTO
    {
        return $this->store($result->runId())->publish($result);
    }

    public function retainDiagnostic(string $runId, string $toolCallId, string $source): string
    {
        $root = \dirname($this->paths->resolveToolBatchesDirectory($runId)).'/code-mode-results';

        return $this->paths->withStorageWriteLock($root, function () use ($root, $toolCallId, $source): string {
            $hash = hash_file('sha256', $source);
            if (false === $hash) {
                throw new \RuntimeException('Code-mode diagnostic cannot be hashed.');
            }
            $path = $root.'/'.hash('sha256', $toolCallId).'/diagnostics/'.$hash.'.txt';
            // Symfony copies through streams. Never hydrate an oversized diagnostic
            // or leave the retained terminal pointing into disposable OutputCap data.
            $this->filesystem->mkdir(\dirname($path), 0o700);
            if (is_link($path)) {
                throw new ToolPayloadException('Code-mode diagnostic cannot be a symbolic link.');
            }
            if (!is_file($path)) {
                $this->filesystem->copy($source, $path);
                $this->filesystem->chmod($path, 0o600);
            }
            if ($hash !== hash_file('sha256', $path)) {
                throw new ToolPayloadException('Immutable code-mode diagnostic has changed.');
            }

            return $path;
        });
    }

    public function read(ToolPayloadReferenceDTO $reference): ToolCallResult
    {
        $result = $this->store($reference->runId)->read($reference);
        if (!$result instanceof ToolCallResult) {
            throw new \UnexpectedValueException('Code-mode artifact is not a terminal result.');
        }

        return $result;
    }

    public function path(ToolPayloadReferenceDTO $reference): string
    {
        // Validate integrity and all path components before exposing owned data.
        $this->read($reference);

        return \dirname($this->paths->resolveToolBatchesDirectory($reference->runId)).'/code-mode-results/'.hash('sha256', $reference->toolCallId).'/'.$reference->sha256.'.json';
    }

    private function store(string $runId): SessionToolPayloadStore
    {
        return new SessionToolPayloadStore($this->paths, $this->serializer, $this->validator, $this->filesystem, $this->locks, ['run_id' => $runId, 'path' => \dirname($this->paths->resolveToolBatchesDirectory($runId)).'/code-mode-results']);
    }
}
