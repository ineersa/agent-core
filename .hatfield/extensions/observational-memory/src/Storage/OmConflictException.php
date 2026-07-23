<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Storage;

/**
 * Deterministic identity conflict: same key, incompatible payload.
 *
 * Treated as unrecoverable by Messenger handlers.
 */
final class OmConflictException extends \RuntimeException
{
}
