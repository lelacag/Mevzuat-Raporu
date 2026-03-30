<?php /* EN + TR comments used. */
require_once 'config.php';  // DB_HOST, DB_NAME, DB_USER, DB_PASS constants

function db_connect() {
    static $pdo = null;  // Singleton pattern for connection reuse
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);  // Throw errors
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);  // Default fetch style
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);  // Real prepared statements for security
        } catch (PDOException $e) {
            // Log the real error but do not expose internal details to end users in production
            error_log('DB connection failed: ' . $e->getMessage());
            if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
                die('Database connection failed.');
            } else {
                die('DB connection failed: ' . $e->getMessage());
            }
        }
    }
    return $pdo;
}

function query($sql, $params = []) {
    $pdo = db_connect();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function insert_id() {
    return db_connect()->lastInsertId();
}

// Make $pdo available globally for legacy code
$pdo = db_connect();

// Example usage: $stmt = query("SELECT * FROM users WHERE id = ?", [1]); $user = $stmt->fetch();
?>