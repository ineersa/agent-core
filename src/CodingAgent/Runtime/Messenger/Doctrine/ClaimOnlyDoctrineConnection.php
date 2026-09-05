<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection;

/**
 * Doctrine Messenger connection that never reclaims claimed rows by age.
 *
 * Unclaimed due rows remain receivable. Claimed rows stay invisible to get(),
 * findAll(), and getMessageCount() until ack/reject deletes them. Legitimate
 * retries enqueue a fresh unclaimed row.
 */
final class ClaimOnlyDoctrineConnection extends Connection
{
    /**
     * @return list<array<string, mixed>>|null
     */
    public function get(int $fetchSize = 1): ?array
    {
        get:
        $this->driverConnection->beginTransaction();
        try {
            $query = $this->createUnclaimedAvailableMessagesQueryBuilder()
                ->orderBy('available_at', 'ASC')
                ->setMaxResults($fetchSize);

            $doctrineEnvelopes = $this->executeQuery(
                $query->getSQL(),
                $query->getParameters(),
                $query->getParameterTypes()
            )->fetchAllAssociative();

            if ([] === $doctrineEnvelopes) {
                $this->driverConnection->commit();
                $this->queueEmptiedAt = microtime(true) * 1000;

                return null;
            }

            $this->queueEmptiedAt = null;
            $doctrineEnvelopes = array_map($this->decodeEnvelopeHeaders(...), $doctrineEnvelopes);
            $now = new \DateTimeImmutable('UTC');

            if (1 === \count($doctrineEnvelopes)) {
                $queryBuilder = $this->driverConnection->createQueryBuilder()
                    ->update($this->configuration['table_name'])
                    ->set('delivered_at', '?')
                    ->where('id = ?');

                $this->executeStatement($queryBuilder->getSQL(), [
                    $now,
                    $doctrineEnvelopes[0]['id'],
                ], [
                    Types::DATETIME_IMMUTABLE,
                ]);
            } else {
                $ids = array_column($doctrineEnvelopes, 'id');
                $queryBuilder = $this->driverConnection->createQueryBuilder()
                    ->update($this->configuration['table_name'])
                    ->set('delivered_at', '?')
                    ->where('id IN (?)');

                $this->executeStatement($queryBuilder->getSQL(), [
                    $now,
                    $ids,
                ], [
                    Types::DATETIME_IMMUTABLE,
                    ArrayParameterType::STRING,
                ]);
            }

            $this->driverConnection->commit();

            return $doctrineEnvelopes;
        } catch (\Throwable $e) {
            $this->driverConnection->rollBack();

            if ($this->isAutoSetupEnabled() && $e instanceof TableNotFoundException) {
                $this->setup();
                goto get;
            }

            throw $e;
        }
    }

    public function getMessageCount(): int
    {
        $queryBuilder = $this->createUnclaimedAvailableMessagesQueryBuilder()
            ->select('COUNT(m.id) AS message_count')
            ->setMaxResults(1);

        return $this->executeQuery(
            $queryBuilder->getSQL(),
            $queryBuilder->getParameters(),
            $queryBuilder->getParameterTypes()
        )->fetchOne();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(?int $limit = null): array
    {
        $queryBuilder = $this->createUnclaimedAvailableMessagesQueryBuilder();

        if (null !== $limit) {
            $queryBuilder->setMaxResults($limit);
        }

        return array_map(
            $this->decodeEnvelopeHeaders(...),
            $this->executeQuery(
                $queryBuilder->getSQL(),
                $queryBuilder->getParameters(),
                $queryBuilder->getParameterTypes()
            )->fetchAllAssociative()
        );
    }

    private function createUnclaimedAvailableMessagesQueryBuilder(): QueryBuilder
    {
        $now = new \DateTimeImmutable('UTC');

        return $this->driverConnection->createQueryBuilder()
            ->select('m.*')
            ->from($this->configuration['table_name'], 'm')
            ->where('m.queue_name = ?')
            ->andWhere('m.delivered_at is null')
            ->andWhere('m.available_at <= ?')
            ->setParameters([
                $this->configuration['queue_name'],
                $now,
            ], [
                Types::STRING,
                Types::DATETIME_IMMUTABLE,
            ]);
    }

    /**
     * @param list<mixed>|array<string, mixed> $parameters
     * @param array<int|string, mixed>         $types
     */
    private function executeQuery(string $sql, array $parameters = [], array $types = []): Result
    {
        try {
            return $this->driverConnection->executeQuery($sql, $parameters, $types);
        } catch (TableNotFoundException $e) {
            if (!$this->isAutoSetupEnabled() || $this->driverConnection->isTransactionActive()) {
                throw $e;
            }
        }

        $this->setup();

        return $this->driverConnection->executeQuery($sql, $parameters, $types);
    }

    /**
     * @param array<string, mixed> $doctrineEnvelope
     *
     * @return array<string, mixed>
     */
    private function decodeEnvelopeHeaders(array $doctrineEnvelope): array
    {
        $doctrineEnvelope['headers'] = json_decode((string) $doctrineEnvelope['headers'], true);

        return $doctrineEnvelope;
    }

    private function isAutoSetupEnabled(): bool
    {
        return (bool) $this->configuration['auto_setup'];
    }
}
