<?php

declare(strict_types=1);

use Castor\Attribute\AsTask;
use CastorTasks\OwnedTemporaryDirectory;
use Ineersa\CodingAgent\Tests\Support\ExtensionAgentCassetteConnection;
use Symfony\Component\Yaml\Yaml;

use function CastorTasks\project_root_dir;
use function CastorTasks\qa_test_home_shell_prefix;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/shared.php';

#[AsTask(name: 'llm:cassette:record', description: 'Record the extension agent cassette privately using configured llama_cpp/flash')]
function llm_cassette_record(): void
{
    $root = project_root_dir();
    // Read the operator's settings before entering isolated QA HOME. Never write them.
    $connection = ExtensionAgentCassetteConnection::load($root, $root);

    \CastorTasks\initialize_standalone_qa_run();
    $output = OwnedTemporaryDirectory::allocate($root.'/var', 'llm-capture-'.bin2hex(random_bytes(8)), 0700);
    $output->assertOwned();
    $settings = $output->path.'/connection.yaml';
    file_put_contents($settings, Yaml::dump($connection));
    chmod($settings, 0600);
    try {
        $prefix = qa_test_home_shell_prefix().' APP_ENV=test ';
        $migrate = run_test_db_migrate_bounded($prefix.\PHP_BINARY.' bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration');
        if (0 !== $migrate['exitCode']) {
            throw new RuntimeException('Cassette test database migration failed: '.$migrate['output']);
        }
        $result = run_test_command_bounded(
            'llm-cassette-record',
            $prefix.'HATFIELD_PRIVATE_CASSETTE_DIR='.escapeshellarg($output->path).' '.\PHP_BINARY.' vendor/bin/phpunit --filter=ConfiguredModelAgentRunnerCassetteTest',
            60,
            $output->path.'/recording.log',
        );
        $cassette = $output->path.'/extension-agent-tool-drain.json';
        if (is_file($cassette)) {
            chmod($cassette, 0600);
        }
        if (0 !== $result['exitCode']) {
            throw new RuntimeException('Cassette recording failed (exit '.$result['exitCode'].'). Inspect the private capture; it was not promoted.');
        }
    } finally {
        unlink($settings);
        echo 'Private capture directory (not promoted): '.$output->path.\PHP_EOL;
    }
}
