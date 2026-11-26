<?php

declare(strict_types=1);

namespace Ineersa\Tools\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\UnionType;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids optional service collaborators: a constructor parameter typed as a
 * nullable class/interface with a null default, in a class under $sourceDirectory.
 *
 * Classes in a DTO namespace segment or with a DTO short-name suffix are data,
 * not services, and are skipped. Scalars, arrays, callables, Closure, enums,
 * Throwable (the `$previous` pattern) and DateTimeInterface values are not
 * collaborators.
 *
 * @implements Rule<ClassMethod>
 */
final readonly class NoNullableCollaboratorRule implements Rule
{
    public const string IDENTIFIER = 'hatfield.nullableCollaborator';

    public function __construct(
        private string $sourceDirectory,
        private ReflectionProvider $reflectionProvider,
    ) {
    }

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $class = $scope->getClassReflection();
        if ('__construct' !== $node->name->toLowerString()
            || null === $class
            || !str_starts_with($scope->getFile(), rtrim($this->sourceDirectory, '/').'/')
            || str_ends_with($class->getName(), 'DTO')
            || \in_array('DTO', explode('\\', $class->getName()), true)) {
            return [];
        }

        $errors = [];
        foreach ($node->params as $param) {
            if (!$this->isOptionalCollaborator($param) || !$param->var instanceof Variable || !\is_string($param->var->name)) {
                continue;
            }
            $errors[] = RuleErrorBuilder::message(\sprintf(
                'Constructor parameter $%s of %s is a nullable collaborator with a null default; inject a required service or a null object instead.',
                $param->var->name,
                $class->getDisplayName(),
            ))
                ->identifier(self::IDENTIFIER)
                ->line($param->getStartLine())
                ->build();
        }

        return $errors;
    }

    private function isOptionalCollaborator(Param $param): bool
    {
        if (!$param->default instanceof ConstFetch || 'null' !== $param->default->name->toLowerString()) {
            return false;
        }

        $type = $param->type;
        if ($type instanceof NullableType) {
            return $this->isCollaboratorType($type->type);
        }
        if (!$type instanceof UnionType) {
            return false;
        }

        $nullable = false;
        $hasClass = false;
        foreach ($type->types as $member) {
            $nullable = $nullable || ($member instanceof Identifier && 'null' === $member->toLowerString());
            $hasClass = $hasClass || $this->isCollaboratorType($member);
        }

        return $nullable && $hasClass;
    }

    private function isCollaboratorType(Identifier|Name|ComplexType $type): bool
    {
        if ($type instanceof IntersectionType) {
            return true;
        }

        if (!$type instanceof Name || 'closure' === strtolower($type->toString())) {
            return false;
        }
        if (!$this->reflectionProvider->hasClass($type->toString())) {
            return true;
        }
        $class = $this->reflectionProvider->getClass($type->toString());

        return !$class->isEnum() && !$this->isOrImplements($class, \Throwable::class) && !$this->isOrImplements($class, \DateTimeInterface::class);
    }

    private function isOrImplements(ClassReflection $class, string $interface): bool
    {
        return $class->getName() === $interface || $class->implementsInterface($interface);
    }
}
