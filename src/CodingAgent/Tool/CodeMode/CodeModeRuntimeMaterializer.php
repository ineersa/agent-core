<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

use Symfony\Component\Filesystem\Filesystem;

/** Copies the locked runtime closure out of the host archive before external PHP starts. */
final readonly class CodeModeRuntimeMaterializer
{
    public function __construct(private Filesystem $filesystem)
    {
    }

    public function materialize(string $target): void
    {
        $root = \dirname(__DIR__, 4);
        $packages = array_column(json_decode($this->filesystem->readFile($root.'/composer.lock'), true, flags: \JSON_THROW_ON_ERROR)['packages'], null, 'name');
        $pending = ['amphp/sync', 'ineersa/sqlite-queue', 'symfony/serializer', 'symfony/property-access', 'symfony/validator', 'helgesverre/toon'];
        $seen = [];
        $autoload = [];
        while ([] !== $pending) {
            $name = array_pop($pending);
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $package = $packages[$name];
            foreach (array_keys($package['require'] ?? []) as $requirement) {
                if (isset($packages[$requirement])) {
                    $pending[] = $requirement;
                }
            }
            $this->filesystem->mirror($root.'/vendor/'.$name, $target.'/vendor/'.$name);
            $autoload[$name] = $package['autoload'] ?? [];
        }
        $this->filesystem->copy($root.'/vendor/composer/ClassLoader.php', $target.'/ClassLoader.php');
        $this->filesystem->dumpFile($target.'/packages.php', '<?php return '.var_export(array_reverse($autoload, true), true).';');
        foreach ([
            'AgentCore/Application/Pipeline/ToolExecutionEndPayloadCodec.php',
            'AgentCore/Contract/Tool/ToolPayloadStoreInterface.php',
            'AgentCore/Domain/Message/AbstractAgentBusMessage.php',
            'AgentCore/Domain/Message/ExecuteShellToolCall.php',
            'AgentCore/Domain/Message/ExecuteToolCall.php',
            'AgentCore/Domain/Message/ToolCallResult.php',
            'AgentCore/Domain/Run/PendingHumanInputRequestDTO.php',
            'AgentCore/Domain/Tool/DeferredToolCompletionCorrelation.php',
            'AgentCore/Domain/Tool/DTO/ToolPayloadDataDTO.php',
            'AgentCore/Domain/Tool/DTO/ToolPayloadReferenceDTO.php',
            'AgentCore/Domain/Tool/ToolResultText.php',
            'CodingAgent/Session/Exception/ToolPayloadException.php',
            'CodingAgent/Session/SessionToolPayloadStore.php',
            'CodingAgent/Session/ToolBatchRunStoragePathsInterface.php',
            'CodingAgent/Tool/CodeMode/CodeModeDiagnostics.php',
            'CodingAgent/Tool/CodeMode/CodeModeTerminalIdentity.php',
            'CodingAgent/Tool/CodeMode/CodeModeValueCodec.php',
            'CodingAgent/Tool/CodeMode/DTO/CodeModeCallRequestDTO.php',
            'CodingAgent/Tool/CodeMode/DTO/CodeModeReplyDTO.php',
            'CodingAgent/Tool/CodeMode/DTO/CodeModeTerminalReportDTO.php',
        ] as $file) {
            $this->filesystem->copy($root.'/src/'.$file, $target.'/src/'.$file);
        }
        $this->filesystem->copy(__DIR__.'/Resources/autoload.php.inc', $target.'/autoload.php');
    }
}
