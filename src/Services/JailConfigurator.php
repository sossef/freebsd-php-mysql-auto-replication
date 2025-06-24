<?php

namespace Monsefrachid\MysqlReplication\Services;

use Monsefrachid\MysqlReplication\Support\ShellRunner;
use Monsefrachid\MysqlReplication\Contracts\JailDriverInterface;
use RuntimeException;

/**
 * Class JailConfigurator
 *
 * Updates replica jail config.json with IP, hostname, boot flags, etc.
 */
class JailConfigurator
{
    /**
     * @var ShellRunner
     */
    private ShellRunner $shell;

    /**
     * @param ShellRunner $shell
     */
    public function __construct(
        ShellRunner $shell, 
        protected JailDriverInterface $jail
    )
    {
        $this->shell = $shell;
    }

    /**
     * Assign a free IP in the range and update jail config.
     *
     * @param string $jailName
     * @return void
     */
    public function configure(string $jailName): void
    {
        $configPath = \Config::get('IOCAGE_JAILS_MOUNT_PATH') . "/{$jailName}/config.json";

        if ($this->shell->isDryRun()) {
            echo "🔇 [DRY-RUN] Skipping jail config update: {$configPath}\n";
            return;
        }

        if (!file_exists($configPath)) {
            throw new RuntimeException("Jail config not found: {$configPath}");
        }

        $config = json_decode(file_get_contents($configPath), true);

        if (!is_array($config)) {
            throw new RuntimeException("Invalid jail config JSON: {$configPath}");
        }

        $config['ip4_addr'] = $this->findAvailableIP();
        $config['boot'] = 1;
        $config['defaultrouter'] = '10.0.0.1';
        $config['host_hostname'] = str_replace('_', '-', $jailName);
        $config['host_hostuuid'] = $jailName;
        $config['jail_zfs_dataset'] = "iocage/jails/{$jailName}/data";
        $config['allow_raw_sockets'] = 1;
        $config['release'] = '14.3-RELEASE';

        file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));

        $this->jail->enableBoot($jailName);
    }

    /**
     * Scan jail configs and return a free IP in the range.
     *
     * @return string
     */
    private function findAvailableIP(): string
    {
        $used = [];

        foreach (glob(\Config::get('IOCAGE_JAILS_MOUNT_PATH') . '/*/config.json') as $file) {
            $data = json_decode(file_get_contents($file), true);

            if (!$data || !is_array($data)) {
                continue;
            }

            if (
                isset($data['ip4_addr']) &&
                is_string($data['ip4_addr']) &&
                strpos($data['ip4_addr'], '10.0.0.') !== false &&
                preg_match("/10\\.0\\.0\\.(\\d+)/", $data['ip4_addr'], $m)
            ) {
                $used[] = (int)$m[1];
            }
        }

        for ($i = 2; $i < 254; $i++) {
            if (!in_array($i, $used, true)) {
                return "lo1|10.0.0.{$i}/24";
            }
        }

        throw new RuntimeException('No available IP in 10.0.0.X range.');
    }
}
