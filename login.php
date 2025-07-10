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
    <title>Login & Signup - StudyOrganizer</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .container {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
        }

        .header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .header h1 {
            color: #667eea;
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .header p {
            color: #666;
            font-size: 0.9rem;
        }

        .form-toggle {
            display: flex;
            margin-bottom: 2rem;
            border-radius: 5px;
            overflow: hidden;
            border: 1px solid #e0e0e0;
        }

        .toggle-btn {
            flex: 1;
            padding: 10px;
            background: #f5f5f5;
            border: none;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .toggle-btn.active {
            background: #667eea;
            color: white;
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
            margin-bottom: 5px;
            font-weight: 500;
            color: #333;
        }

        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
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
            padding: 12px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .submit-btn:hover {
            background: #5a67d8;
        }

        .message {
            margin-top: 1rem;
            padding: 10px;
            border-radius: 5px;
            text-align: center;
            font-size: 14px;
        }

        .success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .footer {
            text-align: center;
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid #eee;
            color: #666;
            font-size: 0.8rem;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>📚 StudyOrganizer</h1>
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
                    <input type="email" id="login_email" name="email" required>
                </div>

                <div class="form-group">
                    <label for="login_password">Password:</label>
                    <input type="password" id="login_password" name="password" required>
                </div>

                <button type="submit" class="submit-btn">Login</button>
            </form>

            <!-- Signup Form -->
            <form id="signupForm" class="form" method="POST">
                <input type="hidden" name="action" value="signup">

                <div class="form-row">
                    <div class="form-group">
                        <label for="signup_name">Name:</label>
                        <input type="text" id="signup_name" name="name" required>
                    </div>

                    <div class="form-group">
                        <label for="signup_surname">Surname:</label>
                        <input type="text" id="signup_surname" name="surname" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="signup_email">Email:</label>
                    <input type="email" id="signup_email" name="email" required>
                </div>

                <div class="form-group">
                    <label for="signup_password">Password:</label>
                    <input type="password" id="signup_password" name="password" required>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password:</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
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
            <div style="margin-top: 1rem; padding: 10px; background: #f0f8ff; border-radius: 5px; font-size: 12px; color: #666;">
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
    </script>
</body>

</html>
