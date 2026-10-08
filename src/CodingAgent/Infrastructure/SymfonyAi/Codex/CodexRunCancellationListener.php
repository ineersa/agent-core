<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi\Codex;

use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmInvocationCancelScope;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketModelClient;
use Symfony\AI\Platform\Event\InvocationEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class CodexRunCancellationListener
{
    #[AsEventListener]
    public function __invoke(InvocationEvent $event): void
    {
        $token = LlmInvocationCancelScope::current();
        if (!$event->getModel() instanceof CodexModel || null === $token) {
            return;
        }

        $options = $event->getOptions();
        $options[CodexWebSocketModelClient::CANCELLATION] = new CodexRunCancellation($token);
        $event->setOptions($options);
    }
}
