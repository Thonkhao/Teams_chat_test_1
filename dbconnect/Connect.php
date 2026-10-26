<?php

//require_once 'vendor/autoload.php';
require_once 'C:\\inetpub\\config\\vendor\\autoload.php';

// ✅ โหลด .env จากโฟลเดอร์นอก wwwroot เพื่อความปลอดภัย
$envPath = 'C:\\inetpub\\config\\ms_teams';
$dotenv = Dotenv\Dotenv::createImmutable($envPath);
$dotenv->load();

class DBConnection
{
    public static function getConnection()
    {
        $serverName = $_ENV['DB_HOST'];
        $userName = $_ENV['DB_USERNAME'];
        $userPassword = $_ENV['DB_PASSWORD'];
        $Database = $_ENV['DB_DATABASE'];
        $dsn = "sqlsrv:Server=$serverName;Database=$Database";

        try {
            return new PDO($dsn, $userName, $userPassword, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            error_log("DBConnection Error: " . $e->getMessage());
            http_response_code(500);
            die('Database connection failed.');
        }
    }

}
