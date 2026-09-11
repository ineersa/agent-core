<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Arguments;

use Ineersa\CodingAgent\Tool\Validation\Settings\SettingsPath;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Validated arguments for the settings tool.
 *
 * Provider-visible schema is the explicit flat object on
 * {@see \Ineersa\CodingAgent\Tool\SettingsTool::definition()} (typed handler +
 * explicit schema). This DTO owns input constraints only.
 *
 * `value` is an uninitialized public property: Symfony Serializer leaves it
 * untouched when the key is omitted and assigns (including null) when present.
 * {@see hasValue()} uses ReflectionProperty::isInitialized so any legal JSON
 * string remains a real provided value.
 */
final class SettingsArgumentsDTO
{
    /**
     * Open JSON value for set. Remains uninitialized when omitted; explicit
     * null is a provided value. Objects arrive as associative arrays.
     *
     * @var string|int|float|bool|array<mixed>|null
     */
    public string|int|float|bool|array|null $value;

    public function __construct(
        #[Assert\Choice(choices: ['read', 'set', 'remove'], message: 'The "operation" argument must be one of: read, set, remove.')]
        public readonly string $operation = '',
        #[SettingsPath]
        public readonly string $path = '',
        #[Assert\When(
            expression: 'this.operation === "read" and this.scope !== null and this.scope !== ""',
            constraints: [
                new Assert\Choice(choices: ['effective', 'user', 'project'], message: 'The "scope" argument for read must be one of: effective, user, project.'),
            ],
        )]
        #[Assert\When(
            expression: 'this.operation === "set" || this.operation === "remove"',
            constraints: [
                new Assert\NotBlank(normalizer: 'trim', message: 'set/remove require explicit scope "user" or "project".'),
                new Assert\Choice(choices: ['user', 'project'], message: 'Invalid mutation scope "{{ value }}"; must be user or project.'),
            ],
        )]
        public readonly ?string $scope = null,
    ) {
    }

    public function hasValue(): bool
    {
        return (new \ReflectionProperty($this, 'value'))->isInitialized($this);
    }

    #[Assert\Callback]
    public function validateValuePresence(ExecutionContextInterface $context): void
    {
        if ('set' !== $this->operation || $this->hasValue()) {
            return;
        }

        $context->buildViolation('The "value" argument is required for set (use explicit null to set null).')
            ->atPath('value')
            ->addViolation();
    }
}
