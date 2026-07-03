<?php

namespace App\Database;

use Illuminate\Database\Connectors\ConnectionFactory;
use App\Database\Connections\OdbcConnection;
use PDO;

class OdbcConnectionFactory extends ConnectionFactory
{
    public function make($config, $name = null)
    {
        if ($config['driver'] === 'odbc') {
            return $this->createOdbcConnection($config);
        }

        return parent::make($config, $name);
    }

    protected function createOdbcConnection($config)
    {
        $dsn = $config['dsn'];
        $username = $config['username'] ?? '';
        $password = $config['password'] ?? '';
        $options = $config['options'] ?? [];

        if (!str_starts_with($dsn, 'odbc:')) {
            $dsn = 'odbc:' . $dsn;
        }

        $pdo = new PDO($dsn, $username, $password, $options);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return new OdbcConnection($pdo, $config['database'] ?? '', $config['prefix'] ?? '', $config);
    }
}
