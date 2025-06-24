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
     * Start the specified iocage jail.
     *
     * @param string $jailName The name of the jail to start.
     *
     * @return void
     */
    public function start(string $jailName): void;

    /**
     * Check if the specified iocage jail is currently running.
     *
     * @param string $jailName The name of the jail to check.
     *
     * @return bool True if the jail is running, false otherwise.
     */
    public function isRunning(string $jailName): bool;
    
    /**
     * Execute a command inside a local iocage jail.
     *
     * @param string $jailName   The name of the local jail to execute the command in.
     * @param string $command    The shell command to execute inside the jail.
     * @param string $description Optional description for logging or debugging purposes.
     *
     * @return string The output of the executed command.
     */
    public function exec(string $jailName, string $command, string $description = ''): string;

    /**
     * Execute a single-line MySQL query inside a remote jail over SSH.
     *
     * @param string $remoteHost The remote SSH target (e.g., user@host).
     * @param string $sshKey     The SSH private key file path to authenticate.
     * @param string $jailName   The name of the jail on the remote host.
     * @param string $query      The MySQL query to execute (single-line).
     * @param string $description Optional description for logging or debugging purposes.
     *
     * @return string The output of the executed query.
     */
    public function execMySQLRemote(string $remoteHost, string $sshKey, string $jailName, string $query, string $description = ''): string;

    /**
     * Execute a multi-line MySQL query script inside a remote jail over SSH.
     *
     * @param string $remoteHost The remote SSH target (e.g., user@host).
     * @param string $sshKey     The SSH private key file path to authenticate.
     * @param string $jailName   The name of the jail on the remote host.
     * @param string $sqlContent The full multi-line SQL script content.
     * @param string $description Optional description for logging or debugging purposes.
     *
     * @return string The output of the executed script.
     */
    public function execMySqlRemoteMultiLine(string $remoteHost, string $sshKey, string $jailName, string $sqlContent, string $description = ''): string;


    /**
     * Run a service-related action (e.g., start/stop/restart) inside the specified jail.
     *
     * @param string $jailName    The name of the jail where the service is located.
     * @param string $service     The name of the service to control (e.g., 'mysql-server').
     * @param string $action      The action to perform on the service (e.g., 'start', 'stop', 'restart').
     * @param string $description Optional description for logging or debugging purposes.
     *
     * @return void
     */
    public function runService(string $jailName, string $service, string $action, string $description = ''): void;

    /**
     * Remove a file from within the specified jail.
     *
     * @param string $jailName The name of the jail where the file resides.
     * @param string $filePath The full path to the file inside the jail to be deleted.
     *
     * @return void
     */
    public function removeFile(string $jailName, string $filePath): void;

    /**
     * Enable the jail to automatically start on system boot.
     *
     * @param string $jailName The name of the jail to enable for boot startup.
     *
     * @return void
     */
    public function enableBoot(string $jailName): void;

    /**
     * Get the root filesystem path of the specified jail.
     *
     * @param string $jailName The name of the jail.
     *
     * @return string The absolute path to the jail's root directory.
     */
    public function getJailRootPath(string $jailName): string;

    /**
     * Get the path to the iocage configuration file for the specified jail.
     *
     * @param string $jailName The name of the jail.
     *
     * @return string The absolute path to the jail's config file.
     */
    public function getJailConfigPath(string $jailName): string;

    /**
     * Get the directory path where ZFS snapshot backups are stored.
     *
     * @return string The absolute path to the snapshot backup directory.
     */
    public function getSnapshotBackupDir(): string;

    /**
     * Get the ZFS dataset path that contains all jails.
     *
     * @return string The ZFS dataset path (e.g., 'tank/iocage/jails').
     */
    public function getJailsDatasetPath(): string;

    /**
     * Get the base mount point on disk where all jails are mounted.
     *
     * @return string The absolute mount path for jails (e.g., '/tank/iocage/jails').
     */
    public function getJailsMountPath(): string;

    /**
     * Get the full ZFS dataset path to an individual jail's root dataset.
     *
     * @return string The dataset path for a specific jail's ZFS volume.
     */
    public function getJailZfsDatasetPath(): string;
}
