<?php

namespace Monsefrachid\MysqlReplication\Core;

use Monsefrachid\MysqlReplication\Core\ReplicatorBase;

/**
 * Factory class responsible for instantiating the appropriate
 * Replicator subclass (RemoteReplicator or LocalReplicator)
 * based on the --from CLI argument.
 */
class ReplicatorFactory
{
     /**
     * Create an instance of either RemoteReplicator or LocalReplicator
     * based on the provided CLI options.
     *
     * @param array $options Parsed command-line options. Must include 'from' and 'to'.
     *                       Optional flags: 'force', 'dry-run', 'skip-test'.
     *
     * @return ReplicatorBase The appropriate replicator instance to execute the replication process.
     */
    public static function create(array $options): ReplicatorBase
    {
        $from = $options['from'];
        $to = $options['to'];
        $force = isset($options['force']);
        $dryRun = isset($options['dry-run']);
        $skipTest = isset($options['skip-test']);
        $sshKey = \Config::get('DEFAULT_SSH_KEY');

        // Use LocalReplicator if source is on localhost and an SSH username is present (e.g., localhost@jail@snapshot)
        if (str_starts_with($from, 'localhost:')) {
            return new LocalReplicator($from, $to, $force, $dryRun, $skipTest, $sshKey);
        }

        // Otherwise, default to RemoteReplicator
        return new RemoteReplicator($from, $to, $force, $dryRun, $skipTest, $sshKey);
    }
}
