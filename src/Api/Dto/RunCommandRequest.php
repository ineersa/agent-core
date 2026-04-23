<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Api\Dto;

use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Utils\StringUtils;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class RunCommandRequest
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     */
    public function __construct(
        #[Assert\NotBlank(message: 'Field "kind" must be a non-empty string.')]
        public ?string $kind {
            set => StringUtils::normalizeNullable($value);
        },
        #[Assert\NotBlank(message: 'Field "idempotency_key" must be a non-empty string.')]
        public ?string $idempotency_key {
            set => StringUtils::normalizeNullable($value);
        },
        public array $payload = [],
        public array $options = [],
    ) {
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if (null !== $this->kind && !CoreCommandKind::isCore($this->kind) && !str_starts_with($this->kind, 'ext:')) {
            $context
                ->buildViolation('Field "kind" must be one of core kinds or start with "ext:".')
                ->atPath('kind')
                ->addViolation()
            ;
        }

        $unknownOptions = $this->options
                |> array_keys(...)
                |> (static fn ($x) => array_diff($x, ['cancel_safe']))
                |> array_values(...);
        if ([] !== $unknownOptions) {
            sort($unknownOptions);

            $context
                ->buildViolation(\sprintf('Unknown command options: %s.', implode(', ', $unknownOptions)))
                ->atPath('options')
                ->addViolation()
            ;
        }

        if (\array_key_exists('cancel_safe', $this->options) && !\is_bool($this->options['cancel_safe'])) {
            $context
                ->buildViolation('Option "cancel_safe" must be a boolean.')
                ->atPath('options[cancel_safe]')
                ->addViolation()
            ;
        }

        if (null !== $this->kind && CoreCommandKind::isCore($this->kind) && \array_key_exists('cancel_safe', $this->options)) {
            $context
                ->buildViolation('Option "cancel_safe" is reserved for extension commands.')
                ->atPath('options[cancel_safe]')
                ->addViolation()
            ;
        }
    }
}
