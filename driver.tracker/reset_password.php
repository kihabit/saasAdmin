<?php 
// Enable error reporting (remove in production)
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'config.php';

// Get database connection
$db = Database::getInstance();
$conn = $db->getConnection();

$response = [];
$showSuccess = false;
$validToken = false;
$email = '';

// Check if token is provided and valid
if (isset($_GET['token']) && !empty($_GET['token'])) {
    $token = $_GET['token'];
    
    // Verify token and check if it's not expired
    $stmt = $conn->prepare("SELECT email, expire_at FROM user_login WHERE token = ? AND expire_at > NOW()");
    $stmt->bind_param("s", $token);
    
    $stmt->execute();
    
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $validToken = true;
        $email = $row['email'];
    } else {
        $response['error'] = "Invalid or expired reset token.";
    }
} else {
    $response['error'] = "No reset token provided.";
}

// Process password reset form submission
if ($_POST && $validToken) {
    $newPassword = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    // Validate passwords
    if (empty($newPassword) || empty($confirmPassword)) {
        $response['error'] = "Please fill in all fields.";
    } elseif (strlen($newPassword) < 8) {
        $response['error'] = "Password must be at least 8 characters long.";
    } elseif ($newPassword !== $confirmPassword) {
        $response['error'] = "Passwords do not match.";
    } else {
        // Hash the new password with MD5
        $hashedPassword = md5($newPassword);
        
        // Update password and clear token
        $stmt = $conn->prepare("UPDATE user_login SET password_hash = ?, token = NULL, expire_at = NULL WHERE token = ?");
        $stmt->bind_param("ss", $hashedPassword, $token);
        
        if ($stmt->execute()) {
            $response['success'] = "Password reset successfully!";
            $showSuccess = true;
        } else {
            $response['error'] = "Failed to reset password. Please try again.";
        }
    }
    
    // Return JSON response for AJAX requests
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo json_encode($response);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: white;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 80px 20px;
            position: relative;
            overflow-x: hidden;
        }

        /* Animated background elements */
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

        .reset-container {
            background: white;
            border-radius: 24px;
            padding: 48px 40px;
            box-shadow: 
                0 32px 64px rgba(0, 0, 255, 0.12),
                0 0 0 1px rgba(0, 0, 255, 0.08),
                inset 0 1px 0 rgba(255, 255, 255, 0.9);
            width: 100%;
            max-width: 420px;
            position: relative;
            transform: translateY(0);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(20px);
            margin: 60px 0;
        }

        .reset-container::before {
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

        .reset-container:hover {
            transform: translateY(-8px);
            box-shadow: 
                0 40px 80px rgba(0, 0, 255, 0.16),
                0 0 0 1px rgba(0, 0, 255, 0.12),
                inset 0 1px 0 rgba(255, 255, 255, 0.9);
        }

        .reset-header {
            text-align: center;
            margin-bottom: 40px;
            position: relative;
        }

        .logo-icon img {
            width: 60px;
            height: 60px;
            margin-bottom: 20px;
        }

        .reset-title {
            color: #1a1a1a;
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 12px;
            background: linear-gradient(135deg, #1a1a1a 0%, #0000FF 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.02em;
        }

        .reset-subtitle {
            color: #374151;
            font-size: 1rem;
            font-weight: 500;
            line-height: 1.5;
            margin-bottom: 8px;
        }

        .user-email {
            color: #0000FF;
            font-weight: 600;
            font-size: 0.9rem;
            background: rgba(0, 0, 255, 0.1);
            padding: 8px 16px;
            border-radius: 12px;
            display: inline-block;
            margin-top: 8px;
        }

        .form-group {
            margin-bottom: 28px;
            position: relative;
        }

        .form-label {
            display: block;
            color: #000000;
            font-weight: 500;
            margin-bottom: 10px;
            font-size: 1.1rem;
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
            color: #374151;
            font-size: 20px;
            transition: color 0.3s ease;
            z-index: 1;
        }

        .toggle-password {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #374151;
            font-size: 18px;
            cursor: pointer;
            transition: color 0.3s ease;
            z-index: 1;
        }

        .toggle-password:hover {
            color: #0000FF;
        }

        .form-input {
            width: 100%;
            padding: 16px 50px 16px 50px;
            border: 2px solid #e5e7eb;
            border-radius: 16px;
            font-size: 1rem;
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
            color: #374151;
            font-weight: 500;
        }

        .form-input.invalid {
            border-color: #ef4444;
            background: #fef2f2;
        }

        .form-input.valid {
            border-color: #10b981;
        }

        .field-error {
            margin-top: 6px;
            font-size: 0.85rem;
            color: #ef4444;
            display: none;
        }

        .field-success {
            margin-top: 6px;
            font-size: 0.85rem;
            color: #10b981;
            display: none;
        }

        .password-strength {
            margin-top: 8px;
            font-size: 0.85rem;
        }

        .strength-bar {
            width: 100%;
            height: 4px;
            background: #e5e7eb;
            border-radius: 2px;
            margin: 6px 0;
            overflow: hidden;
        }

        .strength-fill {
            height: 100%;
            width: 0%;
            transition: all 0.3s ease;
            border-radius: 2px;
        }

        .strength-weak .strength-fill {
            width: 33%;
            background: #ef4444;
        }

        .strength-medium .strength-fill {
            width: 66%;
            background: #f59e0b;
        }

        .strength-strong .strength-fill {
            width: 100%;
            background: #10b981;
        }

        .strength-text {
            font-size: 0.8rem;
            font-weight: 500;
        }

        .strength-weak .strength-text {
            color: #ef4444;
        }

        .strength-medium .strength-text {
            color: #f59e0b;
        }

        .strength-strong .strength-text {
            color: #10b981;
        }

        .reset-button {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, #0000FF 0%, #4169E1 100%);
            color: white;
            border: none;
            border-radius: 16px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            letter-spacing: 0.5px;
            display: block;
            margin: 0 0 32px 0;
            z-index: 10;
        }

        .reset-button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.6s ease;
        }

        .reset-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 16px 40px rgba(0, 0, 255, 0.4);
            background: linear-gradient(135deg, #0000CC 0%, #365AC7 100%);
        }

        .reset-button:hover::before {
            left: 100%;
        }

        .reset-button:active {
            transform: translateY(-1px);
        }

        .reset-button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            background: #9ca3af;
        }

        .success-message {
            background: linear-gradient(135deg, #ecfdf5, #d1fae5);
            border: 2px solid #10b981;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
            display: none;
            animation: slideInUp 0.5s ease-out;
        }

        .success-message.show {
            display: block;
        }

        .error-message {
            background: linear-gradient(135deg, #fef2f2, #fecaca);
            border: 2px solid #ef4444;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
            display: none;
            animation: slideInUp 0.5s ease-out;
        }

        .error-message.show {
            display: block;
        }

        .success-icon, .error-icon {
            font-size: 2rem;
            text-align: center;
            margin-bottom: 12px;
        }

        .success-title, .error-title {
            font-size: 1.1rem;
            font-weight: 600;
            text-align: center;
            margin-bottom: 8px;
        }

        .success-title {
            color: #065f46;
        }

        .error-title {
            color: #991b1b;
        }

        .success-text, .error-text {
            font-size: 0.9rem;
            text-align: center;
            line-height: 1.4;
        }

        .success-text {
            color: #047857;
        }

        .error-text {
            color: #dc2626;
        }

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .back-to-login {
            text-align: center;
            margin-top: 32px;
        }

        .back-link {
            color: #0000FF;
            text-decoration: none;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .back-link::before {
            content: '←';
            transition: transform 0.3s ease;
        }

        .back-link:hover::before {
            transform: translateX(-4px);
        }

        .back-link::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: #0000FF;
            transition: width 0.3s ease;
        }

        .back-link:hover::after {
            width: 100%;
        }

        @media (max-width: 480px) {
            .reset-container {
                padding: 32px 24px;
                margin: 30px 10px;
                max-width: 100%;
            }
            
            .reset-title {
                font-size: 1.8rem;
            }
        }

        /* Loading animation */
        .loading {
            position: relative;
        }

        .loading::after {
            content: '';
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: translateY(-50%) rotate(360deg); }
        }

        /* Shake animation */
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
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

    <div class="reset-container">
        <div class="reset-header">
            <div class="logo-icon">
                <img src="https://erphub.ai/driver.tracker/icon/ic_logo.png" alt="Logo">
            </div>
            <h1 class="reset-title">Reset Password</h1>
            <?php if ($validToken): ?>
                <p class="reset-subtitle">Create a new password for your account</p>
                <div class="user-email"><?php echo htmlspecialchars($email); ?></div>
            <?php else: ?>
                <p class="reset-subtitle">Invalid or expired reset link</p>
            <?php endif; ?>
        </div>

        <!-- Success Message -->
        <div class="success-message <?php echo $showSuccess ? 'show' : ''; ?>" id="successMessage">
            <div class="success-icon">🎉</div>
            <div class="success-title">Password Reset Successful!</div>
            <div class="success-text">
                Your password has been successfully reset. You can now sign in with your new password.
            </div>
        </div>

        <!-- Error Message -->
        <div class="error-message <?php echo !$validToken || (isset($response['error']) && !$showSuccess) ? 'show' : ''; ?>" id="errorMessage">
            <div class="error-icon">❌</div>
            <div class="error-title">Error</div>
            <div class="error-text" id="errorText">
                <?php 
                if (!$validToken) {
                    echo "The password reset link is invalid or has expired. Please request a new password reset.";
                } elseif (isset($response['error'])) {
                    echo htmlspecialchars($response['error']);
                }
                ?>
            </div>
        </div>

        <?php if ($validToken && !$showSuccess): ?>
        <form id="resetForm" method="POST">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            
            <div class="form-group">
                <label class="form-label" for="password">New Password</label>
                <div class="input-wrapper">
                    <input 
                        type="password" 
                        id="password" 
                        name="password"
                        class="form-input" 
                        placeholder="Enter your new password"
                        required
                    >
                    <div class="input-icon">🔒</div>
                    <button type="button" class="toggle-password" data-target="password">👁️</button>
                </div>
                <div class="field-error" id="passwordError">Password must be at least 8 characters</div>
                <div class="field-success" id="passwordSuccess">✓ Strong password</div>
                
                <div class="password-strength" id="passwordStrength">
                    <div class="strength-bar">
                        <div class="strength-fill"></div>
                    </div>
                    <div class="strength-text">Password strength</div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="confirm_password">Confirm Password</label>
                <div class="input-wrapper">
                    <input 
                        type="password" 
                        id="confirm_password" 
                        name="confirm_password"
                        class="form-input" 
                        placeholder="Confirm your new password"
                        required
                    >
                    <div class="input-icon">🔒</div>
                    <button type="button" class="toggle-password" data-target="confirm_password">👁️</button>
                </div>
                <div class="field-error" id="confirm_passwordError">Passwords do not match</div>
                <div class="field-success" id="confirm_passwordSuccess">✓ Passwords match</div>
            </div>

            <button type="submit" class="reset-button" id="resetBtn">
                Reset Password
            </button>
        </form>
        <?php endif; ?>

        <div class="back-to-login">
            <a href="/driver.tracker/index.php" class="back-link">
                <?php echo $showSuccess ? 'Sign In Now' : 'Back to Sign In'; ?>
            </a>
        </div>
    </div>

    <script>
        // Form elements
        const resetForm = document.getElementById('resetForm');
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const resetBtn = document.getElementById('resetBtn');
        const passwordStrength = document.getElementById('passwordStrength');

        // Password strength checker
        function checkPasswordStrength(password) {
            let strength = 0;
            let feedback = [];

            if (password.length >= 8) strength += 1;
            else feedback.push('At least 8 characters');

            if (/[a-z]/.test(password)) strength += 1;
            else feedback.push('Lowercase letter');

            if (/[A-Z]/.test(password)) strength += 1;
            else feedback.push('Uppercase letter');

            if (/[0-9]/.test(password)) strength += 1;
            else feedback.push('Number');

            if (/[^A-Za-z0-9]/.test(password)) strength += 1;
            else feedback.push('Special character');

            return { strength, feedback };
        }

        function updatePasswordStrength(password) {
            const result = checkPasswordStrength(password);
            const strengthBar = passwordStrength.querySelector('.strength-fill');
            const strengthText = passwordStrength.querySelector('.strength-text');

            // Remove existing classes
            passwordStrength.classList.remove('strength-weak', 'strength-medium', 'strength-strong');

            if (password.length === 0) {
                strengthText.textContent = 'Password strength';
                return;
            }

            if (result.strength <= 2) {
                passwordStrength.classList.add('strength-weak');
                strengthText.textContent = 'Weak password';
            } else if (result.strength <= 3) {
                passwordStrength.classList.add('strength-medium');
                strengthText.textContent = 'Medium password';
            } else {
                passwordStrength.classList.add('strength-strong');
                strengthText.textContent = 'Strong password';
            }
        }

        function showFieldValidation(fieldId, isValid, errorMsg = '', successMsg = '') {
            const field = document.getElementById(fieldId);
            const errorElement = document.getElementById(fieldId + 'Error');
            const successElement = document.getElementById(fieldId + 'Success');

            if (field.value.trim() === '') {
                field.classList.remove('valid', 'invalid');
                errorElement.style.display = 'none';
                successElement.style.display = 'none';
                return false;
            }

            if (isValid) {
                field.classList.remove('invalid');
                field.classList.add('valid');
                errorElement.style.display = 'none';
                successElement.style.display = 'block';
                if (successMsg) successElement.textContent = successMsg;
                return true;
            } else {
                field.classList.remove('valid');
                field.classList.add('invalid');
                errorElement.style.display = 'block';
                successElement.style.display = 'none';
                if (errorMsg) errorElement.textContent = errorMsg;
                return false;
            }
        }

        function updateSubmitButton() {
            const isPasswordValid = passwordInput.value.length >= 8;
            const doPasswordsMatch = passwordInput.value === confirmPasswordInput.value && passwordInput.value.length > 0;
            
            resetBtn.disabled = !(isPasswordValid && doPasswordsMatch);
            resetBtn.style.opacity = (isPasswordValid && doPasswordsMatch) ? '1' : '0.6';
        }

        // Password validation
        if (passwordInput) {
            passwordInput.addEventListener('input', function() {
                const isValid = this.value.length >= 8;
                updatePasswordStrength(this.value);
                showFieldValidation('password', isValid);
                updateSubmitButton();
            });

            // Confirm password validation
            confirmPasswordInput.addEventListener('input', function() {
                const isValid = this.value === passwordInput.value && this.value.length > 0;
                showFieldValidation('confirm_password', isValid);
                updateSubmitButton();
            });

            // Password toggle functionality
            document.querySelectorAll('.toggle-password').forEach(button => {
                button.addEventListener('click', function() {
                    const targetId = this.getAttribute('data-target');
                    const targetInput = document.getElementById(targetId);
                    
                    if (targetInput.type === 'password') {
                        targetInput.type = 'text';
                        this.textContent = '🙈';
                    } else {
                        targetInput.type = 'password';
                        this.textContent = '👁️';
                    }
                });
            });

            // Form submission
            resetForm.addEventListener('submit', function(e) {
                const isPasswordValid = passwordInput.value.length >= 8;
                const doPasswordsMatch = passwordInput.value === confirmPasswordInput.value;

                if (!isPasswordValid) {
                    e.preventDefault();
                    showFieldValidation('password', false, 'Password must be at least 8 characters');
                    return;
                }

                if (!doPasswordsMatch) {
                    e.preventDefault();
                    showFieldValidation('confirm_password', false, 'Passwords do not match');
                    return;
                }

                // Add loading state
                resetBtn.classList.add('loading');
                resetBtn.textContent = 'Resetting...';
                resetBtn.disabled = true;
            });

            // Initial state
            updateSubmitButton();
        }

        // Add floating particles
        function createParticle() {
            const particle = document.createElement('div');
            particle.style.cssText = `
                position: fixed;
                width: 4px;
                height: 4px;
                background: rgba(0, 0, 255, 0.3);
                border-radius: 50%;
                pointer-events: none;
                z-index: -1;
                animation: particleFloat 3s ease-out forwards;
            `;
            
            particle.style.left = Math.random() * 100 + 'vw';
            particle.style.top = '100vh';
            
            document.body.appendChild(particle);
            
            setTimeout(() => {
                particle.remove();
            }, 3000);
        }

        // Add particle animation
        const particleStyle = document.createElement('style');
        particleStyle.textContent = `
            @keyframes particleFloat {
                to {
                    transform: translateY(-100vh) rotate(360deg);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(particleStyle);

        setInterval(createParticle, 2000);
    </script>
</body>
</html>