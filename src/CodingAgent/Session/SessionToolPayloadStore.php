<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadDataDTO;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Ineersa\CodingAgent\Session\Exception\ToolPayloadException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Immutable, content-addressed data shared by transport and the batch index. */
final readonly class SessionToolPayloadStore implements ToolPayloadStoreInterface
{
    /** @param array{run_id: string, path: string}|null $runRoot */
    public function __construct(
        private ToolBatchRunStoragePathsInterface $paths,
        private SerializerInterface&NormalizerInterface&DenormalizerInterface $serializer,
        private ValidatorInterface $validator,
        private Filesystem $filesystem,
        private LockFactory $locks,
        private ?array $runRoot = null,
    ) {
    }

    public function forRun(string $runId): ToolPayloadStoreInterface
    {
        $root = $this->root($runId);
        if (null !== $this->runRoot) {
            return $this;
        }

        return new self($this->paths, $this->serializer, $this->validator, $this->filesystem, $this->locks, ['run_id' => $runId, 'path' => $root]);
    }

    public function publish(ExecuteToolCall|ExecuteShellToolCall|ToolCallResult $message): ToolPayloadReferenceDTO
    {
        $kind = match (true) {
            $message instanceof ExecuteToolCall => 'call',
            $message instanceof ExecuteShellToolCall => 'shell',
            default => 'result',
        };
        // Preserve the configured snake-case representation. Private bus fields
        // are explicit constructor inputs, not inferred getter properties.
        $data = $message instanceof ToolCallResult
            ? (new ToolExecutionEndPayloadCodec($this->serializer))->normalizeResult($message)
            : $this->serializer->normalize($message);
        $data += ['run_id' => $message->runId(), 'turn_no' => $message->turnNo(), 'step_id' => $message->stepId(), 'attempt' => $message->attempt(), 'idempotency_key' => $message->idempotencyKey()];
        $document = new ToolPayloadDataDTO($kind, $data);
        $this->validate($document);
        $json = $this->serializer->serialize($document, 'json');
        $name = $message instanceof ExecuteToolCall ? $message->toolName : ($message instanceof ExecuteShellToolCall ? 'bash' : ($data['result']['tool_name'] ?? null));
        $reference = new ToolPayloadReferenceDTO($kind, $message->runId(), $message->turnNo(), $message->stepId(), $message->attempt(), $message->idempotencyKey(), $message->toolCallId, $message instanceof ExecuteShellToolCall ? 0 : $message->orderIndex, \is_string($name) ? $name : null, hash('sha256', $json), \strlen($json));
        $this->seal($reference, $json);

        return $reference;
    }

    public function publishCorrelation(DeferredToolCompletionCorrelation $correlation): ToolPayloadReferenceDTO
    {
        $data = $this->serializer->normalize($correlation);
        $document = new ToolPayloadDataDTO('correlation', $data);
        $this->validate($document);
        $json = $this->serializer->serialize($document, 'json');
        $reference = new ToolPayloadReferenceDTO('correlation', $correlation->runId, $correlation->turnNo, $correlation->stepId, $correlation->attempt, $correlation->idempotencyKey, $correlation->toolCallId, $correlation->orderIndex, $correlation->toolName, hash('sha256', $json), \strlen($json));
        $this->seal($reference, $json);

        return $reference;
    }

    public function read(ToolPayloadReferenceDTO $reference): ExecuteToolCall|ExecuteShellToolCall|ToolCallResult|DeferredToolCompletionCorrelation
    {
        $this->validate($reference);

        $json = $this->readJson($reference);
        $document = $this->serializer->deserialize($json, ToolPayloadDataDTO::class, 'json');
        $this->validate($document);
        if ($document->kind !== $reference->kind) {
            throw new ToolPayloadException('Tool data kind does not match its reference.');
        }
        $type = match ($reference->kind) {
            'call' => ExecuteToolCall::class,
            'shell' => ExecuteShellToolCall::class,
            'result' => ToolCallResult::class,
            'correlation' => DeferredToolCompletionCorrelation::class,
            default => throw new ToolPayloadException('Unsupported tool data kind.'),
        };
        $message = $this->serializer->denormalize($document->data, $type);
        $this->validate($message);
        $this->assertIdentity($message, $reference);

        return $message instanceof ToolCallResult ? $message->finalized() : $message;
    }

    public function readCorrelation(ToolPayloadReferenceDTO $reference): DeferredToolCompletionCorrelation
    {
        $data = $this->read($reference);
        if (!$data instanceof DeferredToolCompletionCorrelation) {
            throw new ToolPayloadException('Deferred correlation reference has a different data kind.');
        }

        return $data;
    }

    public function deleteCall(string $runId, string $toolCallId): void
    {
        $this->filesystem->remove($this->directory($runId, $toolCallId));
    }

    public function retainProcessedResult(ToolPayloadReferenceDTO $invocation, ToolCallResult $result): ToolPayloadReferenceDTO
    {
        $this->validate($invocation);
        if ('call' !== $invocation->kind || null !== $result->pendingHumanInput || ($result->result['tool_name'] ?? null) !== $invocation->toolName) {
            throw new ToolPayloadException('Processed terminal does not match its invocation.');
        }
        $this->assertIdentity($result, $invocation);
        $anchor = $this->processedPath($invocation);
        // This short publication lock is independent of owner admission. The
        // candidate is bound to the full invocation, never another worker's turn.
        $lock = $this->locks->createLock('processed-tool-'.hash('sha256', $anchor));
        if (!$lock->acquire(true)) {
            throw new ToolPayloadException('Processed terminal evidence cannot be locked.');
        }
        try {
            $existing = $this->findProcessedResultReference($invocation);
            if (null !== $existing) {
                return $existing;
            }
            $reference = $this->publish($result);
            $this->filesystem->dumpFile($anchor, $this->serializer->serialize($reference, 'json'));
            $this->filesystem->chmod($anchor, 0600);

            return $reference;
        } finally {
            $lock->release();
        }
    }

    public function findProcessedResultReference(ToolPayloadReferenceDTO $invocation): ?ToolPayloadReferenceDTO
    {
        $this->validate($invocation);
        if ('call' !== $invocation->kind) {
            throw new ToolPayloadException('Processed terminal requires a call reference.');
        }
        $path = $this->processedPath($invocation);
        if (is_link($path)) {
            throw new ToolPayloadException('Processed terminal cannot be a symbolic link.');
        }
        if (!is_file($path)) {
            return null;
        }
        // Each reference string is at most 255 bytes. Even fully escaped JSON
        // fits here; repair discovery never reads a potentially large result.
        if (filesize($path) > 16_384) {
            throw new ToolPayloadException('Processed terminal reference exceeds its metadata bounds.');
        }
        $json = $this->filesystem->readFile($path);
        $reference = $this->serializer->deserialize($json, ToolPayloadReferenceDTO::class, 'json');
        $this->validate($reference);
        if ('result' !== $reference->kind || [$reference->runId, $reference->turnNo, $reference->stepId, $reference->attempt, $reference->idempotencyKey, $reference->toolCallId, $reference->orderIndex, $reference->toolName]
            !== [$invocation->runId, $invocation->turnNo, $invocation->stepId, $invocation->attempt, $invocation->idempotencyKey, $invocation->toolCallId, $invocation->orderIndex, $invocation->toolName]) {
            throw new ToolPayloadException('Processed terminal reference has a different invocation identity.');
        }

        return $reference;
    }

    public function deleteAllForRun(string $runId): void
    {
        $this->filesystem->remove(\dirname($this->directory($runId, 'cleanup')));
    }

    private function processedPath(ToolPayloadReferenceDTO $invocation): string
    {
        return $this->directory($invocation->runId, $invocation->toolCallId).'/processed-'.$invocation->sha256.'.json';
    }

    private function readJson(ToolPayloadReferenceDTO $reference): string
    {
        $path = $this->path($reference);
        if (!is_file($path) || is_link($path)) {
            throw new ToolPayloadException('Active tool data is missing.');
        }
        $stream = fopen($path, 'rb');
        if (false === $stream) {
            throw new ToolPayloadException('Active tool data cannot be opened.');
        }
        try {
            $stat = fstat($stream);
            if (false === $stat || $stat['size'] !== $reference->bytes) {
                throw new ToolPayloadException('Active tool data failed its integrity check.');
            }
            $json = stream_get_contents($stream);
            if (false === $json) {
                throw new ToolPayloadException('Active tool data cannot be read.');
            }
            if (\strlen($json) !== $reference->bytes || hash('sha256', $json) !== $reference->sha256) {
                throw new ToolPayloadException('Active tool data failed its integrity check.');
            }

            return $json;
        } finally {
            fclose($stream);
        }
    }

    private function assertIdentity(ExecuteToolCall|ExecuteShellToolCall|ToolCallResult|DeferredToolCompletionCorrelation $message, ToolPayloadReferenceDTO $reference): void
    {
        $expected = [$reference->runId, $reference->turnNo, $reference->stepId, $reference->attempt, $reference->idempotencyKey, $reference->toolCallId];
        if ($message instanceof DeferredToolCompletionCorrelation) {
            $actual = [$message->runId, $message->turnNo, $message->stepId, $message->attempt, $message->idempotencyKey, $message->toolCallId, $message->orderIndex, $message->toolName];
            if ($actual !== [...$expected, $reference->orderIndex, $reference->toolName]) {
                throw new ToolPayloadException('Deferred correlation data identity does not match its reference.');
            }

            return;
        }
        $actual = [$message->runId(), $message->turnNo(), $message->stepId(), $message->attempt(), $message->idempotencyKey(), $message->toolCallId];
        // Shells have no batch order. Results may omit the routing tool name.
        if (!$message instanceof ExecuteShellToolCall) {
            $actual[] = $message->orderIndex;
            $expected[] = $reference->orderIndex;
        }
        if ($message instanceof ExecuteToolCall) {
            $actual[] = $message->toolName;
            $expected[] = $reference->toolName;
        }
        if ($actual !== $expected) {
            throw new ToolPayloadException('Tool data identity does not match its reference.');
        }
    }

    private function seal(ToolPayloadReferenceDTO $reference, string $json): void
    {
        $this->validate($reference);
        $path = $this->path($reference);
        $this->filesystem->mkdir(\dirname($path), 0700);
        if (is_link($path)) {
            throw new ToolPayloadException('Tool data cannot be a symbolic link.');
        }
        if (is_file($path)) {
            if (filesize($path) !== $reference->bytes || hash_file('sha256', $path) !== $reference->sha256) {
                throw new ToolPayloadException('Immutable tool data has changed.');
            }

            return;
        }
        $this->filesystem->dumpFile($path, $json);
        $this->filesystem->chmod($path, 0600);
    }

    private function path(ToolPayloadReferenceDTO $reference): string
    {
        return $this->directory($reference->runId, $reference->toolCallId).'/'.$reference->sha256.'.json';
    }

    private function directory(string $runId, string $toolCallId): string
    {
        $path = $this->root($runId).'/'.hash('sha256', $toolCallId);
        if (is_link($path) || is_link(\dirname($path))) {
            throw new ToolPayloadException('Tool data directory cannot be a symbolic link.');
        }

        return $path;
    }

    private function root(string $runId): string
    {
        if ('' === $runId || '.' === $runId || '..' === $runId || str_contains($runId, '/') || str_contains($runId, '\\') || str_contains($runId, "\0")) {
            throw new ToolPayloadException('Invalid tool data run identity.');
        }
        if (null !== $this->runRoot) {
            if ($this->runRoot['run_id'] !== $runId) {
                throw new ToolPayloadException('Scoped tool data has a different run identity.');
            }

            return $this->runRoot['path'];
        }

        return \dirname($this->paths->resolveToolBatchesDirectory($runId)).'/tool-payloads';
    }

    private function validate(object $data): void
    {
        $errors = $this->validator->validate($data);
        if (0 !== \count($errors)) {
            throw new ValidationFailedException($data, $errors);
        }
    }
}
