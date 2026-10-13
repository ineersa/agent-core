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
        $anchorKey = null;
        // Validate the candidate even when it would deduplicate an existing control.
        $candidate = \is_string($update) ? new ReasoningConfiguration($update) : null;
        if (\is_string($update)) {
            for ($i = \count($messages) - 1; $i >= 0; --$i) {
                $key = $messages[$i]->getMetadata()->get(ChatGPTReasoningTransitionMetadata::MESSAGE_KEY);
                if (\is_string($key) && '' !== $key) {
                    $anchorKey = $key;
                    break;
                }
            }
            if (null === $anchorKey) {
                throw new \LogicException('ChatGPT reasoning transition lacks a durable user/tool anchor.');
            }
        }
        $result = [];
        $ledger = array_column($this->sessions->listReasoningTransitions($runId, $modelRef), 'effort', 'message_key');
        if (null !== $anchorKey && null !== $candidate) {
            $ledger[$anchorKey] = $candidate->effort;
        }
        $last = null;
        foreach ($messages as $message) {
            $key = $message->getMetadata()->get(ChatGPTReasoningTransitionMetadata::MESSAGE_KEY);
            $effort = \is_string($key) ? ($ledger[$key] ?? null) : null;
            if (\is_string($effort) && $effort !== $last) {
                // Control-only AssistantMessage is a serialization envelope, not fabricated text.
                $result[] = new AssistantMessage(new ReasoningConfiguration($effort));
                $last = $effort;
            }
            $result[] = $message;
        }

        $request = new ProviderRequest(input: array_replace($input, ['message_bag' => new MessageBag(...$result)]), options: $nextOptions);
        // All historical envelopes and the complete bag must be constructible before
        // advancing durable state. A valid candidate cannot hide an invalid old control.
        if (null !== $anchorKey && null !== $candidate) {
            $this->sessions->rememberReasoningTransition($runId, $modelRef, $anchorKey, $candidate->effort);
        }

        return $request;
    }
}
