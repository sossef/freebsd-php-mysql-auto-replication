<?php

namespace Monsefrachid\MysqlReplication\Services;

use Monsefrachid\MysqlReplication\Support\ShellRunner;
use Monsefrachid\MysqlReplication\Contracts\JailDriverInterface;
use RuntimeException;

/**
 * Class IocageJailDriver
 *
 * Handles jail operations using iocage.
 */
class IocageJailDriver implements JailDriverInterface
{
    /**
     * @var ShellRunner
     */
    private ShellRunner $shell;

    /**
     * Constructor
     *
     * @param ShellRunner $shell
     */
    public function __construct(ShellRunner $shell)
    {
        $this->shell = $shell;
    }

    /**
     * {@inheritdoc}
     */
    public function jailExists(string $jailName): bool
    {
        $output = $this->shell->shell(
            "sudo iocage list -H -q | awk '{print \$1}' | grep -w ^{$jailName}$",
            "Check if jail '{$jailName}' exists"
        );

        return is_string($output) && trim($output) === $jailName;
    }

    /**
     * {@inheritdoc}
     */
    public function destroyJail(string $jailName): void
    {
        $this->shell->run(
            "sudo iocage destroy -f --recursive {$jailName}",
            "Force destroy existing jail '{$jailName}'"
        );
    }

    /**
     * Start the specified jail using iocage.
     *
     * @param string $jailName
     * @return void
     * @throws RuntimeException if the jail fails to start
     */
    public function start(string $jailName): void
    {
        $this->shell->run("sudo iocage start {$jailName}", "Start replica jail");
    }

    /**
     * Check if the specified jail is currently running.
     *
     * @param string $jailName
     * @return bool
     */
    public function isRunning(string $jailName): bool
    {
        // Run the iocage command to get jail state
        $cmd = "sudo iocage get state {$jailName} 2>/dev/null";
        $output = shell_exec($cmd);

        // Trim and check if state equals "up"
        $status = is_string($output) ? trim($output) : '';
        return $status === 'up';
    }

    /**
     * {@inheritdoc}
     */
    public function assertJailRootExists(string $jailName): void
    {
        $rootPath = $this->getJailsMountPath() . "/{$jailName}/root";

        // 🛡 Skip check if dry-run is enabled
        if ($this->shell->isDryRun()) {
            echo "🔇 [DRY-RUN] Skipping jail root existence check: {$rootPath}\n";
            return;
        }

        if (!is_dir($rootPath)) {
            throw new RuntimeException("❌ Jail root '{$rootPath}' does not exist after snapshot transfer.");
        }
    }

    public function exec(
        string $jailName, 
        string $command, 
        string $description = ''
    ): string
    {
        return $this->shell->shell(
            "sudo iocage exec {$jailName} {$command}",
            $description
        );
    }        

    public function execMySQLRemote(
        string $remoteHost, 
        string $sshKey, 
        string $jailName, 
        string $query, 
        string $description = ''
    ): string
    {
        $bin = \Config::get('MYSQL_BIN_PATH');

        $cmd = <<<EOD
ssh -i {$sshKey} {$remoteHost} "sudo iocage exec {$jailName} {$bin} -N -e '{$query}'"
EOD;

        return $this->shell->shell($cmd, $description);
    }

    public function execMySqlRemoteMultiLine(string $remoteHost, string $sshKey, string $jailName, string $sqlContent, string $description = ''): string 
    {
        $bin = \Config::get('MYSQL_BIN_PATH');

        $cmd = <<<EOD
echo "{$sqlContent}" | ssh -i {$sshKey} {$remoteHost} "sudo iocage exec {$jailName} sh -c '{$bin}'"
EOD;

        return $this->shell->run($cmd, $description);
    }

    public function runService(
        string $jailName, 
        string $service, 
        string $action, 
        string $description = ''
    ): void
    {
        $this->shell->run(
            "sudo iocage exec {$jailName} service {$service} {$action}",
            $description
        );
    }

    public function removeFile(
        string $jailName, 
        string $filePath
    ): void
    {
        $this->shell->run(
            "sudo iocage exec {$jailName} rm -f {$filePath}",
            "Remove file '{$filePath}' in jail '{$jailName}'"
        );
    }

    public function enableBoot(string $jailName): void
    {
        $this->shell->run(
            "sudo iocage set boot=on {$jailName}",
            "Enable and mount {$jailName} jail"
        );
    }

    public function getJailRootPath(string $jailName): string
    {
        return \Config::get('IOCAGE_JAILS_MOUNT_PATH') . "/{$jailName}/root";
    }

    public function getJailConfigPath(string $jailName): string
    {
        return \Config::get('IOCAGE_JAILS_MOUNT_PATH') . "/{$jailName}/config.json";
    }

    public function getSnapshotBackupDir(): string
    {
        return \Config::get('IOCAGE_SNAPSHOT_BACKUP_DIR');
    }

    public function getJailsMountPath(): string
    {
        return \Config::get('IOCAGE_JAILS_MOUNT_PATH');
    }

    public function getJailsDatasetPath(): string
    {
        return \Config::get('IOCAGE_JAILS_DATASET_PATH');
    }

    public function getJailZfsDatasetPath(): string
    {
        return \Config::get('IOCAGE_JAIL_ZFS_DATASET_PATH');
    }
}
