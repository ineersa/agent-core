<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

use Ineersa\CodingAgent\Tool\CodeMode\DTO\CodeModeTerminalReportDTO;
use Ineersa\SqliteQueue\Messenger\TransportFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\Serializer as MessengerSerializer;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/** Internal post-exit producer; needs only the session broker, not the application kernel. */
final class CodeModeWakeup
{
    public const string FLAG = '--internal-code-mode-wakeup';

    public static function send(string $runId, string $id, string $endpoint): void
    {
        $serializer = new MessengerSerializer(new Serializer([
            new ObjectNormalizer(nameConverter: new CamelCaseToSnakeCaseNameConverter()),
        ], [new JsonEncoder()]));
        $transport = (new TransportFactory())->createTransport('sqlite-queue://code_mode_requests', ['endpoint' => $endpoint], $serializer);
        try {
            $transport->send(new Envelope(new CodeModeTerminalReportDTO($runId, $id)));
        } finally {
            $transport->close();
        }
    }
}
