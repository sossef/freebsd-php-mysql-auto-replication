<?php

namespace Monsefrachid\MysqlReplication\Core;

/**
 * Class Replicator
 *
 * Orchestrates the MySQL replication setup from a source jail to a replica jail.
 * This version supports replication from a remote FreeBSD jail via ZFS snapshot.
 */
class Replicator
{
    /** @var string SSH user@host of the source jail */
    private string $from;

    /** @var string Jail name of the source */
    private string $sourceJail;

    /** @var string Jail name of the replica to create */
    private string $replicaJail;

    /** @var bool Whether to force overwrite an existing jail */
    private bool $force;

    /** @var bool Whether to simulate execution without making changes */
    private bool $dryRun;

    /** @var bool Whether to skip replication test at the end */
    private bool $skipTest;

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
    }

    /**
     * Entry point to execute the replication process.
     */
    public function run(): void
    {
        echo "🛠️ Running replication from '{$this->from}:{$this->sourceJail}' to '{$this->replicaJail}'\n";
        echo "Flags: force=" . ($this->force ? "true" : "false") .
             ", dryRun=" . ($this->dryRun ? "true" : "false") .
             ", skipTest=" . ($this->skipTest ? "true" : "false") . "\n";

        // Future implementation
    }
}
