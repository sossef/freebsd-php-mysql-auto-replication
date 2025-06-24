<?php

namespace Monsefrachid\MysqlReplication\Services;

use Monsefrachid\MysqlReplication\Support\ShellRunner;
use Monsefrachid\MysqlReplication\Support\Config;
use Monsefrachid\MysqlReplication\Support\MetaInfo;
use Monsefrachid\MysqlReplication\Support\LoggerTrait;
use Monsefrachid\MysqlReplication\Contracts\JailDriverInterface;
use RuntimeException;

/**
 * Class MySqlConfigurator
 *
 * Sets up my.cnf, assigns server-id, configures SSL paths, and restarts MySQL.
 */
class MySqlConfigurator
{
    use LoggerTrait;
    
    /**
     * @var ShellRunner
     */
    private ShellRunner $shell;

    /**
     * Path to the SSH private key used for remote jail operations.
     *
     * @var string
     */
    private string $sshKey;

    /**
     * Target path where Primary MySQL SSL certificates are be stored.
     *
     * @var string
     */
    private string $dbSslPath;

    /**
     * Constructor
     *
     * @param ShellRunner $shell
     * @param string $sshKey
     */
    public function __construct(
        ShellRunner $shell, 
        protected JailDriverInterface $jailDriver,
        string $sshKey        
    )
    {
        $this->shell = $shell;
        $this->sshKey = $sshKey;
        $this->dbSslPath = Config::get('DB_SSL_PATH');
    }

    /**
     * Configure the replica jail’s MySQL server (`my.cnf`) for replication and SSL,
     * then restart MySQL with a fresh UUID and inject replication setup SQL.
     *
     * Steps:
     *   - Modify the replica jail's `my.cnf` file to include server-id, SSL paths, and relay-log.
     *   - Restart MySQL to apply changes.
     *   - Remove `auto.cnf` to regenerate a unique server UUID.
     *   - Inject CHANGE MASTER TO ... SQL using the provided snapshot metadata.
     *
     * @param string   $replicaJail   The name of the jail being configured.
     * @param string   $snapshotName  The name of the snapshot (used for logging or binlog linkage).
     * @param MetaInfo $meta          Metadata about the master binlog file, position, and host jail.
     *
     * @throws RuntimeException If the config file cannot be read or written.
     *
     * @return void
    */
    public function configure(string $replicaJail, string $snapshotName, MetaInfo $meta): void
    {
        $replicaRoot = $this->jailDriver->getJailsMountPath() . "/{$replicaJail}/root";
        $mycnfPath = "{$replicaRoot}/usr/local/etc/mysql/my.cnf";

        // Modify config contents (only in non-dry-run mode)
        if ($this->shell->isDryRun()) {
            $this->logDryRun("Skipping file read/write for {$mycnfPath}\n");
        } else {
            $content = file_get_contents($mycnfPath);

            if ($content === false) {
                throw new RuntimeException("Failed to read my.cnf at {$mycnfPath}");
            }

            // Ensure [mysqld] section exists
            if (!preg_match('/^\[mysqld\]/m', $content)) {
                $content = "[mysqld]\n" . $content;
            }

            // Set or replace the server-id
            if (preg_match('/server-id\s*=\s*\d+/i', $content)) {
                $content = preg_replace('/server-id\s*=\s*\d+/i', 'server-id=' . $this->generateServerId(), $content);
            } else {
                $content .= "\nserver-id=" . $this->generateServerId();
            }

            // Set SSL cert and key paths
            $content = preg_replace('/ssl-cert\s*=.*/i', "ssl-cert={$this->dbSslPath}/client-cert.pem", $content);
            $content = preg_replace('/ssl-key\s*=.*/i', "ssl-key={$this->dbSslPath}/client-key.pem", $content);

            // Add relay-log if missing
            if (!preg_match('/relay-log\s*=/i', $content)) {
                $content .= "\nrelay-log=relay-log";
            }

            // Write the updated configuration back to disk
            file_put_contents($mycnfPath, $content);
        }

        // Stop MySQL service to apply changes and regenerate UUID
        $this->jailDriver->runService(
            $replicaJail, 
            'mysql-server', 
            'stop', 
            'Stop MySQL in replica jail'
        );

        // Remove auto.cnf to regenerate server UUID (important for replication)
        $this->jailDriver->exec(
            $replicaJail, 
            'rm -f /var/db/mysql/auto.cnf', 
            'Delete auto.cnf to regenerate server UUID'
        );

        // Start MySQL service again
        $this->jailDriver->runService(
            $replicaJail, 
            'mysql-server', 
            'start', 
            'Start MySQL in replica jail'
        );

         // Inject CHANGE MASTER TO ... SQL to configure replica replication
        $this->injectReplicationSQL($replicaJail, $snapshotName, $meta);
    }

    /**
     * Generate a unique MySQL server ID for the replica jail.
     *
     * Scans existing jails for used `server-id` values (from their `my.cnf` files),
     * and returns the first available ID in the range 2–99.
     *
     * This ensures no replication conflict due to duplicate server IDs.
     *
     * @return int A unique server-id value between 2 and 99 (inclusive).
     *
     * @throws RuntimeException If all IDs in the range are already in use.
 */
    private function generateServerId(): int
    {
        $ids = [];

        // Iterate over all jails and collect existing server-id values from their my.cnf files
        foreach (glob($this->jailDriver->getJailsMountPath() . '/*/root/usr/local/etc/mysql/my.cnf') as $file) {
            $text = file_get_contents($file);

            // Extract server-id if present
            if (preg_match('/server-id\s*=\s*(\d+)/i', $text, $m)) {
                $ids[] = (int)$m[1];
            }
        }

        // Search for the first unused server-id between 2 and 99
        for ($i = 2; $i < 100; $i++) {
            if (!in_array($i, $ids, true)) {
                return $i;
            }
        }

        // All IDs in range are taken — abort
        throw new RuntimeException("No available server-id between 2–99");
    }

    /**
     * Injects MySQL replication SQL into the replica jail to configure it as a slave.
     *
     * This method:
     *   - Constructs the `CHANGE MASTER TO` SQL command using metadata from the snapshot.
     *   - Temporarily writes the SQL to a file.
     *   - Executes it inside the replica jail using the MySQL binary.
     *   - Cleans up the temporary file afterward.
     *
     * @param string   $replicaJail   The name of the jail where the replica is configured.
     * @param string   $snapshotName  The snapshot name (used for context only).
     * @param MetaInfo $meta          Replication metadata (log file, position, host, jail).
     *
     * @return void
     */
    private function injectReplicationSQL(string $replicaJail, string $snapshotName, MetaInfo $meta): void
    {
        // Get MySQL replication credentials from config
        $masterUser = Config::get('MASTER_DB_USER');
        $masterPassword = Config::get('MASTER_DB_PASSWORD');

        // Compose the full SQL script to configure replication     
        $sql = <<<EOD
            STOP REPLICA;
            RESET REPLICA ALL;
            CHANGE MASTER TO
            MASTER_HOST='{$meta->masterHost}',
            MASTER_USER='{$masterUser}',
            MASTER_PASSWORD='{$masterPassword}',
            MASTER_LOG_FILE='{$meta->masterLogFile}',
            MASTER_LOG_POS={$meta->masterLogPos},
            MASTER_SSL=1,
            MASTER_SSL_CA='{$this->dbSslPath}/ca.pem',
            MASTER_SSL_CERT='{$this->dbSslPath}/client-cert.pem',
            MASTER_SSL_KEY='{$this->dbSslPath}/client-key.pem';
            START REPLICA;
            SHOW REPLICA STATUS\G;
        EOD;

         // Write the SQL script to a temporary file
        $tempSqlFile = '/tmp/replica_setup.sql';
        file_put_contents($tempSqlFile, $sql);

        // Build the MySQL execution command
        $mysqlBinPath = Config::get('MYSQL_BIN_PATH');
        $command = "{$mysqlBinPath} < {$tempSqlFile}";

        // Run the SQL inside the jail
        $this->jailDriver->exec(
            $replicaJail, 
            $command, 
            "Configure replication (inject SQL)"
        );

        // Clean up the temporary SQL file
        @unlink('/tmp/replica_setup.sql');
    }
}
