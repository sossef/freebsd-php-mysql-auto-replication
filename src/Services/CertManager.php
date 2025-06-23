<?php

namespace Monsefrachid\MysqlReplication\Services;

use Monsefrachid\MysqlReplication\Support\ShellRunner;

/**
 * Class CertManager
 *
 * Handles SSL certificate transfer and setup from remote jail to local replica.
 */
class CertManager
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
     * Transfer SSL certs from the remote source jail to the replica.
     *
     * @param string $remote       user@host
     * @param string $sourceJail   Source jail name
     * @param string $replicaJail  Replica jail name
     * @return void
     */
    public function transferCerts(string $remote, string $sourceJail, string $replicaJail): void
    {
        $replicaRoot = \Config::get('JAILS_MOUNT_PATH') . "/{$replicaJail}/root";
        $certTarget = "{$replicaRoot}/var/db/mysql/certs";

        $this->shell->run(
            "scp -i {$this->sshKey} {$remote}:/tmp/ssl_certs_primary/*.pem /tmp/",
            "Copy SSL certs from remote primary jail"
        );

        $this->shell->run(
            "sudo mkdir -p {$certTarget}",
            "Create cert target directory in replica jail"
        );

        $this->shell->run(
            "sudo mv /tmp/*.pem {$certTarget}/",
            "Move certs to replica jail"
        );

        $this->shell->run(
            "sudo chown 88:88 {$certTarget}/*.pem",
            "Set MySQL user:group ownership on certs"
        );

        $this->shell->run(
            "sudo chmod 600 {$certTarget}/*.pem",
            "Restrict cert file permissions"
        );
    }   

    public function transferCertsFromLocal(string $replicaJail): void
    {
        $replicaRoot = \Config::get('JAILS_MOUNT_PATH') . "/{$replicaJail}/root";
        $certTarget = "{$replicaRoot}/var/db/mysql/certs";
        $localSourcePath = "/usr/local/share/mysql_certs/primary";

        $this->shell->run(
            "sudo mkdir -p {$certTarget}",
            "Create cert target directory in replica jail"
        );

        $this->shell->run(
            "sudo cp {$localSourcePath}/*.pem {$certTarget}/",
            "Copy local SSL certs to replica jail"
        );

        $this->shell->run(
            "sudo chown 88:88 {$certTarget}/*.pem",
            "Set MySQL user:group ownership on certs"
        );

        $this->shell->run(
            "sudo chmod 600 {$certTarget}/*.pem",
            "Restrict cert file permissions"
        );
    }
}
