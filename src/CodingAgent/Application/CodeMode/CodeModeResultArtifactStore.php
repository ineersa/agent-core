<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\CodeMode;

use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Ineersa\CodingAgent\Session\SessionToolPayloadStore;
use Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Immutable native results are outside disposable batch transport storage. */
final readonly class CodeModeResultArtifactStore
{
    public function __construct(private ToolBatchRunStoragePathsInterface $paths, private SerializerInterface&NormalizerInterface&DenormalizerInterface $serializer, private ValidatorInterface $validator, private Filesystem $filesystem, private LockFactory $locks)
    {
    }

    public function publish(ToolCallResult|ExecuteToolCall $result): ToolPayloadReferenceDTO
    {
        return $this->store($result->runId())->publish($result);
    }

    public function read(ToolPayloadReferenceDTO $reference): ToolCallResult
    {
        $result = $this->store($reference->runId)->read($reference);
        if (!$result instanceof ToolCallResult) {
            throw new \UnexpectedValueException('Code-mode artifact is not a terminal result.');
        }

        return $result;
    }

    public function invocation(ToolPayloadReferenceDTO $reference): ExecuteToolCall
    {
        $call = $this->store($reference->runId)->read($reference);
        if (!$call instanceof ExecuteToolCall) {
            throw new \UnexpectedValueException('Code-mode artifact is not an invocation.');
        }

        return $call;
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
