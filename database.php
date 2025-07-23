<?php
// Database functions with UTF-8 support and Thailand timezone (UTC+7)
// Set default timezone to Thailand
date_default_timezone_set('Asia/Bangkok');

// UPDATED: Database connection with UTF-8 support and Thailand timezone and Thailand timezone
function getDBConnection()
{
    $host = 'localhost';
    $dbname = 'u182463273_ozn';
    $username = 'u182463273_ozn';
    $password = 'K640dFQRk4^';

    try {
        // FIXED: Add charset=utf8mb4 
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // ADDED: Ensure UTF-8 communication
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

        // ADDED: Set Thailand timezone (UTC+7)
        $pdo->exec("SET time_zone = '+07:00'");

        return $pdo;
    } catch (PDOException $e) {
        die("Connection failed: " . $e->getMessage());
    }
}

// FIXED: Save file to database with proper timestamp and UTF-8 handling
function saveFileToDatabase($user_id, $file_name, $subject, $file_type, $original_file_path, $summary_file_path, $language = 'en')
{
    $pdo = getDBConnection();

    // Check if language column exists, if not add it
    try {
        $check_column = $pdo->query("SHOW COLUMNS FROM user_files LIKE 'language'");
        if ($check_column->rowCount() == 0) {
            $pdo->exec("ALTER TABLE user_files ADD COLUMN language VARCHAR(5) DEFAULT 'en' AFTER file_type");
            error_log("Added language column to user_files table");
        }
    } catch (Exception $e) {
        error_log("Error checking/adding language column: " . $e->getMessage());
    }

    // FIXED: Proper SQL with current timestamp
    $sql = "INSERT INTO user_files (user_id, file_name, subject, file_type, language, original_file_path, summary_file_path, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

    $stmt = $pdo->prepare($sql);
    // FIXED: Correct number of parameters (removed extra comma and NOW() is handled in SQL)
    return $stmt->execute([$user_id, $file_name, $subject, $file_type, $language, $original_file_path, $summary_file_path]);
}

// UPDATED: Get user files with proper UTF-8 handling and safe JSON encoding
function getUserFiles($user_id)
{
    $pdo = getDBConnection();

    // Check if language column exists, if not add it with default value
    try {
        $check_column = $pdo->query("SHOW COLUMNS FROM user_files LIKE 'language'");
        if ($check_column->rowCount() == 0) {
            $pdo->exec("ALTER TABLE user_files ADD COLUMN language VARCHAR(5) DEFAULT 'en' AFTER file_type");
            error_log("Added language column to user_files table");
        }
    } catch (Exception $e) {
        error_log("Error checking/adding language column: " . $e->getMessage());
    }

    $sql = "SELECT *, DATE(created_at) as date FROM user_files WHERE user_id = ? ORDER BY created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);

    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formatted_files = [];
    foreach ($files as $file) {
        // FIXED: Safe file reading with UTF-8 support
        $summary_content = 'No summary available';
        $full_summary = 'No summary available';

        if (!empty($file['summary_file_path']) && file_exists($file['summary_file_path'])) {
            $file_contents = file_get_contents($file['summary_file_path']);
            if ($file_contents !== false) {
                // FIXED: Ensure UTF-8 encoding and safe substring
                $full_summary = mb_convert_encoding($file_contents, 'UTF-8', 'auto');
                $summary_content = mb_substr($full_summary, 0, 200, 'UTF-8') . '...';
            }
        }

        // Get language info (default to English if not set)
        $file_language = isset($file['language']) ? $file['language'] : 'en';

        // Set appropriate "No summary" message based on language
        if ($summary_content === 'No summary available') {
            $summary_content = ($file_language === 'th') ? 'ไม่มีข้อมูลสรุป' : 'No summary available';
            $full_summary = $summary_content;
        }

        // CRITICAL FIX: Clean all text data for safe JSON encoding
        $formatted_files[] = [
            'id' => (int)$file['id'],
            'name' => cleanForJson($file['file_name']),
            'subject' => cleanForJson($file['subject']),
            'date' => $file['date'],
            'summary' => cleanForJson($summary_content),
            'full_summary' => cleanForJson($full_summary),
            'language' => $file_language,
            'status' => 'completed',
            'debug_info' => '',
            'extracted_text' => '',
            'summary_file_path' => $file['summary_file_path'],
            'original_file_path' => $file['original_file_path']
        ];
    }

    return $formatted_files;
}

// NEW: Helper function to clean text for safe JSON encoding
function cleanForJson($text)
{
    if (!$text) return '';

    // Convert to UTF-8 if needed
    $clean = mb_convert_encoding($text, 'UTF-8', 'auto');

    // Remove control characters that break JSON
    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $clean);

    // Remove or replace problematic quotes and newlines
    $clean = str_replace(["\r\n", "\r", "\n"], ' ', $clean);
    $clean = str_replace(['"', "'"], ['\"', "\'"], $clean);

    // Trim excessive whitespace
    $clean = preg_replace('/\s+/', ' ', trim($clean));

    return $clean;
}

// UPDATED: Create enhanced summary text file with Thailand timezone
function createSummaryTextFile($summary_content, $original_filename, $user_id, $subject, $language = 'en', $alt_summary = '')
{
    $summary_dir = "uploads/user_$user_id/$subject/summaries/";

    if (!file_exists($summary_dir)) {
        mkdir($summary_dir, 0755, true);
    }

    $summary_filename = pathinfo($original_filename, PATHINFO_FILENAME) . '_summary.txt';
    $summary_path = $summary_dir . $summary_filename;

    // Set Thailand timezone for file timestamps
    date_default_timezone_set('Asia/Bangkok');

    // Create enhanced content with better formatting
    $file_content = "📄 DOCUMENT ANALYSIS SUMMARY\n";
    $file_content .= str_repeat("=", 50) . "\n\n";

    // Add document info
    $file_content .= "📋 Document: " . $original_filename . "\n";
    $file_content .= "📚 Subject: " . $subject . "\n";
    $file_content .= "🌐 Language: " . ($language === 'th' ? 'Thai (ภาษาไทย)' : 'English') . "\n";
    $file_content .= "📅 Generated: " . date('Y-m-d H:i:s T') . " (Thailand Time)\n\n";

    $file_content .= str_repeat("-", 50) . "\n\n";

    // Add main summary with better formatting
    $file_content .= "🤖 AI ANALYSIS:\n\n";

    // Clean up and format the summary content
    $formatted_summary = $summary_content;

    // Replace bullet points with better formatting
    $formatted_summary = str_replace('•', '  •', $formatted_summary);

    // Add proper spacing around sections
    $formatted_summary = preg_replace('/\[([^\]]+)\]:/', "\n📌 $1:", $formatted_summary);

    $file_content .= $formatted_summary . "\n\n";

    // Add alternative language summary if available
    if (!empty($alt_summary)) {
        $separator = "\n" . str_repeat("-", 50) . "\n\n";
        if ($language === 'th') {
            $file_content .= $separator . "🇺🇸 ENGLISH SUMMARY:\n\n" . $alt_summary . "\n\n";
        } else {
            $file_content .= $separator . "🇹🇭 สรุปภาษาไทย:\n\n" . $alt_summary . "\n\n";
        }
    }

    // Add metadata footer
    $file_content .= str_repeat("=", 50) . "\n";
    $file_content .= "📊 TECHNICAL INFO:\n";
    $file_content .= "  • AI Analysis: Claude (Anthropic)\n";
    $file_content .= "  • Processing Language: " . ($language === 'th' ? 'Thai (ภาษาไทย)' : 'English') . "\n";
    $file_content .= "  • Generated Time: " . date('l, F j, Y \a\t g:i A T') . "\n";
    $file_content .= str_repeat("=", 50) . "\n";

    // FIXED: Write file with explicit UTF-8 encoding
    if (file_put_contents($summary_path, $file_content, LOCK_EX) === false) {
        error_log("Failed to write summary file: $summary_path");
        return false;
    }

    return $summary_path;
}

// Helper function to format file sizes
function formatFileSize($bytes, $precision = 2)
{
    $units = array('B', 'KB', 'MB', 'GB', 'TB');

    for ($i = 0; $bytes > 1024; $i++) {
        $bytes /= 1024;
    }

    return round($bytes, $precision) . ' ' . $units[$i];
}

// Get file content with UTF-8 support
function getFileContent($file_path)
{
    $content = file_get_contents($file_path);
    if ($content !== false) {
        return mb_convert_encoding($content, 'UTF-8', 'auto');
    }
    return false;
}

// Get file type
function getFileType($file_path)
{
    $file_info = new finfo(FILEINFO_MIME_TYPE);
    return $file_info->file($file_path);
}

// Get file extension
function getFileExtension($file_path)
{
    return pathinfo($file_path, PATHINFO_EXTENSION);
}

// Get file size
function getFileSize($file_path)
{
    return filesize($file_path);
}

// Get file name
function getFileName($file_path)
{
    return pathinfo($file_path, PATHINFO_FILENAME);
}

// Delete user file
function deleteUserFile($file_id, $user_id)
{
    $pdo = getDBConnection();
    $sql = "SELECT * FROM user_files WHERE id = ? AND user_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$file_id, $user_id]);
    $file = $stmt->fetch();

    if ($file) {
        if (file_exists($file['original_file_path'])) {
            unlink($file['original_file_path']);
        }
        if (!empty($file['summary_file_path']) && file_exists($file['summary_file_path'])) {
            unlink($file['summary_file_path']);
        }

        $delete_sql = "DELETE FROM user_files WHERE id = ? AND user_id = ?";
        $delete_stmt = $pdo->prepare($delete_sql);
        return $delete_stmt->execute([$file_id, $user_id]);
    }

    return false;
}

// UPDATED: Serve original file with better headers and UTF-8 support
function serveOriginalFile($file_id, $user_id)
{
    $pdo = getDBConnection();
    $sql = "SELECT * FROM user_files WHERE id = ? AND user_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$file_id, $user_id]);
    $file = $stmt->fetch();

    if (!$file) {
        header('HTTP/1.0 404 Not Found');
        echo 'File not found';
        exit;
    }

    if (!file_exists($file['original_file_path'])) {
        header('HTTP/1.0 404 Not Found');
        echo 'File not found on disk';
        exit;
    }

    $file_path = $file['original_file_path'];
    $file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    $file_size = filesize($file_path);

    // Clear any previous output
    if (ob_get_level()) {
        ob_end_clean();
    }

    // Set appropriate headers based on file type
    if ($file_extension === 'pdf') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($file['file_name']) . '"');
    } elseif (in_array($file_extension, ['jpg', 'jpeg'])) {
        header('Content-Type: image/jpeg');
        header('Content-Disposition: inline; filename="' . basename($file['file_name']) . '"');
    } elseif ($file_extension === 'png') {
        header('Content-Type: image/png');
        header('Content-Disposition: inline; filename="' . basename($file['file_name']) . '"');
    } elseif ($file_extension === 'webp') {
        header('Content-Type: image/webp');
        header('Content-Disposition: inline; filename="' . basename($file['file_name']) . '"');
    } elseif ($file_extension === 'gif') {
        header('Content-Type: image/gif');
        header('Content-Disposition: inline; filename="' . basename($file['file_name']) . '"');
    } else {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file['file_name']) . '"');
    }

    // Set content length and cache headers
    header('Content-Length: ' . $file_size);
    header('Cache-Control: private, max-age=3600');

    // For large files, read in chunks
    if ($file_size > 10 * 1024 * 1024) { // > 10MB
        $fp = fopen($file_path, 'rb');
        if ($fp) {
            while (!feof($fp)) {
                echo fread($fp, 8192); // 8KB chunks
                if (connection_aborted()) break;
                flush();
            }
            fclose($fp);
        }
    } else {
        readfile($file_path);
    }

    exit;
}

// UPDATED: Serve summary file with UTF-8 support
function serveSummaryFile($file_id, $user_id)
{
    $pdo = getDBConnection();
    $sql = "SELECT * FROM user_files WHERE id = ? AND user_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$file_id, $user_id]);
    $file = $stmt->fetch();

    if (!$file || !file_exists($file['summary_file_path'])) {
        header('HTTP/1.0 404 Not Found');
        echo 'Summary file not found';
        return;
    }

    // FIXED: Set UTF-8 headers for text files
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . pathinfo($file['file_name'], PATHINFO_FILENAME) . '_summary.txt"');
    header('Content-Length: ' . filesize($file['summary_file_path']));

    readfile($file['summary_file_path']);
}
