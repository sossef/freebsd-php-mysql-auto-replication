<?php

namespace Monsefrachid\MysqlReplication\Services;

use Monsefrachid\MysqlReplication\Support\ShellRunner;
use Monsefrachid\MysqlReplication\Contracts\JailDriverInterface;

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
    public function __construct(
        ShellRunner $shell, 
        protected JailDriverInterface $jailDriver, 
        string $sshKey
    )
    {
        $this->shell = $shell;
        $this->sshKey = $sshKey;
    }

    /**
     * Transfer SSL certificates from the source jail on a remote host to the replica jail.
     *
     * This function:
     *   1. Copies `.pem` certificate files from the remote source jail to local `/tmp`.
     *   2. Creates the target certificate directory inside the replica jail.
     *   3. Moves the certs into the replica jail’s configured MySQL SSL path.
     *   4. Sets appropriate ownership and permissions for MySQL (user ID 88).
     *
     * @param string $remote      The SSH target (e.g., user@host) of the source system.
     * @param string $sourceJail  The source jail name on the remote system (unused here but retained for interface consistency).
     * @param string $replicaJail The name of the local jail receiving the certs.
     *
     * @return void
     */
    public function transferCerts(string $remote, string $sourceJail, string $replicaJail): void
    {
        // Determine target path inside the replica jail for MySQL SSL certificates
        $replicaRoot = $this->jailDriver->getJailsMountPath() . "/{$replicaJail}/root";
        $certTarget = "{$replicaRoot}" . \Config::get('DB_SSL_PATH');

        // Step 1: Secure copy certs from remote jail's /tmp to local /tmp
        $this->shell->run(
            "scp -i {$this->sshKey} {$remote}:/tmp/ssl_certs_primary/*.pem /tmp/",
            "Copy SSL certs from remote primary jail"
        );

        // Step 2: Create the cert target directory inside replica jail
        $this->shell->run(
            "sudo mkdir -p {$certTarget}",
            "Create cert target directory in replica jail"
        );

        // Step 3: Move certs into the replica jail's MySQL cert path
        $this->shell->run(
            "sudo mv /tmp/*.pem {$certTarget}/",
            "Move certs to replica jail"
        );

        // Step 4: Change ownership to MySQL user (UID 88)
        $this->shell->run(
            "sudo chown 88:88 {$certTarget}/*.pem",
            "Set MySQL user:group ownership on certs"
        );

        // Step 5: Restrict permissions for security (read/write owner only)
        $this->shell->run(
            "sudo chmod 600 {$certTarget}/*.pem",
            "Restrict cert file permissions"
        );
    }

    /**
     * Transfer SSL certificates from a predefined local path to the replica jail.
     *
     * This method is used when the primary and replica jails are on the same host.
     * It performs the following:
     *   1. Ensures the target directory inside the replica jail exists.
     *   2. Copies `.pem` cert files from a trusted local path to the replica jail.
     *   3. Sets proper ownership and file permissions for MySQL (UID 88).
     *
     * @param string $replicaJail The name of the local jail that will receive the certs.
     *
     * @return void
     */
    public function transferCertsFromLocal(string $replicaJail): void
    {
        // Construct the replica jail's root and target certificate path
        $replicaRoot = $this->jailDriver->getJailsMountPath() . "/{$replicaJail}/root";
        $certTarget = "{$replicaRoot}" . \Config::get('DB_SSL_PATH');

        // Define the path on the host where SSL certs are stored
        $localSourcePath = \Config::get('MYSQL_SSL_CERTS_PATH');

        // Step 1: Ensure the target directory inside the jail exists
        $this->shell->run(
            "sudo mkdir -p {$certTarget}",
            "Create cert target directory in replica jail"
        );

        // Step 2: Copy certs from the host to the jail
        $this->shell->run(
            "sudo cp {$localSourcePath}/*.pem {$certTarget}/",
            "Copy local SSL certs to replica jail"
        );

        // Step 3: Set proper ownership for MySQL (UID 88)
        $this->shell->run(
            "sudo chown 88:88 {$certTarget}/*.pem",
            "Set MySQL user:group ownership on certs"
        );

        // Step 4: Set restrictive permissions (read/write for owner only)
        $this->shell->run(
            "sudo chmod 600 {$certTarget}/*.pem",
            "Restrict cert file permissions"
        );
    }
}
