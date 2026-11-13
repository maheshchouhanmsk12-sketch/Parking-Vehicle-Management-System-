<?php
/**
 * Database Connection for Vehicle Parking Management System
 * Optimized for Aiven Cloud MySQL & PHP 8.x
 */

// Disable mysqli exception throwing for safe, graceful query handling in PHP 8.1+
mysqli_report(MYSQLI_REPORT_OFF);

// Helper to load .env file if present
if (!function_exists('loadEnvFile')) {
    function loadEnvFile($path) {
        if (!file_exists($path) || !is_readable($path)) {
            return false;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                list($key, $val) = explode('=', $line, 2);
                $key = trim($key);
                $val = trim($val);
                $val = trim($val, "\"'");
                if (!array_key_exists($key, $_SERVER) && !array_key_exists($key, $_ENV)) {
                    putenv("$key=$val");
                    $_ENV[$key] = $val;
                    $_SERVER[$key] = $val;
                }
            }
        }
        return true;
    }
}

// Attempt to load .env from current directory or parent directory
loadEnvFile(__DIR__ . '/.env');
if (file_exists(dirname(__DIR__) . '/.env')) {
    loadEnvFile(dirname(__DIR__) . '/.env');
}

// Function to get env var with multiple fallbacks
if (!function_exists('getDbEnv')) {
    function getDbEnv($keys, $default = '') {
        if (!is_array($keys)) $keys = array($keys);
        foreach ($keys as $k) {
            $val = getenv($k);
            if ($val !== false && $val !== '') return $val;
            if (isset($_ENV[$k]) && $_ENV[$k] !== '') return $_ENV[$k];
            if (isset($_SERVER[$k]) && $_SERVER[$k] !== '') return $_SERVER[$k];
        }
        return $default;
    }
}

// Check if a database connection URI is provided
$db_uri = getDbEnv(array('DATABASE_URL', 'MYSQL_URL', 'AIVEN_MYSQL_URL'));
$parsed_uri = !empty($db_uri) ? parse_url($db_uri) : null;

$db_server   = $parsed_uri && isset($parsed_uri['host']) ? $parsed_uri['host'] : getDbEnv(array('DB_SERVER', 'DB_HOST', 'MYSQLHOST'), 'localhost');
$db_port     = $parsed_uri && isset($parsed_uri['port']) ? (int)$parsed_uri['port'] : (int)getDbEnv(array('DB_PORT', 'MYSQLPORT'), 3306);
$db_username = $parsed_uri && isset($parsed_uri['user']) ? urldecode($parsed_uri['user']) : getDbEnv(array('DB_USERNAME', 'DB_USER', 'MYSQLUSER'), 'root');
$db_password = $parsed_uri && isset($parsed_uri['pass']) ? urldecode($parsed_uri['pass']) : getDbEnv(array('DB_PASSWORD', 'DB_PASS', 'MYSQLPASSWORD'), '');
$db_name     = $parsed_uri && isset($parsed_uri['path']) ? trim($parsed_uri['path'], '/') : getDbEnv(array('DB_NAME', 'MYSQLDATABASE'), 'parking_db');

// SSL detection: default to true if Aiven or custom port != 3306 or remote host, unless explicitly turned off
$ssl_env = strtolower((string)getDbEnv(array('DB_SSL', 'MYSQL_SSL', 'MYSQL_SSL_MODE'), ''));
if ($ssl_env === 'false' || $ssl_env === '0' || $ssl_env === 'off' || $ssl_env === 'disabled') {
    $db_ssl = false;
} elseif ($ssl_env === 'true' || $ssl_env === '1' || $ssl_env === 'on' || $ssl_env === 'require' || $ssl_env === 'required') {
    $db_ssl = true;
} else {
    $db_ssl = ($db_server !== 'localhost' && $db_server !== '127.0.0.1');
}

// Base URL helper
$base_url = getDbEnv('BASE_URL', '');
if (empty($base_url) && isset($_SERVER['HTTP_HOST'])) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) ? 'https://' : 'http://';
    $base_url = $protocol . $_SERVER['HTTP_HOST'];
}

// Initialize MySQLi
$conn = mysqli_init();
if (!$conn) {
    die("Database initialization error: mysqli_init failed.");
}

$conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);

// Configure SSL for Aiven Cloud MySQL
$flags = 0;
if ($db_ssl) {
    if (defined('MYSQLI_OPT_SSL_VERIFY_SERVER_CERT')) {
        $conn->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
    }
    $ssl_ca = getDbEnv(array('DB_SSL_CA', 'MYSQL_SSL_CA', 'AIVEN_CA_CERT'), null);
    if (!empty($ssl_ca) && file_exists($ssl_ca)) {
        $conn->ssl_set(NULL, NULL, $ssl_ca, NULL, NULL);
    } else {
        $conn->ssl_set(NULL, NULL, NULL, NULL, NULL);
    }
    $flags = defined('MYSQLI_CLIENT_SSL') ? MYSQLI_CLIENT_SSL : 2048;
}

// Connect to MySQL
$connected = @$conn->real_connect($db_server, $db_username, $db_password, $db_name, $db_port, NULL, $flags);

// Handle connection failure gracefully
if (!$connected) {
    $error_msg = $conn->connect_error ?: mysqli_connect_error();
    $error_no  = $conn->connect_errno ?: mysqli_connect_errno();

    $current_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (php_sapi_name() !== 'cli' && $current_script !== 'db_setup.php') {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Database Connection Required</title>
            <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
            <style>
                body { background: #f8f9fa; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
                .setup-card { max-width: 600px; margin: 80px auto; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="card setup-card border-0">
                    <div class="card-header bg-danger text-white text-center py-3">
                        <h4 class="mb-0">Database Connection Required</h4>
                    </div>
                    <div class="card-body p-4 text-center">
                        <p class="text-muted">Could not connect to the MySQL database with current configuration:</p>
                        <div class="alert alert-light text-left border small mb-4">
                            <strong>Server:</strong> <?php echo htmlspecialchars($db_server . ':' . $db_port); ?><br>
                            <strong>Database:</strong> <?php echo htmlspecialchars($db_name); ?><br>
                            <strong>SSL:</strong> <?php echo $db_ssl ? 'Enabled (MYSQLI_CLIENT_SSL)' : 'Disabled'; ?><br>
                            <strong>Error (<?php echo $error_no; ?>):</strong> <?php echo htmlspecialchars($error_msg); ?>
                        </div>
                        <p>To configure your Aiven MySQL database or import tables with 1-click:</p>
                        <a href="db_setup.php" class="btn btn-primary btn-lg btn-block">Open Database Setup / Importer (db_setup.php)</a>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
} else {
    @$conn->set_charset("utf8mb4");

    // AUTOMATIC DATABASE INITIALIZATION:
    // If the connected database is empty (e.g. tables like 'users' or 'parked_list' do not exist),
    // automatically import the database schema from parking_db.sql so the application works instantly!
    $chk_table = @$conn->query("SHOW TABLES LIKE 'users'");
    if (!$chk_table || $chk_table->num_rows == 0) {
        $sql_file = __DIR__ . '/database/parking_db.sql';
        if (!file_exists($sql_file) && file_exists(__DIR__ . '/parkings/database/parking_db.sql')) {
            $sql_file = __DIR__ . '/parkings/database/parking_db.sql';
        }
        if (file_exists($sql_file)) {
            @$conn->query("SET FOREIGN_KEY_CHECKS=0");
            @$conn->query("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'");

            $sql_content = file_get_contents($sql_file);
            $sql_content = preg_replace('!/\*.*?\*/!s', '', $sql_content);
            $lines = explode("\n", $sql_content);
            $clean_sql = '';
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (strpos($trimmed, '--') === 0 || strpos($trimmed, '#') === 0) continue;
                $clean_sql .= $line . "\n";
            }
            $statements = preg_split('/;\s*(\r\n|\n|\r)/', $clean_sql);
            foreach ($statements as $stmt) {
                $stmt = trim($stmt);
                if (!empty($stmt)) {
                    @$conn->query($stmt);
                }
            }

            @$conn->query("CREATE TABLE IF NOT EXISTS `system_settings` (
                `id` int(30) NOT NULL AUTO_INCREMENT,
                `name` text NOT NULL,
                `email` varchar(200) NOT NULL,
                `contact` varchar(20) NOT NULL,
                `cover_img` text NOT NULL,
                `about_content` text NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pass_hash = md5('admin123');
            @$conn->query("INSERT INTO `users` (`id`, `name`, `type`, `username`, `password`) VALUES (1, 'Administrator', 1, 'admin', '{$pass_hash}') ON DUPLICATE KEY UPDATE `password` = '{$pass_hash}'");
            @$conn->query("SET FOREIGN_KEY_CHECKS=1");
        }
    }
}
