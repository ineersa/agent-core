<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Command\RoutedCommand;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;

final class CommandRouter
{
    public function __construct(
        private readonly CommandHandlerRegistry $registry,
        private readonly string $extensionPrefix = 'ext:',
    ) {
    }

    public function route(ApplyCommand $command): RoutedCommand
    {
        if (CoreCommandKind::isCore($command->kind)) {
            return RoutedCommand::core($command->kind, $command->payload, $command->options);
        }

        if (!str_starts_with($command->kind, $this->extensionPrefix)) {
            return RoutedCommand::rejected(
                $command->kind,
                \sprintf('Unknown command kind "%s". Extension commands must use "%s" prefix.', $command->kind, $this->extensionPrefix),
            );
        }

        $optionValidationError = $this->validateExtensionOptions($command);
        if (null !== $optionValidationError) {
            return RoutedCommand::rejected($command->kind, $optionValidationError);
        }

        $handler = $this->registry->find($command->kind);
        if (null === $handler) {
            return RoutedCommand::rejected($command->kind, \sprintf('No extension command handler registered for "%s".', $command->kind));
        }

        $cancelSafeRequested = (bool) ($command->options['cancel_safe'] ?? false);
        if ($cancelSafeRequested && !$handler->supportsCancelSafe($command->kind)) {
            return RoutedCommand::rejected($command->kind, \sprintf('Extension command "%s" does not allow cancel_safe=true.', $command->kind));
        }

        return RoutedCommand::extension($command->kind, $command->payload, $command->options);
    }

    private function validateExtensionOptions(ApplyCommand $command): ?string
    {
        $allowedOptionKeys = ['cancel_safe'];
        $unknownOptionKeys = array_values(array_diff(array_keys($command->options), $allowedOptionKeys));

        if ([] !== $unknownOptionKeys) {
            sort($unknownOptionKeys);

            return \sprintf(
                'Unknown extension command options for "%s": %s. Allowed options: %s.',
                $command->kind,
                implode(', ', $unknownOptionKeys),
                implode(', ', $allowedOptionKeys),
            );
        }

        if (\array_key_exists('cancel_safe', $command->options) && !\is_bool($command->options['cancel_safe'])) {
            return \sprintf(
                'Option "cancel_safe" for "%s" must be boolean, got %s.',
                $command->kind,
                get_debug_type($command->options['cancel_safe']),
            );
        }

        return null;
    }
}
