<?php

namespace Monsefrachid\MysqlReplication\Services;

use Monsefrachid\MysqlReplication\Support\ShellRunner;
use Monsefrachid\MysqlReplication\Support\LoggerTrait;
use Monsefrachid\MysqlReplication\Contracts\JailDriverInterface;
use RuntimeException;

/**
 * Class JailConfigurator
 *
 * Updates replica jail config.json with IP, hostname, boot flags, etc.
 */
class JailConfigurator
{
    use LoggerTrait;
    
    /**
     * @var ShellRunner
     */
    private ShellRunner $shell;

    /**
     * @param ShellRunner $shell
     */
    public function __construct(
        ShellRunner $shell, 
        protected JailDriverInterface $jailDriver
    )
    {
        $this->shell = $shell;
    }

   /**
     * Configure the specified jail by modifying its `config.json` file.
     *
     * This method sets essential runtime parameters for networking, boot behavior,
     * ZFS dataset, and hostname. It performs file-level edits on the jail’s
     * configuration and enables the jail to start on boot.
     *
     * @param string $jailName The name of the jail to configure.
     *
     * @throws RuntimeException If the config file is missing or contains invalid JSON.
     *
     * @return void
     */
    public function configure(string $jailName): void
    {
        // Path to the jail's config.json file
        $configPath = $this->jailDriver->getJailsMountPath() . "/{$jailName}/config.json";

        // Skip actual modifications if dry-run is enabled
        if ($this->shell->isDryRun()) {
            $this->logDryRun("Skipping jail config update: {$configPath}");
            return;
        }

        // Ensure the config file exists
        if (!file_exists($configPath)) {
            throw new RuntimeException("Jail config not found: {$configPath}");
        }

        // Read and decode the JSON config
        $config = json_decode(file_get_contents($configPath), true);

        // Validate JSON parsing
        if (!is_array($config)) {
            throw new RuntimeException("Invalid jail config JSON: {$configPath}");
        }

        // Apply jail settings
        $config['ip4_addr'] = $this->findAvailableIP(); // Assign a free IP address
        $config['boot'] = 1;                            // Enable auto-start at boot
        $config['defaultrouter'] = '10.0.0.1';          // Static gateway (assumed default)
        $config['host_hostname'] = str_replace('_', '-', $jailName); // Hostname format
        $config['host_hostuuid'] = $jailName;           // Jail UUID
        $config['jail_zfs_dataset'] = $this->jailDriver->getJailZfsDatasetPath() . "/{$jailName}/data";
        $config['allow_raw_sockets'] = 1;               // Allow ping, traceroute, etc.
        $config['release'] = '14.3-RELEASE';            // FreeBSD release version

        // Write updated config back to disk
        file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));

        // Enable jail to start at boot using iocage
        $this->jailDriver->enableBoot($jailName);
    }

    /**
     * Find and return the first available IP address in the 10.0.0.X/24 range for loopback interface.
     *
     * This method:
     *   - Scans all existing jail config files under the configured mount path.
     *   - Collects used IPs from those configs that match the `10.0.0.X` pattern.
     *   - Returns the first unused IP in the range 10.0.0.2 to 10.0.0.253.
     *
     * @return string A formatted IP address string for the jail (e.g., "lo1|10.0.0.12/24").
     *
     * @throws RuntimeException If no free IP is found in the 10.0.0.X range.
     */
    private function findAvailableIP(): string
    {
        $used = [];

        // Iterate over all jail config.json files
        foreach (glob($this->jailDriver->getJailsMountPath() . '/*/config.json') as $file) {
            $data = json_decode(file_get_contents($file), true);

            // Skip invalid or unreadable JSON
            if (!$data || !is_array($data)) {
                continue;
            }

            // Extract and track used IPs in the 10.0.0.X range
            if (
                isset($data['ip4_addr']) &&
                is_string($data['ip4_addr']) &&
                strpos($data['ip4_addr'], '10.0.0.') !== false &&
                preg_match("/10\\.0\\.0\\.(\\d+)/", $data['ip4_addr'], $m)
            ) {
                $used[] = (int)$m[1];
            }
        }

        // Search for the first unused IP in the 10.0.0.2 to 10.0.0.253 range
        for ($i = 2; $i < 254; $i++) {
            if (!in_array($i, $used, true)) {
                return "lo1|10.0.0.{$i}/24";
            }
        }

        // All IPs are used
        throw new RuntimeException('No available IP in 10.0.0.X range.');
    }

}
