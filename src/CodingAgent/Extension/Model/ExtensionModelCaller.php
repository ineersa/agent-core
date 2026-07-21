<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Model;

use Ineersa\Hatfield\ExtensionApi\Model\AiModelReference;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\AgentProcessor;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ResultInterface;

/**
 * Thin ExtensionApi model bridge over Hatfield's configured Symfony AI Platform.
 *
 * Creates a standard non-streaming Symfony Agent for the requested model and
 * optionally attaches AgentProcessor so supplied tools execute through the
 * normal Symfony tool loop. Does not rebuild providers, map message arrays, or
 * invent public result/error DTOs.
 *
 * @internal app-layer adapter; not part of ExtensionApi
 */
final readonly class ExtensionModelCaller
{
    public function __construct(
        private PlatformInterface $platform,
        private LoggerInterface $logger,
    ) {
    }

    public function call(
        AiModelReference $model,
        MessageBag $messages,
        ?ToolboxInterface $toolbox = null,
    ): ResultInterface {
        $inputProcessors = [];
        $outputProcessors = [];
        if (null !== $toolbox) {
            $processor = new AgentProcessor($toolbox);
            $inputProcessors[] = $processor;
            $outputProcessors[] = $processor;
        }

        $agent = new Agent(
            platform: $this->platform,
            model: $model->toString(),
            inputProcessors: $inputProcessors,
            outputProcessors: $outputProcessors,
            name: 'extension-call-model',
        );

        try {
            return $agent->call($messages, ['stream' => false]);
        } catch (\Throwable $e) {
            // Privacy-safe local diagnostic only; native Symfony AI exceptions propagate.
            $this->logger->warning('extension.model_call_failed', [
                'component' => 'extension_model_caller',
                'event_type' => 'model_call_failed',
                'model' => $model->toString(),
                'provider' => $model->providerId,
                'has_toolbox' => null !== $toolbox,
                'exception_class' => $e::class,
            ]);

            throw $e;
        }
    }
}
