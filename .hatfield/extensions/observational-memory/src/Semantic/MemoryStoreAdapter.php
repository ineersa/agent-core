<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Semantic;

use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Query\QueryInterface;
use Symfony\AI\Store\Query\TextQuery;
use Symfony\AI\Store\StoreInterface;

/** Adapts vendor option names and FTS tokenization without implementing ranking. */
final readonly class MemoryStoreAdapter implements StoreInterface
{
    /** @param (\Closure(VectorDocument): bool)|null $filter */
    public function __construct(private StoreInterface $store, private bool $text, private int $chunkCount, private ?\Closure $filter = null)
    {
    }

    /** @param VectorDocument|array<VectorDocument> $documents */
    public function add(VectorDocument|array $documents): void
    {
        $documents = $documents instanceof VectorDocument ? [$documents] : $documents;
        if ($this->text) {
            $documents = array_map(static fn (VectorDocument $document): VectorDocument => new VectorDocument($document->getId(), new Vector([1.0]), $document->getMetadata()), $documents);
        }
        $this->store->add($documents);
    }

    /** @param string|array<string> $ids
     * @param array<string, mixed> $options
     */
    public function remove(array|string $ids, array $options = []): void
    {
        $this->store->remove($ids, $options);
    }

    /** @param array<string, mixed> $options */
    public function clear(array $options = []): void
    {
        $this->store->clear($options);
    }

    /** @param array<string, mixed> $options
     * @return iterable<VectorDocument>
     */
    public function query(QueryInterface $query, array $options = []): iterable
    {
        if ($this->text && $query instanceof TextQuery) {
            // CombinedStore flattens text arrays. Re-tokenize here; only letters
            // and numbers enter SQLite's quoted FTS terms, never user syntax.
            preg_match_all('/[\p{L}\p{N}]+/u', implode(' ', $query->getTexts()), $matches);
            if ([] === $matches[0]) {
                return;
            }
            $query = new TextQuery(array_values(array_unique($matches[0])));
        }
        $limit = (int) ($options['maxItems'] ?? 100);
        // Apply dates before candidate truncation. Vektor has no native metadata
        // filter, so constrained queries must request the full graph candidate set.
        $fetch = null === $this->filter ? $limit : max(1, $this->chunkCount);
        $count = 0;
        foreach ($this->store->query($query, [$this->text ? 'maxItems' : 'k' => $fetch]) as $document) {
            if (null !== $this->filter && !($this->filter)($document)) {
                continue;
            }
            yield $document;
            if (++$count >= $limit) {
                break;
            }
        }
    }

    public function supports(string $queryClass): bool
    {
        return $this->store->supports($queryClass);
    }
}
