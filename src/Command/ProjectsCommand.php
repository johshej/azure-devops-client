<?php

namespace Ado\Command;

use Ado\Api\AzureDevOps;
use Ado\Config\Config;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'projects', description: 'List projects in the organization')]
class ProjectsCommand extends Command
{
    public function __construct(private Config $config, private AzureDevOps $api)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projects = $this->api->getProjects();

        $io->table(
            ['Name', 'State', 'Description'],
            array_map(fn($p) => [
                $p['name'],
                $p['state'] ?? '',
                $p['description'] ?? '',
            ], $projects)
        );

        return Command::SUCCESS;
    }
}
