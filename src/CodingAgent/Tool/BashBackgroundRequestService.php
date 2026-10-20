<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\CodingAgent\Tool\DTO\BashBackgroundRequestDTO;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class BashBackgroundRequestService
{
    public function __construct(
        private BackgroundProcessManager $manager,
        private DenormalizerInterface $serializer,
        private ValidatorInterface $validator,
        private LoggerInterface $logger,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function request(string $runId, array $payload): bool
    {
        try {
            $request = $this->serializer->denormalize($payload, BashBackgroundRequestDTO::class);
        } catch (ExceptionInterface $exception) {
            $this->logger->warning('bash_tool.background_target_invalid', [
                'component' => 'tool.bash',
                'event_type' => 'bash_tool.background_target_invalid',
                'run_id' => $runId,
                'session_id' => $runId,
                'exception_class' => $exception::class,
            ]);

            return false;
        }

        if (0 !== \count($this->validator->validate($request))) {
            return false;
        }

        return $this->manager->requestBackground($runId, $request->recordId, $request->toolCallId);
    }
}
