<?php

/**
 * Unit Tests for BackupFunctions.php
 * 
 * Tests the backup/restore helper functions in source/compose.manager/include/BackupFunctions.php.
 * These functions handle creating, listing, restoring, and managing backup archives.
 */

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;
use PluginTests\Mocks\FunctionMocks;

// Load BackupFunctions.php only if not already loaded (avoids logger() redeclaration
// conflict with Helpers.php which also defines logger()).
if (!function_exists('getBackupDestination')) {
    require_once '/usr/local/emhttp/plugins/compose.manager/include/BackupFunctions.php';
}

class BackupFunctionsTest extends TestCase
{
    private string $testComposeRoot;
    private string $testBackupDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test compose root and backup directory
        $this->testComposeRoot = sys_get_temp_dir() . '/compose_backup_test_' . getmypid();
        $this->testBackupDir = sys_get_temp_dir() . '/compose_backup_dest_' . getmypid();

        foreach ([$this->testComposeRoot, $this->testBackupDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        global $compose_root;
        $compose_root = $this->testComposeRoot;

        FunctionMocks::setPluginConfig('compose.manager', [
            'PROJECTS_FOLDER' => $this->testComposeRoot,
            'BACKUP_DESTINATION' => $this->testBackupDir,
            'BACKUP_RETENTION' => '5',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ([$this->testComposeRoot, $this->testBackupDir] as $dir) {
            if (is_dir($dir)) {
                $this->recursiveDelete($dir);
            }
        }
        $_POST = [];
        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object !== '.' && $object !== '..') {
                    $path = $dir . '/' . $object;
                    if (is_dir($path)) {
                        $this->recursiveDelete($path);
                    } else {
                        unlink($path);
                    }
                }
            }
            rmdir($dir);
        }
    }

    private function createTestStack(string $name, array $files = []): string
    {
        $stackPath = $this->testComposeRoot . '/' . $name;
        if (!is_dir($stackPath)) {
            mkdir($stackPath, 0755, true);
        }

        if (!isset($files['compose.yaml'])) {
            file_put_contents($stackPath . '/compose.yaml', "services:\n  web:\n    image: nginx\n");
        }

        foreach ($files as $filename => $content) {
            file_put_contents($stackPath . '/' . $filename, $content);
        }

        return $stackPath;
    }

    // ===========================================
    // getBackupDestination() Tests
    // ===========================================

    public function testGetBackupDestinationReturnsConfiguredPath(): void
    {
        FunctionMocks::setPluginConfig('compose.manager', [
            'BACKUP_DESTINATION' => '/mnt/user/backups/compose',
        ]);

        $result = getBackupDestination();
        $this->assertEquals('/mnt/user/backups/compose', $result);
    }

    public function testGetBackupDestinationReturnsDefaultWhenNotConfigured(): void
    {
        FunctionMocks::setPluginConfig('compose.manager', []);

        $result = getBackupDestination();
        $this->assertEquals('/boot/config/plugins/compose.manager/backups', $result);
    }

    public function testGetBackupDestinationTrimsTrailingSlash(): void
    {
        FunctionMocks::setPluginConfig('compose.manager', [
            'BACKUP_DESTINATION' => '/mnt/user/backups/',
        ]);

        $result = getBackupDestination();
        $this->assertEquals('/mnt/user/backups', $result);
    }

    // ===========================================
    // getBackupSource() Tests
    // ===========================================

    public function testGetBackupSourceReturnsProjectsFolder(): void
    {
        global $compose_root;
        $compose_root = '/mnt/user/compose-projects';

        $result = getBackupSource();
        $this->assertEquals('/mnt/user/compose-projects', $result);

        // Restore
        $compose_root = $this->testComposeRoot;
    }

    public function testGetBackupSourceTrimsTrailingSlash(): void
    {
        global $compose_root;
        $compose_root = '/mnt/user/compose-projects/';

        $result = getBackupSource();
        $this->assertEquals('/mnt/user/compose-projects', $result);

        $compose_root = $this->testComposeRoot;
    }

    // ===========================================
    // formatBytes() Tests
    // ===========================================

    public function testFormatBytesSmall(): void
    {
        $this->assertEquals('512 B', formatBytes(512));
    }

    public function testFormatBytesZero(): void
    {
        $this->assertEquals('0 B', formatBytes(0));
    }

    public function testFormatBytesKilobytes(): void
    {
        $result = formatBytes(2048);
        $this->assertEquals('2 KB', $result);
    }

    public function testFormatBytesMegabytes(): void
    {
        $result = formatBytes(5 * 1048576);
        $this->assertEquals('5 MB', $result);
    }

    public function testFormatBytesGigabytes(): void
    {
        $result = formatBytes(2 * 1073741824);
        $this->assertEquals('2 GB', $result);
    }

    public function testFormatBytesFractionalMB(): void
    {
        // 1.5 MB = 1572864 bytes
        $result = formatBytes(1572864);
        $this->assertEquals('1.5 MB', $result);
    }

    // ===========================================
    // listBackupArchives() Tests
    // ===========================================

    public function testListBackupArchivesEmptyDirectory(): void
    {
        $archives = listBackupArchives($this->testBackupDir);
        $this->assertIsArray($archives);
        $this->assertEmpty($archives);
    }

    public function testListBackupArchivesMatchesNamingPattern(): void
    {
        // Create valid backup files
        file_put_contents($this->testBackupDir . '/backup_2026-01-15_10-30.tar.gz', 'fake');
        file_put_contents($this->testBackupDir . '/backup_2026-02-01_03-00.tar.gz', 'fake2');

        // Create an invalid file that should NOT be listed
        file_put_contents($this->testBackupDir . '/random-file.tar.gz', 'nope');
        file_put_contents($this->testBackupDir . '/backup_invalid.tar.gz', 'nope');

        $archives = listBackupArchives($this->testBackupDir);
        $this->assertCount(2, $archives);
    }

    public function testListBackupArchivesSortedNewestFirst(): void
    {
        file_put_contents($this->testBackupDir . '/backup_2026-01-01_00-00.tar.gz', 'old');
        file_put_contents($this->testBackupDir . '/backup_2026-02-01_12-00.tar.gz', 'new');
        file_put_contents($this->testBackupDir . '/backup_2026-01-15_06-30.tar.gz', 'mid');

        $archives = listBackupArchives($this->testBackupDir);

        $this->assertEquals('backup_2026-02-01_12-00.tar.gz', $archives[0]['filename']);
        $this->assertEquals('backup_2026-01-15_06-30.tar.gz', $archives[1]['filename']);
        $this->assertEquals('backup_2026-01-01_00-00.tar.gz', $archives[2]['filename']);
    }

    public function testListBackupArchivesIncludesMetadata(): void
    {
        file_put_contents($this->testBackupDir . '/backup_2026-02-09_10-00.tar.gz', str_repeat('x', 2048));

        $archives = listBackupArchives($this->testBackupDir);
        $this->assertCount(1, $archives);

        $archive = $archives[0];
        $this->assertArrayHasKey('filename', $archive);
        $this->assertArrayHasKey('size', $archive);
        $this->assertArrayHasKey('sizeBytes', $archive);
        $this->assertArrayHasKey('modified', $archive);
        $this->assertEquals(2048, $archive['sizeBytes']);
        $this->assertEquals('2 KB', $archive['size']);
    }

    public function testListBackupArchivesWithSecondsInFilename(): void
    {
        file_put_contents($this->testBackupDir . '/backup_2026-02-09_10-00-30.tar.gz', 'data');

        $archives = listBackupArchives($this->testBackupDir);
        $this->assertCount(1, $archives);
        $this->assertEquals('backup_2026-02-09_10-00-30.tar.gz', $archives[0]['filename']);
    }

    public function testListBackupArchivesNonexistentDirectory(): void
    {
        $archives = listBackupArchives('/nonexistent/path/that/does/not/exist');
        $this->assertIsArray($archives);
        $this->assertEmpty($archives);
    }

    // ===========================================
    // resolveArchivePath() Tests
    // ===========================================

    public function testResolveArchivePathAbsolutePathExists(): void
    {
        $filePath = $this->testBackupDir . '/backup_2026-01-01_00-00.tar.gz';
        file_put_contents($filePath, 'data');

        $resolved = resolveArchivePath($filePath);
        $this->assertEquals($filePath, $resolved);
    }

    public function testResolveArchivePathFromDirectory(): void
    {
        $filePath = $this->testBackupDir . '/backup_2026-01-01_00-00.tar.gz';
        file_put_contents($filePath, 'data');

        $resolved = resolveArchivePath('backup_2026-01-01_00-00.tar.gz', $this->testBackupDir);
        $this->assertEquals($filePath, $resolved);
    }

    public function testResolveArchivePathFallbackToConfiguredDestination(): void
    {
        FunctionMocks::setPluginConfig('compose.manager', [
            'BACKUP_DESTINATION' => $this->testBackupDir,
        ]);

        $filePath = $this->testBackupDir . '/backup_2026-03-01_00-00.tar.gz';
        file_put_contents($filePath, 'data');

        $resolved = resolveArchivePath('backup_2026-03-01_00-00.tar.gz');
        $this->assertEquals($filePath, $resolved);
    }

    public function testResolveArchivePathReturnsAsIsWhenNotFound(): void
    {
        $resolved = resolveArchivePath('nonexistent.tar.gz');
        $this->assertEquals('nonexistent.tar.gz', $resolved);
    }

    // ===========================================
    // createBackup() Tests (require tar)
    // ===========================================

    /**
     * @requires OS Linux
     */
    public function testCreateBackupErrorWhenNoStacks(): void
    {
        // Empty projects folder — no stacks
        $result = createBackup();
        $this->assertEquals('error', $result['result']);
        $this->assertStringContainsString('没有找到可备份的栈', $result['message']);
    }

    /**
     * @requires OS Linux
     */
    public function testCreateBackupSuccess(): void
    {
        $this->createTestStack('mystack');

        FunctionMocks::setPluginConfig('compose.manager', [
            'BACKUP_DESTINATION' => $this->testBackupDir,
            'BACKUP_RETENTION' => '10',
        ]);

        $result = createBackup();
        $this->assertEquals('success', $result['result']);
        $this->assertArrayHasKey('archive', $result);
        $this->assertArrayHasKey('size', $result);
        $this->assertArrayHasKey('stacks', $result);
        $this->assertEquals(1, $result['stacks']);
        $this->assertStringStartsWith('backup_', $result['archive']);

        // Verify the archive file exists
        $archivePath = $this->testBackupDir . '/' . $result['archive'];
        $this->assertFileExists($archivePath);
    }

    /**
     * @requires OS Linux
     */
    public function testCreateBackupMultipleStacks(): void
    {
        $this->createTestStack('stack1');
        $this->createTestStack('stack2');
        $this->createTestStack('stack3');

        FunctionMocks::setPluginConfig('compose.manager', [
            'BACKUP_DESTINATION' => $this->testBackupDir,
            'BACKUP_RETENTION' => '10',
        ]);

        $result = createBackup();
        $this->assertEquals('success', $result['result']);
        $this->assertEquals(3, $result['stacks']);
    }

    public function testCreateBackupErrorOnMissingSource(): void
    {
        global $compose_root;
        $compose_root = '/nonexistent/source/path';

        $result = createBackup();
        $this->assertEquals('error', $result['result']);
        $this->assertStringContainsString('项目目录不存在', $result['message']);

        $compose_root = $this->testComposeRoot;
    }

    /**
     * @requires OS Linux
     */
    public function testCreateBackupSkipsVersionFileAtRoot(): void
    {
        $this->createTestStack('mystack');
        // Place the plugin-managed version file at the compose root (as compose.manager.plg does)
        file_put_contents($this->testComposeRoot . '/version', "1\n");

        FunctionMocks::setPluginConfig('compose.manager', [
            'BACKUP_DESTINATION' => $this->testBackupDir,
            'BACKUP_RETENTION' => '10',
        ]);

        $result = createBackup();
        $this->assertEquals('success', $result['result']);
        // Only the real stack should be counted; 'version' must not appear as a stack
        $this->assertEquals(1, $result['stacks']);
    }

    // ===========================================
    // applyRetentionPolicy() Tests
    // ===========================================

    public function testApplyRetentionPolicyDeletesOldArchives(): void
    {
        // Create more archives than the retention count
        FunctionMocks::setPluginConfig('compose.manager', [
            'BACKUP_RETENTION' => '2',
        ]);

        file_put_contents($this->testBackupDir . '/backup_2026-01-01_00-00.tar.gz', 'old1');
        file_put_contents($this->testBackupDir . '/backup_2026-01-02_00-00.tar.gz', 'old2');
        file_put_contents($this->testBackupDir . '/backup_2026-01-03_00-00.tar.gz', 'new1');
        file_put_contents($this->testBackupDir . '/backup_2026-01-04_00-00.tar.gz', 'new2');

        applyRetentionPolicy($this->testBackupDir);

        $remaining = listBackupArchives($this->testBackupDir);
        $this->assertCount(2, $remaining);
        // Newest two should remain
        $this->assertEquals('backup_2026-01-04_00-00.tar.gz', $remaining[0]['filename']);
        $this->assertEquals('backup_2026-01-03_00-00.tar.gz', $remaining[1]['filename']);
    }

    public function testApplyRetentionPolicyNoDeleteWhenUnderLimit(): void
    {
        FunctionMocks::setPluginConfig('compose.manager', [
            'BACKUP_RETENTION' => '5',
        ]);

        file_put_contents($this->testBackupDir . '/backup_2026-01-01_00-00.tar.gz', 'data1');
        file_put_contents($this->testBackupDir . '/backup_2026-01-02_00-00.tar.gz', 'data2');

        applyRetentionPolicy($this->testBackupDir);

        $remaining = listBackupArchives($this->testBackupDir);
        $this->assertCount(2, $remaining);
    }

    public function testApplyRetentionPolicyZeroMeansUnlimited(): void
    {
        FunctionMocks::setPluginConfig('compose.manager', [
            'BACKUP_RETENTION' => '0',
        ]);

        for ($i = 1; $i <= 10; $i++) {
            file_put_contents($this->testBackupDir . "/backup_2026-01-{$i}_00-00.tar.gz", "data{$i}");
        }

        // Note: filename 2026-01-1 won't match pattern (needs 2 digits), fix:
        // Actually the pattern requires \d{4}-\d{2}-\d{2} so single digit day won't match.
        // Let's use properly formatted filenames:
        $this->recursiveDelete($this->testBackupDir);
        mkdir($this->testBackupDir, 0755, true);
        for ($i = 1; $i <= 10; $i++) {
            $day = str_pad((string)$i, 2, '0', STR_PAD_LEFT);
            file_put_contents($this->testBackupDir . "/backup_2026-01-{$day}_00-00.tar.gz", "data{$i}");
        }

        applyRetentionPolicy($this->testBackupDir);

        $remaining = listBackupArchives($this->testBackupDir);
        $this->assertCount(10, $remaining);
    }

    // ===========================================
    // readArchiveStacks() Tests
    // ===========================================

    /**
     * @requires OS Linux
     */
    public function testReadArchiveStacksFromRealArchive(): void
    {
        // Create stacks and a real tar.gz
        $this->createTestStack('alpha');
        $this->createTestStack('beta');

        $archivePath = $this->testBackupDir . '/test_archive.tar.gz';
        $cmd = 'cd ' . escapeshellarg($this->testComposeRoot) . " && tar czf " . escapeshellarg($archivePath) . " alpha beta 2>&1";
        exec($cmd, $output, $exitCode);
        $this->assertEquals(0, $exitCode, 'tar failed: ' . implode("\n", $output));

        $result = readArchiveStacks($archivePath);
        $this->assertEquals('success', $result['result']);
        $this->assertContains('alpha', $result['stacks']);
        $this->assertContains('beta', $result['stacks']);
    }

    public function testReadArchiveStacksErrorOnMissingFile(): void
    {
        $result = readArchiveStacks('/nonexistent/archive.tar.gz');
        $this->assertEquals('error', $result['result']);
        $this->assertStringContainsString('未找到归档', $result['message']);
    }

    // ===========================================
    // restoreStacks() Tests
    // ===========================================

    /**
     * @requires OS Linux
     */
    public function testRestoreStacksSuccess(): void
    {
        // Create and backup a stack
        $this->createTestStack('restore-test', [
            'compose.yaml' => "services:\n  app:\n    image: alpine\n",
            '.env' => "FOO=bar\n",
        ]);

        $archivePath = $this->testBackupDir . '/restore_test.tar.gz';
        $cmd = 'cd ' . escapeshellarg($this->testComposeRoot) . " && tar czf " . escapeshellarg($archivePath) . " restore-test 2>&1";
        exec($cmd);

        // Delete the stack
        $this->recursiveDelete($this->testComposeRoot . '/restore-test');
        $this->assertDirectoryDoesNotExist($this->testComposeRoot . '/restore-test');

        // Restore it
        $result = restoreStacks($archivePath, ['restore-test']);
        $this->assertEquals('success', $result['result']);
        $this->assertContains('restore-test', $result['restored']);

        // Verify files are back
        $this->assertFileExists($this->testComposeRoot . '/restore-test/compose.yaml');
        $this->assertFileExists($this->testComposeRoot . '/restore-test/.env');
    }

    public function testRestoreStacksErrorOnMissingArchive(): void
    {
        $result = restoreStacks('/nonexistent/archive.tar.gz', ['some-stack']);
        $this->assertEquals('error', $result['result']);
        $this->assertStringContainsString('未找到归档', $result['message']);
    }

    public function testRestoreStacksErrorOnEmptyStackList(): void
    {
        $archivePath = $this->testBackupDir . '/dummy.tar.gz';
        file_put_contents($archivePath, 'fake');

        $result = restoreStacks($archivePath, []);
        $this->assertEquals('error', $result['result']);
        $this->assertStringContainsString('未选择要恢复的栈', $result['message']);
    }

    // ===========================================
    // updateBackupCron() Tests
    // ===========================================

    /**
     * @requires OS Linux
     */
    public function testUpdateBackupCronCreatesFileWhenEnabled(): void
    {
        $cronFile = sys_get_temp_dir() . '/test_compose_cron_' . getmypid() . '.cron';
        if (is_file($cronFile)) @unlink($cronFile);

        // Set env vars so CronManager writes to our test file and skips update_cron
        putenv('COMPOSE_MANAGER_CRON_FILE=' . $cronFile);
        putenv('COMPOSE_MANAGER_CRON_NOSYNC=1');

        // Set backup schedule enabled
        FunctionMocks::setPluginConfig('compose.manager', [
            'BACKUP_SCHEDULE_ENABLED' => 'true',
            'BACKUP_SCHEDULE_FREQUENCY' => 'daily',
            'BACKUP_SCHEDULE_TIME' => '02:30',
        ]);

        // Execute
        updateBackupCron();

        // Verify cron file created with backup line
        $this->assertFileExists($cronFile);
        $content = file_get_contents($cronFile);
        $this->assertStringContainsString('30 2 * * *', $content);
        $this->assertStringContainsString('backup_cron.sh', $content);
        $this->assertStringContainsString('#compose-backup', $content);
        // No stray 'root' user field
        $this->assertStringNotContainsString('* root ', $content);

        // Cleanup
        @unlink($cronFile);
        putenv('COMPOSE_MANAGER_CRON_FILE');
        putenv('COMPOSE_MANAGER_CRON_NOSYNC');
    }

    /**
     * @requires OS Linux
     */
    public function testUpdateBackupCronRemovesLineWhenDisabled(): void
    {
        $cronFile = sys_get_temp_dir() . '/test_compose_cron_disabled_' . getmypid() . '.cron';
        if (is_file($cronFile)) @unlink($cronFile);

        putenv('COMPOSE_MANAGER_CRON_FILE=' . $cronFile);
        putenv('COMPOSE_MANAGER_CRON_NOSYNC=1');

        // First enable backup to create the file
        FunctionMocks::setPluginConfig('compose.manager', [
            'BACKUP_SCHEDULE_ENABLED' => 'true',
            'BACKUP_SCHEDULE_FREQUENCY' => 'daily',
            'BACKUP_SCHEDULE_TIME' => '03:00',
        ]);
        updateBackupCron();
        $this->assertFileExists($cronFile);

        // Now disable backup
        FunctionMocks::setPluginConfig('compose.manager', [
            'BACKUP_SCHEDULE_ENABLED' => 'false',
        ]);
        updateBackupCron();

        // Cron file should be removed (no entries left)
        $this->assertFileDoesNotExist($cronFile);

        putenv('COMPOSE_MANAGER_CRON_FILE');
        putenv('COMPOSE_MANAGER_CRON_NOSYNC');
    }
}
