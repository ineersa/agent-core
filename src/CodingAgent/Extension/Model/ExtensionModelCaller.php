<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Model;

use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\SymfonyPlatformFactoryInterface;
use Ineersa\Hatfield\ExtensionApi\Model\ModelCallException;
use Ineersa\Hatfield\ExtensionApi\Model\ModelCallResultDTO;
use Ineersa\Hatfield\ExtensionApi\Model\ModelToolCallDTO;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\Role;
use Symfony\AI\Platform\PlatformInterface as SymfonyPlatformInterface;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Dedicated non-streaming model caller for ExtensionApiInterface::callModel().
 *
 * Resolves exact configured provider/model references only. Never injects
 * Hatfield ambient tools and never executes returned tool calls.
 *
 * Platform construction is intentionally lazy: kernel boot and Extension API
 * registration must not require provider credentials. createPlatform() runs
 * only inside call() after exact model validation succeeds.
 *
 * @internal
 */
final readonly class ExtensionModelCaller implements ExtensionModelCallInterface
{
    public function __construct(
        private AppConfig $appConfig,
        private SymfonyPlatformFactoryInterface $platformFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $tools
     * @param array<string, mixed>|null  $structuredContent
     */
    public function call(
        string $model,
        array $messages,
        array $tools = [],
        ?array $structuredContent = null,
    ): ModelCallResultDTO {
        $modelRef = $this->requireExactConfiguredModel($model);
        $messageBag = $this->buildMessageBag($messages);
        $platformTools = $this->buildTools($tools);

        $options = [
            'stream' => false,
        ];
        if ([] !== $platformTools) {
            $options['tools'] = $platformTools;
        }
        if (null !== $structuredContent) {
            if (!$this->isJsonSchemaObject($structuredContent)) {
                throw ModelCallException::invalidInput('structuredContent must be a JSON Schema object map.');
            }
            // OpenAI-compatible json_schema response_format semantics. Not all
            // providers honor this shape; unsupported providers fail through the
            // normal ModelCallException contract.
            $options['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'extension_structured_content',
                    'schema' => $structuredContent,
                    'strict' => true,
                ],
            ];
        }

        try {
            $platform = $this->createPlatformLazily($modelRef->toString());
            $deferred = $platform->invoke($modelRef->toString(), $messageBag, $options);
            $result = $deferred->getResult();
        } catch (ModelCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Decide the public error first so logs and thrown ModelCallException agree.
            if ($this->isUnsupportedNonStreaming($e)) {
                $errorCode = ModelCallException::CODE_UNSUPPORTED;
                $errorCategory = 'unsupported';
                $exception = ModelCallException::unsupported(
                    $modelRef->toString(),
                    'Provider does not support the requested non-streaming model call semantics.',
                );
            } else {
                $errorCode = ModelCallException::CODE_PROVIDER_FAILED;
                $errorCategory = $this->classifyFailure($e);
                $exception = ModelCallException::providerFailed($modelRef->toString(), $errorCategory);
            }

            $this->logger->warning('extension.model_call_failed', [
                'component' => 'extension_model_caller',
                'event_type' => 'model_call_failed',
                'model' => $modelRef->toString(),
                'provider' => $modelRef->providerId,
                'error_code' => $errorCode,
                'error_category' => $errorCategory,
                'exception_class' => $e::class,
            ]);

            throw $exception;
        }

        return $this->mapResult($modelRef->toString(), $result, null !== $structuredContent);
    }

    /**
     * Stub target for extension-supplied tool definitions. Never executed.
     */
    public function extensionToolStub(): void
    {
        throw new \LogicException('Extension model tools are definitions only and are never executed by callModel().');
    }

    private function createPlatformLazily(string $model): SymfonyPlatformInterface
    {
        try {
            return $this->platformFactory->createPlatform();
        } catch (\Throwable $e) {
            $this->logger->warning('extension.model_call_platform_create_failed', [
                'component' => 'extension_model_caller',
                'event_type' => 'model_call_platform_create_failed',
                'model' => $model,
                'exception_class' => $e::class,
            ]);

            throw ModelCallException::providerFailed($model, 'platform_unavailable');
        }
    }

    private function requireExactConfiguredModel(string $model): AiModelReference
    {
        if (str_starts_with($model, '@')) {
            throw ModelCallException::invalidModel($model);
        }

        $ref = AiModelReference::tryParse($model);
        if (null === $ref) {
            throw ModelCallException::invalidModel($model);
        }

        $catalog = $this->appConfig->catalog;
        if (null === $catalog || !$catalog->isAvailable($ref)) {
            throw ModelCallException::unknownModel($model);
        }

        return $ref;
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function buildMessageBag(array $messages): MessageBag
    {
        if ([] === $messages) {
            throw ModelCallException::invalidInput('messages must be a non-empty list.');
        }

        $bag = new MessageBag();
        foreach ($messages as $index => $message) {
            if (!\is_array($message)) {
                throw ModelCallException::invalidInput(\sprintf('messages[%d] must be an object/map.', $index));
            }
            $role = $message['role'] ?? null;
            if (!\is_string($role) || '' === $role) {
                throw ModelCallException::invalidInput(\sprintf('messages[%d].role is required.', $index));
            }
            $content = $message['content'] ?? '';
            if (!\is_string($content)) {
                throw ModelCallException::invalidInput(\sprintf('messages[%d].content must be a string when provided.', $index));
            }

            match ($role) {
                Role::System->value => $bag->add(Message::forSystem($content)),
                Role::User->value => $bag->add(Message::ofUser($content)),
                Role::Assistant->value => $this->addAssistantMessage($bag, $message, $content, $index),
                Role::ToolCall->value => $this->addToolMessage($bag, $message, $content, $index),
                default => throw ModelCallException::invalidInput(\sprintf('messages[%d].role "%s" is not supported.', $index, $role)),
            };
        }

        return $bag;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function addAssistantMessage(MessageBag $bag, array $message, string $content, int $index): void
    {
        $toolCalls = $this->parseAssistantToolCalls($message['tool_calls'] ?? null, $index);
        $parts = [];
        if ('' !== $content) {
            $parts[] = $content;
        }
        foreach ($toolCalls as $toolCall) {
            $parts[] = $toolCall;
        }
        if ([] === $parts) {
            $parts[] = '';
        }
        $bag->add(Message::ofAssistant(...$parts));
    }

    /**
     * @param array<string, mixed> $message
     */
    private function addToolMessage(MessageBag $bag, array $message, string $content, int $index): void
    {
        $toolCallId = $message['tool_call_id'] ?? null;
        if (!\is_string($toolCallId) || '' === $toolCallId) {
            throw ModelCallException::invalidInput(\sprintf('messages[%d].tool_call_id is required for role=tool.', $index));
        }
        $toolName = $message['name'] ?? 'tool';
        if (!\is_string($toolName) || '' === $toolName) {
            $toolName = 'tool';
        }
        $bag->add(Message::ofToolCall(
            new ToolCall($toolCallId, $toolName, []),
            $content,
        ));
    }

    /**
     * @return list<ToolCall>
     */
    private function parseAssistantToolCalls(mixed $raw, int $messageIndex): array
    {
        if (null === $raw) {
            return [];
        }
        if (!\is_array($raw)) {
            throw ModelCallException::invalidInput(\sprintf('messages[%d].tool_calls must be a list.', $messageIndex));
        }

        $toolCalls = [];
        foreach ($raw as $toolIndex => $entry) {
            if (!\is_array($entry)) {
                throw ModelCallException::invalidInput(\sprintf('messages[%d].tool_calls[%s] must be an object/map.', $messageIndex, (string) $toolIndex));
            }
            $id = $entry['id'] ?? null;
            $name = $entry['name'] ?? null;
            if (!\is_string($id) || '' === $id || !\is_string($name) || '' === $name) {
                throw ModelCallException::invalidInput(\sprintf('messages[%d].tool_calls[%s] requires id and name.', $messageIndex, (string) $toolIndex));
            }
            $arguments = $entry['arguments'] ?? [];
            if (!\is_array($arguments)) {
                throw ModelCallException::invalidInput(\sprintf('messages[%d].tool_calls[%s].arguments must be an object/map.', $messageIndex, (string) $toolIndex));
            }
            /* @var array<string, mixed> $arguments */
            $toolCalls[] = new ToolCall($id, $name, $arguments);
        }

        return $toolCalls;
    }

    /**
     * @param list<array<string, mixed>> $tools
     *
     * @return list<Tool>
     */
    private function buildTools(array $tools): array
    {
        $result = [];
        foreach ($tools as $index => $tool) {
            if (!\is_array($tool)) {
                throw ModelCallException::invalidInput(\sprintf('tools[%d] must be an object/map.', $index));
            }
            $name = $tool['name'] ?? null;
            if (!\is_string($name) || '' === $name) {
                throw ModelCallException::invalidInput(\sprintf('tools[%d].name is required.', $index));
            }
            $description = $tool['description'] ?? '';
            if (!\is_string($description)) {
                throw ModelCallException::invalidInput(\sprintf('tools[%d].description must be a string when provided.', $index));
            }
            $parameters = $tool['parameters'] ?? null;
            if (null !== $parameters && !\is_array($parameters)) {
                throw ModelCallException::invalidInput(\sprintf('tools[%d].parameters must be an object/map when provided.', $index));
            }

            $result[] = new Tool(
                new ExecutionReference(self::class, 'extensionToolStub'),
                $name,
                $description,
                $parameters,
            );
        }

        return $result;
    }

    private function mapResult(string $model, mixed $result, bool $structuredRequested): ModelCallResultDTO
    {
        try {
            $content = '';
            $toolCalls = [];
            $structured = null;

            if ($result instanceof TextResult) {
                $content = $result->getContent();
            } elseif ($result instanceof ToolCallResult) {
                foreach ($result->getContent() as $toolCall) {
                    $toolCalls[] = new ModelToolCallDTO(
                        id: $toolCall->getId(),
                        name: $toolCall->getName(),
                        arguments: $toolCall->getArguments(),
                    );
                }
            } elseif ($result instanceof ObjectResult) {
                $structured = $this->normalizeStructured($result->getContent());
                $content = $this->encodeStructured($structured);
            } elseif ($result instanceof MultiPartResult) {
                $content = $result->asText();
                $toolCallResult = $result->asToolCallResult();
                if (null !== $toolCallResult) {
                    foreach ($toolCallResult->getContent() as $toolCall) {
                        $toolCalls[] = new ModelToolCallDTO(
                            id: $toolCall->getId(),
                            name: $toolCall->getName(),
                            arguments: $toolCall->getArguments(),
                        );
                    }
                }
            } else {
                throw ModelCallException::providerFailed($model, 'unexpected_result_type');
            }

            if ($structuredRequested && null === $structured && '' !== $content) {
                $decoded = json_decode($content, true);
                if (\JSON_ERROR_NONE === json_last_error() && (\is_array($decoded) || \is_object($decoded))) {
                    $structured = $this->normalizeStructured($decoded);
                }
            }

            return new ModelCallResultDTO(
                model: $model,
                content: $content,
                toolCalls: $toolCalls,
                structuredContent: $structured,
            );
        } catch (ModelCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Never leak JsonException/ValueError/provider result internals across callModel().
            $this->logger->warning('extension.model_call_failed', [
                'component' => 'extension_model_caller',
                'event_type' => 'model_call_result_mapping_failed',
                'model' => $model,
                'error_code' => ModelCallException::CODE_PROVIDER_FAILED,
                'error_category' => 'result_mapping_failed',
                'exception_class' => $e::class,
            ]);

            throw ModelCallException::providerFailed($model, 'result_mapping_failed');
        }
    }

    /**
     * @return array<string, mixed>|list<mixed>
     */
    private function normalizeStructured(mixed $value): array
    {
        if (\is_array($value)) {
            return $value;
        }
        if (\is_object($value)) {
            /** @var array<string, mixed> $encoded */
            $encoded = json_decode(json_encode($value, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);

            return $encoded;
        }

        return [];
    }

    /**
     * @param array<string, mixed>|list<mixed> $structured
     */
    private function encodeStructured(array $structured): string
    {
        return json_encode($structured, \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function isJsonSchemaObject(array $schema): bool
    {
        if ([] === $schema) {
            return false;
        }

        // Require object-like schema keys; reject bare lists.
        return !array_is_list($schema);
    }

    private function classifyFailure(\Throwable $e): string
    {
        $message = mb_strtolower($e->getMessage());
        if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
            return 'timeout';
        }
        if (str_contains($message, 'rate') || str_contains($message, '429')) {
            return 'rate_limit';
        }
        if (str_contains($message, 'auth') || str_contains($message, '401') || str_contains($message, '403')) {
            return 'auth';
        }

        return 'provider_error';
    }

    /**
     * Only explicit non-streaming unsupport phrases. Do not match generic
     * "stream" substrings (e.g. "upstream connect timeout").
     */
    private function isUnsupportedNonStreaming(\Throwable $e): bool
    {
        $message = mb_strtolower($e->getMessage());

        return str_contains($message, 'non-stream')
            || str_contains($message, 'non streaming')
            || str_contains($message, 'streaming required')
            || str_contains($message, 'stream only')
            || str_contains($message, 'streaming-only')
            || str_contains($message, 'does not support non-streaming');
    }
}
