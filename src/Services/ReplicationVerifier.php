<?php

namespace Monsefrachid\MysqlReplication\Services;

use Monsefrachid\MysqlReplication\Support\ShellRunner;
use Monsefrachid\MysqlReplication\Support\Config;
use Monsefrachid\MysqlReplication\Support\LoggerTrait;
use Monsefrachid\MysqlReplication\Contracts\JailDriverInterface;
use RuntimeException;

/**
 * Performs an end-to-end replication test to ensure MySQL replication is working.
 *
 * This class:
 *   - Inserts a test row into the primary database via SSH.
 *   - Waits briefly for replication to occur.
 *   - Checks for the presence of that row in the replica database.
 *
 * Throws an exception if replication appears to have failed.
 */
class ReplicationVerifier
{
    use LoggerTrait;

    private ShellRunner $shell;
    private string $sshKey;
    private bool $dryRun;

    /**
     * @param ShellRunner $shell  Used for executing shell commands directly.
     * @param JailDriverInterface $jail Jail driver abstraction for MySQL jail execution.
     * @param string $sshKey Path to SSH private key for accessing the primary host.
     * @param bool $dryRun Whether to skip actual execution (dry-run mode).
     */
    public function __construct(
        ShellRunner $shell, 
        protected JailDriverInterface $jail,
        string $sshKey = '', 
        bool $dryRun = false        
    )
    {
        $this->shell = $shell;
        $this->sshKey = $sshKey;
        $this->dryRun = $dryRun;
    }

    /**
     * Performs the replication test by inserting a row into the master,
     * then checking for its presence in the replica.
     *
     * @param string $masterHost      The remote host of the primary jail (e.g. user@host).
     * @param string $masterJailName  The name of the primary jail on the master.
     * @param string $sourceJail      The local name of the primary jail (for context).
     * @param string $replicaJail     The jail name of the replica to verify.
     * @param bool   $skipTest        Whether to skip this verification step.
     *
     * @throws RuntimeException If the inserted test row is not found in the replica.
     */
    public function verify(
        string $masterHost,
        string $masterJailName,
        string $sourceJail,
        string $replicaJail,
        bool $skipTest = false
    ): void {
        // Respect dry-run or skip flags
        if ($this->dryRun) {
            echo "🔇 [DRY-RUN] Skipping replication setup and verification.\n";
            return;
        }

        if ($skipTest) {
            echo "⚠️ [SKIP] Replication test skipped due to --skip-test flag.\n";
            return;
        }

        echo "⚙️ [STEP] Run end-to-end replication test...\n";

        // Generate a timestamped test message
        $date = date('YmdHis');
        $testInsert = <<<SQL
            CREATE DATABASE IF NOT EXISTS testdb;
            USE testdb;
            CREATE TABLE IF NOT EXISTS ping (msg VARCHAR(100));
            INSERT INTO ping (msg) VALUES ('replication check @ $date');
        SQL;

        // Send insert query to primary over SSH
        $mysqlBinPath = Config::get('MYSQL_BIN_PATH');
        $insertCmd = "echo \"$testInsert\" | ssh -i {$this->sshKey} {$masterHost} \"sudo iocage exec {$masterJailName} {$mysqlBinPath}\"";
        $this->shell->run($insertCmd, "Insert test row on primary");

        // $this->jail->execMySqlRemoteMultiLine(
        //     $masterHost,
        //     $this->sshKey,
        //     $masterJailName,
        //     $testInsert,
        //     "Insert test row on primary"
        // );

        // Allow time for replication
        sleep(4);

        // Prepare verification SELECT statement
        $testSelect = <<<SQL
            SELECT msg FROM testdb.ping WHERE msg = "replication check @ $date";
        SQL;

        // Run SELECT query on the replica jail
        $cmd = "{$mysqlBinPath} -e '{$testSelect}'";
        $check = $this->jail->exec(
            $replicaJail,
            $cmd,
            "Verify replication row in replica jail"
        );

        // Confirm the replicated row exists in replica
        if (!str_contains($check ?? '', 'replication check')) {
            throw new RuntimeException("❌ Replication test failed. Test row not found in replica.");
        }

        echo "\n✅ End-to-end replication test passed.\n";
    }

    public function verifyReplicaStatus(
        string $replicaJail,
        bool $skipTest = false
    ): ?array {
        // Respect dry-run or skip flags
        if ($this->dryRun) {
            echo "🔇 [DRY-RUN] Skipping replication setup and verification.\n";
            return null;
        }

        if ($skipTest) {
            echo "⚠️ [SKIP] Replication test skipped due to --skip-test flag.\n";
            return null;
        }

        echo "⚙️ [STEP] Checking replication status...\n";

        // Prepare verification SELECT statement
        $replicaStatusQuery = <<<SQL
            SHOW REPLICA STATUS\G;
        SQL;

        $mysqlBinPath = Config::get('MYSQL_BIN_PATH');
        $cmd = "{$mysqlBinPath} -e '{$replicaStatusQuery}'";
        $check = $this->jail->exec(
            $replicaJail,
            $cmd,
            "Verify replication row in replica jail"
        );      
        
        $status = $this->parseReplicaStatus($check);

        $ioRunning = $status['Replica_IO_Running'] ?? 'Unknown';
        $sqlRunning = $status['Replica_SQL_Running'] ?? 'Unknown';
        $sslAllowed = $status['Source_SSL_Allowed'] ?? 'Unknown';

        $this->log("🔍 Replica_IO_Running: $ioRunning\n");
        $this->log("🔍 Replica_SQL_Running: $sqlRunning\n");
        $this->log("🔍 Source_SSL_Allowed: $sslAllowed\n");

        if ($ioRunning !== 'Yes' || $sqlRunning !== 'Yes' || $sslAllowed !== 'Yes') {
            $this->logError("Replica status check failed!\n\n");
            return [false, $check];
        } 
        
        $this->logSuccess("Replica status check passed.\n\n");

        return [true, $check];
    }

    private function parseReplicaStatus(string $output): array
    {
        $result = [];

        foreach (explode("\n", $output) as $line) {
            // Match lines like: "Replica_IO_Running: Yes"
            if (preg_match('/^\s*([\w_]+):\s*(.*)$/', $line, $matches)) {
                $key = $matches[1];
                $value = trim($matches[2]);
                $result[$key] = $value;
            }
        }

        return $result;
    }

}
