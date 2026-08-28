<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\AppConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\UuidV7;

use function Symfony\Component\String\u;

/**
 * Reconciles orphan numeric session directories into hatfield_session after DB loss.
 *
 * Invoked once on agent startup immediately after application schema migrations.
 * Scans sessions.path for canonical positive-digit directories with events.jsonl that
 * lack a matching hatfield_session row, derives available catalog metadata from the
 * canonical event stream, and inserts the row with the directory's explicit ID.
 *
 * Never rewrites session files. Concurrent startups are race-safe via
 * ON CONFLICT(id) DO NOTHING on the primary key. Malformed event logs are skipped
 * with privacy-safe diagnostics (no raw prompt/event/tool content). DB/storage
 * infrastructure failures propagate to StartupDatabaseMigrator.
 */
final class SessionCatalogRecoveryService
{
    public function __construct(
        private readonly AppConfig $appConfig,
        private readonly HatfieldSessionStore $sessionStore,
        private readonly Connection $connection,
        private readonly SessionRunEventStore $eventStore,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        $sessionsDir = $this->sessionStore->resolveSessionsBasePath();
        if (!is_dir($sessionsDir)) {
            return;
        }

        $entries = @scandir($sessionsDir);
        if (false === $entries) {
            $this->logger->warning('session_catalog_recovery.scan_failed', [
                'component' => 'session_catalog_recovery',
                'event_type' => 'session_catalog_recovery.scan_failed',
                'reason' => 'scandir_failed',
            ]);

            return;
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            if (!$this->isCanonicalPositiveSessionId($entry)) {
                continue;
            }

            $sessionId = $entry;
            $sessionDir = $sessionsDir.'/'.$sessionId;
            if (!is_dir($sessionDir)) {
                continue;
            }

            $eventsPath = $sessionDir.'/events.jsonl';
            if (!is_file($eventsPath)) {
                continue;
            }

            $id = (int) $sessionId;
            if ($this->sessionRowExists($id)) {
                continue;
            }

            // Event read/denormalization may fail for corrupt orphans — local degradation.
            // DB insert stays outside this catch so infrastructure failures hard-fail startup.
            try {
                $events = $this->eventStore->allFor($sessionId);
                $meta = $this->deriveMetadata($events);
            } catch (\Throwable $e) {
                $this->logger->warning('session_catalog_recovery.orphan_skipped', [
                    'component' => 'session_catalog_recovery',
                    'event_type' => 'session_catalog_recovery.orphan_skipped',
                    'session_id' => $sessionId,
                    'run_id' => $sessionId,
                    'exception_class' => $e::class,
                    'reason' => 'unrecoverable_orphan',
                ]);

                continue;
            }

            $this->insertRecoveredRow($id, $sessionId, $meta);
        }
    }

    /**
     * Positive decimal directory names that round-trip exactly through int.
     * Rejects leading zeros (`007`), zero, non-digits, and overflow aliases.
     */
    private function isCanonicalPositiveSessionId(string $entry): bool
    {
        if ('' === $entry || !ctype_digit($entry)) {
            return false;
        }

        $asInt = (int) $entry;
        if ($asInt <= 0) {
            return false;
        }

        // Leading-zero aliases and saturating overflow names fail this equality.
        return (string) $asInt === $entry;
    }

    /**
     * @param array{
     *     cwd: string,
     *     prompt: ?string,
     *     parent_id: ?string,
     *     model: ?string,
     *     model_provider: ?string,
     *     model_name: ?string,
     *     reasoning: ?string,
     *     name: string,
     *     provider_cache_key: string,
     *     created_at: \DateTimeImmutable,
     *     updated_at: \DateTimeImmutable
     * } $meta
     */
    private function insertRecoveredRow(int $id, string $sessionId, array $meta): void
    {
        // Explicit ID insert; ON CONFLICT(id) ignores only concurrent PK races.
        $affected = $this->connection->executeStatement(
            'INSERT INTO hatfield_session (
                id, cwd, prompt, parent_id, root_id, model, model_provider, model_name,
                reasoning, name, provider_cache_key, created_at, updated_at
            ) VALUES (
                :id, :cwd, :prompt, :parent_id, :root_id, :model, :model_provider, :model_name,
                :reasoning, :name, :provider_cache_key, :created_at, :updated_at
            ) ON CONFLICT(id) DO NOTHING',
            [
                'id' => $id,
                'cwd' => $meta['cwd'],
                'prompt' => $meta['prompt'],
                'parent_id' => $meta['parent_id'],
                'root_id' => null,
                'model' => $meta['model'],
                'model_provider' => $meta['model_provider'],
                'model_name' => $meta['model_name'],
                'reasoning' => $meta['reasoning'],
                'name' => $meta['name'],
                'provider_cache_key' => $meta['provider_cache_key'],
                'created_at' => $meta['created_at']->format('Y-m-d H:i:s'),
                'updated_at' => $meta['updated_at']->format('Y-m-d H:i:s'),
            ],
        );

        if ($affected > 0) {
            $this->logger->info('session_catalog_recovery.recovered', [
                'component' => 'session_catalog_recovery',
                'event_type' => 'session_catalog_recovery.recovered',
                'session_id' => $sessionId,
                'run_id' => $sessionId,
            ]);
        }
    }

    /**
     * @param list<RunEvent> $events
     *
     * @return array{
     *     cwd: string,
     *     prompt: ?string,
     *     parent_id: ?string,
     *     model: ?string,
     *     model_provider: ?string,
     *     model_name: ?string,
     *     reasoning: ?string,
     *     name: string,
     *     provider_cache_key: string,
     *     created_at: \DateTimeImmutable,
     *     updated_at: \DateTimeImmutable
     * }
     */
    private function deriveMetadata(array $events): array
    {
        $prompt = null;
        $model = null;
        $reasoning = null;
        $parentId = null;
        $createdAt = null;
        $updatedAt = null;

        foreach ($events as $event) {
            if (null === $createdAt || $event->createdAt < $createdAt) {
                $createdAt = $event->createdAt;
            }
            if (null === $updatedAt || $event->createdAt > $updatedAt) {
                $updatedAt = $event->createdAt;
            }

            if (RunEventTypeEnum::RunStarted->value === $event->type) {
                $fromStart = $this->extractFromRunStarted($event);
                if (null === $prompt && null !== $fromStart['prompt']) {
                    $prompt = $fromStart['prompt'];
                }
                if (null === $model && null !== $fromStart['model']) {
                    $model = $fromStart['model'];
                }
                if (null === $reasoning && null !== $fromStart['reasoning']) {
                    $reasoning = $fromStart['reasoning'];
                }
                if (null === $parentId && null !== $fromStart['parent_id']) {
                    $parentId = $fromStart['parent_id'];
                }
            }
        }

        $modelProvider = null;
        $modelName = null;
        if (null !== $model) {
            $ref = AiModelReference::tryParse($model);
            if (null !== $ref) {
                $modelProvider = $ref->providerId;
                $modelName = $ref->modelName;
            }
        }

        $now = new \DateTimeImmutable();

        return [
            'cwd' => $this->appConfig->cwd,
            'prompt' => $prompt,
            'parent_id' => $parentId,
            'model' => $model,
            'model_provider' => $modelProvider,
            'model_name' => $modelName,
            'reasoning' => $reasoning,
            'name' => $this->resolveDefaultName($prompt),
            // Original provider_cache_key is SQLite-only; mint a fresh UUIDv7.
            'provider_cache_key' => UuidV7::v7()->toRfc4122(),
            'created_at' => $createdAt ?? $now,
            'updated_at' => $updatedAt ?? $now,
        ];
    }

    /**
     * @return array{prompt: ?string, model: ?string, reasoning: ?string, parent_id: ?string}
     */
    private function extractFromRunStarted(RunEvent $event): array
    {
        $payload = $event->payload;
        $inner = \is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
        $messages = \is_array($inner['messages'] ?? null) ? $inner['messages'] : [];
        $metadata = \is_array($inner['metadata'] ?? null) ? $inner['metadata'] : [];

        $prompt = $this->firstUserPromptText($messages);

        $model = null;
        $rawModel = $metadata['model'] ?? null;
        if (\is_string($rawModel)) {
            $rawModel = trim($rawModel);
            $model = '' !== $rawModel ? $rawModel : null;
        }

        $reasoning = null;
        $rawReasoning = $metadata['reasoning'] ?? null;
        if (\is_string($rawReasoning)) {
            $rawReasoning = trim($rawReasoning);
            $reasoning = '' !== $rawReasoning ? $rawReasoning : null;
        }

        $parentId = null;
        $sessionMeta = \is_array($metadata['session'] ?? null) ? $metadata['session'] : [];
        $rawParent = $sessionMeta['parent_run_id'] ?? null;
        if (\is_string($rawParent)) {
            $rawParent = trim($rawParent);
            $parentId = '' !== $rawParent ? $rawParent : null;
        }

        return [
            'prompt' => $prompt,
            'model' => $model,
            'reasoning' => $reasoning,
            'parent_id' => $parentId,
        ];
    }

    /**
     * @param list<mixed> $messages
     */
    private function firstUserPromptText(array $messages): ?string
    {
        foreach ($messages as $message) {
            if (!\is_array($message)) {
                continue;
            }
            if ('user' !== ($message['role'] ?? null)) {
                continue;
            }

            $content = $message['content'] ?? null;
            if (!\is_array($content)) {
                continue;
            }

            $parts = [];
            foreach ($content as $part) {
                if (!\is_array($part)) {
                    continue;
                }
                if ('text' !== ($part['type'] ?? null)) {
                    continue;
                }
                $text = $part['text'] ?? null;
                if (\is_string($text) && '' !== $text) {
                    $parts[] = $text;
                }
            }

            if ([] !== $parts) {
                return implode("\n", $parts);
            }
        }

        return null;
    }

    private function resolveDefaultName(?string $prompt): string
    {
        if (null === $prompt || '' === trim($prompt)) {
            return 'Session';
        }

        $name = u($prompt)
            ->trim()
            ->replaceMatches('/\s+/u', ' ')
            ->truncate(200, '');

        $nameStr = $name->toString();

        return '' !== $nameStr ? $nameStr : 'Session';
    }

    private function sessionRowExists(int $id): bool
    {
        $found = $this->connection->fetchOne(
            'SELECT 1 FROM hatfield_session WHERE id = ?',
            [$id],
        );

        return false !== $found && null !== $found;
    }
}
