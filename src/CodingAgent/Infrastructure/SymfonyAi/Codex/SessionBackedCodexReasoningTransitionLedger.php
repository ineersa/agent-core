<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi\Codex;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexReasoningTransitionLedger;

/**
 * Persists Astra reasoning transitions inside hatfield_session.reasoning_baseline.
 *
 * Uses the immutable provider_cache_key as the ledger family so worker recreation
 * and full-history/SSE rebuilds keep historical configuration_update offsets.
 */
final class SessionBackedCodexReasoningTransitionLedger extends CodexReasoningTransitionLedger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function transitions(string $promptCacheKey): array
    {
        $entity = $this->findSession($promptCacheKey);
        if (null === $entity || !\is_array($entity->reasoningBaseline)) {
            return [];
        }

        $stored = $entity->reasoningBaseline['transitions'] ?? null;
        if (!\is_array($stored)) {
            return [];
        }

        $transitions = [];
        foreach ($stored as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $after = $item['after'] ?? null;
            $effort = $item['effort'] ?? null;
            if (\is_int($after) && \is_string($effort) && '' !== $effort) {
                $transitions[] = ['after' => $after, 'effort' => $effort];
            }
        }

        return $transitions;
    }

    public function remember(string $promptCacheKey, int $after, string $effort): void
    {
        $entity = $this->findSession($promptCacheKey);
        if (null === $entity || !\is_array($entity->reasoningBaseline)) {
            return;
        }

        $transitions = $this->transitions($promptCacheKey);
        foreach ($transitions as $transition) {
            if ($transition['after'] === $after && $transition['effort'] === $effort) {
                return;
            }
        }

        $transitions[] = ['after' => $after, 'effort' => $effort];
        $baseline = $entity->reasoningBaseline;
        $baseline['transitions'] = $transitions;
        $entity->reasoningBaseline = $baseline;
        $this->entityManager->flush();
    }

    public function forget(string $promptCacheKey): void
    {
        $entity = $this->findSession($promptCacheKey);
        if (null === $entity || !\is_array($entity->reasoningBaseline)) {
            return;
        }

        $baseline = $entity->reasoningBaseline;
        if (!\array_key_exists('transitions', $baseline)) {
            return;
        }

        unset($baseline['transitions']);
        $entity->reasoningBaseline = $baseline;
        $this->entityManager->flush();
    }

    private function findSession(string $promptCacheKey): ?HatfieldSession
    {
        if ('' === $promptCacheKey) {
            return null;
        }

        /** @var HatfieldSession|null $entity */
        $entity = $this->entityManager->getRepository(HatfieldSession::class)->findOneBy([
            'providerCacheKey' => $promptCacheKey,
        ]);
        if (null !== $entity) {
            $this->entityManager->refresh($entity);
        }

        return $entity;
    }
}
