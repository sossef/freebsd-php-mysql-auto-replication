<?php

namespace Monsefrachid\MysqlReplication\Services;

use Monsefrachid\MysqlReplication\Support\ShellRunner;
use Monsefrachid\MysqlReplication\Contracts\JailDriverInterface;
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

    private string $snapshotBackupPath;    

    private string $jailsDatasetPath;    

    /**
     * Constructor
     *
     * @param ShellRunner $shell
     * @param string $sshKey
     */
    public function __construct(
        ShellRunner $shell,
        string $sshKey,
        protected JailDriverInterface $jail
    )
    {
        $this->shell = $shell;
        $this->sshKey = $sshKey;
        $this->snapshotBackupPath = \Config::get('SNAPSHOT_BACKUP_DIR');
        $this->jailsDatasetPath = \Config::get('JAILS_DATASET_PATH');
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
    public function createRemoteSnapshot(string $remote, string $jailName, string $snapshotSuffix): string
    {
        $snapshotName = "{$jailName}_{$snapshotSuffix}";
        $remoteBackupDir = $this->snapshotBackupPath;
        $snapshotFull = "{$this->jailsDatasetPath}/{$jailName}@{$snapshotSuffix}";
        $zfsFile = "{$remoteBackupDir}/{$snapshotName}.zfs";
        $metaFile = "{$remoteBackupDir}/{$snapshotName}.meta";
        $mysqlBinPath = \Config::get('MYSQL_BIN_PATH');

        if ($this->shell->isDryRun()) {
            echo "🔇 [DRY-RUN] Skipping remote MySQL query parsing\n";
            $logFile = 'mysql-bin.000001';
            $logPos = 1234;
        } else {
            // Step 1: Capture master status
            $output = $this->jail->execMySQLRemote($remote, $this->sshKey, $jailName, 'SHOW MASTER STATUS', "Fetch MySQL master status on {$jailName}");
            $lines = explode("\n", trim($output));

            if (count($lines) < 1 || !str_contains($lines[0], "\t")) {
                throw new \RuntimeException("Unexpected output when fetching master status:\n{$output}");
            }

            [$logFile, $logPos] = explode("\t", $lines[0]);
        }        

        // Step 2: Create snapshot
        $this->shell->run(
            "ssh -i {$this->sshKey} {$remote} sudo zfs snapshot -r {$snapshotFull}",
            "Create snapshot {$snapshotFull} on remote"
        );

        // Step 3: Send snapshot to file
        $this->shell->run(
            "ssh -i {$this->sshKey} {$remote} \"sudo zfs send -R {$snapshotFull} | sudo tee {$zfsFile} > /dev/null\"",
            "Send snapshot to file {$zfsFile}"
        );

        // Step 4: Write meta file (log file and position)
        // Get primary host IP
        $primaryIp = trim($this->shell->shell(
            "ssh -i {$this->sshKey} {$remote} \"ifconfig vtnet0 | awk '/inet / {print \\$2}'\"",
            "Get primary droplet IP"
        ));
        // Write meta file
        $this->shell->run(
            "ssh -i {$this->sshKey} {$remote} \"echo '{$logFile}' > /tmp/{$snapshotName}.meta && echo '{$logPos}' >> /tmp/{$snapshotName}.meta && echo '{$primaryIp}' >> /tmp/{$snapshotName}.meta && echo '{$jailName}' >> /tmp/{$snapshotName}.meta && sudo mv /tmp/{$snapshotName}.meta {$metaFile}\"",
            "Write binlog metadata to {$metaFile}"
        );

        return $snapshotName; // base name without .zfs or .meta extension
    }

    /**
     * Transfers a ZFS snapshot and its metadata file from a remote server and
     * receives it into a local jail dataset.
     *
     * @param string $remote           SSH target string (e.g. user@host) of the source server.
     * @param string $snapshotName     Name of the snapshot (without extension) to transfer and apply.
     * @param string $targetJailName   Name of the jail to receive the snapshot into.
     *
     * @throws RuntimeException        If SCP or ZFS receive command fails.
     */
    public function receiveSnapshotFromRemoteFile(
        string $remote,
        string $snapshotName,
        string $targetJailName
    ): void {
        $remotePath = $this->snapshotBackupPath;
        $localPath = $this->snapshotBackupPath;
        $zfsFile = "{$snapshotName}.zfs";
        $metaFile = "{$snapshotName}.meta";

        // Step 1: SCP both .zfs and .meta from remote
        $this->shell->run(
            "scp -i {$this->sshKey} {$remote}:{$remotePath}/{$zfsFile} {$remote}:{$remotePath}/{$metaFile} {$localPath}/",
            "Transfer snapshot and metadata from remote to local"
        );

        // Step 2: Receive snapshot from .zfs file
        $this->shell->run(
            "sudo zfs receive -F {$this->jailsDatasetPath}/{$targetJailName} < {$localPath}/{$zfsFile}",
            "Receive snapshot into jail {$targetJailName}"
        );
    }

    public function receiveSnapshotFromLocal(
        string $snapshotName,
        string $targetJailName
    ): void {
        $localPath = $this->snapshotBackupPath;
        $zfsFile = "{$snapshotName}.zfs";

        // Step 2: Receive snapshot from .zfs file
        $this->shell->run(
            "sudo zfs receive -F {$this->jailsDatasetPath}/{$targetJailName} < {$localPath}/{$zfsFile}",
            "Receive snapshot into jail {$targetJailName}"
        );
    }

    /**
     * Verify that the snapshot exists on the remote system.
     *
     * @param string $remote
     * @param string $snapshotName
     *
     * @return void
     */
    public function verifyRemoteSnapshot(string $remote, string $snapshotName): void
    {
        $base = "{$this->snapshotBackupPath}/{$snapshotName}";
        $cmd = "[ -f {$base}.zfs ] && [ -f {$base}.meta ]";
        $this->shell->run(
            "ssh -i {$this->sshKey} {$remote} '{$cmd}'",
            "Verify snapshot and metadata files exist on remote"
        );
    }

    /**
     * Verify that the snapshot exists on in local backup folder.
     *
     * @param string $snapshotName
     *
     * @return void
     */
    public function verifyLocalSnapshot(string $snapshotName): void
    {
        $base = "{$this->snapshotBackupPath}/{$snapshotName}";
        if (!file_exists("{$base}.zfs") || !file_exists("{$base}.meta")) {
            throw new RuntimeException("❌ Local snapshot verification failed: missing .zfs or .meta for '{$snapshotName}'");
        }

        echo "✅ Local snapshot and metadata verified for '{$snapshotName}'\n";
    }

    // Deprecated but preserved for fallback/alternate use

    /**
     * Create a live recursive ZFS snapshot on the remote jail dataset.
     *
     * This method does not store the snapshot as a .zfs file.
     * Instead, it is intended for direct stream-based replication.
     *
     * @param string $remote        SSH target (e.g., user@host)
     * @param string $jailName      Name of the source jail (e.g., mysql_jail_primary)
     * @param string $snapshotSuffix Suffix to append to the snapshot name (e.g., replica_YYYYMMDDHHMMSS)
     * @return string               Full snapshot name (e.g., mysql_jail_primary@replica_20250623010101)
     */
    public function createRemoteSnapshotLive(string $remote, string $jailName, string $snapshotSuffix): string
    {
        $snapshot = "{$jailName}@{$snapshotSuffix}";
        $this->shell->run(
            "ssh -i {$this->sshKey} {$remote} sudo zfs snapshot -r {$this->jailsDatasetPath}/{$snapshot}",
            "Create remote ZFS snapshot: {$snapshot}"
        );
        return $snapshot;
    }

    /**
     * Verify that a live ZFS snapshot exists on the remote system.
     *
     * This checks the output of `zfs list -t snapshot` for the expected snapshot name.
     *
     * @param string $remote         SSH target (e.g., user@host)
     * @param string $snapshotSuffix Snapshot suffix to verify (e.g., replica_YYYYMMDDHHMMSS)
     */
    public function verifyRemoteSnapshotLive(string $remote, string $snapshotSuffix): void
    {
        $this->shell->run(
            "ssh -i {$this->sshKey} {$remote} zfs list -t snapshot | grep {$snapshotSuffix}",
            "Verify remote snapshot exists"
        );
    }

    /**
     * Stream a remote ZFS snapshot directly into a local ZFS dataset.
     *
     * This bypasses intermediate .zfs file creation and uses a live ZFS send/receive pipeline.
     * Requires both source and target systems to be online and accessible via SSH.
     *
     * @param string $remote        SSH target (e.g., user@host)
     * @param string $snapshot      Full snapshot name (e.g., mysql_jail_primary@replica_YYYYMMDDHHMMSS)
     * @param string $targetJailName Name of the new jail to create locally
     */
    public function streamSnapshotToLocal(string $remote, string $snapshot, string $targetJailName): void
    {
        $this->shell->run(
            "ssh -i {$this->sshKey} {$remote} sudo zfs send -R {$this->jailsDatasetPath}/{$snapshot} | sudo zfs recv -F {$this->jailsDatasetPath}/{$targetJailName}",
            "Send and receive ZFS snapshot for jail '{$targetJailName}'",
            "Failed to ZFS receive replica"
        );
    }
}
