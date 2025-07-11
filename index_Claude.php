<?php
// Database connection
function getDBConnection()
{
    $host = 'localhost';
    $dbname = 'u182463273_ozn';
    $username = 'u182463273_ozn';
    $password = 'K640dFQRk4^';

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        die("Connection failed: " . $e->getMessage());
    }
}

// Save file to database
function saveFileToDatabase($user_id, $file_name, $subject, $file_type, $original_file_path, $summary_file_path)
{
    $pdo = getDBConnection();

    $sql = "INSERT INTO user_files (user_id, file_name, subject, file_type, original_file_path, summary_file_path) 
            VALUES (?, ?, ?, ?, ?, ?)";

    $stmt = $pdo->prepare($sql);
    return $stmt->execute([$user_id, $file_name, $subject, $file_type, $original_file_path, $summary_file_path]);
}

// Get user files
function getUserFiles($user_id)
{
    $pdo = getDBConnection();

    $sql = "SELECT * FROM user_files WHERE user_id = ? ORDER BY created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Create summary text file
function createSummaryTextFile($summary_content, $original_filename, $user_id, $subject)
{
    $summary_dir = "uploads/user_$user_id/$subject/summaries/";

    if (!file_exists($summary_dir)) {
        mkdir($summary_dir, 0755, true);
    }

    $summary_filename = pathinfo($original_filename, PATHINFO_FILENAME) . '_summary.txt';
    $summary_path = $summary_dir . $summary_filename;

    file_put_contents($summary_path, $summary_content);

    return $summary_path;
}
