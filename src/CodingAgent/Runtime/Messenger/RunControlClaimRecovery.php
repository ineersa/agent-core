<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Clears claimed run_control rows only while exclusive worker ownership is held.
 *
 * Session Doctrine DSNs keep claimed rows unavailable for ~ten years. The
 * controller owns exactly one run_control consumer. After that tracked process
 * exits, reclaim succeeds only when this process can acquire the shared
 * run_control worker lock and keep it for the entire SQL update. A live worker
 * that already holds the lock blocks reclaim, including same-process holders.
 * AdvanceRun and other run_control handlers remain token/idempotency guarded.
 *
 * Scope is intentionally narrow: only the session run_control queue. Other
 * transports stay on explicit /repair redrive. The long redeliver_timeout is
 * unchanged.
 */
final readonly class RunControlClaimRecovery implements RunControlClaimRecoveryInterface
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.messenger_transport_connection')]
        private Connection $connection,
        private RunControlWorkerOwnership $ownership,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{released: int, failure: ?string}
     */
    public function releaseAbandonedClaims(string $sessionId): array
    {
        $sessionId = trim($sessionId);
        if ('' === $sessionId || 'unknown' === $sessionId) {
            return [
                'released' => 0,
                'failure' => 'missing_session_id',
            ];
        }

        $acquired = $this->ownership->tryAcquireForRecovery($sessionId);
        if (null !== $acquired['failure']) {
            if ('live_owner_present' === $acquired['failure']) {
                $this->logger->error('run_control.claim_recovery_blocked_by_live_owner', [
                    'component' => 'RunControlClaimRecovery',
                    'event_type' => 'run_control.claim_recovery_blocked_by_live_owner',
                    'session_id' => $sessionId,
                    'queue_name' => 'run_control_'.$sessionId,
                ]);
            }

            return [
                'released' => 0,
                'failure' => $acquired['failure'],
            ];
        }

        $queueName = 'run_control_'.$sessionId;
        $lock = $acquired['lock'];

        try {
            try {
                $released = $this->connection->executeStatement(
                    'UPDATE messenger_messages SET delivered_at = NULL WHERE queue_name = ? AND delivered_at IS NOT NULL',
                    [$queueName],
                );
            } catch (\Throwable $exception) {
                $this->logger->error('run_control.claim_recovery_failed', [
                    'component' => 'RunControlClaimRecovery',
                    'event_type' => 'run_control.claim_recovery_failed',
                    'session_id' => $sessionId,
                    'queue_name' => $queueName,
                    'exception' => $exception,
                ]);

                return [
                    'released' => 0,
                    'failure' => 'db_update_failed',
                ];
            }

            $this->logger->warning('run_control.claim_recovery_released', [
                'component' => 'RunControlClaimRecovery',
                'event_type' => 'run_control.claim_recovery_released',
                'session_id' => $sessionId,
                'queue_name' => $queueName,
                'released' => $released,
            ]);

            return [
                'released' => $released,
                'failure' => null,
            ];
        } finally {
            $this->ownership->releaseRecoveryLock($lock);
        }
    }
}
