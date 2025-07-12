<?php
require_once 'auth.php';

$message = '';
$message_type = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'signup') {
            $result = signup($_POST['email'], $_POST['password'], $_POST['confirm_password'], $_POST['name'], $_POST['surname']);
            $message = $result['message'];
            $message_type = $result['success'] ? 'success' : 'error';
        } elseif ($_POST['action'] === 'login') {
            // Login function handles redirect on success, but we need to catch failures
            $result = login($_POST['email'], $_POST['password']);
            // If we reach this point, login failed (successful login redirects and exits)
            if (isset($result) && !$result['success']) {
                $message = $result['message'];
                $message_type = 'error';
            } else {
                // This shouldn't happen, but just in case
                $message = 'Login failed. Please check your credentials.';
                $message_type = 'error';
            }
        }
    }
}

// Handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    logout();
}

// Redirect to dashboard if already logged in
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login & Signup - OZNOTE</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #8FBB99 0%, #B0FE76 50%, #81E979 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
        }

        /* Background pattern overlay */
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image:
                radial-gradient(circle at 25% 25%, rgba(86, 54, 53, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 75% 75%, rgba(89, 84, 74, 0.1) 0%, transparent 50%);
            pointer-events: none;
        }

        .container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 2.5rem;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(86, 54, 53, 0.1);
            width: 100%;
            max-width: 420px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
            z-index: 1;
        }

        .header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .header h1 {
            color: #563635;
            font-size: 2.2rem;
            margin-bottom: 0.5rem;
            font-weight: 700;
        }

        .header p {
            color: #59544A;
            font-size: 0.95rem;
            opacity: 0.8;
        }

        .form-toggle {
            display: flex;
            margin-bottom: 2rem;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #8FBB99;
            background: rgba(176, 254, 118, 0.1);
        }

        .toggle-btn {
            flex: 1;
            padding: 12px;
            background: transparent;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
            color: #563635;
        }

        .toggle-btn.active {
            background: #B0FE76;
            color: #563635;
            font-weight: 600;
        }

        .toggle-btn:hover:not(.active) {
            background: rgba(176, 254, 118, 0.2);
        }

        .form-container {
            position: relative;
        }

        .form {
            display: none;
        }

        .form.active {
            display: block;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #563635;
            font-size: 14px;
        }

        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #8FBB99;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.8);
            color: #563635;
        }

        .form-group input:focus {
            outline: none;
            border-color: #B0FE76;
            background: white;
            box-shadow: 0 0 0 3px rgba(176, 254, 118, 0.1);
        }

        .form-group input::placeholder {
            color: #8FBB99;
        }

        .form-row {
            display: flex;
            gap: 1rem;
        }

        .form-row .form-group {
            flex: 1;
        }

        .submit-btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #B0FE76 0%, #81E979 100%);
            color: #563635;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(176, 254, 118, 0.3);
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(176, 254, 118, 0.4);
            background: linear-gradient(135deg, #81E979 0%, #B0FE76 100%);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        .message {
            margin-top: 1rem;
            padding: 12px 16px;
            border-radius: 10px;
            text-align: center;
            font-size: 14px;
            font-weight: 500;
        }

        .success {
            background: rgba(129, 233, 121, 0.2);
            color: #563635;
            border: 1px solid #81E979;
        }

        .error {
            background: rgba(220, 53, 69, 0.1);
            color: #c82333;
            border: 1px solid rgba(220, 53, 69, 0.3);
        }

        .footer {
            text-align: center;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(143, 187, 153, 0.3);
            color: #59544A;
            font-size: 0.85rem;
        }

        /* Back to home link */
        .back-home {
            position: absolute;
            top: 20px;
            left: 20px;
            color: #563635;
            text-decoration: none;
            font-weight: 500;
            padding: 8px 16px;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 20px;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .back-home:hover {
            background: rgba(176, 254, 118, 0.9);
            transform: translateY(-2px);
        }

        /* Responsive design */
        @media (max-width: 480px) {
            .container {
                margin: 20px;
                padding: 2rem;
            }

            .header h1 {
                font-size: 1.8rem;
            }

            .form-row {
                flex-direction: column;
                gap: 0;
            }
        }

        /* Debug info styling */
        .debug-info {
            margin-top: 1rem;
            padding: 12px;
            background: rgba(176, 254, 118, 0.1);
            border-radius: 8px;
            font-size: 12px;
            color: #59544A;
            border: 1px solid rgba(143, 187, 153, 0.3);
        }

        .debug-info strong {
            color: #563635;
        }
    </style>
</head>

<body>
    <!-- Back to home button -->
    <a href="index.php" class="back-home">← Back to Home</a>

    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>📚 OZNOTE</h1>
            <p>AI-powered document organization for students</p>
        </div>

        <!-- Form Toggle -->
        <div class="form-toggle">
            <button class="toggle-btn active" onclick="showForm('login')">Login</button>
            <button class="toggle-btn" onclick="showForm('signup')">Sign Up</button>
        </div>

        <div class="form-container">
            <!-- Login Form -->
            <form id="loginForm" class="form active" method="POST">
                <input type="hidden" name="action" value="login">

                <div class="form-group">
                    <label for="login_email">Email:</label>
                    <input type="email" id="login_email" name="email" placeholder="Enter your email" required>
                </div>

                <div class="form-group">
                    <label for="login_password">Password:</label>
                    <input type="password" id="login_password" name="password" placeholder="Enter your password" required>
                </div>

                <button type="submit" class="submit-btn">Login</button>
            </form>

            <!-- Signup Form -->
            <form id="signupForm" class="form" method="POST">
                <input type="hidden" name="action" value="signup">

                <div class="form-row">
                    <div class="form-group">
                        <label for="signup_name">Name:</label>
                        <input type="text" id="signup_name" name="name" placeholder="First name" required>
                    </div>

                    <div class="form-group">
                        <label for="signup_surname">Surname:</label>
                        <input type="text" id="signup_surname" name="surname" placeholder="Last name" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="signup_email">Email:</label>
                    <input type="email" id="signup_email" name="email" placeholder="Enter your email" required>
                </div>

                <div class="form-group">
                    <label for="signup_password">Password:</label>
                    <input type="password" id="signup_password" name="password" placeholder="Create a password" required>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password:</label>
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm your password" required>
                </div>

                <button type="submit" class="submit-btn">Sign Up</button>
            </form>
        </div>

        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Debug info (remove in production) -->
        <?php if (isset($_POST['action']) && $_POST['action'] === 'login'): ?>
            <div class="debug-info">
                <strong>Debug Info:</strong><br>
                Email: <?php echo htmlspecialchars($_POST['email'] ?? 'none'); ?><br>
                Action: <?php echo htmlspecialchars($_POST['action']); ?><br>
                <?php if (isset($result)): ?>
                    Result: <?php echo $result ? 'Array returned' : 'No result'; ?><br>
                    <?php if (is_array($result)): ?>
                        Success: <?php echo $result['success'] ? 'true' : 'false'; ?><br>
                        Message: <?php echo htmlspecialchars($result['message'] ?? 'no message'); ?><br>
                    <?php endif; ?>
                <?php else: ?>
                    Result: No result variable set<br>
                <?php endif; ?>
                Message variable: <?php echo htmlspecialchars($message); ?><br>
                Message type: <?php echo htmlspecialchars($message_type); ?>
            </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="footer">
            <p>Secure login powered by modern encryption</p>
        </div>
    </div>

    <script>
        function showForm(formType) {
            // Hide all forms
            document.querySelectorAll('.form').forEach(form => {
                form.classList.remove('active');
            });

            // Remove active class from all buttons
            document.querySelectorAll('.toggle-btn').forEach(btn => {
                btn.classList.remove('active');
            });

            // Show selected form and activate button
            if (formType === 'login') {
                document.getElementById('loginForm').classList.add('active');
                document.querySelector('.toggle-btn:first-child').classList.add('active');
            } else {
                document.getElementById('signupForm').classList.add('active');
                document.querySelector('.toggle-btn:last-child').classList.add('active');
            }
        }

        // Add some interactive effects
        document.querySelectorAll('.form-group input').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.style.transform = 'translateY(-2px)';
            });

            input.addEventListener('blur', function() {
                this.parentElement.style.transform = 'translateY(0)';
            });
        });

        // Add form validation feedback
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('.submit-btn');
                submitBtn.innerHTML = 'Processing...';
                submitBtn.style.opacity = '0.7';
            });
        });

        // Password confirmation validation
        const signupForm = document.getElementById('signupForm');
        const password = document.getElementById('signup_password');
        const confirmPassword = document.getElementById('confirm_password');

        function validatePassword() {
            if (password.value !== confirmPassword.value) {
                confirmPassword.setCustomValidity("Passwords don't match");
            } else {
                confirmPassword.setCustomValidity('');
            }
        }

        password.addEventListener('change', validatePassword);
        confirmPassword.addEventListener('keyup', validatePassword);
    </script>
</body>

</html>
