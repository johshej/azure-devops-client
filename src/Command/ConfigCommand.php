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

#[AsCommand(name: 'config', description: 'Configure PAT, org, and default project')]
class ConfigCommand extends Command
{
    public function __construct(private Config $config)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('pat', null, InputOption::VALUE_REQUIRED, 'Personal Access Token')
            ->addOption('org', null, InputOption::VALUE_REQUIRED, 'Organization name')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Default project');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Azure DevOps CLI — Configuration');

        $pat = $input->getOption('pat')
            ?? $io->askHidden('PAT (Personal Access Token)', fn() => $this->config->get('pat', ''));
        $org = $input->getOption('org')
            ?? $io->ask('Organization name', $this->config->get('org', ''));

        $io->text("Testing connection to <info>https://dev.azure.com/{$org}</info> ...");

        $testConfig = new Config();
        $testConfig->set('org', $org);
        $testConfig->set('pat', $pat);

        try {
            $api = new AzureDevOps($testConfig);
            $projects = $api->getProjects();
            $projectNames = array_column($projects, 'name');
            $io->success('Connected! Found ' . count($projects) . ' project(s):');
            foreach ($projectNames as $name) {
                $io->text("  • {$name}");
            }
        } catch (\RuntimeException $e) {
            $io->error("Connection failed: {$e->getMessage()}");
            if (!$io->confirm('Save config anyway?', false)) {
                return Command::FAILURE;
            }
            $projectNames = [];
        }

        $defaultProject = $input->getOption('project') ?? $this->config->get('project', '');
        if (!$defaultProject && count($projectNames) === 1) {
            $defaultProject = $projectNames[0];
        } elseif (!$defaultProject && $projectNames) {
            $defaultProject = $io->choice('Default project', $projectNames, $projectNames[0]);
        }

        $this->config->set('pat', $pat);
        $this->config->set('org', $org);
        $this->config->set('project', $defaultProject);
        $this->config->save();

        $io->success('Config saved to ' . Config::configPath());

        return Command::SUCCESS;
    }
}
