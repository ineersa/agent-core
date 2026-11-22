<?php

declare(strict_types=1);

require __DIR__.'/ClassLoader.php';
$loader = new Composer\Autoload\ClassLoader();
$loader->addPsr4('Ineersa\\AgentCore\\', __DIR__.'/src/AgentCore');
$loader->addPsr4('Ineersa\\CodingAgent\\', __DIR__.'/src/CodingAgent');
$packages = require __DIR__.'/packages.php';
foreach ($packages as $name => $autoload) {
    foreach ($autoload['psr-4'] ?? [] as $namespace => $paths) {
        foreach ((array) $paths as $path) {
            $loader->addPsr4($namespace, __DIR__.'/vendor/'.$name.'/'.$path);
        }
    }
}
$loader->register();
foreach ($packages as $name => $autoload) {
    foreach ($autoload['files'] ?? [] as $file) {
        require __DIR__.'/vendor/'.$name.'/'.$file;
    }
}
