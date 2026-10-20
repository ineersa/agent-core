<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadDataDTO;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
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
    public function __construct(
        private ToolBatchRunStoragePathsInterface $paths,
        private LockFactory $lockFactory,
        private SerializerInterface&NormalizerInterface&DenormalizerInterface $serializer,
        private ValidatorInterface $validator,
        private Filesystem $filesystem,
    ) {
    }

    public function publish(ExecuteToolCall|ExecuteShellToolCall|ToolCallResult $message): ToolPayloadReferenceDTO
    {
        if ($message instanceof ToolCallResult) {
            $message = (new ToolExecutionEndPayloadCodec($this->serializer))->finalizeResult($message);
        }
        $kind = match (true) {
            $message instanceof ExecuteToolCall => 'call',
            $message instanceof ExecuteShellToolCall => 'shell',
            default => 'result',
        };
        // Preserve the configured snake-case representation. Private bus fields
        // are explicit constructor inputs, not inferred getter properties.
        $data = $this->serializer->normalize($message);
        $data += ['run_id' => $message->runId(), 'turn_no' => $message->turnNo(), 'step_id' => $message->stepId(), 'attempt' => $message->attempt(), 'idempotency_key' => $message->idempotencyKey()];
        $document = new ToolPayloadDataDTO($kind, $data);
        $this->validate($document);
        $json = $this->serializer->serialize($document, 'json');
        $name = $message instanceof ExecuteToolCall ? $message->toolName : ($message instanceof ExecuteShellToolCall ? 'bash' : ($message->result['tool_name'] ?? null));
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

    private function seal(ToolPayloadReferenceDTO $reference, string $json): void
    {
        $this->validate($reference);
        $this->locked($reference->runId, $reference->toolCallId, function () use ($reference, $json): void {
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
        });
    }

    public function read(ToolPayloadReferenceDTO $reference): ExecuteToolCall|ExecuteShellToolCall|ToolCallResult|DeferredToolCompletionCorrelation
    {
        $this->validate($reference);

        return $this->locked($reference->runId, $reference->toolCallId, function () use ($reference): ExecuteToolCall|ExecuteShellToolCall|ToolCallResult|DeferredToolCompletionCorrelation {
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
                $hash = hash_init('sha256');
                $bytes = hash_update_stream($hash, $stream);
                if (false === $stat || $stat['size'] !== $reference->bytes || $bytes !== $reference->bytes || hash_final($hash) !== $reference->sha256 || !rewind($stream)) {
                    throw new ToolPayloadException('Active tool data failed its integrity check.');
                }
                $json = stream_get_contents($stream);
                if (false === $json) {
                    throw new ToolPayloadException('Active tool data cannot be read.');
                }
            } finally {
                fclose($stream);
            }
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
            };
            $message = $this->serializer->denormalize($document->data, $type);
            $this->validate($message);
            if ($message instanceof DeferredToolCompletionCorrelation) {
                if ($message->runId !== $reference->runId || $message->turnNo !== $reference->turnNo || $message->stepId !== $reference->stepId || $message->attempt !== $reference->attempt || $message->idempotencyKey !== $reference->idempotencyKey || $message->toolCallId !== $reference->toolCallId || $message->orderIndex !== $reference->orderIndex || $message->toolName !== $reference->toolName) {
                    throw new ToolPayloadException('Deferred correlation data identity does not match its reference.');
                }

                return $message;
            }
            if ($message->runId() !== $reference->runId || $message->turnNo() !== $reference->turnNo || $message->stepId() !== $reference->stepId || $message->attempt() !== $reference->attempt || $message->idempotencyKey() !== $reference->idempotencyKey || $message->toolCallId !== $reference->toolCallId || (!$message instanceof ExecuteShellToolCall && $message->orderIndex !== $reference->orderIndex) || ($message instanceof ExecuteToolCall && $message->toolName !== $reference->toolName)) {
                throw new ToolPayloadException('Tool data identity does not match its reference.');
            }

            return $message instanceof ToolCallResult ? $message->finalized() : $message;
        });
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
        $this->locked($runId, $toolCallId, fn () => $this->filesystem->remove($this->directory($runId, $toolCallId)));
    }

    public function deleteAllForRun(string $runId): void
    {
        $this->filesystem->remove(\dirname($this->directory($runId, 'cleanup')));
    }

    private function path(ToolPayloadReferenceDTO $reference): string
    {
        return $this->directory($reference->runId, $reference->toolCallId).'/'.$reference->sha256.'.json';
    }

    private function directory(string $runId, string $toolCallId): string
    {
        if ('' === $runId || '.' === $runId || '..' === $runId || str_contains($runId, '/') || str_contains($runId, '\\') || str_contains($runId, "\0")) {
            throw new ToolPayloadException('Invalid tool data run identity.');
        }
        $path = \dirname($this->paths->resolveToolBatchesDirectory($runId)).'/tool-payloads/'.hash('sha256', $toolCallId);
        if (is_link($path) || is_link(\dirname($path))) {
            throw new ToolPayloadException('Tool data directory cannot be a symbolic link.');
        }

        return $path;
    }

    private function validate(object $data): void
    {
        $errors = $this->validator->validate($data);
        if (0 !== \count($errors)) {
            throw new ValidationFailedException($data, $errors);
        }
    }

    private function locked(string $runId, string $toolCallId, callable $operation): mixed
    {
        $lock = $this->lockFactory->createLock('tool-payload.'.hash('sha256', $runId.'|'.$toolCallId));
        $lock->acquire(true);
        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }
}
