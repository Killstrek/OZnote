<?php
session_start();

// Include authentication functions
require_once 'auth.php'; // This should contain your login functions

// AUTHENTICATION CHECK - Redirect if not logged in
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

// Handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    logout(); // This will redirect to login.php
}

// Configuration
define('UPLOAD_DIR', 'uploads/');
define('CLAUDE_API_KEY', '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'); // Add your Claude API key
define('OCR_SPACE_API_KEY', 'K83046822188957'); // Add your OCR.space API key

// Database connection functions
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

// Get user files from database
function getUserFiles($user_id)
{
    $pdo = getDBConnection();

    $sql = "SELECT *, DATE(created_at) as date FROM user_files WHERE user_id = ? ORDER BY created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);

    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert to the format expected by the frontend
    $formatted_files = [];
    foreach ($files as $index => $file) {
        $formatted_files[] = [
            'id' => $file['id'],
            'name' => $file['file_name'],
            'subject' => $file['subject'],
            'date' => $file['date'],
            'summary' => 'AI-analyzed document', // You can store this in DB too
            'status' => 'completed', // You can store this in DB too
            'debug_info' => '',
            'extracted_text' => '' // You can store this in DB too
        ];
    }

    return $formatted_files;
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

// Create user-specific folder structure
function createUserFolderStructure($user_id)
{
    $user_folder = UPLOAD_DIR . 'user_' . $user_id . '/';
    $subject_folders = ['Physics', 'Biology', 'Chemistry', 'Mathematics', 'Others'];

    try {
        // Create main user folder if it doesn't exist
        if (!file_exists($user_folder)) {
            if (!mkdir($user_folder, 0755, true)) {
                error_log("Failed to create user folder: " . $user_folder);
                return false;
            }
            error_log("Created user folder: " . $user_folder);
        }

        // Create subject subfolders
        foreach ($subject_folders as $subject) {
            $subject_folder = $user_folder . $subject . '/';
            if (!file_exists($subject_folder)) {
                if (!mkdir($subject_folder, 0755, true)) {
                    error_log("Failed to create subject folder: " . $subject_folder);
                    return false;
                }
                error_log("Created subject folder: " . $subject_folder);
            }
        }

        return true;
    } catch (Exception $e) {
        error_log("Error creating user folder structure: " . $e->getMessage());
        return false;
    }
}

// Get user-specific upload path
function getUserUploadPath($user_id, $subject = 'Others')
{
    // Ensure valid subject
    $valid_subjects = ['Physics', 'Biology', 'Chemistry', 'Mathematics', 'Others'];
    if (!in_array($subject, $valid_subjects)) {
        $subject = 'Others';
    }

    return UPLOAD_DIR . 'user_' . $user_id . '/' . $subject . '/';
}

// Create upload directory if it doesn't exist
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

// Create user folder structure when user accesses the system
if (isset($_SESSION['user_id'])) {
    createUserFolderStructure($_SESSION['user_id']);
}

// Initialize uploaded files in session if not exists


// OCR Function using OCR.space API with detailed debugging
function performOCR($file_path)
{
    $api_key = OCR_SPACE_API_KEY;

    // Check if API key is set
    if ($api_key === 'AIzaSyCu68wHXmbZUFaO-ZNdcA66dmSfzQrznD8' || empty($api_key)) {
        error_log("OCR.space API key not configured");
        return ['error' => 'OCR.space API key not configured', 'text' => false];
    }

    // Check if file exists
    if (!file_exists($file_path)) {
        error_log("File not found: " . $file_path);
        return ['error' => 'File not found', 'text' => false];
    }

    $url = "https://api.ocr.space/parse/image";

    // Prepare the file for upload
    $post_data = [
        'apikey' => $api_key,
        'language' => 'eng',
        'isOverlayRequired' => 'false',
        'file' => new CURLFile($file_path),
        'detectOrientation' => 'true',
        'isTable' => 'true',
        'OCREngine' => '2'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    error_log("OCR API Response Code: " . $http_code);
    error_log("OCR API Response: " . substr($response, 0, 500));

    if ($curl_error) {
        error_log("CURL Error: " . $curl_error);
        return ['error' => 'CURL Error: ' . $curl_error, 'text' => false];
    }

    if ($http_code === 200) {
        $result = json_decode($response, true);

        if (isset($result['ParsedResults']) && !empty($result['ParsedResults'])) {
            $extracted_text = '';
            foreach ($result['ParsedResults'] as $parsed_result) {
                if (isset($parsed_result['ParsedText'])) {
                    $extracted_text .= $parsed_result['ParsedText'] . "\n";
                }
            }
            $final_text = trim($extracted_text);
            return ['error' => null, 'text' => $final_text, 'debug' => 'OCR successful'];
        } else if (isset($result['ErrorMessage'])) {
            error_log("OCR.space Error: " . $result['ErrorMessage']);
            return ['error' => 'OCR.space Error: ' . $result['ErrorMessage'], 'text' => false];
        } else {
            return ['error' => 'No text found in document', 'text' => false];
        }
    } else {
        return ['error' => 'HTTP Error: ' . $http_code, 'text' => false];
    }
}

// Extract text from PDF (simple implementation)
function extractTextFromPDF($file_path)
{
    $content = shell_exec("pdftotext '$file_path' -");
    return $content ? trim($content) : false;
}

function analyzeWithClaude($text_content, $filename)
{
    $api_key = CLAUDE_API_KEY;

    if ($api_key === '' || empty($api_key)) {
        error_log("Claude API key not configured");
        return [
            'subject' => 'Others',
            'summary' => 'Claude API key not configured',
            'debug' => 'API key missing'
        ];
    }

    if (empty(trim($text_content))) {
        return [
            'subject' => 'Others',
            'summary' => 'No text content to analyze',
            'debug' => 'Empty text content'
        ];
    }

    $url = "https://api.anthropic.com/v1/messages";

    $prompt = "Please analyze the following educational content and provide:
1. Subject category (choose from: Physics, Biology, Chemistry, Mathematics, Others)
2. A concise summary in 1-2 sentences describing the main topics covered

Content from file '$filename':
$text_content

Respond in this exact JSON format:
{
    \"subject\": \"[Subject Name]\",
    \"summary\": \"[Your summary here]\"
}";

    $request_data = [
        'model' => 'claude-3-5-sonnet-20241022',
        'max_tokens' => 300,
        'messages' => [
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ]
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . $api_key,
        'anthropic-version: 2023-06-01'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    error_log("Claude API Response Code: " . $http_code);
    error_log("Claude API Response: " . substr($response, 0, 500));

    if ($curl_error) {
        error_log("CURL Error for Claude: " . $curl_error);
        return [
            'subject' => 'Others',
            'summary' => 'Network error connecting to Claude API',
            'debug' => 'CURL Error: ' . $curl_error
        ];
    }

    if ($http_code === 200) {
        $result = json_decode($response, true);
        if (isset($result['content'][0]['text'])) {
            $claude_response = $result['content'][0]['text'];

            preg_match('/\{[^}]+\}/', $claude_response, $matches);
            if ($matches) {
                $analysis = json_decode($matches[0], true);
                if ($analysis && isset($analysis['subject']) && isset($analysis['summary'])) {
                    return [
                        'subject' => $analysis['subject'],
                        'summary' => $analysis['summary'],
                        'debug' => 'Claude analysis successful'
                    ];
                }
            }

            return [
                'subject' => 'Others',
                'summary' => 'Document analyzed but format parsing failed',
                'debug' => 'JSON parsing failed from: ' . substr($claude_response, 0, 200)
            ];
        }
    } else {
        $error_response = json_decode($response, true);
        $error_message = isset($error_response['error']['message']) ? $error_response['error']['message'] : 'Unknown API error';

        return [
            'subject' => 'Others',
            'summary' => 'Claude API error: ' . $error_message,
            'debug' => 'HTTP ' . $http_code . ': ' . $error_message
        ];
    }

    return [
        'subject' => 'Others',
        'summary' => 'Document uploaded successfully but AI analysis failed',
        'debug' => 'All analysis methods failed'
    ];
}

// Process uploaded file with comprehensive debugging and organized storage
function processUploadedFile($uploaded_file)
{
    $file_extension = strtolower(pathinfo($uploaded_file['name'], PATHINFO_EXTENSION));
    $user_id = $_SESSION['user_id'];

    // Clean filename to prevent issues
    $clean_filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $uploaded_file['name']);
    $timestamp = time();
    $final_filename = $timestamp . '_' . $clean_filename;

    $debug_info = [];
    $debug_info[] = "File extension: " . $file_extension;
    $debug_info[] = "Original filename: " . $uploaded_file['name'];
    $debug_info[] = "Clean filename: " . $final_filename;
    $debug_info[] = "User ID: " . $user_id;

    // First, create a temporary upload to process
    $temp_upload_path = UPLOAD_DIR . 'temp_' . $final_filename;

    if (move_uploaded_file($uploaded_file['tmp_name'], $temp_upload_path)) {
        $debug_info[] = "File moved to temporary location successfully";
        $extracted_text = '';
        $ocr_debug = '';

        if ($file_extension === 'pdf') {
            $debug_info[] = "Processing PDF file";
            $extracted_text = extractTextFromPDF($temp_upload_path);

            if ($extracted_text && strlen(trim($extracted_text)) > 10) {
                $debug_info[] = "PDF text extraction successful";
            } else {
                $debug_info[] = "PDF text extraction failed, trying OCR";
                $ocr_result = performOCR($temp_upload_path);
                $extracted_text = $ocr_result['text'];
                $ocr_debug = $ocr_result['error'] ?? $ocr_result['debug'] ?? '';
            }
        } elseif (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff'])) {
            $debug_info[] = "Processing image file";
            $ocr_result = performOCR($temp_upload_path);
            $extracted_text = $ocr_result['text'];
            $ocr_debug = $ocr_result['error'] ?? $ocr_result['debug'] ?? '';
        } else {
            $debug_info[] = "Unsupported file type";
            // Clean up temp file
            if (file_exists($temp_upload_path)) {
                unlink($temp_upload_path);
            }
            return [
                'subject' => 'Others',
                'summary' => 'Unsupported file type: ' . $file_extension,
                'status' => 'error',
                'debug_info' => implode('; ', $debug_info)
            ];
        }

        $debug_info[] = "Extracted text length: " . strlen($extracted_text);
        $debug_info[] = "OCR debug: " . $ocr_debug;

        if ($extracted_text && strlen(trim($extracted_text)) > 10) {
            $debug_info[] = "Text extraction successful, analyzing with Claude";

            $analysis = analyzeWithClaude($extracted_text, $uploaded_file['name']);
            $debug_info[] = "Claude debug: " . ($analysis['debug'] ?? 'no debug info');

            $subject = $analysis['subject'];

            // Now move file to the correct subject folder
            $subject_upload_path = getUserUploadPath($user_id, $subject);

            // Ensure the folder exists
            if (!file_exists($subject_upload_path)) {
                mkdir($subject_upload_path, 0755, true);
            }

            $final_upload_path = $subject_upload_path . $final_filename;

            // Move from temp to final location
            if (rename($temp_upload_path, $final_upload_path)) {
                $debug_info[] = "File moved to final location: " . $final_upload_path;

                // Create summary text file
                $summary_file_path = createSummaryTextFile(
                    $analysis['summary'],
                    $uploaded_file['name'],
                    $user_id,
                    $subject
                );

                // Save to database
                saveFileToDatabase(
                    $user_id,
                    $uploaded_file['name'],
                    $subject,
                    $file_extension,
                    $final_upload_path,
                    $summary_file_path
                );

                return [
                    'subject' => $subject,
                    'summary' => $analysis['summary'],
                    'status' => 'completed',
                    'extracted_text' => substr($extracted_text, 0, 1000),
                    'debug_info' => implode('; ', $debug_info),
                    'file_path' => $final_upload_path
                ];
            } else {
                $debug_info[] = "Failed to move file to final location";
                // Clean up temp file
                if (file_exists($temp_upload_path)) {
                    unlink($temp_upload_path);
                }
                return [
                    'subject' => 'Others',
                    'summary' => 'File processing completed but failed to organize',
                    'status' => 'error',
                    'debug_info' => implode('; ', $debug_info)
                ];
            }
        } else {
            $debug_info[] = "Text extraction failed or insufficient content";

            // Still save file to Others folder even if analysis fails
            $subject_upload_path = getUserUploadPath($user_id, 'Others');
            if (!file_exists($subject_upload_path)) {
                mkdir($subject_upload_path, 0755, true);
            }

            $final_upload_path = $subject_upload_path . $final_filename;

            if (rename($temp_upload_path, $final_upload_path)) {
                $debug_info[] = "File saved to Others folder despite analysis failure";

                // Still save to database even if analysis failed
                saveFileToDatabase(
                    $user_id,
                    $uploaded_file['name'],
                    'Others',
                    $file_extension,
                    $final_upload_path,
                    ''
                );
            } else {
                // Clean up temp file
                if (file_exists($temp_upload_path)) {
                    unlink($temp_upload_path);
                }
            }

            return [
                'subject' => 'Others',
                'summary' => 'Unable to extract readable text from this file. OCR debug: ' . $ocr_debug,
                'status' => 'error',
                'extracted_text' => '',
                'debug_info' => implode('; ', $debug_info)
            ];
        }
    } else {
        $debug_info[] = "File move to temporary location failed";
        return [
            'subject' => 'Others',
            'summary' => 'Failed to save uploaded file',
            'status' => 'error',
            'debug_info' => implode('; ', $debug_info)
        ];
    }
}


// Handle file upload with detailed debugging
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['uploaded_file'])) {
    $uploaded_file = $_FILES['uploaded_file'];

    if ($uploaded_file['error'] === UPLOAD_ERR_OK) {
        $processing_result = processUploadedFile($uploaded_file);

        // REDIRECT after successful upload to prevent re-submission
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
        exit();
    } else {
        $error_messages = [
            UPLOAD_ERR_INI_SIZE => 'File too large (exceeds upload_max_filesize)',
            UPLOAD_ERR_FORM_SIZE => 'File too large (exceeds MAX_FILE_SIZE)',
            UPLOAD_ERR_PARTIAL => 'File upload was interrupted',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
        ];

        $error_message = $error_messages[$uploaded_file['error']] ?? 'Unknown upload error';

        // REDIRECT after error to show error message
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
        exit();
    }
}

// Get current tab and selected subject
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';
$selected_subject = isset($_GET['subject']) ? $_GET['subject'] : null;

// Get user files from database instead of session
$user_files = getUserFiles($_SESSION['user_id']);

// Define subjects with their counts
$subjects = [
    'Physics' => ['name' => 'Physics', 'color' => 'bg-blue-500', 'count' => 0],
    'Biology' => ['name' => 'Biology', 'color' => 'bg-green-500', 'count' => 0],
    'Chemistry' => ['name' => 'Chemistry', 'color' => 'bg-purple-500', 'count' => 0],
    'Mathematics' => ['name' => 'Mathematics', 'color' => 'bg-orange-500', 'count' => 0],
    'Others' => ['name' => 'Others', 'color' => 'bg-gray-500', 'count' => 0]
];

// Count files by subject from database
foreach ($user_files as $file) {
    $fileSubject = isset($file['subject']) ? trim($file['subject']) : 'Others';

    // Ensure the subject exists in our predefined list
    if (array_key_exists($fileSubject, $subjects)) {
        $subjects[$fileSubject]['count']++;
    } else {
        // If subject doesn't exist, count it as Others
        $subjects['Others']['count']++;
    }
}

// Convert back to indexed array for easier iteration
$subjects = array_values($subjects);

// Get recent files (first 5 for desktop)
$recent_files = array_slice($user_files, 0, 5);

// Filter files by subject if selected
$filtered_files = $user_files;
if ($selected_subject && !empty($selected_subject)) {
    $filtered_files = array_filter($user_files, function ($file) use ($selected_subject) {
        $fileSubject = isset($file['subject']) ? trim($file['subject']) : 'Others';
        return $fileSubject === $selected_subject;
    });
    // Reset array keys after filtering
    $filtered_files = array_values($filtered_files);
}

function getSubjectIcon($subject)
{
    $icons = [
        'Physics' => '⚛️',
        'Biology' => '🔬',
        'Chemistry' => '🧪',
        'Mathematics' => '🔢',
        'Others' => '📄'
    ];
    return $icons[$subject] ?? '📄';
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StudyOrganizer</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Custom responsive breakpoints and scale adjustments */
        :root {
            --scale-factor: 1;
            --sidebar-width: 16rem;
            --content-padding: 2rem;
            --card-padding: 1.5rem;
            --font-scale: 1;
        }

        /* Scale adjustments for different screen sizes */
        @media (max-width: 640px) {
            :root {
                --scale-factor: 0.9;
                --sidebar-width: 100%;
                --content-padding: 1rem;
                --card-padding: 1rem;
                --font-scale: 0.95;
            }
        }

        @media (min-width: 641px) and (max-width: 1024px) {
            :root {
                --scale-factor: 0.95;
                --sidebar-width: 14rem;
                --content-padding: 1.5rem;
                --card-padding: 1.25rem;
                --font-scale: 0.98;
            }
        }

        @media (min-width: 1025px) and (max-width: 1536px) {
            :root {
                --scale-factor: 1;
                --sidebar-width: 16rem;
                --content-padding: 2rem;
                --card-padding: 1.5rem;
                --font-scale: 1;
            }
        }

        @media (min-width: 1537px) {
            :root {
                --scale-factor: 1.1;
                --sidebar-width: 18rem;
                --content-padding: 2.5rem;
                --card-padding: 2rem;
                --font-scale: 1.05;
            }
        }

        /* Apply scale factor to elements */
        .scalable {
            transform: scale(var(--scale-factor));
            transform-origin: top left;
        }

        .sidebar {
            width: var(--sidebar-width);
        }

        .content-padding {
            padding: var(--content-padding);
        }

        .card-padding {
            padding: var(--card-padding);
        }

        /* Responsive font scaling */
        body {
            font-size: calc(1rem * var(--font-scale));
        }

        .bg-blue-500 {
            background-color: #3b82f6;
        }

        .bg-green-500 {
            background-color: #10b981;
        }

        .bg-purple-500 {
            background-color: #8b5cf6;
        }

        .bg-orange-500 {
            background-color: #f97316;
        }

        .bg-gray-500 {
            background-color: #6b7280;
        }

        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
        }

        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f1f5f9;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        .sidebar-transition {
            transition: transform 0.3s ease-in-out, width 0.3s ease-in-out;
        }

        .upload-area:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .card-hover:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        /* Mobile-specific styles */
        @media (max-width: 768px) {
            .mobile-hidden {
                display: none !important;
            }

            .mobile-sidebar {
                position: fixed;
                top: 0;
                left: -100%;
                height: 100vh;
                z-index: 50;
                transition: left 0.3s ease-in-out;
                width: 80% !important;
                max-width: 320px;
            }

            .mobile-sidebar.active {
                left: 0 !important;
            }

            .mobile-overlay {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 40;
                opacity: 0;
                visibility: hidden;
                transition: opacity 0.3s ease-in-out, visibility 0.3s ease-in-out;
            }

            .mobile-overlay.active {
                opacity: 1 !important;
                visibility: visible !important;
            }

            .grid-cols-3 {
                grid-template-columns: 1fr !important;
            }

            .lg\:grid-cols-3 {
                grid-template-columns: 1fr !important;
            }

            .lg\:col-span-2 {
                grid-column: span 1 / span 1 !important;
            }

            /* Hide sidebar on mobile by default */
            .sidebar {
                width: 80% !important;
                max-width: 320px !important;
            }

            /* Ensure main content takes full width on mobile */
            .main-content-mobile {
                margin-left: 0 !important;
                width: 100% !important;
            }
        }

        /* Tablet adjustments */
        @media (min-width: 769px) and (max-width: 1024px) {
            .grid-cols-3 {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }

            .lg\:grid-cols-3 {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }
        }

        /* High DPI display adjustments */
        @media (-webkit-min-device-pixel-ratio: 2),
        (min-resolution: 192dpi) {
            .text-xs {
                font-size: 0.7rem;
            }

            .text-sm {
                font-size: 0.85rem;
            }

            .text-base {
                font-size: 0.95rem;
            }

            .text-lg {
                font-size: 1.1rem;
            }

            .text-xl {
                font-size: 1.2rem;
            }

            .text-2xl {
                font-size: 1.4rem;
            }
        }

        /* Flexible grid system */
        .responsive-grid {
            display: grid;
            gap: 1rem;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        }

        .responsive-grid-small {
            display: grid;
            gap: 0.75rem;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        }

        /* Upload section grid - now 2 columns */
        .upload-grid {
            display: grid;
            gap: 1rem;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        }

        @media (min-width: 640px) {
            .upload-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        /* Dynamic font sizes based on container */
        .container-responsive {
            font-size: clamp(0.875rem, 2.5vw, 1rem);
        }

        /* Touch-friendly button sizes */
        @media (hover: none) and (pointer: coarse) {
            .btn-touch {
                min-height: 44px;
                min-width: 44px;
                padding: 0.75rem 1rem;
            }
        }

        /* Print styles */
        @media print {
            .no-print {
                display: none !important;
            }

            .print-full-width {
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }
        }
    </style>
</head>

<body class="bg-gray-50 overflow-hidden container-responsive">
    <!-- Mobile overlay -->
    <div id="mobileOverlay" class="mobile-overlay no-print" onclick="toggleMobileSidebar()"></div>

    <div class="flex h-screen">
        <!-- Sidebar -->
        <div id="sidebar" class="sidebar bg-white shadow-sm border-r border-gray-200 flex flex-col sidebar-transition mobile-sidebar no-print md:relative">
            <!-- Header -->
            <div class="px-4 sm:px-6 py-4 sm:py-6 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h1 class="text-lg sm:text-xl font-bold text-gray-800">StudyOrganizer</h1>
                    <div class="flex items-center space-x-2">
                        <div class="w-6 h-6 sm:w-8 sm:h-8 bg-blue-500 rounded-full flex items-center justify-center">
                            <span class="text-white text-xs sm:text-sm font-semibold">
                                <?php echo strtoupper(substr($_SESSION['user_email'], 0, 1)); ?>
                            </span>
                        </div>
                        <!-- Mobile close button -->
                        <button class="md:hidden text-gray-500 hover:text-gray-700 p-1 mobile-close-btn" onclick="toggleMobileSidebar()">
                            <span class="text-xl">✕</span>
                        </button>
                    </div>
                </div>
                <p class="text-xs sm:text-sm text-gray-600 mt-1 truncate">Welcome, <?php echo htmlspecialchars($_SESSION['user_email']); ?></p>
            </div>

            <!-- Navigation -->
            <nav class="flex-1 px-2 sm:px-4 py-4 sm:py-6">
                <div class="space-y-1 sm:space-y-2">
                    <a href="?tab=dashboard" class="flex items-center px-3 sm:px-4 py-2 sm:py-3 rounded-lg transition-colors btn-touch <?php echo $active_tab === 'dashboard' ? 'bg-blue-100 text-blue-600' : 'text-gray-600 hover:bg-gray-100'; ?>">
                        <span class="text-lg sm:text-xl mr-2 sm:mr-3">📚</span>
                        <span class="font-medium text-sm sm:text-base">Dashboard</span>
                    </a>
                    <a href="?tab=subjects" class="flex items-center px-3 sm:px-4 py-2 sm:py-3 rounded-lg transition-colors btn-touch <?php echo $active_tab === 'subjects' ? 'bg-blue-100 text-blue-600' : 'text-gray-600 hover:bg-gray-100'; ?>">
                        <span class="text-lg sm:text-xl mr-2 sm:mr-3">📁</span>
                        <span class="font-medium text-sm sm:text-base">All Subjects</span>
                    </a>
                </div>

                <!-- Subjects Quick Access -->
                <div class="mt-6 sm:mt-8">
                    <h3 class="px-3 sm:px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2 sm:mb-3">Quick Access</h3>
                    <div class="space-y-1">
                        <?php foreach ($subjects as $subject): ?>
                            <a href="?tab=subjects&subject=<?php echo urlencode($subject['name']); ?>"
                                class="flex items-center justify-between px-3 sm:px-4 py-2 text-xs sm:text-sm text-gray-600 hover:bg-gray-100 rounded-lg transition-colors btn-touch">
                                <div class="flex items-center">
                                    <span class="mr-2 sm:mr-3 text-sm sm:text-base"><?php echo getSubjectIcon($subject['name']); ?></span>
                                    <span class="truncate"><?php echo $subject['name']; ?></span>
                                </div>
                                <span class="text-xs bg-gray-200 text-gray-600 px-2 py-1 rounded-full flex-shrink-0"><?php echo $subject['count']; ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </nav>

            <!-- Footer with Logout -->
            <div class="px-2 sm:px-4 py-3 sm:py-4 border-t border-gray-200">
                <button class="w-full flex items-center px-3 sm:px-4 py-2 text-xs sm:text-sm text-gray-600 hover:bg-gray-100 rounded-lg transition-colors mb-2 btn-touch">
                    <span class="text-base sm:text-lg mr-2 sm:mr-3">🔍</span>
                    <span>Search Files</span>
                </button>
                <a href="?action=logout" class="w-full flex items-center px-3 sm:px-4 py-2 text-xs sm:text-sm text-red-600 hover:bg-red-50 rounded-lg transition-colors btn-touch">
                    <span class="text-base sm:text-lg mr-2 sm:mr-3">🚪</span>
                    <span>Logout</span>
                </a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col overflow-hidden main-content-mobile">
            <!-- Top Bar -->
            <div class="bg-white px-4 sm:px-6 lg:px-8 py-3 sm:py-4 border-b border-gray-200 flex items-center justify-between no-print">
                <div class="flex items-center">
                    <!-- Mobile menu button -->
                    <button id="mobileMenuBtn" class="md:hidden mr-3 text-gray-500 hover:text-gray-700 p-2 btn-touch focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-opacity-50 rounded mobile-menu-trigger" onclick="toggleMobileSidebar()">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                    <div>
                        <h2 class="text-xl sm:text-2xl font-bold text-gray-800">
                            <?php if ($active_tab === 'dashboard'): ?>
                                Welcome Back!
                            <?php elseif ($selected_subject): ?>
                                <?php echo htmlspecialchars($selected_subject); ?> Files
                            <?php else: ?>
                                All Subjects
                            <?php endif; ?>
                        </h2>
                        <p class="text-gray-600 mt-1 text-sm sm:text-base">
                            <?php if ($active_tab === 'dashboard'): ?>
                                Manage your study documents with AI-powered organization
                            <?php else: ?>
                                <?php echo count($filtered_files); ?> files found
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <div class="flex items-center space-x-2 sm:space-x-4">
                    <button class="px-2 sm:px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors hidden sm:block btn-touch">
                        <span class="text-gray-600 text-sm sm:text-base">⚙️ Settings</span>
                    </button>
                </div>
            </div>

            <!-- Content Area -->
            <div class="flex-1 overflow-auto custom-scrollbar content-padding print-full-width">
                <?php if ($active_tab === 'dashboard'): ?>
                    <!-- Dashboard View -->
                    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4 sm:gap-6 lg:gap-8">
                        <!-- Left Column - Upload and Stats -->
                        <div class="xl:col-span-2 space-y-4 sm:space-y-6 lg:space-y-8">
                            <!-- Upload Section -->
                            <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-xl sm:rounded-2xl card-padding border border-blue-100">
                                <h3 class="text-xl sm:text-2xl font-semibold text-gray-800 mb-4 sm:mb-6 flex items-center">
                                    <span class="mr-2 sm:mr-3 text-lg sm:text-xl">📤</span>
                                    <span class="text-base sm:text-xl">Upload Documents</span>
                                </h3>
                                <div class="upload-grid mb-4 sm:mb-6">
                                    <form method="post" enctype="multipart/form-data">
                                        <label class="cursor-pointer block">
                                            <input type="file" name="uploaded_file" accept=".pdf" onChange="this.form.submit()" class="hidden" />
                                            <div class="upload-area bg-white rounded-lg sm:rounded-xl p-4 sm:p-6 text-center hover:shadow-lg transition-all duration-300 border-2 border-dashed border-gray-200 hover:border-blue-300 btn-touch">
                                                <div class="text-red-500 text-2xl sm:text-4xl mb-2 sm:mb-3">📄</div>
                                                <span class="text-base sm:text-lg font-medium text-gray-800">PDF Files</span>
                                                <p class="text-xs sm:text-sm text-gray-500 mt-1 sm:mt-2">Click to upload</p>
                                            </div>
                                        </label>
                                    </form>
                                    <form method="post" enctype="multipart/form-data">
                                        <label class="cursor-pointer block">
                                            <input type="file" name="uploaded_file" accept="image/*" onChange="this.form.submit()" class="hidden" />
                                            <div class="upload-area bg-white rounded-lg sm:rounded-xl p-4 sm:p-6 text-center hover:shadow-lg transition-all duration-300 border-2 border-dashed border-gray-200 hover:border-green-300 btn-touch">
                                                <div class="text-green-500 text-2xl sm:text-4xl mb-2 sm:mb-3">🖼️</div>
                                                <span class="text-base sm:text-lg font-medium text-gray-800">Images</span>
                                                <p class="text-xs sm:text-sm text-gray-500 mt-1 sm:mt-2">JPG, PNG, etc.</p>
                                            </div>
                                        </label>
                                    </form>
                                </div>
                                <div class="bg-blue-50 rounded-lg p-3 sm:p-4 border-l-4 border-blue-500">
                                    <p class="text-blue-800 font-medium text-sm sm:text-base">💡 AI-Powered Organization</p>
                                    <p class="text-blue-700 text-xs sm:text-sm mt-1">Files are automatically categorized by subject and summarized using advanced AI</p>
                                </div>
                            </div>

                            <!-- Subject Overview Grid -->
                            <div class="bg-white rounded-xl sm:rounded-2xl card-padding shadow-sm border border-gray-200">
                                <h3 class="text-lg sm:text-xl font-semibold text-gray-800 mb-4 sm:mb-6">Subject Overview</h3>
                                <div class="responsive-grid-small">
                                    <?php foreach ($subjects as $index => $subject): ?>
                                        <a href="?tab=subjects&subject=<?php echo urlencode($subject['name']); ?>"
                                            class="card-hover bg-gradient-to-br from-white to-gray-50 rounded-lg sm:rounded-xl p-4 sm:p-6 border border-gray-200 transition-all duration-300 block group btn-touch">
                                            <div class="flex items-center justify-between mb-3 sm:mb-4">
                                                <div class="w-10 h-10 sm:w-12 sm:h-12 <?php echo $subject['color']; ?> rounded-lg sm:rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform">
                                                    <span class="text-white text-lg sm:text-xl"><?php echo getSubjectIcon($subject['name']); ?></span>
                                                </div>
                                                <span class="text-2xl sm:text-3xl font-bold text-gray-800"><?php echo $subject['count']; ?></span>
                                            </div>
                                            <p class="font-semibold text-gray-800 text-base sm:text-lg"><?php echo htmlspecialchars($subject['name']); ?></p>
                                            <p class="text-gray-600 text-sm sm:text-base">files stored</p>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Right Column - Recent Files -->
                        <div class="space-y-4 sm:space-y-6 lg:space-y-8">
                            <div class="bg-white rounded-xl sm:rounded-2xl p-4 sm:p-6 shadow-sm border border-gray-200">
                                <h3 class="text-lg sm:text-xl font-semibold text-gray-800 mb-4 sm:mb-6">Recent Files</h3>
                                <div class="space-y-3 sm:space-y-4">
                                    <?php if (empty($recent_files)): ?>
                                        <div class="text-center py-6 sm:py-8">
                                            <div class="text-gray-400 text-3xl sm:text-4xl mb-2 sm:mb-3">📂</div>
                                            <p class="text-gray-500 text-sm sm:text-base">No files uploaded yet</p>
                                            <p class="text-gray-400 text-xs sm:text-sm">Upload your first document to get started</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($recent_files as $file): ?>
                                            <div class="flex items-center justify-between p-3 sm:p-4 bg-gray-50 rounded-lg sm:rounded-xl hover:bg-gray-100 transition-colors cursor-pointer btn-touch" data-file-id="<?php echo $file['id']; ?>">
                                                <div class="flex items-center space-x-3 sm:space-x-4 flex-1 min-w-0">
                                                    <div class="w-10 h-10 sm:w-12 sm:h-12 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                                        <span class="text-blue-600 text-lg sm:text-xl">📄</span>
                                                    </div>
                                                    <div class="flex-1 min-w-0">
                                                        <p class="font-medium text-gray-800 truncate text-sm sm:text-base"><?php echo htmlspecialchars($file['name']); ?></p>
                                                        <p class="text-xs sm:text-sm text-gray-600"><?php echo htmlspecialchars($file['subject']); ?> • <?php echo $file['date']; ?></p>
                                                        <?php if (isset($file['status'])): ?>
                                                            <div class="mt-1">
                                                                <?php if ($file['status'] === 'processing'): ?>
                                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-yellow-100 text-yellow-800">
                                                                        🔄 Processing...
                                                                    </span>
                                                                <?php elseif ($file['status'] === 'completed'): ?>
                                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-green-100 text-green-800">
                                                                        ✅ Ready
                                                                    </span>
                                                                <?php elseif ($file['status'] === 'error'): ?>
                                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-red-100 text-red-800">
                                                                        ❌ Error
                                                                    </span>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <button class="file-menu-btn text-gray-400 hover:text-gray-600 p-2 flex-shrink-0 ml-2 btn-touch focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-opacity-50 rounded" data-file-id="<?php echo $file['id']; ?>">
                                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                        <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z" />
                                                    </svg>
                                                </button>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <?php if (count($user_files) > 5): ?>
                                    <div class="mt-4 sm:mt-6 text-center">
                                        <a href="?tab=subjects" class="text-blue-600 hover:text-blue-700 font-medium text-sm sm:text-base">View All Files →</a>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Quick Stats -->
                            <div class="bg-white rounded-xl sm:rounded-2xl p-4 sm:p-6 shadow-sm border border-gray-200">
                                <h3 class="text-lg sm:text-xl font-semibold text-gray-800 mb-4 sm:mb-6">Quick Stats</h3>
                                <div class="space-y-3 sm:space-y-4">
                                    <div class="flex items-center justify-between p-3 bg-blue-50 rounded-lg">
                                        <div class="flex items-center">
                                            <div class="w-6 h-6 sm:w-8 sm:h-8 bg-blue-500 rounded-lg flex items-center justify-center mr-2 sm:mr-3">
                                                <span class="text-white text-xs sm:text-sm">📊</span>
                                            </div>
                                            <span class="font-medium text-gray-800 text-sm sm:text-base">Total Files</span>
                                        </div>
                                        <span class="text-xl sm:text-2xl font-bold text-blue-600"><?php echo count($user_files); ?></span>
                                    </div>
                                    <div class="flex items-center justify-between p-3 bg-green-50 rounded-lg">
                                        <div class="flex items-center">
                                            <div class="w-6 h-6 sm:w-8 sm:h-8 bg-green-500 rounded-lg flex items-center justify-center mr-2 sm:mr-3">
                                                <span class="text-white text-xs sm:text-sm">🤖</span>
                                            </div>
                                            <span class="font-medium text-gray-800 text-sm sm:text-base">AI Processed</span>
                                        </div>
                                        <span class="text-xl sm:text-2xl font-bold text-green-600">
                                            <?php
                                            $processed = 0;
                                            $user_files = getUserFiles($user_id);
                                            foreach ($user_files as $file) {
                                                if (isset($file['status']) && $file['status'] === 'completed') {
                                                    $processed++;
                                                }
                                            }
                                            echo $processed;
                                            ?>
                                        </span>
                                    </div>
                                    <div class="flex items-center justify-between p-3 bg-purple-50 rounded-lg">
                                        <div class="flex items-center">
                                            <div class="w-6 h-6 sm:w-8 sm:h-8 bg-purple-500 rounded-lg flex items-center justify-center mr-2 sm:mr-3">
                                                <span class="text-white text-xs sm:text-sm">📚</span>
                                            </div>
                                            <span class="font-medium text-gray-800 text-sm sm:text-base">Subjects</span>
                                        </div>
                                        <span class="text-xl sm:text-2xl font-bold text-purple-600">
                                            <?php
                                            $activeSubjects = 0;
                                            foreach ($subjects as $subject) {
                                                if ($subject['count'] > 0) {
                                                    $activeSubjects++;
                                                }
                                            }
                                            echo $activeSubjects;
                                            ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- Subjects View -->
                    <div class="space-y-4 sm:space-y-6 lg:space-y-8">
                        <!-- Filter Bar -->
                        <div class="bg-white rounded-lg sm:rounded-xl p-4 sm:p-6 shadow-sm border border-gray-200">
                            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between space-y-4 sm:space-y-0">
                                <div class="flex flex-col sm:flex-row items-start sm:items-center space-y-2 sm:space-y-0 sm:space-x-4 w-full sm:w-auto">
                                    <?php if ($selected_subject): ?>
                                        <a href="?tab=subjects" class="text-blue-600 hover:text-blue-700 font-medium text-sm sm:text-base btn-touch">← All Subjects</a>
                                        <span class="text-gray-400 hidden sm:block">|</span>
                                    <?php endif; ?>
                                    <div class="flex flex-col sm:flex-row items-start sm:items-center space-y-2 sm:space-y-0 sm:space-x-2 w-full sm:w-auto">
                                        <span class="text-gray-600 text-sm sm:text-base">Filter by:</span>
                                        <div class="flex flex-wrap gap-2">
                                            <a href="?tab=subjects" class="px-3 py-1 rounded-full text-xs sm:text-sm <?php echo !$selected_subject ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?> transition-colors btn-touch">
                                                All
                                            </a>
                                            <?php foreach ($subjects as $index => $subject): ?>
                                                <?php if ($subject['count'] > 0): ?>
                                                    <a href="?tab=subjects&subject=<?php echo urlencode($subject['name']); ?>"
                                                        class="px-3 py-1 rounded-full text-xs sm:text-sm <?php echo $selected_subject === $subject['name'] ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?> transition-colors btn-touch"
                                                        data-subject="<?php echo htmlspecialchars($subject['name']); ?>">
                                                        <?php echo htmlspecialchars($subject['name']); ?> (<?php echo $subject['count']; ?>)
                                                    </a>
                                                <?php else: ?>
                                                    <span class="px-3 py-1 rounded-full text-xs sm:text-sm bg-gray-50 text-gray-400 cursor-not-allowed opacity-60"
                                                        data-subject="<?php echo htmlspecialchars($subject['name']); ?>">
                                                        <?php echo htmlspecialchars($subject['name']); ?> (0)
                                                    </span>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2 sm:space-x-3 w-full sm:w-auto">
                                    <button class="px-3 sm:px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors flex-1 sm:flex-none btn-touch">
                                        <span class="text-gray-600 text-xs sm:text-sm">🔄 Refresh</span>
                                    </button>
                                    <form method="post" enctype="multipart/form-data" class="inline flex-1 sm:flex-none">
                                        <label class="cursor-pointer w-full sm:w-auto">
                                            <input type="file" name="uploaded_file" accept=".pdf,image/*" onChange="this.form.submit()" class="hidden" />
                                            <div class="px-3 sm:px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-lg transition-colors cursor-pointer text-center btn-touch">
                                                <span class="text-xs sm:text-sm">📤 Upload File</span>
                                            </div>
                                        </label>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Files Grid -->
                        <div class="responsive-grid">
                            <?php if (empty($filtered_files)): ?>
                                <div class="col-span-full text-center py-12 sm:py-16">
                                    <div class="text-gray-400 text-4xl sm:text-6xl mb-3 sm:mb-4">📂</div>
                                    <h3 class="text-lg sm:text-xl font-semibold text-gray-600 mb-2">No files found</h3>
                                    <p class="text-gray-500 mb-4 sm:mb-6 text-sm sm:text-base">
                                        <?php echo $selected_subject ? "No files in " . htmlspecialchars($selected_subject) . " subject yet." : "Upload your first document to get started."; ?>
                                    </p>
                                    <form method="post" enctype="multipart/form-data" class="inline">
                                        <label class="cursor-pointer">
                                            <input type="file" name="uploaded_file" accept=".pdf,image/*" onChange="this.form.submit()" class="hidden" />
                                            <div class="px-4 sm:px-6 py-2 sm:py-3 bg-blue-500 hover:bg-blue-600 text-white rounded-lg transition-colors cursor-pointer inline-flex items-center btn-touch">
                                                <span class="mr-2">📤</span>
                                                <span class="text-sm sm:text-base">Upload Your First File</span>
                                            </div>
                                        </label>
                                    </form>
                                </div>
                            <?php else: ?>
                                <?php foreach ($filtered_files as $file): ?>
                                    <div class="bg-white rounded-lg sm:rounded-xl p-4 sm:p-6 shadow-sm border border-gray-200 hover:shadow-md transition-all duration-300 cursor-pointer card-hover btn-touch" data-file-id="<?php echo $file['id']; ?>">
                                        <div class="flex items-start justify-between mb-3 sm:mb-4">
                                            <div class="flex items-center space-x-3 flex-1 min-w-0">
                                                <div class="w-12 h-12 sm:w-14 sm:h-14 bg-blue-100 rounded-lg sm:rounded-xl flex items-center justify-center flex-shrink-0">
                                                    <span class="text-blue-600 text-xl sm:text-2xl">📄</span>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <h4 class="font-semibold text-gray-800 text-base sm:text-lg truncate"><?php echo htmlspecialchars($file['name']); ?></h4>
                                                    <p class="text-xs sm:text-sm text-gray-600"><?php echo htmlspecialchars($file['subject']); ?> • <?php echo $file['date']; ?></p>
                                                </div>
                                            </div>
                                            <button class="file-menu-btn text-gray-400 hover:text-gray-600 p-2 flex-shrink-0 btn-touch focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-opacity-50 rounded" data-file-id="<?php echo $file['id']; ?>">
                                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                    <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z" />
                                                </svg>
                                            </button>
                                        </div>

                                        <div class="bg-gray-50 rounded-lg p-3 sm:p-4 mb-3 sm:mb-4">
                                            <p class="text-xs sm:text-sm text-gray-700 font-medium mb-1 sm:mb-2">AI Summary:</p>
                                            <p class="text-xs sm:text-sm text-gray-600 line-clamp-3"><?php echo htmlspecialchars($file['summary']); ?></p>
                                        </div>

                                        <div class="flex items-center justify-between">
                                            <?php if (isset($file['status'])): ?>
                                                <div>
                                                    <?php if ($file['status'] === 'processing'): ?>
                                                        <span class="inline-flex items-center px-2 sm:px-3 py-1 rounded-full text-xs bg-yellow-100 text-yellow-800">
                                                            🔄 Processing...
                                                        </span>
                                                    <?php elseif ($file['status'] === 'completed'): ?>
                                                        <span class="inline-flex items-center px-2 sm:px-3 py-1 rounded-full text-xs bg-green-100 text-green-800">
                                                            ✅ Analyzed
                                                        </span>
                                                    <?php elseif ($file['status'] === 'error'): ?>
                                                        <span class="inline-flex items-center px-2 sm:px-3 py-1 rounded-full text-xs bg-red-100 text-red-800">
                                                            ❌ Error
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <div></div>
                                            <?php endif; ?>
                                            <button class="file-details-btn text-blue-600 hover:text-blue-700 text-xs sm:text-sm font-medium btn-touch" data-file-id="<?php echo $file['id']; ?>">
                                                View Details →
                                            </button>
                                        </div>

                                        <?php if (isset($file['debug_info']) && !empty($file['debug_info']) && $file['status'] === 'error'): ?>
                                            <div class="mt-3 sm:mt-4 p-2 sm:p-3 bg-red-50 rounded-lg border-l-4 border-red-500">
                                                <p class="text-xs text-red-700 font-medium mb-1">Debug Information:</p>
                                                <p class="text-xs text-red-600"><?php echo htmlspecialchars($file['debug_info']); ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- File Details Modal -->
    <div id="fileDetailsModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-2 sm:p-4 no-print">
        <div class="bg-white rounded-xl sm:rounded-2xl max-w-4xl w-full max-h-[95vh] sm:max-h-[90vh] overflow-hidden">
            <!-- Modal Header -->
            <div class="flex items-center justify-between p-4 sm:p-6 border-b border-gray-200">
                <div class="flex-1 min-w-0">
                    <h3 id="modalFileName" class="text-lg sm:text-xl font-semibold text-gray-800 truncate"></h3>
                    <p id="modalSubject" class="text-gray-600 text-sm sm:text-base"></p>
                </div>
                <button onclick="closeFileDetails()" class="text-gray-400 hover:text-gray-600 text-xl sm:text-2xl ml-4 btn-touch">&times;</button>
            </div>

            <!-- Modal Tabs -->
            <div class="border-b border-gray-200">
                <div class="flex">
                    <button id="summaryTab" onclick="switchTab('summary')" class="flex-1 py-3 sm:py-4 px-4 sm:px-6 text-xs sm:text-sm font-medium text-blue-600 border-b-2 border-blue-600 btn-touch">
                        Summary & Details
                    </button>
                    <button id="fullTextTab" onclick="switchTab('fulltext')" class="flex-1 py-3 sm:py-4 px-4 sm:px-6 text-xs sm:text-sm font-medium text-gray-500 hover:text-gray-700 btn-touch">
                        Full Text Content
                    </button>
                </div>
            </div>

            <!-- Modal Content -->
            <div class="p-4 sm:p-6 max-h-[50vh] sm:max-h-[60vh] overflow-auto custom-scrollbar">
                <!-- Summary Tab -->
                <div id="summaryContent" class="tab-content">
                    <div class="space-y-4 sm:space-y-6">
                        <div>
                            <h4 class="font-semibold text-gray-800 mb-2 text-sm sm:text-base">AI Summary</h4>
                            <div class="bg-gray-50 rounded-lg p-3 sm:p-4">
                                <p id="modalSummary" class="text-gray-700 text-sm sm:text-base"></p>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6">
                            <div>
                                <h4 class="font-semibold text-gray-800 mb-2 text-sm sm:text-base">File Information</h4>
                                <div class="space-y-2 text-xs sm:text-sm">
                                    <p id="modalDate" class="text-gray-600"></p>
                                    <p id="modalStatus" class="text-gray-600"></p>
                                </div>
                            </div>
                            <div>
                                <h4 class="font-semibold text-gray-800 mb-2 text-sm sm:text-base">Actions</h4>
                                <div class="space-y-2">
                                    <button class="w-full px-3 sm:px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-lg transition-colors text-xs sm:text-sm btn-touch">
                                        📤 Re-analyze with AI
                                    </button>
                                    <button class="w-full px-3 sm:px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded-lg transition-colors text-xs sm:text-sm btn-touch">
                                        🗑️ Delete File
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Full Text Tab -->
                <div id="fulltextContent" class="tab-content hidden">
                    <div>
                        <h4 class="font-semibold text-gray-800 mb-3 sm:mb-4 text-sm sm:text-base">Extracted Text Content</h4>
                        <div class="bg-gray-50 rounded-lg p-3 sm:p-4 max-h-72 sm:max-h-96 overflow-auto custom-scrollbar">
                            <pre id="modalFullText" class="text-xs sm:text-sm text-gray-700 whitespace-pre-wrap font-mono"></pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Mobile sidebar toggle with better debugging
        function toggleMobileSidebar() {
            console.log('toggleMobileSidebar called'); // Debug log

            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('mobileOverlay');

            if (!sidebar || !overlay) {
                console.error('Sidebar or overlay element not found');
                return;
            }

            console.log('Current sidebar classes:', sidebar.className);
            console.log('Current overlay classes:', overlay.className);

            // Toggle active class
            const isActive = sidebar.classList.contains('active');

            if (isActive) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                document.body.style.overflow = ''; // Restore scroll
                console.log('Sidebar closed');
            } else {
                sidebar.classList.add('active');
                overlay.classList.add('active');
                document.body.style.overflow = 'hidden'; // Prevent background scroll
                console.log('Sidebar opened');
            }

            console.log('New sidebar classes:', sidebar.className);
            console.log('New overlay classes:', overlay.className);
        }

        // Ensure DOM is ready before setting up event listeners
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM Content Loaded');

            // Test if elements exist
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('mobileOverlay');
            const menuBtn = document.getElementById('mobileMenuBtn');

            console.log('Sidebar element:', sidebar);
            console.log('Overlay element:', overlay);
            console.log('Menu button element:', menuBtn);

            // Add additional event listener to menu button
            if (menuBtn) {
                menuBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    console.log('Menu button clicked via event listener');
                    toggleMobileSidebar();
                });
            }

            // Add event listener to overlay
            if (overlay) {
                overlay.addEventListener('click', function() {
                    console.log('Overlay clicked');
                    toggleMobileSidebar();
                });
            }

            // Add event listener to close button
            const closeBtn = document.querySelector('.mobile-close-btn');
            if (closeBtn) {
                closeBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    console.log('Close button clicked');
                    toggleMobileSidebar();
                });
            }
        });

        // Add debugging for subject counting
        console.log('Subject data from PHP:', <?php echo json_encode($subjects); ?>);
        console.log('Selected subject:', '<?php echo $selected_subject; ?>');
        console.log('File data:', fileData);
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded, fileData:', fileData); // Debug log

            // Add event listeners to all file cards
            document.querySelectorAll('[data-file-id]').forEach(card => {
                card.addEventListener('click', function(e) {
                    const fileId = this.getAttribute('data-file-id');
                    console.log('Card clicked, file ID:', fileId);
                    openFileDetails(fileId);
                });
            });

            // Add event listeners to three-dot buttons
            document.querySelectorAll('.file-menu-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const fileId = this.getAttribute('data-file-id');
                    console.log('Three-dot button clicked, file ID:', fileId);
                    openFileDetails(fileId);
                });
            });
        });
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 768) {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('mobileOverlay');

                if (!sidebar.contains(e.target) && !e.target.closest('.mobile-menu-trigger')) {
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                }
            }
        });

        // File details modal functionality with better error handling


        function openFileDetails(fileId) {
            console.log('Opening file details for ID:', fileId); // Debug log

            const file = fileData.find(f => f.id == fileId);
            if (!file) {
                console.error('File not found with ID:', fileId);
                alert('File not found!');
                return;
            }

            try {
                // Update modal content
                document.getElementById('modalFileName').textContent = file.name || 'Unknown File';
                document.getElementById('modalSubject').textContent = file.subject || 'Unknown Subject';
                document.getElementById('modalSummary').textContent = file.summary || 'No summary available';
                document.getElementById('modalDate').textContent = 'Uploaded: ' + (file.date || 'Unknown date');
                document.getElementById('modalStatus').textContent = file.status ? 'Status: ' + file.status : 'Status: Unknown';

                // Update full text content
                const fullTextDiv = document.getElementById('modalFullText');
                if (file.extracted_text && file.extracted_text.trim()) {
                    fullTextDiv.textContent = file.extracted_text;
                } else {
                    fullTextDiv.innerHTML = 'Full text not available - this may be due to OCR processing failure or the file may not contain extractable text.';
                }

                // Reset to summary tab
                switchTab('summary');

                // Show modal
                document.getElementById('fileDetailsModal').classList.remove('hidden');

                // Prevent body scroll on mobile
                document.body.style.overflow = 'hidden';

                console.log('Modal opened successfully'); // Debug log
            } catch (error) {
                console.error('Error opening modal:', error);
                alert('Error opening file details');
            }
        }

        function closeFileDetails() {
            document.getElementById('fileDetailsModal').classList.add('hidden');

            // Restore body scroll
            document.body.style.overflow = '';
        }

        function switchTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.add('hidden');
            });

            // Remove active styles from all tabs
            document.getElementById('summaryTab').className = 'flex-1 py-3 sm:py-4 px-4 sm:px-6 text-xs sm:text-sm font-medium text-gray-500 hover:text-gray-700 btn-touch';
            document.getElementById('fullTextTab').className = 'flex-1 py-3 sm:py-4 px-4 sm:px-6 text-xs sm:text-sm font-medium text-gray-500 hover:text-gray-700 btn-touch';

            // Show selected tab content and apply active styles
            if (tabName === 'summary') {
                document.getElementById('summaryContent').classList.remove('hidden');
                document.getElementById('summaryTab').className = 'flex-1 py-3 sm:py-4 px-4 sm:px-6 text-xs sm:text-sm font-medium text-blue-600 border-b-2 border-blue-600 btn-touch';
            } else if (tabName === 'fulltext') {
                document.getElementById('fulltextContent').classList.remove('hidden');
                document.getElementById('fullTextTab').className = 'flex-1 py-3 sm:py-4 px-4 sm:px-6 text-xs sm:text-sm font-medium text-blue-600 border-b-2 border-blue-600 btn-touch';
            }
        }

        // Close modal when clicking outside
        document.getElementById('fileDetailsModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeFileDetails();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeFileDetails();

                // Also close mobile sidebar if open
                if (window.innerWidth <= 768) {
                    const sidebar = document.getElementById('sidebar');
                    const overlay = document.getElementById('mobileOverlay');
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                }
            }
        });

        // Handle window resize
        window.addEventListener('resize', function() {
            // Close mobile sidebar on resize to desktop
            if (window.innerWidth > 768) {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('mobileOverlay');
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
            }
        });

        // Auto-refresh the page when processing files to show updated status
        <?php
        $hasProcessingFiles = false;
        if (isset($_SESSION[$user_files_key]) && !empty($_SESSION[$user_files_key])) {
            $user_files = getUserFiles($user_id);
            foreach ($user_files as $file) {
                if (isset($file['status']) && $file['status'] === 'processing') {
                    $hasProcessingFiles = true;
                    break;
                }
            }
        }
        if ($hasProcessingFiles): ?>
            setTimeout(function() {
                window.location.reload();
            }, 3000);
        <?php endif; ?>

        // Show loading state when uploading files
        document.addEventListener('change', function(e) {
            if (e.target.type === 'file' && e.target.files.length > 0) {
                // Create loading overlay
                const overlay = document.createElement('div');
                overlay.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4';
                overlay.innerHTML = `
                <div class="bg-white rounded-xl sm:rounded-2xl p-6 sm:p-8 text-center max-w-md mx-auto">
                    <div class="animate-spin rounded-full h-12 w-12 sm:h-16 sm:w-16 border-b-4 border-blue-500 mx-auto mb-4 sm:mb-6"></div>
                    <h3 class="text-lg sm:text-xl font-semibold text-gray-800 mb-2">Processing your file...</h3>
                    <p class="text-gray-600 mb-2 text-sm sm:text-base">OCR and AI analysis in progress</p>
                    <div class="bg-blue-50 rounded-lg p-3 sm:p-4 mt-4">
                        <p class="text-blue-800 text-xs sm:text-sm">⚡ This may take a few moments for larger files</p>
                    </div>
                </div>
                `;
                document.body.appendChild(overlay);
            }
        });

        // Add smooth transitions for navigation
        document.querySelectorAll('a[href*="tab="]').forEach(link => {
            link.addEventListener('click', function(e) {
                // Add loading state if needed
                const currentContent = document.querySelector('.flex-1.overflow-auto');
                if (currentContent) {
                    currentContent.style.opacity = '0.7';
                }

                // Close mobile sidebar on navigation
                if (window.innerWidth <= 768) {
                    const sidebar = document.getElementById('sidebar');
                    const overlay = document.getElementById('mobileOverlay');
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                }
            });
        });

        // Add keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Don't trigger shortcuts when modal is open or when typing in inputs
            if (document.getElementById('fileDetailsModal').classList.contains('hidden') &&
                !e.target.matches('input, textarea, select')) {

                // Ctrl/Cmd + U for upload
                if ((e.ctrlKey || e.metaKey) && e.key === 'u') {
                    e.preventDefault();
                    const fileInput = document.querySelector('input[type="file"]');
                    if (fileInput) fileInput.click();
                }

                // Ctrl/Cmd + D for dashboard
                if ((e.ctrlKey || e.metaKey) && e.key === 'd') {
                    e.preventDefault();
                    window.location.href = '?tab=dashboard';
                }

                // Ctrl/Cmd + S for subjects
                if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                    e.preventDefault();
                    window.location.href = '?tab=subjects';
                }

                // M key for mobile menu toggle
                if (e.key === 'm' || e.key === 'M') {
                    if (window.innerWidth <= 768) {
                        e.preventDefault();
                        toggleMobileSidebar();
                    }
                }
            }
        });

        // Improve touch interactions on mobile
        if ('ontouchstart' in window) {
            document.addEventListener('touchstart', function() {}, {
                passive: true
            });
        }

        // Add scale detection and adjustment
        function adjustForScale() {
            const scale = window.devicePixelRatio || 1;
            const viewport = document.querySelector('meta[name="viewport"]');

            if (scale > 1.5) {
                // High DPI display adjustments
                document.documentElement.style.setProperty('--font-scale', '0.9');
                document.documentElement.style.setProperty('--content-padding', '1rem');
            } else if (scale < 1) {
                // Low DPI display adjustments
                document.documentElement.style.setProperty('--font-scale', '1.1');
                document.documentElement.style.setProperty('--content-padding', '2.5rem');
            }
        }

        // Run scale adjustment on load and resize
        window.addEventListener('load', adjustForScale);
        window.addEventListener('resize', adjustForScale);

        // Detect zoom level changes
        let lastInnerWidth = window.innerWidth;
        window.addEventListener('resize', function() {
            const currentInnerWidth = window.innerWidth;
            const zoomLevel = Math.round((currentInnerWidth / lastInnerWidth) * 100);

            if (Math.abs(zoomLevel - 100) > 10) {
                // Significant zoom change detected
                adjustForScale();
            }

            lastInnerWidth = currentInnerWidth;
        });

        // Performance optimization: Use passive listeners where possible
        document.addEventListener('scroll', function() {
            // Handle scroll events efficiently
        }, {
            passive: true
        });

        document.addEventListener('touchmove', function() {
            // Handle touch move events efficiently
        }, {
            passive: true
        });
        const fileData = <?php echo json_encode($user_files); ?>;
    </script>
</body>

</html>
