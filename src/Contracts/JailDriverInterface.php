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
}
