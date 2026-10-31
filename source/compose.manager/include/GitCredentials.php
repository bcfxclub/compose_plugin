<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/compose.manager/include/Defines.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/CredentialVault.php';

/**
 * Hands a git stack's HTTPS token to git for one run.
 *
 * The token is kept in the credential vault. For a run that talks to the
 * repository (clone, fetch, ls-remote), it is written to a file that only root
 * can read, under /var/tmp (in RAM), and git is pointed at it with
 * "credential.helper=store --file=...". The file is removed when the run ends,
 * and a file left behind by a run that was killed is removed by the next one.
 * The token is never on a command line, in the environment, in the clone's
 * .git/config or in a log.
 *
 * The file holds the stack's own repository address, and git is told to match
 * the path as well as the host (credential.useHttpPath), so git offers the
 * token to that repository only, not to every repository on the same host.
 */
final class GitCredentials
{
    /** A file older than this, that no running git uses, was left by a run that died. */
    private const STALE_AFTER_SECONDS = 3600;

    /**
     * Write the credential for one repository to a new file, for one git run.
     *
     * @return string The file; pass it to settingsFor() and remove it with remove() afterwards
     * @throws RuntimeException if the credential is missing, is not a git credential, is for
     *                          another host, or the file cannot be written
     */
    public static function writeFile(string $credentialId, string $repositoryUrl): string
    {
        $repository = parse_url($repositoryUrl);
        if (!is_array($repository) || strtolower((string) ($repository['scheme'] ?? '')) !== 'https'
            || !isset($repository['host'], $repository['path'])) {
            throw new RuntimeException('git 凭据只能用于 https:// 仓库地址。');
        }
        $host = strtolower($repository['host']) . (isset($repository['port']) ? ':' . $repository['port'] : '');

        $line = (new CredentialVault())->useCredential(
            $credentialId,
            static function (array $credential) use ($host, $repository): string {
                if (($credential['provider'] ?? '') !== 'git') {
                    throw new RuntimeException("凭据 '{$credential['name']}' 不是 git 仓库凭据。");
                }
                if (($credential['registry'] ?? '') !== $host) {
                    throw new RuntimeException(
                        "凭据 '{$credential['name']}' 属于 {$credential['registry']}，但仓库位于 {$host}。"
                    );
                }
                return 'https://' . rawurlencode($credential['username']) . ':' . rawurlencode($credential['secret'])
                    . '@' . $host . $repository['path'] . "\n";
            }
        );

        return self::writeRunFile($line);
    }

    /**
     * Write a secret to a new file that only root can read, for one git run.
     *
     * Also used for a git stack's ssh deploy key. Remove it with remove() afterwards.
     *
     * @throws RuntimeException if the file cannot be written
     */
    public static function writeRunFile(string $content): string
    {
        $directory = self::prepareDirectory();
        self::removeStaleFiles($directory);

        $file = $directory . '/' . bin2hex(random_bytes(16));
        // Created with root-only permissions (the umask), so the secret is never in a file others can read.
        $oldUmask = umask(0077);
        try {
            $handle = @fopen($file, 'x');
        } finally {
            umask($oldUmask);
        }
        if ($handle === false) {
            throw new RuntimeException('无法为 git 创建凭据文件。');
        }
        chmod($file, 0600);
        $written = fwrite($handle, $content);
        fclose($handle);
        if ($written !== strlen($content)) {
            @unlink($file);
            throw new RuntimeException('无法写入 git 凭据文件。');
        }
        return $file;
    }

    /**
     * The settings that make git use a credential file (for GitCommand::run's $extraConfig).
     *
     * @return string[]
     */
    public static function settingsFor(string $file): array
    {
        return [
            'credential.helper=store --file=' . $file,
            'credential.useHttpPath=true',
        ];
    }

    /**
     * Remove a credential file written by writeFile().
     */
    public static function remove(string $file): void
    {
        $directory = realpath(COMPOSE_GIT_CREDENTIAL_DIR);
        $target = realpath($file);
        if ($directory === false || $target === false || dirname($target) !== $directory) {
            return;
        }
        @unlink($target);
    }

    /**
     * The folder for run files, made if missing. It is in /var/tmp, where anyone can
     * create things, so a folder someone else made first (or a symlink there) is refused:
     * its owner could read or swap the files root writes into it.
     */
    private static function prepareDirectory(): string
    {
        $directory = rtrim(COMPOSE_GIT_CREDENTIAL_DIR, '/');
        if (!file_exists($directory) && @readlink($directory) === false && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建用于存放 git 凭据文件的文件夹。');
        }
        clearstatcache(true, $directory);
        if (@readlink($directory) !== false || !is_dir($directory)) {
            throw new RuntimeException("$directory 不是普通文件夹，因此未在其中写入凭据。请将其删除。");
        }
        // Compared with whoever runs this (root on Unraid; the tests run as another user).
        if (@fileowner($directory) !== (function_exists('posix_geteuid') ? posix_geteuid() : 0)) {
            throw new RuntimeException("$directory 属于其他用户，因此未在其中写入凭据。请将其删除。");
        }
        if (!chmod($directory, 0700)) {
            throw new RuntimeException("无法将 $directory 设为私有权限，因此未在其中写入凭据。");
        }
        return $directory;
    }

    /**
     * Remove files left behind by runs that were killed before they could clean up.
     *
     * Every run that uses one of these files is time-limited to well under an
     * hour (GitCommand's and GitClone's timeouts), so a file older than that
     * belongs to no run that is still going.
     */
    private static function removeStaleFiles(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $file) {
            $modified = @filemtime($file);
            if ($modified === false || time() - $modified <= self::STALE_AFTER_SECONDS) {
                continue;
            }
            self::remove($file);
        }
    }
}
