<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Diagnostics;

use Ineersa\AgentCore\Infrastructure\SymfonyAi\ProviderRequestPreparedEvent;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\Template;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Records privacy-safe fingerprints from Hatfield's final prepared request.
 *
 * The typed event carries run correlation separately from provider options.
 * Storage and normalization failures are logged and never break invocation.
 */
final class PromptCacheDiagnosticsInvocationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly PromptCacheDiagnosticsStore $store,
        private readonly HatfieldModelCatalog $modelCatalog,
        private readonly LoggerInterface $logger,
        private readonly bool $writeDiagnostics,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [ProviderRequestPreparedEvent::class => 'recordDiagnostics'];
    }

    public function recordDiagnostics(ProviderRequestPreparedEvent $event): void
    {
        if (!$this->writeDiagnostics) {
            return;
        }

        $runId = $event->invocationInput->runId;
        if (null === $runId || '' === $runId || !$event->input instanceof MessageBag) {
            return;
        }

        try {
            $provider = $event->resolvedModel->providerId;
            $hmacKeySource = $this->resolveHmacKeySource($event->options, $runId);

            $this->store->append($runId, [
                'step_id' => $event->invocationInput->stepId,
                'model' => $event->model,
                'provider' => $provider,
                'transport' => $this->resolveTransport($provider),
                'cache_family_fp' => $this->hmac($hmacKeySource, $hmacKeySource),
                'components' => $this->buildComponents($event->input, $event->options, $hmacKeySource),
            ]);
        } catch (\Throwable $e) {
            // Diagnostics must never break provider invocation.
            $this->logger->warning('session.prompt_cache_diagnostics.record_failed', [
                'component' => 'prompt_cache_diagnostics_subscriber',
                'event_type' => 'session.prompt_cache_diagnostics.record_failed',
                'run_id' => $runId,
                'step_id' => $event->invocationInput->stepId,
                'exception_class' => $e::class,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<array{section: string, type: ?string, role: ?string, name: ?string, hmac: string, bytes: int}>
     */
    private function buildComponents(MessageBag $bag, array $options, string $hmacKeySource): array
    {
        $components = [];

        foreach ($bag->getMessages() as $message) {
            if ($message instanceof SystemMessage) {
                $content = $message->getContent();
                $payload = $content instanceof Template
                    ? ['kind' => 'template', 'template' => $content->getTemplate(), 'type' => $content->getType()]
                    : ['kind' => 'text', 'text' => $content];
                $components[] = $this->component('instructions', 'system', 'system', null, $payload, $hmacKeySource);
                continue;
            }

            if ($message instanceof UserMessage) {
                $parts = [];
                foreach ($message->getContent() as $part) {
                    $parts[] = $part instanceof Text
                        ? ['kind' => 'text', 'text' => $part->getText()]
                        : ['kind' => $part::class];
                }
                $components[] = $this->component('messages', 'user', 'user', null, $parts, $hmacKeySource);
                continue;
            }

            if ($message instanceof AssistantMessage) {
                $payload = [
                    'text' => $message->asText(),
                    'thinking' => array_map(static fn ($t): string => $t->getContent(), $message->getThinking()),
                    'tool_calls' => array_map(
                        static fn (ToolCall $c): array => ['name' => $c->getName(), 'arguments' => $c->getArguments()],
                        $message->getToolCalls(),
                    ),
                ];
                $components[] = $this->component('messages', 'assistant', 'assistant', null, $payload, $hmacKeySource);
                continue;
            }

            if ($message instanceof ToolCallMessage) {
                $toolCall = $message->getToolCall();
                $payload = [
                    'tool_name' => $toolCall->getName(),
                    'arguments' => $toolCall->getArguments(),
                    'result_text' => $message->asText(),
                ];
                $components[] = $this->component('messages', 'tool', 'tool', $toolCall->getName(), $payload, $hmacKeySource);
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
                $components[] = $this->component('tools', 'function', null, $tool->getName(), $payload, $hmacKeySource);
            }
        }

        return $components;
    }

    /**
     * @return array{section: string, type: ?string, role: ?string, name: ?string, hmac: string, bytes: int}
     */
    private function component(
        string $section,
        ?string $type,
        ?string $role,
        ?string $name,
        mixed $payload,
        string $hmacKeySource,
    ): array {
        $canonical = $this->canonicalJson($payload);

        return [
            'section' => $section,
            'type' => $type,
            'role' => $role,
            'name' => $name,
            'hmac' => $this->hmac($canonical, $hmacKeySource),
            'bytes' => \strlen($canonical),
        ];
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
        $promptCacheKey = $options['prompt_cache_key'] ?? null;

        return \is_string($promptCacheKey) && '' !== $promptCacheKey ? $promptCacheKey : $runId;
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
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
