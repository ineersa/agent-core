<?php

declare(strict_types=1);

namespace Ineersa\Tools\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;
use Psr\Log\LoggerInterface;

/**
 * Enforces the AGENTS.md rule that every caught exception is propagated, logged,
 * or turned into a typed refusal/error result.
 *
 * A catch body is handled when, outside nested closures, arrow functions,
 * functions and classes (which run elsewhere), it contains at least one of:
 *  - a throw expression;
 *  - a method call whose receiver type is Psr\Log\LoggerInterface;
 *  - a call (method, static, function or `new`) with an argument that
 *    references the caught variable, e.g. `$this->fail($e)` or
 *    `notify($e->getMessage())`;
 *  - a return whose expression is object-typed and references the caught variable.
 *
 * Presence anywhere in the body is enough; the rule is not path-sensitive.
 * Expression types come from the scope at the try statement, so values assigned
 * inside the try or catch body are not resolved.
 *
 * @implements Rule<Catch_>
 */
final class NoSilentCatchRule implements Rule
{
    public const string IDENTIFIER = 'hatfield.silentCatch';
    private const string MESSAGE = 'Catch block must rethrow, log through Psr\Log\LoggerInterface, pass the caught exception to a call, or return a typed result built from it.';

    public function getNodeType(): string
    {
        return Catch_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $caughtVariable = null !== $node->var && \is_string($node->var->name) ? $node->var->name : null;
        foreach ($this->ownNodes($node->stmts) as $inner) {
            if ($inner instanceof Throw_
                || $this->isLoggerCall($inner, $scope)
                || $this->passesCaughtVariable($inner, $caughtVariable)
                || ($inner instanceof Return_ && $this->isTypedFailureResult($inner, $caughtVariable, $scope))) {
                return [];
            }
        }

        return [
            RuleErrorBuilder::message(self::MESSAGE)
                ->identifier(self::IDENTIFIER)
                ->build(),
        ];
    }

    /**
     * @param array<Node> $nodes
     *
     * @return list<Node>
     */
    private function ownNodes(array $nodes): array
    {
        $collector = new class extends NodeVisitorAbstract {
            /** @var list<Node> */
            public array $nodes = [];

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Closure || $node instanceof ArrowFunction || $node instanceof Function_ || $node instanceof ClassLike) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }
                $this->nodes[] = $node;

                return null;
            }
        };
        (new NodeTraverser($collector))->traverse($nodes);

        return $collector->nodes;
    }

    private function isLoggerCall(Node $node, Scope $scope): bool
    {
        if (!$node instanceof MethodCall && !$node instanceof NullsafeMethodCall) {
            return false;
        }
        $receiverType = TypeCombinator::removeNull($scope->getType($node->var));

        return (new ObjectType(LoggerInterface::class))->isSuperTypeOf($receiverType)->yes();
    }

    private function passesCaughtVariable(Node $node, ?string $caughtVariable): bool
    {
        if (null === $caughtVariable || !$node instanceof CallLike || $node->isFirstClassCallable()) {
            return false;
        }

        return $this->mentionsVariable($node->getArgs(), $caughtVariable);
    }

    private function isTypedFailureResult(Return_ $return, ?string $caughtVariable, Scope $scope): bool
    {
        if (null === $return->expr || null === $caughtVariable || !$scope->getType($return->expr)->isObject()->yes()) {
            return false;
        }

        return $this->mentionsVariable([$return->expr], $caughtVariable);
    }

    /**
     * @param array<Node> $nodes
     */
    private function mentionsVariable(array $nodes, string $name): bool
    {
        foreach ($this->ownNodes($nodes) as $node) {
            if ($node instanceof Variable && $node->name === $name) {
                return true;
            }
        }

        return false;
    }
}
