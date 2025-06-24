#!/usr/local/bin/php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Monsefrachid\MysqlReplication\Core\ReplicatorFactory;
use Monsefrachid\MysqlReplication\Support\Config;

Config::load(__DIR__);

// Parse CLI arguments
$options = getopt("", [
    "from:",
    "to:",
    "force",
    "dry-run",
    "skip-test"
]);

// Validate arguments
if (!isset($options['from'], $options['to'])) {
    fwrite(STDERR, <<<USAGE

    Usage:
      ./replicate.php --from user@host:sourceJail --to localhost:replicaJail [--force] [--dry-run] [--skip-test]

    Examples:
      ./replicate.php --from root@192.168.1.10:mysql_jail_primary --to localhost:mysql_jail_replica
      ./replicate.php --from localhost:mysql_jail_primary@replica_20250622 --to localhost:mysql_jail_replica

    USAGE);

    exit(1);
}

// Use factory to create the correct replicator subclass
$replicator = ReplicatorFactory::create($options);

// Run the replication process
$replicator->run();
