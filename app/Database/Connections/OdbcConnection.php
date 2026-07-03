<?php

namespace App\Database\Connections;

use Illuminate\Database\Connection;
use App\Database\Query\OracleGrammar;

class OdbcConnection extends Connection
{
    protected function getDefaultQueryGrammar()
    {
        return $this->withTablePrefix(new OracleGrammar($this));
    }
}
