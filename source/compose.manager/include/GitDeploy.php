<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/compose.manager/include/Defines.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/Util.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitPathGuard.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitStackSettings.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitStackState.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitClone.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitDeployCheck.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitCommand.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/DockerCommand.php';

/**
 * An automatic deploy that no longer applies once it holds the stack's lock
 * (GitDeploy::assertAutomaticDeployStillApplies()). Nothing was changed.
 */
final class GitDeploySkipped extends RuntimeException
{
}

/**
 * The git half of deploying a git stack. compose.sh calls this under the
 * stack's lock (scripts/git_stack.php), then pulls and starts the containers.
 *
 * prepare() fetches, checks out the new commit and runs every check. If
 * anything fails, it puts the previous commit back before it returns, so
 * nothing has changed. Only after it succeeds does compose.sh touch a
 * container.
 */
final class GitDeploy
{
    /**
     * A file in the stack folder that says local changes to files in the stack's
     * folder in the clone were discarded, and no deploy has succeeded since. The
     * containers may still hold the discarded files (a single-file bind mount keeps
     * the old copy), but no commit differs, so only this tells the next "up" to
     * recreate them.
     */
    public const DISCARDED_CHANGES_FILE = 'git_discarded_changes';

    /**
     * The exit status of git_stack.php prepare, and of compose.sh gitdeploy, when an
     * automatic deploy was skipped because it no longer applies (EX_TEMPFAIL: the
     * next scheduled check decides again). GIT_DEPLOY_EXIT_SKIPPED in common.sh.
     */
    public const EXIT_SKIPPED = 75;

    /** @var callable(string): void */
    private $say;

    /**
     * What the checks' prune changed in the plugin's override, for a dry run to put back:
     * the file, the content the prune read and the content it wrote. Null when the prune
     * changed nothing, or has not run.
     *
     * @var array{path: string, before: string, after: string}|null
     */
    private ?array $overridePrune = null;

    /**
     * @param string $stackDir The stack folder (holds git.json)
     * @param callable(string): void|null $say Receives progress messages for the person deploying
     */
    public function __construct(private readonly string $stackDir, ?callable $say = null)
    {
        $this->say = $say ?? static function (string $message): void {
        };
    }

    /**
     * For an automatic deploy (the scheduled check's), check again what the scheduled
     * check decided on before it started the deploy: things may have changed while
     * the deploy waited for the stack's lock, or while the check asked the remote.
     * compose.sh calls this under the stack's lock, before prepare(), so nothing can
     * change it again before the deploy is done.
     *
     * @param string $projectName The stack's compose project name
     * @param string|null $deployedWhenDecided The deployed commit the scheduled check compared
     *     the new commit with (null if none was deployed). Another commit deployed since (by
     *     hand, say, or a rollback) is newer than that decision: the automatic deploy would
     *     replace it with the commit the check compared, which may be older.
     * @throws GitDeploySkipped naming why the deploy no longer applies: automatic deploys are
     *     no longer set to Deploy, a deploy failed meanwhile, another commit was deployed
     *     meanwhile, the clone has local changes, the stack is not running (stopped
     *     meanwhile), or git or docker could not be asked
     */
    public function assertAutomaticDeployStillApplies(string $projectName, ?string $deployedWhenDecided): void
    {
        $settings = $this->loadSettings();
        if ($settings->autoDeploy !== GitStackSettings::AUTO_DEPLOY_DEPLOY) {
            throw new GitDeploySkipped('该栈的自动部署已不再设为“部署”，因此未执行部署。');
        }
        $state = GitStackState::load($this->stackDir);
        if ($state->failedCommit !== null) {
            throw new GitDeploySkipped(
                '提交 ' . substr($state->failedCommit, 0, 12) . ' 的部署失败，该栈的自动部署已暂停'
                . '，因此未执行部署。'
            );
        }
        if ($state->deployedCommit !== $deployedWhenDecided) {
            throw new GitDeploySkipped(
                '自定时检查决定部署后，已部署提交又发生了变化（其间有另一次部署运行），'
                . '因此该栈未自动部署。'
            );
        }
        // A question git or docker could not answer is a skip too, not a failure: the next scheduled
        // check asks again, as it does when it cannot ask them itself.
        $clone = new GitClone($settings);
        try {
            $hasLocalChanges = self::localChanges($clone, $state) !== [];
        } catch (RuntimeException $error) {
            throw new GitDeploySkipped(
                '无法读取克隆的本地改动（' . $error->getMessage() . '），因此该栈未自动部署。',
                0,
                $error
            );
        }
        if ($hasLocalChanges) {
            throw new GitDeploySkipped('克隆中存在本地改动，因此未自动部署该栈。');
        }
        try {
            $running = self::hasRunningContainers($projectName);
        } catch (RuntimeException $error) {
            throw new GitDeploySkipped($error->getMessage() . ' 因此该栈未自动部署。', 0, $error);
        }
        if (!$running) {
            throw new GitDeploySkipped('栈未运行，因此未自动部署。');
        }
    }

    /**
     * What in the clone a deploy would stop on without the option to save it: the
     * tracked files changed by hand, and a commit made in the clone by hand
     * (GitClone::isMadeByHand(), the test dealWithLocalChanges() uses).
     *
     * @return string[] Changed files, and a line naming a hand-made commit
     * @throws RuntimeException when git cannot be asked
     */
    public static function localChanges(GitClone $clone, GitStackState $state): array
    {
        $changes = $clone->locallyChangedFiles();
        $checkedOut = $clone->checkedOutCommit();
        if ($clone->isMadeByHand($checkedOut, $state->deployedCommit, $state->failedCommit)) {
            $changes[] = 'a commit made in the clone (' . substr($checkedOut, 0, 12) . ')';
        }
        return $changes;
    }

    /**
     * Whether any of a compose project's containers is running, as docker reports it. A
     * container that is restarting (crash-looping, say) or paused counts as running, as
     * it does for the image auto-update: docker ps without -a lists those too, and a
     * crash-looping stack is the one whose fix should be deployed.
     *
     * @throws RuntimeException when docker cannot be asked: not knowing is not "stopped"
     */
    public static function hasRunningContainers(string $projectName): bool
    {
        $result = DockerCommand::run([
            'ps', '-q', '--filter', 'label=com.docker.compose.project=' . $projectName,
        ]);
        if (!$result->succeeded()) {
            throw new RuntimeException("Could not ask docker whether '$projectName' is running: " . $result->errorSummary());
        }
        return trim($result->stdout) !== '';
    }

    /**
     * Fetch, check out and check the commit to deploy.
     *
     * @param string $projectName The stack's compose project name
     * @param string[] $profiles Compose profiles the deploy will use
     * @param string|null $commit A specific commit to deploy, or null for the branch's latest
     * @param bool $saveAndDiscardLocalChanges Save local changes as a patch, then discard them, instead of refusing
     * @return string The commit that was checked out before, to put back if the pull fails
     * @throws RuntimeException naming why nothing was deployed
     */
    public function prepare(string $projectName, array $profiles, ?string $commit, bool $saveAndDiscardLocalChanges): string
    {
        if (!GitPathGuard::isArrayStarted()) {
            throw new RuntimeException('阵列未启动，因此未部署任何内容。');
        }
        $settings = $this->loadSettings();
        $clone = new GitClone($settings);
        $clone->assertOwned();
        $state = GitStackState::load($this->stackDir);

        $previous = $clone->checkedOutCommit();

        // Fetch and check the target first: fetching changes only .git, so a
        // network failure or a removed compose file stops the deploy before
        // any local change is discarded.
        ($this->say)("Fetching {$settings->branch} from {$settings->url}...");
        $latest = $clone->fetch();
        $target = $commit ?? $latest;
        if (!GitClone::isCommitId($target)) {
            throw new RuntimeException("Not a full commit id: $target");
        }
        if (!$clone->composeFileExistsAt($target)) {
            throw new RuntimeException(
                "{$settings->composePath} does not exist at commit " . substr($target, 0, 12)
                . '. 它可能已在仓库中被移动或删除。未执行部署，栈保持原样。'
            );
        }

        $this->dealWithLocalChanges($clone, $state, $previous, $saveAndDiscardLocalChanges);

        try {
            if ($target !== $previous) {
                ($this->say)('正在检出 ' . substr($target, 0, 12) . '...');
                $clone->checkOut($target);
            }
            $this->runChecks($settings, $projectName, $profiles);
        } catch (Throwable $error) {
            throw $this->putBack($clone, $previous, $error);
        }

        return $previous;
    }

    /**
     * Try a deploy without deploying: fetch, check out the commit, run every check a
     * deploy runs before it pulls the images, and compare the compose configuration with
     * the one of the commit checked out before. Then check that commit out again. No
     * container is started, stopped or recreated, nothing is pulled or built, and the
     * stack's state is not changed.
     *
     * Like a deploy, it checks the commit out in the clone itself (the checks need the
     * files a clone holds that are not in the repository), so for as long as the checks
     * run a container that reads a folder of the clone sees the tried commit's files.
     *
     * The caller holds the stack's lock (GitStackManager::dryRun() takes it).
     *
     * The configuration is compared as "docker compose config --no-interpolate
     * --no-env-resolution" prints it: variables as they are written, not their values,
     * so the diff holds nothing from the .env or the env files. A change in a value
     * of the .env is not shown; the .env is not part of the repository anyway. The
     * problems are the deploy's own messages, with values filled in, as its log has them.
     *
     * The checks prune the plugin's override as a deploy's do (see
     * pruneOverrideServicesTheComposeFileNoLongerHas()). When the prune changed it, the
     * dry run puts back what the prune read, unless the override was saved again after
     * the prune (from the Labels tab, say), which it then leaves as it is. A save before
     * the prune is what the prune read, so it is kept either way.
     *
     * @param string $projectName The stack's compose project name
     * @param string[] $profiles Compose profiles the deploy would use
     * @param string|null $commit A specific commit to try, or null for the branch's latest
     * @return array{previousCommit: string, deployedCommit: ?string, targetCommit: string, problems: string[], recreatesEveryContainer: bool, configDiff: ?string, configProblem: ?string}
     *     previousCommit: the commit checked out before, which the configuration is compared with.
     *     deployedCommit: the last commit deployed successfully (it differs from previousCommit after a failed up).
     *     problems: what the checks found that would stop the deploy, empty when they all passed.
     *     recreatesEveryContainer: whether up would recreate every container (only worked out when nothing would stop it).
     *     configDiff: the change to the compose configuration as a unified diff, '' when there is none,
     *     null when it could not be worked out, with configProblem saying why.
     * @throws RuntimeException when the deploy would stop before its checks (the array is stopped, the
     *     fetch fails, the commit has no compose file, the clone has local changes), or when the commit
     *     checked out before could not be checked out again
     */
    public function dryRun(string $projectName, array $profiles, ?string $commit): array
    {
        if (!GitPathGuard::isArrayStarted()) {
            throw new RuntimeException('阵列未启动，因此未执行任何操作。');
        }
        $settings = $this->loadSettings();
        $clone = new GitClone($settings);
        $clone->assertOwned();
        $state = GitStackState::load($this->stackDir);

        $previous = $clone->checkedOutCommit();

        ($this->say)("Fetching {$settings->branch} from {$settings->url}...");
        $latest = $clone->fetch();
        $target = $commit ?? $latest;
        if (!GitClone::isCommitId($target)) {
            throw new RuntimeException("Not a full commit id: $target");
        }
        if (!$clone->composeFileExistsAt($target)) {
            throw new RuntimeException(
                "{$settings->composePath} does not exist at commit " . substr($target, 0, 12)
                . '. 它可能已在仓库中被移动或删除，因此部署会中止。'
            );
        }
        // A deploy without --save-local-changes stops on them, and the dry run cannot check
        // another commit out over them.
        $changedFiles = $clone->locallyChangedFiles();
        $madeByHand = $clone->isMadeByHand($previous, $state->deployedCommit, $state->failedCommit);
        if ($changedFiles !== [] || $madeByHand) {
            throw new RuntimeException(
                '克隆包含 ' . self::describeLocalChanges($changedFiles, $madeByHand, $previous) . '。除非将其保存为补丁并丢弃（--save-local-changes），否则部署会因此停止；而试运行无法'
                . ' 在改动之上'
                . ' 尝试另一个提交，因此什么都没做。'
            );
        }

        [$configBefore, $configBeforeProblem] = $this->composeConfig($projectName, $profiles);

        $result = null;
        $error = null;
        $this->overridePrune = null;
        try {
            if ($target !== $previous) {
                ($this->say)('正在检出 ' . substr($target, 0, 12) . '...');
                $clone->checkOut($target);
            }
            $problems = $this->findProblems($settings, $projectName, $profiles);
            [$configAfter, $configAfterProblem] = $this->composeConfig($projectName, $profiles);
            // A silent copy: upArguments() says what it decides, as if up were running.
            $recreates = $problems === [] && (new GitDeploy($this->stackDir))->upArguments($previous) !== [];

            $configDiff = null;
            $configProblem = null;
            if ($configBefore === null) {
                $configProblem = '提交 ' . substr($previous, 0, 12) . " 的 compose 配置无法读取：$configBeforeProblem";
            } elseif ($configAfter === null) {
                $configProblem = '提交 ' . substr($target, 0, 12) . " 的 compose 配置无法读取：$configAfterProblem";
            } else {
                try {
                    $configDiff = self::configDiff($configBefore, $configAfter, $previous, $target);
                } catch (RuntimeException $diffError) {
                    $configProblem = $diffError->getMessage();
                }
            }
            $result = [
                'previousCommit' => $previous,
                'deployedCommit' => $state->deployedCommit,
                'targetCommit' => $target,
                'problems' => $problems,
                'recreatesEveryContainer' => $recreates,
                'configDiff' => $configDiff,
                'configProblem' => $configProblem,
            ];
        } catch (Throwable $caught) {
            $error = $caught;
        }

        $putBackProblems = $this->putBackAfterDryRun($clone, $previous, $this->overridePrune);
        if ($putBackProblems !== []) {
            $message = ($error !== null ? $error->getMessage() . "\n" : '') . implode("\n", $putBackProblems);
            throw new RuntimeException($message, 0, $error);
        }
        if ($error !== null) {
            throw $error instanceof RuntimeException ? $error : new RuntimeException($error->getMessage(), 0, $error);
        }
        return $result;
    }

    /**
     * Extra arguments for "up" after prepare().
     *
     * Plain "up -d" recreates a container only when its compose definition
     * changed, so a commit that only edits a bind-mounted config file would
     * leave the containers on the old file. When the stack's setting is on and
     * anything in the stack's folder changed, the whole stack is recreated
     * (not restarted: a restart keeps a single-file mount on the old copy of
     * the file).
     *
     * "Changed" is measured from both the previously checked-out commit and
     * the last successfully deployed one. After a failed "up" the two differ,
     * and the running containers may hold either commit's files.
     *
     * @param string $previousCommit The commit prepare() returned
     * @return string[] ['--force-recreate'] or []
     */
    public function upArguments(string $previousCommit): array
    {
        $settings = $this->loadSettings();
        if (!$settings->recreateOnFolderChange) {
            return [];
        }
        $clone = new GitClone($settings);
        $current = $clone->checkedOutCommit();

        if (is_file($this->stackDir . '/' . self::DISCARDED_CHANGES_FILE)) {
            ($this->say)("栈文件夹中的本地改动已被丢弃，因此所有容器都会重建。");
            return ['--force-recreate'];
        }

        $compareFrom = [$previousCommit];
        $deployed = GitStackState::load($this->stackDir)->deployedCommit;
        if ($deployed !== null && $deployed !== $previousCommit) {
            $compareFrom[] = $deployed;
        }
        foreach ($compareFrom as $from) {
            if ($from !== $current && !$clone->hasCommit($from)) {
                // The branch was rewritten and git has since pruned the old
                // commit, so the change cannot be measured: assume one.
                ($this->say)('提交 ' . substr($from, 0, 12) . ' 已不在克隆中，因此所有容器都会重建。');
                return ['--force-recreate'];
            }
            if ($from !== $current && $clone->stackFilesChanged($from, $current)) {
                ($this->say)("栈文件夹中的文件已变更，因此所有容器都会重建。");
                return ['--force-recreate'];
            }
        }
        return [];
    }

    /**
     * Put the clone back at a commit (after a failed pull, before any container changed).
     */
    public function restore(string $commit): void
    {
        $clone = new GitClone($this->loadSettings());
        if ($clone->checkedOutCommit() !== $commit) {
            $clone->checkOut($commit);
        }
    }

    /**
     * Record how the deploy of the checked-out commit ended.
     */
    public function finish(bool $succeeded): void
    {
        $clone = new GitClone($this->loadSettings());
        $commit = $clone->checkedOutCommit();
        $state = GitStackState::load($this->stackDir);
        $newState = $succeeded
            ? new GitStackState($commit, null)
            : new GitStackState($state->deployedCommit, $commit);
        $newState->save($this->stackDir);
        if ($succeeded) {
            // The containers were just made from the clone as it is. If this cannot be
            // removed, every deploy recreates the containers until it is (it is in the
            // stack folder, and removing it by hand is safe).
            @unlink($this->stackDir . '/' . self::DISCARDED_CHANGES_FILE);
        }
    }

    /**
     * The -f and --env-file arguments for the stack as it is checked out now.
     *
     * @return string[]
     */
    public function composeArgs(): array
    {
        $composeRoot = dirname(rtrim($this->stackDir, '/'));
        $folder = basename(rtrim($this->stackDir, '/'));
        StackInfo::clearCache();
        $stack = StackInfo::fromProject($composeRoot, $folder);
        $built = $stack->buildComposeArgs();

        $args = [];
        foreach ($built['filePaths'] as $file) {
            $args[] = '-f';
            $args[] = $file;
        }
        if ($args === []) {
            throw new RuntimeException("No compose file was found for the stack in {$this->stackDir}.");
        }
        if (isset($built['envFilePath'])) {
            $args[] = '--env-file';
            $args[] = $built['envFilePath'];
        }
        return $args;
    }

    /**
     * The folder docker compose runs in for the deploy: the compose file's
     * folder, where the checks ran it too.
     */
    public function composeFolder(): string
    {
        $settings = $this->loadSettings();
        $composeFile = realpath($settings->composeFileInClone());
        if ($composeFile === false) {
            throw new RuntimeException("The compose file {$settings->composeFileInClone()} was not found.");
        }
        return dirname($composeFile);
    }

    /**
     * After a failed check or checkout, check the previous commit out again.
     * Returns the error to throw: the original problem, or, if putting the
     * commit back failed too, both, so nobody is told the clone is where it
     * was when it is not.
     */
    private function putBack(GitClone $clone, string $previous, Throwable $error): RuntimeException
    {
        try {
            if ($clone->checkedOutCommit() !== $previous) {
                ($this->say)('正在还原 ' . substr($previous, 0, 12) . '...');
                $clone->checkOut($previous);
            }
        } catch (Throwable $putBackError) {
            return new RuntimeException(
                $error->getMessage() . "\nThe previous commit " . substr($previous, 0, 12)
                . ' 无法再次检出（' . $putBackError->getMessage() . '). '
                . '没有容器被改动，但克隆并不在已部署的提交上。',
                0,
                $error
            );
        }
        return $error instanceof RuntimeException ? $error : new RuntimeException($error->getMessage(), 0, $error);
    }

    /**
     * After a dry run, check the commit from before out again and undo what the checks'
     * prune changed in the plugin's override.
     *
     * @param array{path: string, before: string, after: string}|null $overridePrune What the prune
     *     changed, or null when it changed nothing or did not run
     * @return string[] What could not be put back; empty when everything was
     */
    private function putBackAfterDryRun(GitClone $clone, string $previous, ?array $overridePrune): array
    {
        $problems = [];
        try {
            if ($clone->checkedOutCommit() !== $previous) {
                ($this->say)('正在还原 ' . substr($previous, 0, 12) . '...');
                $clone->checkOut($previous);
            }
        } catch (Throwable $error) {
            $problems[] = '提交 ' . substr($previous, 0, 12) . ' 无法再次检出（' . $error->getMessage()
                . '）。没有容器被改动，但克隆已不在原先位置：请部署该栈，或使用 compose-git reclone 重新克隆'
                . ' with compose-git reclone.';
        }
        if ($overridePrune === null) {
            // The dry run did not change the override: whatever changed it is someone else's save.
            return $problems;
        }
        if (@file_get_contents($overridePrune['path']) !== $overridePrune['after']) {
            // Saved again since the checks pruned it: that save is newer than what the prune read.
            ($this->say)("插件的覆盖文件在试运行期间被改动，因此保持当前状态。");
            return $problems;
        }
        if (file_put_contents($overridePrune['path'], $overridePrune['before']) !== strlen($overridePrune['before'])) {
            $problems[] = "The plugin's override {$overridePrune['path']} could not be put back as it was before the dry run.";
        } else {
            ($this->say)("已将插件的覆盖文件还原到试运行之前的状态。");
        }
        return $problems;
    }

    /**
     * The compose configuration of the commit checked out now, with variables as they
     * are written (see dryRun()).
     *
     * @param string[] $profiles
     * @return array{0: ?string, 1: ?string} The configuration, or null and why it could not be read
     */
    private function composeConfig(string $projectName, array $profiles): array
    {
        try {
            $args = $this->composeArgs();
            $folder = $this->composeFolder();
        } catch (RuntimeException $error) {
            return [null, $error->getMessage()];
        }
        foreach ($profiles as $profile) {
            $args[] = '--profile';
            $args[] = $profile;
        }
        $result = DockerCommand::run(
            array_merge(['compose'], $args, ['-p', $projectName, 'config', '--no-interpolate', '--no-env-resolution']),
            $folder
        );
        if (!$result->succeeded()) {
            return [null, trim($result->stderr !== '' ? $result->stderr : $result->errorSummary())];
        }
        return [$result->stdout, null];
    }

    /**
     * The change from one compose configuration to another, as a unified diff ('' when
     * there is none).
     *
     * @throws RuntimeException when git could not compare them
     */
    private static function configDiff(string $before, string $after, string $previousCommit, string $targetCommit): string
    {
        if ($before === $after) {
            return '';
        }
        $dir = sys_get_temp_dir() . '/compose-git-dry-run-' . bin2hex(random_bytes(4));
        if (!@mkdir($dir, 0700)) {
            throw new RuntimeException("无法创建用于比较 compose 配置的文件夹 {$dir}。");
        }
        $beforeName = 'before-' . substr($previousCommit, 0, 12) . '.yaml';
        $afterName = 'after-' . substr($targetCommit, 0, 12) . '.yaml';
        try {
            if (file_put_contents("$dir/$beforeName", $before) !== strlen($before)
                || file_put_contents("$dir/$afterName", $after) !== strlen($after)) {
                throw new RuntimeException("无法将 compose 配置写入 {$dir} 以进行比较。");
            }
            $diff = GitCommand::run(['diff', '--no-index', '--no-color', '--no-prefix', '--', $beforeName, $afterName], $dir);
            // git diff --no-index answers 1 when the files differ. It also answers 1, printing nothing, when it
            // could not read them; the two differ, so an empty answer means it could not compare them.
            if ($diff->exitCode !== 1 || trim($diff->stdout) === '') {
                throw new RuntimeException('Could not compare the compose configurations: ' . $diff->errorSummary());
            }
            return $diff->stdout;
        } finally {
            @unlink("$dir/$beforeName");
            @unlink("$dir/$afterName");
            @rmdir($dir);
        }
    }

    private function loadSettings(): GitStackSettings
    {
        $settings = GitStackSettings::load($this->stackDir);
        if ($settings === null) {
            throw new RuntimeException("{$this->stackDir} is not a git stack (it has no git.json).");
        }

        // compose is run with the file named in the stack's indirect file,
        // while the checks use git.json's compose path. If git.json was edited
        // by hand, the two can disagree, and the checks would pass for a file
        // that is not the one deployed.
        $indirectFile = rtrim($this->stackDir, '/') . '/indirect';
        $indirect = trim((string) @file_get_contents($indirectFile));
        if ($indirect !== $settings->composeFileInClone()) {
            throw new RuntimeException(
                "git.json 与该栈的 indirect 文件指向了不同的 compose 文件（"
                . $settings->composeFileInClone() . " and " . ($indirect === '' ? 'none' : $indirect) . "）。"
                . "请让 {$indirectFile} 指向第一个文件，然后重新部署。"
            );
        }
        return $settings;
    }

    /**
     * The clone's local changes in words: the changed files, or else the commit made by hand.
     *
     * @param string[] $changedFiles
     */
    private static function describeLocalChanges(array $changedFiles, bool $madeByHand, string $checkedOut): string
    {
        if ($changedFiles !== []) {
            return '本地改动：' . implode(', ', array_slice($changedFiles, 0, 5)) . (count($changedFiles) > 5 ? ', ...' : '');
        }
        return 'a commit made in the clone by hand (' . substr($checkedOut, 0, 12) . ')';
    }

    /**
     * Refuse when the clone has local changes, or save them as a patch and discard them.
     *
     * A commit made by hand in the clone counts as a local change too. Call
     * after fetch(), so the branch it is compared with is up to date.
     */
    private function dealWithLocalChanges(GitClone $clone, GitStackState $state, string $checkedOut, bool $saveAndDiscard): void
    {
        $changedFiles = $clone->locallyChangedFiles();
        $madeByHand = $clone->isMadeByHand($checkedOut, $state->deployedCommit, $state->failedCommit);
        if ($changedFiles === [] && !$madeByHand) {
            return;
        }

        $base = $checkedOut;
        if ($madeByHand) {
            // Save the hand-made commits too, from where they left the branch.
            $base = $clone->lastCommitSharedWithBranch($checkedOut) ?? $state->deployedCommit;
            if ($base === null) {
                throw new RuntimeException(
                    '克隆当前位于 ' . substr($checkedOut, 0, 12) . '，与分支 '
                    . "{$clone->settings()->branch} 没有共同历史，因此未执行部署。请用 compose-git reclone 重新克隆。"
                );
            }
        }

        if (!$saveAndDiscard) {
            $what = self::describeLocalChanges($changedFiles, $madeByHand, $checkedOut);
            throw new RuntimeException(
                "克隆包含 {$what}，因此未执行部署。"
                . '请使用「保存为补丁并丢弃」的选项重新部署，或手动撤销这些改动。'
            );
        }

        $clone->unstageAll();
        $clone->assertDiscardKeepsUntrackedFiles();
        $patch = $clone->saveChangesAsPatch($base, $this->stackDir);
        if ($patch !== null) {
            ($this->say)("已将本地改动保存到 {$patch}");
        }
        // Recorded before the discard, so a failure here leaves the changes in place.
        if ($this->anyInStackFolder($clone, $changedFiles)) {
            $this->recordDiscardedChanges();
        }
        $clone->discardLocalChanges();
    }

    /**
     * Whether any of these paths (relative to the repository) is in the stack's folder.
     *
     * @param string[] $paths
     */
    private function anyInStackFolder(GitClone $clone, array $paths): bool
    {
        $stackFolder = $clone->stackFolderInRepo();
        if ($stackFolder === '') {
            return $paths !== [];
        }
        foreach ($paths as $path) {
            if (str_starts_with($path, $stackFolder . '/')) {
                return true;
            }
        }
        return false;
    }

    private function recordDiscardedChanges(): void
    {
        $stackDir = rtrim($this->stackDir, '/');
        if (str_starts_with($stackDir, rtrim(COMPOSE_GIT_MNT_DIR, '/') . '/')) {
            GitPathGuard::assertSafeToWrite($stackDir);
        }
        $file = $stackDir . '/' . self::DISCARDED_CHANGES_FILE;
        $content = date('c') . "\n";
        if (file_put_contents($file, $content) !== strlen($content)) {
            throw new RuntimeException("无法写入 {$file}，因此本地改动未被丢弃。");
        }
    }

    /**
     * @param string[] $profiles
     * @throws RuntimeException listing every problem found
     */
    private function runChecks(GitStackSettings $settings, string $projectName, array $profiles): void
    {
        $problems = $this->findProblems($settings, $projectName, $profiles);
        if ($problems !== []) {
            throw new RuntimeException("该栈未部署：\n- " . implode("\n- ", $problems));
        }
    }

    /**
     * Run every check on the commit checked out now.
     *
     * @param string[] $profiles
     * @return string[] One message per problem found; empty when the stack can be deployed
     * @throws RuntimeException when the compose file leads outside the clone, so nothing else is checked
     */
    private function findProblems(GitStackSettings $settings, string $projectName, array $profiles): array
    {
        ($this->say)('部署前正在检查栈...');

        // The compose file must stay inside the clone, even through a symlink in the repository.
        $composeFile = realpath($settings->composeFileInClone());
        $cloneReal = realpath($settings->cloneDir);
        if ($composeFile === false || $cloneReal === false || !str_starts_with($composeFile, $cloneReal . '/')) {
            throw new RuntimeException("{$settings->composePath} leads outside the repository (through a symlink), so it was not used.");
        }

        $this->pruneOverrideServicesTheComposeFileNoLongerHas();

        $args = $this->composeArgs();
        foreach ($profiles as $profile) {
            $args[] = '--profile';
            $args[] = $profile;
        }
        $envFile = null;
        $envIndex = array_search('--env-file', $args, true);
        if ($envIndex !== false) {
            $envFile = $args[$envIndex + 1];
            // Say it: the stack folder's .env wins over the repository's, so
            // which one is used can change between two deploys.
            ($this->say)("正在使用 {$envFile} 处的 .env 文件");
        } else {
            $envFile = rtrim($this->stackDir, '/') . '/.env';
        }

        $pluginSettings = parse_plugin_cfg('compose.manager');
        $missingNetworksWillBeCreated = ($pluginSettings['CREATE_MISSING_EXTERNAL_NETWORKS'] ?? 'false') === 'true';

        $check = new GitDeployCheck(
            $projectName,
            $args,
            $settings->cloneDir,
            dirname($composeFile),
            $envFile,
            $missingNetworksWillBeCreated
        );
        $problems = $check->run();
        if ($problems !== []) {
            return $problems;
        }
        foreach ($check->foldersDockerWillCreate() as $folder) {
            ($this->say)("Docker 将创建缺失的文件夹 {$folder}");
        }
        return [];
    }

    /**
     * Remove services from the plugin-managed override that the new commit's
     * compose file no longer has, as the web UI does before every "up".
     *
     * An override entry for a renamed or removed service defines a service
     * with no image, and compose would refuse the whole stack over a file the
     * user did not write. The entries only hold the plugin's UI labels. If the
     * checks then fail and the previous commit is put back, the labels of a
     * removed service are not restored; they are only labels.
     */
    private function pruneOverrideServicesTheComposeFileNoLongerHas(): void
    {
        StackInfo::clearCache();
        $stack = StackInfo::fromProject(dirname(rtrim($this->stackDir, '/')), basename(rtrim($this->stackDir, '/')));
        $pruned = $stack->pruneOrphanOverrideServices();
        // For a dry run, which puts back what the prune read only if nothing saved the override since.
        $overridePath = $stack->overrideInfo->getProjectOverridePath();
        if ($pruned['changed'] && $overridePath !== null && isset($pruned['before'], $pruned['after'])) {
            $this->overridePrune = ['path' => $overridePath, 'before' => $pruned['before'], 'after' => $pruned['after']];
        }
        foreach ($pruned['removed'] as $service) {
            ($this->say)("Removed '$service' from the plugin's override: the compose file no longer has that service.");
        }
    }
}
