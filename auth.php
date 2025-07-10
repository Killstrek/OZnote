<?php
// auth.php - Authentication logic
require_once 'config.php';

function signup($email, $password, $confirm_password, $name, $surname)
{
    global $pdo;

    // Validation
    if (empty($email) || empty($password) || empty($confirm_password) || empty($name) || empty($surname)) {
        return ['success' => false, 'message' => 'All fields are required'];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Invalid email format'];
    }

    if (strlen($password) < 6) {
        return ['success' => false, 'message' => 'Password must be at least 6 characters'];
    }

    if ($password !== $confirm_password) {
        return ['success' => false, 'message' => 'Passwords do not match'];
    }

    // Check if email already exists in OZN table
    $stmt = $pdo->prepare("SELECT ID FROM OZN WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->rowCount() > 0) {
        return ['success' => false, 'message' => 'Email already exists'];
    }

    // Insert user into OZN table with plain text password (NOT RECOMMENDED)
    $stmt = $pdo->prepare("INSERT INTO OZN (email, password, username, surname, tel) VALUES (?, ?, ?, ?, '')");

    try {
        $stmt->execute([$email, $password, $name, $surname]);
        return ['success' => true, 'message' => 'Account created successfully'];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Registration failed'];
    }
}

function login($email, $password)
{
    global $pdo;

    if (empty($email) || empty($password)) {
        return ['success' => false, 'message' => 'Email and password are required'];
    }

    try {
        $stmt = $pdo->prepare("SELECT ID, email, password FROM OZN WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return ['success' => false, 'message' => 'No account found with this email address'];
        }

        // Debug: log the login attempt
        error_log("Login attempt for user ID: " . $user['ID'] . ", email: " . $email);

        // Direct password comparison (NOT RECOMMENDED)
        if ($password === $user['password']) {
            $_SESSION['user_id'] = $user['ID'];
            $_SESSION['user_email'] = $user['email'];

            error_log("Login successful for user ID: " . $user['ID']);

            // Redirect to dashboard after successful login
            header('Location: dashboard.php');
            exit();
        } else {
            error_log("Password verification failed for user ID: " . $user['ID']);
            return ['success' => false, 'message' => 'Invalid email or password. Please check your credentials and try again.'];
        }
    } catch (PDOException $e) {
        error_log("Login error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Login failed. Please try again later.'];
    }
}

function logout()
{
    session_destroy();
    header('Location: index.php');  // Redirect to index page instead of login
    exit();
}

function isLoggedIn()
{
    return isset($_SESSION['user_id']);
}
