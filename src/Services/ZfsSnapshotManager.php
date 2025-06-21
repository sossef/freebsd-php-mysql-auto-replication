<?php

namespace Monsefrachid\MysqlReplication\Services;

use Monsefrachid\MysqlReplication\Support\ShellRunner;
use RuntimeException;

/**
 * Class ZfsSnapshotManager
 *
 * Handles creation, transfer, and verification of ZFS snapshots
 * across local and remote systems using SSH and ZFS CLI.
 */
class ZfsSnapshotManager
{
    /**
     * @var ShellRunner
     */
    private ShellRunner $shell;

    /**
     * Constructor
     *
     * @param ShellRunner $shell
     */
    public function __construct(ShellRunner $shell)
    {
        $this->shell = $shell;
    }

    /**
     * Create a recursive snapshot on the remote system.
     *
     * @param string $remote         user@host
     * @param string $jailName       The source jail name
     * @param string $snapshotSuffix e.g., replica_20250620
     *
     * @return string The full snapshot name (e.g., jail@suffix)
     */
    public function createRemoteSnapshot(string $remote, string $jailName, string $snapshotSuffix): string
    {
        $snapshot = "{$jailName}@{$snapshotSuffix}";
        $this->shell->run(
            "ssh {$remote} sudo zfs snapshot -r tank/iocage/jails/{$snapshot}",
            "Create remote ZFS snapshot: {$snapshot}"
        );
        return $snapshot;
    }

    /**
     * Verify that the snapshot exists on the remote system.
     *
     * @param string $remote
     * @param string $snapshotSuffix
     *
     * @return void
     */
    public function verifyRemoteSnapshot(string $remote, string $snapshotSuffix): void
    {
        $this->shell->run(
            "ssh {$remote} zfs list -t snapshot | grep {$snapshotSuffix}",
            "Verify remote snapshot exists"
        );
    }

    /**
     * Send snapshot from remote to local jail ZFS dataset.
     *
     * @param string $remote
     * @param string $snapshot
     * @param string $targetJailName
     *
     * @return void
     */
    public function sendSnapshotToLocal(string $remote, string $snapshot, string $targetJailName): void
    {
        $this->shell->run(
            "ssh {$remote} sudo zfs send -R tank/iocage/jails/{$snapshot} | sudo zfs recv -F tank/iocage/jails/{$targetJailName}",
            "Send and receive ZFS snapshot for jail '{$targetJailName}'",
            "Failed to ZFS receive replica"
        );
    }
}
