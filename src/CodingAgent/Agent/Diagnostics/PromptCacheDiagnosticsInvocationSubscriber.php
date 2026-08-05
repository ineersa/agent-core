<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Diagnostics;

use Ineersa\AgentCore\Infrastructure\SymfonyAi\PlatformInvocationMetadata;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Event\InvocationEvent;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Content\Thinking;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\Template;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Observes final shaped Symfony AI InvocationEvent requests and appends privacy-safe
 * structural fingerprints to CodingAgent-owned diagnostics sidecars.
 *
 * Priority +100 captures PlatformInvocationMetadata correlation before AgentCore strips it.
 * Priority -100 observes final MessageBag/tools/options after AgentCore shaping and persists.
 * Never mutates the event. Storage/normalization failures are logged and do not break invoke.
 *
 * Correlation is keyed by spl_object_id for the duration of one dispatch only (capture + record).
 */
final class PromptCacheDiagnosticsInvocationSubscriber implements EventSubscriberInterface
{
    /** @var array<int, array{run_id: string, turn_no: ?int, step_id: ?string}> */
    private array $correlationByEvent = [];

    public function __construct(
        private readonly PromptCacheDiagnosticsStore $store,
        private readonly HatfieldModelCatalog $modelCatalog,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            InvocationEvent::class => [
                ['captureCorrelation', 100],
                ['recordDiagnostics', -100],
            ],
        ];
    }

    public function captureCorrelation(InvocationEvent $event): void
    {
        $metadata = PlatformInvocationMetadata::extract($event->getOptions());
        if (null === $metadata) {
            return;
        }

        $runId = $metadata->input->runId;
        if (null === $runId || '' === $runId) {
            return;
        }

        $this->correlationByEvent[spl_object_id($event)] = [
            'run_id' => $runId,
            'turn_no' => $metadata->input->turnNo,
            'step_id' => $metadata->input->stepId,
        ];
    }

    public function recordDiagnostics(InvocationEvent $event): void
    {
        $eventId = spl_object_id($event);
        if (!isset($this->correlationByEvent[$eventId])) {
            return;
        }

        $correlation = $this->correlationByEvent[$eventId];
        unset($this->correlationByEvent[$eventId]);

        $input = $event->getInput();
        if (!$input instanceof MessageBag) {
            return;
        }

        try {
            $options = $event->getOptions();
            $modelName = $event->getModel()->getName();
            $provider = $this->resolveProvider($modelName);
            $transport = $this->resolveTransport($provider);
            $hmacKeySource = $this->resolveHmacKeySource($options, $correlation['run_id']);
            $components = $this->buildComponents($input, $options, $hmacKeySource);
            $canonical = $this->canonicalJson([
                'model' => $modelName,
                'components' => $components,
            ]);

            $record = [
                'recorded_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
                'run_id' => $correlation['run_id'],
                'turn_no' => $correlation['turn_no'],
                'step_id' => $correlation['step_id'],
                'model' => $modelName,
                'provider' => $provider,
                'transport' => $transport,
                'prompt_cache_key_present' => \array_key_exists('prompt_cache_key', $options)
                    || \array_key_exists('provider_cache_key', $options),
                'cache_family_fp' => $this->hmac($hmacKeySource, $hmacKeySource),
                'request_hmac' => $this->hmac($canonical, $hmacKeySource),
                'request_bytes' => \strlen($canonical),
                'components' => $components,
            ];

            $this->store->append($correlation['run_id'], $record);
        } catch (\Throwable $e) {
            // Diagnostics must never break provider invocation.
            $this->logger->warning('session.prompt_cache_diagnostics.record_failed', [
                'component' => 'prompt_cache_diagnostics_subscriber',
                'event_type' => 'session.prompt_cache_diagnostics.record_failed',
                'run_id' => $correlation['run_id'],
                'step_id' => $correlation['step_id'],
                'exception_class' => $e::class,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<array{
     *     section: string,
     *     type: ?string,
     *     role: ?string,
     *     name: ?string,
     *     hmac: string,
     *     bytes: int
     * }>
     */
    private function buildComponents(MessageBag $bag, array $options, string $hmacKeySource): array
    {
        $components = [];

        foreach ($bag->getMessages() as $message) {
            if ($message instanceof SystemMessage) {
                $payload = $this->normalizeSystemContent($message->getContent());
                $canonical = $this->canonicalJson($payload);
                $components[] = [
                    'section' => 'instructions',
                    'type' => 'system',
                    'role' => 'system',
                    'name' => null,
                    'hmac' => $this->hmac($canonical, $hmacKeySource),
                    'bytes' => \strlen($canonical),
                ];
                continue;
            }

            if ($message instanceof UserMessage) {
                $payload = $this->normalizeContentParts($message->getContent());
                $canonical = $this->canonicalJson($payload);
                $components[] = [
                    'section' => 'messages',
                    'type' => 'user',
                    'role' => 'user',
                    'name' => null,
                    'hmac' => $this->hmac($canonical, $hmacKeySource),
                    'bytes' => \strlen($canonical),
                ];
                continue;
            }

            if ($message instanceof AssistantMessage) {
                $payload = [
                    'text' => $message->asText(),
                    'thinking' => array_map(
                        static fn (Thinking $t): string => $t->getContent(),
                        $message->getThinking(),
                    ),
                    'tool_calls' => array_map(
                        static fn (ToolCall $c): array => [
                            'name' => $c->getName(),
                            'arguments' => $c->getArguments(),
                        ],
                        $message->getToolCalls(),
                    ),
                ];
                $canonical = $this->canonicalJson($payload);
                $components[] = [
                    'section' => 'messages',
                    'type' => 'assistant',
                    'role' => 'assistant',
                    'name' => null,
                    'hmac' => $this->hmac($canonical, $hmacKeySource),
                    'bytes' => \strlen($canonical),
                ];
                continue;
            }

            if ($message instanceof ToolCallMessage) {
                $toolCall = $message->getToolCall();
                $payload = [
                    'tool_name' => $toolCall->getName(),
                    'arguments' => $toolCall->getArguments(),
                    'result_text' => $message->asText(),
                ];
                $canonical = $this->canonicalJson($payload);
                $components[] = [
                    'section' => 'messages',
                    'type' => 'tool',
                    'role' => 'tool',
                    'name' => $toolCall->getName(),
                    'hmac' => $this->hmac($canonical, $hmacKeySource),
                    'bytes' => \strlen($canonical),
                ];
            }
        }

        $tools = $options['tools'] ?? null;
        if (\is_array($tools)) {
            foreach ($tools as $tool) {
                if (!$tool instanceof Tool) {
                    continue;
                }
                $payload = [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters' => $tool->getParameters(),
                ];
                $canonical = $this->canonicalJson($payload);
                $components[] = [
                    'section' => 'tools',
                    'type' => 'function',
                    'role' => null,
                    'name' => $tool->getName(),
                    'hmac' => $this->hmac($canonical, $hmacKeySource),
                    'bytes' => \strlen($canonical),
                ];
            }
        }

        return $components;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeSystemContent(string|Template $content): array
    {
        if ($content instanceof Template) {
            return [
                'kind' => 'template',
                'template' => $content->getTemplate(),
                'type' => $content->getType(),
            ];
        }

        return ['kind' => 'text', 'text' => $content];
    }

    /**
     * @param list<object> $parts
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeContentParts(array $parts): array
    {
        $normalized = [];
        foreach ($parts as $part) {
            if ($part instanceof Text) {
                $normalized[] = ['kind' => 'text', 'text' => $part->getText()];
                continue;
            }
            if ($part instanceof Thinking) {
                $normalized[] = ['kind' => 'thinking', 'text' => $part->getContent()];
                continue;
            }
            if ($part instanceof Template) {
                $normalized[] = [
                    'kind' => 'template',
                    'template' => $part->getTemplate(),
                    'type' => $part->getType(),
                ];
                continue;
            }
            if ($part instanceof ToolCall) {
                $normalized[] = [
                    'kind' => 'tool_call',
                    'name' => $part->getName(),
                    'arguments' => $part->getArguments(),
                ];
                continue;
            }
            $normalized[] = ['kind' => $part::class];
        }

        return $normalized;
    }

    private function resolveProvider(string $modelName): string
    {
        $ref = AiModelReference::tryParse($modelName);

        return null !== $ref ? $ref->providerId : 'unknown';
    }

    private function resolveTransport(string $providerId): string
    {
        if ('unknown' === $providerId) {
            return 'unknown';
        }

        $provider = $this->modelCatalog->getProvider($providerId);
        if (null === $provider) {
            return 'unknown';
        }

        if ('codex' === $provider->type) {
            $transport = $provider->transport;

            return null !== $transport && '' !== $transport ? $transport : 'websocket';
        }

        return 'http';
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveHmacKeySource(array $options, string $runId): string
    {
        $providerCacheKey = $options['provider_cache_key'] ?? null;
        if (\is_string($providerCacheKey) && '' !== $providerCacheKey) {
            return $providerCacheKey;
        }

        $promptCacheKey = $options['prompt_cache_key'] ?? null;
        if (\is_string($promptCacheKey) && '' !== $promptCacheKey) {
            return $promptCacheKey;
        }

        return $runId;
    }

    private function hmac(string $payload, string $keySource): string
    {
        return hash_hmac('sha256', $payload, $keySource);
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->canonicalize($value), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $out = [];
            foreach ($value as $item) {
                $out[] = $this->canonicalize($item);
            }

            return $out;
        }

        ksort($value);
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = $this->canonicalize($item);
        }

        return $out;
    }
}
