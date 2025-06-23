<?php

namespace Monsefrachid\MysqlReplication\Support;

class MetaInfo
{
    public readonly string $masterLogFile;
    public readonly int $masterLogPos;
    public readonly string $masterHost;
    public readonly string $masterJailName;

    public function __construct(string $masterLogFile, int $masterLogPos, string $masterHost, string $masterJailName)
    {
        $this->masterLogFile = $masterLogFile;
        $this->masterLogPos = $masterLogPos;
        $this->masterHost = $masterHost;
        $this->masterJailName = $masterJailName;
    }
}
