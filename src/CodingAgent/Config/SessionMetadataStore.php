<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;

/**
 * Session metadata store — delegates to HatfieldSessionStore.
 *
 * Session metadata lives in the hatfield_session DB table, not
 * in a metadata.yaml file. This class is a focused adapter so
 * callers that need only metadata operations (e.g. ModelSelectionService)
 * can depend on a narrow interface without importing the full session
 * lifecycle store.
 */
final class SessionMetadataStore
{
    public function __construct(
        private readonly HatfieldSessionStore $hatfieldSessionStore,
    ) {
    }

    /**
     * Load the persisted session row by public session id.
     *
     * Callers must treat the returned entity as read-only.
     */
    public function findSession(string $sessionId): ?HatfieldSession
    {
        return $this->hatfieldSessionStore->findSession($sessionId);
    }

    /**
     * Write session metadata fields to the database.
     *
     * Delegates to HatfieldSessionStore::updateMetadata(), which maps
     * known keys to entity columns and ignores unknown keys.
     *
     * @param array<string, string> $fields Key-value pairs to set
     */
    public function writeSessionMetadata(string $sessionId, array $fields): void
    {
        $this->hatfieldSessionStore->updateMetadata($sessionId, $fields);
    }
}
