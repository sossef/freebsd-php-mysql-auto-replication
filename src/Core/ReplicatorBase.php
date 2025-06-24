<?php

namespace Monsefrachid\MysqlReplication\Core;

use Monsefrachid\MysqlReplication\Support\ShellRunner;
use Monsefrachid\MysqlReplication\Support\MetaInfo;
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
    protected JailConfigurator $configurator;

    /**
     * Transfers SSL certs from remote source jail to replica.
     *
     * @var CertManager
     */
    protected CertManager $certs;

    /**
     * Configures my.cnf and restarts MySQL in the replica jail.
     *
     * @var MySqlConfigurator
     */
    protected MySqlConfigurator $mysql;

    /**
     * Handles replication SQL injection and verification.
     *
     * @var ReplicationVerifier
     */
    protected ReplicationVerifier $verifier;

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
        [$this->from, $this->sourceJail] = explode(':', $from);
        [, $this->replicaJail] = explode(':', $to);

        $this->force = $force;
        $this->dryRun = $dryRun;
        $this->skipTest = $skipTest;
        $this->sshKey = $sshKey;

        $this->shell = new ShellRunner(
            $this->dryRun
        );

        $this->jailDriver = new IocageJailDriver(
            $this->shell
        );

        $this->zfs = new ZfsSnapshotManager(
            $this->shell, 
            $this->jailDriver,
            $this->sshKey            
        );

        $this->jails = new JailManager(
            $this->jailDriver
        );

        $this->configurator = new JailConfigurator(
            $this->shell, 
            $this->jailDriver
        );

        $this->certs = new CertManager(
            $this->shell, 
            $this->jailDriver,
            $this->sshKey            
        );

        $this->mysql = new MySqlConfigurator(
            $this->shell, 
            $this->jailDriver,
            $this->sshKey            
        );

        $this->verifier = new ReplicationVerifier(
            $this->shell, 
            $this->jailDriver, 
            $this->sshKey, 
            $this->dryRun
        );
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

    /**
     * Entry point to execute the replication process.
     */
    public function run(): void
    {
        echo "\n🛠️ Running replication from '{$this->from}:{$this->sourceJail}' to '{$this->replicaJail}'\n\n";
        echo 'Flags: force=' . ($this->force ? 'true' : 'false') .
            ', dryRun=' . ($this->dryRun ? 'true' : 'false') .
            ', skipTest=' . ($this->skipTest ? 'true' : 'false') . "\n";

        // Step 0: Check for existing jail and destroy if --force is set
        if ($this->jails->exists($this->replicaJail)) {
            if ($this->force) {
                echo "⚠️ [FORCE] Jail '{$this->replicaJail}' already exists. Destroying...\n";
                $this->jails->destroy($this->replicaJail);
            } else {
                echo "❌ Jail '{$this->replicaJail}' already exists. Use --force to overwrite.\n";
                exit(1);
            }
        }

        // Step 1: Prepare Snapshot
        $snapshot = $this->prepareSnapshot();

        // Step 2: Ensure jail root exists
        $this->jails->assertRootExists($this->replicaJail);

        // Step 3: Configure replica jail and start
        $this->configurator->configure($this->replicaJail);
        $this->jails->start($this->replicaJail);

        // Step 4: Transfer SSL certs from primary jail to replica
        //$this->certs->transferCerts($this->from, $this->sourceJail, $this->replicaJail);
        $this->transferCertificates();

        // Step 5: Load meta data and configure replica's my.cnf, restart MySQL
        $this->loadMetaData($snapshot);
        $this->mysql->configure(
            $this->replicaJail,
            $snapshot,
            $this->meta
        );

        // Step 6: Replication testing
        $this->verifier->verify(
            $this->meta->masterHost,
            $this->meta->masterJailName,
            $this->sourceJail,
            $this->replicaJail,
            $this->skipTest
        );

        echo "\n✅ Replica setup complete and replication initialized.\n\n";
    }

    /**
     * Load MySQL replication metadata from a snapshot-specific `.meta` file.
     *
     * This metadata includes the binlog file name, position, master host, and source jail name,
     * and is required to properly configure the replica during setup.
     *
     * @param string $snapshotName The name of the snapshot whose metadata file should be loaded.
     *
     * @return void
     *
     * @throws \RuntimeException If the metadata file is missing or has insufficient data.
     */
    protected function loadMetaData(string $snapshotName): void
    {
        //$snapshotBackupPath = \Config::get('IOCAGE_SNAPSHOT_BACKUP_DIR');
        $snapshotBackupPath = $this->jailDriver->getSnapshotBackupDir();

        $metaPath = "{$snapshotBackupPath}/{$snapshotName}.meta";

        if (!file_exists($metaPath)) {
            throw new \RuntimeException("Meta file not found at: {$metaPath}");
        }

        $lines = file($metaPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if (count($lines) < 3) {
            throw new \RuntimeException("Meta file must contain at least 3 lines (log file, log position, and primary IP).");
        }

        $masterLogFile = trim($lines[0]);
        $masterLogPos = (int) trim($lines[1]);
        $masterHost = trim($lines[2]);
        $masterJailName = trim($lines[3]);

        $this->meta = new MetaInfo($masterLogFile, $masterLogPos, $masterHost, $masterJailName);

        echo "🔢 Binlog: {$this->meta->masterLogFile}, Position: {$this->meta->masterLogPos}, Host: {$this->meta->masterHost}, Jail: {$this->meta->masterJailName}\n";
    }
}
