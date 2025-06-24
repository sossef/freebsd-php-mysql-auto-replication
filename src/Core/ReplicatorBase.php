<?php

namespace Monsefrachid\MysqlReplication\Core;

use Monsefrachid\MysqlReplication\Support\ShellRunner;
use Monsefrachid\MysqlReplication\Support\MetaInfo;
use Monsefrachid\MysqlReplication\Support\Logger;
use Monsefrachid\MysqlReplication\Support\LoggerTrait;
use Monsefrachid\MysqlReplication\Services\ZfsSnapshotManager;
use Monsefrachid\MysqlReplication\Services\JailManager;
use Monsefrachid\MysqlReplication\Contracts\JailDriverInterface;
use Monsefrachid\MysqlReplication\Services\IocageJailDriver;
use Monsefrachid\MysqlReplication\Services\JailConfigurator;
use Monsefrachid\MysqlReplication\Services\CertManager;
use Monsefrachid\MysqlReplication\Services\MySqlConfigurator;
use Monsefrachid\MysqlReplication\Services\ReplicationVerifier;

/**
 * Class Replicator
 *
 * Orchestrates the MySQL replication setup from a source jail to a replica jail.
 * This version supports replication from a remote FreeBSD jail via ZFS snapshot.
 */
abstract class ReplicatorBase
{
    // Inject logging helpers from LoggerTrait
    use LoggerTrait;

    /**
     * SSH user@host of the source jail (e.g., "user@192.168.1.10")
     *
     * @var string
     */
    protected string $from;

    /**
     * Jail name of the source (e.g., "mysql_jail_primary")
     *
     * @var string
     */
    protected string $sourceJail;

    /**
     * Jail name of the replica to create (e.g., "mysql_jail_replica")
     *
     * @var string
     */
    protected string $replicaJail;

    /**
     * SSH identity key to use with remote commands (e.g. -i ~/.ssh/id_digitalocean)
     *
     * @var string
     */
    protected string $sshKey;

    /**
     * Whether to force overwrite if the replica jail already exists
     *
     * @var bool
     */
    protected bool $force;

    /**
     * Whether to simulate execution without actually running commands
     *
     * @var bool
     */
    protected bool $dryRun;

    /**
     * Whether to skip the end-to-end replication test after setup
     *
     * @var bool
     */
    protected bool $skipTest;

    /**
     * Metadata about the current replication snapshot.
     *
     * Holds information such as snapshot name, binlog file, and position,
     * used during MySQL replication setup. May be null if not yet initialized.
     *
     * @var MetaInfo|null
     */
    protected ?MetaInfo $meta = null;

    /**
     * Handles shell command execution
     *
     * @var ShellRunner
     */
    protected ShellRunner $shell;

    /**
     * Handles ZFS snapshot operations
     *
     * @var ZfsSnapshotManager
     */
    protected ZfsSnapshotManager $zfs;

    /**
     * Handles jail existence checks, destruction, and validation
     *
     * @var JailManager
     */
    protected JailManager $jails;

    /**
     * Driver responsible for interacting with iocage jails.
     *
     * Abstracts jail operations such as execution, service control,
     * and file manipulation across both local and remote contexts.
     *
     * @var JailDriverInterface
     */
    protected JailDriverInterface $jailDriver;

    /**
     * Configures replica jail's network, hostname, and other flags.
     *
     * @var JailConfigurator
     */
    protected JailConfigurator $jailConfigurator;

    /**
     * Transfers SSL certs from remote source jail to replica.
     *
     * @var CertManager
     */
    protected CertManager $certsManager;

    /**
     * Configures my.cnf and restarts MySQL in the replica jail.
     *
     * @var MySqlConfigurator
     */
    protected MySqlConfigurator $mySqlConfigurator;

    /**
     * Handles replication SQL injection and verification.
     *
     * @var ReplicationVerifier
     */
    protected ReplicationVerifier $replicationVerifier;

    /**
     * Constructor
     *
     * @param string $from Format: user@host:jailName
     * @param string $to Format: localhost:replicaJailName
     * @param bool $force
     * @param bool $dryRun
     * @param bool $skipTest
     * @param string $sshKey Optional SSH key argument to use with remote connections
     */
    public function __construct(
        string $from,
        string $to,
        bool $force = false,
        bool $dryRun = false,
        bool $skipTest = false,
        string $sshKey = '',
    ) {
        // Parse the replication source and target strings.
        // Format is "user@host:jailName" for remote, or just ":jailName" for local.
        [$this->from, $this->sourceJail] = explode(':', $from);
        [, $this->replicaJail] = explode(':', $to);

        // Store replication options
        $this->force = $force;         // Whether to force overwrite existing replica
        $this->dryRun = $dryRun;       // If true, commands will be logged but not executed
        $this->skipTest = $skipTest;   // If true, final replication verification will be skipped
        $this->sshKey = $sshKey;       // Path to SSH key for remote connections

        // Initialize shell runner, used for all system command execution
        $this->shell = new ShellRunner($this->dryRun);

        // Initialize jail driver (local or remote), responsible for jail-related operations
        $this->jailDriver = new IocageJailDriver($this->shell);

        // Initialize snapshot manager, handles ZFS send/receive and snapshot naming
        $this->zfs = new ZfsSnapshotManager(
            $this->shell, 
            $this->jailDriver,
            $this->sshKey
        );

        // Initialize jail manager, high-level controller for jail lifecycle (start/stop/etc.)
        $this->jails = new JailManager($this->jailDriver);

        // Initialize jail configurator, responsible for boot, fstab, and file system setup
        $this->jailConfigurator = new JailConfigurator(
            $this->shell, 
            $this->jailDriver
        );

        // Initialize certificate manager, responsible for transferring and placing SSL files
        $this->certsManager = new CertManager(
            $this->shell, 
            $this->jailDriver,
            $this->sshKey
        );

        // Initialize MySQL configurator, handles replication setup inside the replica jail
        $this->mySqlConfigurator = new MySqlConfigurator(
            $this->shell, 
            $this->jailDriver,
            $this->sshKey
        );

        // Initialize verifier, performs final validation to ensure replication is successful
        $this->replicationVerifier = new ReplicationVerifier(
            $this->shell, 
            $this->jailDriver, 
            $this->sshKey, 
            $this->dryRun
        );
    }    

    /**
     * Orchestrates the full replication workflow from source to replica jail.
     *
     * Steps:
     *   0. Check if the replica jail exists and optionally destroy it if --force is set.
     *   1. Prepare a snapshot from the source jail.
     *   2. Ensure the root path for the replica jail exists.
     *   3. Configure and start the replica jail.
     *   4. Transfer SSL certificates from source to replica jail.
     *   5. Load snapshot metadata and configure MySQL replication.
     *   6. Verify replication with a test query.
     *
     * @return void
     */
    public function run(): void
    {
        Logger::load(__DIR__ . '/../../logs'); // default name: tmp

        // Display replication source/target and runtime flags
        $this->log("\n🛠️ Running replication from '{$this->from}:{$this->sourceJail}' to '{$this->replicaJail}'\n\n");
        $this->log('Flags: force=' . ($this->force ? 'true' : 'false') .
            ', dryRun=' . ($this->dryRun ? 'true' : 'false') .
            ', skipTest=' . ($this->skipTest ? 'true' : 'false') . "\n");

        // Check if the target replica jail already exists
        if ($this->jails->exists($this->replicaJail)) {
            if ($this->force) {
                // If --force flag is set, destroy the existing jail to proceed
                $this->logWarning("[FORCE] Jail '{$this->replicaJail}' already exists. Destroying...");

                $this->jails->destroy($this->replicaJail);
            } else {
                // Otherwise, halt execution to avoid accidental overwrite
                $this->logError("Jail '{$this->replicaJail}' already exists. Use --force to overwrite.");

                exit(1);
            }
        }

        // Step 1: Create or retrieve snapshot from source jail
        $snapshotName = $this->prepareSnapshot();

        // Rename log file using snapshot name
        $this->renameLogFile($snapshotName);

        // Step 2: Ensure the replica jail's root directory is in place
        $this->jails->assertRootExists($this->replicaJail);

        // Step 3: Configure system files and start the replica jail
        $this->jailConfigurator->configure($this->replicaJail);
        $this->jails->startJail($this->replicaJail);

        // Step 4: Transfer required SSL certificates to replica jail
        $this->transferCertificates();

        // Step 5: Load binlog metadata and configure MySQL replica settings
        $this->loadMetaData($snapshotName);
        $this->mySqlConfigurator->configure(
            $this->replicaJail,
            $snapshotName,
            $this->meta
        );

        // Step 6: Test replication health and correctness
        $replicaStatus = $this->replicationVerifier->verifyReplicaStatus(
            $this->meta->masterHost,
            $this->meta->masterJailName,
            $this->sourceJail,
            $this->replicaJail,
            $this->skipTest
        );

        if ($replicaStatus) {
            // Final confirmation
            $this->logSuccess("Replica setup complete and replication initialized. See report in " . $this->getLogFilePath());
            $this->log("\n\n{$replicaStatus}\n\n");        
        }

        //$this->logError("Replica setup complete but replica status failed.");
        
    }

    /**
     * Load replication metadata from a snapshot's .meta file and populate $this->meta.
     *
     * The metadata file is expected to contain at least 4 lines:
     *   1. Binlog file name (e.g., mysql-bin.000003)
     *   2. Binlog position (e.g., 2048)
     *   3. Master host (e.g., 159.89.226.27)
     *   4. Master jail name (e.g., mysql_jail_primary)
     *
     * @param string $snapshotName The name of the snapshot (used to locate the .meta file).
     *
     * @throws \RuntimeException If the metadata file is missing or incomplete.
     *
     * @return void
     */
    protected function loadMetaData(string $snapshotName): void
    {
        // Get the directory path where .meta files are stored (via jail driver abstraction)
        $snapshotBackupPath = $this->jailDriver->getSnapshotBackupDir();

        // Compose the full path to the .meta file for the given snapshot
        $metaPath = "{$snapshotBackupPath}/{$snapshotName}.meta";

        // Validate the metadata file exists
        if (!file_exists($metaPath)) {
            throw new \RuntimeException("Meta file not found at: {$metaPath}");
        }

        // Read file into array of lines, skipping empty lines
        $lines = file($metaPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        // Validate the file contains at least the required lines
        if (count($lines) < 3) {
            throw new \RuntimeException("Meta file must contain at least 3 lines (log file, log position, and primary IP).");
        }

        // Extract values from lines
        $masterLogFile = trim($lines[0]);            // Binlog file name
        $masterLogPos = (int) trim($lines[1]);       // Binlog position
        $masterHost = trim($lines[2]);               // Master host IP
        $masterJailName = trim($lines[3]);           // Name of the source jail

        // Populate metadata object with parsed info
        $this->meta = new MetaInfo($masterLogFile, $masterLogPos, $masterHost, $masterJailName);

        // Output debug info to console for visibility
        $this->logInfo("Binlog: {$this->meta->masterLogFile}, Position: {$this->meta->masterLogPos}, Host: {$this->meta->masterHost}, Jail: {$this->meta->masterJailName}");
    }

    /**
     * Prepare and return the name of the ZFS snapshot to be used for replication.
     *
     * This method must be implemented by concrete subclasses to define how
     * the snapshot is created or retrieved (e.g., from a local source or over SSH).
     *
     * @return string The name of the prepared snapshot.
     */
    abstract protected function prepareSnapshot(): string;

    /**
     * Transfer SSL certificates required for MySQL replication.
     *
     * This method must be implemented by subclasses to handle the transfer
     * of CA, client certificate, and key files from the source environment
     * (local or remote) into the replica jail.
     *
     * @return void
     */
    abstract protected function transferCertificates(): void;
}
