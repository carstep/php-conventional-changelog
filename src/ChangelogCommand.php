<?php

namespace ConventionalChangelog;

use DateTime;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ChangelogCommand extends Command
{
    /**
     * Changelog filename.
     *
     * @var string
     */
    public static $fileName = 'CHANGELOG.md';

    /**
     * Types allowed on changelog and labels (preserve the order).
     *
     * @var string[][]
     */
    public static $types = [
        'feat' => ['code' => 'feat', 'label' => 'Features'],
        'fix' => ['code' => 'fix', 'label' => 'Fixes'],
        'perf' => ['code' => 'perf', 'label' => 'Performance Features'],
        'refactor' => ['code' => 'refactor', 'label' => 'Refactoring'],
        'docs' => ['code' => 'docs', 'label' => 'Docs'],
        'chore' => ['code' => 'chore', 'label' => 'Chores'],
    ];

    /**
     * Changelog pattern.
     *
     * @var string
     */
    public static $header = "# Changelog\nAll notable changes to this project will be documented in this file.\n\n\n";

    /**
     * Ignore message commit patterns.
     *
     * @var string[]
     */
    public static $ignorePatterns = [
        '/^chore\(release\):/i',
    ];

    /**
     * Configure.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('changelog')
            ->setDescription('Generate changelogs and release notes from a project\'s commit messages' .
                'and metadata and automate versioning with semver.org and conventionalcommits.org')
            ->setDefinition([
                new InputArgument('path', InputArgument::OPTIONAL, 'Define the path directory where generate changelog', getcwd()),
                new InputOption('commit', 'c', InputOption::VALUE_NONE, 'Commit the new release once changelog is generated'),
                new InputOption('first-release', null, InputOption::VALUE_NONE, 'Run at first release (if --ver isn\'t specified version code it will be 1.0.0)'),
                new InputOption('from-date', null, InputOption::VALUE_REQUIRED, 'Get commits from specified date [YYYY-MM-DD]'),
                new InputOption('to-date', null, InputOption::VALUE_REQUIRED, 'Get commits last tag date (or specified on --from-date) to specified date [YYYY-MM-DD]'),
                new InputOption('major', 'maj', InputOption::VALUE_NONE, 'Major release (important changes)'),
                new InputOption('minor', 'min', InputOption::VALUE_NONE, 'Minor release (add functionality)'),
                new InputOption('patch', 'p', InputOption::VALUE_NONE, 'Patch release (bug fixes) [default]'),
                new InputOption('ver', null, InputOption::VALUE_REQUIRED, 'Define the next release version code (semver)'),
            ]);
    }

    /**
     * Execute command.
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $root = $input->getArgument('path'); // Root

        $autoCommit = $input->getOption('commit'); // Commit once changelog is generated
        $fromDate = $input->getOption('from-date');
        $toDate = $input->getOption('to-date');

        $firstRelease = $input->getOption('first-release');
        $patchRelease = $input->getOption('patch');
        $minorRelease = $input->getOption('minor');
        $majorRelease = $input->getOption('major');

        // Current Dates
        $today = new DateTime();
        $todayString = Utils::getDateString($today);

        // First commit
        $firstCommit = Git::getFirstCommit();

        if (!$firstRelease) {
            $lastVersion = Git::getLastTag(); // Last version
            $lastVersionCommit = Git::getLastTagCommit(); // Last version commit
            $lastVersionDate = Git::getCommitDate($lastVersionCommit); // Last version date

            // Generate new version code
            $newVersion = Utils::bumpVersion($lastVersion, $majorRelease, $minorRelease, $patchRelease);
        }

        $nextVersion = $input->getOption('ver');
        if (!empty($nextVersion)) {
            $newVersion = $nextVersion;
        }
        $newVersion = preg_replace('#^v#i', '', $newVersion);

        if (empty($newVersion)) {
            $newVersion = '1.0.0';
        }

        // Remote url
        $url = Git::getRemoteUrl();

        if ($firstRelease) {
            // Get all commits from the first one
            $additionalParams = "{$firstCommit}..HEAD";
            $lastVersion = $firstCommit;
        } else {
            // Get latest commits from last version date
            $additionalParams = "{$lastVersion}..HEAD";
        }

        if (!empty($fromDate) ||
            !empty($toDate)) {
            $additionalParams = '';
            if (!empty($fromDate)) {
                $additionalParams .= ' --since="' . date('Y-m-d', strtotime($fromDate)) . '"';
            }
            if (!empty($toDate)) {
                $time = strtotime($toDate);
                $additionalParams .= ' --before="' . date('Y-m-d', $time) . '"';
                $today->setTimestamp($time);
                $todayString = Utils::getDateString($today);
            }
        }

        $commitsRaw = Git::getCommits($additionalParams);

        // Get all commits information
        $commits = [];
        foreach ($commitsRaw as $commit) {
            $rows = explode("\n", $commit);
            $count = count($rows);
            // Commit info
            $head = Utils::clean($rows[0]);
            $sha = Utils::clean($rows[$count - 1]);
            $message = '';
            // Get message
            for ($i = 0; $i < $count; $i++) {
                $row = $rows[$i];
                if ($i !== 0 && $i !== $count - 1) {
                    $message .= $row . "\n";
                }
            }
            // Check ignored commit
            $ignore = false;
            foreach (self::$ignorePatterns as $pattern) {
                if (preg_match($pattern, $head)) {
                    $ignore = true;
                    break;
                }
            }
            // Add commit
            if (!empty($sha) && !$ignore) {
                $commits[] = [
                    'sha' => $sha,
                    'head' => $head,
                    'message' => Utils::clean($message),
                ];
            }
        }

        // Changes groups
        $changes = [];
        foreach (self::$types as $key => $type) {
            $changes[$key] = [];
        }

        // Group all changes to lists by type
        foreach ($commits as $commit) {
            foreach (self::$types as $key => $type) {
                $head = Utils::clean($commit['head']);
                $code = preg_quote($type['code'], '/');
                if (preg_match('/^' . $code . '(\(.*?\))?[:]?\\s/i', $head)) {
                    $parse = $this->parseCommitHead($head, $type['code']);
                    $context = $parse['context'];
                    $description = $parse['description'];
                    $sha = $commit['sha'];
                    $short = substr($sha, 0, 6);
                    // List item
                    $itemKey = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '', $description));
                    $changes[$key][$context][$itemKey][$sha] = [
                        'description' => $description,
                        'short' => $short,
                        'url' => $url,
                        'sha' => $sha,
                    ];
                }
            }
        }

        // File
        $file = $root . DIRECTORY_SEPARATOR . self::$fileName;

        // Initialize changelogs
        $changelogCurrent = '';
        $changelogNew = "## [{$newVersion}]($url/compare/{$lastVersion}...v{$newVersion}) ({$today->format('Y-m-d')})\n\n";

        // Get changelogs content
        if (file_exists($file)) {
            $header = ltrim(self::$header);
            $header = preg_quote($header, '/');
            $changelogCurrent = file_get_contents($file);
            $changelogCurrent = ltrim($changelogCurrent);
            $changelogCurrent = preg_replace("/^$header/i", '', $changelogCurrent);
        }

        // Add all changes list to new changelog
        $changelogNew .= $this->getMarkdownChanges($changes);

        $io = new SymfonyStyle($input, $output);

        // Save new changelog prepending the current one
        file_put_contents($file, self::$header . "{$changelogNew}{$changelogCurrent}");
        $io->success("Changelog generated to: {$file}");

        // Create commit and add tag
        if ($autoCommit) {
            system("git commit -m \"chore(release): {$newVersion}\"");
            system("git tag v{$newVersion}");
            $output->success("Committed new version with tag: v{$newVersion}");
        }

        return Command::SUCCESS;
    }

    /**
     * Generate markdown from changes.
     *
     * @param $changes
     *
     * @return string
     */
    protected function getMarkdownChanges($changes)
    {
        $changelog = '';
        // Add all changes list to new changelog
        foreach ($changes as $type => $list) {
            if (empty($list)) {
                continue;
            }
            ksort($list);
            $changelog .= PHP_EOL . '### ' . self::$types[$type]['label'] . "\n\n";
            foreach ($list as $context => $items) {
                asort($items);
                if (is_string($context) && !empty($context)) {
                    // Context section
                    $changelog .= PHP_EOL . "##### {$context}" . "\n\n";
                }
                foreach ($items as $itemsList) {
                    $description = '';
                    $sha = '';
                    $shaGroup = [];
                    foreach ($itemsList as $item) {
                        $description = $item['description'];
                        if (!empty($item['sha'])) {
                            $shaGroup[] = "[{$item['short']}]({$item['url']}/commit/{$item['sha']})";
                        }
                    }
                    if (!empty($shaGroup)) {
                        $sha = '(' . implode(', ', $shaGroup) . ')';
                    }
                    $changelog .= "* {$description} {$sha}\n";
                }
            }
        }
        // Add version separator
        $changelog .= PHP_EOL . '---' . "\n\n";

        return $changelog;
    }

    /**
     * Parse conventional commit head.
     *
     * @param string $message
     * @param string $type
     *
     * @return array
     */
    protected function parseCommitHead($head, $type)
    {
        $parse = [
            'context' => null,
            'description' => Utils::clean($head),
        ];

        $descriptionType = preg_quote(substr($parse['description'], 0, strlen($type)), '/');
        $parse['description'] = preg_replace('/^' . $descriptionType . '[:]?\s*/i', '', $parse['description']);
        $parse['description'] = preg_replace('/^\((.*?)\)[!]?[:]?\s*/', '**$1**: ', Utils::clean($parse['description']));
        $parse['description'] = preg_replace('/\s+/m', ' ', $parse['description']);

        // Set context
        if (preg_match("/^\*\*(.*?)\*\*:(.*?)$/", $parse['description'], $match)) {
            $parse['context'] = Utils::clean($match[1]);
            $parse['description'] = Utils::clean($match[2]);

            // Unify context labels
            $parse['context'] = preg_replace('/[_]+/m', ' ', $parse['context']);
            $parse['context'] = ucfirst($parse['context']);
            $parse['context'] = preg_replace('/((?<=\p{Ll})\p{Lu})|((?!\A)\p{Lu}(?>\p{Ll}))/u', ' $0', $parse['context']);
            $parse['context'] = preg_replace('/\.(php|md|json|txt|csv)($|\s)/', '', $parse['context']);
            $parse['context'] = Utils::clean($parse['context']);
        }

        $parse['description'] = ucfirst($parse['description']);

        return $parse;
    }
}