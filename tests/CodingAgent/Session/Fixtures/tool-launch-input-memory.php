<?php

declare(strict_types=1);

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Session\SessionToolLaunchInputStore;
use Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

require dirname(__DIR__, 4).'/vendor/autoload.php';

$paths = new class($argv[1]) implements ToolBatchRunStoragePathsInterface {
    public function __construct(private readonly string $directory)
    {
    }

    public function resolveToolBatchesDirectory(string $runId): string
    {
        return $this->directory.'/'.$runId.'/runtime/tool-batches';
    }
};
$store = new SessionToolLaunchInputStore($paths, new LockFactory(new FlockStore($argv[1])), AttributeSerializerValidatorTestFactory::serializer(), new Filesystem());
$messages = [];
for ($i = 0; $i < 64; ++$i) {
    $messages[] = new AgentMessage('user', [['type' => 'text', 'text' => $i.str_repeat('x', 524288)]]);
}
$weak = WeakReference::create($messages[0]);
$before = memory_get_usage(true);
$reference = $store->publish('fork', 'owner', 1, 'step', 'call', 'model', '', $messages);
$after = memory_get_usage(true);
$peak = memory_get_peak_usage(true);
unset($messages);
$released = null === $weak->get();
$store->delete('owner', 'call');
echo json_encode(['input_bytes' => $reference->bytes, 'owner_allocated_bytes' => $before, 'after_publish_bytes' => $after, 'peak_bytes' => $peak, 'source_released' => $released], \JSON_THROW_ON_ERROR)."\n";
