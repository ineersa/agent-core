<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\SafeGuard;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class SafeGuardConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('safe_guard');
        $root = $tree->getRootNode()->ignoreExtraKeys(false)->normalizeKeys(false);
        $root->children()->booleanNode('auto_deny_in_noninteractive')->info('Deny approval-required actions without an approval channel.')->defaultTrue()->end()->end();
        foreach (['allow_command_patterns', 'protected_read_patterns', 'dangerous_command_patterns'] as $name) {
            $root->children()->arrayNode($name)->info('Literal patterns, preserved exactly.')->normalizeKeys(false)
                ->stringPrototype()->cannotBeEmpty()->end()->end()->end();
        }
        $root->children()->arrayNode('allow_write_outside_cwd')->info('Absolute paths where outside-project writes are allowed.')->normalizeKeys(false)
            ->stringPrototype()->cannotBeEmpty()->validate()
                ->ifTrue(static fn (string $path): bool => !str_starts_with($path, '/'))
                ->thenInvalid('Expected an absolute path, got %s.')
            ->end()->end()->end()->end();

        return $tree;
    }
}
