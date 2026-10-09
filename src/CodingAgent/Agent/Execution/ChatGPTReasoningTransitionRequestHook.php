<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Contract\Hook\BeforeProviderRequestHookInterface;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Domain\Model\ProviderRequest;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\ReasoningConfiguration;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\MessageBag;

/** Emits native controls before their durable user/tool anchors, without mutating the retained bag. */
final readonly class ChatGPTReasoningTransitionRequestHook implements BeforeProviderRequestHookInterface
{
    public function __construct(private HatfieldSessionStore $sessions)
    {
    }

    public function beforeProviderRequest(string $model, array $input, array $options, ?CancellationTokenInterface $cancelToken = null): ?ProviderRequest
    {
        $enabled = true === ($options[ChatGPTReasoningTransitionMetadata::ENABLED] ?? false);
        $update = $options[ChatGPTReasoningTransitionMetadata::UPDATE] ?? null;
        $nextOptions = $options;
        unset($nextOptions[ChatGPTReasoningTransitionMetadata::ENABLED], $nextOptions[ChatGPTReasoningTransitionMetadata::UPDATE], $nextOptions['hatfield_run_id'], $nextOptions['hatfield_model_ref']);
        if (!$enabled) {
            return $nextOptions === $options ? null : new ProviderRequest(options: $nextOptions);
        }
        $bag = $input['message_bag'] ?? null;
        if (!$bag instanceof MessageBag) {
            throw new \LogicException('ChatGPT reasoning transitions require a message bag.');
        }
        $messages = $bag->getMessages();
        $runId = $options['hatfield_run_id'] ?? null;
        $modelRef = $options['hatfield_model_ref'] ?? null;
        if (!\is_string($runId) || !\is_string($modelRef)) {
            throw new \LogicException('ChatGPT reasoning transition lacks its run/model identity.');
        }
        $anchor = null;
        if (\is_string($update)) {
            for ($i = \count($messages) - 1; $i >= 0; --$i) {
                $key = $messages[$i]->getMetadata()->get(ChatGPTReasoningTransitionMetadata::MESSAGE_KEY);
                if (\is_string($key) && '' !== $key) {
                    $anchor = $i;
                    $this->sessions->rememberReasoningTransition($runId, $modelRef, $key, $update);
                    break;
                }
            }
            if (null === $anchor) {
                throw new \LogicException('ChatGPT reasoning transition lacks a durable user/tool anchor.');
            }
        }
        $result = [];
        $ledger = array_column($this->sessions->listReasoningTransitions($runId, $modelRef), 'effort', 'message_key');
        $last = null;
        foreach ($messages as $index => $message) {
            $key = $message->getMetadata()->get(ChatGPTReasoningTransitionMetadata::MESSAGE_KEY);
            $effort = \is_string($key) ? ($ledger[$key] ?? null) : null;
            if (\is_string($effort) && $effort !== $last) {
                // Control-only AssistantMessage is a serialization envelope, not fabricated text.
                $result[] = new AssistantMessage(new ReasoningConfiguration($effort));
                $last = $effort;
            }
            $result[] = $message;
        }

        return new ProviderRequest(input: array_replace($input, ['message_bag' => new MessageBag(...$result)]), options: $nextOptions);
    }
}
