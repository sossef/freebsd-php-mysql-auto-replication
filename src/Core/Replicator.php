<?php

namespace Monsefrachid\MysqlReplication\Core;

use Monsefrachid\MysqlReplication\Support\ShellRunner;
use Monsefrachid\MysqlReplication\Services\ZfsSnapshotManager;
use Monsefrachid\MysqlReplication\Services\JailManager;
use Monsefrachid\MysqlReplication\Services\IocageJailDriver;
use Monsefrachid\MysqlReplication\Services\JailConfigurator;

/**
 * Class Replicator
 *
 * Orchestrates the MySQL replication setup from a source jail to a replica jail.
 * This version supports replication from a remote FreeBSD jail via ZFS snapshot.
 */
class Replicator
{
    /**
     * SSH user@host of the source jail (e.g., "user@192.168.1.10")
     *
     * @var string
     */
    private string $from;

    /**
     * Jail name of the source (e.g., "mysql_jail_primary")
     *
     * @var string
     */
    private string $sourceJail;

    /**
     * Jail name of the replica to create (e.g., "mysql_jail_replica")
     *
     * @var string
     */
    private string $replicaJail;

    /**
     * SSH identity key to use with remote commands (e.g. -i ~/.ssh/id_digitalocean)
     *
     * @var string
     */
    private string $sshKey;

    /**
     * Whether to force overwrite if the replica jail already exists
     *
     * @var bool
     */
    private bool $force;

    /**
     * Whether to simulate execution without actually running commands
     *
     * @var bool
     */
    private bool $dryRun;

    /**
     * Whether to skip the end-to-end replication test after setup
     *
     * @var bool
     */
    private bool $skipTest;

    /**
     * Handles shell command execution
     *
     * @var ShellRunner
     */
    private ShellRunner $shell;

    /**
     * Handles ZFS snapshot operations
     *
     * @var ZfsSnapshotManager
     */
    private ZfsSnapshotManager $zfs;

    /**
     * Handles jail existence checks, destruction, and validation
     *
     * @var JailManager
     */
    private JailManager $jails;

    /**
     * Configures replica jail's network, hostname, and other flags.
     *
     * @var JailConfigurator
     */
    private JailConfigurator $configurator;

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
        $this->configurator = new JailConfigurator();
    }

    /**
     * Entry point to execute the replication process.
     */
    public function run(): void
    {
        echo "\n🛠️ Running replication from '{$this->from}:{$this->sourceJail}' to '{$this->replicaJail}'\n\n";
        echo 'Flags: force=' . ($this->force ? 'true' : 'false') .
            ', dryRun=' . ($this->dryRun ? 'true' : 'false') .
            ', skipTest=' . ($this->skipTest ? 'true' : 'false') . "\n\n";

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

        // Step 1: Create snapshot on remote source jail
        $snapshotSuffix = 'replica_' . date('YmdHis');

        $snapshot = $this->zfs->createRemoteSnapshot(
            $this->from,
            $this->sourceJail,
            $snapshotSuffix
        );

        // Step 2: Verify snapshot exists
        $this->zfs->verifyRemoteSnapshot(
            $this->from,
            $snapshotSuffix
        );

        // Step 3: Transfer snapshot to local and create new jail dataset
        $this->zfs->sendSnapshotToLocal(
            $this->from,
            $snapshot,
            $this->replicaJail
        );

        // Step 4: Ensure jail root exists
        $this->jails->assertRootExists($this->replicaJail);

        // Step 5: Configure replica jail
        $this->configurator->configure($this->replicaJail);

        echo "\n✅ Jail config updated. Next: MySQL certs and replication config.\n";
    }
}
