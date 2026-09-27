<?php

declare(strict_types=1);

/** Test-only kernel subprocess: real outer transactions, without DAMA's enclosing transaction. */
require dirname(__DIR__, 4).'/vendor/autoload.php';

use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Ineersa\CodingAgent\Tests\Doctrine\Support\SqliteImmediateTransactionKernelTestKernel;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as MessengerConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;

$_ENV['APP_SECRET'] = 'test-secret';
foreach (['APP_ENV', 'APP_DEBUG', 'HATFIELD_CWD', 'HATFIELD_LOG_DIR', 'HATFIELD_TEST_DATABASE_PATH', 'HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH'] as $key) {
    $_ENV[$key] = (string) getenv($key);
}
StaticDriver::setKeepStaticConnections(false);
SqliteImmediateTransactionKernelTestKernel::bootForSqliteWorker();
$container = SqliteImmediateTransactionKernelTestKernel::getContainerForSqliteWorker();
$logger = $container->get('test.sqlite_polling_query_logger');
if (!$logger instanceof Monolog\Logger) {
    throw new RuntimeException('Expected the application Monolog logger.');
}
$logCapture = new Monolog\Handler\TestHandler();
$logger->pushHandler($logCapture);
/** @var Connection $db */
$db = $container->get('doctrine.dbal.messenger_transport_connection');
/** @var TransportFactoryInterface $factory */
$factory = $container->get('messenger.transport_factory');
$dsn = 'doctrine://messenger_transport?queue_name=poll_probe&redeliver_timeout=315360000';
$transport = $factory->createTransport($dsn, [], new PhpSerializer());
if (!$transport instanceof DoctrineTransport) {
    throw new RuntimeException('Expected the Doctrine transport with the polling connection.');
}
$transport->setup();
$stock = new MessengerConnection(MessengerConnection::buildConfiguration($dsn), $db);

$statements = [];
$sample = static function (string $label, Closure $operation) use ($logCapture, &$statements): mixed {
    $logCapture->clear();
    try {
        return $operation();
    } finally {
        $statements[$label] = array_map(static fn (Monolog\LogRecord $record): string => $record->context['sql'] ?? $record->message, $logCapture->getRecords());
    }
};
$sample('stock_empty', static fn (): ?array => $stock->get());
$data['empty_count'] = $sample('optimized_empty', static fn (): int => count(iterator_to_array($transport->get())));
$transport->send(new Envelope(new stdClass()));
$transport->send(new Envelope(new stdClass()));
$batch = $sample('batch', static fn (): array => iterator_to_array($transport->get(2)));
$data['batch_count'] = count($batch);
$data['reserved_count'] = $sample('reserved', static fn (): int => count(iterator_to_array($transport->get())));
$db->executeStatement('UPDATE messenger_messages SET delivered_at = ? WHERE id = (SELECT MIN(id) FROM messenger_messages)', ['2000-01-01 00:00:00']);
$expired = $sample('expired', static fn (): array => iterator_to_array($transport->get()));
$data['expired_count'] = count($expired);
$transport->ack($expired[0]);
$transport->reject($batch[1]);
$data['rows_after_ack_reject'] = (int) $db->fetchOne('SELECT COUNT(*) FROM messenger_messages');
$transport->send(new Envelope(new stdClass(), [new DelayStamp(60000)]));
$data['delayed_count'] = $sample('delayed', static fn (): int => count(iterator_to_array($transport->get())));
$db->executeStatement('DELETE FROM messenger_messages');
$other = $factory->createTransport(str_replace('poll_probe', 'other_queue', $dsn), [], new PhpSerializer());
$other->send(new Envelope(new stdClass()));
$data['other_queue_count'] = $sample('other_queue', static fn (): int => count(iterator_to_array($transport->get())));
$transport->send(new Envelope(new stdClass()));
$data['new_arrival_count'] = $sample('new_arrival', static fn (): int => count(iterator_to_array($transport->get())));

$missing = $factory->createTransport($dsn.'&table_name=missing_probe&auto_setup=false', [], new PhpSerializer());
try {
    iterator_to_array($missing->get());
    throw new RuntimeException('Missing table must not be mistaken for an empty queue.');
} catch (Symfony\Component\Messenger\Exception\TransportException $e) {
    if (!$e->getPrevious() instanceof TableNotFoundException) {
        throw $e;
    }
    $data['missing_table'] = 'missing_table_propagated';
}
$autoSetup = $factory->createTransport($dsn.'&table_name=fresh_probe', [], new PhpSerializer());
$data['auto_setup_count'] = count(iterator_to_array($autoSetup->get()));

$data['statements'] = $statements;
$db->close();
echo json_encode($data, \JSON_THROW_ON_ERROR)."\n";
