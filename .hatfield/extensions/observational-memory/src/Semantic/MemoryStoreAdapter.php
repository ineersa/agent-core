<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Semantic;

use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Query\QueryInterface;
use Symfony\AI\Store\Query\TextQuery;
use Symfony\AI\Store\StoreInterface;

/** Adapts vendor option names and FTS tokenization without implementing ranking. */
final class MemoryStoreAdapter implements StoreInterface
{
    private bool $truncated = false;

    /** @param (\Closure(VectorDocument): bool)|null $filter */
    public function __construct(private readonly StoreInterface $store, private readonly bool $text, private readonly int $chunkCount, private readonly ?\Closure $filter = null)
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
        $this->truncated = false;
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
        // Vektor has no metadata filter. Bound overfetch independently of corpus
        // size; report an exhausted budget even when few dated hits survive.
        $fetch = min(max(1, $this->chunkCount), null === $this->filter ? $limit : 500);
        $count = 0;
        $seen = 0;
        foreach ($this->store->query($query, [$this->text ? 'maxItems' : 'k' => $fetch]) as $document) {
            ++$seen;
            $this->truncated = $seen >= $fetch && $this->chunkCount > $seen;
            if (null !== $this->filter && !($this->filter)($document)) {
                continue;
            }
            yield $document;
            if (++$count >= $limit) {
                $this->truncated = $this->truncated || $this->chunkCount > $seen;
                break;
            }
        }
    }

    public function wasTruncated(): bool
    {
        return $this->truncated;
    }

    public function supports(string $queryClass): bool
    {
        return $this->store->supports($queryClass);
    }
}
