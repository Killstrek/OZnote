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

// Handle file viewing/download/delete/reanalyze requests
if (isset($_GET['action']) && isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];

    if (isset($_GET['file_id'])) {
        $file_id = intval($_GET['file_id']);

        if ($_GET['action'] === 'view_original') {
            serveOriginalFile($file_id, $user_id);
            exit();
        } elseif ($_GET['action'] === 'download_summary') {
            serveSummaryFile($file_id, $user_id);
            exit();
        } elseif ($_GET['action'] === 'delete_file') {
            deleteUserFile($file_id, $user_id);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query(['tab' => $_GET['tab'] ?? 'dashboard']));
            exit();
        } elseif ($_GET['action'] === 'reanalyze_file') {
            reanalyzeFile($file_id, $user_id);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query(['tab' => $_GET['tab'] ?? 'dashboard']));
            exit();
        }
    }
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

    $formatted_files = [];
    foreach ($files as $file) {
        // Read summary directly from file
        $summary_content = 'No summary available';
        $full_summary = 'No summary available';
        if (!empty($file['summary_file_path']) && file_exists($file['summary_file_path'])) {
            $full_summary = file_get_contents($file['summary_file_path']);
            $summary_content = substr($full_summary, 0, 200) . '...';
        }

        $formatted_files[] = [
            'id' => $file['id'],
            'name' => $file['file_name'],
            'subject' => $file['subject'],
            'date' => $file['date'],
            'summary' => $summary_content,
            'full_summary' => $full_summary, // Add this for modal
            'status' => 'completed',
            'debug_info' => '',
            'extracted_text' => '', // This would need to be stored or regenerated
            'summary_file_path' => $file['summary_file_path'],
            'original_file_path' => $file['original_file_path']
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

// OCR Function using OCR.space API with enhanced error handling and retry mechanism
function performOCR($file_path)
{
    $api_key = OCR_SPACE_API_KEY;

    // Validate API key
    if (empty($api_key) || $api_key === 'YOUR_OCR_API_KEY') {
        error_log("OCR.space API key not configured properly");
        return ['error' => 'OCR.space API key not configured', 'text' => false, 'debug' => 'Invalid API key'];
    }

    // Validate file exists and is readable
    if (!file_exists($file_path)) {
        error_log("OCR file not found: " . $file_path);
        return ['error' => 'File not found', 'text' => false, 'debug' => 'File path invalid'];
    }

    if (!is_readable($file_path)) {
        error_log("OCR file not readable: " . $file_path);
        return ['error' => 'File not readable', 'text' => false, 'debug' => 'File permissions issue'];
    }

    // Check file size (OCR.space has limits)
    $file_size = filesize($file_path);
    if ($file_size > 10 * 1024 * 1024) { // 10MB limit
        error_log("OCR file too large: " . $file_size . " bytes");
        return ['error' => 'File too large for OCR processing', 'text' => false, 'debug' => 'File exceeds 10MB limit'];
    }

    // Check file type
    $file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'pdf'];
    if (!in_array($file_extension, $allowed_extensions)) {
        error_log("OCR unsupported file type: " . $file_extension);
        return ['error' => 'Unsupported file type for OCR', 'text' => false, 'debug' => 'File type not supported'];
    }

    $url = "https://api.ocr.space/parse/image";

    // Enhanced OCR parameters for better accuracy
    $post_data = [
        'apikey' => $api_key,
        'language' => 'eng',
        'isOverlayRequired' => 'false',
        'file' => new CURLFile($file_path),
        'detectOrientation' => 'true',
        'isTable' => 'true',
        'OCREngine' => '2', // Use OCR Engine 2 for better accuracy
        'scale' => 'true',   // Auto-scale for better text detection
        'isCreateSearchablePdf' => 'false',
        'isSearchablePdfHideTextLayer' => 'false'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120); // Increased timeout for large files
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'StudyOrganizer/1.0');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    $curl_info = curl_getinfo($ch);
    curl_close($ch);

    // Enhanced logging
    error_log("OCR API Request - File: " . basename($file_path) . ", Size: " . $file_size . " bytes");
    error_log("OCR API Response Code: " . $http_code);
    error_log("OCR API Response Preview: " . substr($response, 0, 200));

    if ($curl_error) {
        error_log("OCR CURL Error: " . $curl_error);
        return ['error' => 'Network error: ' . $curl_error, 'text' => false, 'debug' => 'CURL failed'];
    }

    if ($http_code === 200 && !empty($response)) {
        $result = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("OCR JSON decode error: " . json_last_error_msg());
            return ['error' => 'Invalid response format', 'text' => false, 'debug' => 'JSON parsing failed'];
        }

        // Check for API errors
        if (isset($result['IsErroredOnProcessing']) && $result['IsErroredOnProcessing'] === true) {
            $error_msg = isset($result['ErrorMessage']) ? $result['ErrorMessage'][0] : 'Unknown OCR processing error';
            error_log("OCR Processing Error: " . $error_msg);
            return ['error' => 'OCR processing failed: ' . $error_msg, 'text' => false, 'debug' => 'API processing error'];
        }

        if (isset($result['ParsedResults']) && !empty($result['ParsedResults'])) {
            $extracted_text = '';
            $total_confidence = 0;
            $confidence_count = 0;

            foreach ($result['ParsedResults'] as $parsed_result) {
                if (isset($parsed_result['ParsedText'])) {
                    $extracted_text .= $parsed_result['ParsedText'] . "\n";

                    // Track OCR confidence if available
                    if (isset($parsed_result['TextOverlay']['Lines'])) {
                        foreach ($parsed_result['TextOverlay']['Lines'] as $line) {
                            if (isset($line['Words'])) {
                                foreach ($line['Words'] as $word) {
                                    if (isset($word['Height']) && $word['Height'] > 0) {
                                        $confidence_count++;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            $final_text = trim($extracted_text);

            if (!empty($final_text)) {
                $word_count = str_word_count($final_text);
                error_log("OCR Success - Extracted {$word_count} words");

                return [
                    'error' => null,
                    'text' => $final_text,
                    'debug' => "OCR successful - {$word_count} words extracted"
                ];
            } else {
                error_log("OCR returned empty text");
                return ['error' => 'No text found in document', 'text' => false, 'debug' => 'Empty OCR result'];
            }
        } else {
            error_log("OCR No parsed results in response");
            return ['error' => 'No text content detected', 'text' => false, 'debug' => 'No parsed results'];
        }
    } else {
        // Handle specific HTTP error codes
        $error_message = "HTTP Error $http_code";
        switch ($http_code) {
            case 401:
                $error_message = "Invalid API key";
                break;
            case 403:
                $error_message = "API key limit exceeded or permission denied";
                break;
            case 422:
                $error_message = "Invalid file format or corrupted file";
                break;
            case 500:
                $error_message = "OCR service temporary error";
                break;
            case 503:
                $error_message = "OCR service unavailable";
                break;
        }

        error_log("OCR HTTP Error: $http_code - $error_message");
        return ['error' => $error_message, 'text' => false, 'debug' => "HTTP $http_code"];
    }
}

// Extract text from PDF
function extractTextFromPDF($file_path)
{
    $content = shell_exec("pdftotext '$file_path' -");
    return $content ? trim($content) : false;
}

// Analyze with Claude - Enhanced with retry mechanism for overload handling
function analyzeWithClaude($text_content, $filename)
{
    $api_key = CLAUDE_API_KEY;

    if (empty($api_key)) {
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

    // Truncate very long text to avoid API limits
    $max_length = 15000; // Reasonable limit for Claude API
    if (strlen($text_content) > $max_length) {
        $text_content = substr($text_content, 0, $max_length) . "\n[Content truncated for analysis...]";
        error_log("Text content truncated to $max_length characters for Claude analysis");
    }

    $url = "https://api.anthropic.com/v1/messages";

    // Enhanced prompt for better analysis
    $prompt = "Please analyze the following educational document and provide a comprehensive analysis:

**Document:** $filename
**Content:** 
$text_content

**Instructions:**
1. **Subject Classification:** Choose the most appropriate category from: Physics, Biology, Chemistry, Mathematics, Others
2. **Detailed Summary:** Provide a comprehensive summary (3-5 sentences) that includes:
   - Main topic and scope of the document
   - Key concepts, theories, or principles discussed
   - Important findings, formulas, or conclusions
   - Educational level and context (if identifiable)
   - Practical applications or examples mentioned

**Analysis Guidelines:**
- Focus on educational value and learning objectives
- Identify specific subtopics within the subject area
- Highlight any diagrams, equations, or data mentioned
- Note any experimental procedures or problem-solving methods
- Consider interdisciplinary connections if present

**Response Format (JSON only):**
{
    \"subject\": \"[Subject Category]\",
    \"summary\": \"[Detailed summary with key insights and educational value]\"
}

Ensure the summary is informative, well-structured, and captures the essential learning content of the document.";

    $request_data = [
        'model' => 'claude-3-5-sonnet-20241022',
        'max_tokens' => 500,
        'messages' => [
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ]
    ];

    // Retry mechanism for handling API overload
    $max_retries = 3;
    $base_delay = 2; // seconds

    for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
        error_log("Claude API attempt $attempt of $max_retries for file: $filename");

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
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); // Increased timeout
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'StudyOrganizer/1.0');

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        error_log("Claude API attempt $attempt - HTTP Code: $http_code");

        if ($curl_error) {
            error_log("Claude CURL Error on attempt $attempt: " . $curl_error);
            if ($attempt === $max_retries) {
                return [
                    'subject' => 'Others',
                    'summary' => 'Network error connecting to Claude API after ' . $max_retries . ' attempts: ' . $curl_error,
                    'debug' => 'CURL Error after retries: ' . $curl_error
                ];
            }
            // Wait before retry
            sleep($base_delay * $attempt);
            continue;
        }

        // Handle successful response
        if ($http_code === 200) {
            $result = json_decode($response, true);
            if (isset($result['content'][0]['text'])) {
                $claude_response = $result['content'][0]['text'];

                // Try to extract JSON from the response
                preg_match('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/', $claude_response, $matches);
                if ($matches) {
                    $analysis = json_decode($matches[0], true);
                    if ($analysis && isset($analysis['subject']) && isset($analysis['summary'])) {
                        // Validate subject category
                        $valid_subjects = ['Physics', 'Biology', 'Chemistry', 'Mathematics', 'Others'];
                        if (!in_array($analysis['subject'], $valid_subjects)) {
                            $analysis['subject'] = 'Others';
                        }

                        error_log("Claude analysis successful on attempt $attempt");
                        return [
                            'subject' => $analysis['subject'],
                            'summary' => $analysis['summary'],
                            'debug' => "Claude analysis successful on attempt $attempt - Enhanced summary generated"
                        ];
                    }
                }

                // Fallback parsing method
                $lines = explode("\n", $claude_response);
                $subject = 'Others';
                $summary = 'Document analyzed successfully. The content has been processed and categorized.';

                foreach ($lines as $line) {
                    if (stripos($line, 'subject') !== false && stripos($line, ':') !== false) {
                        $parts = explode(':', $line, 2);
                        if (count($parts) > 1) {
                            $extracted_subject = trim(str_replace(['"', "'", '}', '{'], '', $parts[1]));
                            $valid_subjects = ['Physics', 'Biology', 'Chemistry', 'Mathematics', 'Others'];
                            if (in_array($extracted_subject, $valid_subjects)) {
                                $subject = $extracted_subject;
                            }
                        }
                    }
                    if (stripos($line, 'summary') !== false && stripos($line, ':') !== false) {
                        $parts = explode(':', $line, 2);
                        if (count($parts) > 1) {
                            $summary = trim(str_replace(['"', "'", '}'], '', $parts[1]));
                            if (strlen($summary) > 10) { // Only use if we got a meaningful summary
                                break;
                            }
                        }
                    }
                }

                return [
                    'subject' => $subject,
                    'summary' => $summary,
                    'debug' => "Claude response parsed with fallback method on attempt $attempt"
                ];
            }
        }

        // Handle retryable errors (overload, rate limits, server errors)
        if (in_array($http_code, [429, 500, 502, 503, 504, 529])) {
            $error_response = json_decode($response, true);
            $error_message = 'API temporarily unavailable';

            if (isset($error_response['error']['message'])) {
                $error_message = $error_response['error']['message'];
            }

            error_log("Claude API retryable error on attempt $attempt: HTTP $http_code - $error_message");

            if ($attempt < $max_retries) {
                // Exponential backoff: wait longer for each retry
                $wait_time = $base_delay * pow(2, $attempt - 1);
                error_log("Waiting {$wait_time} seconds before retry...");
                sleep($wait_time);
                continue;
            } else {
                // Final attempt failed
                return [
                    'subject' => 'Others',
                    'summary' => "Document uploaded successfully. Claude AI is temporarily overloaded (HTTP $http_code). The document has been saved and can be re-analyzed later when the service is available.",
                    'debug' => "HTTP $http_code after $max_retries attempts: $error_message"
                ];
            }
        }

        // Handle non-retryable errors (auth, etc.)
        else {
            $error_response = json_decode($response, true);
            $error_message = 'Unknown API error';

            if (isset($error_response['error']['message'])) {
                $error_message = $error_response['error']['message'];
            }

            error_log("Claude API non-retryable error: HTTP $http_code - $error_message");

            return [
                'subject' => 'Others',
                'summary' => "Document uploaded successfully. Claude API error (HTTP $http_code): $error_message",
                'debug' => "HTTP $http_code (non-retryable): $error_message"
            ];
        }
    }

    // Should not reach here, but just in case
    return [
        'subject' => 'Others',
        'summary' => 'Document uploaded successfully but AI analysis failed after multiple attempts.',
        'debug' => 'All retry attempts exhausted'
    ];
}

// Process uploaded file
function processUploadedFile($uploaded_file)
{
    $file_extension = strtolower(pathinfo($uploaded_file['name'], PATHINFO_EXTENSION));
    $user_id = $_SESSION['user_id'];

    $clean_filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $uploaded_file['name']);
    $timestamp = time();
    $final_filename = $timestamp . '_' . $clean_filename;

    $temp_upload_path = UPLOAD_DIR . 'temp_' . $final_filename;

    if (move_uploaded_file($uploaded_file['tmp_name'], $temp_upload_path)) {
        $extracted_text = '';

        if ($file_extension === 'pdf') {
            $extracted_text = extractTextFromPDF($temp_upload_path);
            if (!$extracted_text || strlen(trim($extracted_text)) <= 10) {
                $ocr_result = performOCR($temp_upload_path);
                $extracted_text = $ocr_result['text'];
            }
        } elseif (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff'])) {
            $ocr_result = performOCR($temp_upload_path);
            $extracted_text = $ocr_result['text'];
        }

        if ($extracted_text && strlen(trim($extracted_text)) > 10) {
            $analysis = analyzeWithClaude($extracted_text, $uploaded_file['name']);
            $subject = $analysis['subject'];

            $subject_upload_path = getUserUploadPath($user_id, $subject);
            if (!file_exists($subject_upload_path)) {
                mkdir($subject_upload_path, 0755, true);
            }

            $final_upload_path = $subject_upload_path . $final_filename;

            if (rename($temp_upload_path, $final_upload_path)) {
                $summary_file_path = createSummaryTextFile(
                    $analysis['summary'],
                    $uploaded_file['name'],
                    $user_id,
                    $subject
                );

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
                    'file_path' => $final_upload_path
                ];
            }
        }
    }

    return [
        'subject' => 'Others',
        'summary' => 'Failed to process file',
        'status' => 'error'
    ];
}

// IMPROVED FILE SERVING FUNCTION
function serveOriginalFile($file_id, $user_id)
{
    // Add error logging
    error_log("Serving file - ID: $file_id, User: $user_id");

    $pdo = getDBConnection();
    $sql = "SELECT * FROM user_files WHERE id = ? AND user_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$file_id, $user_id]);
    $file = $stmt->fetch();

    if (!$file) {
        error_log("File not found in database - ID: $file_id");
        header('HTTP/1.0 404 Not Found');
        echo 'File not found in database';
        exit;
    }

    if (!file_exists($file['original_file_path'])) {
        error_log("File not found on disk: " . $file['original_file_path']);
        header('HTTP/1.0 404 Not Found');
        echo 'File not found on disk';
        exit;
    }

    $file_path = $file['original_file_path'];
    $file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    $file_size = filesize($file_path);

    error_log("Serving file: $file_path, Size: $file_size bytes, Extension: $file_extension");

    // Clear any previous output
    if (ob_get_level()) {
        ob_end_clean();
    }

    // Set appropriate headers based on file type
    if ($file_extension === 'pdf') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($file['file_name']) . '"');
        header('X-Frame-Options: SAMEORIGIN'); // Allow iframe embedding from same origin
    } elseif (in_array($file_extension, ['jpg', 'jpeg'])) {
        header('Content-Type: image/jpeg');
        header('Content-Disposition: inline; filename="' . basename($file['file_name']) . '"');
    } elseif ($file_extension === 'png') {
        header('Content-Type: image/png');
        header('Content-Disposition: inline; filename="' . basename($file['file_name']) . '"');
    } elseif ($file_extension === 'gif') {
        header('Content-Type: image/gif');
        header('Content-Disposition: inline; filename="' . basename($file['file_name']) . '"');
    } elseif (in_array($file_extension, ['bmp', 'tiff', 'tif'])) {
        header('Content-Type: image/' . $file_extension);
        header('Content-Disposition: inline; filename="' . basename($file['file_name']) . '"');
    } else {
        // For unknown file types, force download
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file['file_name']) . '"');
    }

    // Set cache headers
    header('Cache-Control: private, max-age=3600'); // Cache for 1 hour
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($file_path)) . ' GMT');

    // Set content length
    header('Content-Length: ' . $file_size);

    // Handle range requests for large files (helps with loading)
    if (isset($_SERVER['HTTP_RANGE'])) {
        $range = $_SERVER['HTTP_RANGE'];
        if (preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
            $start = intval($matches[1]);
            $end = $matches[2] ? intval($matches[2]) : $file_size - 1;

            header('HTTP/1.1 206 Partial Content');
            header("Content-Range: bytes $start-$end/$file_size");
            header('Content-Length: ' . ($end - $start + 1));

            // Read and output the range
            $fp = fopen($file_path, 'rb');
            fseek($fp, $start);
            echo fread($fp, $end - $start + 1);
            fclose($fp);
            exit;
        }
    }

    // For small files, read directly
    if ($file_size < 10 * 1024 * 1024) { // Less than 10MB
        readfile($file_path);
    } else {
        // For larger files, read in chunks to avoid memory issues
        $fp = fopen($file_path, 'rb');
        if ($fp) {
            while (!feof($fp)) {
                echo fread($fp, 8192); // 8KB chunks
                if (connection_aborted()) {
                    break;
                }
                flush();
            }
            fclose($fp);
        } else {
            error_log("Could not open file for reading: $file_path");
            header('HTTP/1.0 500 Internal Server Error');
            echo 'Could not read file';
        }
    }

    exit;
}

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

    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="' . pathinfo($file['file_name'], PATHINFO_FILENAME) . '_summary.txt"');
    header('Content-Length: ' . filesize($file['summary_file_path']));
    readfile($file['summary_file_path']);
}

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

function reanalyzeFile($file_id, $user_id)
{
    $pdo = getDBConnection();
    $sql = "SELECT * FROM user_files WHERE id = ? AND user_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$file_id, $user_id]);
    $file = $stmt->fetch();

    if (!$file || !file_exists($file['original_file_path'])) {
        return false;
    }

    $file_extension = strtolower(pathinfo($file['original_file_path'], PATHINFO_EXTENSION));
    $extracted_text = '';

    if ($file_extension === 'pdf') {
        $extracted_text = extractTextFromPDF($file['original_file_path']);
        if (!$extracted_text || strlen(trim($extracted_text)) <= 10) {
            $ocr_result = performOCR($file['original_file_path']);
            $extracted_text = $ocr_result['text'];
        }
    } else if (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff'])) {
        $ocr_result = performOCR($file['original_file_path']);
        $extracted_text = $ocr_result['text'];
    }

    if ($extracted_text && strlen(trim($extracted_text)) > 10) {
        $analysis = analyzeWithClaude($extracted_text, $file['file_name']);
        $new_subject = $analysis['subject'];

        if (!empty($file['summary_file_path']) && file_exists($file['summary_file_path'])) {
            file_put_contents($file['summary_file_path'], $analysis['summary']);
        }

        if ($new_subject !== $file['subject']) {
            $new_subject_path = getUserUploadPath($user_id, $new_subject);
            if (!file_exists($new_subject_path)) {
                mkdir($new_subject_path, 0755, true);
            }

            $new_file_path = $new_subject_path . basename($file['original_file_path']);
            if (rename($file['original_file_path'], $new_file_path)) {
                $update_sql = "UPDATE user_files SET subject = ?, original_file_path = ? WHERE id = ?";
                $update_stmt = $pdo->prepare($update_sql);
                $update_stmt->execute([$new_subject, $new_file_path, $file_id]);
            }
        }

        return true;
    }

    return false;
}

// Create upload directory if it doesn't exist
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

// Create user folder structure when user accesses the system
if (isset($_SESSION['user_id'])) {
    createUserFolderStructure($_SESSION['user_id']);
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['uploaded_file'])) {
    $uploaded_file = $_FILES['uploaded_file'];

    if ($uploaded_file['error'] === UPLOAD_ERR_OK) {
        $processing_result = processUploadedFile($uploaded_file);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
        exit();
    } else {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
        exit();
    }
}

// Get current tab and selected subject
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';
$selected_subject = isset($_GET['subject']) ? $_GET['subject'] : null;

// Get user files from database
$user_files = getUserFiles($_SESSION['user_id']);

// Define subjects with their counts
$subjects = [
    'Physics' => ['name' => 'Physics', 'color' => 'bg-physics', 'count' => 0],
    'Biology' => ['name' => 'Biology', 'color' => 'bg-biology', 'count' => 0],
    'Chemistry' => ['name' => 'Chemistry', 'color' => 'bg-chemistry', 'count' => 0],
    'Mathematics' => ['name' => 'Mathematics', 'color' => 'bg-mathematics', 'count' => 0],
    'Others' => ['name' => 'Others', 'color' => 'bg-others', 'count' => 0]
];

// Count files by subject from database
foreach ($user_files as $file) {
    $fileSubject = isset($file['subject']) ? trim($file['subject']) : 'Others';
    if (array_key_exists($fileSubject, $subjects)) {
        $subjects[$fileSubject]['count']++;
    } else {
        $subjects['Others']['count']++;
    }
}

// Convert back to indexed array for easier iteration
$subjects = array_values($subjects);

// Get recent files (first 5 for dashboard)
$recent_files = array_slice($user_files, 0, 5);

// Filter files by subject if selected
$filtered_files = $user_files;
if ($selected_subject && !empty($selected_subject)) {
    $filtered_files = array_filter($user_files, function ($file) use ($selected_subject) {
        $fileSubject = isset($file['subject']) ? trim($file['subject']) : 'Others';
        return $fileSubject === $selected_subject;
    });
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
        /* Custom color scheme based on provided palette */
        :root {
            --color-primary-dark: #363635;
            /* Dark gray */
            --color-primary-medium: #59544A;
            /* Medium gray */
            --color-accent-bright: #B0FE76;
            /* Bright green */
            --color-accent-medium: #81F979;
            /* Medium green */
            --color-accent-light: #8FBB99;
            /* Light green */

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

        /* Custom themed colors */
        .bg-theme-dark {
            background-color: var(--color-primary-dark);
        }

        .bg-theme-medium {
            background-color: var(--color-primary-medium);
        }

        .bg-theme-bright {
            background-color: var(--color-accent-bright);
        }

        .bg-theme-green {
            background-color: var(--color-accent-medium);
        }

        .bg-theme-light {
            background-color: var(--color-accent-light);
        }

        .text-theme-dark {
            color: var(--color-primary-dark);
        }

        .text-theme-medium {
            color: var(--color-primary-medium);
        }

        .text-theme-bright {
            color: var(--color-accent-bright);
        }

        .text-theme-green {
            color: var(--color-accent-medium);
        }

        .text-theme-light {
            color: var(--color-accent-light);
        }

        .border-theme-dark {
            border-color: var(--color-primary-dark);
        }

        .border-theme-medium {
            border-color: var(--color-primary-medium);
        }

        .border-theme-bright {
            border-color: var(--color-accent-bright);
        }

        .border-theme-green {
            border-color: var(--color-accent-medium);
        }

        .border-theme-light {
            border-color: var(--color-accent-light);
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

        /* Responsive font scaling - CHANGED TO DARK GRAY */
        body {
            font-size: calc(1rem * var(--font-scale));
            background-color: var(--color-primary-dark);
            min-height: 100vh;
        }

        /* Custom subject colors using the palette */
        .bg-physics {
            background: linear-gradient(135deg, var(--color-accent-medium), var(--color-accent-bright));
        }

        .bg-biology {
            background: linear-gradient(135deg, var(--color-accent-light), var(--color-accent-medium));
        }

        .bg-chemistry {
            background: linear-gradient(135deg, var(--color-primary-medium), var(--color-accent-light));
        }

        .bg-mathematics {
            background: linear-gradient(135deg, var(--color-accent-bright), var(--color-accent-medium));
        }

        .bg-others {
            background: linear-gradient(135deg, var(--color-primary-dark), var(--color-primary-medium));
        }

        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
        }

        .custom-scrollbar::-webkit-scrollbar-track {
            background: var(--color-accent-light);
            opacity: 0.3;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: var(--color-accent-medium);
            border-radius: 3px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: var(--color-accent-bright);
        }

        .sidebar-transition {
            transition: transform 0.3s ease-in-out, width 0.3s ease-in-out;
        }

        .upload-area:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(54, 54, 53, 0.2);
        }

        .card-hover:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(54, 54, 53, 0.15);
        }

        /* Mobile-specific styles */
        @media (max-width: 768px) {
            .mobile-sidebar {
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                height: 100vh !important;
                z-index: 50 !important;
                width: 80% !important;
                max-width: 320px !important;
                transform: translateX(-100%) !important;
                transition: transform 0.3s ease-in-out !important;
            }

            .mobile-sidebar.active {
                transform: translateX(0) !important;
            }

            .mobile-overlay {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(54, 54, 53, 0.7);
                z-index: 40;
                opacity: 0;
                visibility: hidden;
                transition: opacity 0.3s ease-in-out, visibility 0.3s ease-in-out;
            }

            .mobile-overlay.active {
                opacity: 1 !important;
                visibility: visible !important;
            }

            .mobile-menu-btn.hidden {
                opacity: 0 !important;
                visibility: hidden !important;
                transform: scale(0.8) !important;
                transition: opacity 0.3s ease-in-out, visibility 0.3s ease-in-out, transform 0.3s ease-in-out !important;
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

            .sidebar {
                width: 80% !important;
                max-width: 320px !important;
            }

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

        /* Upload section grid */
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

        .mobile-menu-btn {
            position: relative;
            z-index: 51;
            cursor: pointer;
            user-select: none;
            -webkit-tap-highlight-color: transparent;
            min-height: 44px;
            min-width: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .mobile-menu-btn:active {
            transform: scale(0.95);
        }

        /* Glass morphism effect for cards */
        .glass-card {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        /* Gradient text effect */
        .gradient-text {
            background: linear-gradient(45deg, var(--color-accent-bright), var(--color-accent-medium));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
    </style>
</head>

<body class="overflow-hidden container-responsive">
    <!-- Mobile overlay -->
    <div id="mobileOverlay" class="mobile-overlay no-print"></div>

    <div class="flex h-screen">
        <!-- Sidebar - CHANGED TO GRADIENT BACKGROUND -->
        <div id="sidebar" class="mobile-sidebar shadow-lg border-r border-theme-medium flex flex-col no-print md:relative" style="background: linear-gradient(135deg, var(--color-primary-dark) 0%, var(--color-primary-medium) 100%);">
            <!-- Header -->
            <div class="px-4 sm:px-6 py-4 sm:py-6 border-b border-theme-medium">
                <div class="flex items-center justify-between">
                    <h1 class="text-lg sm:text-xl font-bold text-theme-bright gradient-text">StudyOrganizer</h1>
                    <div class="flex items-center space-x-4 ml-6">
                        <div class="w-6 h-6 sm:w-8 sm:h-8 bg-theme-green rounded-full flex items-center justify-center">
                            <span class="text-white text-xs sm:text-sm font-semibold">
                                <?php echo strtoupper(substr($_SESSION['user_email'], 0, 1)); ?>
                            </span>
                        </div>
                        <!-- Mobile close button -->
                        <button id="mobileCloseBtn" class="md:hidden text-theme-bright hover:text-white p-2 mobile-menu-btn" type="button">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                </div>
                <p class="text-xs sm:text-sm text-theme-light mt-1 truncate">Welcome, <?php echo htmlspecialchars($_SESSION['user_email']); ?></p>
            </div>

            <!-- Navigation -->
            <nav class="flex-1 px-2 sm:px-4 py-4 sm:py-6">
                <div class="space-y-1 sm:space-y-2">
                    <a href="?tab=dashboard" class="flex items-center px-3 sm:px-4 py-2 sm:py-3 rounded-lg transition-colors btn-touch <?php echo $active_tab === 'dashboard' ? 'bg-theme-green text-white' : 'text-theme-light hover:bg-theme-medium'; ?>">
                        <span class="text-lg sm:text-xl mr-2 sm:mr-3">📚</span>
                        <span class="font-medium text-sm sm:text-base">Dashboard</span>
                    </a>
                    <a href="?tab=subjects" class="flex items-center px-3 sm:px-4 py-2 sm:py-3 rounded-lg transition-colors btn-touch <?php echo $active_tab === 'subjects' ? 'bg-theme-green text-white' : 'text-theme-light hover:bg-theme-medium'; ?>">
                        <span class="text-lg sm:text-xl mr-2 sm:mr-3">📁</span>
                        <span class="font-medium text-sm sm:text-base">All Subjects</span>
                    </a>
                </div>

                <!-- Subjects Quick Access -->
                <div class="mt-6 sm:mt-8">
                    <h3 class="px-3 sm:px-4 text-xs font-semibold text-theme-bright uppercase tracking-wider mb-2 sm:mb-3">Quick Access</h3>
                    <div class="space-y-1">
                        <?php foreach ($subjects as $subject): ?>
                            <a href="?tab=subjects&subject=<?php echo urlencode($subject['name']); ?>"
                                class="flex items-center justify-between px-3 sm:px-4 py-2 text-xs sm:text-sm text-theme-light hover:bg-theme-medium rounded-lg transition-colors btn-touch">
                                <div class="flex items-center">
                                    <span class="mr-2 sm:mr-3 text-sm sm:text-base"><?php echo getSubjectIcon($subject['name']); ?></span>
                                    <span class="truncate"><?php echo $subject['name']; ?></span>
                                </div>
                                <span class="text-xs bg-theme-green text-theme-dark px-2 py-1 rounded-full flex-shrink-0"><?php echo $subject['count']; ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </nav>

            <!-- Footer with Logout -->
            <div class="px-2 sm:px-4 py-3 sm:py-4 border-t border-theme-medium">
                <a href="?action=logout" class="w-full flex items-center px-3 sm:px-4 py-2 text-xs sm:text-sm text-red-400 hover:bg-theme-medium rounded-lg transition-colors btn-touch">
                    <span class="text-base sm:text-lg mr-2 sm:mr-3">🚪</span>
                    <span>Logout</span>
                </a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col overflow-hidden main-content-mobile">
            <!-- Top Bar -->
            <div class="glass-card px-4 sm:px-6 lg:px-8 py-3 sm:py-4 border-b border-theme-light flex items-center justify-between no-print">
                <div class="flex items-center">
                    <!-- Mobile menu button -->
                    <button id="mobileMenuBtn" class="md:hidden mr-3 text-white hover:text-theme-bright p-2 mobile-menu-btn focus:outline-none focus:ring-2 focus:ring-theme-green focus:ring-opacity-50 rounded" type="button">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                    </button>
                    <div>
                        <h2 class="text-xl sm:text-2xl font-bold text-white">
                            <?php if ($active_tab === 'dashboard'): ?>
                                Welcome Back!
                            <?php elseif ($selected_subject): ?>
                                <?php echo htmlspecialchars($selected_subject); ?> Files
                            <?php else: ?>
                                All Subjects
                            <?php endif; ?>
                        </h2>
                        <p class="text-gray-300 mt-1 text-sm sm:text-base">
                            <?php if ($active_tab === 'dashboard'): ?>
                                Manage your study documents with AI-powered organization
                            <?php else: ?>
                                <?php echo count($filtered_files); ?> files found
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <div class="flex items-center space-x-2 sm:space-x-4">
                    <button class="px-2 sm:px-4 py-2 bg-theme-light hover:bg-theme-green text-white rounded-lg transition-colors hidden sm:block btn-touch">
                        <span class="text-sm sm:text-base">⚙️ Settings</span>
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
                            <div class="glass-card rounded-xl sm:rounded-2xl card-padding border-theme-bright">
                                <h3 class="text-xl sm:text-2xl font-semibold text-white mb-4 sm:mb-6 flex items-center">
                                    <span class="mr-2 sm:mr-3 text-lg sm:text-xl">📤</span>
                                    <span class="text-base sm:text-xl gradient-text">Upload Documents</span>
                                </h3>
                                <div class="upload-grid mb-4 sm:mb-6">
                                    <form method="post" enctype="multipart/form-data">
                                        <label class="cursor-pointer block">
                                            <input type="file" name="uploaded_file" accept=".pdf" class="hidden" />
                                            <div class="upload-area glass-card rounded-lg sm:rounded-xl p-4 sm:p-6 text-center hover:shadow-lg transition-all duration-300 border-2 border-dashed border-theme-bright hover:border-theme-green btn-touch">
                                                <div class="text-red-400 text-2xl sm:text-4xl mb-2 sm:mb-3">📄</div>
                                                <span class="text-base sm:text-lg font-medium text-white">PDF Files</span>
                                                <p class="text-xs sm:text-sm text-gray-300 mt-1 sm:mt-2">Click to upload</p>
                                            </div>
                                        </label>
                                    </form>
                                    <form method="post" enctype="multipart/form-data">
                                        <label class="cursor-pointer block">
                                            <input type="file" name="uploaded_file" accept="image/*" class="hidden" />
                                            <div class="upload-area glass-card rounded-lg sm:rounded-xl p-4 sm:p-6 text-center hover:shadow-lg transition-all duration-300 border-2 border-dashed border-theme-bright hover:border-theme-green btn-touch">
                                                <div class="text-theme-green text-2xl sm:text-4xl mb-2 sm:mb-3">🖼️</div>
                                                <span class="text-base sm:text-lg font-medium text-white">Images</span>
                                                <p class="text-xs sm:text-sm text-gray-300 mt-1 sm:mt-2">JPG, PNG, etc.</p>
                                            </div>
                                        </label>
                                    </form>
                                </div>
                                <div class="bg-theme-bright rounded-lg p-3 sm:p-4 border-l-4 border-theme-green">
                                    <p class="text-black font-medium text-sm sm:text-base">💡 AI-Powered Organization</p>
                                    <p class="text-black text-xs sm:text-sm mt-1">Files are automatically categorized by subject and summarized using advanced AI</p>
                                </div>
                            </div>

                            <!-- Subject Overview Grid -->
                            <div class="glass-card rounded-xl sm:rounded-2xl card-padding shadow-sm border-theme-light">
                                <h3 class="text-lg sm:text-xl font-semibold text-white mb-4 sm:mb-6">Subject Overview</h3>
                                <div class="responsive-grid-small">
                                    <?php foreach ($subjects as $index => $subject): ?>
                                        <a href="?tab=subjects&subject=<?php echo urlencode($subject['name']); ?>"
                                            class="card-hover <?php echo $subject['color']; ?> rounded-lg sm:rounded-xl p-4 sm:p-6 border border-theme-light transition-all duration-300 block group btn-touch">
                                            <div class="flex items-center justify-between mb-3 sm:mb-4">
                                                <div class="w-10 h-10 sm:w-12 sm:h-12 bg-white bg-opacity-20 rounded-lg sm:rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform">
                                                    <span class="text-white text-lg sm:text-xl"><?php echo getSubjectIcon($subject['name']); ?></span>
                                                </div>
                                                <span class="text-2xl sm:text-3xl font-bold text-theme-dark"><?php echo $subject['count']; ?></span>
                                            </div>
                                            <p class="font-semibold <?php echo in_array($subject['name'], ['Physics', 'Mathematics', 'Biology']) ? 'text-theme-dark' : 'text-white'; ?> text-base sm:text-lg"><?php echo htmlspecialchars($subject['name']); ?></p>
                                            <p class="text-white text-opacity-80 text-sm sm:text-base">files stored</p>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Right Column - Recent Files -->
                        <div class="space-y-4 sm:space-y-6 lg:space-y-8">
                            <div class="glass-card rounded-xl sm:rounded-2xl p-4 sm:p-6 shadow-sm border-theme-light">
                                <h3 class="text-lg sm:text-xl font-semibold text-white mb-4 sm:mb-6">Recent Files</h3>
                                <div class="space-y-3 sm:space-y-4">
                                    <?php if (empty($recent_files)): ?>
                                        <div class="text-center py-6 sm:py-8">
                                            <div class="text-gray-400 text-3xl sm:text-4xl mb-2 sm:mb-3">📂</div>
                                            <p class="text-gray-300 text-sm sm:text-base">No files uploaded yet</p>
                                            <p class="text-gray-400 text-xs sm:text-sm">Upload your first document to get started</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($recent_files as $file): ?>
                                            <div class="flex items-center justify-between p-3 sm:p-4 bg-white bg-opacity-20 rounded-lg sm:rounded-xl hover:bg-opacity-30 transition-colors cursor-pointer btn-touch file-card" data-file-id="<?php echo $file['id']; ?>">
                                                <div class="flex items-center space-x-3 sm:space-x-4 flex-1 min-w-0">
                                                    <div class="w-10 h-10 sm:w-12 sm:h-12 bg-theme-green rounded-lg flex items-center justify-center flex-shrink-0">
                                                        <span class="text-white text-lg sm:text-xl">📄</span>
                                                    </div>
                                                    <div class="flex-1 min-w-0">
                                                        <p class="font-medium text-white truncate text-sm sm:text-base"><?php echo htmlspecialchars($file['name']); ?></p>
                                                        <p class="text-xs sm:text-sm text-gray-300"><?php echo htmlspecialchars($file['subject']); ?> • <?php echo $file['date']; ?></p>
                                                        <div class="mt-1">
                                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-theme-green text-white">
                                                                ✅ Ready
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <button class="file-menu-btn text-gray-300 hover:text-white p-2 flex-shrink-0 ml-2 btn-touch focus:outline-none focus:ring-2 focus:ring-theme-green focus:ring-opacity-50 rounded" data-file-id="<?php echo $file['id']; ?>">
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
                                        <a href="?tab=subjects" class="text-theme-green hover:text-theme-bright font-medium text-sm sm:text-base">View All Files →</a>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Quick Stats -->
                            <div class="glass-card rounded-xl sm:rounded-2xl p-4 sm:p-6 shadow-sm border-theme-light">
                                <h3 class="text-lg sm:text-xl font-semibold text-white mb-4 sm:mb-6">Quick Stats</h3>
                                <div class="space-y-3 sm:space-y-4">
                                    <div class="flex items-center justify-between p-3 bg-theme-bright bg-opacity-30 rounded-lg">
                                        <div class="flex items-center">
                                            <div class="w-6 h-6 sm:w-8 sm:h-8 bg-theme-green rounded-lg flex items-center justify-center mr-2 sm:mr-3">
                                                <span class="text-white text-xs sm:text-sm">📊</span>
                                            </div>
                                            <span class="font-medium text-black text-sm sm:text-base">Total Files</span>
                                        </div>
                                        <span class="text-xl sm:text-2xl font-bold text-black"><?php echo count($user_files); ?></span>
                                    </div>
                                    <div class="flex items-center justify-between p-3 bg-theme-green bg-opacity-30 rounded-lg">
                                        <div class="flex items-center">
                                            <div class="w-6 h-6 sm:w-8 sm:h-8 bg-theme-medium rounded-lg flex items-center justify-center mr-2 sm:mr-3">
                                                <span class="text-white text-xs sm:text-sm">🤖</span>
                                            </div>
                                            <span class="font-medium text-black text-sm sm:text-base">AI Processed</span>
                                        </div>
                                        <span class="text-xl sm:text-2xl font-bold text-black"><?php echo count($user_files); ?></span>
                                    </div>
                                    <div class="flex items-center justify-between p-3 bg-theme-light bg-opacity-30 rounded-lg">
                                        <div class="flex items-center">
                                            <div class="w-6 h-6 sm:w-8 sm:h-8 bg-theme-dark rounded-lg flex items-center justify-center mr-2 sm:mr-3">
                                                <span class="text-white text-xs sm:text-sm">📚</span>
                                            </div>
                                            <span class="font-medium text-black text-sm sm:text-base">Subjects</span>
                                        </div>
                                        <span class="text-xl sm:text-2xl font-bold text-black">
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
                        <div class="glass-card rounded-lg sm:rounded-xl p-4 sm:p-6 shadow-sm border-theme-light">
                            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between space-y-4 sm:space-y-0">
                                <div class="flex flex-col sm:flex-row items-start sm:items-center space-y-2 sm:space-y-0 sm:space-x-4 w-full sm:w-auto">
                                    <?php if ($selected_subject): ?>
                                        <a href="?tab=subjects" class="text-theme-green hover:text-theme-bright font-medium text-sm sm:text-base btn-touch">← All Subjects</a>
                                        <span class="text-gray-300 hidden sm:block">|</span>
                                    <?php endif; ?>
                                    <div class="flex flex-col sm:flex-row items-start sm:items-center space-y-2 sm:space-y-0 sm:space-x-2 w-full sm:w-auto">
                                        <span class="text-gray-300 text-sm sm:text-base">Filter by:</span>
                                        <div class="flex flex-wrap gap-2">
                                            <a href="?tab=subjects" class="px-3 py-1 rounded-full text-xs sm:text-sm <?php echo !$selected_subject ? 'bg-theme-green text-white' : 'bg-theme-light text-black hover:bg-theme-medium'; ?> transition-colors btn-touch">
                                                All
                                            </a>
                                            <?php foreach ($subjects as $index => $subject): ?>
                                                <?php if ($subject['count'] > 0): ?>
                                                    <a href="?tab=subjects&subject=<?php echo urlencode($subject['name']); ?>"
                                                        class="px-3 py-1 rounded-full text-xs sm:text-sm <?php echo $selected_subject === $subject['name'] ? 'bg-theme-green text-white' : 'bg-theme-light text-black hover:bg-theme-medium'; ?> transition-colors btn-touch">
                                                        <?php echo htmlspecialchars($subject['name']); ?> (<?php echo $subject['count']; ?>)
                                                    </a>
                                                <?php else: ?>
                                                    <span class="px-3 py-1 rounded-full text-xs sm:text-sm bg-gray-50 text-gray-400 cursor-not-allowed opacity-60">
                                                        <?php echo htmlspecialchars($subject['name']); ?> (0)
                                                    </span>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2 sm:space-x-3 w-full sm:w-auto">
                                    <button class="px-3 sm:px-4 py-2 bg-theme-light hover:bg-theme-medium text-white rounded-lg transition-colors flex-1 sm:flex-none btn-touch">
                                        <span class="text-xs sm:text-sm">🔄 Refresh</span>
                                    </button>
                                    <form method="post" enctype="multipart/form-data" class="inline flex-1 sm:flex-none">
                                        <label class="cursor-pointer w-full sm:w-auto">
                                            <input type="file" name="uploaded_file" accept=".pdf,image/*" class="hidden" />
                                            <div class="px-3 sm:px-4 py-2 bg-theme-green hover:bg-theme-bright text-white rounded-lg transition-colors cursor-pointer text-center btn-touch">
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
                                    <h3 class="text-lg sm:text-xl font-semibold text-white mb-2">No files found</h3>
                                    <p class="text-gray-300 mb-4 sm:mb-6 text-sm sm:text-base">
                                        <?php echo $selected_subject ? "No files in " . htmlspecialchars($selected_subject) . " subject yet." : "Upload your first document to get started."; ?>
                                    </p>
                                    <form method="post" enctype="multipart/form-data" class="inline">
                                        <label class="cursor-pointer">
                                            <input type="file" name="uploaded_file" accept=".pdf,image/*" class="hidden" />
                                            <div class="px-4 sm:px-6 py-2 sm:py-3 bg-theme-green hover:bg-theme-bright text-white rounded-lg transition-colors cursor-pointer inline-flex items-center btn-touch">
                                                <span class="mr-2">📤</span>
                                                <span class="text-sm sm:text-base">Upload Your First File</span>
                                            </div>
                                        </label>
                                    </form>
                                </div>
                            <?php else: ?>
                                <?php foreach ($filtered_files as $file): ?>
                                    <div class="glass-card rounded-lg sm:rounded-xl p-4 sm:p-6 shadow-sm border-theme-light hover:shadow-md transition-all duration-300 cursor-pointer card-hover btn-touch file-card" data-file-id="<?php echo $file['id']; ?>">
                                        <div class="flex items-start justify-between mb-3 sm:mb-4">
                                            <div class="flex items-center space-x-3 flex-1 min-w-0">
                                                <div class="w-12 h-12 sm:w-14 sm:h-14 bg-theme-green bg-opacity-20 rounded-lg sm:rounded-xl flex items-center justify-center flex-shrink-0">
                                                    <span class="text-theme-green text-xl sm:text-2xl">📄</span>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <h4 class="font-semibold text-white text-base sm:text-lg truncate"><?php echo htmlspecialchars($file['name']); ?></h4>
                                                    <p class="text-xs sm:text-sm text-gray-300"><?php echo htmlspecialchars($file['subject']); ?> • <?php echo $file['date']; ?></p>
                                                </div>
                                            </div>
                                            <button class="file-menu-btn text-gray-300 hover:text-white p-2 flex-shrink-0 btn-touch focus:outline-none focus:ring-2 focus:ring-theme-green focus:ring-opacity-50 rounded" data-file-id="<?php echo $file['id']; ?>">
                                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                    <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z" />
                                                </svg>
                                            </button>
                                        </div>

                                        <div class="bg-theme-bright bg-opacity-20 rounded-lg p-3 sm:p-4 mb-3 sm:mb-4">
                                            <p class="text-xs sm:text-sm text-black font-medium mb-1 sm:mb-2">AI Summary:</p>
                                            <p class="text-xs sm:text-sm text-black line-clamp-3">
                                                <?php
                                                if (!empty($file['summary_file_path']) && file_exists($file['summary_file_path'])) {
                                                    echo htmlspecialchars(substr(file_get_contents($file['summary_file_path']), 0, 200) . '...');
                                                } else {
                                                    echo htmlspecialchars($file['summary'] ?? 'No summary available');
                                                }
                                                ?>
                                            </p>
                                        </div>

                                        <div class="flex items-center justify-between">
                                            <div>
                                                <span class="inline-flex items-center px-2 sm:px-3 py-1 rounded-full text-xs bg-theme-green bg-opacity-20 text-gray-700">
                                                    ✅ Analyzed
                                                </span>
                                            </div>
                                            <button class="file-details-btn text-theme-green hover:text-theme-bright text-xs sm:text-sm font-medium btn-touch" data-file-id="<?php echo $file['id']; ?>">
                                                View Details →
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- UPDATED File Details Modal -->
    <div id="fileDetailsModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-2 sm:p-4 no-print">
        <div class="glass-card rounded-xl sm:rounded-2xl max-w-4xl w-full max-h-[95vh] sm:max-h-[90vh] overflow-hidden border-theme-light">
            <!-- Modal Header -->
            <div class="flex items-center justify-between p-4 sm:p-6 border-b border-theme-light">
                <div class="flex-1 min-w-0">
                    <h3 id="modalFileName" class="text-lg sm:text-xl font-semibold text-white truncate"></h3>
                    <p id="modalSubject" class="text-gray-300 text-sm sm:text-base"></p>
                </div>
                <button onclick="closeFileDetails()" class="text-gray-300 hover:text-white text-xl sm:text-2xl ml-4 btn-touch focus:outline-none focus:ring-2 focus:ring-theme-green focus:ring-opacity-50 rounded p-2">&times;</button>
            </div>

            <!-- Modal Tabs -->
            <div class="border-b border-theme-light">
                <div class="flex">
                    <button id="summaryTab" onclick="switchTab('summary')" class="flex-1 py-3 sm:py-4 px-4 sm:px-6 text-xs sm:text-sm font-medium text-theme-green border-b-2 border-theme-green btn-touch focus:outline-none">
                        📝 Summary & Details
                    </button>
                    <button id="fullTextTab" onclick="switchTab('fulltext')" class="flex-1 py-3 sm:py-4 px-4 sm:px-6 text-xs sm:text-sm font-medium text-gray-300 hover:text-white btn-touch focus:outline-none">
                        👁️ View Original File
                    </button>
                </div>
            </div>

            <!-- Modal Content -->
            <div class="p-4 sm:p-6 max-h-[50vh] sm:max-h-[60vh] overflow-auto custom-scrollbar">
                <!-- Summary Tab -->
                <div id="summaryContent" class="tab-content">
                    <div class="space-y-4 sm:space-y-6">
                        <div>
                            <h4 class="font-semibold text-white mb-3 text-sm sm:text-base flex items-center">
                                <span class="mr-2">🤖</span>AI Summary
                            </h4>
                            <div class="bg-theme-bright bg-opacity-20 rounded-lg p-3 sm:p-4 border-l-4 border-theme-green">
                                <p id="modalSummary" class="text-black text-sm sm:text-base leading-relaxed"></p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6">
                            <!-- File Information -->
                            <div class="bg-white bg-opacity-10 rounded-lg p-4">
                                <h4 class="font-semibold text-white mb-3 text-sm sm:text-base flex items-center">
                                    <span class="mr-2">📋</span>File Information
                                </h4>
                                <div class="space-y-2 text-xs sm:text-sm">
                                    <div class="flex items-center">
                                        <span class="text-gray-300 w-16">Date:</span>
                                        <span id="modalDate" class="text-white"></span>
                                    </div>
                                    <div class="flex items-center">
                                        <span class="text-gray-300 w-16">Status:</span>
                                        <span id="modalStatus" class="text-white"></span>
                                    </div>
                                    <div class="flex items-center">
                                        <span class="text-gray-300 w-16">ID:</span>
                                        <span id="modalSize" class="text-white"></span>
                                    </div>
                                </div>
                            </div>

                            <!-- File Actions -->
                            <div class="bg-white bg-opacity-10 rounded-lg p-4">
                                <h4 class="font-semibold text-white mb-3 text-sm sm:text-base flex items-center">
                                    <span class="mr-2">⚡</span>Quick Actions
                                </h4>
                                <div class="space-y-2">
                                    <button id="downloadSummaryBtn" class="w-full px-3 sm:px-4 py-2 bg-theme-green hover:bg-theme-bright text-gray-800 rounded-lg transition-colors text-xs sm:text-sm btn-touch font-medium flex items-center justify-center">
                                        <span class="mr-2">📥</span>Download Summary
                                    </button>
                                    <button id="reanalyzeBtn" class="w-full px-3 sm:px-4 py-2 bg-theme-medium hover:bg-theme-dark text-white rounded-lg transition-colors text-xs sm:text-sm btn-touch font-medium flex items-center justify-center">
                                        <span class="mr-2">🔄</span>Re-analyze with AI
                                    </button>
                                    <button id="deleteBtn" class="w-full px-3 sm:px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded-lg transition-colors text-xs sm:text-sm btn-touch font-medium flex items-center justify-center">
                                        <span class="mr-2">🗑️</span>Delete File
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- View Original File Tab - SIMPLIFIED -->
                <div id="fulltextContent" class="tab-content hidden">
                    <div id="filePreviewContainer" class="w-full">
                        <!-- This will be populated by JavaScript when the tab is clicked -->
                        <div class="text-center py-8">
                            <div class="text-theme-medium text-4xl mb-4">📄</div>
                            <p class="text-white mb-4">Switch to this tab to view the original file</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Store file data globally
        const fileData = <?php echo json_encode($user_files); ?>;
        console.log('File data loaded:', fileData);

        // Global variable to store current file ID
        let currentModalFileId = null;

        function getCurrentFileId() {
            return currentModalFileId;
        }

        // UPDATED IMPROVED FUNCTIONS
        function switchTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.add('hidden');
            });

            // Remove active styles from all tabs
            const tabs = ['summaryTab', 'fullTextTab'];
            tabs.forEach(tabId => {
                const tab = document.getElementById(tabId);
                if (tab) {
                    tab.className = 'flex-1 py-3 sm:py-4 px-4 sm:px-6 text-xs sm:text-sm font-medium text-gray-300 hover:text-white btn-touch focus:outline-none';
                }
            });

            // Show selected tab content and apply active styles
            const contentMap = {
                'summary': 'summaryContent',
                'fulltext': 'fulltextContent'
            };

            const tabMap = {
                'summary': 'summaryTab',
                'fulltext': 'fullTextTab'
            };

            if (contentMap[tabName]) {
                document.getElementById(contentMap[tabName]).classList.remove('hidden');

                // If switching to fulltext tab, automatically load the file options
                if (tabName === 'fulltext') {
                    loadFileOptions();
                }
            }

            if (tabMap[tabName]) {
                document.getElementById(tabMap[tabName]).className = 'flex-1 py-3 sm:py-4 px-4 sm:px-6 text-xs sm:text-sm font-medium text-theme-green border-b-2 border-theme-green btn-touch focus:outline-none';
            }
        }

        function loadFileOptions() {
            const container = document.getElementById('filePreviewContainer');
            const currentFileId = getCurrentFileId();

            if (!currentFileId) {
                container.innerHTML = `
                    <div class="text-center">
                        <div class="text-red-400 text-4xl mb-4">❌</div>
                        <p class="text-white">File not found</p>
                    </div>
                `;
                return;
            }

            // Get file info from our global fileData
            const file = fileData.find(f => f.id == currentFileId);
            const fileName = file ? file.name : 'Unknown File';
            const fileExtension = fileName.split('.').pop().toLowerCase();

            // Create simple, clear options
            container.innerHTML = `
                <div class="space-y-6">
                    <div class="text-center">
                        <div class="text-theme-green text-5xl mb-3">📄</div>
                        <h4 class="text-white text-lg font-semibold">${fileName}</h4>
                        <p class="text-gray-300 text-sm">Choose how you want to view this file</p>
                    </div>
                    
                    <!-- Primary Actions -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- View in New Tab -->
                        <div class="bg-theme-bright bg-opacity-20 rounded-lg p-4 text-center hover:bg-opacity-30 transition-all">
                            <div class="text-theme-green text-3xl mb-3">🔗</div>
                            <h5 class="text-white font-semibold mb-2">Open in New Tab</h5>
                            <p class="text-gray-300 text-sm mb-4">Best for viewing and zooming</p>
                            <button onclick="viewFileInBrowser(${currentFileId})" 
                                    class="w-full px-4 py-2 bg-theme-green hover:bg-theme-bright text-white rounded-lg transition-colors btn-touch font-medium">
                                Open File →
                            </button>
                        </div>
                        
                        <!-- Download -->
                        <div class="bg-theme-light bg-opacity-20 rounded-lg p-4 text-center hover:bg-opacity-30 transition-all">
                            <div class="text-theme-medium text-3xl mb-3">💾</div>
                            <h5 class="text-white font-semibold mb-2">Download</h5>
                            <p class="text-gray-300 text-sm mb-4">Save to your device</p>
                            <button onclick="downloadFile(${currentFileId})" 
                                    class="w-full px-4 py-2 bg-theme-medium hover:bg-theme-dark text-white rounded-lg transition-colors btn-touch font-medium">
                                Download ⬇️
                            </button>
                        </div>
                    </div>
                    
                    <!-- Preview Option (only for supported file types) -->
                    ${(fileExtension === 'pdf' || ['jpg', 'jpeg', 'png', 'gif'].includes(fileExtension)) ? `
                    <div class="border-t border-theme-light pt-4">
                        <div class="bg-theme-green bg-opacity-10 rounded-lg p-4">
                            <div class="flex items-center justify-between mb-3">
                                <div>
                                    <h5 class="text-white font-semibold flex items-center">
                                        <span class="mr-2">👁️</span>Quick Preview
                                    </h5>
                                    <p class="text-gray-300 text-sm">Preview the file below (may take a moment to load)</p>
                                </div>
                                <button onclick="showInlinePreview(${currentFileId})" 
                                        class="px-4 py-2 bg-theme-green hover:bg-theme-bright text-white rounded-lg transition-colors btn-touch font-medium">
                                    Show Preview
                                </button>
                            </div>
                        </div>
                    </div>
                    ` : `
                    <div class="border-t border-theme-light pt-4">
                        <div class="bg-yellow-500 bg-opacity-10 rounded-lg p-4 text-center">
                            <div class="text-yellow-400 text-2xl mb-2">ℹ️</div>
                            <p class="text-yellow-200 text-sm">
                                This file type (${fileExtension.toUpperCase()}) cannot be previewed inline. 
                                Use "Open in New Tab" or "Download" to view it.
                            </p>
                        </div>
                    </div>
                    `}
                </div>
            `;
        }

        function viewFileInBrowser(fileId) {
            // Open file in new tab/window
            window.open(`?action=view_original&file_id=${fileId}`, '_blank');
        }

        function downloadFile(fileId) {
            // Create a temporary link to trigger download
            const link = document.createElement('a');
            link.href = `?action=view_original&file_id=${fileId}`;
            link.download = ''; // This will suggest download
            link.target = '_blank';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        function showInlinePreview(fileId) {
            const container = document.getElementById('filePreviewContainer');

            // Show loading state
            container.innerHTML = `
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <h5 class="text-white font-semibold">File Preview</h5>
                        <button onclick="loadFileOptions()" 
                                class="px-3 py-1 bg-theme-medium hover:bg-theme-dark text-white rounded transition-colors text-sm btn-touch">
                            ← Back to Options
                        </button>
                    </div>
                    <div class="border border-theme-light rounded-lg overflow-hidden">
                        <div id="iframeContainer" class="w-full h-96 flex items-center justify-center bg-gray-100" style="min-height: 500px;">
                            <div class="text-center">
                                <div class="animate-spin rounded-full h-12 w-12 border-b-4 border-theme-green mx-auto mb-4"></div>
                                <p class="text-gray-600">Loading preview...</p>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            // Create iframe properly with JavaScript
            const iframeContainer = document.getElementById('iframeContainer');
            const iframe = document.createElement('iframe');

            // Set iframe properties
            iframe.src = `?action=view_original&file_id=${fileId}`;
            iframe.className = 'w-full h-full';
            iframe.style.minHeight = '500px';
            iframe.style.border = 'none';

            // Set up load timeout
            const loadTimeout = setTimeout(() => {
                handleIframeError(fileId);
            }, 10000); // 10 second timeout

            // Handle successful load
            iframe.onload = function() {
                clearTimeout(loadTimeout);
                try {
                    // Check if iframe has content
                    const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                    if (iframeDoc.body && iframeDoc.body.innerHTML.trim() === '') {
                        // Empty body might indicate an error
                        setTimeout(() => {
                            if (iframeDoc.body && iframeDoc.body.innerHTML.trim() === '') {
                                handleIframeError(fileId);
                            }
                        }, 2000);
                    }
                } catch (e) {
                    // Cross-origin or other access issues - this is actually normal for file serving
                    console.log('Iframe loaded (cross-origin, which is expected)');
                }
            };

            // Handle load error
            iframe.onerror = function() {
                clearTimeout(loadTimeout);
                handleIframeError(fileId);
            };

            // Replace loading content with iframe
            iframeContainer.innerHTML = '';
            iframeContainer.appendChild(iframe);
        }

        function handleIframeError(fileId) {
            // Iframe failed to load, show alternative options
            const container = document.getElementById('filePreviewContainer');
            container.innerHTML = `
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <h5 class="text-white font-semibold">File Preview</h5>
                        <button onclick="loadFileOptions()" 
                                class="px-3 py-1 bg-theme-medium hover:bg-theme-dark text-white rounded transition-colors text-sm btn-touch">
                            ← Back to Options
                        </button>
                    </div>
                    <div class="text-center space-y-4 py-8">
                        <div class="text-yellow-400 text-4xl mb-4">⚠️</div>
                        <h5 class="text-white font-semibold">Preview timeout or error</h5>
                        <p class="text-gray-300 text-sm">The file is taking too long to load or cannot be previewed inline</p>
                        <div class="flex flex-col sm:flex-row gap-4 justify-center max-w-md mx-auto">
                            <button onclick="viewFileInBrowser(${fileId})" 
                                    class="px-4 py-2 bg-theme-green hover:bg-theme-bright text-white rounded-lg transition-colors btn-touch">
                                Open in New Tab
                            </button>
                            <button onclick="downloadFile(${fileId})" 
                                    class="px-4 py-2 bg-theme-medium hover:bg-theme-dark text-white rounded-lg transition-colors btn-touch">
                                Download File
                            </button>
                        </div>
                    </div>
                </div>
            `;
        }

        // Mobile sidebar functionality
        function toggleMobileSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('mobileOverlay');
            const menuBtn = document.getElementById('mobileMenuBtn');

            if (!sidebar || !overlay || !menuBtn) {
                console.error('Required elements not found');
                return;
            }

            const isActive = sidebar.classList.contains('active');

            if (isActive) {
                // Closing sidebar
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                menuBtn.classList.remove('hidden');
                document.body.style.overflow = '';
                console.log('Sidebar closed');
            } else {
                // Opening sidebar
                sidebar.classList.add('active');
                overlay.classList.add('active');
                menuBtn.classList.add('hidden');
                document.body.style.overflow = 'hidden';
                console.log('Sidebar opened');
            }
        }

        // File modal functions
        function openFileDetails(fileId) {
            console.log('Opening file details for ID:', fileId);

            // Store the current file ID globally
            currentModalFileId = fileId;

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
                document.getElementById('modalSummary').textContent = file.full_summary || 'No summary available';
                document.getElementById('modalDate').textContent = 'Uploaded: ' + (file.date || 'Unknown date');
                document.getElementById('modalStatus').textContent = 'Status: ' + (file.status || 'Completed');
                document.getElementById('modalSize').textContent = 'File ID: ' + file.id;

                // Reset the file preview container to default state
                const filePreviewContainer = document.getElementById('filePreviewContainer');
                if (filePreviewContainer) {
                    filePreviewContainer.innerHTML = `
                        <div class="text-center py-8">
                            <div class="text-theme-medium text-4xl mb-4">📄</div>
                            <p class="text-white mb-4">Switch to this tab to view the original file</p>
                        </div>
                    `;
                }

                // Set up action buttons
                setupModalActions(file.id);

                // Reset to summary tab
                switchTab('summary');

                // Show modal
                document.getElementById('fileDetailsModal').classList.remove('hidden');
                document.body.style.overflow = 'hidden';

                console.log('Modal opened successfully');
            } catch (error) {
                console.error('Error opening modal:', error);
                alert('Error opening file details');
            }
        }

        function closeFileDetails() {
            document.getElementById('fileDetailsModal').classList.add('hidden');
            document.body.style.overflow = '';
        }

        function setupModalActions(fileId) {
            // Download summary button  
            const downloadSummaryBtn = document.getElementById('downloadSummaryBtn');
            if (downloadSummaryBtn) {
                downloadSummaryBtn.onclick = () => {
                    window.location.href = `?action=download_summary&file_id=${fileId}`;
                };
            }

            // Re-analyze button
            const reanalyzeBtn = document.getElementById('reanalyzeBtn');
            if (reanalyzeBtn) {
                reanalyzeBtn.onclick = () => {
                    if (confirm('Are you sure you want to re-analyze this file? This will update the AI summary and may change the subject classification.')) {
                        window.location.href = `?action=reanalyze_file&file_id=${fileId}&tab=<?php echo $active_tab; ?><?php echo $selected_subject ? '&subject=' . urlencode($selected_subject) : ''; ?>`;
                    }
                };
            }

            // Delete button
            const deleteBtn = document.getElementById('deleteBtn');
            if (deleteBtn) {
                deleteBtn.onclick = () => {
                    const fileName = document.getElementById('modalFileName').textContent;
                    if (confirm(`Are you sure you want to delete "${fileName}"? This action cannot be undone.`)) {
                        window.location.href = `?action=delete_file&file_id=${fileId}&tab=<?php echo $active_tab; ?><?php echo $selected_subject ? '&subject=' . urlencode($selected_subject) : ''; ?>`;
                    }
                };
            }
        }

        // Document ready setup
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded, setting up event listeners...');

            // Mobile menu setup
            const menuBtn = document.getElementById('mobileMenuBtn');
            const closeBtn = document.getElementById('mobileCloseBtn');
            const overlay = document.getElementById('mobileOverlay');

            if (menuBtn) {
                menuBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    toggleMobileSidebar();
                });
            }

            if (closeBtn) {
                closeBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    toggleMobileSidebar();
                });
            }

            if (overlay) {
                overlay.addEventListener('click', function() {
                    toggleMobileSidebar();
                });
            }

            // File card click events
            document.querySelectorAll('.file-card').forEach(card => {
                card.addEventListener('click', function(e) {
                    // Don't open modal if clicking on menu button
                    if (e.target.closest('.file-menu-btn')) {
                        return;
                    }

                    const fileId = this.getAttribute('data-file-id');
                    console.log('File card clicked, ID:', fileId);
                    openFileDetails(fileId);
                });
            });

            // File menu button events
            document.querySelectorAll('.file-menu-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const fileId = this.getAttribute('data-file-id');
                    console.log('File menu button clicked, ID:', fileId);
                    openFileDetails(fileId);
                });
            });

            // File details button events
            document.querySelectorAll('.file-details-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const fileId = this.getAttribute('data-file-id');
                    console.log('File details button clicked, ID:', fileId);
                    openFileDetails(fileId);
                });
            });
        });

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
                    const menuBtn = document.getElementById('mobileMenuBtn');
                    if (sidebar && overlay && menuBtn) {
                        sidebar.classList.remove('active');
                        overlay.classList.remove('active');
                        menuBtn.classList.remove('hidden');
                        document.body.style.overflow = '';
                    }
                }
            }
        });

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('mobileOverlay');
                const menuBtn = document.getElementById('mobileMenuBtn');
                if (sidebar && overlay && menuBtn) {
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                    menuBtn.classList.remove('hidden');
                    document.body.style.overflow = '';
                }
            }
        });

        // Show loading state when uploading files
        document.addEventListener('change', function(e) {
            if (e.target.type === 'file' && e.target.files.length > 0) {
                // Show loading overlay
                const overlay = document.createElement('div');
                overlay.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4';
                overlay.innerHTML = `
                    <div class="glass-card rounded-xl sm:rounded-2xl p-6 sm:p-8 text-center max-w-md mx-auto border-theme-light">
                        <div class="animate-spin rounded-full h-12 w-12 sm:h-16 sm:w-16 border-b-4 border-theme-green mx-auto mb-4 sm:mb-6"></div>
                        <h3 class="text-lg sm:text-xl font-semibold text-white mb-2">Processing your file...</h3>
                        <p class="text-gray-300 mb-2 text-sm sm:text-base">OCR and AI analysis in progress</p>
                        <div class="bg-theme-bright bg-opacity-20 rounded-lg p-3 sm:p-4 mt-4">
                            <p class="text-black text-xs sm:text-sm">⚡ This may take a few moments for larger files</p>
                        </div>
                    </div>
                `;
                document.body.appendChild(overlay);

                // Auto-submit the form
                e.target.closest('form').submit();
            }
        });

        // Add smooth transitions for navigation
        document.querySelectorAll('a[href*="tab="]').forEach(link => {
            link.addEventListener('click', function(e) {
                // Close mobile sidebar on navigation
                if (window.innerWidth <= 768) {
                    const sidebar = document.getElementById('sidebar');
                    const overlay = document.getElementById('mobileOverlay');
                    const menuBtn = document.getElementById('mobileMenuBtn');
                    if (sidebar && overlay && menuBtn) {
                        sidebar.classList.remove('active');
                        overlay.classList.remove('active');
                        menuBtn.classList.remove('hidden');
                        document.body.style.overflow = '';
                    }
                }
            });
        });

        // Keyboard shortcuts
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

        // Touch interactions for mobile
        if ('ontouchstart' in window) {
            document.addEventListener('touchstart', function() {}, {
                passive: true
            });
        }

        console.log('All event listeners set up successfully');
    </script>
</body>

</html>
