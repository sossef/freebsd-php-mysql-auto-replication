<?php

namespace Monsefrachid\MysqlReplication\Support;

/**
 * Represents metadata associated with a MySQL replication snapshot.
 *
 * Contains information extracted from `SHOW MASTER STATUS` on the primary,
 * used to configure replication on the replica.
 */
class MetaInfo
{
    /**
     * The binary log file name on the primary (e.g., `mysql-bin.000003`).
     *
     * @var string
     */
    public readonly string $masterLogFile;

    /**
     * The binary log position on the primary at snapshot time.
     *
     * @var int
     */
    public readonly int $masterLogPos;

    /**
     * The IP or hostname of the primary jail's host machine.
     *
     * @var string
     */
    public readonly string $masterHost;

    /**
     * The jail name on the primary where MySQL is running.
     *
     * @var string
     */
    public readonly string $masterJailName;

    /**
     * MetaInfo constructor.
     *
     * @param string $masterLogFile   The binary log file name.
     * @param int    $masterLogPos    The binary log file position.
     * @param string $masterHost      The primary host's IP or DNS name.
     * @param string $masterJailName  The jail name on the master.
     */
    public function __construct(string $masterLogFile, int $masterLogPos, string $masterHost, string $masterJailName)
    {
        $this->masterLogFile = $masterLogFile;
        $this->masterLogPos = $masterLogPos;
        $this->masterHost = $masterHost;
        $this->masterJailName = $masterJailName;
    }
}

