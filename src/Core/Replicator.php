<?php

namespace Monsefrachid\MysqlReplication\Core;

use Monsefrachid\MysqlReplication\Support\ShellRunner;
use Monsefrachid\MysqlReplication\Services\ZfsSnapshotManager;

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
     * Constructor
     *
     * @param string $from Format: user@host:jailName
     * @param string $to Format: localhost:replicaJailName
     * @param bool $force
     * @param bool $dryRun
     * @param bool $skipTest
     */
    public function __construct(
        string $from,
        string $to,
        bool $force = false,
        bool $dryRun = false,
        bool $skipTest = false
    ) {
        [$this->from, $this->sourceJail] = explode(':', $from);
        [, $this->replicaJail] = explode(':', $to);

        $this->force = $force;
        $this->dryRun = $dryRun;
        $this->skipTest = $skipTest;

        $this->shell = new ShellRunner($this->dryRun);
        $this->zfs = new ZfsSnapshotManager($this->shell);
    }

    /**
     * Entry point to execute the replication process.
     */
    public function run(): void
    {
        echo "\n🛠️ Running replication from '{$this->from}:{$this->sourceJail}' to '{$this->replicaJail}'\n";
        echo 'Flags: force=' . ($this->force ? 'true' : 'false') .
            ', dryRun=' . ($this->dryRun ? 'true' : 'false') .
            ', skipTest=' . ($this->skipTest ? 'true' : 'false') . "\n";

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

        // Step 4: Jail config, cert copy, mysql setup (to be implemented)
        echo "\n✅ Snapshot transfer complete. Next: configure jail and MySQL.\n";
    }
}
