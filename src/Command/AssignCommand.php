<?php

namespace Ado\Command;

use Ado\Api\AzureDevOps;
use Ado\Config\Config;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'assign', description: 'Assign a work item to a user')]
class AssignCommand extends Command
{
    public function __construct(private Config $config, private AzureDevOps $api)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Work item ID')
            ->addArgument('user', InputArgument::REQUIRED, 'User email or display name')
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'Project override');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $project = $input->getOption('project') ?? $this->config->get('project');
        $id = (int) $input->getArgument('id');
        $user = $input->getArgument('user');

        $patch = [
            ['op' => 'replace', 'path' => '/fields/System.AssignedTo', 'value' => $user],
        ];

        try {
            $this->api->updateWorkItem($project, $id, $patch);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success("#{$id} assigned to {$user}");
        return Command::SUCCESS;
    }
}
