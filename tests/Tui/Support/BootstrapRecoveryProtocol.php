<?php

declare(strict_types=1);

// Owned protocol peer, not a configured controller or worker stack. Commands
// and pipe EOF are its deterministic barriers; it never needs a signal to exit.
require dirname(__DIR__, 3).'/vendor/autoload.php';

use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;

$directory = getcwd();
$generation = is_file($directory.'/generation') ? (int) file_get_contents($directory.'/generation') + 1 : 1;
file_put_contents($directory.'/generation', (string) $generation);
$template = json_decode(file_get_contents($directory.'/transfer.json'), true, 512, \JSON_THROW_ON_ERROR);
$cut = null;
$sequence = 0;
$emit = static function (string $type, string $runId, int $seq = 0, array $payload = []): void {
    if (!JsonlCodec::write(\STDOUT, JsonlCodec::encodeEvent(new RuntimeEvent($type, $runId, $seq, $payload)))) {
        exit(2);
    }
};
$emit(RuntimeEventTypeEnum::RuntimeReady->value, '');
while (false !== ($line = fgets(\STDIN))) {
    $command = JsonlCodec::decodeCommand($line);
    file_put_contents($directory.'/commands.jsonl', JsonlCodec::encodeCommand($command), \FILE_APPEND);
    if ('start_run' === $command->type) {
        $sequence = 13;
        $emit(RuntimeEventTypeEnum::RunStarted->value, $command->runId, $sequence - 1);
        $emit(RuntimeEventTypeEnum::UserMessageSubmitted->value, $command->runId, $sequence,
            ['text' => $command->payload['prompt'], 'idempotency_key' => 'initial']);
    } elseif ('resume' === $command->type) {
        // An untrusted wire packet cannot replace the local recovery decision.
        $emit(RuntimeEventTypeEnum::SessionRestoring->value, $command->runId, 0,
            ['previous_command_id' => $command->id, 'command_id' => 'unsolicited-request']);
        if (is_file($directory.'/previous.json')) {
            $previous = json_decode(file_get_contents($directory.'/previous.json'), true, 512, \JSON_THROW_ON_ERROR);
            $emit(RuntimeEventTypeEnum::BootstrapAvailable->value, $command->runId, 0, $previous);
            $emit(RuntimeEventTypeEnum::BootstrapFrame->value, $command->runId, 0,
                ['bootstrap_id' => $previous['bootstrap_id'], 'view_epoch' => $previous['view_epoch'], 'index' => 0, 'data' => base64_encode('stale packet')]);
        }
        $bytes = str_replace('Owner-projected answer', 'Owner-projected answer generation '.$generation, $template['bytes']);
        $cut = array_replace($template['cut'], ['bootstrap_id' => substr(hash('sha256', (string) $generation), 0, 32),
            'view_epoch' => $generation, 'canonical_seq' => 13 + 100 * ($generation - 1), 'end_offset' => 500 * $generation,
            'bytes' => strlen($bytes), 'checksum' => hash('sha256', $bytes)]);
        $sequence = $cut['canonical_seq'];
        file_put_contents($directory.'/previous.json', json_encode($cut + ['command_id' => $command->id], \JSON_THROW_ON_ERROR));
        $emit(RuntimeEventTypeEnum::BootstrapAvailable->value, $command->runId, 0, $cut + ['command_id' => $command->id]);
        $emit(RuntimeEventTypeEnum::BootstrapFrame->value, $command->runId, 0,
            ['bootstrap_id' => $cut['bootstrap_id'], 'view_epoch' => $cut['view_epoch'], 'index' => 0, 'data' => base64_encode($bytes)]);
        $emit(RuntimeEventTypeEnum::BootstrapEnd->value, $command->runId, 0, $cut + ['frames' => 1]);
        if (is_file($directory.'/child-run')) {
            $emit(RuntimeEventTypeEnum::RunStarted->value, file_get_contents($directory.'/child-run'), 1);
        }
    } elseif ('bootstrap.applied' === $command->type) {
        if ($command->payload !== $cut) {
            exit(3);
        }
        $suffix = new RuntimeEvent(RuntimeEventTypeEnum::UserMessageSubmitted->value, $command->runId, ++$sequence,
            ['text' => 'Caught-up input generation '.$generation, 'idempotency_key' => 'suffix-'.$generation]);
        $emit(RuntimeEventTypeEnum::BootstrapSuffix->value, $command->runId, 0,
            ['bootstrap_id' => $cut['bootstrap_id'], 'view_epoch' => $cut['view_epoch'], 'canonical_seq' => $sequence,
                'index' => 0, 'last' => true, 'data' => base64_encode(JsonlCodec::encodeEvent($suffix))]);
        $emit(RuntimeEventTypeEnum::SessionReady->value, $command->runId, 0,
            ['bootstrap_id' => $cut['bootstrap_id'], 'view_epoch' => $cut['view_epoch'], 'canonical_seq' => $sequence, 'end_offset' => $cut['end_offset'] + 1]);
    } elseif ('follow_up' === $command->type || 'user_message' === $command->type) {
        if ('exit-controller' === ($command->payload['text'] ?? null)) {
            exit(0);
        }
        $emit(RuntimeEventTypeEnum::UserMessageSubmitted->value, $command->runId, ++$sequence,
            ['text' => $command->payload['text'], 'idempotency_key' => $command->id]);
    }
}
