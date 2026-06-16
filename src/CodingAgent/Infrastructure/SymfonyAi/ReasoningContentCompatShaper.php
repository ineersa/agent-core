<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\ProviderCompatibilityFeatureShaperInterface;
use Ineersa\AgentCore\Contract\ProviderCompatibilityOptionEnum;
use Ineersa\AgentCore\Domain\Model\ProviderCompatibility;
use Ineersa\AgentCore\Domain\Model\ProviderRequest;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Thinking;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * Injects empty reasoning_content into assistant messages for providers
 * that require it (DeepSeek).
 *
 * Activated by {@see ProviderCompatibilityOptionEnum::REQUIRES_REASONING_CONTENT_ON_ASSISTANT}
 * in the resolved {@see ProviderCompatibility}. Runs during the final
 * compat-normalization step — no private request-option marker needed.
 */
final readonly class ReasoningContentCompatShaper implements ProviderCompatibilityFeatureShaperInterface
{
    public function supports(ProviderCompatibility $compat): bool
    {
        return $compat->has(ProviderCompatibilityOptionEnum::REQUIRES_REASONING_CONTENT_ON_ASSISTANT);
    }

    public function shape(
        string $model,
        array $input,
        array $options,
        ProviderCompatibility $compat,
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
