<?php

namespace Ado\Command;

use Ado\Api\AzureDevOps;
use Ado\Config\Config;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'teams', description: 'List teams in a project')]
class TeamsCommand extends Command
{
    public function __construct(private Config $config, private AzureDevOps $api)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'Project override');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $project = $input->getOption('project') ?? $this->config->get('project');

        try {
            $teams = $this->api->getTeams($project);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->table(
            ['Name', 'Description'],
            array_map(fn($t) => [$t['name'], $t['description'] ?? ''], $teams)
        );

        return Command::SUCCESS;
    }
}
