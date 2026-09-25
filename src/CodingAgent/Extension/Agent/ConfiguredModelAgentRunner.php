<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Agent;

use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Model\ModelResolverInterface;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelResolutionOptions;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\PreparedInvocationPlatform;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ProviderRequestPreparer;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\ConfiguredSymfonyAiPlatformFactory;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentCallRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentRunnerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Agent\Toolbox\ToolCallArgumentResolverInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Internal Hatfield runner for the public ExtensionApi agent capability.
 *
 * Reuses the configured Symfony AI Platform, Agent-owned toolbox loop, and
 * Hatfield routing metadata. Publicly blocking; streams
 * internally so Codex WebSocket and HTTP streaming providers complete.
 *
 * When {@see AgentCallRequestDTO::$maxDurationSeconds} is set, this runner
 * builds a selected-provider Platform once for the call (including tool-loop
 * turns) with matching idle timeout and max_duration budgets. Default calls
 * keep the shared Platform.
 */
final readonly class ConfiguredModelAgentRunner implements AgentRunnerInterface
{
    public function __construct(
        private PlatformInterface $platform,
        private ConfiguredSymfonyAiPlatformFactory $platformFactory,
        private ?HatfieldModelCatalog $modelCatalog,
        private LoggerInterface $logger,
        private ToolCallArgumentResolverInterface $argumentResolver,
        private ModelResolverInterface $modelResolver,
        private ProviderRequestPreparer $providerRequestPreparer,
    ) {
    }

    public function contextWindow(string $exactModel): ?int
    {
        // No AI settings → no catalog. Callers (OM chunk budgets) already treat
        // null/nonpositive as a durable config failure; never invent a window.
        if (null === $this->modelCatalog) {
            return null;
        }

        try {
            $model = $this->modelCatalog->requireModel(AiModelReference::parse($exactModel));
        } catch (\InvalidArgumentException|\RuntimeException) {
            // Missing/malformed catalog entries are durable config failures for callers.
            // Return null rather than inventing a default window.
            return null;
        }

        return $model->contextWindow;
    }

    public function run(AgentCallRequestDTO $request): void
    {
        $stepId = $this->buildStepId($request);
        $messages = new MessageBag(
            Message::forSystem($request->instructions),
            Message::ofUser($request->input),
        );

        $toolbox = null;
        if ([] !== $request->tools) {
            $isolated = new IsolatedAgentToolbox(array_values($request->tools), $this->argumentResolver);
            // Fault-tolerant so execution failures are model-visible tool results.
            $toolbox = new FaultTolerantToolbox($isolated);
        }

        $invocationInput = new ModelInvocationInput(
            runId: $request->sessionId,
            stepId: $stepId,
        );
        $cancelToken = new NullCancellationToken();
        $resolutionValues = [];
        if (null !== $request->thinkingLevel) {
            $resolutionValues['thinking_level'] = $request->thinkingLevel;
        }
        $resolvedModel = $this->modelResolver->resolve(
            $request->model,
            [] !== $messages->withoutSystemMessage()->getMessages(),
            $invocationInput,
            new ModelResolutionOptions($resolutionValues),
        );

        $platform = $this->platform;
        if (null !== $request->maxDurationSeconds) {
            $providerId = AiModelReference::parse($request->model)->providerId;
            $platform = $this->platformFactory->createPlatformForProvider(
                $providerId,
                $request->maxDurationSeconds,
            );
        }

        $preparedPlatform = new PreparedInvocationPlatform(
            $platform,
            $this->providerRequestPreparer,
            $resolvedModel,
            $invocationInput,
            $cancelToken,
        );

        $agent = new Agent(
            platform: $preparedPlatform,
            model: $resolvedModel->model,
            name: 'extension-agent',
            toolbox: $toolbox,
            maxToolCalls: $request->maxToolCalls ?? 50,
        );

        $options = ['stream' => true];
        if ('off' === $request->thinkingLevel) {
            // Explicit per-call off only (Dropper). Do not infer from session/default
            // reasoning=off, and do not invent provider engines from ids.
            $options = array_replace($options, $this->explicitThinkingOffProviderOptions($request->model));
        }

        $this->logger->info('extension.agent.run.started', [
            'component' => 'extension_agent_runner',
            'event_type' => 'extension.agent.run.started',
            'run_id' => $request->sessionId,
            'session_id' => $request->sessionId,
            'correlation_id' => $request->correlationId,
            'model' => $request->model,
            'tool_count' => \count($request->tools),
            'step_id' => $stepId,
            'max_duration_seconds' => $request->maxDurationSeconds,
            'thinking_level' => $request->thinkingLevel,
        ]);

        try {
            $agent->call($messages, $options)->getResult();
        } catch (\Throwable $e) {
            $this->logger->error('extension.agent.run.failed', [
                'component' => 'extension_agent_runner',
                'event_type' => 'extension.agent.run.failed',
                'run_id' => $request->sessionId,
                'session_id' => $request->sessionId,
                'correlation_id' => $request->correlationId,
                'model' => $request->model,
                'step_id' => $stepId,
                // Privacy: log exception class only; message may contain prompts/tool output.
                'exception_class' => $e::class,
            ]);

            throw $e;
        }

        $this->logger->info('extension.agent.run.completed', [
            'component' => 'extension_agent_runner',
            'event_type' => 'extension.agent.run.completed',
            'run_id' => $request->sessionId,
            'session_id' => $request->sessionId,
            'correlation_id' => $request->correlationId,
            'model' => $request->model,
            'step_id' => $stepId,
        ]);
    }

    private function buildStepId(AgentCallRequestDTO $request): string
    {
        $correlation = $request->correlationId ?? '';

        return 'ext-agent:'.$request->sessionId.':'.('' !== $correlation ? $correlation : bin2hex(random_bytes(8)));
    }

    /**
     * Provider options for an explicit AgentCallRequestDTO thinkingLevel=off.
     *
     * z.ai disable already flows through SessionAwareModelResolver + ReasoningOptionsFeatureShaper.
     * llama.cpp requires an explicit catalog thinking_format=llama_cpp; without it this returns [].
     *
     * @return array<string, mixed>
     */
    private function explicitThinkingOffProviderOptions(string $exactModel): array
    {
        if (null === $this->modelCatalog) {
            return [];
        }

        $ref = AiModelReference::parse($exactModel);

        $model = $this->modelCatalog->getModel($ref);
        if (null === $model || !$model->reasoning) {
            return [];
        }

        $thinkingFormat = $model->compatibility?->thinkingFormat;
        if (null === $thinkingFormat) {
            $thinkingFormat = $this->modelCatalog->getProvider($ref->providerId)?->compatibility?->thinkingFormat;
        }
        if ('llama_cpp' === $thinkingFormat) {
            // Disables the reasoning phase for chat-template models; not merely hidden.
            return ['chat_template_kwargs' => ['enable_thinking' => false]];
        }

        return [];
    }
}
