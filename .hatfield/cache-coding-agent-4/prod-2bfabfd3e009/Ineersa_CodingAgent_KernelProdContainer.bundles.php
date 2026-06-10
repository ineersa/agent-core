<?php

return [
    'ServicesBundle' => new \Symfony\Component\DependencyInjection\Kernel\ServicesBundle(),
    'ConsoleBundle' => new \Symfony\Component\Console\ConsoleBundle(),
    'FrameworkBundle' => new \Symfony\Bundle\FrameworkBundle\FrameworkBundle(),
    'MonologBundle' => new \Symfony\Bundle\MonologBundle\MonologBundle(),
    'DoctrineBundle' => new \Doctrine\Bundle\DoctrineBundle\DoctrineBundle(),
    'DoctrineMigrationsBundle' => new \Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle(),
];
