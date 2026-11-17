<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Runtime;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class ObservationalMemoryConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder(OmSettings::SETTINGS_KEY);
        $tree->getRootNode()->normalizeKeys(false);
        $librarian = (new LibrarianConfiguration())->getConfigTreeBuilder()->getRootNode();
        $librarian->isRequired();
        $tree->getRootNode()->append($librarian);

        return $tree;
    }
}
