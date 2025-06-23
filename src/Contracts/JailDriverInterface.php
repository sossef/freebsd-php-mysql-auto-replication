<?php

namespace Monsefrachid\MysqlReplication\Contracts;

/**
 * Interface JailDriverInterface
 *
 * Defines the core operations required for jail management.
 * Allows support for different jail backends (e.g., iocage, jail.conf).
 */
interface JailDriverInterface
{
    /**
     * Determine if the jail already exists.
     *
     * @param string $jailName
     * @return bool
     */
    public function jailExists(string $jailName): bool;

    /**
     * Destroy the specified jail and all related datasets.
     *
     * @param string $jailName
     * @return void
     */
    public function destroyJail(string $jailName): void;

    /**
     * Ensure the jail's root path exists after snapshot restore.
     *
     * @param string $jailName
     * @return void
     */
    public function assertJailRootExists(string $jailName): void;

    /**
     * Start the specified jail.
     */
    public function start(string $jailName): void;

    /**
     * Check if the jail is currently running.
     */
    public function isRunning(string $jailName): bool;

    
    public function exec(string $jailName, string $command): string;

    public function execMySQLRemote(string $remoteHost, string $sshKey, string $jailName, string $query): string;

    /**
     * Run a service action inside the jail (e.g., start/stop mysql-server).
     */
    public function runService(string $jailName, string $service, string $action): void;

    /**
     * Remove a file inside the jail.
     */
    public function removeFile(string $jailName, string $filePath): void;

}
