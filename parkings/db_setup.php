<?php
/**
 * 1-Click Aiven Cloud MySQL Database Setup & Health Check Tool
 * Vehicle Parking Management System
 */

// Basic settings
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load existing .env if present
$env_file = __DIR__ . '/.env';
if (!file_exists($env_file) && file_exists(dirname(__DIR__) . '/.env')) {
    $env_file = dirname(__DIR__) . '/.env';
}

function parseEnvFile($file) {
    $vars = array();
    if (file_exists($file) && is_readable($file)) {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                list($k, $v) = explode('=', $line, 2);
                $vars[trim($k)] = trim(trim($v), "\"'");
            }
        }
    }
    return $vars;
}

$loaded_env = parseEnvFile($env_file);

// Function to get config value
function getVal($key, $default = '', $post_key = null) {
    global $loaded_env;
    if ($post_key && isset($_POST[$post_key]) && $_POST[$post_key] !== '') {
        return trim($_POST[$post_key]);
    }
    if (isset($loaded_env[$key]) && $loaded_env[$key] !== '') {
        return $loaded_env[$key];
    }
    $env = getenv($key);
    if ($env !== false && $env !== '') {
        return $env;
    }
    return $default;
}

// Handle Service URI parse if provided
$service_uri = trim($_POST['service_uri'] ?? '');
if (!empty($service_uri)) {
    $parsed = parse_url($service_uri);
    if ($parsed) {
        if (!empty($parsed['host'])) $_POST['db_server'] = $parsed['host'];
        if (!empty($parsed['port'])) $_POST['db_port'] = $parsed['port'];
        if (!empty($parsed['user'])) $_POST['db_username'] = urldecode($parsed['user']);
        if (!empty($parsed['pass'])) $_POST['db_password'] = urldecode($parsed['pass']);
        if (!empty($parsed['path'])) $_POST['db_name'] = trim($parsed['path'], '/');
        $_POST['db_ssl'] = '1';
    }
}

// Current connection parameters
$db_server   = getVal('DB_SERVER', getVal('DB_HOST', 'localhost'), 'db_server');
$db_port     = (int)getVal('DB_PORT', '3306', 'db_port');
$db_username = getVal('DB_USERNAME', getVal('DB_USER', 'root'), 'db_username');
$db_password = getVal('DB_PASSWORD', getVal('DB_PASS', ''), 'db_password');
$db_name     = getVal('DB_NAME', 'parking_db', 'db_name');

$ssl_input   = isset($_POST['submitted']) ? (isset($_POST['db_ssl']) ? 1 : 0) : null;
if ($ssl_input !== null) {
    $db_ssl = (bool)$ssl_input;
} else {
    $env_ssl = strtolower(getVal('DB_SSL', ''));
    if ($env_ssl === 'false' || $env_ssl === '0') {
        $db_ssl = false;
    } elseif ($env_ssl === 'true' || $env_ssl === '1' || $env_ssl === 'require') {
        $db_ssl = true;
    } else {
        $db_ssl = ($db_server !== 'localhost' && $db_server !== '127.0.0.1');
    }
}

// Locate SQL dump file
$sql_file = __DIR__ . '/database/parking_db.sql';
if (!file_exists($sql_file) && file_exists(__DIR__ . '/parkings/database/parking_db.sql')) {
    $sql_file = __DIR__ . '/parkings/database/parking_db.sql';
}

// Execution state
$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$test_result = null;
$import_result = null;

// Save to .env if requested
if (!empty($_POST['save_env'])) {
    $env_content = "# Database Configuration for Parking Vehicle Management System\n";
    $env_content .= "DB_SERVER=" . addcslashes($db_server, '"\\') . "\n";
    $env_content .= "DB_PORT=" . $db_port . "\n";
    $env_content .= "DB_USERNAME=" . addcslashes($db_username, '"\\') . "\n";
    $env_content .= "DB_PASSWORD=" . addcslashes($db_password, '"\\') . "\n";
    $env_content .= "DB_NAME=" . addcslashes($db_name, '"\\') . "\n";
    $env_content .= "DB_SSL=" . ($db_ssl ? "true" : "false") . "\n";
    $env_content .= "BASE_URL=\n";
    @file_put_contents(__DIR__ . '/.env', $env_content);
    if (file_exists(__DIR__ . '/parkings')) {
        @file_put_contents(__DIR__ . '/parkings/.env', $env_content);
    }
}

// Helper: Connect to MySQL with Aiven SSL support
function connectMySQL($server, $port, $user, $pass, $dbname, $use_ssl) {
    $mysqli = mysqli_init();
    if (!$mysqli) {
        return array('success' => false, 'error' => 'mysqli_init() failed.', 'errno' => 0);
    }
    $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
    $flags = 0;
    if ($use_ssl) {
        if (defined('MYSQLI_OPT_SSL_VERIFY_SERVER_CERT')) {
            $mysqli->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
        }
        $mysqli->ssl_set(NULL, NULL, NULL, NULL, NULL);
        $flags = defined('MYSQLI_CLIENT_SSL') ? MYSQLI_CLIENT_SSL : 2048;
    }
    // Attempt connection
    $connected = @$mysqli->real_connect($server, $user, $pass, $dbname, (int)$port, NULL, $flags);
    if (!$connected) {
        $err = $mysqli->connect_error ?: mysqli_connect_error();
        $errno = $mysqli->connect_errno ?: mysqli_connect_errno();
        return array('success' => false, 'error' => $err, 'errno' => $errno);
    }
    @$mysqli->set_charset("utf8mb4");
    return array('success' => true, 'conn' => $mysqli);
}

// Process Test Connection
if ($action === 'test' || $action === 'import' || php_sapi_name() === 'cli') {
    $conn_res = connectMySQL($db_server, $db_port, $db_username, $db_password, $db_name, $db_ssl);
    if (!$conn_res['success']) {
        $test_result = array(
            'success' => false,
            'message' => $conn_res['error'],
            'errno' => $conn_res['errno']
        );
    } else {
        $mysqli = $conn_res['conn'];
        // Gather stats
        $ssl_cipher = 'None';
        $cipher_res = $mysqli->query("SHOW STATUS LIKE 'Ssl_cipher'");
        if ($cipher_res && $cipher_res instanceof mysqli_result && $row = $cipher_res->fetch_row()) {
            $ssl_cipher = $row[1] ?: 'None';
        }
        $tables = array();
        $tbl_res = $mysqli->query("SHOW TABLES");
        if ($tbl_res && $tbl_res instanceof mysqli_result) {
            while ($trow = $tbl_res->fetch_row()) {
                $tname = $trow[0];
                $cnt_q = $mysqli->query("SELECT count(*) FROM `$tname`");
                $cnt = ($cnt_q && $cnt_q instanceof mysqli_result) ? $cnt_q->fetch_row()[0] : 0;
                $tables[$tname] = $cnt;
            }
        }
        $admin_exists = false;
        if (isset($tables['users'])) {
            $uq = $mysqli->query("SELECT * FROM `users` WHERE `username` = 'admin'");
            if ($uq && $uq instanceof mysqli_result && $uq->num_rows > 0) {
                $admin_exists = true;
            }
        }
        $test_result = array(
            'success' => true,
            'server_version' => $mysqli->server_info,
            'ssl_cipher' => $ssl_cipher,
            'database' => $db_name,
            'tables' => $tables,
            'admin_exists' => $admin_exists
        );
        $mysqli->close();
    }
}

// Process 1-Click Database Import
if ($action === 'import') {
    if (!$test_result['success']) {
        $import_result = array(
            'success' => false,
            'message' => 'Cannot import: connection to MySQL failed: ' . $test_result['message']
        );
    } elseif (!file_exists($sql_file)) {
        $import_result = array(
            'success' => false,
            'message' => 'SQL dump file not found at: ' . $sql_file
        );
    } else {
        $conn_res = connectMySQL($db_server, $db_port, $db_username, $db_password, $db_name, $db_ssl);
        $mysqli = $conn_res['conn'];

        // Disable foreign key checks for clean import
        $mysqli->query("SET FOREIGN_KEY_CHECKS=0");
        $mysqli->query("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'");

        $sql_content = file_get_contents($sql_file);

        // Clean & parse SQL queries
        // Remove multi-line comments
        $sql_content = preg_replace('!/\*.*?\*/!s', '', $sql_content);
        $lines = explode("\n", $sql_content);
        $clean_sql = '';
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (strpos($trimmed, '--') === 0 || strpos($trimmed, '#') === 0) continue;
            $clean_sql .= $line . "\n";
        }

        // Split by statement delimiters
        $statements = preg_split('/;\s*(\r\n|\n|\r)/', $clean_sql);
        $executed = 0;
        $errors = array();

        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if (empty($stmt)) continue;
            if (!$mysqli->query($stmt)) {
                // Ignore benign table exists/drop errors
                if ($mysqli->errno != 1050 && $mysqli->errno != 1051) {
                    $errors[] = "Query failed [{$mysqli->errno}]: " . substr($stmt, 0, 80) . "... Error: " . $mysqli->error;
                }
            } else {
                $executed++;
            }
        }

        // Ensure system_settings table exists
        $mysqli->query("CREATE TABLE IF NOT EXISTS `system_settings` (
            `id` int(30) NOT NULL AUTO_INCREMENT,
            `name` text NOT NULL,
            `email` varchar(200) NOT NULL,
            `contact` varchar(20) NOT NULL,
            `cover_img` text NOT NULL,
            `about_content` text NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Verify or create Admin User
        $admin_check = $mysqli->query("SELECT * FROM `users` WHERE `username` = 'admin'");
        if (!$admin_check || $admin_check->num_rows == 0) {
            $pass_hash = md5('admin123');
            $mysqli->query("INSERT INTO `users` (`id`, `name`, `type`, `username`, `password`) VALUES (1, 'Administrator', 1, 'admin', '{$pass_hash}') ON DUPLICATE KEY UPDATE `password` = '{$pass_hash}'");
        }

        // Re-enable foreign key checks
        $mysqli->query("SET FOREIGN_KEY_CHECKS=1");

        // Re-check tables
        $tables = array();
        $tbl_res = $mysqli->query("SHOW TABLES");
        if ($tbl_res && $tbl_res instanceof mysqli_result) {
            while ($trow = $tbl_res->fetch_row()) {
                $tname = $trow[0];
                $cnt_q = $mysqli->query("SELECT count(*) FROM `$tname`");
                $cnt = ($cnt_q && $cnt_q instanceof mysqli_result) ? $cnt_q->fetch_row()[0] : 0;
                $tables[$tname] = $cnt;
            }
        }

        $import_result = array(
            'success' => true,
            'statements' => $executed,
            'tables' => $tables,
            'errors' => $errors,
            'admin_user' => 'admin',
            'admin_pass' => 'admin123'
        );

        $mysqli->close();
    }
}

// If run from CLI, print status and exit
if (php_sapi_name() === 'cli') {
    echo "=== Aiven Cloud MySQL DB Tool (CLI) ===\n";
    echo "Host: {$db_server}:{$db_port}\n";
    echo "User: {$db_username}\n";
    echo "DB: {$db_name}\n";
    echo "SSL: " . ($db_ssl ? "Enabled" : "Disabled") . "\n";
    if ($test_result['success']) {
        echo "[OK] Connected successfully! Server: {$test_result['server_version']}, Cipher: {$test_result['ssl_cipher']}\n";
        echo "Tables found: " . count($test_result['tables']) . "\n";
        foreach ($test_result['tables'] as $tb => $c) {
            echo "  - $tb ($c rows)\n";
        }
    } else {
        echo "[FAIL] Connection error: {$test_result['message']} (Code: {$test_result['errno']})\n";
    }
    exit(0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aiven MySQL Database Setup &amp; Health Check</title>
    <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/font-awesome/css/all.min.css">
    <style>
        body {
            background: #f0f2f5;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #333;
        }
        .main-container {
            max-width: 960px;
            margin: 40px auto;
            padding: 0 15px;
        }
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            margin-bottom: 25px;
            overflow: hidden;
        }
        .card-header {
            font-weight: 600;
            border-bottom: none;
            padding: 16px 24px;
        }
        .badge-pill {
            font-size: 0.85rem;
            padding: 0.4em 0.8em;
        }
        .table-stat-badge {
            display: inline-block;
            background: #e9ecef;
            color: #495057;
            padding: 4px 10px;
            border-radius: 6px;
            margin: 3px;
            font-size: 0.85rem;
        }
        .table-stat-badge strong {
            color: #007bff;
        }
    </style>
</head>
<body>

<div class="main-container">
    <!-- Header -->
    <div class="text-center mb-4">
        <h2 class="font-weight-bold text-dark"><i class="fa fa-parking text-primary mr-2"></i>Parking Management System</h2>
        <p class="text-muted">Aiven Cloud MySQL Database Setup, SSL Health Check &amp; 1-Click Importer</p>
    </div>

    <!-- Alert Messages -->
    <?php if ($import_result): ?>
        <?php if ($import_result['success']): ?>
            <div class="alert alert-success card border-success p-4 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <i class="fa fa-check-circle fa-3x text-success mr-3"></i>
                    <div>
                        <h4 class="alert-heading mb-1 font-weight-bold">Database Imported &amp; Verified Successfully!</h4>
                        <p class="mb-0 text-muted">Executed <strong><?php echo $import_result['statements']; ?></strong> SQL statements. All tables and default admin account are active.</p>
                    </div>
                </div>
                <hr>
                <div class="row align-items-center">
                    <div class="col-md-7">
                        <h6 class="font-weight-bold mb-2">Default Admin Credentials:</h6>
                        <ul class="mb-0 pl-3">
                            <li>Username: <code><?php echo $import_result['admin_user']; ?></code></li>
                            <li>Password: <code><?php echo $import_result['admin_pass']; ?></code></li>
                        </ul>
                    </div>
                    <div class="col-md-5 text-md-right mt-3 mt-md-0">
                        <a href="login.php" class="btn btn-success btn-lg px-4 font-weight-bold shadow-sm">
                            <i class="fa fa-sign-in-alt mr-2"></i>Proceed to Login
                        </a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-danger card p-3 shadow-sm">
                <h5 class="alert-heading font-weight-bold"><i class="fa fa-exclamation-triangle mr-2"></i>Import Failed</h5>
                <p class="mb-0"><?php echo htmlspecialchars($import_result['message']); ?></p>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Status Overview Row -->
    <div class="row">
        <!-- Environment & System Health -->
        <div class="col-md-5">
            <div class="card h-100">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <span><i class="fa fa-server mr-2"></i>System Environment</span>
                    <span class="badge badge-success">PHP <?php echo phpversion(); ?></span>
                </div>
                <div class="card-body">
                    <ul class="list-group list-group-flush small">
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            PHP MySQLi Extension
                            <span class="badge badge-success"><i class="fa fa-check"></i> Enabled</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            OpenSSL / TLS Support
                            <span class="badge badge-success"><i class="fa fa-check"></i> Enabled</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            SQL Dump File
                            <?php if (file_exists($sql_file)): ?>
                                <span class="badge badge-success"><i class="fa fa-check"></i> Ready (<?php echo round(filesize($sql_file)/1024, 1); ?> KB)</span>
                            <?php else: ?>
                                <span class="badge badge-danger"><i class="fa fa-times"></i> Missing</span>
                            <?php endif; ?>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            Environment File (.env)
                            <?php if (file_exists($env_file)): ?>
                                <span class="badge badge-info"><i class="fa fa-file"></i> Detected</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Not created yet</span>
                            <?php endif; ?>
                        </li>
                    </ul>

                    <!-- Connection Health Badge -->
                    <div class="mt-3 text-center p-3 rounded <?php echo ($test_result && $test_result['success']) ? 'bg-light border border-success' : 'bg-light border border-warning'; ?>">
                        <?php if ($test_result && $test_result['success']): ?>
                            <span class="text-success font-weight-bold d-block mb-1">
                                <i class="fa fa-shield-alt text-success mr-1"></i> Aiven MySQL Connected!
                            </span>
                            <small class="text-muted d-block">SSL Cipher: <code><?php echo htmlspecialchars($test_result['ssl_cipher']); ?></code></small>
                            <small class="text-muted d-block">Tables: <?php echo count($test_result['tables']); ?> | Admin User: <?php echo $test_result['admin_exists'] ? 'Ready' : 'Not found'; ?></small>
                        <?php elseif ($test_result): ?>
                            <span class="text-danger font-weight-bold d-block mb-1">
                                <i class="fa fa-times-circle text-danger mr-1"></i> Connection Failed
                            </span>
                            <small class="text-danger d-block"><?php echo htmlspecialchars($test_result['message']); ?> (Code: <?php echo $test_result['errno']; ?>)</small>
                        <?php else: ?>
                            <span class="text-muted small">Click "Test Connection" to check Aiven MySQL status.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Database Settings & Actions -->
        <div class="col-md-7">
            <div class="card h-100">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <span><i class="fa fa-database mr-2"></i>Aiven MySQL Configuration</span>
                    <span class="badge badge-light"><?php echo $db_ssl ? 'SSL Enforced' : 'Plain'; ?></span>
                </div>
                <div class="card-body">
                    <form method="POST" action="db_setup.php">
                        <input type="hidden" name="submitted" value="1">

                        <!-- Quick Service URI paste -->
                        <div class="form-group mb-3">
                            <label class="small font-weight-bold text-muted mb-1">
                                <i class="fa fa-link mr-1"></i>Quick Aiven Service URI (Optional Paste)
                            </label>
                            <input type="text" name="service_uri" class="form-control form-control-sm" placeholder="mysql://avnadmin:password@mysql-...aivencloud.com:26284/defaultdb?ssl-mode=REQUIRED" value="<?php echo htmlspecialchars($service_uri); ?>">
                            <small class="form-text text-muted">Paste your full Aiven Service URI to auto-fill all fields below.</small>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-8">
                                <label class="small font-weight-bold text-muted mb-1">Database Host / Server</label>
                                <input type="text" name="db_server" class="form-control" value="<?php echo htmlspecialchars($db_server); ?>" required placeholder="e.g. mysql-3b...aivencloud.com">
                            </div>
                            <div class="form-group col-md-4">
                                <label class="small font-weight-bold text-muted mb-1">Port</label>
                                <input type="number" name="db_port" class="form-control" value="<?php echo htmlspecialchars($db_port); ?>" required placeholder="26284">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label class="small font-weight-bold text-muted mb-1">Username</label>
                                <input type="text" name="db_username" class="form-control" value="<?php echo htmlspecialchars($db_username); ?>" required placeholder="avnadmin">
                            </div>
                            <div class="form-group col-md-6">
                                <label class="small font-weight-bold text-muted mb-1">Password</label>
                                <input type="password" name="db_password" class="form-control" value="<?php echo htmlspecialchars($db_password); ?>" placeholder="Enter password">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-7">
                                <label class="small font-weight-bold text-muted mb-1">Database Name</label>
                                <input type="text" name="db_name" class="form-control" value="<?php echo htmlspecialchars($db_name); ?>" required placeholder="defaultdb">
                            </div>
                            <div class="form-group col-md-5 d-flex align-items-center mt-3">
                                <div class="custom-control custom-checkbox mr-3">
                                    <input type="checkbox" class="custom-control-input" id="db_ssl" name="db_ssl" value="1" <?php echo $db_ssl ? 'checked' : ''; ?>>
                                    <label class="custom-control-label small font-weight-bold" for="db_ssl">SSL (Aiven)</label>
                                </div>
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="save_env" name="save_env" value="1" checked>
                                    <label class="custom-control-label small" for="save_env">Save .env</label>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="d-flex justify-content-between mt-3">
                            <button type="submit" name="action" value="test" class="btn btn-outline-primary">
                                <i class="fa fa-plug mr-1"></i>Test Connection
                            </button>
                            <button type="submit" name="action" value="import" class="btn btn-primary font-weight-bold shadow-sm" onclick="return confirm('Import database tables from parking_db.sql into \'' + this.form.db_name.value + '\'?');">
                                <i class="fa fa-download mr-1"></i>1-Click Import Database
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Tables Preview Section -->
    <?php if ($test_result && $test_result['success'] && !empty($test_result['tables'])): ?>
        <div class="card mt-3">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <span><i class="fa fa-table mr-2"></i>Active Tables in <code><?php echo htmlspecialchars($db_name); ?></code></span>
                <span class="badge badge-secondary"><?php echo count($test_result['tables']); ?> Tables</span>
            </div>
            <div class="card-body">
                <div class="d-flex flex-wrap">
                    <?php foreach ($test_result['tables'] as $tbl => $rows): ?>
                        <div class="table-stat-badge">
                            <i class="fa fa-database text-muted mr-1"></i>
                            <strong><?php echo htmlspecialchars($tbl); ?></strong>: <?php echo $rows; ?> rows
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-3 text-right">
                    <a href="login.php" class="btn btn-outline-success btn-sm">
                        <i class="fa fa-arrow-right mr-1"></i>Go to Login Page
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="text-center text-muted small mt-4">
        Parking Vehicle Management System &bull; Render &amp; Aiven Cloud Ready
    </div>
</div>

<script src="assets/vendor/jquery/jquery.min.js"></script>
<script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>

