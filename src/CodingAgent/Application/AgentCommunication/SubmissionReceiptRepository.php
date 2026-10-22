<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\AgentCommunication;

use Ineersa\CodingAgent\Application\AgentCommunication\DTO\SubmissionReceiptDTO;
use Ineersa\CodingAgent\Session\Contract\RunHistorySourceProviderInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/** Point lookup over the disposable canonical location index, not another journal. */
final readonly class SubmissionReceiptRepository
{
    public function __construct(private RunHistorySourceProviderInterface $sources, private DenormalizerInterface $denormalizer)
    {
    }

    public function receipt(string $runId, string $identity): ?SubmissionReceiptDTO
    {
        $source = $this->sources->historySource($runId);
        $payload = $source->log->submissionReceipt($source->path, $runId, $identity);

        return null === $payload ? null : $this->denormalizer->denormalize($payload, SubmissionReceiptDTO::class);
    }
}
