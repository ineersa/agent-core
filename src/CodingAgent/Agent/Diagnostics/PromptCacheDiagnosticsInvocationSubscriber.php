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
 * Never mutates the event. Disabled by default because it is append-only diagnostics,
 * not provider cache or replay state. Storage/normalization failures are logged and do not break invoke.
 *
 * Correlation uses WeakMap so an exception between the two priorities cannot leak entries
 * after the event object is released by the dispatcher.
 */
final class PromptCacheDiagnosticsInvocationSubscriber implements EventSubscriberInterface
{
    /**
     * Event → correlation for one dual-priority dispatch.
     *
     * WeakMap TValue is invariant in PHPStan, so the property uses array<string, mixed>
     * (not a shape generic). Local @var restores the run_id/step_id shape at use sites.
     * Entries GC when the InvocationEvent is released if recordDiagnostics never runs.
     *
     * @var \WeakMap<InvocationEvent, array<string, mixed>>
     *
     * @see https://phpstan.org/blog/whats-up-with-template-covariant
     */
    private \WeakMap $correlationByEvent;

    public function __construct(
        private readonly PromptCacheDiagnosticsStore $store,
        private readonly HatfieldModelCatalog $modelCatalog,
        private readonly LoggerInterface $logger,
        private readonly bool $writeDiagnostics,
    ) {
        // Fresh map per subscriber instance; entries GC when InvocationEvent is released.
        $this->correlationByEvent = new \WeakMap();
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
        if (!$this->writeDiagnostics) {
            return;
        }

        $metadata = PlatformInvocationMetadata::extract($event->getOptions());
        if (null === $metadata) {
            return;
        }

        $runId = $metadata->input->runId;
        if (null === $runId || '' === $runId) {
            return;
        }

        /** @var array{run_id: string, step_id: ?string} $correlation */
        $correlation = [
            'run_id' => $runId,
            'step_id' => $metadata->input->stepId,
        ];
        $this->correlationByEvent[$event] = $correlation;
    }

    public function recordDiagnostics(InvocationEvent $event): void
    {
        if (!isset($this->correlationByEvent[$event])) {
            return;
        }

        /** @var array{run_id: string, step_id: ?string} $correlation */
        $correlation = $this->correlationByEvent[$event];
        unset($this->correlationByEvent[$event]);

        $input = $event->getInput();
        if (!$input instanceof MessageBag) {
            return;
        }

        try {
            $options = $event->getOptions();
            $modelName = $event->getModel()->getName();
            $provider = $this->resolveProvider($modelName);
            $hmacKeySource = $this->resolveHmacKeySource($options, $correlation['run_id']);

            $this->store->append($correlation['run_id'], [
                'step_id' => $correlation['step_id'],
                'model' => $modelName,
                'provider' => $provider,
                'transport' => $this->resolveTransport($provider),
                'cache_family_fp' => $this->hmac($hmacKeySource, $hmacKeySource),
                'components' => $this->buildComponents($input, $options, $hmacKeySource),
            ]);
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
        foreach (['provider_cache_key', 'prompt_cache_key'] as $key) {
            $value = $options[$key] ?? null;
            if (\is_string($value) && '' !== $value) {
                return $value;
            }
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
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
