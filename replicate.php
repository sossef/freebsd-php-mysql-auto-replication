#!/usr/local/bin/php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Monsefrachid\MysqlReplication\Core\Replicator;

// Parse CLI arguments
$options = getopt("", [
    "from:",
    "to:",
    "force",
    "dry-run",
    "skip-test"
]);

if (!isset($options['from'], $options['to'])) {
    fwrite(STDERR, <<<USAGE

Usage:
  ./replicate.php --from user@host:sourceJail --to localhost:replicaJail [--force] [--dry-run] [--skip-test]

Examples:
  ./replicate.php --from root@192.168.1.10:mysql_jail_primary --to localhost:mysql_jail_replica
  ./replicate.php --from root@192.168.1.10:mysql_jail_primary --to localhost:mysql_jail_replica --dry-run

USAGE);
    exit(1);
}

// Extract and forward parameters to the Replicator
$replicator = new Replicator(
    $options['from'],
    $options['to'],
    isset($options['force']),
    isset($options['dry-run']),
    isset($options['skip-test'])
);

// Run the replication process
$replicator->run();
