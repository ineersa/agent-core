<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor;
use Ineersa\CodingAgent\Application\Message\DTO\SettleObsoleteDeferredCompletionDTO;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Messenger ingress for obsolete deferred settlement.
 *
 * Both transported and middleware-hydrated SettleObsoleteDeferredCompletionDTO
 * reach the owner transition through RunMessageProcessor.
 */
final readonly class SettleObsoleteDeferredCompletionCoordinationHandler
{
    public function __construct(
        private ValidatorInterface $validator,
        #[Autowire(lazy: true)] private RunMessageProcessor $processor,
    ) {
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function __invoke(SettleObsoleteDeferredCompletionDTO $message): void
    {
        $violations = $this->validator->validate($message);
        if (0 !== \count($violations)) {
            throw new ValidationFailedException($message, $violations);
        }
        $this->processor->process('settle_obsolete_deferred', $message);
    }
}
