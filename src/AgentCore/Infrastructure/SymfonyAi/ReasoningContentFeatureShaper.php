<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\ProviderCompatibilityFeatureShaperInterface;
use Ineersa\AgentCore\Domain\Model\ProviderRequest;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Thinking;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * Injects empty reasoning/thinking content into assistant messages for
 * providers that require it (DeepSeek).
 *
 * Activated when {@code 'requires_reasoning_content_on_assistant'} is in
 * the compat features array.
 */
final readonly class ReasoningContentFeatureShaper implements ProviderCompatibilityFeatureShaperInterface
{
    private const string FEATURE = 'requires_reasoning_content_on_assistant';

    public function supports(array $compatFeatures): bool
    {
        return \in_array(self::FEATURE, $compatFeatures, true);
    }

    public function shape(
        string $model,
        array $input,
        array $options,
        array $compatFeatures,
    ): ?ProviderRequest {
        if (!isset($input['message_bag'])) {
            return null;
        }

        /** @var MessageBag $bag */
        $bag = $input['message_bag'];
        $messages = $bag->getMessages();
        $modified = false;
        $newMessages = [];

        foreach ($messages as $message) {
            if ($message instanceof AssistantMessage && !$message->hasThinking()) {
                $content = $message->getContent();
                $content[] = new Thinking('', null);

                $replacement = new AssistantMessage(...$content);

                // Preserve metadata from the original message.
                foreach ($message->getMetadata()->all() as $key => $value) {
                    $replacement->getMetadata()->add($key, $value);
                }

                $newMessages[] = $replacement;
                $modified = true;
            } else {
                $newMessages[] = $message;
            }
        }

        if (!$modified) {
            return null;
        }

        return new ProviderRequest(
            input: ['message_bag' => new MessageBag(...$newMessages)],
        );
    }
}
