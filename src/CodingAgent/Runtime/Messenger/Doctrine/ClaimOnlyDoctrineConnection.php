<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Query\ForUpdate\ConflictResolutionMode;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection;

/**
 * Doctrine Messenger connection that never reclaims claimed rows by age.
 *
 * Domain shape:
 * - queued/unclaimed (`delivered_at IS NULL`) remains receivable when available_at is due
 * - claimed (`delivered_at IS NOT NULL`) stays invisible to get()/findAll()/getMessageCount()
 *   forever, regardless of age
 *
 * Ack/reject still delete the row. A legitimate retry must enqueue a fresh unclaimed
 * row (Symfony's reject+retry path), not age-reclaim the claimed delivery.
 */
final class ClaimOnlyDoctrineConnection extends Connection
{
    public function get(int $fetchSize = 1): ?array
    {
        get:
        $this->driverConnection->beginTransaction();
        try {
            $query = $this->createUnclaimedAvailableMessagesQueryBuilder()
                ->orderBy('available_at', 'ASC')
                ->setMaxResults($fetchSize);

            if ($this->driverConnection->getDatabasePlatform() instanceof OraclePlatform) {
                $query->select('m.id');
            }

            $sql = $query->getSQL();

            if ($this->driverConnection->getDatabasePlatform() instanceof OraclePlatform) {
                $query = $this->createUnlockedQueryBuilder('w')
                    ->where('w.id IN ('.str_replace('SELECT a.* FROM', 'SELECT a.id FROM', $sql).')')
                    ->setParameters($query->getParameters(), $query->getParameterTypes());

                $sql = $query->getSQL();
            }

            $sql = $this->addSkipLockedMode($query, $sql);

            $doctrineEnvelopes = $this->executeUnlockedQuery(
                $sql,
                $query->getParameters(),
                $query->getParameterTypes()
            )->fetchAllAssociative();

            if ([] === $doctrineEnvelopes) {
                $this->driverConnection->commit();
                $this->queueEmptiedAt = microtime(true) * 1000;

                return null;
            }

            $this->queueEmptiedAt = null;

            $doctrineEnvelopes = array_map($this->decodeUnlockedEnvelopeHeaders(...), $doctrineEnvelopes);
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

        return $this->executeUnlockedQuery(
            $queryBuilder->getSQL(),
            $queryBuilder->getParameters(),
            $queryBuilder->getParameterTypes()
        )->fetchOne();
    }

    public function findAll(?int $limit = null): array
    {
        $queryBuilder = $this->createUnclaimedAvailableMessagesQueryBuilder();

        if (null !== $limit) {
            $queryBuilder->setMaxResults($limit);
        }

        return array_map(
            $this->decodeUnlockedEnvelopeHeaders(...),
            $this->executeUnlockedQuery(
                $queryBuilder->getSQL(),
                $queryBuilder->getParameters(),
                $queryBuilder->getParameterTypes()
            )->fetchAllAssociative()
        );
    }

    private function createUnclaimedAvailableMessagesQueryBuilder(): QueryBuilder
    {
        $now = new \DateTimeImmutable('UTC');

        return $this->createUnlockedQueryBuilder()
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

    private function createUnlockedQueryBuilder(string $alias = 'm'): QueryBuilder
    {
        $queryBuilder = $this->driverConnection->createQueryBuilder()
            ->from($this->configuration['table_name'], $alias);

        $alias .= '.';

        return $queryBuilder->select($alias.'*');
    }

    /**
     * @param list<mixed>|array<string, mixed> $parameters
     * @param array<int|string, mixed>         $types
     */
    private function executeUnlockedQuery(string $sql, array $parameters = [], array $types = []): Result
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
    private function decodeUnlockedEnvelopeHeaders(array $doctrineEnvelope): array
    {
        $doctrineEnvelope['headers'] = json_decode((string) $doctrineEnvelope['headers'], true);

        return $doctrineEnvelope;
    }

    private function addSkipLockedMode(QueryBuilder $query, string $sql): string
    {
        $query->forUpdate(ConflictResolutionMode::SKIP_LOCKED);
        try {
            return $query->getSQL();
        } catch (DBALException) {
            return $this->fallBackToPlainForUpdate($query, $sql);
        }
    }

    private function fallBackToPlainForUpdate(QueryBuilder $query, string $sql): string
    {
        $query->forUpdate();
        try {
            return $query->getSQL();
        } catch (DBALException) {
            return $sql;
        }
    }

    private function isAutoSetupEnabled(): bool
    {
        return (bool) $this->configuration['auto_setup'];
    }
}
