<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Runtime;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class LibrarianConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('librarian');
        $tree->getRootNode()->normalizeKeys(false)->children()
            ->stringNode('url')->info('Full HTTP(S) MCP endpoint; no credentials, query, or fragment.')->isRequired()->cannotBeEmpty()
                ->validate()->ifTrue(static function (string $url): bool {
                    $parts = parse_url($url);

                    return false === $parts || !\in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                        || '' === ($parts['host'] ?? '') || isset($parts['user']) || isset($parts['pass'])
                        || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f]/', $url);
                })->thenInvalid('Expected an absolute HTTP(S) MCP endpoint without credentials, query, or fragment.')->end()
            ->end()
            ->integerNode('timeout_seconds')->info('Bounded connection and tool request timeout in seconds.')->min(1)->max(300)->defaultValue(30)->end()
            ->stringNode('token')->info('Optional bearer token or env:NAME secret reference.')->defaultNull()->end()
        ->end();

        return $tree;
    }
}
