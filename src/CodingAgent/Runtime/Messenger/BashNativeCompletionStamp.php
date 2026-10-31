<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

use Ineersa\CodingAgent\Tool\BackgroundProcess\DTO\BashNativeCompletionMetadataDTO;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Host-side envelope metadata for private native Bash facts.
 *
 * Carries validated {@see BashNativeCompletionMetadataDTO} beside an ordinary
 * ToolPayloadMessage result so owner receive can apply launch/accept/finish
 * writes before ordinary admission, without a Bash deferred lifecycle.
 */
final readonly class BashNativeCompletionStamp implements StampInterface
{
    public function __construct(
        public BashNativeCompletionMetadataDTO $metadata,
    ) {
    }
}
