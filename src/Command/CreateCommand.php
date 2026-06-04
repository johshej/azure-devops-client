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

#[AsCommand(name: 'create', description: 'Create a work item')]
class CreateCommand extends Command
{
    public function __construct(private Config $config, private AzureDevOps $api)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Title')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Work item type', 'Task')
            ->addOption('description', 'd', InputOption::VALUE_REQUIRED, 'Description')
            ->addOption('assigned-to', null, InputOption::VALUE_REQUIRED, 'Assign to (email or display name)')
            ->addOption('priority', null, InputOption::VALUE_REQUIRED, 'Priority (1-4)')
            ->addOption('iteration', null, InputOption::VALUE_REQUIRED, 'Iteration/sprint path')
            ->addOption('area', null, InputOption::VALUE_REQUIRED, 'Area path')
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'Project override');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $project = $input->getOption('project') ?? $this->config->get('project');

        if (!$project) {
            $io->error('No project specified.');
            return Command::FAILURE;
        }

        $title = $input->getOption('title') ?? $io->ask('Title');
        $type = $input->getOption('type');

        $patch = [
            ['op' => 'add', 'path' => '/fields/System.Title', 'value' => $title],
        ];

        if ($desc = $input->getOption('description')) {
            $patch[] = ['op' => 'add', 'path' => '/fields/System.Description', 'value' => $desc];
        }
        if ($user = $input->getOption('assigned-to')) {
            $patch[] = ['op' => 'add', 'path' => '/fields/System.AssignedTo', 'value' => $user];
        }
        if ($priority = $input->getOption('priority')) {
            $patch[] = ['op' => 'add', 'path' => '/fields/Microsoft.VSTS.Common.Priority', 'value' => (int) $priority];
        }
        if ($iteration = $input->getOption('iteration')) {
            $patch[] = ['op' => 'add', 'path' => '/fields/System.IterationPath', 'value' => $iteration];
        }
        if ($area = $input->getOption('area')) {
            $patch[] = ['op' => 'add', 'path' => '/fields/System.AreaPath', 'value' => $area];
        }

        try {
            $item = $this->api->createWorkItem($project, $type, $patch);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $id = $item['id'];
        $url = $item['_links']['html']['href'] ?? '';
        $io->success("Created #{$id}: {$title}");
        if ($url) {
            $io->text("<href={$url}>{$url}</>");
        }

        return Command::SUCCESS;
    }
}
