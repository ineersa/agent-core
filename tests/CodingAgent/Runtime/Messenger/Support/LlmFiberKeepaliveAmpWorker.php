<?php

declare(strict_types=1);

/**
 * Owned MEMORY-07 keepalive worker for hatfield:messenger:consume-llm.
 *
 * Args: endpoint delayServerBase hitFile resultFile claimFile
 *
 * Exercises production LlmFiberConsumeCommand with Amp HTTP Fibers and the
 * Revolt EventLoop keepalive timer. Stops after two handled messages.
 */

use Amp\Cancellation;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Ineersa\CodingAgent\Runtime\Messenger\LlmFiberConsumeCommand;
use Ineersa\CodingAgent\Runtime\Messenger\LlmFiberConsumeWaitSubscriber;
use Ineersa\SqliteQueue\Client;
use Ineersa\SqliteQueue\Messenger\BrokerConnection;
use Ineersa\SqliteQueue\Messenger\Transport;
use Ineersa\SqliteQueue\ValueObject\QueueName;
use Psr\Log\AbstractLogger;
use Revolt\EventLoop;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

$endpoint = (string) ($argv[1] ?? '');
$delayBase = (string) ($argv[2] ?? '');
$hitFile = (string) ($argv[3] ?? '');
$resultFile = (string) ($argv[4] ?? '');
$claimFile = (string) ($argv[5] ?? '');
if ('' === $endpoint || '' === $delayBase || '' === $hitFile || '' === $resultFile || '' === $claimFile) {
    fwrite(\STDERR, "usage: endpoint delayServerBase hitFile resultFile claimFile\n");
    exit(2);
}

$logFile = $resultFile.'.log';
$log = static function (string $message) use ($logFile): void {
    file_put_contents($logFile, sprintf("%.3f %s\n", microtime(true), $message), \FILE_APPEND);
};

require dirname(__DIR__, 5).'/vendor/autoload.php';
$log('autoload ok driver='.EventLoop::getDriver()::class);

$serializer = new PhpSerializer();
$connect = static function (Cancellation $cancellation) use ($endpoint): Client {
    return Client::connect($endpoint, cancellation: $cancellation);
};
$transport = new Transport(
    new BrokerConnection($connect),
    new QueueName('llm'),
    $serializer,
    new BrokerConnection($connect),
    $endpoint,
);
$log('transport ready');

$http = HttpClientBuilder::buildDefault();
$handled = [];
$bus = new MessageBus([
    new HandleMessageMiddleware(new HandlersLocator([
        stdClass::class => [static function (stdClass $message) use ($http, $delayBase, $hitFile, $claimFile, &$handled, $log): void {
            $id = (string) ($message->id ?? 'unknown');
            $log('handler start '.$id);
            $started = microtime(true);
            // Claim barrier: parent must wait until both Fibers hold reserved deliveries
            // and Amp HTTP is in flight before asserting no redelivery.
            file_put_contents($claimFile, (string) (((int) @file_get_contents($claimFile)) + 1));

            $request = new Request(rtrim($delayBase, '/').'/v1/chat/completions', 'POST');
            $request->setHeader('content-type', 'application/json');
            $request->setBody(json_encode([
                'model' => 'fixture',
                'stream' => true,
                'messages' => [['role' => 'user', 'content' => $id]],
            ], \JSON_THROW_ON_ERROR));
            $response = $http->request($request);
            $body = $response->getBody()->buffer();
            $handled[] = [
                'id' => $id,
                'status' => $response->getStatus(),
                'body_bytes' => strlen($body),
                'started_at' => $started,
                'finished_at' => microtime(true),
            ];
            file_put_contents($hitFile, (string) (((int) @file_get_contents($hitFile)) + 1));
            $log('handler done '.$id);
        }],
    ])),
]);

$receiverLocator = new ServiceLocator([
    'llm' => static fn () => $transport,
]);
$eventDispatcher = new EventDispatcher();
$eventDispatcher->addSubscriber(new LlmFiberConsumeWaitSubscriber($receiverLocator));
$eventDispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(2));

$logger = new class($log) extends AbstractLogger {
    /** @var list<array{level:mixed, message:string, context:array<string, mixed>, at:float}> */
    public array $records = [];

    public function __construct(private Closure $log)
    {
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
            'at' => microtime(true),
        ];
        ($this->log)(sprintf('[%s] %s %s', (string) $level, (string) $message, json_encode($context)));
    }
};

$command = new LlmFiberConsumeCommand(
    new RoutableMessageBus(new ServiceLocator(['default' => static fn () => $bus]), $bus),
    $receiverLocator,
    $eventDispatcher,
    $logger,
);

$application = new Application('memory07-keepalive', 'test');
$application->setAutoExit(false);
$application->setDispatcher($eventDispatcher);
$application->addCommand($command);

$input = new ArgvInput([
    'bin/console',
    'hatfield:messenger:consume-llm',
    '--keepalive=1',
    '--memory-limit=256M',
    '--no-interaction',
]);
file_put_contents($resultFile.'.boot', json_encode([
    'has_keepalive' => $input->hasParameterOption('--keepalive'),
    'driver' => EventLoop::getDriver()::class,
    'uv' => extension_loaded('uv'),
], \JSON_THROW_ON_ERROR));
$log('boot has_keepalive='.($input->hasParameterOption('--keepalive') ? 'yes' : 'no'));

$started = microtime(true);
$exit = $application->run($input, new NullOutput());
$log('application exit='.$exit);

file_put_contents($resultFile, json_encode([
    'exit' => $exit,
    'elapsed_ms' => (microtime(true) - $started) * 1000.0,
    'handled' => $handled,
    'keepalive_signals' => array_values(array_filter(
        $logger->records,
        static fn (array $row): bool => str_contains($row['message'], 'Renewing Fiber llm leases via Revolt timer'),
    )),
    'keepalive_armed' => array_values(array_filter(
        $logger->records,
        static fn (array $row): bool => str_contains($row['message'], 'Arming Revolt keepalive timer'),
    )),
    'driver' => EventLoop::getDriver()::class,
    'uv' => extension_loaded('uv'),
], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));

$transport->close();
exit(0 === $exit ? 0 : (int) $exit);
