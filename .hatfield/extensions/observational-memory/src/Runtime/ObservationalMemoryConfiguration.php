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
        $tree->getRootNode()->ignoreExtraKeys(false)->normalizeKeys(false)->children()
            ->stringNode('model')->info('Shared generation model, as provider/model.')->isRequired()->cannotBeEmpty()
                ->attribute('hatfield.model_picker', true)
                ->validate()->ifTrue(static fn (string $model): bool => 1 !== preg_match('#^(?!@)[^/]+/.+$#', $model))
                    ->thenInvalid('Expected an exact provider/model reference, got %s.')->end()
            ->end()
            ->arrayNode('storage')->ignoreExtraKeys(false)->normalizeKeys(false)->addDefaultsIfNotSet()->children()
                ->stringNode('database')->info('SQLite database path, relative to the project or absolute.')->cannotBeEmpty()->defaultValue(OmSettings::DEFAULT_RELATIVE_DB_PATH)->end()
            ->end()->end()
            ->arrayNode('observer')->ignoreExtraKeys(false)->normalizeKeys(false)->addDefaultsIfNotSet()->children()
                ->floatNode('context_window_ratio')->info('Context fraction that triggers observation (strictly between 0 and 1).')->defaultValue(OmSettings::DEFAULT_CONTEXT_WINDOW_RATIO)
                    ->validate()->ifTrue(static fn (float $ratio): bool => !is_finite($ratio) || $ratio <= 0 || $ratio >= 1)
                        ->thenInvalid('Expected a finite ratio strictly between 0 and 1.')->end()
                ->end()
            ->end()->end()
            ->arrayNode('reflector')->ignoreExtraKeys(false)->normalizeKeys(false)->addDefaultsIfNotSet()->children()
                ->integerNode('reflect_after_observation_tokens')->info('Observation-token threshold for reflection.')->min(1)->defaultValue(OmSettings::DEFAULT_REFLECT_AFTER_OBSERVATION_TOKENS)->end()
            ->end()->end()
            ->arrayNode('pools')->ignoreExtraKeys(false)->normalizeKeys(false)->addDefaultsIfNotSet()->children()
                ->integerNode('observations_max_tokens')->info('Maximum tokens in the observations pool.')->min(1)->defaultValue(OmSettings::DEFAULT_OBSERVATIONS_MAX_TOKENS)->end()
            ->end()->end()

        ->end();

        $librarian = (new LibrarianConfiguration())->getConfigTreeBuilder()->getRootNode();
        $librarian->beforeNormalization()->ifNull()->thenUnset()->end();
        $tree->getRootNode()->append($librarian);

        return $tree;
    }
}
