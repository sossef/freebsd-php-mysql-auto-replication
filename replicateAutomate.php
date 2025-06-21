#!/usr/local/bin/php
<?php

/**
 * MySQL Replica Automation Script using ZFS Snapshots (FreeBSD)
 *
 * Usage:
 *   ./replicateAutomate.php -from user@primaryHost:primaryJail -to localhost:newReplicaJailName
 */

function parseArgs() {
    global $argv;
    $args = getopt("", ["from:", "to:"]);
    if (!$args || !isset($args['from']) || !isset($args['to'])) {
        fwrite(STDERR, "Usage: ./replicateAutomate.php --from user@host:jailName --to localhost:replicaJailName\n");
        exit(1);
    }

    list($fromUserHost, $fromJail) = explode(':', $args['from']);
    list($toHost, $toJail) = explode(':', $args['to']);

    return [$fromUserHost, $fromJail, $toJail];
}

function run($cmd, $desc, $rollback = null) {
    echo "⚙️ [STEP] $desc...\n";
    echo "👉 [CMD] $cmd\n";
    exec($cmd, $output, $code);
    if ($code !== 0) {
        echo "❌ [ERROR] $desc failed.\n";
        if ($rollback) {
            echo "🌀 [ROLLBACK] Executing rollback.\n";
            exec($rollback);
        }
        exit(1);
    }
    return $output;
}

function generateReplicaIP() {
    $used = [];
    foreach (glob("/tank/iocage/jails/*/config.json") as $file) {
        $content = file_get_contents($file);
        $cfg = json_decode($content, true);
        if (!$cfg || !is_array($cfg)) continue;
        if (isset($cfg['ip4_addr']) && is_string($cfg['ip4_addr']) && preg_match("/10\\.0\\.0\\.(\d+)/", $cfg['ip4_addr'], $m)) {
            $used[] = (int)$m[1];
        }
    }
    for ($i = 2; $i < 254; $i++) {
        if (!in_array($i, $used)) return "lo1|10.0.0.$i/24";
    }
    throw new Exception("No available IP address for jail.");
}

function generateServerID() {
    $ids = [];
    foreach (glob("/tank/iocage/jails/*/root/usr/local/etc/mysql/my.cnf") as $file) {
        $content = file_get_contents($file);
        if (preg_match("/server-id\\s*=\\s*(\d+)/", $content, $m)) {
            $ids[] = (int)$m[1];
        }
    }
    for ($i = 2; $i < 100; $i++) {
        if (!in_array($i, $ids)) return $i;
    }
    throw new Exception("No available server-id found.");
}

function getMasterStatus($remote, $sshKey) {
    global $sourceJail;
    $cmd = "ssh $sshKey $remote \"sudo iocage exec $sourceJail /usr/local/bin/mysql -e \\\"SHOW MASTER STATUS\\\\G\\\"\"";
    echo "👉 [CMD] $cmd\n";
    $out = shell_exec($cmd);
    if (!$out) throw new Exception("Failed to get master status output.");
    if (!preg_match('/File:\s+(\\S+)/', $out, $f) || !preg_match('/Position:\s+(\\d+)/', $out, $p)) {
        throw new Exception("Could not parse master status from output: \n$out");
    }
    return [$f[1], $p[1]];
}

list($remote, $sourceJail, $replicaJail) = parseArgs();
$sshKey = "-i ~/.ssh/id_digitalocean";
$date = date("YmdHis");
$snapshot = "$sourceJail@replica_$date";
$remoteHostOnly = preg_replace('/^.*@/', '', $remote);

run("ssh $sshKey $remote sudo zfs snapshot -r tank/iocage/jails/$sourceJail@replica_$date", "Create ZFS snapshot on primary");
run("ssh $sshKey $remote zfs list -t snapshot | grep replica_$date", "Verify snapshot exists on primary");

$rollbackCmd = "sudo zfs destroy -r tank/iocage/jails/$replicaJail";

run("ssh $sshKey $remote sudo zfs send -R tank/iocage/jails/$snapshot | sudo zfs recv -F tank/iocage/jails/$replicaJail", "Send and receive ZFS snapshot", $rollbackCmd);

$replicaRoot = "/tank/iocage/jails/$replicaJail/root";
if (!is_dir($replicaRoot)) {
    echo "❌ [ERROR] Replica jail root '$replicaRoot' does not exist or is invalid. Aborting.\n";
    exec($rollbackCmd);
    exit(1);
}

$ip = generateReplicaIP();
$serverId = generateServerID();

$configPath = "/tank/iocage/jails/$replicaJail/config.json";
$config = json_decode(file_get_contents($configPath), true);
$config['ip4_addr'] = $ip;
$config['boot'] = 1;
$config['defaultrouter'] = "10.0.0.1";
$config['host_hostname'] = str_replace('_', '-', $replicaJail);
$config['host_hostuuid'] = $replicaJail;
$config['jail_zfs_dataset'] = "iocage/jails/$replicaJail/data";
$config['allow_raw_sockets'] = 1;
$config['release'] = "14.3-RELEASE";
file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));

$statusRaw = shell_exec("sudo iocage get state $replicaJail 2>/dev/null");
$status = is_string($statusRaw) ? trim($statusRaw) : "";
if ($status !== "up") {
    run("sudo iocage start $replicaJail", "Start replica jail", $rollbackCmd);
} else {
    echo "ℹ️ [INFO] Jail '$replicaJail' is already running. Skipping start.\n";
}

run("scp $sshKey $remote:/tmp/ssl_certs_primary/*.pem /tmp/", "Copy SSL certs from primary");
run("sudo mkdir -p /tank/iocage/jails/$replicaJail/root/var/db/mysql/certs", "Create certs dir in replica jail");
run("sudo mv /tmp/*.pem /tank/iocage/jails/$replicaJail/root/var/db/mysql/certs/", "Move certs to replica jail");
run("sudo chown 88:88 /tank/iocage/jails/$replicaJail/root/var/db/mysql/certs/*.pem", "Chown certs to mysql:mysql");
run("sudo chmod 600 /tank/iocage/jails/$replicaJail/root/var/db/mysql/certs/*.pem", "Restrict cert permissions");

run("scp $sshKey $remote:/tank/iocage/jails/$sourceJail/root/usr/local/etc/mysql/my.cnf /tmp/my.cnf_primary", "Copy my.cnf from primary");
run("sudo mv /tmp/my.cnf_primary $replicaRoot/usr/local/etc/mysql/my.cnf", "Move my.cnf into replica jail");

$mycnfPath = "$replicaRoot/usr/local/etc/mysql/my.cnf";
$mycnf = file_get_contents($mycnfPath);
if ($mycnf === false) {
    throw new Exception("Could not read my.cnf at $mycnfPath");
}
if (!preg_match('/^\[mysqld\]/m', $mycnf)) {
    $mycnf = "[mysqld]\n" . $mycnf;
}
$mycnf = preg_replace("/server-id\\s*=\\s*\\d+/i", "server-id=$serverId", $mycnf);
$mycnf = preg_replace("/ssl-cert\\s*=.*\\.pem/i", "ssl-cert=/var/db/mysql/certs/client-cert.pem", $mycnf);
$mycnf = preg_replace("/ssl-key\\s*=.*\\.pem/i", "ssl-key=/var/db/mysql/certs/client-key.pem", $mycnf);
if (!preg_match("/relay-log\\s*=/i", $mycnf)) {
    $mycnf .= "\nrelay-log=relay-log\n";
}
file_put_contents($mycnfPath, $mycnf);

run("sudo iocage exec $replicaJail service mysql-server stop", "Stop MySQL to regenerate server UUID");
run("sudo iocage exec $replicaJail rm -f /var/db/mysql/auto.cnf", "Delete auto.cnf to regenerate UUID");
run("sudo iocage exec $replicaJail service mysql-server start", "Start MySQL service in replica");

list($logFile, $logPos) = getMasterStatus($remote, $sshKey);

$replicaSQL = <<<EOD
STOP REPLICA;
RESET REPLICA ALL;
CHANGE MASTER TO
  MASTER_HOST='$remoteHostOnly',
  MASTER_USER='repl',
  MASTER_PASSWORD='replica_pass',
  MASTER_LOG_FILE='$logFile',
  MASTER_LOG_POS=$logPos,
  MASTER_SSL=1,
  MASTER_SSL_CA='/var/db/mysql/certs/ca.pem',
  MASTER_SSL_CERT='/var/db/mysql/certs/client-cert.pem',
  MASTER_SSL_KEY='/var/db/mysql/certs/client-key.pem';
START REPLICA;
SHOW REPLICA STATUS\G;
EOD;
file_put_contents("/tmp/replica_setup.sql", $replicaSQL);

if (!is_dir($replicaRoot)) {
    echo "❌ [ERROR] Replica jail root '$replicaRoot' does not exist or is invalid. Aborting.\n";
    exec($rollbackCmd);
    exit(1);
}

run("sudo iocage exec $replicaJail /usr/local/bin/mysql < /tmp/replica_setup.sql", "Configure replication on replica");
@unlink("/tmp/replica_setup.sql");

// End-to-End Replication Test
echo "🔸 [STEP] Run end-to-end replication test...\n";

$testInsert = <<<SQL
CREATE DATABASE IF NOT EXISTS testdb;
USE testdb;
CREATE TABLE IF NOT EXISTS ping (msg VARCHAR(100));
INSERT INTO ping (msg) VALUES ('replication check @ $date');
SQL;

$remoteInsertCmd = "echo \"$testInsert\" | ssh $sshKey $remote \"sudo iocage exec $sourceJail /usr/local/bin/mysql\"";
run($remoteInsertCmd, "Insert test row on primary");
sleep(4);
$check = run("sudo iocage exec $replicaJail /usr/local/bin/mysql -e 'SELECT msg FROM testdb.ping ORDER BY msg DESC LIMIT 1'", "Check replicated row");
if (!isset($check[1]) || !str_contains($check[1], 'replication check')) {
    echo "❌ [ERROR] Replication test failed. Test row not found in replica.\n";
    exit(1);
}

echo "\n✅ End-to-end replication test passed.\n";
echo "\n✅ Replica setup complete and replication initialized.\n";
