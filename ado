#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

use Ado\Api\AzureDevOps;
use Ado\Command;
use Ado\Config\Config;
use Symfony\Component\Console\Application;

$config = new Config();

$app = new Application('ado', '1.0.0');
$api = new AzureDevOps($config);

$app->addCommands([
    new Command\ConfigCommand($config),
    new Command\ProjectsCommand($config, $api),
    new Command\ListCommand($config, $api),
    new Command\ShowCommand($config, $api),
    new Command\CreateCommand($config, $api),
    new Command\UpdateCommand($config, $api),
    new Command\AssignCommand($config, $api),
    new Command\StatesCommand($config, $api),
    new Command\SprintsCommand($config, $api),
    new Command\TeamsCommand($config, $api),
]);

$app->run();
