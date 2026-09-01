<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Extension\CommandHandlerInterface;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Command\RoutedCommand;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;

final class CommandRouter
{
    /** @var iterable<CommandHandlerInterface> */
    private readonly iterable $handlers;

    /**
     * @param iterable<CommandHandlerInterface> $handlers
     */
    public function __construct(
        iterable $handlers = [],
        private readonly string $extensionPrefix = 'ext:',
    ) {
        $this->handlers = $handlers;
    }

    public function route(ApplyCommand $command): RoutedCommand
    {
        $optionValidationError = $this->validateOptionKeys($command);
        if (null !== $optionValidationError) {
            return RoutedCommand::rejected($optionValidationError);
        }

        if (CoreCommandKind::isCore($command->kind)) {
            if (\array_key_exists('cancel_safe', $command->options)) {
                return RoutedCommand::rejected(
                    \sprintf('Option "cancel_safe" is reserved for extension commands and cannot be used for core command "%s".', $command->kind),
                );
            }

            return RoutedCommand::core($command->payload, []);
        }

        if (!str_starts_with($command->kind, $this->extensionPrefix)) {
            return RoutedCommand::rejected(
                \sprintf('Unknown command kind "%s". Extension commands must use "%s" prefix.', $command->kind, $this->extensionPrefix),
            );
        }

        $handler = $this->findHandler($command->kind);
        if (null === $handler) {
            return RoutedCommand::rejected(\sprintf('No extension command handler registered for "%s".', $command->kind));
        }

        $normalizedOptions = [
            'cancel_safe' => true === ($command->options['cancel_safe'] ?? false),
        ];

        if ($normalizedOptions['cancel_safe'] && !$handler->supportsCancelSafe($command->kind)) {
            return RoutedCommand::rejected(\sprintf('Extension command "%s" does not allow cancel_safe=true.', $command->kind));
        }

        return RoutedCommand::extension($command->payload, $normalizedOptions);
    }

    public function handlerFor(string $kind): ?CommandHandlerInterface
    {
        return $this->findHandler($kind);
    }

    private function findHandler(string $kind): ?CommandHandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($kind)) {
                return $handler;
            }
        }

        return null;
    }

    private function validateOptionKeys(ApplyCommand $command): ?string
    {
        $allowedOptionKeys = ['cancel_safe'];
        $unknownOptionKeys = array_values(array_diff(array_keys($command->options), $allowedOptionKeys));

        if ([] === $unknownOptionKeys) {
            return null;
        }

        sort($unknownOptionKeys);

        return \sprintf(
            'Unknown command options for "%s": %s. Allowed options: %s.',
            $command->kind,
            implode(', ', $unknownOptionKeys),
            implode(', ', $allowedOptionKeys),
        );
    }
}
