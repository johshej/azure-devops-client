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

#[AsCommand(name: 'sprints', description: 'List sprints/iterations')]
class SprintsCommand extends Command
{
    public function __construct(private Config $config, private AzureDevOps $api)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('team', null, InputOption::VALUE_REQUIRED, 'Limit to a specific team\'s iterations')
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'Project override');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $project = $input->getOption('project') ?? $this->config->get('project');
        $team = $input->getOption('team') ?? '';

        try {
            $iterations = $team
                ? $this->api->getIterations($project, $team)
                : $this->api->getAllIterations($project);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $rows = array_map(function ($it) {
            $attr = $it['attributes'] ?? [];
            return [
                $it['name'],
                isset($attr['startDate']) ? substr($attr['startDate'], 0, 10) : '',
                isset($attr['finishDate']) ? substr($attr['finishDate'], 0, 10) : '',
                $it['path'] ?? '',
            ];
        }, $iterations);

        $io->table(['Sprint', 'Start', 'End', 'Path'], $rows);

        return Command::SUCCESS;
    }
}
