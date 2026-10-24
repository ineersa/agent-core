<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Runtime;

use Ineersa\HatfieldExt\ObservationalMemory\Semantic\SemanticSettings;
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
            ->arrayNode('semantic')->info('Optional semantic search; clear this block to disable it.')->ignoreExtraKeys(false)->normalizeKeys(false)
                ->beforeNormalization()->ifTrue(static fn (mixed $value): bool => null === $value || [] === $value)->thenUnset()->end()
                ->children()
                    ->arrayNode('embedding_api')->isRequired()->ignoreExtraKeys(false)->normalizeKeys(false)->children()
                        ->stringNode('base_url')->info('Embedding HTTP(S) endpoint, without credentials, query, or fragment.')->isRequired()->cannotBeEmpty()->end()
                        ->stringNode('model_id')->info('Embedding model ID served by this endpoint.')->isRequired()->cannotBeEmpty()->end()
                        ->stringNode('query_prefix')->info('Prefix added to embedding queries.')->defaultValue('')->end()
                        ->integerNode('chunk_bytes')->info('Embedding chunk size in bytes.')->min(4)->defaultValue(SemanticSettings::DEFAULT_CHUNK_BYTES)->end()
                        ->integerNode('overlap_bytes')->info('Overlap in bytes, smaller than the chunk size.')->min(0)->defaultValue(SemanticSettings::DEFAULT_OVERLAP_BYTES)->end()
                        ->integerNode('max_lines')->info('Maximum lines per embedding chunk.')->min(1)->defaultValue(SemanticSettings::DEFAULT_MAX_LINES)->end()
                        ->integerNode('batch_size')->info('Embedding batch size.')->min(1)->defaultValue(SemanticSettings::DEFAULT_EMBEDDING_BATCH_SIZE)->end()
                    ->end()->end()
                    ->arrayNode('reranker_api')->info('Optional reranker; clear this block to disable it.')->ignoreExtraKeys(false)->normalizeKeys(false)
                        ->beforeNormalization()->ifNull()->thenUnset()->end()
                        ->children()
                            ->stringNode('base_url')->info('Reranker HTTP(S) endpoint, without credentials, query, or fragment.')->isRequired()->cannotBeEmpty()->end()
                            ->stringNode('model_id')->info('Reranker model ID served by this endpoint.')->isRequired()->cannotBeEmpty()->end()
                            ->integerNode('batch_size')->info('Reranker batch size.')->min(1)->defaultValue(SemanticSettings::DEFAULT_RERANKER_BATCH_SIZE)->end()
                            ->integerNode('document_characters')->info('Maximum document characters sent to the reranker.')->min(1)->defaultValue(SemanticSettings::DEFAULT_DOCUMENT_CHARACTERS)->end()
                            ->floatNode('min_score')->info('Optional finite minimum score; omit to use any inherited score.')->end()
                        ->end()
                    ->end()
                ->end()
                // Reuse the runtime authority for URLs, finite scores, and chunk/overlap relationships.
                ->validate()->always(static function (array $value): array {
                    SemanticSettings::fromArray($value);

                    return $value;
                })->end()
            ->end()
        ->end();

        return $tree;
    }
}
