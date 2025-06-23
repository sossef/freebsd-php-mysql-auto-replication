<?php

namespace Monsefrachid\MysqlReplication\Services;

use Monsefrachid\MysqlReplication\Support\ShellRunner;
use Monsefrachid\MysqlReplication\Contracts\CertTransferInterface;

/**
 * Class CertManager
 *
 * Handles SSL certificate transfer and setup from remote jail to local replica.
 */
class LocalCertManager implements CertTransferInterface
{
    /**
     * @var ShellRunner
     */
    private ShellRunner $shell;

    /**
     * Constructor
     *
     * @param ShellRunner $shell
     * @param string $sshKey
     */
    public function __construct(ShellRunner $shell)
    {
        $this->shell = $shell;
    }

    public function transferCerts(string $source, string $sourceJail, string $replicaJail): void
    {
        $certSource = "/usr/local/share/mysql_certs/primary/*.pem";
        $certTarget = "/tank/iocage/jails/{$replicaJail}/root/var/db/mysql/certs";

        $this->shell->run("sudo mkdir -p {$certTarget}", "Create cert target directory in replica jail");
        $this->shell->run("sudo cp {$certSource} {$certTarget}/", "Copy certs to replica jail");
        $this->shell->run("sudo chown 88:88 {$certTarget}/*.pem", "Set MySQL user:group ownership on certs");
        $this->shell->run("sudo chmod 600 {$certTarget}/*.pem", "Restrict cert file permissions");
    }
}
