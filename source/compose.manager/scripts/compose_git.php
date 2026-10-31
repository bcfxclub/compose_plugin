<?php

/**
 * compose-git: create, deploy and look after git-backed stacks from the command line.
 *
 * Run it as scripts/compose-git (a small wrapper around this file). Stacks are
 * named by their exact folder in the projects folder. The exit status is the
 * result, so scripts and git hooks can use it:
 *   0  success (for check: the deployed commit is the branch's latest)
 *   1  failed; the message says what changed, if anything
 *   2  the command line was not understood
 *   3  check only: the branch has a newer commit that changes the stack's folder
 *   4  check only: the branch has a newer commit, but it changes nothing in the stack's folder
 */

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/compose.manager/include/GitStackManager.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitScheduledCheck.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/CronManager.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/Helpers.php';

const COMPOSE_GIT_EXIT_OK = 0;
const COMPOSE_GIT_EXIT_FAILED = 1;
const COMPOSE_GIT_EXIT_USAGE = 2;
const COMPOSE_GIT_EXIT_BEHIND = 3;
const COMPOSE_GIT_EXIT_NEWER_ELSEWHERE = 4;

/** The command line itself was not understood (as opposed to a value that was refused). */
final class ComposeGitUsageError extends InvalidArgumentException
{
}

const COMPOSE_GIT_USAGE = <<<'TEXT'
用法：
  compose-git add <name> --url <url> --path <compose file> [--branch <branch>]
              [--clones-root <folder>] [--description <text>] [--credential <name>]
  compose-git convert <stack> --url <url> --path <compose file> [--branch <branch>]
              [--clones-root <folder>] [--credential <name>]
  compose-git credential <stack> <name>|--none
  compose-git auto-deploy <stack> off|notify|deploy
  compose-git resume <stack>
  compose-git deploy-key <stack>
  compose-git trust-host <stack>
  compose-git check <stack>|--all
  compose-git deploy <stack> [--commit <提交 ID>] [--save-local-changes] [--wait|--no-wait]
              [--wait-timeout <秒数>] [--profile <名称>]...
  compose-git deploy <stack> --dry-run [--commit <提交 ID>] [--profile <名称>]...
  compose-git reclone <stack>
  compose-git status [<stack>|--all] [--json]
  compose-git scheduled-check

<url> 可以是 https 地址（不含用户名和密码）、ssh 地址
（ssh://git@host/path 或 git@host:path），或 /mnt 下某个仓库的路
径。
<compose file> 是 compose 文件在仓库内的路径，例如
stacks/whoami/compose.yaml。
--branch 默认为 main。<stack> 是栈的文件夹名，需完全一致。
--credential 指定插件“凭据”页中的一个 git 凭据（HTTPS 令牌），用
于私有仓库；对已有栈使用 credential 命令可更换或移除该凭据。
ssh 栈在添加时会获得一个部署密钥，每个仓库一个：同一仓库的第二个
栈沿用第一个栈的密钥。deploy-key 可再次显示该密钥，以便作为只读
部署密钥添加到仓库。添加时会固定其主机的密钥；服务器密钥变更后，
用 trust-host 重新固定。
栈的“等待健康检查”设置要求等待时，deploy 会等待容器健康；--wait
与 --no-wait 可覆盖该设置。
auto-deploy 设置计划检查在新提交改动栈时做什么：不处理（off，默
认）、发送通知（notify）、或直接部署（deploy）。某次部署失败后，
计划检查会暂时放过该栈，直到有一次部署成功；resume 可清除该失败
状态。
scheduled-check 立即执行一次计划检查（只要有栈需要，cron 每 15
分钟运行一次）。

退出状态：0 成功，1 失败，2 用法错误；对 check 而言，3 表示较新
的提交改动了栈的文件夹，4 表示较新的提交只改动了该文件夹之外的文
件。详见 'compose-git check --help'。

运行 'compose-git <command> --help' 可查看某条命令的作用，以及各
选项的含义。
TEXT;

/**
 * The detailed help for one command, or null if there is no such command.
 */
function compose_git_command_help(string $command): ?string
{
    return match ($command) {
        'add' => <<<'TEXT'
用法：compose-git add <name> --url <url> --path <compose file> [选项]

从仓库创建一个新的 git 栈：先把仓库克隆到阵列上，再新建一个栈文
件夹指向克隆中的 compose 文件。此时尚未部署任何内容，接下来请运
行 'compose-git deploy <stack>'。如果克隆失败，或该分支在 --path
处没有 compose 文件，则不会创建任何内容。

<name> 是栈的名称。其文件夹名会把不安全的字符替换掉，并在最后打
印出来。该名称的栈文件夹必须尚不存在；若要把已有栈纳入 git 管
理，请使用 'compose-git convert'。

选项：
  --url <url>
      仓库地址：不含用户名和密码的 https:// 地址、ssh 地址
      （ssh://git@host[:port]/path 或 git@host:path），或本服务器上
      /mnt 下某个仓库的路径。必填。ssh 栈会获得该仓库的部署密钥（为
      仓库的第一个栈创建），并固定该主机的 ssh 密钥。
  --path <compose file>
      compose 文件在仓库内的路径，例如 stacks/whoami/compose.yaml。
      必填。
  --branch <branch>
      要跟随的分支。默认：main。
  --clones-root <folder>
      克隆存放的文件夹，形式为 <stack>-<id>。必须位于某个共享文件
      夹、磁盘或存储池上，且该共享文件夹必须已存在。默认：
      /mnt/user/appdata/compose.manager/git。
  --description <text>
      Compose 页面上该栈显示的说明文字。
  --credential <name>
      插件“凭据”页中的 git 凭据（HTTPS 令牌），按名称或 ID 指定，用
      于私有仓库。

示例：
  compose-git add whoami --url https://github.com/you/stacks.git --path whoami/compose.yaml
TEXT,
        'convert' => <<<'TEXT'
用法：compose-git convert <stack> --url <url> --path <compose file> [选项]

把一个已有的栈纳入 git 管理。请先把它的 compose 文件复制进仓库并
推送。之后克隆仓库，并让栈指向克隆中的 compose 文件。

栈会保留名称、项目名、.env、覆盖文件与各项设置，因此其数据卷和网
络也保持不变。它原来的 compose 文件，以及原有的 indirect 设置
（如有），会被移动到栈文件夹下的 pre-git-<date> 文件夹中：这就是
回退的办法。正在运行的容器在下次部署前不受影响。原 compose 文件
旁边的文件夹会被列出来作为提醒，因为像 ./data 这样的相对绑定挂
载，现在指向的是克隆内部。

如果克隆失败，或该分支在 --path 处没有 compose 文件，则不做任何
改动。当该栈上还有其他操作正在运行时，它会拒绝启动。

选项：
  --url <url>
      仓库地址：不含用户名和密码的 https:// 地址、ssh 地址
      （ssh://git@host[:port]/path 或 git@host:path），或本服务器上
      /mnt 下某个仓库的路径。必填。ssh 栈会获得该仓库的部署密钥（为
      仓库的第一个栈创建），并固定该主机的 ssh 密钥。
  --path <compose file>
      compose 文件在仓库内的路径。必填。
  --branch <branch>
      要跟随的分支。默认：main。
  --clones-root <folder>
      克隆存放的文件夹，形式为 <stack>-<id>。必须位于某个共享文件
      夹、磁盘或存储池上，且该共享文件夹必须已存在。默认：
      /mnt/user/appdata/compose.manager/git。
  --credential <name>
      插件“凭据”页中的 git 凭据（HTTPS 令牌），按名称或 ID 指定，用
      于私有仓库。

然后部署它：compose-git deploy <stack>
TEXT,
        'credential' => <<<'TEXT'
用法：compose-git credential <stack> <name>
       compose-git credential <stack> --none

更换或移除栈访问其仓库所用的 git 凭据。<name> 是插件“凭据”页中的
git 凭据（HTTPS 令牌），按名称或 ID 指定。会先用新设置访问仓库，
只有成功才会保存。不会触碰任何容器。

ssh 栈始终有一个部署密钥。<name> 只能是同一仓库的另一个部署密
钥：即该仓库其他栈正在使用的那个，或为该仓库创建的那个。这样可以
把各自拥有密钥的多个栈（在仓库的多个栈共用密钥之前）合并到一个密
钥上；旧密钥仍留在“凭据”页中。

选项：
  --none
      不使用凭据访问仓库，适用于公开仓库。
TEXT,
        'deploy-key' => <<<'TEXT'
用法：compose-git deploy-key <stack>

打印 ssh 栈部署密钥的公钥部分，以便作为只读部署密钥添加到仓库
（在 GitHub 上：仓库的 Settings → Deploy keys）。该密钥在仓库的
第一个栈被添加时创建，由该仓库的所有 ssh 栈共用，并保存在插件的
凭据保险库中。此命令不做任何改动。
TEXT,
        'trust-host' => <<<'TEXT'
用法：compose-git trust-host <stack>

重新固定 ssh 栈所属仓库服务器的密钥，用于服务器重建或密钥被有意
更换之后。在此之前每次拉取都会失败，因为主机密钥发生变化，同样也
是中间人攻击的特征。命令会打印已固定的指纹和对方提供的指纹：部署
前请把新指纹与你的 git 主机公布的指纹做比对。只有用新密钥成功访
问仓库之后，才会保存它们。不会触碰任何容器。
TEXT,
        'check' => <<<'TEXT'
用法：compose-git check <stack>
       compose-git check --all

向仓库询问栈所在分支上的最新提交，并与已部署的提交比较。若有更新
的提交，会把它拉取到克隆中，查看它改动了什么。不会改动任何容器，
也不会改动任何已检出的文件。

栈的文件夹是指仓库中存放其 compose 文件的文件夹（当 compose 文件
位于顶层时，即为整个仓库）。若较新的提交没有改动该文件夹中的任何
内容，会单独报告（退出码 4）：在包含多个栈的仓库中，这通常是针对
另一个栈的提交。但栈也可能用到其文件夹之外的文件：如指向
../shared 的绑定挂载、为 .. 的构建上下文、另一个文件夹中的
env_file、extends 或 include。对这些文件的改动不会被视作对栈的改
动，因此当栈用到它们时请照常部署。

选项：
  --all
      检查所有 git 栈，每个一行。

退出状态：
  0   已是最新
  1   无法读取该栈或仓库（或该栈上还有其他操作正在运行）
  3   较新的提交改动了栈的文件夹，或其改动无法判断（尚未部署任
      何内容，或已部署的提交已不在克隆中）；可用 'compose-git
      deploy <stack>' 部署它
  4   分支上有较新的提交，但它只改动了栈文件夹之外的文件
使用 --all 时：只要有栈的文件夹发生变化就返回 3，即使另一个栈无
法检查；否则若有栈无法检查则返回 1；否则若有栈在文件夹之外有较新
提交则返回 4。若脚本需要在分支变动时就部署，可把 4 当作 3 处理。
TEXT,
        'deploy' => <<<'TEXT'
用法：compose-git deploy <stack> [选项]

部署栈所在分支上的最新提交，或 --commit 指定的提交。顺序为：拉取
该提交、在克隆中检出、执行检查、拉取并构建镜像，然后运行 docker
compose up。以下情况检查会中止部署：compose 文件无效、它使用的某
个变量未设置、仓库 .env.example 中的某个名称在 .env 中缺失、某个
外部网络或数据卷、绑定挂载文件夹、config 或 secret 文件、构建文
件夹不存在，或某个容器名或端口已被占用。

如果 up 之前的任何一步失败，会还原到上一个提交，且不改动任何容
器。如果 up 本身失败，该提交会被记录为失败（'compose-git status'
会显示），且不做任何回滚：请修复原因（通常在仓库中），然后再次部
署。

当提交改动了仓库中该栈文件夹内的任何内容时，栈内的所有容器都会被
重建，以便改动后的配置文件生效。要关闭该行为，请在栈的 git.json
中设置 "recreateOnFolderChange": false。

若该栈上还有其他操作正在运行（例如从 Web 界面点启动），它会等待
最多 30 秒（COMPOSE_LOCK_TIMEOUT）让其结束，然后失败，并且不做任
何改动。

选项：
  --commit <提交 ID>
      部署该提交而不是最新的提交，例如回退到较早的版本。请给出完整
      ID（40 个字符，SHA-256 仓库为 64 个）。之后不带 --commit 的部
      署会回到分支上的最新提交。
  --save-local-changes
      如果克隆中被跟踪的文件有改动，或克隆中有人手工创建了提交，就把
      这些改动保存为栈文件夹下 git-changes 文件夹中的补丁，然后丢弃
      它们，再部署。不加该选项时，部署会中止并列出被改动的文件。无论
      哪种情况，不属于仓库的文件都不会被触碰。
  --wait
      等待容器运行且健康，若不满足则把本次部署记为失败。默认：采用栈
      的“等待健康检查”设置。
  --no-wait
      不等待，无论栈的设置如何。
  --wait-timeout <秒数>
      等待容器健康的时间。隐含 --wait。
  --profile <名称>
      同时启动该 compose profile 中的服务。要使用多个 profile 可重复
      指定。默认：该栈正在使用的 profile；若没有，则用其默认
      profile，与 Web 界面的“更新”一致。
  --dry-run
      只做检查而不部署：拉取、检出该提交、执行上述全部检查、显示
      compose 配置如何变化（变量按写法显示，不显示其值），然后再把之
      前的提交检出回来。检查发现的问题会按部署时的说法给出，并填好具
      体值（例如缺失文件夹的路径）。不会拉取、构建或部署任何内容，但
      检查运行期间克隆中存放的是试过的那个提交，因此有容器从克隆读取
      文件时，那段时间它看到的是这些文件。全部检查通过时退出状态为
      0，有检查未通过或无法完成试运行时为 1。只能与 --commit 和
      --profile 一起使用。

示例：
  compose-git deploy myapp
  compose-git deploy myapp --commit 4f1c2b0e9d8a7c6b5a4f3e2d1c0b9a8f7e6d5c4b
  compose-git deploy myapp --dry-run
TEXT,
        'reclone' => <<<'TEXT'
用法：compose-git reclone <stack>

用已部署提交处的一个全新克隆替换该栈的克隆，用于克隆处于不良状态
时。不会触碰任何容器。

旧克隆会被移到 <clone>.replaced-<date>，绝不会被删除；其中不属于
仓库的文件（例如容器写入的数据）会被列出来。请把需要的东西复制回
去，然后自行删除旧克隆。如果新克隆失败，旧克隆会被移回。

当该栈上还有其他操作正在运行时，它会拒绝启动。
TEXT,
        'status' => <<<'TEXT'
用法：compose-git status [<stack> | --all] [--json]

显示本服务器上 git 栈的已知信息，不访问仓库：仓库与分支、compose
文件在仓库中的路径、克隆文件夹、已部署的提交、若上次部署在 up 阶
段失败则显示失败的提交、克隆中已检出的提交，以及克隆中被跟踪文件
的改动。不做任何改动。

选项：
  --all
      显示所有 git 栈。未指定栈时也是这样。git 设置无法读取的栈会连
      同原因一起显示。
  --json
      以 JSON 输出同样的内容，供脚本使用。
TEXT,
        'auto-deploy' => <<<'TEXT'
用法：compose-git auto-deploy <stack> off|notify|deploy

选择当栈所在分支上的新提交改动了栈（即仓库中该栈文件夹内的任何内
容）时，计划检查（每 15 分钟一次）要做什么：
  off       不做检查（默认）
  notify    发送一条 Unraid 通知，每个提交一次
  deploy    部署该提交，同不带选项的 'compose-git deploy'
每个提交只会尝试一次。未在运行的栈（已停止或从未部署）不会被部
署，直到它再次运行；被回退到较早提交的栈会被推进到最新提交。只要
有任何栈的设置不是 off，就会添加插件的 cron 条目。
TEXT,
        'resume' => <<<'TEXT'
用法：compose-git resume <stack>

清除栈的部署失败状态，使计划检查再次部署它。up 阶段部署失败后，
该栈的自动部署会暂停直到有一次部署成功；若计划部署在 up 之前失败
（例如拉取镜像），该提交不会被再次尝试。在你用其他方式修好原因之
后使用本命令。已部署的提交与容器不会被改动。
TEXT,
        'scheduled-check' => <<<'TEXT'
用法：compose-git scheduled-check

立即执行计划检查，检查所有 auto-deploy 设置不是 off 的 git 栈。
只要有栈需要，cron 每 15 分钟运行一次。阵列未启动，或另一次检查
仍在运行时，不做任何事。
TEXT,
        default => null,
    };
}

/**
 * Split arguments into positional values and --options. Options that take a
 * value are named in $withValue; --profile may repeat.
 *
 * @param string[] $args
 * @param string[] $withValue
 * @param string[] $flags
 * @return array{0: string[], 1: array<string, string|true|string[]>}
 */
function compose_git_parse(array $args, array $withValue, array $flags): array
{
    $positional = [];
    $options = [];
    for ($i = 0; $i < count($args); $i++) {
        $arg = $args[$i];
        if (!str_starts_with($arg, '--')) {
            $positional[] = $arg;
            continue;
        }
        $name = substr($arg, 2);
        if (in_array($name, $flags, true)) {
            $options[$name] = true;
        } elseif (in_array($name, $withValue, true)) {
            if (!isset($args[$i + 1])) {
                throw new ComposeGitUsageError("--$name 需要一个值。");
            }
            $value = $args[++$i];
            if ($name === 'profile') {
                $options['profile'] = array_merge((array) ($options['profile'] ?? []), [$value]);
            } else {
                $options[$name] = $value;
            }
        } else {
            throw new ComposeGitUsageError("未知选项 --{$name}。");
        }
    }
    return [$positional, $options];
}

/**
 * @param array<string, string|true|string[]> $options
 */
function compose_git_required(array $options, string $name): string
{
    $value = $options[$name] ?? null;
    if (!is_string($value) || $value === '') {
        throw new ComposeGitUsageError("必须提供 --{$name}。");
    }
    return $value;
}

/**
 * @param string[] $positional
 */
function compose_git_one_stack(array $positional): string
{
    if (count($positional) !== 1) {
        throw new ComposeGitUsageError('请只指定一个栈。');
    }
    return $positional[0];
}

/**
 * compose.sh's --wait arguments for a deploy.
 *
 * Waits for healthy containers as the web UI's up does: the stack's own
 * setting, else the plugin default (both from resolveStackWaitSettings()),
 * with --wait, --no-wait and --wait-timeout overriding them.
 *
 * @param array{enabled: bool, timeout: string, stackName?: string} $waitSettings
 * @param array<string, string|true|string[]> $options
 * @return string[]
 */
function compose_git_wait_arguments(array $waitSettings, array $options): array
{
    if (isset($options['wait']) && isset($options['no-wait'])) {
        throw new ComposeGitUsageError('不能同时使用 --wait 和 --no-wait。');
    }
    $wait = $waitSettings['enabled'];
    $timeout = $waitSettings['timeout'];
    if (isset($options['wait']) || isset($options['wait-timeout'])) {
        $wait = true;
    }
    if (isset($options['no-wait'])) {
        $wait = false;
    }
    if (isset($options['wait-timeout'])) {
        $timeout = (string) $options['wait-timeout'];
        if (preg_match('/^[1-9][0-9]{0,5}$/', $timeout) !== 1) {
            throw new ComposeGitUsageError('--wait-timeout 必须是秒数。');
        }
    }
    // As the web UI's and the scheduled deploy wait: a timeout read from the settings
    // files is passed on only when it is a plain number.
    return gitDeployWaitArguments(['enabled' => $wait, 'timeout' => $timeout]);
}

/**
 * The environment compose.sh gets for a git deploy: what DockerCommand gives the deploy's
 * checks, plus the documented lock settings. Nothing else of the caller's shell is passed on.
 *
 * @param array<string, string> $current The caller's environment
 * @return array<string, string>
 */
function compose_git_deploy_environment(array $current): array
{
    $environment = [
        'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        'HOME' => $current['HOME'] ?? '/root',
        'LC_ALL' => 'C',
    ];
    // TERM only changes how Compose draws its progress.
    foreach (['DOCKER_CONFIG', 'COMPOSE_LOCK_TIMEOUT', 'COMPOSE_LOCK_DIR', 'TERM'] as $name) {
        if (isset($current[$name]) && $current[$name] !== '') {
            $environment[$name] = $current[$name];
        }
    }
    return $environment;
}

/**
 * The folder compose.sh runs in for a git deploy: the compose file's folder, where the
 * deploy's checks run too.
 */
function compose_git_deploy_directory(?string $composeFilePath): string
{
    $composeFile = $composeFilePath === null ? false : realpath($composeFilePath);
    if ($composeFile === false) {
        // The compose file is checked again by the deploy, which stops before up if it is missing.
        return '/';
    }
    return dirname($composeFile);
}

/**
 * Deploy through compose.sh, so the deploy takes the same lock, credentials
 * and logging as every other stack action. Its output goes straight to ours.
 *
 * @param array<string, string|true|string[]> $options
 * @param resource $errors
 */
function compose_git_deploy(GitStackManager $manager, string $folder, array $options, $errors): int
{
    $stack = $manager->gitStack($folder);
    if (!$stack->hasResolvedIdentity()) {
        fwrite($errors, $stack->getIdentityBlockReason() . "\n");
        return COMPOSE_GIT_EXIT_FAILED;
    }

    $waitSettings = resolveStackWaitSettings($stack->path, parse_plugin_cfg('compose.manager'));
    $command = buildGitDeployCommand(
        $stack,
        isset($options['commit']) ? (string) $options['commit'] : null,
        isset($options['save-local-changes']),
        gitDeployProfiles($stack, array_map('strval', (array) ($options['profile'] ?? []))),
        compose_git_wait_arguments($waitSettings, $options)
    );

    // compose.sh runs with the same environment and folder as the deploy's checks, so a
    // variable exported in the caller's shell (or the folder it was run from, ${PWD})
    // cannot make up deploy something other than what was checked.
    $process = proc_open(
        $command,
        [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR],
        $pipes,
        compose_git_deploy_directory($stack->composeFilePath),
        compose_git_deploy_environment(getenv())
    );
    if (!is_resource($process)) {
        fwrite($errors, "无法启动 compose.sh。\n");
        return COMPOSE_GIT_EXIT_FAILED;
    }
    $exitCode = proc_close($process);
    return $exitCode === 0 ? COMPOSE_GIT_EXIT_OK : COMPOSE_GIT_EXIT_FAILED;
}

/**
 * compose-git deploy --dry-run: try the deploy without deploying, and print what it found.
 *
 * @param array<string, string|true|string[]> $options
 * @return int 0 when every check passed, 1 when one did not
 */
function compose_git_dry_run(GitStackManager $manager, string $folder, array $options): int
{
    foreach (['save-local-changes', 'wait', 'no-wait', 'wait-timeout'] as $option) {
        if (isset($options[$option])) {
            throw new ComposeGitUsageError("--dry-run 只能与 --commit 和 --profile 一起使用，不能使用 --{$option}：试运行不保存任何内容，也不启动任何容器。");
        }
    }
    $stack = $manager->gitStack($folder);
    $result = $manager->dryRun(
        $folder,
        isset($options['commit']) ? (string) $options['commit'] : null,
        gitDeployProfiles($stack, array_map('strval', (array) ($options['profile'] ?? [])))
    );
    $previous = substr($result['previousCommit'], 0, 12);
    $target = substr($result['targetCommit'], 0, 12);

    if ($result['previousCommit'] === $result['targetCommit']) {
        echo "试运行 {$folder}：{$target}，即当前检出的提交。\n";
    } else {
        echo "试运行 {$folder}：从 {$previous}（当前检出）到 {$target}。\n";
    }
    // After a failed up the clone holds the commit that failed, not the one that last worked.
    if ($result['deployedCommit'] === null) {
        echo "尚未部署任何提交。\n";
    } elseif ($result['deployedCommit'] !== $result['previousCommit']) {
        echo '最近一次成功部署的提交是 ' . substr($result['deployedCommit'], 0, 12) . ".\n";
    }
    if ($result['problems'] === []) {
        echo "所有检查均已通过。试运行不会拉取或构建镜像，因此缺失的镜像或标签\n"
            . "只有在实际部署时才会被发现。\n";
        if ($result['recreatesEveryContainer']) {
            echo "up 会重建该栈的所有容器（参见 'deploy --help' 中的 'recreateOnFolderChange'）。\n";
        }
    } else {
        echo "部署会中止：\n- " . implode("\n- ", $result['problems']) . "\n";
    }

    echo "\n";
    if ($result['configDiff'] === null) {
        echo "无法得知 compose 配置会如何变化。{$result['configProblem']}\n";
    } elseif ($result['configDiff'] === '') {
        echo "compose 配置没有变化。\n";
    } else {
        echo "compose 配置的变化如下（变量按原样显示，不展开取值）：\n" . $result['configDiff'];
    }
    echo "\n未部署任何内容，克隆已还原到 {$previous}。\n";

    return $result['problems'] === [] ? COMPOSE_GIT_EXIT_OK : COMPOSE_GIT_EXIT_FAILED;
}

/**
 * A status row for a git stack whose settings or state could not be read,
 * with the same keys as GitStackManager::status() and the reason as the problem.
 *
 * @return array<string, mixed>
 */
function compose_git_unreadable_status(string $folder, string $problem): array
{
    return [
        'stack' => $folder,
        'url' => null,
        'branch' => null,
        'composePath' => null,
        'cloneDir' => null,
        'recreateOnFolderChange' => null,
        'deployedCommit' => null,
        'failedCommit' => null,
        'checkedOutCommit' => null,
        'localChanges' => [],
        'commitMadeByHand' => false,
        'failedAutomaticCommit' => null,
        'problem' => $problem,
    ];
}

/**
 * @param array<string, mixed> $status
 */
function compose_git_print_status(array $status): void
{
    $short = static fn(?string $commit): string => $commit === null ? '-' : substr($commit, 0, 12);
    echo $status['stack'] . "\n";
    if ($status['url'] === null) {
        // The stack's git settings could not be read (see compose_git_unreadable_status()).
        echo '  问题:         ' . $status['problem'] . "\n";
        return;
    }
    echo '  仓库:         ' . $status['url'] . ' (' . $status['branch'] . ")\n";
    echo '  compose 文件: ' . $status['composePath'] . "\n";
    echo '  克隆:         ' . $status['cloneDir'] . "\n";
    if ($status['credential'] !== null) {
        echo '  凭据:         ' . $status['credential'] . "\n";
    }
    echo '  自动部署:     ' . $status['autoDeploy'] . "\n";
    echo '  已部署:       ' . $short($status['deployedCommit']) . "\n";
    if ($status['failedCommit'] !== null) {
        echo '  失败:         ' . $short($status['failedCommit']) . "（请修复后重新部署）\n";
    }
    if ($status['failedAutomaticCommit'] !== null) {
        echo '  失败:         ' . $short($status['failedAutomaticCommit'])
            . "（自动部署，在 up 之前失败；compose-git resume 会重试）\n";
    }
    echo '  已检出:       ' . $short($status['checkedOutCommit']) . "\n";
    if ($status['localChanges'] !== []) {
        echo '  本地改动:     ' . implode(', ', $status['localChanges']) . "\n";
    }
    if ($status['commitMadeByHand']) {
        echo "  手工创建: 当前检出的提交是在克隆中提交的（deploy --save-local-changes 可将其保存）\n";
    }
    if ($status['problem'] !== null) {
        echo '  问题:         ' . $status['problem'] . "\n";
    }
}

/**
 * @param string[] $argv
 * @param resource|null $errors Where progress and errors go (standard error by default)
 */
function compose_git_main(array $argv, string $composeRoot, $errors = null): int
{
    $errors ??= STDERR;
    $say = static function (string $message) use ($errors): void {
        fwrite($errors, $message . "\n");
    };
    $manager = new GitStackManager(rtrim($composeRoot, '/'), $say);
    $command = $argv[1] ?? '';
    $args = array_slice($argv, 2);

    // 'compose-git <command> --help' (or -h) prints that command's help, whatever else is given.
    $commandHelp = compose_git_command_help($command);
    if ($commandHelp !== null && (in_array('--help', $args, true) || in_array('-h', $args, true))) {
        echo $commandHelp . "\n";
        return COMPOSE_GIT_EXIT_OK;
    }

    try {
        switch ($command) {
            case 'add':
                [$positional, $options] = compose_git_parse($args, ['url', 'path', 'branch', 'clones-root', 'description', 'credential'], []);
                $folder = $manager->add(
                    compose_git_one_stack($positional),
                    compose_git_required($options, 'url'),
                    (string) ($options['branch'] ?? GitStackManager::DEFAULT_BRANCH),
                    compose_git_required($options, 'path'),
                    isset($options['clones-root']) ? (string) $options['clones-root'] : null,
                    (string) ($options['description'] ?? ''),
                    isset($options['credential']) ? $manager->findGitCredential((string) $options['credential']) : null
                );
                echo "使用以下命令部署：compose-git deploy $folder\n";
                return COMPOSE_GIT_EXIT_OK;

            case 'convert':
                [$positional, $options] = compose_git_parse($args, ['url', 'path', 'branch', 'clones-root', 'credential'], []);
                $folder = compose_git_one_stack($positional);
                $backup = $manager->convert(
                    $folder,
                    compose_git_required($options, 'url'),
                    (string) ($options['branch'] ?? GitStackManager::DEFAULT_BRANCH),
                    compose_git_required($options, 'path'),
                    isset($options['clones-root']) ? (string) $options['clones-root'] : null,
                    isset($options['credential']) ? $manager->findGitCredential((string) $options['credential']) : null
                );
                echo "被替换的文件位于 {$backup}。\n使用以下命令部署：compose-git deploy $folder\n";
                return COMPOSE_GIT_EXIT_OK;

            case 'credential':
                [$positional, $options] = compose_git_parse($args, [], ['none']);
                if (count($positional) === 2 && !isset($options['none'])) {
                    $manager->setCredential($positional[0], $manager->findCredentialFor($positional[0], $positional[1]));
                } elseif (count($positional) === 1 && isset($options['none'])) {
                    $manager->setCredential($positional[0], null);
                } else {
                    throw new ComposeGitUsageError('credential 需要一个栈，以及一个凭据名称或 --none。');
                }
                return COMPOSE_GIT_EXIT_OK;

            case 'auto-deploy':
                [$positional] = compose_git_parse($args, [], []);
                if (count($positional) !== 2) {
                    throw new ComposeGitUsageError('auto-deploy 需要一个栈，以及以下之一：' . implode(', ', GitStackSettings::AUTO_DEPLOY_CHOICES) . '.');
                }
                $manager->setAutoDeploy($positional[0], $positional[1]);
                // Installs the scheduled check's cron line while any stack asks for it, and removes it after.
                if (!composeCronManager()->rebuild()) {
                    fwrite($errors, "设置已保存，但无法更新插件的 cron 文件。\n");
                    return COMPOSE_GIT_EXIT_FAILED;
                }
                return COMPOSE_GIT_EXIT_OK;

            case 'resume':
                [$positional] = compose_git_parse($args, [], []);
                $manager->resume(compose_git_one_stack($positional));
                return COMPOSE_GIT_EXIT_OK;

            case 'deploy-key':
                [$positional] = compose_git_parse($args, [], []);
                echo $manager->deployKey(compose_git_one_stack($positional)) . "\n";
                return COMPOSE_GIT_EXIT_OK;

            case 'trust-host':
                [$positional] = compose_git_parse($args, [], []);
                $manager->trustHost(compose_git_one_stack($positional));
                return COMPOSE_GIT_EXIT_OK;

            case 'check':
                [$positional, $options] = compose_git_parse($args, [], ['all']);
                $folders = isset($options['all']) ? $manager->listGitStacks() : [compose_git_one_stack($positional)];
                $anyChanged = false;
                $anyFailed = false;
                $anyElsewhere = false;
                foreach ($folders as $folder) {
                    try {
                        $result = $manager->check($folder);
                        $deployed = $result['deployedCommit'] === null ? 'nothing deployed yet' : substr($result['deployedCommit'], 0, 12) . ' deployed';
                        $newer = substr($result['remoteCommit'], 0, 12) . " 可在 {$result['branch']} 上获取";
                        $stackFolder = $result['stackFolder'] === '' ? 'the repository' : $result['stackFolder'] . '/';
                        if ($result['upToDate']) {
                            echo "{$folder}：已是最新（{$deployed}）\n";
                        } elseif ($result['deployedCommit'] === null) {
                            echo "{$folder}：{$newer}（{$deployed}）\n";
                            $anyChanged = true;
                        } elseif ($result['deployedCommitMissing']) {
                            echo "{$folder}：{$newer}（{$deployed}，但已不在克隆中，因此这些改动"
                                . "无法判断）\n";
                            $anyChanged = true;
                        } elseif ($result['changesStack'] === true) {
                            echo "{$folder}：{$newer}，改动了 {$stackFolder}（{$deployed}）\n";
                            $anyChanged = true;
                        } else {
                            echo "{$folder}：{$newer}，但未改动 {$stackFolder}（{$deployed}）中的任何内容；"
                                . "如果该栈使用了该文件夹之外的文件，仍可部署\n";
                            $anyElsewhere = true;
                        }
                    } catch (RuntimeException | InvalidArgumentException $error) {
                        echo "{$folder}：无法检查：" . $error->getMessage() . "\n";
                        $anyFailed = true;
                    }
                }
                if ($anyChanged) {
                    return COMPOSE_GIT_EXIT_BEHIND;
                }
                if ($anyFailed) {
                    return COMPOSE_GIT_EXIT_FAILED;
                }
                return $anyElsewhere ? COMPOSE_GIT_EXIT_NEWER_ELSEWHERE : COMPOSE_GIT_EXIT_OK;

            case 'deploy':
                [$positional, $options] = compose_git_parse($args, ['commit', 'wait-timeout', 'profile'], ['save-local-changes', 'wait', 'no-wait', 'dry-run']);
                if (isset($options['dry-run'])) {
                    return compose_git_dry_run($manager, compose_git_one_stack($positional), $options);
                }
                return compose_git_deploy($manager, compose_git_one_stack($positional), $options, $errors);

            case 'reclone':
                [$positional] = compose_git_parse($args, [], []);
                $movedTo = $manager->reclone(compose_git_one_stack($positional));
                if ($movedTo !== null) {
                    echo "旧克隆位于 {$movedTo}。请复制回你需要的内容，然后自行删除它。\n";
                }
                return COMPOSE_GIT_EXIT_OK;

            case 'status':
                [$positional, $options] = compose_git_parse($args, [], ['all', 'json']);
                $listingAll = $positional === [] || isset($options['all']);
                $folders = $listingAll ? $manager->listGitStacks() : [compose_git_one_stack($positional)];
                $statuses = [];
                foreach ($folders as $folder) {
                    try {
                        $statuses[] = $manager->status($folder);
                    } catch (RuntimeException | InvalidArgumentException $error) {
                        if (!$listingAll) {
                            throw $error;
                        }
                        // One unreadable stack (a hand-edited git.json, say) must not hide the others.
                        $statuses[] = compose_git_unreadable_status($folder, $error->getMessage());
                    }
                }
                if (isset($options['json'])) {
                    echo json_encode($statuses, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                } else {
                    foreach ($statuses as $status) {
                        compose_git_print_status($status);
                    }
                    if ($statuses === []) {
                        echo "没有 Git 栈。\n";
                    }
                }
                return COMPOSE_GIT_EXIT_OK;

            case 'scheduled-check':
                [$positional] = compose_git_parse($args, [], []);
                if ($positional !== []) {
                    throw new ComposeGitUsageError('scheduled-check 会检查所有自动部署未关闭的 Git 栈；请勿指定栈名。');
                }
                (new GitScheduledCheck($composeRoot, null, null, $say))->run();
                return COMPOSE_GIT_EXIT_OK;

            case 'help':
                // 'compose-git help <command>' is the same as 'compose-git <command> --help'.
                if (isset($args[0])) {
                    $helpFor = compose_git_command_help($args[0]);
                    if ($helpFor === null) {
                        throw new ComposeGitUsageError("未知命令 '{$args[0]}'。");
                    }
                    echo $helpFor . "\n";
                    return COMPOSE_GIT_EXIT_OK;
                }
                echo COMPOSE_GIT_USAGE . "\n";
                return COMPOSE_GIT_EXIT_OK;

            case '--help':
            case '-h':
                echo COMPOSE_GIT_USAGE . "\n";
                return COMPOSE_GIT_EXIT_OK;

            case '':
                echo COMPOSE_GIT_USAGE . "\n";
                return COMPOSE_GIT_EXIT_USAGE;

            default:
                throw new ComposeGitUsageError("未知命令 '$command'。");
        }
    } catch (ComposeGitUsageError $error) {
        // A mistake in a known command shows that command's help; anything else, the overview.
        fwrite($errors, $error->getMessage() . "\n\n" . ($commandHelp ?? COMPOSE_GIT_USAGE) . "\n");
        return COMPOSE_GIT_EXIT_USAGE;
    } catch (Throwable $error) {
        fwrite($errors, $error->getMessage() . "\n");
        return COMPOSE_GIT_EXIT_FAILED;
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    exit(compose_git_main($argv, $compose_root));
}
