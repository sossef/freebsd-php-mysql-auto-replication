<?php

namespace Monsefrachid\MysqlReplication\Core;

use Monsefrachid\MysqlReplication\Core\ReplicatorBase;

/**
 * Handles replication from a remote MySQL jail.
 *
 * This class is responsible for:
 * - Creating a snapshot on the remote host
 * - Ensuring .zfs and .meta files are generated
 * - Verifying the snapshot and metadata exist remotely
 */
class RemoteReplicator extends ReplicatorBase
{
    /**
     * Creates and verifies the remote snapshot for replication.
     *
     * @return string The snapshot name (e.g., mysql_jail_primary_replica_20250622163916)
     */
    protected function prepareSnapshot(): string
    {
        // Generate a unique timestamped snapshot suffix
        $snapshotSuffix = date('YmdHis');

        // Create the remote snapshot and store .zfs and .meta files under snapshot backup folder
        $snapshot = $this->zfs->createRemoteSnapshot(
            $this->from,
            $this->sourceJail,
            $snapshotSuffix
        );

        // Verify the .zfs and .meta files were successfully created on the remote host
        $this->zfs->verifyRemoteSnapshot(
            $this->from,
            $snapshot
        );

        // Transfer .zfs and .meta to local and create new jail dataset
        $this->zfs->receiveSnapshotFromRemoteFile(
            $this->from,
            $snapshot,
            $this->replicaJail
        );

        return $snapshot;
    }

    /**
     * Transfer SSL certificates from the source jail (remote or local) to the replica jail.
     *
     * Uses the CertManager to securely copy necessary certificate files (CA, client cert, key)
     * from the source jail (identified by host and name) into the replica jail,
     * ensuring proper setup for SSL-based MySQL replication.
     *
     * @return void
     */
    protected function transferCertificates(): void
    {
        if ($this->shell->isDryRun()) {
            $this->logDryRun("Skipping...");
            return;
        }

        $this->certsManager->transferCerts($this->from, $this->sourceJail, $this->replicaJail);
    }
}
