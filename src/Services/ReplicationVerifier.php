<?php

namespace Monsefrachid\MysqlReplication\Services;

use Monsefrachid\MysqlReplication\Support\ShellRunner;
use RuntimeException;

class ReplicationVerifier
{
    private ShellRunner $shell;
    private string $sshKey;
    private bool $dryRun;

    public function __construct(ShellRunner $shell, string $sshKey = '', bool $dryRun = false)
    {
        $this->shell = $shell;
        $this->sshKey = $sshKey;
        $this->dryRun = $dryRun;
    }

    public function verify(
        string $remoteHostOnly,
        string $sourceJail,
        string $replicaJail,
        string $logFile,
        int $logPos,
        bool $skipTest = false
    ): void {
        if ($this->dryRun) {
            echo "🔇 [DRY-RUN] Skipping replication setup and verification.\n";
            return;
        }

        if ($skipTest) {
            echo "⚠️ [SKIP] Replication test skipped due to --skip-test flag.\n";
            return;
        }

        echo "⚙️ [STEP] Run end-to-end replication test...\n";

        $date = date('YmdHis');
        $testInsert = <<<SQL
CREATE DATABASE IF NOT EXISTS testdb;
USE testdb;
CREATE TABLE IF NOT EXISTS ping (msg VARCHAR(100));
INSERT INTO ping (msg) VALUES ('replication check @ $date');
SQL;

        $insertCmd = "echo \"$testInsert\" | ssh {$this->sshKey} {$remoteHostOnly} \"sudo iocage exec {$sourceJail} /usr/local/bin/mysql\"";
        $this->shell->run($insertCmd, "Insert test row on primary");

        sleep(4);
        $check = shell_exec("sudo iocage exec {$replicaJail} /usr/local/bin/mysql -e 'SELECT msg FROM testdb.ping ORDER BY msg DESC LIMIT 1'");
        if (!str_contains($check ?? '', 'replication check')) {
            throw new RuntimeException("❌ Replication test failed. Test row not found in replica.");
        }

        echo "\n✅ End-to-end replication test passed.\n";
    }
}
