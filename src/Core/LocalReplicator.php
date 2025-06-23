<?php

namespace Monsefrachid\MysqlReplication\Core;

use Monsefrachid\MysqlReplication\Core\ReplicatorBase;

/**
 * Handles replication using a snapshot that already exists locally.
 *
 * This is useful when the snapshot was transferred or created manually on the local machine.
 */
class LocalReplicator extends ReplicatorBase
{
    /**
     * Verifies that the local snapshot and metadata files exist.
     *
     * @return string The snapshot name (e.g., mysql_jail_primary_replica_20250622163916)
     */
    protected function prepareSnapshot(): string
    {
        // For local replication, the source jail string actually contains the snapshot name
        $snapshot = $this->sourceJail;

        $this->zfs->receiveSnapshotFromLocal(
            $snapshot,
            $this->replicaJail
        );

        // Validate that both .zfs and .meta files exist locally in /tank/backups/iocage/jail/
        $this->zfs->verifyLocalSnapshot($snapshot);

        return $snapshot;
    }
}
