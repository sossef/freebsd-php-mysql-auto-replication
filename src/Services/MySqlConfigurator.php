<?php

namespace Monsefrachid\MysqlReplication\Services;

use Monsefrachid\MysqlReplication\Support\ShellRunner;
use Monsefrachid\MysqlReplication\Support\MetaInfo;
use RuntimeException;

/**
 * Class MySqlConfigurator
 *
 * Sets up my.cnf, assigns server-id, configures SSL paths, and restarts MySQL.
 */
class MySqlConfigurator
{
    /**
     * @var ShellRunner
     */
    private ShellRunner $shell;

    /**
     * @var string
     */
    private string $sshKey;

    /**
     * Constructor
     *
     * @param ShellRunner $shell
     * @param string $sshKey
     */
    public function __construct(ShellRunner $shell, string $sshKey)
    {
        $this->shell = $shell;
        $this->sshKey = $sshKey;
    }

    /**
     * Copy and modify my.cnf from remote jail and restart MySQL in replica.
     *
     * @param string $remote       user@host
     * @param string $sourceJail   Source jail name
     * @param string $replicaJail  Replica jail name
     *
     * @return void
     */
    public function configure(string $replicaJail, string $snapshotName, MetaInfo $meta): void
    {
        $replicaRoot = \Config::get('JAILS_MOUNT_PATH') . "/{$replicaJail}/root";
        $mycnfPath = "{$replicaRoot}/usr/local/etc/mysql/my.cnf";

        // Modify config contents (only in non-dry-run mode)
        if ($this->shell->isDryRun()) {
            echo "🔇 [DRY-RUN] Skipping file read/write for {$mycnfPath}\n";
        } else {
            $content = file_get_contents($mycnfPath);

            if ($content === false) {
                throw new RuntimeException("Failed to read my.cnf at {$mycnfPath}");
            }

            if (!preg_match('/^\[mysqld\]/m', $content)) {
                $content = "[mysqld]\n" . $content;
            }

            // Set server-id
            if (preg_match('/server-id\s*=\s*\d+/i', $content)) {
                $content = preg_replace('/server-id\s*=\s*\d+/i', 'server-id=' . $this->generateServerId(), $content);
            } else {
                $content .= "\nserver-id=" . $this->generateServerId();
            }

            // Set SSL cert/key paths
            $content = preg_replace('/ssl-cert\s*=.*/i', 'ssl-cert=/var/db/mysql/certs/client-cert.pem', $content);
            $content = preg_replace('/ssl-key\s*=.*/i', 'ssl-key=/var/db/mysql/certs/client-key.pem', $content);

            // Add relay-log if not present
            if (!preg_match('/relay-log\s*=/i', $content)) {
                $content .= "\nrelay-log=relay-log";
            }

            file_put_contents($mycnfPath, $content);
        }

        // Restart MySQL and regenerate UUID
        $this->shell->run(
            "sudo iocage exec {$replicaJail} service mysql-server stop",
            "Stop MySQL in replica jail"
        );

        $this->shell->run(
            "sudo iocage exec {$replicaJail} rm -f /var/db/mysql/auto.cnf",
            "Delete auto.cnf to regenerate server UUID"
        );

        $this->shell->run(
            "sudo iocage exec {$replicaJail} service mysql-server start",
            "Start MySQL in replica jail"
        );

        $this->injectReplicationSQL($replicaJail, $snapshotName, $meta);
    }

    /**
     * Generate a server-id that is not in use (e.g. 2–99)
     *
     * @return int
     */
    private function generateServerId(): int
    {
        $ids = [];

        foreach (glob(\Config::get('JAILS_MOUNT_PATH') . '/*/root/usr/local/etc/mysql/my.cnf') as $file) {
            $text = file_get_contents($file);
            if (preg_match('/server-id\s*=\s*(\d+)/i', $text, $m)) {
                $ids[] = (int)$m[1];
            }
        }

        for ($i = 2; $i < 100; $i++) {
            if (!in_array($i, $ids, true)) {
                return $i;
            }
        }

        throw new RuntimeException("No available server-id between 2–99");
    }

    /**
     * Retrieve the current binary log file and position from the source MySQL server.
     * Inject replication config SQL
     *
     * @param string $remote The SSH user@host of the source server
     * @param string $sourceJail The source jail name running MySQL
     * @param string $replicaJail The replica jail name running MySQL
     * @param string $remoteHostOnly remote host address
     */
    private function injectReplicationSQL0(string $remote, string $sourceJail, string $replicaJail, string $remoteHostOnly): void
    {
        $output = $this->shell->shell(
            "ssh -i {$this->sshKey} {$remote} \"sudo iocage exec {$sourceJail} /usr/local/bin/mysql -e 'SHOW MASTER STATUS\\G'\"",
            "Fetch MySQL binary log file and position from primary..."
        );

        if (!$output) {
            throw new \RuntimeException("Failed to retrieve master status from source MySQL server.");
        }

        if (!preg_match('/File:\s+(\S+)/', $output, $f) || !preg_match('/Position:\s+(\d+)/', $output, $p)) {
            throw new \RuntimeException("Could not parse log file and position from master status output.");
        }

        $logFile = $f[1];
        $logPos = $p[1];

        echo "🔢 Binlog: {$logFile}, Position: {$logPos}\n";

        $sql = <<<EOD
        STOP REPLICA;
        RESET REPLICA ALL;
        CHANGE MASTER TO
        MASTER_HOST='$remoteHostOnly',
        MASTER_USER='repl',
        MASTER_PASSWORD='replica_pass',
        MASTER_LOG_FILE='$logFile',
        MASTER_LOG_POS=$logPos,
        MASTER_SSL=1,
        MASTER_SSL_CA='/var/db/mysql/certs/ca.pem',
        MASTER_SSL_CERT='/var/db/mysql/certs/client-cert.pem',
        MASTER_SSL_KEY='/var/db/mysql/certs/client-key.pem';
        START REPLICA;
        SHOW REPLICA STATUS\G;
        EOD;

        file_put_contents('/tmp/replica_setup.sql', $sql);

        $this->shell->run(
            "sudo iocage exec {$replicaJail} /usr/local/bin/mysql < /tmp/replica_setup.sql",
            "Configure replication (inject SQL)"
        );

        @unlink('/tmp/replica_setup.sql');
    }

    private function injectReplicationSQL(string $replicaJail, string $snapshotName, MetaInfo $meta): void
    {
        $sql = <<<EOD
        STOP REPLICA;
        RESET REPLICA ALL;
        CHANGE MASTER TO
        MASTER_HOST='{$meta->masterHost}',
        MASTER_USER='repl',
        MASTER_PASSWORD='replica_pass',
        MASTER_LOG_FILE='{$meta->masterLogFile}',
        MASTER_LOG_POS={$meta->masterLogPos},
        MASTER_SSL=1,
        MASTER_SSL_CA='/var/db/mysql/certs/ca.pem',
        MASTER_SSL_CERT='/var/db/mysql/certs/client-cert.pem',
        MASTER_SSL_KEY='/var/db/mysql/certs/client-key.pem';
        START REPLICA;
        SHOW REPLICA STATUS\G;
        EOD;

        file_put_contents('/tmp/replica_setup.sql', $sql);

        $this->shell->run(
            "sudo iocage exec {$replicaJail} /usr/local/bin/mysql < /tmp/replica_setup.sql",
            "Configure replication (inject SQL)"
        );

        @unlink('/tmp/replica_setup.sql');
    }
}
