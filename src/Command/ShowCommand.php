<?php

namespace Ado\Command;

use Ado\Api\AzureDevOps;
use Ado\Config\Config;
use Ado\Util\HtmlText;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'show', description: 'Show details of a work item')]
class ShowCommand extends Command
{
    public function __construct(private Config $config, private AzureDevOps $api)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Work item ID')
            ->addOption('no-comments', null, InputOption::VALUE_NONE, 'Skip loading comments')
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'Project override');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $project = $input->getOption('project') ?? $this->config->get('project');
        $id = (int) $input->getArgument('id');

        try {
            $item = $this->api->getWorkItemWithRelations($project, $id);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $f = $item['fields'];
        $url = $item['_links']['html']['href'] ?? '';

        $io->title("#{$id}: " . ($f['System.Title'] ?? ''));

        $io->definitionList(
            ['Type'      => $f['System.WorkItemType'] ?? ''],
            ['State'     => $f['System.State'] ?? ''],
            ['Priority'  => $f['Microsoft.VSTS.Common.Priority'] ?? ''],
            ['Assigned'  => $this->formatAssigned($f['System.AssignedTo'] ?? null)],
            ['Iteration' => $f['System.IterationPath'] ?? ''],
            ['Area'      => $f['System.AreaPath'] ?? ''],
            ['Created'   => $this->formatDate($f['System.CreatedDate'] ?? '') . '  ' . $this->formatAssigned($f['System.CreatedBy'] ?? null)],
            ['Changed'   => $this->formatDate($f['System.ChangedDate'] ?? '')],
        );

        if ($url) {
            $io->text("<href={$url}>{$url}</>");
        }

        // Parent
        $parentId = null;
        $childIds = [];
        foreach ($item['relations'] ?? [] as $rel) {
            $relId = $this->extractIdFromUrl($rel['url'] ?? '');
            if (!$relId) continue;
            if ($rel['rel'] === 'System.LinkTypes.Hierarchy-Reverse') {
                $parentId = $relId;
            } elseif ($rel['rel'] === 'System.LinkTypes.Hierarchy-Forward') {
                $childIds[] = $relId;
            }
        }

        if ($parentId) {
            $io->section('Parent');
            try {
                $parent = $this->api->getWorkItem($project, $parentId);
                $pf = $parent['fields'];
                $io->text(sprintf('  #%d  [%s]  %s  (%s)',
                    $parentId,
                    $pf['System.WorkItemType'] ?? '',
                    $pf['System.Title'] ?? '',
                    $pf['System.State'] ?? ''
                ));
            } catch (\RuntimeException) {
                $io->text("  #{$parentId}");
            }
        }

        // Children
        if ($childIds) {
            $io->section('Children (' . count($childIds) . ')');
            try {
                $children = $this->api->getWorkItemsBatch($project, $childIds, [
                    'System.Id', 'System.Title', 'System.WorkItemType', 'System.State', 'System.AssignedTo',
                ]);
                $rows = array_map(fn($c) => [
                    $c['fields']['System.Id'] ?? '',
                    $c['fields']['System.WorkItemType'] ?? '',
                    $c['fields']['System.State'] ?? '',
                    $c['fields']['System.Title'] ?? '',
                    $this->formatAssigned($c['fields']['System.AssignedTo'] ?? null),
                ], $children);
                $io->table(['ID', 'Type', 'State', 'Title', 'Assigned To'], $rows);
            } catch (\RuntimeException $e) {
                $io->text("  Could not load children: {$e->getMessage()}");
            }
        }

        // Description
        $desc = $f['System.Description'] ?? $f['Microsoft.VSTS.Common.AcceptanceCriteria'] ?? '';
        if ($desc) {
            $io->section('Description');
            $output->writeln(OutputFormatter::escape(HtmlText::convert($desc)));
        }

        // Comments
        if (!$input->getOption('no-comments')) {
            try {
                $comments = $this->api->getWorkItemComments($project, $id);
                if ($comments) {
                    $io->section('Comments (' . count($comments) . ')');
                    foreach ($comments as $comment) {
                        $author = OutputFormatter::escape($comment['createdBy']['displayName'] ?? '?');
                        $date = $this->formatDate($comment['createdDate'] ?? '');
                        $text = OutputFormatter::escape(HtmlText::convert($comment['text'] ?? '', 100));
                        $output->writeln("<comment>{$author}</comment>  <fg=gray>{$date}</>");
                        $output->writeln($text);
                        $output->writeln('');
                    }
                }
            } catch (\RuntimeException $e) {
                $io->comment("Could not load comments: {$e->getMessage()}");
            }
        }

        return Command::SUCCESS;
    }

    private function extractIdFromUrl(string $url): ?int
    {
        if (preg_match('/\/workItems\/(\d+)$/i', $url, $m)) {
            return (int) $m[1];
        }
        return null;
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
            return (new \DateTime($date))->format('Y-m-d H:i');
        } catch (\Exception) {
            return substr($date, 0, 16);
        }
    }
}
