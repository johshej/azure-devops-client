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

#[AsCommand(name: 'update', description: 'Update a work item')]
class UpdateCommand extends Command
{
    public function __construct(private Config $config, private AzureDevOps $api)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Work item ID')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'New title')
            ->addOption('state', 's', InputOption::VALUE_REQUIRED, 'New state')
            ->addOption('assigned-to', null, InputOption::VALUE_REQUIRED, 'Reassign to user')
            ->addOption('priority', null, InputOption::VALUE_REQUIRED, 'Priority (1-4)')
            ->addOption('description', 'd', InputOption::VALUE_REQUIRED, 'New description')
            ->addOption('iteration', null, InputOption::VALUE_REQUIRED, 'Move to iteration path')
            ->addOption('comment', 'c', InputOption::VALUE_REQUIRED, 'Add a comment')
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'Project override');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $project = $input->getOption('project') ?? $this->config->get('project');
        $id = (int) $input->getArgument('id');

        $patch = [];

        if ($title = $input->getOption('title')) {
            $patch[] = ['op' => 'replace', 'path' => '/fields/System.Title', 'value' => $title];
        }
        if ($state = $input->getOption('state')) {
            $patch[] = ['op' => 'replace', 'path' => '/fields/System.State', 'value' => $state];
        }
        if ($user = $input->getOption('assigned-to')) {
            $patch[] = ['op' => 'replace', 'path' => '/fields/System.AssignedTo', 'value' => $user];
        }
        if ($priority = $input->getOption('priority')) {
            $patch[] = ['op' => 'replace', 'path' => '/fields/Microsoft.VSTS.Common.Priority', 'value' => (int) $priority];
        }
        if ($desc = $input->getOption('description')) {
            $patch[] = ['op' => 'replace', 'path' => '/fields/System.Description', 'value' => $desc];
        }
        if ($iteration = $input->getOption('iteration')) {
            $patch[] = ['op' => 'replace', 'path' => '/fields/System.IterationPath', 'value' => $iteration];
        }
        if ($comment = $input->getOption('comment')) {
            $patch[] = ['op' => 'add', 'path' => '/fields/System.History', 'value' => $comment];
        }

        if (empty($patch)) {
            $io->warning('Nothing to update. Use --state, --title, --assigned-to, --priority, --comment, etc.');
            return Command::FAILURE;
        }

        try {
            $item = $this->api->updateWorkItem($project, $id, $patch);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $url = $item['_links']['html']['href'] ?? '';
        $io->success("Updated #{$id}");
        if ($url) {
            $io->text("<href={$url}>{$url}</>");
        }

        return Command::SUCCESS;
    }
}
