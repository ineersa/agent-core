<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Agent;

use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\PlatformInvocationMetadata;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentCallRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentRunnerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\AgentProcessor;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\StreamResult;

/**
 * Internal Hatfield runner for the public ExtensionApi agent capability.
 *
 * Reuses the configured Symfony AI Platform, standard Agent + AgentProcessor
 * tool loop, and Hatfield routing metadata. Publicly blocking; streams
 * internally so Codex WebSocket and HTTP streaming providers complete.
 */
final readonly class ConfiguredModelAgentRunner implements AgentRunnerInterface
{
    public function __construct(
        private PlatformInterface $platform,
        private LoggerInterface $logger,
    ) {
    }

    public function run(AgentCallRequestDTO $request): void
    {
        $stepId = $this->buildStepId($request);
        $messages = new MessageBag(
            Message::forSystem($request->instructions),
            Message::ofUser($request->input),
        );

        $inputProcessors = [];
        $outputProcessors = [];
        if ([] !== $request->tools) {
            $processor = new AgentProcessor(new IsolatedAgentToolbox(array_values($request->tools)));
            $inputProcessors[] = $processor;
            $outputProcessors[] = $processor;
        }

        $agent = new Agent(
            platform: $this->platform,
            model: $request->model,
            inputProcessors: $inputProcessors,
            outputProcessors: $outputProcessors,
            name: 'extension-agent',
        );

        $options = PlatformInvocationMetadata::inject(
            ['stream' => true],
            new PlatformInvocationMetadata(
                new ModelInvocationInput(
                    runId: $request->sessionId,
                    stepId: $stepId,
                ),
                new NullCancellationToken(),
            ),
        );

        $this->logger->info('extension.agent.run.started', [
            'component' => 'extension_agent_runner',
            'event_type' => 'extension.agent.run.started',
            'run_id' => $request->sessionId,
            'session_id' => $request->sessionId,
            'correlation_id' => $request->correlationId,
            'model' => $request->model,
            'tool_count' => \count($request->tools),
            'step_id' => $stepId,
        ]);

        try {
            $result = $agent->call($messages, $options);
            $this->drainResult($result);
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

    private function drainResult(ResultInterface $result): void
    {
        if ($result instanceof StreamResult) {
            // Fully consume the generator so SSE/WebSocket transports complete
            // and AgentProcessor stream tool-call listeners execute.
            foreach ($result->getContent() as $_) {
            }

            return;
        }

        // Non-stream results still need content materialization for converters.
        $result->getContent();
    }
}
