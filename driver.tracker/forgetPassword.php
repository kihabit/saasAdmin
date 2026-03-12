<?php 
// Enable error reporting (remove in production)
ini_set('display_errors', 0);
error_reporting(E_ALL);
require_once 'config.php';


use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

// Get database connection
$db = Database::getInstance();
$conn = $db->getConnection();

$response = [];
$showSuccess = false;
//$_POST['email']='kneerajkumarn@gmail.com';
if(!empty($_POST['email'])){
    $email = $_POST['email'] ?? '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $response['error'] = "Invalid email format";
        
        // ✅ Check if request is from app (check for specific header or return JSON for all POST)
        if (isset($_POST['from_app']) || strpos($_SERVER['HTTP_USER_AGENT'] ?? '', 'okhttp') !== false) {
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }
        // For web, continue to show HTML page with error
    } else {
        // Check if email exists
        $stmt = $conn->prepare("SELECT user_id FROM user_login WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            // Generate token
            $token = bin2hex(random_bytes(32));
            $expiry = date("Y-m-d H:i:s", strtotime("+1 hour"));

            // Store token
            $stmt_update = $conn->prepare("UPDATE user_login SET token = ?, expire_at = ? WHERE email = ?");
            $stmt_update->bind_param("sss", $token, $expiry, $email);
            $stmt_update->execute();

            // Prepare email
            $resetLink = "https://erphub.ai/driver.tracker/reset_password.php?token=$token";
            $subject = "Reset Your Password";
            $message = "
                <html>
                <head><title>Password Reset</title></head>
                <body>
                    <p>Click the link below to reset your password:</p>
                    <p><a href='$resetLink'>$resetLink</a></p>
                    <p>This link will expire in 1 hour.</p>
                </body>
                </html>
            ";
            // $headers  = "MIME-Version: 1.0\r\n";
            // $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            // $headers .= "From: Key Dynamics <keydynamicsit@gmail.com>\r\n";
            // $headers .= "Reply-To: keydynamicsit@gmail.com\r\n";

            
            
            $mail = new PHPMailer(true);

            try {
                $mail->isSMTP();
                $mail->Host       = 'mail.erphub.ai';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'no-reply@erphub.ai';
                $mail->Password   = 'quuNpY@2]NWN'; // Password
                $mail->SMTPSecure = false;
                $mail->Port       = 25;
            
                $mail->setFrom('no-reply@erphub.ai', 'ERP Hub');
                $mail->addAddress($email);
            
                $mail->isHTML(true);
                $mail->Subject = 'Reset Your Password';
                $mail->Body    = $message;
            
                $mail->send();
                $response['success'] = "Password reset email sent.";
                $showSuccess = true;
            
            } catch (Exception $e) {
                $response['error'] = "Mail error: {$mail->ErrorInfo}";
            }
            // if (mail($email, $subject, $message, $headers)) {
            //     $response['success'] = "Password reset email sent.";
            //     $showSuccess = true;
            // } else {
            //     $response['error'] = "Failed to send email.";
            // }
        } else {
            $response['error'] = "Email not found.";
        }

        // ✅ Return JSON only for app requests
        if (isset($_POST['from_app']) || strpos($_SERVER['HTTP_USER_AGENT'] ?? '', 'okhttp') !== false) {
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }
        // For web, continue to show HTML page with success/error
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password</title>
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

        .forgot-container {
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

        .forgot-container::before {
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

        .forgot-container:hover {
            transform: translateY(-8px);
            box-shadow: 
                0 40px 80px rgba(0, 0, 255, 0.16),
                0 0 0 1px rgba(0, 0, 255, 0.12),
                inset 0 1px 0 rgba(255, 255, 255, 0.9);
        }

        .forgot-header {
            text-align: center;
            margin-bottom: 40px;
            position: relative;
        }

        .logo-icon svg {
            width: 60px;
            height: 60px;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        .forgot-title {
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

        .forgot-subtitle {
            color: #374151;
            font-size: 1rem;
            font-weight: 500;
            opacity: 1;
            line-height: 1.5;
            margin-bottom: 8px;
        }

        .forgot-description {
            color: #4b5563;
            font-size: 0.9rem;
            font-weight: 400;
            line-height: 1.4;
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

        .form-input {
            width: 100%;
            padding: 16px 20px 16px 50px;
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

        .additional-help {
            text-align: center;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid #e5e7eb;
        }

        .help-text {
            color: #1f2937;
            font-size: 1rem;
            margin-bottom: 12px;
            font-weight: 500;
        }

        .contact-link {
            color: #0000FF;
            text-decoration: none;
            font-weight: 600;
            font-size: 1rem;
            transition: color 0.3s ease;
        }

        .contact-link:hover {
            color: #4169E1;
            text-decoration: underline;
        }

        @media (max-width: 480px) {
            .forgot-container {
                padding: 32px 24px;
                margin: 30px 10px;
                max-width: 100%;
            }
            
            .forgot-title {
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

    <div class="forgot-container">
        <div class="forgot-header">
            <div class="logo-icon">
                <img src="https://erphub.ai/driver.tracker/icon/ic_logo.png">
            </div>
            <h1 class="forgot-title">Forgot Password?</h1>
            <p class="forgot-subtitle">No worries! We'll help you reset it.</p>
            <p class="forgot-description">Enter your email address and we'll send you a secure link to reset your password.</p>
        </div>

        <!-- Success Message -->
        <div class="success-message <?php echo $showSuccess && isset($response['success']) ? 'show' : ''; ?>" id="successMessage">
            <div class="success-icon">✅</div>
            <div class="success-title">Reset Link Sent!</div>
            <div class="success-text">
                We've sent a password reset link to your email address. 
                Please check your inbox and follow the instructions to reset your password.
            </div>
        </div>

        <!-- Error Message -->
        <div class="error-message <?php echo !$showSuccess && isset($response['error']) ? 'show' : ''; ?>" id="errorMessage">
            <div class="error-icon">❌</div>
            <div class="error-title">Error</div>
            <div class="error-text" id="errorText">
                <?php echo isset($response['error']) ? htmlspecialchars($response['error']) : ''; ?>
            </div>
        </div>

        <form id="forgotForm" method="POST" <?php echo $showSuccess ? 'style="display: none;"' : ''; ?>>
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
                <div class="field-error" id="emailError">Please enter a valid email address</div>
                <div class="field-success" id="emailSuccess">✓ Valid email</div>
            </div>

            <button type="submit" class="reset-button" id="resetBtn">
                Send Reset Link
            </button>
        </form>

        <div class="back-to-login">
            <a href="./login.php" class="back-link" id="backToLogin">Back to Sign In</a>
        </div>

        <div class="additional-help">
            <p class="help-text">Still having trouble accessing your account?</p>
            <a href="#" class="contact-link" id="contactSupport">Contact Support</a>
        </div>
    </div>

    <script>
        // Form elements
        const forgotForm = document.getElementById('forgotForm');
        const emailInput = document.getElementById('email');
        const resetBtn = document.getElementById('resetBtn');
        const successMessage = document.getElementById('successMessage');
        const errorMessage = document.getElementById('errorMessage');

        // Validation function
        function validateEmail(email) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email.trim());
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
            const isEmailValid = validateEmail(emailInput.value);
            
            resetBtn.disabled = !isEmailValid;
            resetBtn.style.opacity = isEmailValid ? '1' : '0.6';
            
            if (!isEmailValid && emailInput.value.trim() !== '') {
                resetBtn.textContent = 'Enter Valid Email';
            } else {
                resetBtn.textContent = 'Send Reset Link';
            }
        }

        // Email validation
        emailInput.addEventListener('input', function() {
            const isValid = validateEmail(this.value);
            showFieldValidation('email', isValid);
            updateSubmitButton();
        });

        emailInput.addEventListener('blur', function() {
            if (this.value.trim() === '') {
                showFieldValidation('email', false, 'Email address is required');
            }
        });

        // Form submission
        forgotForm.addEventListener('submit', function(e) {
            const isEmailValid = validateEmail(emailInput.value);

            if (!emailInput.value.trim()) {
                e.preventDefault();
                showFieldValidation('email', false, 'Email address is required');
                return;
            }

            if (!isEmailValid) {
                e.preventDefault();
                this.style.animation = 'shake 0.5s ease-in-out';
                setTimeout(() => {
                    this.style.animation = '';
                }, 500);
                
                showFieldValidation('email', false, 'Please enter a valid email address');
                return;
            }

            // Add loading state
            resetBtn.classList.add('loading');
            resetBtn.textContent = 'Sending...';
            resetBtn.disabled = true;
        });

        document.getElementById('contactSupport').addEventListener('click', function(e) {
            e.preventDefault();
            alert('📞 Contact Support:\n\nEmail: support@company.com\nPhone: 1-800-SUPPORT\nLive Chat: Available 24/7');
        });

        // Input focus enhancements
        emailInput.addEventListener('focus', function() {
            this.parentElement.parentElement.style.transform = 'scale(1.01)';
        });
        
        emailInput.addEventListener('blur', function() {
            this.parentElement.parentElement.style.transform = 'scale(1)';
        });

        // Initial state
        updateSubmitButton();

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

        // Hide messages after some time
        setTimeout(function() {
            if (successMessage.classList.contains('show')) {
                successMessage.style.opacity = '0.8';
            }
            if (errorMessage.classList.contains('show')) {
                errorMessage.style.opacity = '0.8';
            }
        }, 5000);
    </script>
</body>
</html>