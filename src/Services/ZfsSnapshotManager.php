<?php

namespace Monsefrachid\MysqlReplication\Services;

use Monsefrachid\MysqlReplication\Support\ShellRunner;
use RuntimeException;

/**
 * Class ZfsSnapshotManager
 *
 * Handles creation, transfer, and verification of ZFS snapshots
 * across local and remote systems using SSH and ZFS CLI.
 */
class ZfsSnapshotManager
{
    /**
     * @var ShellRunner
     */
    private ShellRunner $shell;

    /**
     * SSH identity key to use with remote commands
     *
     * @var string
     */
    private string $sshKey;

    /**
     * Constructor
     *
     * @param ShellRunner $shell
     * @param string $sshKey
     */
    public function __construct(ShellRunner $shell, string $sshKey)
    {
        $this->shell = $shell;
        $this->sshKey = $sshKey;
    }

    /**
     * Create a recursive snapshot on the remote system.
     *
     * @param string $remote         user@host
     * @param string $jailName       The source jail name
     * @param string $snapshotSuffix e.g., replica_20250620
     *
     * @return string The full snapshot name (e.g., jail@suffix)
     */
    public function createRemoteSnapshot0(string $remote, string $jailName, string $snapshotSuffix): string
    {
        $snapshot = "{$jailName}@{$snapshotSuffix}";
        $this->shell->run(
            "ssh {$this->sshKey} {$remote} sudo zfs snapshot -r tank/iocage/jails/{$snapshot}",
            "Create remote ZFS snapshot: {$snapshot}"
        );
        return $snapshot;
    }

    public function createRemoteSnapshot(string $remote, string $jailName, string $snapshotSuffix): string
    {
        $snapshotName = "{$jailName}_{$snapshotSuffix}";
        $remoteBackupDir = "/tank/backups/iocage/jail";
        $snapshotFull = "tank/iocage/jails/{$jailName}@{$snapshotSuffix}";
        $zfsFile = "{$remoteBackupDir}/{$snapshotName}.zfs";
        $metaFile = "{$remoteBackupDir}/{$snapshotName}.meta";

        // Step 1: Capture master status
        $cmdMasterStatus = "ssh {$this->sshKey} {$remote} \"sudo iocage exec {$jailName} /usr/local/bin/mysql -N -e 'SHOW MASTER STATUS'\"";
        $output = $this->shell->shell($cmdMasterStatus, "Fetch MySQL master status on {$jailName}");
        $lines = explode("\n", trim($output));

        if (count($lines) < 1 || !str_contains($lines[0], "\t")) {
            throw new \RuntimeException("Unexpected output when fetching master status:\n{$output}");
        }

        [$logFile, $logPos] = explode("\t", $lines[0]);

        // Step 2: Create snapshot
        $this->shell->run(
            "ssh {$this->sshKey} {$remote} sudo zfs snapshot -r {$snapshotFull}",
            "Create snapshot {$snapshotFull} on remote"
        );

        // Step 3: Send snapshot to file
        $this->shell->run(
            "ssh {$this->sshKey} {$remote} \"sudo zfs send -R {$snapshotFull} | sudo tee {$zfsFile} > /dev/null\"",
            "Send snapshot to file {$zfsFile}"
        );

        // Step 4: Write meta file (log file and position)
        $this->shell->run(
            "ssh {$this->sshKey} {$remote} \"echo '{$logFile}' > /tmp/{$snapshotName}.meta && echo '{$logPos}' >> /tmp/{$snapshotName}.meta && sudo mv /tmp/{$snapshotName}.meta {$metaFile}\"",
            "Write binlog metadata to {$metaFile}"
        );

        return $snapshotName; // base name without .zfs or .meta extension
    }

    public function receiveSnapshotFromRemoteFile(
        string $remote,
        string $snapshotName,
        string $targetJailName
    ): void {
        $remotePath = "/tank/backups/iocage/jail";
        $localPath = "/tank/backups/iocage/jail";
        $zfsFile = "{$snapshotName}.zfs";
        $metaFile = "{$snapshotName}.meta";

        // Step 1: SCP both .zfs and .meta from remote
        $this->shell->run(
            "scp -i {$this->sshKey} {$remote}:{$remotePath}/{$zfsFile} {$remote}:{$remotePath}/{$metaFile} {$localPath}/",
            "Transfer snapshot and metadata from remote to local"
        );

        // Step 2: Receive snapshot from .zfs file
        $this->shell->run(
            "sudo zfs receive -F tank/iocage/jails/{$targetJailName} < {$localPath}/{$zfsFile}",
            "Receive snapshot into jail {$targetJailName}"
        );
    }

    /**
     * Verify that the snapshot exists on the remote system.
     *
     * @param string $remote
     * @param string $snapshotSuffix
     *
     * @return void
     */
    public function verifyRemoteSnapshot0(string $remote, string $snapshotSuffix): void
    {
        $this->shell->run(
            "ssh {$this->sshKey} {$remote} zfs list -t snapshot | grep {$snapshotSuffix}",
            "Verify remote snapshot exists"
        );
    }

    public function verifyRemoteSnapshot(string $remote, string $snapshotSuffix): void
    {
        $zfsPath = "/tank/backups/iocage/jail/{$snapshotSuffix}.zfs";
        $metaPath = "/tank/backups/iocage/jail/{$snapshotSuffix}.meta";

        $cmd = "ssh {$this->sshKey} {$remote} '[ -f {$zfsPath} ] && [ -f {$metaPath} ]'";

        try {
            $this->shell->run($cmd, "Verify snapshot and metadata files exist on remote");
        } catch (RuntimeException $e) {
            throw new RuntimeException("❌ Remote snapshot verification failed: snapshot or metadata not found for '{$snapshotSuffix}'");
        }
    }

    /**
     * Send snapshot from remote to local jail ZFS dataset.
     *
     * @param string $remote
     * @param string $snapshot
     * @param string $targetJailName
     *
     * @return void
     */
    public function sendSnapshotToLocal(string $remote, string $snapshot, string $targetJailName): void
    {
        $this->shell->run(
            "ssh {$this->sshKey} {$remote} sudo zfs send -R tank/iocage/jails/{$snapshot} | sudo zfs recv -F tank/iocage/jails/{$targetJailName}",
            "Send and receive ZFS snapshot for jail '{$targetJailName}'",
            "Failed to ZFS receive replica"
        );
    }
}
