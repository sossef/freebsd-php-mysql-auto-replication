<?php

namespace Monsefrachid\MysqlReplication\Services;

use Monsefrachid\MysqlReplication\Support\ShellRunner;
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
    public function configure(string $remote, string $sourceJail, string $replicaJail): void
    {
        $replicaRoot = "/tank/iocage/jails/{$replicaJail}/root";
        $mycnfPath = "{$replicaRoot}/usr/local/etc/mysql/my.cnf";

        // 🔹 Step 1: Copy my.cnf from remote
        $this->shell->run(
            "scp {$this->sshKey} {$remote}:/tank/iocage/jails/{$sourceJail}/root/usr/local/etc/mysql/my.cnf /tmp/my.cnf_primary",
            "Copy my.cnf from primary jail"
        );

        $this->shell->run(
            "sudo mv /tmp/my.cnf_primary {$mycnfPath}",
            "Move my.cnf into replica jail"
        );

        // 🔹 Step 2: Modify values inside my.cnf
        $content = file_get_contents($mycnfPath);

        if ($content === false) {
            throw new RuntimeException("Failed to read my.cnf at {$mycnfPath}");
        }

        if (!preg_match('/^\[mysqld\]/m', $content)) {
            $content = "[mysqld]\n" . $content;
        }

        $content = preg_replace('/server-id\s*=\s*\d+/i', '', $content); // remove if already set
        $content .= "\nserver-id=" . $this->generateServerId();

        $content = preg_replace('/ssl-cert\s*=.*/i', 'ssl-cert=/var/db/mysql/certs/client-cert.pem', $content);
        $content = preg_replace('/ssl-key\s*=.*/i', 'ssl-key=/var/db/mysql/certs/client-key.pem', $content);

        if (!preg_match('/relay-log\s*=/i', $content)) {
            $content .= "\nrelay-log=relay-log";
        }

        file_put_contents($mycnfPath, $content);

        // 🔹 Step 3: Restart MySQL and regenerate UUID
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
    }

    /**
     * Generate a server-id that is not in use (e.g. 2–99)
     *
     * @return int
     */
    private function generateServerId(): int
    {
        $ids = [];

        foreach (glob('/tank/iocage/jails/*/root/usr/local/etc/mysql/my.cnf') as $file) {
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
}
