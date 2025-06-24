<?php

namespace Monsefrachid\MysqlReplication\Services;

use Monsefrachid\MysqlReplication\Support\ShellRunner;
use RuntimeException;

class ReplicationVerifier
{
    private ShellRunner $shell;
    private string $sshKey;
    private bool $dryRun;

    public function __construct(
        ShellRunner $shell, 
        string $sshKey = '', 
        bool $dryRun = false, 
        protected JailDriverInterface $jail
    )
    {
        $this->shell = $shell;
        $this->sshKey = $sshKey;
        $this->dryRun = $dryRun;
    }

    public function verify(
        string $masterHost,
        string $masterJailName,
        string $sourceJail,
        string $replicaJail,
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

        $mysqlBinPath = \Config::get('MYSQL_BIN_PATH');

        $insertCmd = "echo \"$testInsert\" | ssh -i {$this->sshKey} {$masterHost} \"sudo iocage exec {$masterJailName} {$mysqlBinPath}\"";
        $this->shell->run($insertCmd, "Insert test row on primary");

        sleep(4);

        $testSelect = <<<SQL
        SELECT msg FROM testdb.ping WHERE msg = "replication check @ $date";
        SQL;

        $check = shell_exec("sudo iocage exec {$replicaJail} {$mysqlBinPath} -e '{$testSelect}'");
        if (!str_contains($check ?? '', 'replication check')) {
            throw new RuntimeException("❌ Replication test failed. Test row not found in replica.");
        }

        echo "\n✅ End-to-end replication test passed.\n";
    }
}
