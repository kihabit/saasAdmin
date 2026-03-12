<?php
/**
 * Database Configuration File
 * Contains all database credentials and application settings
 */

// Database Configuration
define('DB_HOST', "127.0.0.1");
define('DB_USERNAME', "root");
// Base URL (change according to your domain)
define('BASE_URL', 'http://localhost/schoolAdmin/driver.tracker/');
//define('DB_USERNAME', "u613073349_school");
//define('DB_PASSWORD', "fi3G@LP8H9~");
define('DB_PASSWORD', "");
define('DB_NAME', "u613073349_school");
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATION', 'utf8mb4_unicode_ci');

// Application Settings
define('APP_NAME', 'Driver Tracker Login');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'http://localhost/your_project');
define('APP_TIMEZONE', 'Asia/Kolkata');

// Security Settings
define('SESSION_LIFETIME', 3600);
define('REMEMBER_ME_LIFETIME', 2592000);
define('PASSWORD_MIN_LENGTH', 8);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900);

// Cookie Settings
define('COOKIE_DOMAIN', '');
define('COOKIE_PATH', '/');
define('COOKIE_SECURE', false);
define('COOKIE_HTTPONLY', true);

// File Upload Settings
define('UPLOAD_MAX_SIZE', 5242880);
define('UPLOAD_ALLOWED_TYPES', 'jpg,jpeg,png,gif,pdf,doc,docx');
define('UPLOAD_PATH', 'uploads/');

// Email Configuration
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your_email@gmail.com');
define('SMTP_PASSWORD', 'your_app_password');
define('SMTP_ENCRYPTION', 'tls');
define('MAIL_FROM_ADDRESS', 'noreply@yourapp.com');
define('MAIL_FROM_NAME', 'Driver Tracker System');

// Default Pages/Redirects
define('LOGIN_PAGE', 'index.php');
define('DASHBOARD_PAGE', 'dashboard.php');
define('LOGOUT_PAGE', 'logout.php');
define('REGISTER_PAGE', 'signUp.html');
define('FORGOT_PASSWORD_PAGE', 'forgetPassword.html');

// API Settings
define('API_KEY', 'your_api_key_here');
define('API_BASE_URL', 'https://api.example.com/');

// Development/Production Mode
define('ENVIRONMENT', 'development');
define('DEBUG_MODE', true);
define('ERROR_REPORTING', true);

// Logging Settings
define('LOG_ERRORS', true);
define('LOG_PATH', 'logs/');
define('LOG_FILE', 'app.log');

// Set timezone
date_default_timezone_set(APP_TIMEZONE);

// Error reporting based on environment
if (ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

/**
 * Database Connection Class
 */
class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        
        $this->connection = new mysqli( DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME ); $this->connection->set_charset(DB_CHARSET); if ($this->connection->connect_error) { throw new Exception("Connection failed: " . $this->connection->connect_error); }
        
        
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    public function close() {
        if ($this->connection) {
            $this->connection->close();
        }
    }
    
    private function logError($message) {
        if (LOG_ERRORS) {
            $logDir = LOG_PATH;
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            
            $logFile = $logDir . LOG_FILE;
            $timestamp = date('Y-m-d H:i:s');
            $logMessage = "[{$timestamp}] ERROR: {$message}" . PHP_EOL;
            file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
        }
    }
}

/**
 * Utility Functions
 */

// Sanitize input data
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Generate secure random token
function generateToken($length = 32) {
    return bin2hex(random_bytes($length));
}

// Hash password securely
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

// Verify password
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Validate email
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// Check if string meets password requirements
function isValidPassword($password) {
    return strlen($password) >= PASSWORD_MIN_LENGTH;
}

// Generate secure session ID
function generateSessionId() {
    return session_create_id();
}

// Log application errors
function logAppError($message, $file = '', $line = '') {
    if (LOG_ERRORS) {
        $logDir = LOG_PATH;
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $logFile = $logDir . LOG_FILE;
        $timestamp = date('Y-m-d H:i:s');
        $fileInfo = $file ? " in {$file}" : '';
        $lineInfo = $line ? " on line {$line}" : '';
        $logMessage = "[{$timestamp}] ERROR: {$message}{$fileInfo}{$lineInfo}" . PHP_EOL;
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
}

// Get client IP address
function getClientIP() {
    $ipkeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($ipkeys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

// Redirect function
function redirect($url) {
    header("Location: " . $url);
    exit();
}

// Set flash message
function setFlashMessage($type, $message) {
    $_SESSION['flash_message'] = [
        'type' => $type,
        'message' => $message
    ];
}

// Get and clear flash message
function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $message;
    }
    return null;
}

// ✅ Function to check if current page is active
function isActivePage($pageName) {
    $current_page = basename($_SERVER['PHP_SELF']);
    return ($current_page == $pageName) ? 'active' : '';
}

// Rate limiting for login attempts
function checkLoginAttempts($identifier) {
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = [];
    }
    
    $now = time();
    $attempts = $_SESSION['login_attempts'][$identifier] ?? [];
    
    $attempts = array_filter($attempts, function($timestamp) use ($now) {
        return ($now - $timestamp) < LOGIN_LOCKOUT_TIME;
    });
    
    return count($attempts) < MAX_LOGIN_ATTEMPTS;
}

// Record failed login attempt
function recordFailedLogin($identifier) {
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = [];
    }
    
    if (!isset($_SESSION['login_attempts'][$identifier])) {
        $_SESSION['login_attempts'][$identifier] = [];
    }
    
    $_SESSION['login_attempts'][$identifier][] = time();
}

// Clear login attempts on successful login
function clearLoginAttempts($identifier) {
    if (isset($_SESSION['login_attempts'][$identifier])) {
        unset($_SESSION['login_attempts'][$identifier]);
    }
}

// Prevent direct access
define('INCLUDED', true);
?>