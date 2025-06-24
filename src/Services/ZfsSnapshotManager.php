<?php

namespace Monsefrachid\MysqlReplication\Services;

use Monsefrachid\MysqlReplication\Support\ShellRunner;
use Monsefrachid\MysqlReplication\Support\Config;
use Monsefrachid\MysqlReplication\Support\LoggerTrait;
use Monsefrachid\MysqlReplication\Contracts\JailDriverInterface;
use RuntimeException;

/**
 * Manages ZFS snapshot creation, transfer, and storage for jail-based MySQL replication.
 *
 * Responsible for both local and remote snapshot handling, including:
 * - Creating recursive snapshots of source jails
 * - Transferring snapshots using `zfs send/receive` or SSH
 * - Storing associated metadata in the backup directory
 */
class ZfsSnapshotManager
{
    use LoggerTrait;
    
    /**
     * Executes shell commands with optional dry-run support.
     *
     * @var ShellRunner
     */
    private ShellRunner $shell;

    /**
     * Path to the SSH private key used for remote ZFS snapshot operations.
     *
     * @var string
     */
    private string $sshKey;

    /**
     * Local filesystem path where ZFS snapshot `.zfs` and `.meta` files are stored.
     *
     * Typically something like `/tank/backups/iocage/jail/`.
     *
     * @var string
     */
    private string $snapshotBackupPath;

    /**
     * ZFS dataset path where all jails are located (e.g., `tank/iocage/jails`).
     *
     * Used when building full snapshot identifiers or target paths.
     *
     * @var string
     */
    private string $jailsDatasetPath; 

    /**
     * ZfsSnapshotManager constructor.
     *
     * Initializes dependencies and pulls snapshot-related paths from the jail driver.
     *
     * @param ShellRunner           $shell       Shell executor for system-level commands.
     * @param JailDriverInterface   $jailDriver  Provides jail-aware path access and dataset resolution.
     * @param string                $sshKey      SSH private key path for remote snapshot operations.
     */
    public function __construct(
        ShellRunner $shell,
        protected JailDriverInterface $jailDriver,
        string $sshKey        
    )
    {
        $this->shell = $shell;
        $this->sshKey = $sshKey;

        // Snapshot and dataset paths derived from jail driver configuration
        $this->snapshotBackupPath = $this->jailDriver->getSnapshotBackupDir();
        $this->jailsDatasetPath = $this->jailDriver->getJailsDatasetPath();
    }

    /**
     * Create a ZFS snapshot on a remote host for the given jail and fetch MySQL binlog metadata.
     *
     * This method:
     *   - Executes `SHOW MASTER STATUS` remotely to capture binlog file and position.
     *   - Constructs consistent snapshot and metadata filenames.
     *   - Prepares data for subsequent snapshot send/export.
     *
     * @param string $remote         SSH target for the remote host (e.g., user@host).
     * @param string $jailName       The name of the jail to snapshot.
     * @param string $snapshotSuffix A unique suffix (usually timestamp-based) for naming the snapshot.
     *
     * @return string The generated snapshot name, e.g., `mysql_jail_20250624153000`.
     *
     * @throws \RuntimeException If master status output is invalid or cannot be parsed.
     */
    public function createRemoteSnapshot(string $remote, string $jailName, string $snapshotSuffix): string
    {
        // Construct snapshot and file names
        $snapshotName = "{$jailName}_{$snapshotSuffix}";
        $remoteBackupDir = $this->snapshotBackupPath;
        $snapshotFull = "{$this->jailsDatasetPath}/{$jailName}@{$snapshotSuffix}";

        $zfsFile = "{$remoteBackupDir}/{$snapshotName}.zfs";
        $metaFile = "{$remoteBackupDir}/{$snapshotName}.meta";

        $mysqlBinPath = Config::get('MYSQL_BIN_PATH');

        // Dry-run mode: skip real MySQL interaction
        if ($this->shell->isDryRun()) {
            $this->logDryRun("Skipping remote MySQL query parsing\n");
            $logFile = 'mysql-bin.000001';
            $logPos = 1234;
        } else {
            // Step 1: Execute SHOW MASTER STATUS on the remote jail
            $output = $this->jailDriver->execMySQLRemote(
                $remote, 
                $this->sshKey, 
                $jailName, 
                'SHOW MASTER STATUS', 
                "Fetch MySQL master status on {$jailName}"
            );

            $lines = explode("\n", trim($output));

            // Validate output
            if (count($lines) < 1 || !str_contains($lines[0], "\t")) {
                throw new \RuntimeException("Unexpected output when fetching master status:\n{$output}");
            }

            // Extract binlog file and position
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

        // Step 4: Get primary host IP
        $primaryIp = trim($this->shell->shell(
            "ssh -i {$this->sshKey} {$remote} \"ifconfig vtnet0 | awk '/inet / {print \\$2}'\"",
            "Get primary droplet IP"
        ));

        // Step 5: Write meta file
        $this->shell->run(
            "ssh -i {$this->sshKey} {$remote} \"echo '{$logFile}' > /tmp/{$snapshotName}.meta && echo '{$logPos}' >> /tmp/{$snapshotName}.meta && echo '{$primaryIp}' >> /tmp/{$snapshotName}.meta && echo '{$jailName}' >> /tmp/{$snapshotName}.meta && sudo mv /tmp/{$snapshotName}.meta {$metaFile}\"",
            "Write binlog metadata to {$metaFile}"
        );

        return $snapshotName; // base name without .zfs or .meta extension
    }

    /**
     * Receives a jail snapshot from a remote host by fetching its `.zfs` and `.meta` files,
     * then applying the ZFS snapshot to the local dataset.
     *
     * Steps:
     *   1. Transfers the `.zfs` (snapshot) and `.meta` (replication metadata) files via SCP.
     *   2. Executes `zfs receive` to import the snapshot into the local jail dataset.
     *
     * @param string $remote         SSH target of the remote host (e.g., user@host).
     * @param string $snapshotName   Name of the snapshot (e.g., mysql_jail_20250624153000).
     * @param string $targetJailName The local jail name to receive the snapshot into.
     *
     * @return void
     */
    public function receiveSnapshotFromRemoteFile(
        string $remote,
        string $snapshotName,
        string $targetJailName
    ): void {
        // Paths where snapshot and metadata are stored on both remote and local
        $remotePath = $this->snapshotBackupPath;
        $localPath = $this->snapshotBackupPath;

        // Filenames for snapshot data and its associated metadata
        $zfsFile = "{$snapshotName}.zfs";
        $metaFile = "{$snapshotName}.meta";

        // Step 1: Copy both snapshot and metadata from remote to local backup path
        $this->shell->run(
            "scp -i {$this->sshKey} {$remote}:{$remotePath}/{$zfsFile} {$remote}:{$remotePath}/{$metaFile} {$localPath}/",
            "Transfer snapshot and metadata from remote to local"
        );

        // Step 2: Apply the snapshot using `zfs receive` to the local jail dataset
        $this->shell->run(
            "sudo zfs receive -F {$this->jailsDatasetPath}/{$targetJailName} < {$localPath}/{$zfsFile}",
            "Receive snapshot into jail {$targetJailName}"
        );
    }

    /**
     * Applies a locally stored ZFS snapshot file to a target jail dataset.
     *
     * This is used when the `.zfs` file is already present on the local machine,
     * typically after a manual transfer or prior remote SCP operation.
     *
     * @param string $snapshotName   The name of the snapshot (e.g., mysql_jail_20250624153000).
     * @param string $targetJailName The jail name to receive the snapshot into.
     *
     * @return void
 */
    public function receiveSnapshotFromLocal(
        string $snapshotName,
        string $targetJailName
    ): void {
        // Path to the local snapshot backup directory
        $localPath = $this->snapshotBackupPath;

        // Name of the ZFS snapshot file to apply
        $zfsFile = "{$snapshotName}.zfs";

        // Apply the snapshot using `zfs receive` to the local jail dataset
        $this->shell->run(
            "sudo zfs receive -F {$this->jailsDatasetPath}/{$targetJailName} < {$localPath}/{$zfsFile}",
            "Receive snapshot into jail {$targetJailName}"
        );
    }

    /**
     * Verifies that both the `.zfs` and `.meta` snapshot files exist on a remote host.
     *
     * Uses an SSH command to test file existence before attempting snapshot transfer.
     * Throws an exception (via ShellRunner) if either file is missing.
     *
     * @param string $remote        SSH target of the remote host (e.g., user@host).
     * @param string $snapshotName  The base name of the snapshot (without extension).
     *
     * @return void
     */
    public function verifyRemoteSnapshot(string $remote, string $snapshotName): void
    {
        // Base path to snapshot files on the remote system
        $base = "{$this->snapshotBackupPath}/{$snapshotName}";

        // Compound shell condition to verify both .zfs and .meta exist
        $cmd = "[ -f {$base}.zfs ] && [ -f {$base}.meta ]";

        // Run SSH check remotely
        $this->shell->run(
            "ssh -i {$this->sshKey} {$remote} '{$cmd}'",
            "Verify snapshot and metadata files exist on remote"
        );

        $this->logSuccess("Remote snapshot and metadata verified for '{$snapshotName}'");
    }

    /**
     * Verifies that both the `.zfs` and `.meta` files for a given snapshot exist locally.
     *
     * Throws an exception if either file is missing, ensuring snapshot integrity before use.
     *
     * @param string $snapshotName The base name of the snapshot (without extension).
     *
     * @throws RuntimeException If `.zfs` or `.meta` file is not found.
     *
     * @return void
     */
    public function verifyLocalSnapshot(string $snapshotName): void
    {
        // Construct the full base path to the snapshot files
        $base = "{$this->snapshotBackupPath}/{$snapshotName}";

        // Check for existence of both required files
        if (!file_exists("{$base}.zfs") || !file_exists("{$base}.meta")) {
            throw new RuntimeException("❌ Local snapshot verification failed: missing .zfs or .meta for '{$snapshotName}'");
        }
        
        $this->logSuccess("Local snapshot and metadata verified for '{$snapshotName}'");
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
        // Construct the snapshot name in format jailName@suffix        
        $snapshot = "{$jailName}@{$snapshotSuffix}";

        // Construct the command
        $command = "ssh -i {$this->sshKey} {$remote} sudo zfs snapshot -r {$this->jailsDatasetPath}/{$snapshot}";

        try {
            // Remotely execute the ZFS snapshot creation command
            $this->shell->run(
                $command,
                "Create remote ZFS snapshot: {$snapshot}"
            );
        } catch (\Throwable $e) {
            $errorMessage = "❌ Failed to create remote snapshot '{$snapshot}' on host '{$remote}': " . $e->getMessage();            
            throw new \RuntimeException($errorMessage, 0, $e);
        }

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
        $command = "ssh -i {$this->sshKey} {$remote} zfs list -t snapshot | grep {$snapshotSuffix}";

        try {
            $this->shell->run(
                $command,
                "Verify remote snapshot exists"
            );
        } catch (\Throwable $e) {
            $errorMessage = "❌ Remote snapshot with suffix '{$snapshotSuffix}' not found or verification failed on host '{$remote}': " . $e->getMessage();
            throw new \RuntimeException($errorMessage, 0, $e);
        }
    }

    /**
     * Streams a ZFS snapshot directly from a remote host into the local replica jail dataset.
     *
     * This performs a `zfs send` over SSH and immediately pipes it into `zfs recv` locally.
     * It avoids creating intermediate `.zfs` files and is suitable for large or live replicas.
     *
     * @param string $remote         SSH target of the remote host (e.g., user@host).
     * @param string $snapshot       Full snapshot name (e.g., `mysql_jail@20250624170000`).
     * @param string $targetJailName The local jail name to receive the streamed snapshot.
     *
     * @throws RuntimeException If the streaming or receiving process fails.
     *
     * @return void
     */
    public function streamSnapshotToLocal(string $remote, string $snapshot, string $targetJailName): void
    {
        try {
            $this->shell->run(
                "ssh -i {$this->sshKey} {$remote} sudo zfs send -R {$this->jailsDatasetPath}/{$snapshot} | sudo zfs recv -F {$this->jailsDatasetPath}/{$targetJailName}",
                "Send and receive ZFS snapshot for jail '{$targetJailName}'",
                "Failed to ZFS receive replica"
            );
        } catch (\Throwable $e) {
            $errorMessage = "❌ Snapshot stream from remote '{$remote}' failed for jail '{$targetJailName}': " . $e->getMessage();
            error_log($errorMessage);
            throw new \RuntimeException($errorMessage, 0, $e);
        }
    }
}
