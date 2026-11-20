<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeValueCodec;

/** @extends ServiceEntityRepository<CodeModeCall> */
final class CodeModeCallRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CodeModeCall::class);
    }

    /**
     * @param array<string, mixed>             $request
     * @param callable(): array<string, mixed> $execute
     *
     * @return array<string, mixed>|null null while the original claimant is alive
     */
    public function settle(string $invocationId, string $callId, array $request, callable $execute): ?array
    {
        $key = ['invocationId' => $invocationId, 'callId' => $callId];
        $fingerprint = hash('sha256', self::encode($request));
        $pid = getmypid();
        $owner = false === $pid ? null : self::identity($pid);
        if (null === $owner) {
            throw new ToolCallException('Cannot establish code-mode claim owner.', retryable: false);
        }
        $db = $this->connection();
        if (1 === $db->executeStatement('INSERT INTO code_mode_call (invocationId, callId, fingerprint, claimant) VALUES (?, ?, ?, ?) ON CONFLICT (invocationId, callId) DO NOTHING', [$invocationId, $callId, $fingerprint, $owner])) {
            // No DB transaction spans the execution of an external effect.
            try {
                $reply = $execute();
                if (\is_string($reply['child'] ?? null)) {
                    if ($reply['child'] !== $this->childId($invocationId, $callId)) {
                        throw new ToolCallException('Code-mode child was not reserved before launch.', retryable: false);
                    }

                    return $reply;
                }
                [$reply, $encoded] = self::terminal($reply);
                $db->executeStatement('UPDATE code_mode_call SET reply = ?, claimant = NULL WHERE invocationId = ? AND callId = ? AND reply IS NULL', [$encoded, $invocationId, $callId]);

                return $reply;
            } finally {
                $db->update('code_mode_call', ['claimant' => null], $key + ['claimant' => $owner]);
            }
        }
        $row = $db->fetchAssociative('SELECT fingerprint, claimant, childId, reply FROM code_mode_call WHERE invocationId = ? AND callId = ?', [$invocationId, $callId]);
        if (false === $row || $row['fingerprint'] !== $fingerprint) {
            throw new ToolCallException('Code-mode call ID was reused for a different request.', retryable: false);
        }
        if (\is_string($row['reply'])) {
            return self::decode($row['reply']);
        }
        if (\is_string($row['claimant']) && self::identity((int) $row['claimant']) === $row['claimant']) {
            return null;
        }
        if (\is_string($row['childId'])) {
            return ['child' => $row['childId']];
        }
        $reply = ['ok' => false, 'error' => 'Code-mode call outcome is unknown; refusing to run it again.'];
        $db->executeStatement('UPDATE code_mode_call SET reply = ?, claimant = NULL WHERE invocationId = ? AND callId = ? AND reply IS NULL AND childId IS NULL', [self::encode($reply), $invocationId, $callId]);

        return $this->reply($invocationId, $callId);
    }

    public function reserveChild(string $invocationId, string $callId, string $childId): void
    {
        $pid = getmypid();
        $owner = false === $pid ? null : self::identity($pid);
        if (null === $owner || 1 !== $this->connection()->executeStatement('UPDATE code_mode_call SET childId = ? WHERE invocationId = ? AND callId = ? AND claimant = ? AND childId IS NULL AND reply IS NULL', [$childId, $invocationId, $callId, $owner])) {
            throw new ToolCallException('Cannot reserve code-mode child for launch.', retryable: false);
        }
    }

    public function childId(string $invocationId, string $callId): ?string
    {
        $child = $this->connection()->fetchOne('SELECT childId FROM code_mode_call WHERE invocationId = ? AND callId = ?', [$invocationId, $callId]);

        return \is_string($child) ? $child : null;
    }

    /** @return array<string, mixed>|null */
    public function reply(string $invocationId, string $callId): ?array
    {
        $json = $this->connection()->fetchOne('SELECT reply FROM code_mode_call WHERE invocationId = ? AND callId = ?', [$invocationId, $callId]);

        return \is_string($json) ? self::decode($json) : null;
    }

    /** @param array<string, mixed> $reply */
    public function completeChild(string $invocationId, string $callId, string $childId, array $reply): bool
    {
        [, $encoded] = self::terminal($reply);

        return 1 === $this->connection()->executeStatement('UPDATE code_mode_call SET reply = ? WHERE invocationId = ? AND callId = ? AND childId = ? AND reply IS NULL', [$encoded, $invocationId, $callId, $childId]);
    }

    public function remove(string $invocationId): void
    {
        $this->connection()->delete('code_mode_call', ['invocationId' => $invocationId]);
    }

    private function connection(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }

    /** Linux process start time prevents PID reuse from renewing a dead claim. */
    private static function identity(int $pid): ?string
    {
        $stat = @file_get_contents('/proc/'.$pid.'/stat');
        if (false === $stat || false === ($end = strrpos($stat, ')'))) {
            return null;
        }
        $fields = explode(' ', substr($stat, $end + 2));

        return isset($fields[19]) && !\in_array($fields[0], ['Z', 'X'], true) ? $pid.':'.$fields[19] : null;
    }

    /** @return array<string, mixed> */
    private static function decode(string $json): array
    {
        /** @var array<string, mixed> $reply */
        $reply = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        return $reply;
    }

    /** @param array<string, mixed> $reply
     * @return array{array<string, mixed>, string}
     */
    private static function terminal(array $reply): array
    {
        try {
            return [$reply, self::encode($reply)];
        } catch (\RuntimeException|\JsonException $error) {
            $reply = ['ok' => false, 'error' => $error instanceof \JsonException ? 'Code-mode result could not be encoded.' : $error->getMessage()];

            return [$reply, self::encode($reply)];
        }
    }

    /** @param array<string, mixed> $value */
    private static function encode(array $value): string
    {
        $json = json_encode(CodeModeValueCodec::assertEncodable($value, 'Code-mode call record'), \JSON_THROW_ON_ERROR);
        if (\strlen($json) > 8_388_608) {
            throw new \RuntimeException('Code-mode call record exceeds 8388608 bytes.');
        }

        return $json;
    }
}
