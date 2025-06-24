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

        // Validate that both .zfs and .meta files exist locally in snapshot backup folder
        $this->zfs->verifyLocalSnapshot($snapshot);

        return $snapshot;
    }

    /**
     * Transfer SSL certificates from the local system into the replica jail.
     *
     * This method delegates to the CertManager to handle the actual file transfer
     * for required certificate files (e.g., CA, client cert, and key) needed for MySQL SSL replication.
     *
     * @return void
     */
    protected function transferCertificates(): void
    {
        $this->certs->transferCertsFromLocal($this->replicaJail);
    }
}
