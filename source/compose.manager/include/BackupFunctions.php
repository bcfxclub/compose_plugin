<?php

/**
 * Compose Manager - Backup & Restore Functions
 *
 * Handles creation and restoration of stack backup archives.
 * Archives are .tar.gz files containing stack directories.
 */

require_once("/usr/local/emhttp/plugins/compose.manager/include/Defines.php");
require_once("/usr/local/emhttp/plugins/compose.manager/include/Util.php");

/**
 * Get the backup destination path from config, falling back to default.
 */
function getBackupDestination()
{
    $cfg = parse_plugin_cfg('compose.manager');
    $dest = $cfg['BACKUP_DESTINATION'] ?? '/boot/config/plugins/compose.manager/backups';
    return rtrim($dest, '/');
}

/**
 * Get the backup source path (projects folder).
 */
function getBackupSource()
{
    global $compose_root;
    return rtrim($compose_root, '/');
}

/**
 * Create a backup archive of all stack directories.
 *
 * @return array Result with 'result' and 'message' keys.
 */
function createBackup()
{
    $source = getBackupSource();
    $destination = getBackupDestination();

    // Validate source
    if (!is_dir($source)) {
        return ['result' => 'error', 'message' => '项目目录不存在：' . $source];
    }

    // Ensure destination directory exists
    if (!is_dir($destination)) {
        @mkdir($destination, 0755, true);
        if (!is_dir($destination)) {
            return ['result' => 'error', 'message' => '无法创建备份目标：' . $destination];
        }
    }

    // Collect stack directories, skipping reserved root-level files (e.g. 'version')
    if (@scandir($source) === false) {
        return ['result' => 'error', 'message' => '无法读取项目目录：' . $source];
    }
    $stacks = StackInfo::listProjectFolders($source);

    if (empty($stacks)) {
        return ['result' => 'error', 'message' => '没有找到可备份的栈。'];
    }

    // Generate archive filename
    $timestamp = date('Y-m-d_H-i');
    $archiveName = "backup_{$timestamp}.tar.gz";
    $archivePath = $destination . '/' . $archiveName;

    // Avoid overwriting — append seconds if file exists
    if (file_exists($archivePath)) {
        $timestamp = date('Y-m-d_H-i-s');
        $archiveName = "backup_{$timestamp}.tar.gz";
        $archivePath = $destination . '/' . $archiveName;
    }

    // Build tar.gz — cd into source dir so paths inside the archive are relative
    $escapedArchive = escapeshellarg($archivePath);
    $tarItems = '';
    foreach ($stacks as $stack) {
        $tarItems .= ' ' . escapeshellarg($stack);
    }

    $cmd = "cd " . escapeshellarg($source) . " && tar czf {$escapedArchive}{$tarItems} 2>&1";
    exec($cmd, $output, $exitCode);

    if ($exitCode !== 0) {
        return ['result' => 'error', 'message' => 'tar command failed (exit ' . $exitCode . '): ' . implode("\n", $output)];
    }

    // Apply retention policy
    applyRetentionPolicy($destination);

    $sizeBytes = filesize($archivePath);
    $sizeHuman = formatBytes($sizeBytes);

    // Note: detailed logging is handled by the caller (exec.php or backup_cron.sh)

    return [
        'result' => 'success',
        'message' => "备份创建成功。",
        'archive' => $archiveName,
        'size' => $sizeHuman,
        'stacks' => count($stacks)
    ];
}

/**
 * Apply retention policy — delete oldest archives exceeding the retention count.
 */
function applyRetentionPolicy($destination)
{
    $cfg = parse_plugin_cfg('compose.manager');
    $retention = intval($cfg['BACKUP_RETENTION'] ?? 5);

    if ($retention <= 0) return; // 0 = unlimited

    $archives = listBackupArchives($destination);

    if (count($archives) > $retention) {
        // Archives are sorted newest-first; remove from the end (oldest)
        $toDelete = array_slice($archives, $retention);
        foreach ($toDelete as $archive) {
            $filePath = $destination . '/' . $archive['filename'];
            @unlink($filePath);
            composeLogger("Retention: deleted old backup " . $archive['filename'], null, 'daemon', 'info', 'backup');
        }
    }
}

/**
 * List backup archives in a directory, sorted newest-first.
 *
 * @param string|null $directory Override directory, or null for configured destination.
 * @return array List of archive info arrays.
 */
function listBackupArchives($directory = null)
{
    $dir = $directory ?? getBackupDestination();
    $archives = [];

    if (!is_dir($dir)) return $archives;

    $entries = @scandir($dir);
    if ($entries === false) return $archives;

    foreach ($entries as $entry) {
        if (preg_match('/^backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}(-\d{2})?\.tar\.gz$/', $entry)) {
            $fullPath = $dir . '/' . $entry;
            $archives[] = [
                'filename' => $entry,
                'size' => formatBytes(filesize($fullPath)),
                'sizeBytes' => filesize($fullPath),
                'modified' => date('c', filemtime($fullPath))
            ];
        }
    }

    // Sort newest first by filename (which contains the timestamp)
    usort($archives, function ($a, $b) {
        return strcmp($b['filename'], $a['filename']);
    });

    return $archives;
}

/**
 * Read the top-level directory names (stacks) from a backup archive.
 *
 * @param string $archivePath Full path to the .tar.gz file.
 * @return array Result with 'stacks' array, or error.
 */
function readArchiveStacks($archivePath)
{
    if (!file_exists($archivePath)) {
        return ['result' => 'error', 'message' => '未找到归档：' . basename($archivePath)];
    }

    // List archive contents and extract top-level directory names
    $escaped = escapeshellarg($archivePath);
    $cmd = "tar tzf {$escaped} 2>/dev/null";
    $output = shell_exec($cmd);

    if (empty($output)) {
        return ['result' => 'error', 'message' => '无法读取归档内容，或归档为空。'];
    }

    $lines = explode("\n", trim($output));
    $stacks = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        $parts = explode('/', $line);
        $topDir = $parts[0];
        if (!empty($topDir) && $topDir !== '.' && !in_array($topDir, $stacks)) {
            $stacks[] = $topDir;
        }
    }

    sort($stacks);

    return ['result' => 'success', 'stacks' => $stacks];
}

/**
 * Restore selected stacks from a backup archive.
 *
 * @param string $archivePath Full path to the .tar.gz file.
 * @param array $stacks Array of stack directory names to restore.
 * @return array Result with 'result', 'message', and 'restored' keys.
 */
function restoreStacks($archivePath, $stacks)
{
    $source = getBackupSource();

    if (!file_exists($archivePath)) {
        return ['result' => 'error', 'message' => '未找到归档：' . basename($archivePath)];
    }

    if (empty($stacks)) {
        return ['result' => 'error', 'message' => '未选择要恢复的栈。'];
    }

    // Ensure projects folder exists
    if (!is_dir($source)) {
        @mkdir($source, 0755, true);
    }

    $escaped = escapeshellarg($archivePath);
    $destEscaped = escapeshellarg($source);
    $restored = [];
    $errors = [];

    foreach ($stacks as $stack) {
        $stackEscaped = escapeshellarg($stack . '/');

        // Extract the stack directory, overwriting existing files
        $cmd = "tar xzf {$escaped} -C {$destEscaped} {$stackEscaped} 2>&1";
        exec($cmd, $output, $exitCode);

        if ($exitCode === 0) {
            $restored[] = $stack;
        } else {
            $errors[] = $stack . ' (exit ' . $exitCode . ')';
        }
    }

    $result = [
        'result' => empty($errors) ? 'success' : (empty($restored) ? 'error' : 'warning'),
        'restored' => $restored,
        'errors' => $errors,
        'message' => count($restored) . ' stack(s) restored successfully: ' . implode(', ', $restored)
    ];

    if (!empty($errors)) {
        $result['message'] .= ' Failed: ' . implode(', ', $errors);
    }

    return $result;
}

/**
 * Install or remove the backup cron job.
 * Delegates to CronManager for centralized .cron file management via update_cron.
 */
function updateBackupCron(): bool
{
    require_once("/usr/local/emhttp/plugins/compose.manager/include/CronManager.php");
    $cronManager = composeCronManager();

    // One-time migration: clean up legacy root crontab entries and old /etc/cron.d file
    $cronManager->cleanupLegacy();

    // Rebuild the unified .cron file (reads config to determine backup schedule)
    return $cronManager->rebuild();
}

/**
 * Format bytes to human-readable string.
 */
function formatBytes($bytes)
{
    if ($bytes >= 1073741824) {
        return round($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return round($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
}

/**
 * Resolve the full path to an archive given a filename.
 * Looks in the optional directory first, then configured destination, falls back to the provided path.
 */
function resolveArchivePath($filenameOrPath, $directory = null)
{
    // Always use basename to prevent path traversal — the archive is resolved
    // relative to the backup destination, never from an arbitrary absolute path.
    $filename = basename($filenameOrPath);

    // Try the explicitly provided directory first
    if ($directory !== null && $directory !== '') {
        $candidate = rtrim($directory, '/') . '/' . $filename;
        if (file_exists($candidate)) {
            return $candidate;
        }
    }

    // Otherwise look in the configured backup destination
    $dest = getBackupDestination();
    $candidate = $dest . '/' . $filename;
    if (file_exists($candidate)) {
        return $candidate;
    }

    // Return as-is for the caller to handle the error
    return $filenameOrPath;
}
