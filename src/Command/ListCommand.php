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

#[AsCommand(name: 'ls', aliases: ['items'], description: 'List work items')]
class ListCommand extends Command
{
    private const FIELDS = [
        'System.Id',
        'System.Title',
        'System.WorkItemType',
        'System.State',
        'System.AssignedTo',
        'System.IterationPath',
        'Microsoft.VSTS.Common.Priority',
        'System.ChangedDate',
        'System.Parent',
    ];

    public function __construct(private Config $config, private AzureDevOps $api)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('mine', 'm', InputOption::VALUE_NONE, 'Only items assigned to me')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter by type (Task, Bug, "User Story"...)')
            ->addOption('state', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter by state')
            ->addOption('sprint', null, InputOption::VALUE_REQUIRED, 'Filter by sprint path or "current"')
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Search in title')
            ->addOption('area', 'a', InputOption::VALUE_REQUIRED, 'Filter by area path (e.g. "Soderberg Haak")')
            ->addOption('team', null, InputOption::VALUE_REQUIRED, 'Filter by team (uses the team\'s configured area paths)')
            ->addOption('group', 'g', InputOption::VALUE_NONE, 'Group results by parent')
            ->addOption('parent', null, InputOption::VALUE_REQUIRED, 'Only show children of this work item ID')
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'Project override')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max results', 50);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $project = $input->getOption('project') ?? $this->config->get('project');

        if (!$project) {
            $io->error('No project specified. Use --project or set a default with: ado config');
            return Command::FAILURE;
        }

        $conditions = ["[System.TeamProject] = '{$project}'"];

        if ($input->getOption('mine')) {
            $conditions[] = '[System.AssignedTo] = @Me';
        }

        if ($types = $input->getOption('type')) {
            $list = implode(', ', array_map(fn($t) => "'{$t}'", $types));
            $conditions[] = "[System.WorkItemType] IN ({$list})";
        }

        if ($states = $input->getOption('state')) {
            $list = implode(', ', array_map(fn($s) => "'{$s}'", $states));
            $conditions[] = "[System.State] IN ({$list})";
        }

        $team = $input->getOption('team');

        if ($sprint = $input->getOption('sprint')) {
            if (strtolower($sprint) === 'current') {
                if (!$team) {
                    $io->error('--sprint current requires --team <name>. Example: ado ls --sprint current --team Web');
                    return Command::FAILURE;
                }
                $conditions[] = "[System.IterationPath] = @CurrentIteration('[{$project}]\\{$team}')";
            } else {
                $conditions[] = "[System.IterationPath] UNDER '{$sprint}'";
            }
        }

        if ($search = $input->getOption('search')) {
            $conditions[] = "[System.Title] CONTAINS '{$search}'";
        }

        if ($parentId = $input->getOption('parent')) {
            $conditions[] = "[System.Parent] = {$parentId}";
        }

        if ($area = $input->getOption('area')) {
            $conditions[] = "[System.AreaPath] UNDER '{$project}\\{$area}'";
        }

        if ($team) {
            try {
                $areaPaths = $this->api->getTeamAreaPaths($project, $team);
            } catch (\RuntimeException $e) {
                $io->error("Could not load team area paths: {$e->getMessage()}");
                return Command::FAILURE;
            }

            if (empty($areaPaths)) {
                $io->error("Team '{$team}' not found or has no area paths configured.");
                return Command::FAILURE;
            }

            $areaClauses = array_map(function ($ap) {
                $op = ($ap['includeChildren'] ?? false) ? 'UNDER' : '=';
                return "[System.AreaPath] {$op} '{$ap['value']}'";
            }, $areaPaths);

            $conditions[] = '(' . implode(' OR ', $areaClauses) . ')';
        }

        $where = implode(' AND ', $conditions);
        $limit = (int) $input->getOption('limit');
        $wiql = "SELECT [System.Id] FROM WorkItems WHERE {$where} ORDER BY [System.ChangedDate] DESC";

        try {
            $ids = $this->api->queryWorkItems($project, $wiql, $limit);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if (empty($ids)) {
            $io->comment('No work items found.');
            return Command::SUCCESS;
        }

        if ($input->getOption('group')) {
            return $this->renderGrouped($io, $output, $project, $ids);
        }

        $items = $this->api->getWorkItemsBatch($project, $ids, self::FIELDS);

        $rows = array_map(function ($item) {
            $f = $item['fields'];
            return [
                $f['System.Id'] ?? '',
                $f['System.WorkItemType'] ?? '',
                $f['System.State'] ?? '',
                $f['Microsoft.VSTS.Common.Priority'] ?? '',
                $f['System.Title'] ?? '',
                $this->formatAssigned($f['System.AssignedTo'] ?? null),
                $this->formatDate($f['System.ChangedDate'] ?? ''),
            ];
        }, $items);

        $io->comment(count($items) . ' item(s)');
        $io->table(['ID', 'Type', 'State', 'Pri', 'Title', 'Assigned To', 'Changed'], $rows);

        return Command::SUCCESS;
    }

    private function renderGrouped(SymfonyStyle $io, OutputInterface $output, string $project, array $ids): int
    {
        // Fetch all items — System.Parent gives us the parent ID directly
        $items = $this->api->getWorkItemsBatch($project, $ids, self::FIELDS);

        // Index items by ID
        $byId = [];
        foreach ($items as $item) {
            $byId[$item['id']] = $item;
        }

        // Build parent map from System.Parent field
        $parentOf = []; // childId => parentId
        foreach ($items as $item) {
            $parentId = $item['fields']['System.Parent'] ?? null;
            if ($parentId) {
                $parentOf[$item['id']] = (int) $parentId;
            }
        }

        // Fetch any parents not already in the result set (e.g. not assigned to @Me)
        $missingParentIds = array_diff(array_unique(array_values($parentOf)), array_keys($byId));
        if ($missingParentIds) {
            $parents = $this->api->getWorkItemsBatch($project, $missingParentIds, self::FIELDS);
            foreach ($parents as $p) {
                $byId[$p['id']] = $p;
            }
        }

        // Group: items that have no parent in context, or whose parent is known
        $roots = []; // parent ID (or null) => [child ids]
        foreach ($items as $item) {
            $pid = $parentOf[$item['id']] ?? null;
            $roots[$pid ?? 'none'][] = $item['id'];
        }

        $output->writeln('');

        // Render orphans (no parent) first without a header
        if (!empty($roots['none'])) {
            foreach ($roots['none'] as $id) {
                $this->printItem($output, $byId[$id], false);
            }
        }

        // Render parent groups
        foreach ($roots as $parentId => $childIds) {
            if ($parentId === 'none') continue;

            if (isset($byId[$parentId])) {
                $this->printItem($output, $byId[$parentId], false);
            } else {
                $output->writeln("  <comment>#{$parentId}</comment>");
            }

            foreach ($childIds as $cid) {
                if (isset($byId[$cid])) {
                    $this->printItem($output, $byId[$cid], true);
                }
            }

            $output->writeln('');
        }

        return Command::SUCCESS;
    }

    private function printItem(OutputInterface $output, array $item, bool $indent): void
    {
        $f = $item['fields'];
        $id = $f['System.Id'] ?? $item['id'];
        $type = $f['System.WorkItemType'] ?? '';
        $state = $f['System.State'] ?? '';
        $title = $f['System.Title'] ?? '';
        $assigned = $this->formatAssigned($f['System.AssignedTo'] ?? null);

        $prefix = $indent ? '    ' : '';
        $idStr = "<comment>#{$id}</comment>";
        $typeStr = "<info>[{$type}]</info>";

        $output->writeln("{$prefix}{$idStr} {$typeStr} {$title}  <fg=gray>({$state}) — {$assigned}</>");
    }

    private function formatAssigned(mixed $assigned): string
    {
        if (!$assigned) return '—';
        if (is_array($assigned)) return $assigned['displayName'] ?? '';
        return (string) $assigned;
    }

    private function formatDate(string $date): string
    {
        if (!$date) return '';
        try {
            return (new \DateTime($date))->format('Y-m-d');
        } catch (\Exception) {
            return substr($date, 0, 10);
        }
    }
}
