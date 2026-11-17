<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Runtime;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class ObservationalMemoryConfiguration implements ConfigurationInterface
{
    public const array OBSOLETE_KEYS = ['storage', 'model', 'observer', 'reflector', 'pools'];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder(OmSettings::SETTINGS_KEY);
        $tree->getRootNode()->normalizeKeys(false);
        // Discard only the retired local pipeline settings before child-node
        // validation, regardless of their old shape. Unknown keys remain errors.
        $tree->getRootNode()->beforeNormalization()->ifArray()
            ->then(static fn (array $value): array => array_diff_key($value, array_flip(self::OBSOLETE_KEYS)))
            ->end();
        $librarian = (new LibrarianConfiguration())->getConfigTreeBuilder()->getRootNode();
        $librarian->isRequired();
        $tree->getRootNode()->append($librarian);

        return $tree;
    }
}
