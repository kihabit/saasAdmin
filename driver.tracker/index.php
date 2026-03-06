<?php
session_start();
error_reporting(E_ALL);
error_reporting(-1);
ini_set('error_reporting', E_ALL);

require_once 'config.php';
    
// Initialize response array
$response = array();

// Get database connection
$db = Database::getInstance();
$conn = $db->getConnection();

// // Check if form is submitted
if (isset($_POST['email']) && isset($_POST['password'])) {echo "Test";
    // Include configuration file
   
    // Get and sanitize input data
    $email = sanitizeInput($_POST['email']);
    $password = sanitizeInput($_POST['password']);
    $remember_me = isset($_POST['remember_me']) && $_POST['remember_me'] == '1';
    
    // Get client IP for rate limiting
    $clientIP = getClientIP();
    
    // Check rate limiting
    if (!checkLoginAttempts($clientIP)) {
        $response['success'] = false;
        $response['message'] = "Too many failed login attempts. Please try again in " . (LOGIN_LOCKOUT_TIME / 60) . " minutes.";
        logAppError("Login rate limit exceeded for IP: " . $clientIP);
    }
    // Validate input
    elseif (empty($email) || empty($password)) {
        $response['success'] = false;
        $response['message'] = "Please fill in all fields.";
    } 
    elseif (!isValidEmail($email)) {
        $response['success'] = false;
        $response['message'] = "Please enter a valid email address.";
    } 
    else {
        try {
            // Prepare SQL statement to prevent SQL injection
            $stmt = $conn->prepare("SELECT user_id, driverId, username, password_hash, email, status FROM user_login WHERE userType=1 and email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
           
            if ($result->num_rows == 1) {
                $user = $result->fetch_assoc();
                
                // ✅ FIX #1: CHECK USER STATUS FIRST - BEFORE PASSWORD VERIFICATION
                $userStatus = isset($user['status']) ? intval($user['status']) : 0;
                
                if ($userStatus != 1) {
                    // User is INACTIVE - Block login
                    recordFailedLogin($clientIP);
                    
                    $response['success'] = false;
                    $response['message'] = "Your account has been deactivated. Please contact the administrator.";
                    
                    // Log inactive user login attempt
                    logAppError("Login attempt for inactive account: " . $user['email'] . " from IP: " . $clientIP);
                } else {
                    // User is ACTIVE - Continue with password verification
                    
                    // Verify password (support both bcrypt and MD5)
                    $passwordValid = false;
                    
                    // Check if it's a bcrypt hash (starts with $2y$, $2a$, $2x$, or $2b$)
                    if (preg_match('/^\$2[yaxb]\$/', $user['password_hash'])) {
                        // Use bcrypt verification
                        $passwordValid = verifyPassword(md5($password), $user['password_hash']);
                    } else {
                        // Assume it's MD5 hash
                        $passwordValid = (md5($password) === $user['password_hash']);
                    }
                    
                    if ($passwordValid) {
                        
                        // Clear failed login attempts
                        clearLoginAttempts($clientIP);
                        
                        // Regenerate session ID for security
                        session_regenerate_id(true);
                        
                        // Login successful - set session variables
                        $_SESSION['user_id'] = $user['user_id'];
                        $_SESSION['driver_id'] = $user['driverId'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['email'] = $user['email'];
                        $_SESSION['logged_in'] = true;
                        $_SESSION['login_time'] = time();
                        $_SESSION['last_activity'] = time();
                        
                        // Update last login timestamp
                        $update_stmt = $conn->prepare("UPDATE user_login SET last_login = CURRENT_TIMESTAMP WHERE user_id = ?");
                        $update_stmt->bind_param("i", $user['user_id']);
                        $update_stmt->execute();
                        $update_stmt->close();
                        
                        // Handle remember me functionality
                        if ($remember_me) {
                            $token = generateToken();
                            $cookie_value = base64_encode($user['user_id'] . ':' . $token);
                            
                            // Store token in database
                            $token_hash = hash('sha256', $token);
                            $remember_stmt = $conn->prepare("INSERT INTO user_remember_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND)) ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), expires_at = VALUES(expires_at)");
                            $user_id_var = (int)$user['user_id'];
                            $token_hash_var = $token_hash;
                            $remember_lifetime_var = REMEMBER_ME_LIFETIME;
                            
                            $remember_stmt->bind_param("isi", $user_id_var, $token_hash_var, $remember_lifetime_var);
                            $remember_stmt->execute();
                            $remember_stmt->close();
                            
                            // Set remember me cookie
                            setcookie(
                                'remember_login', 
                                $cookie_value, 
                                time() + REMEMBER_ME_LIFETIME, 
                                COOKIE_PATH, 
                                COOKIE_DOMAIN, 
                                COOKIE_SECURE, 
                                COOKIE_HTTPONLY
                            );
                        }
                        
                        $response['success'] = true;
                        $response['message'] = "Login successful! Welcome back, " . $user['username'] . "!";
                        $response['redirect'] = DASHBOARD_PAGE;
                        
                        // Log successful login
                        logAppError("Successful login for user: " . $user['email'] . " from IP: " . $clientIP);
                        
                    } else {
                        // Record failed login attempt
                        recordFailedLogin($clientIP);
                        
                        $response['success'] = false;
                        $response['message'] = "Invalid email or password.";
                        
                        // Log failed login attempt
                        logAppError("Failed login attempt for email: " . $email . " from IP: " . $clientIP);
                    }
                }
            } else {
                // Record failed login attempt
                recordFailedLogin($clientIP);
                
                $response['success'] = false;
                $response['message'] = "Invalid email or password.";
                
                // Log failed login attempt
                logAppError("Failed login attempt for non-existent email: " . $email . " from IP: " . $clientIP);
            }
            
            $stmt->close();
            
        } catch (Exception $e) {
            $response['success'] = false;
            $response['message'] = "An error occurred. Please try again later.";
            logAppError("Login error: " . $e->getMessage());
        }
    }
    
    // Return JSON response for AJAX requests
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
    
    // Set flash message for regular form submission
    if (isset($response['message'])) {
        setFlashMessage($response['success'] ? 'success' : 'error', $response['message']);
    }
    
    // Redirect on successful login for regular form submission
    if (isset($response['success']) && $response['success'] && isset($response['redirect'])) {
        header("Location: /schoolAdmin/driver.tracker/dashboard.php");
        exit();
    }
}

// ✅ FIX #2: Handle remember me cookie on page load with STATUS CHECK
if (!isLoggedIn() && isset($_COOKIE['remember_login'])) {
    try {
        $cookie_data = base64_decode($_COOKIE['remember_login']);
        $parts = explode(':', $cookie_data);
        
        if (count($parts) == 2) {
            $user_id = intval($parts[0]);
            $token = $parts[1];
            $token_hash = hash('sha256', $token);
            
            // Check if token exists and is valid - ALSO GET STATUS
            $stmt = $conn->prepare("SELECT u.user_id, u.driverId, u.username, u.email, u.status FROM user_login u INNER JOIN user_remember_tokens rt ON u.user_id = rt.user_id WHERE u.user_id = ? AND rt.token_hash = ? AND rt.expires_at > NOW()");
            $stmt->bind_param("is", $user_id, $token_hash);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows == 1) {
                $user = $result->fetch_assoc();
                
                // ✅ CHECK IF USER IS ACTIVE BEFORE AUTO-LOGIN
                $userStatus = isset($user['status']) ? intval($user['status']) : 0;
                
                if ($userStatus == 1) {
                    // User is ACTIVE - Allow auto-login
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['driver_id'] = $user['driverId'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['logged_in'] = true;
                    $_SESSION['login_time'] = time();
                    $_SESSION['last_activity'] = time();
                    
                    // Update last login
                    $update_stmt = $conn->prepare("UPDATE user_login SET last_login = CURRENT_TIMESTAMP WHERE user_id = ?");
                    $update_stmt->bind_param("i", $user['user_id']);
                    $update_stmt->execute();
                    $update_stmt->close();
                    
                    // Redirect to dashboard
                    redirect(DASHBOARD_PAGE);
                } else {
                    // User is INACTIVE - Remove cookie and block auto-login
                    setcookie('remember_login', '', time() - 3600, COOKIE_PATH, COOKIE_DOMAIN, COOKIE_SECURE, COOKIE_HTTPONLY);
                    logAppError("Auto-login blocked for inactive user: " . $user['email']);
                }
            } else {
                // Invalid token, remove cookie
                setcookie('remember_login', '', time() - 3600, COOKIE_PATH, COOKIE_DOMAIN, COOKIE_SECURE, COOKIE_HTTPONLY);
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        logAppError("Remember me token error: " . $e->getMessage());
        setcookie('remember_login', '', time() - 3600, COOKIE_PATH, COOKIE_DOMAIN, COOKIE_SECURE, COOKIE_HTTPONLY);
    }

    // Check if user is already logged in
    if (isLoggedIn()) {
        redirect(DASHBOARD_PAGE);
    }
}

// Get flash message
$flashMessage = getFlashMessage();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            overflow: hidden;
            height: 100%;
        }

        body {
            margin: 0;
            min-height: 100dvh; /* better than 100vh */
            display: grid;
            place-items: center;
            overflow: hidden;
        }
        .bg-decoration {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: -1;
        }

        .floating-orb {
            position: absolute;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(0, 0, 255, 0.1), rgba(65, 105, 225, 0.05));
            filter: blur(1px);
            animation: float 15s infinite ease-in-out;
        }

        .orb-1 {
            width: 200px;
            height: 200px;
            top: -50px;
            right: -50px;
            animation-delay: 0s;
        }

        .orb-2 {
            width: 150px;
            height: 150px;
            bottom: -30px;
            left: -30px;
            animation-delay: -5s;
        }

        .orb-3 {
            width: 100px;
            height: 100px;
            top: 50%;
            left: -20px;
            animation-delay: -10s;
        }

        .grid-pattern {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: 
                linear-gradient(rgba(0, 0, 255, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0, 0, 255, 0.03) 1px, transparent 1px);
            background-size: 50px 50px;
            animation: gridMove 20s linear infinite;
        }

        @keyframes float {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            25% { transform: translate(30px, -30px) rotate(90deg); }
            50% { transform: translate(-20px, 20px) rotate(180deg); }
            75% { transform: translate(-30px, -20px) rotate(270deg); }
        }

        @keyframes gridMove {
            0% { transform: translate(0, 0); }
            100% { transform: translate(50px, 50px); }
        }

        .login-container {
            background: white;
            border-radius: 24px;
            padding: 24px 22px;
            box-shadow: 
                0 32px 64px rgba(0, 0, 255, 0.12),
                0 0 0 1px rgba(0, 0, 255, 0.08),
                inset 0 1px 0 rgba(255, 255, 255, 0.9);
            width: 100%;
            max-width: 320px;
            max-height:100%;
            overflow-y: hidden;
            position: relative;
            transform: translateY(0);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(20px);
        }

        .login-container::-webkit-scrollbar {
            width: 6px;
        }

        .login-container::-webkit-scrollbar-track {
            background: transparent;
        }

        .login-container::-webkit-scrollbar-thumb {
            background: rgba(0, 0, 255, 0.2);
            border-radius: 10px;
        }

        .login-container::-webkit-scrollbar-thumb:hover {
            background: rgba(0, 0, 255, 0.4);
        }

        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #0000FF, #4169E1, #0000FF);
            border-radius: 24px 24px 0 0;
            background-size: 200% 100%;
            animation: shimmer 3s ease-in-out infinite;
        }

        @keyframes shimmer {
            0%, 100% { background-position: 200% 0; }
            50% { background-position: -200% 0; }
        }

        .login-container:hover {
            transform: none;
            box-shadow: 
                0 40px 80px rgba(0, 0, 255, 0.16),
                0 0 0 1px rgba(0, 0, 255, 0.12),
                inset 0 1px 0 rgba(255, 255, 255, 0.9);
        }

        .login-header {
            text-align: center;
            margin-bottom: 32px;
            position: relative;
        }

        .logo-icon img {
            width: 60px;
            height: 60px;
            margin-bottom: 16px;
        }

        .login-title {
            color: #1a1a1a;
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 8px;
            background: linear-gradient(135deg, #1a1a1a 0%, #0000FF 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.02em;
        }

        .login-subtitle {
            color: #6b7280;
            font-size: 1rem;
            font-weight: 400;
            opacity: 0.8;
        }

        .form-group {
            margin-bottom: 24px;
            position: relative;
        }

        .form-label {
            display: block;
            color: #374151;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 0.9rem;
            letter-spacing: -0.01em;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 16px;
            transition: color 0.3s ease;
            z-index: 1;
        }

        .form-input {
            width: 100%;
            padding: 14px 18px 14px 46px;
            border: 2px solid #e5e7eb;
            border-radius: 14px;
            font-size: 0.95rem;
            background: #fafafa;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            outline: none;
            font-weight: 500;
        }

        .form-input:focus {
            border-color: #0000FF;
            background: white;
            box-shadow: 0 0 0 4px rgba(0, 0, 255, 0.1);
            transform: translateY(-2px);
        }

        .form-input:focus + .input-icon {
            color: #0000FF;
        }

        .form-input::placeholder {
            color: #9ca3af;
            font-weight: 400;
        }

        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #9ca3af;
            cursor: pointer;
            font-size: 16px;
            transition: color 0.3s ease;
            z-index: 1;
        }

        .password-toggle:hover {
            color: #0000FF;
        }

       .checkbox-group {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    margin-bottom: 28px;
}

        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .custom-checkbox {
            width: 22px;
            height: 22px;
            border: 2px solid #d1d5db;
            border-radius: 6px;
            cursor: pointer;
            position: relative;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .custom-checkbox.checked {
            background: #0000FF;
            border-color: #0000FF;
            transform: scale(1.1);
        }

        .custom-checkbox.checked::after {
            content: '✓';
            color: white;
            font-size: 13px;
            font-weight: bold;
            animation: checkmark 0.3s ease;
        }

        @keyframes checkmark {
            0% { transform: scale(0) rotate(45deg); }
            100% { transform: scale(1) rotate(0deg); }
        }

        .checkbox-label {
            color: #374151;
            font-size: 0.9rem;
            cursor: pointer;
            user-select: none;
            font-weight: 500;
        }

        .forgot-link {
            color: #0000FF;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            position: relative;
            white-space: nowrap;
        }

        .forgot-link::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: #0000FF;
            transition: width 0.3s ease;
        }

        .forgot-link:hover::after {
            width: 100%;
        }

        .login-button {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #0000FF 0%, #4169E1 100%);
            color: white;
            border: none;
            border-radius: 14px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            letter-spacing: 0.5px;
            display: block;
            margin: 0 0 28px 0;
        }

        .login-button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.6s ease;
        }

        .login-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 16px 40px rgba(0, 0, 255, 0.4);
            background: linear-gradient(135deg, #0000CC 0%, #365AC7 100%);
        }

        .login-button:hover::before {
            left: 100%;
        }

        .login-button:active {
            transform: translateY(-1px);
        }

        .login-button:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            background: #9ca3af;
        }

        .signup-link {
            text-align: center;
            margin-top: 24px;
            color: #6b7280;
            font-size: 0.9rem;
        }

        .signup-link a {
            color: #0000FF;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }

        .signup-link a:hover {
            color: #4169E1;
        }

        .alert {
            padding: 14px;
            margin-bottom: 20px;
            border-radius: 12px;
            font-weight: 500;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .alert-success {
            background-color: #dcfce7;
            border: 1px solid #bbf7d0;
            color: #166534;
        }

        .alert-error {
            background-color: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
        }

        @media (max-width: 480px) {
            .login-container {
                padding: 28px 20px;
                max-height: 95vh;
            }
            
            .login-title {
                font-size: 1.75rem;
            }

            .login-subtitle {
                font-size: 0.9rem;
            }
            
            .checkbox-group {
                flex-direction: column;
                align-items: flex-start;
                gap: 14px;
            }

            .form-group {
                margin-bottom: 20px;
            }

            .login-button {
                padding: 14px;
                margin-bottom: 20px;
            }
        }

        .loading {
            position: relative;
        }

        .loading::after {
            content: '';
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: translateY(-50%) rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="bg-decoration">
        <div class="grid-pattern"></div>
        <div class="floating-orb orb-1"></div>
        <div class="floating-orb orb-2"></div>
        <div class="floating-orb orb-3"></div>
    </div>

    <div class="login-container">
        <div class="login-header">
            <div class="logo-icon"><img src="/schoolAdmin/driver.tracker/icon/admin_panel.png" alt="Logo"></div>
            <h1 class="login-title">Welcome Back</h1>
            <p class="login-subtitle">Sign in to continue to your account</p>
        </div>

        <?php if ($flashMessage): ?>
            <div class="alert <?php echo $flashMessage['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>">
                <?php echo htmlspecialchars($flashMessage['message']); ?>
            </div>
        <?php endif; ?>

        <form id="loginForm" method="POST" action="/schoolAdmin/driver.tracker/index.php">
            <div class="form-group">
                <label class="form-label" for="email">Email Address</label>
                <div class="input-wrapper">
                    <input 
                        type="email" 
                        id="email" 
                        name="email"
                        class="form-input" 
                        placeholder="Enter your email address"
                        value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                        required
                    >
                    <div class="input-icon">📧</div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="password">Password</label>
                <div class="input-wrapper">
                    <input 
                        type="password" 
                        id="password" 
                        name="password"
                        class="form-input" 
                        placeholder="Enter your password"
                        required
                    >
                    <div class="input-icon">🔒</div>
                    <button type="button" class="password-toggle" id="passwordToggle">👁️</button>
                </div>
            </div>

         <div class="checkbox-group">
    <a href="/schoolAdmin/driver.tracker/forgetPassword.php" class="forgot-link">Forgot password?</a>
</div>

            <button type="submit" class="login-button" id="loginBtn">
                Sign In
            </button>
        </form>

        <div class="signup-link">
            Don't have an account? <a href="signUp.html">Sign up</a>
        </div>
    </div>

    <script>
        // Password toggle functionality
        const passwordToggle = document.getElementById('passwordToggle');
        const passwordInput = document.getElementById('password');

        passwordToggle.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.textContent = type === 'password' ? '👁️' : '🙈';
        });

      

        // Input focus enhancements
        const inputs = document.querySelectorAll('.form-input');
        inputs.forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.parentElement.style.transform = 'scale(1.02)';
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.parentElement.style.transform = 'scale(1)';
            });
        });

        // Auto-hide alerts after 5 seconds
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.opacity = '0';
                setTimeout(() => {
                    alert.remove();
                }, 300);
            }, 5000);
        });

        // Form submission enhancement
        const loginForm = document.getElementById('loginForm');
        const loginBtn = document.getElementById('loginBtn');

        loginForm.addEventListener('submit', function() {
            loginBtn.classList.add('loading');
            loginBtn.disabled = true;
        });
    </script>
</body>
</html>