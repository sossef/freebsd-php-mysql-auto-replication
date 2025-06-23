<?php

namespace Monsefrachid\MysqlReplication\Core;

use Monsefrachid\MysqlReplication\Support\ShellRunner;
use Monsefrachid\MysqlReplication\Services\ZfsSnapshotManager;
use Monsefrachid\MysqlReplication\Services\JailManager;
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
        string $sshKey = '-i ~/.ssh/id_digitalocean'
    ) {
        [$this->from, $this->sourceJail] = explode(':', $from);
        [, $this->replicaJail] = explode(':', $to);

        $this->force = $force;
        $this->dryRun = $dryRun;
        $this->skipTest = $skipTest;
        $this->sshKey = $sshKey;

        $this->shell = new ShellRunner($this->dryRun);
        $this->zfs = new ZfsSnapshotManager($this->shell, $this->sshKey);
        $this->jails = new JailManager(new IocageJailDriver($this->shell));
        $this->configurator = new JailConfigurator($this->shell);
        $this->certs = new CertManager($this->shell, $this->sshKey);
        $this->mysql = new MySqlConfigurator($this->shell, $this->sshKey);
        $this->verifier = new ReplicationVerifier($this->shell, $this->sshKey, $this->dryRun);
    }

    abstract protected function prepareSnapshot(): string;

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
        $this->certs->transferCerts($this->from, $this->sourceJail, $this->replicaJail);

        // Step 5: Configure replica's my.cnf, restart MySQL and get master log info
        $this->mysql->configure(
            $this->getRemoteHostOnly(),
            $this->replicaJail,
            $snapshot
        );

        // Step 6: Replication testing
        $this->verifier->verify(
            $this->getRemoteHostOnly(),
            $this->sourceJail,
            $this->replicaJail,
            $this->skipTest
        );

        echo "\n✅ Replica setup complete and replication initialized.\n\n";
    }


    /**
     * Extracts only the host portion from the $from value (e.g., user@host).
     *
     * @return string
     */
    private function getRemoteHostOnly(): string
    {
        // Expects format user@host
        [, $host] = explode('@', $this->from);
        return $host;
    }
}
