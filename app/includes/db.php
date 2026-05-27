<?php
function getDb(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $host = getenv('DB_HOST') ?: 'db';
    $port = getenv('DB_PORT') ?: '5432';
    $name = getenv('DB_NAME') ?: 'bh_tecnologia';
    $user = getenv('DB_USER') ?: 'bh_user';
    $pass = getenv('DB_PASSWORD') ?: '';

    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$name",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    return $pdo;
}
