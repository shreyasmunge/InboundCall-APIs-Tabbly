<?php

declare(strict_types=1);

namespace Tabbly\Inbound\Database;

use mysqli;
use Tabbly\Inbound\Config\Config;

final class Database
{
    public static function connect(): mysqli
    {
        $host = Config::get('DB_HOST', 'localhost');
        $user = Config::get('DB_USER', '');
        $pass = Config::get('DB_PASS', '');
        $name = Config::get('DB_NAME', 'defaultdb');
        $port = (int) (Config::get('DB_PORT', '25060') ?? 25060);

        $mysqli = @new mysqli($host, $user, $pass, $name, $port);
        if ($mysqli->connect_errno) {
            throw new \RuntimeException('DB connection failed: ' . $mysqli->connect_error);
        }

        return $mysqli;
    }
}
