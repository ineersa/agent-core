<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\Support;

use Ineersa\CodingAgent\Tool\HatfieldToolProviderInterface;
use Ineersa\CodingAgent\Tool\RawAwareToolCallArgumentResolver;
use Ineersa\CodingAgent\Tool\RegistryBackedToolbox;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use Symfony\AI\Agent\Toolbox\Event\ToolCallArgumentsResolved;
use Symfony\AI\Agent\Toolbox\EventListener\ValidateToolCallArgumentsListener;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Agent\Toolbox\ToolCallArgumentResolver;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\ValidatorBuilder;

/**
 * Production-shaped execution path for invalid-argument tests: registry →
 * native ToolCallArgumentResolver → ValidateToolCallArgumentsListener (with
 * the given constraint validator instances) → FaultTolerantToolbox.
 *
 * Mirrors config/services.yaml wiring: the app dispatcher carries the
 * listener, and class-level DTO constraints (e.g. ReadFileTarget) run with
 * the validator instances supplied per test so settings-backed or
 * context-backed validators (ViewImageTargetValidator) receive the same
 * config/context the tool under test uses.
 */
final class ToolValidationHarness
{
    /**
     * @param array<class-string, ConstraintValidator> $constraintValidators
     *                                                                       constraint validator class => pre-constructed instance; omit for
     *                                                                       validators whose constructor is dependency-free
     */
    public static function toolbox(
        HatfieldToolProviderInterface $provider,
        array $constraintValidators = [],
    ): FaultTolerantToolbox {
        $validator = (new ValidatorBuilder())
            ->enableAttributeMapping()
            ->setConstraintValidatorFactory(new ConstraintValidatorFactory($constraintValidators))
            ->getValidator();

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ToolCallArgumentsResolved::class, new ValidateToolCallArgumentsListener($validator));

        return new FaultTolerantToolbox(new RegistryBackedToolbox(
            registry: new ToolRegistry([$provider]),
            argumentResolver: new RawAwareToolCallArgumentResolver(new ToolCallArgumentResolver()),
            nativeToolFactory: NativeToolSchemaProbe::nativeToolFactory(),
            eventDispatcher: $dispatcher,
        ));
    }
}
