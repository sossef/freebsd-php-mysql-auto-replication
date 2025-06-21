<?php

namespace Monsefrachid\MysqlReplication\Services;

use Monsefrachid\MysqlReplication\Contracts\JailDriverInterface;

/**
 * Class JailManager
 *
 * Facade over the configured JailDriver (iocage, jail.conf, etc.).
 */
class JailManager
{
    /**
     * @var JailDriverInterface
     */
    private JailDriverInterface $driver;

    /**
     * Constructor
     *
     * @param JailDriverInterface $driver
     */
    public function __construct(JailDriverInterface $driver)
    {
        $this->driver = $driver;
    }

    /**
     * Determine if the jail exists.
     *
     * @param string $jailName
     * @return bool
     */
    public function exists(string $jailName): bool
    {
        return $this->driver->jailExists($jailName);
    }

    /**
     * Destroy the jail (force).
     *
     * @param string $jailName
     * @return void
     */
    public function destroy(string $jailName): void
    {
        $this->driver->destroyJail($jailName);
    }

    /**
     * Assert that the jail root directory exists.
     *
     * @param string $jailName
     * @return void
     */
    public function assertRootExists(string $jailName): void
    {
        $this->driver->assertJailRootExists($jailName);
    }
}
