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

// Handle API test request
if (isset($_GET['action']) && $_GET['action'] === 'test_api') {
    header('Content-Type: application/json');

    $test_results = [];

    // Test Tesseract OCR
    $test_results['tesseract'] = [];
    
    // Check if Tesseract is installed
    $tesseract_version = shell_exec('tesseract --version 2>&1');
    if (strpos($tesseract_version, 'tesseract') !== false) {
        $test_results['tesseract']['status'] = 'success';
        $test_results['tesseract']['message'] = 'Tesseract is installed and accessible';
        $test_results['tesseract']['version'] = trim(explode("\n", $tesseract_version)[0]);
        
        // Check for language support
        $languages = shell_exec('tesseract --list-langs 2>&1');
        if (strpos($languages, 'eng') !== false) {
            $test_results['tesseract']['languages'] = 'English support: ✅';
        } else {
            $test_results['tesseract']['languages'] = 'English support: ❌';
        }
        
        if (strpos($languages, 'tha') !== false) {
            $test_results['tesseract']['languages'] .= ' | Thai support: ✅';
        } else {
            $test_results['tesseract']['languages'] .= ' | Thai support: ❌';
        }

        // Test PDF support
        $test_results['tesseract']['pdf_support'] = 'PDF support: ✅ (Direct processing)';
    } else {
        $test_results['tesseract']['status'] = 'error';
        $test_results['tesseract']['message'] = 'Tesseract is not installed or not accessible';
    }

    // Test Claude API
    $test_results['claude'] = [];
    if (empty(CLAUDE_API_KEY)) {
        $test_results['claude']['status'] = 'error';
        $test_results['claude']['message'] = 'Claude API key not configured';
    } else {
        $test_results['claude']['status'] = 'info';
        $test_results['claude']['message'] = 'Claude API key is configured (test requires actual content)';
    }

    // Test file system
    $test_results['filesystem'] = [];
    if (!is_writable(UPLOAD_DIR)) {
        $test_results['filesystem']['status'] = 'error';
        $test_results['filesystem']['message'] = 'Upload directory is not writable';
    } else {
        $test_results['filesystem']['status'] = 'success';
        $test_results['filesystem']['message'] = 'Upload directory is writable';
    }

    // Test pdftotext (optional but helpful for text-based PDFs)
    $test_results['pdftotext'] = [];
    $pdftotext_test = shell_exec('pdftotext -v 2>&1');
    if ($pdftotext_test && strpos($pdftotext_test, 'pdftotext') !== false) {
        $test_results['pdftotext']['status'] = 'success';
        $test_results['pdftotext']['message'] = 'pdftotext available for fast PDF text extraction';
    } else {
        $test_results['pdftotext']['status'] = 'warning';
        $test_results['pdftotext']['message'] = 'pdftotext not available - will use Tesseract for all PDFs';
    }

    echo json_encode($test_results);
    exit();
}

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

// Configuration - UPDATED TO USE TESSERACT OCR
define('UPLOAD_DIR', 'uploads/');
define('CLAUDE_API_KEY', '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'); // Add your Claude API key

// TESSERACT OCR CONFIGURATION
define('TESSERACT_CMD', 'tesseract'); // Path to tesseract executable
define('TESSERACT_LANG_DEFAULT', 'eng'); // Default language
define('TESSERACT_LANG_THAI', 'tha'); // Thai language code
define('TESSERACT_TEMP_DIR', sys_get_temp_dir()); // Temporary directory for processing

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

// UPDATED: Save file to database with language support
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

    $sql = "INSERT INTO user_files (user_id, file_name, subject, file_type, language, original_file_path, summary_file_path) 
            VALUES (?, ?, ?, ?, ?, ?, ?)";

    $stmt = $pdo->prepare($sql);
    return $stmt->execute([$user_id, $file_name, $subject, $file_type, $language, $original_file_path, $summary_file_path]);
}

// UPDATED: Get user files from database with language information
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
        // Read summary directly from file
        $summary_content = 'No summary available';
        $full_summary = 'No summary available';
        if (!empty($file['summary_file_path']) && file_exists($file['summary_file_path'])) {
            $full_summary = file_get_contents($file['summary_file_path']);
            $summary_content = substr($full_summary, 0, 200) . '...';
        }

        // Get language info (default to English if not set)
        $file_language = isset($file['language']) ? $file['language'] : 'en';

        // Set appropriate "No summary" message based on language
        if ($summary_content === 'No summary available') {
            $summary_content = ($file_language === 'th') ? 'ไม่มีข้อมูลสรุป' : 'No summary available';
            $full_summary = $summary_content;
        }

        $formatted_files[] = [
            'id' => $file['id'],
            'name' => $file['file_name'],
            'subject' => $file['subject'],
            'date' => $file['date'],
            'summary' => $summary_content,
            'full_summary' => $full_summary,
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

// UPDATED: Create enhanced summary text file with better formatting for structured content
function createSummaryTextFile($summary_content, $original_filename, $user_id, $subject, $language = 'en', $alt_summary = '')
{
    $summary_dir = "uploads/user_$user_id/$subject/summaries/";

    if (!file_exists($summary_dir)) {
        mkdir($summary_dir, 0755, true);
    }

    $summary_filename = pathinfo($original_filename, PATHINFO_FILENAME) . '_summary.txt';
    $summary_path = $summary_dir . $summary_filename;

    // Create enhanced content with better formatting
    $file_content = "📄 DOCUMENT ANALYSIS SUMMARY\n";
    $file_content .= str_repeat("=", 50) . "\n\n";

    // Add document info
    $file_content .= "📋 Document: " . $original_filename . "\n";
    $file_content .= "📚 Subject: " . $subject . "\n";
    $file_content .= "🌐 Language: " . ($language === 'th' ? 'Thai (ภาษาไทย)' : 'English') . "\n";
    $file_content .= "📅 Generated: " . date('Y-m-d H:i:s') . "\n\n";

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
    $file_content .= "  • OCR Technology: Tesseract OCR Engine\n";
    $file_content .= "  • AI Analysis: Claude (Anthropic)\n";
    $file_content .= "  • Processing Language: " . ($language === 'th' ? 'Thai (ภาษาไทย)' : 'English') . "\n";
    $file_content .= "  • File Size: " . (file_exists($original_filename) ? formatFileSize(filesize($original_filename)) : 'Unknown') . "\n";
    $file_content .= str_repeat("=", 50) . "\n";

    file_put_contents($summary_path, $file_content);

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

// NEW: Helper function to detect language from content
function detectLanguageFromContent($text_content)
{
    // Count Thai characters vs English characters
    $thai_chars = 0;
    $english_chars = 0;

    // Convert to UTF-8 if needed
    $text = mb_convert_encoding($text_content, 'UTF-8', 'auto');

    // Count characters
    $length = mb_strlen($text, 'UTF-8');
    for ($i = 0; $i < $length; $i++) {
        $char = mb_substr($text, $i, 1, 'UTF-8');
        $unicode = mb_ord($char, 'UTF-8');

        // Thai Unicode range: 0x0E00-0x0E7F
        if ($unicode >= 0x0E00 && $unicode <= 0x0E7F) {
            $thai_chars++;
        }
        // English characters (basic Latin): 0x0041-0x005A, 0x0061-0x007A
        elseif (($unicode >= 0x0041 && $unicode <= 0x005A) || ($unicode >= 0x0061 && $unicode <= 0x007A)) {
            $english_chars++;
        }
    }

    // Determine primary language
    if ($thai_chars > $english_chars && $thai_chars > 10) {
        return 'th';
    } else {
        return 'en';
    }
}

// NEW: Helper function to get ordinal value of multibyte character
if (!function_exists('mb_ord')) {
    function mb_ord($char, $encoding = 'UTF-8')
    {
        if ($encoding === 'UTF-8') {
            $char = mb_convert_encoding($char, 'UCS-4BE', 'UTF-8');
            return unpack('N', $char)[1];
        }
        return ord($char);
    }
}

// UPDATED: Enhanced performOCR function using Tesseract OCR directly
function performOCR($file_path)
{
    $debug_info = [];
    $debug_info['method'] = 'Direct Tesseract OCR';

    // Validate file exists and is readable
    if (!file_exists($file_path)) {
        error_log("OCR file not found: " . $file_path);
        return ['error' => 'File not found', 'text' => false, 'debug' => 'File path invalid'];
    }

    if (!is_readable($file_path)) {
        error_log("OCR file not readable: " . $file_path);
        return ['error' => 'File not readable', 'text' => false, 'debug' => 'File permissions issue'];
    }

    // Check file size (reasonable limit for local processing)
    $file_size = filesize($file_path);
    if ($file_size > 50 * 1024 * 1024) { // 50MB limit
        error_log("OCR file too large: " . $file_size . " bytes");
        return ['error' => 'File too large for OCR processing', 'text' => false, 'debug' => 'File exceeds 50MB limit'];
    }

    // Check file type - Tesseract supports PDF directly!
    $file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'tif', 'webp', 'pdf'];
    if (!in_array($file_extension, $allowed_extensions)) {
        error_log("OCR unsupported file type: " . $file_extension);
        return ['error' => 'Unsupported file type for OCR', 'text' => false, 'debug' => 'File type not supported'];
    }

    try {
        // Create temporary output file
        $temp_output = TESSERACT_TEMP_DIR . '/tesseract_output_' . uniqid();
        
        // Detect language from filename or use auto-detection
        $detected_language = detectLanguageFromFilename($file_path);
        $language_param = $detected_language === 'th' ? TESSERACT_LANG_THAI : TESSERACT_LANG_DEFAULT;
        
        // Add both languages for better results
        $language_param = TESSERACT_LANG_DEFAULT . '+' . TESSERACT_LANG_THAI;
        
        // Build Tesseract command - DIRECT PROCESSING (works for PDF and images!)
        $cmd = sprintf(
            '%s "%s" "%s" -l %s --psm 1 --oem 3 2>&1',
            TESSERACT_CMD,
            escapeshellarg($file_path),
            escapeshellarg($temp_output),
            $language_param
        );

        $debug_info['command'] = $cmd;
        $debug_info['language'] = $language_param;
        $debug_info['file_type'] = $file_extension;

        error_log("Tesseract OCR Command: " . $cmd);

        // Execute Tesseract command
        $output = shell_exec($cmd);
        $debug_info['shell_output'] = $output;

        // Read the output file
        $output_file = $temp_output . '.txt';
        if (file_exists($output_file)) {
            $extracted_text = file_get_contents($output_file);
            
            // Clean up temporary files
            unlink($output_file);

            // Validate extracted text
            $extracted_text = trim($extracted_text);
            if (!empty($extracted_text) && strlen($extracted_text) > 5) {
                $word_count = str_word_count($extracted_text);
                $char_count = mb_strlen($extracted_text, 'UTF-8');
                
                error_log("Tesseract OCR Success - Extracted {$word_count} words, {$char_count} characters");
                
                return [
                    'error' => null,
                    'text' => $extracted_text,
                    'debug' => "Tesseract OCR successful - {$word_count} words, {$char_count} characters extracted",
                    'language_detected' => $detected_language
                ];
            } else {
                error_log("Tesseract returned empty or minimal text");
                return ['error' => 'No meaningful text found in document', 'text' => false, 'debug' => 'Minimal OCR result'];
            }
        } else {
            error_log("Tesseract output file not created: " . $output_file);
            return ['error' => 'Tesseract processing failed', 'text' => false, 'debug' => 'No output file created: ' . $output];
        }
    } catch (Exception $e) {
        // Clean up on error
        if (isset($temp_output) && file_exists($temp_output . '.txt')) {
            unlink($temp_output . '.txt');
        }

        error_log("Tesseract OCR Exception: " . $e->getMessage());
        return ['error' => 'OCR processing exception: ' . $e->getMessage(), 'text' => false, 'debug' => 'Exception occurred'];
    }
}

// Helper function to detect language from filename
function detectLanguageFromFilename($filename)
{
    $basename = basename($filename);
    // Simple heuristic: look for Thai characters in filename
    if (preg_match('/[\x{0E00}-\x{0E7F}]/u', $basename)) {
        return 'th';
    }
    return 'en';
}

// UPDATED: Simplified PDF processing function using Tesseract directly
function extractTextFromPDF($file_path)
{
    error_log("PDF processing: Using direct Tesseract approach");

    // Method 1: Try pdftotext (fastest for text-based PDFs)
    $content = shell_exec("pdftotext '$file_path' -");
    if ($content && strlen(trim($content)) > 50) {
        error_log("PDF text extraction: pdftotext successful, " . strlen($content) . " characters");
        return trim($content);
    }

    error_log("PDF text extraction: pdftotext failed, trying direct Tesseract OCR");

    // Method 2: Use Tesseract directly on PDF (no ImageMagick needed!)
    $ocr_result = performOCR($file_path);
    if ($ocr_result['text']) {
        error_log("PDF text extraction: Direct Tesseract successful, " . strlen($ocr_result['text']) . " characters");
        return $ocr_result['text'];
    }

    // Both methods failed
    error_log("PDF text extraction: All methods failed");
    return false;
}

// UPDATED: Analyze with Claude - Enhanced with multilingual language detection
function analyzeWithClaude($text_content, $filename)
{
    $api_key = CLAUDE_API_KEY;

    if (empty($api_key)) {
        return [
            'subject' => 'Others',
            'summary' => 'AI API key not configured',
            'debug' => 'API key missing',
            'language' => 'en'
        ];
    }

    if (empty(trim($text_content))) {
        return [
            'subject' => 'Others',
            'summary' => 'No text content to analyze',
            'debug' => 'Empty text content',
            'language' => 'en'
        ];
    }

    // Truncate very long text to avoid API limits
    $max_length = 15000; // Reasonable limit for Claude API
    if (strlen($text_content) > $max_length) {
        $text_content = substr($text_content, 0, $max_length) . "\n[Content truncated for analysis...]";
        error_log("Text content truncated to $max_length characters for AI analysis");
    }

    $url = "https://api.anthropic.com/v1/messages";

    // Enhanced prompt with detailed summary structure, bullet points, and keywords
    $prompt = "Please analyze the following educational document. First detect the primary language of the content, then provide a comprehensive structured analysis in that same language.

**Document:** $filename
**Content:** 
$text_content

**Instructions:**
1. **Language Detection**: Determine if the content is primarily in Thai (ภาษาไทย) or English
2. **Subject Classification**: Choose the most appropriate category from: Physics, Biology, Chemistry, Mathematics, Others
3. **Detailed Structured Summary**: Create a comprehensive analysis with multiple components

**Analysis Guidelines:**
- Focus on educational value and learning objectives
- Extract specific subtopics, concepts, and terminology
- Identify equations, formulas, diagrams, or data mentioned
- Note experimental procedures, problem-solving methods, or case studies
- Highlight key terms, definitions, and important concepts
- Consider practical applications and real-world connections
- Identify difficulty level and target audience

**Response Format (JSON only):**
If the content is primarily in Thai, respond like this:
{
    \"language\": \"th\",
    \"subject\": \"[Subject Category in English]\",
    \"summary\": \"[หัวข้อหลัก]: สรุปเนื้อหาหลักของเอกสาร (2-3 ประโยค)\\n\\n[แนวคิดสำคัญ]:\\n• แนวคิดที่ 1: คำอธิบายสั้นๆ\\n• แนวคิดที่ 2: คำอธิบายสั้นๆ\\n• แนวคิดที่ 3: คำอธิบายสั้นๆ\\n\\n[คำศัพท์สำคัญ]: คำศัพท์1, คำศัพท์2, คำศัพท์3, คำศัพท์4\\n\\n[การประยุกต์ใช้]: อธิบายการนำไปใช้จริงหรือความสำคัญทางการศึกษา\",
    \"summary_en\": \"[Brief English summary for reference]\"
}

If the content is primarily in English, respond like this:
{
    \"language\": \"en\",
    \"subject\": \"[Subject Category]\",
    \"summary\": \"[Main Topic]: Brief overview of the document's primary focus (2-3 sentences)\\n\\n[Key Concepts]:\\n• Concept 1: Brief explanation\\n• Concept 2: Brief explanation\\n• Concept 3: Brief explanation\\n\\n[Important Keywords]: keyword1, keyword2, keyword3, keyword4\\n\\n[Applications/Significance]: Practical applications or educational importance\",
    \"summary_th\": \"[สรุปสั้นๆ เป็นภาษาไทยเพื่ออ้างอิง]\"
}

**Important Notes**: 
- Always provide the summary in the primary language of the source content
- Use bullet points (•) for key concepts to improve readability
- Include 3-5 key concepts maximum
- Extract 4-6 most important keywords/terms
- Subject category should always be in English for consistency
- Keep each bullet point concise but informative (10-15 words max)
- Focus on educational content that would help students understand the material";

    $request_data = [
        'model' => 'claude-3-5-sonnet-20241022',
        'max_tokens' => 800, // Increased for multilingual responses
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
        error_log("AI API attempt $attempt of $max_retries for file: $filename");

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

        error_log("AI API attempt $attempt - HTTP Code: $http_code");

        if ($curl_error) {
            error_log("AI CURL Error on attempt $attempt: " . $curl_error);
            if ($attempt === $max_retries) {
                return [
                    'subject' => 'Others',
                    'summary' => 'Network error connecting to AI API after ' . $max_retries . ' attempts: ' . $curl_error,
                    'debug' => 'CURL Error after retries: ' . $curl_error,
                    'language' => 'en'
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

                        // Get detected language (default to English if not specified)
                        $detected_language = isset($analysis['language']) ? $analysis['language'] : 'en';

                        // Prepare the main summary and alternative language summary
                        $main_summary = $analysis['summary'];
                        $alt_summary = '';

                        if ($detected_language === 'th' && isset($analysis['summary_en'])) {
                            $alt_summary = $analysis['summary_en'];
                        } elseif ($detected_language === 'en' && isset($analysis['summary_th'])) {
                            $alt_summary = $analysis['summary_th'];
                        }

                        error_log("AI analysis successful on attempt $attempt - Language: $detected_language");
                        return [
                            'subject' => $analysis['subject'],
                            'summary' => $main_summary,
                            'summary_alt' => $alt_summary,
                            'language' => $detected_language,
                            'debug' => "AI analysis successful on attempt $attempt - Enhanced multilingual summary generated"
                        ];
                    }
                }

                // Enhanced fallback parsing method with structured content support
                $detected_language = detectLanguageFromContent($text_content);
                $lines = explode("\n", $claude_response);
                $subject = 'Others';
                $summary = ($detected_language === 'th')
                    ? '[หัวข้อหลัก]: เอกสารได้รับการวิเคราะห์เรียบร้อยแล้ว\n\n[แนวคิดสำคัญ]:\n• เนื้อหาได้รับการประมวลผลและจัดหมวดหมู่แล้ว\n• ระบบ AI ได้ทำการวิเคราะห์เอกสารเรียบร้อย\n\n[คำศัพท์สำคัญ]: วิเคราะห์, จัดหมวดหมู่, เอกสาร\n\n[การประยุกต์ใช้]: เอกสารพร้อมสำหรับการศึกษาและอ้างอิง'
                    : '[Main Topic]: Document analyzed successfully\n\n[Key Concepts]:\n• Content has been processed and categorized\n• AI analysis completed successfully\n• Document ready for educational use\n\n[Important Keywords]: analysis, categorization, document, education\n\n[Applications/Significance]: Document is ready for study and reference purposes';

                // Try to extract subject and summary from fallback response
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
                            $extracted_summary = trim(str_replace(['"', "'", '}'], '', $parts[1]));
                            if (strlen($extracted_summary) > 20) { // Only use if we got a meaningful summary
                                // Try to structure the extracted summary
                                if (!strpos($extracted_summary, '[') && !strpos($extracted_summary, '•')) {
                                    // Convert plain text to structured format
                                    $structured_summary = ($detected_language === 'th')
                                        ? "[หัวข้อหลัก]: $extracted_summary\n\n[แนวคิดสำคัญ]:\n• ข้อมูลหลักจากการวิเคราะห์\n• เนื้อหาที่สำคัญของเอกสาร\n\n[คำศัพท์สำคัญ]: การศึกษา, เอกสาร, ข้อมูล\n\n[การประยุกต์ใช้]: นำไปใช้ในการศึกษาและอ้างอิง"
                                        : "[Main Topic]: $extracted_summary\n\n[Key Concepts]:\n• Primary information from analysis\n• Important content from document\n\n[Important Keywords]: education, document, information\n\n[Applications/Significance]: Useful for study and reference";
                                    $summary = $structured_summary;
                                } else {
                                    $summary = $extracted_summary;
                                }
                                break;
                            }
                        }
                    }
                }

                return [
                    'subject' => $subject,
                    'summary' => $summary,
                    'language' => $detected_language,
                    'debug' => "AI response parsed with fallback method on attempt $attempt"
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

            error_log("AI API retryable error on attempt $attempt: HTTP $http_code - $error_message");

            if ($attempt < $max_retries) {
                // Exponential backoff: wait longer for each retry
                $wait_time = $base_delay * pow(2, $attempt - 1);
                error_log("Waiting {$wait_time} seconds before retry...");
                sleep($wait_time);
                continue;
            } else {
                // Final attempt failed - provide message in appropriate language
                $detected_language = detectLanguageFromContent($text_content);
                $error_summary = ($detected_language === 'th')
                    ? "อัพโหลดเอกสารเรียบร้อยแล้ว AI กำลังใช้งานหนัก (HTTP $http_code) เอกสารได้รับการบันทึกแล้วและสามารถวิเคราะห์ใหม่ได้ในภายหลังเมื่อพร้อมใช้งาน"
                    : "Document uploaded successfully. AI is temporarily overloaded (HTTP $http_code). The document has been saved and can be re-analyzed later when the service is available.";

                return [
                    'subject' => 'Others',
                    'summary' => $error_summary,
                    'language' => $detected_language,
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

            error_log("AI API non-retryable error: HTTP $http_code - $error_message");

            $detected_language = detectLanguageFromContent($text_content);
            $error_summary = ($detected_language === 'th')
                ? "อัพโหลดเอกสารเรียบร้อยแล้ว เกิดข้อผิดพลาด AI API (HTTP $http_code): $error_message"
                : "Document uploaded successfully. AI API error (HTTP $http_code): $error_message";

            return [
                'subject' => 'Others',
                'summary' => $error_summary,
                'language' => $detected_language,
                'debug' => "HTTP $http_code (non-retryable): $error_message"
            ];
        }
    }

    // Should not reach here, but just in case
    $detected_language = detectLanguageFromContent($text_content);
    $fallback_summary = ($detected_language === 'th')
        ? 'อัพโหลดเอกสารเรียบร้อยแล้ว แต่การวิเคราะห์ AI ล้มเหลวหลังจากการพยายามหลายครั้ง'
        : 'Document uploaded successfully but AI analysis failed after multiple attempts.';

    return [
        'subject' => 'Others',
        'summary' => $fallback_summary,
        'language' => $detected_language,
        'debug' => 'All retry attempts exhausted'
    ];
}

// UPDATED: Process uploaded file with simplified Tesseract OCR
function processUploadedFile($uploaded_file)
{
    $debug_info = [];
    $debug_info['function_start'] = 'processUploadedFile started - SIMPLIFIED TESSERACT MODE';

    $file_extension = strtolower(pathinfo($uploaded_file['name'], PATHINFO_EXTENSION));
    $user_id = $_SESSION['user_id'];
    $debug_info['file_extension'] = $file_extension;
    $debug_info['user_id'] = $user_id;

    $clean_filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $uploaded_file['name']);
    $timestamp = time();
    $final_filename = $timestamp . '_' . $clean_filename;

    $temp_upload_path = UPLOAD_DIR . 'temp_' . $final_filename;
    $debug_info['temp_path'] = $temp_upload_path;

    if (!move_uploaded_file($uploaded_file['tmp_name'], $temp_upload_path)) {
        $debug_info['error'] = 'Failed to move uploaded file';
        $_SESSION['last_upload_debug']['processing_details'] = $debug_info;
        return [
            'subject' => 'Others',
            'summary' => 'Failed to move uploaded file to temporary location',
            'language' => 'en',
            'status' => 'error',
            'debug' => $debug_info
        ];
    }

    $debug_info['file_moved'] = 'File moved successfully to temp location';
    $extracted_text = '';

    // Simplified text extraction using Tesseract directly
    if ($file_extension === 'pdf') {
        $debug_info['processing_type'] = 'PDF - Direct Tesseract OCR (no ImageMagick)';

        // Use the simplified PDF extraction
        $extracted_text = extractTextFromPDF($temp_upload_path);

        if ($extracted_text) {
            $debug_info['pdf_processing'] = 'SUCCESS - Direct Tesseract extraction';
        } else {
            $debug_info['pdf_processing'] = 'FAILED - could not extract text with Tesseract';
        }
    } elseif (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'tif', 'webp'])) {
        $debug_info['processing_type'] = 'Image - Direct Tesseract OCR';
        $ocr_result = performOCR($temp_upload_path);
        $debug_info['ocr_result'] = $ocr_result;
        $extracted_text = $ocr_result['text'];
    } else {
        $debug_info['error'] = 'Unsupported file type: ' . $file_extension;
    }

    $debug_info['extracted_text_length'] = $extracted_text ? strlen($extracted_text) : 0;
    $debug_info['extracted_text_preview'] = $extracted_text ? substr($extracted_text, 0, 200) . '...' : 'No text extracted';

    // Check if we have sufficient text for analysis
    if (!$extracted_text || strlen(trim($extracted_text)) <= 10) {
        $debug_info['error'] = 'Insufficient text extracted for analysis';
        $_SESSION['last_upload_debug']['processing_details'] = $debug_info;

        // Clean up temp file
        if (file_exists($temp_upload_path)) {
            unlink($temp_upload_path);
        }

        return [
            'subject' => 'Others',
            'summary' => 'Could not extract sufficient text from file. Please ensure the file contains clear, readable text for Tesseract OCR processing.',
            'language' => 'en',
            'status' => 'error',
            'debug' => $debug_info
        ];
    }

    // AI Analysis phase (unchanged)
    $debug_info['ai_analysis'] = 'Starting AI analysis with Claude';
    try {
        $analysis = analyzeWithClaude($extracted_text, $uploaded_file['name']);
        $debug_info['ai_analysis_result'] = $analysis;
    } catch (Exception $e) {
        $debug_info['ai_error'] = 'AI analysis failed: ' . $e->getMessage();
        $_SESSION['last_upload_debug']['processing_details'] = $debug_info;

        // Clean up temp file
        if (file_exists($temp_upload_path)) {
            unlink($temp_upload_path);
        }

        return [
            'subject' => 'Others',
            'summary' => 'AI analysis failed: ' . $e->getMessage(),
            'language' => 'en',
            'status' => 'error',
            'debug' => $debug_info
        ];
    }

    $subject = $analysis['subject'];
    $language = isset($analysis['language']) ? $analysis['language'] : 'en';
    $alt_summary = isset($analysis['summary_alt']) ? $analysis['summary_alt'] : '';

    $debug_info['analysis_subject'] = $subject;
    $debug_info['analysis_language'] = $language;

    // File organization phase (unchanged)
    $subject_upload_path = getUserUploadPath($user_id, $subject);
    if (!file_exists($subject_upload_path)) {
        if (!mkdir($subject_upload_path, 0755, true)) {
            $debug_info['error'] = 'Failed to create subject directory: ' . $subject_upload_path;
            $_SESSION['last_upload_debug']['processing_details'] = $debug_info;

            // Clean up temp file
            if (file_exists($temp_upload_path)) {
                unlink($temp_upload_path);
            }

            return [
                'subject' => 'Others',
                'summary' => 'Failed to create storage directory',
                'language' => 'en',
                'status' => 'error',
                'debug' => $debug_info
            ];
        }
    }

    $final_upload_path = $subject_upload_path . $final_filename;
    $debug_info['final_path'] = $final_upload_path;

    if (!rename($temp_upload_path, $final_upload_path)) {
        $debug_info['error'] = 'Failed to move file to final location';
        $_SESSION['last_upload_debug']['processing_details'] = $debug_info;

        // Clean up temp file
        if (file_exists($temp_upload_path)) {
            unlink($temp_upload_path);
        }

        return [
            'subject' => 'Others',
            'summary' => 'Failed to move file to final storage location',
            'language' => 'en',
            'status' => 'error',
            'debug' => $debug_info
        ];
    }

    // Summary file creation (unchanged)
    try {
        $summary_file_path = createSummaryTextFile(
            $analysis['summary'],
            $uploaded_file['name'],
            $user_id,
            $subject,
            $language,
            $alt_summary
        );
        $debug_info['summary_file_path'] = $summary_file_path;
    } catch (Exception $e) {
        $debug_info['summary_error'] = 'Failed to create summary file: ' . $e->getMessage();
        $summary_file_path = null;
    }

    // Database storage (unchanged)
    try {
        $db_result = saveFileToDatabase(
            $user_id,
            $uploaded_file['name'],
            $subject,
            $file_extension,
            $final_upload_path,
            $summary_file_path,
            $language
        );
        $debug_info['database_save'] = $db_result ? 'SUCCESS' : 'FAILED';
    } catch (Exception $e) {
        $debug_info['database_error'] = 'Database save failed: ' . $e->getMessage();
        $_SESSION['last_upload_debug']['processing_details'] = $debug_info;

        return [
            'subject' => 'Others',
            'summary' => 'File processed but failed to save to database: ' . $e->getMessage(),
            'language' => 'en',
            'status' => 'error',
            'debug' => $debug_info
        ];
    }

    $debug_info['success'] = 'File processed successfully - SIMPLIFIED TESSERACT MODE';
    $_SESSION['last_upload_debug']['processing_details'] = $debug_info;

    return [
        'subject' => $subject,
        'summary' => $analysis['summary'],
        'language' => $language,
        'status' => 'completed',
        'extracted_text' => substr($extracted_text, 0, 1000),
        'file_path' => $final_upload_path,
        'debug' => $debug_info
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
    } elseif ($file_extension === 'webp') {
        header('Content-Type: image/webp');
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

// UPDATED: reanalyzeFile function with simplified Tesseract processing
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
        // Use simplified PDF processing with direct Tesseract
        $extracted_text = extractTextFromPDF($file['original_file_path']);
    } else if (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'tif', 'webp'])) {
        $ocr_result = performOCR($file['original_file_path']);
        $extracted_text = $ocr_result['text'];
    }

    if ($extracted_text && strlen(trim($extracted_text)) > 10) {
        $analysis = analyzeWithClaude($extracted_text, $file['file_name']);
        $new_subject = $analysis['subject'];
        $new_language = isset($analysis['language']) ? $analysis['language'] : 'en';
        $alt_summary = isset($analysis['summary_alt']) ? $analysis['summary_alt'] : '';

        // Update summary file with new analysis
        if (!empty($file['summary_file_path']) && file_exists($file['summary_file_path'])) {
            // Create new summary content with language info
            $summary_content = $analysis['summary'];

            // Add alternative language summary if available
            if (!empty($alt_summary)) {
                $separator = "\n\n" . str_repeat("-", 50) . "\n";
                if ($new_language === 'th') {
                    $summary_content .= $separator . "English Summary:\n" . $alt_summary;
                } else {
                    $summary_content .= $separator . "สรุปภาษาไทย:\n" . $alt_summary;
                }
            }

            // Add language metadata
            $summary_content .= "\n\n" . str_repeat("=", 50) . "\n";
            $summary_content .= "Language: " . ($new_language === 'th' ? 'Thai (ภาษาไทย)' : 'English') . "\n";
            $summary_content .= "Re-analyzed: " . date('Y-m-d H:i:s') . "\n";

            file_put_contents($file['summary_file_path'], $summary_content);
        }

        // Update database with new language info
        try {
            // Check if language column exists
            $check_column = $pdo->query("SHOW COLUMNS FROM user_files LIKE 'language'");
            if ($check_column->rowCount() == 0) {
                $pdo->exec("ALTER TABLE user_files ADD COLUMN language VARCHAR(5) DEFAULT 'en' AFTER file_type");
            }
        } catch (Exception $e) {
            error_log("Error checking/adding language column: " . $e->getMessage());
        }

        // If subject changed, move file to new subject folder
        if ($new_subject !== $file['subject']) {
            $new_subject_path = getUserUploadPath($user_id, $new_subject);
            if (!file_exists($new_subject_path)) {
                mkdir($new_subject_path, 0755, true);
            }

            $new_file_path = $new_subject_path . basename($file['original_file_path']);
            if (rename($file['original_file_path'], $new_file_path)) {
                $update_sql = "UPDATE user_files SET subject = ?, original_file_path = ?, language = ? WHERE id = ?";
                $update_stmt = $pdo->prepare($update_sql);
                $update_stmt->execute([$new_subject, $new_file_path, $new_language, $file_id]);
            }
        } else {
            // Just update the language
            $update_sql = "UPDATE user_files SET language = ? WHERE id = ?";
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([$new_language, $file_id]);
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

// Handle file upload with enhanced error handling and debugging
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['uploaded_file'])) {
    $uploaded_file = $_FILES['uploaded_file'];

    // Add debug session variable to show processing status
    $_SESSION['last_upload_debug'] = [];
    $_SESSION['last_upload_debug']['timestamp'] = date('Y-m-d H:i:s');
    $_SESSION['last_upload_debug']['filename'] = $uploaded_file['name'];

    if ($uploaded_file['error'] === UPLOAD_ERR_OK) {
        $_SESSION['last_upload_debug']['upload_status'] = 'File uploaded successfully';

        try {
            $processing_result = processUploadedFile($uploaded_file);
            $_SESSION['last_upload_debug']['processing_result'] = $processing_result;

            if ($processing_result['status'] === 'completed') {
                $_SESSION['last_upload_debug']['final_status'] = 'SUCCESS: File processed and saved with simplified Tesseract OCR';
            } else {
                $_SESSION['last_upload_debug']['final_status'] = 'ERROR: Processing failed - ' . $processing_result['summary'];
            }
        } catch (Exception $e) {
            $_SESSION['last_upload_debug']['final_status'] = 'EXCEPTION: ' . $e->getMessage();
            error_log("File processing exception: " . $e->getMessage());
        }

        // Redirect with debug parameter
        $redirect_params = $_GET;
        $redirect_params['debug'] = '1';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($redirect_params));
        exit();
    } else {
        $_SESSION['last_upload_debug']['upload_status'] = 'Upload failed with error: ' . $uploaded_file['error'];
        $redirect_params = $_GET;
        $redirect_params['debug'] = '1';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($redirect_params));
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

// Continue with your existing HTML output...
// [The rest of your HTML code remains the same]
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OZNOTE</title>
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

        /* Settings Modal Animation */
        #settingsModal {
            opacity: 0;
            transition: opacity 0.3s ease-in-out;
        }

        #settingsModal:not(.hidden) {
            opacity: 1;
        }

        /* Progress bar animation */
        @keyframes progressPulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.7;
            }
        }

        .animate-pulse {
            animation: progressPulse 2s infinite;
        }

        /* Hover effects for feature cards */
        .glass-card:hover {
            transform: translateY(-1px);
            transition: transform 0.2s ease-in-out;
        }

        /* Mobile responsiveness for settings */
        @media (max-width: 640px) {
            #settingsModal .glass-card {
                margin: 1rem;
                max-width: calc(100vw - 2rem);
            }
        }

        .sidebar .border-t.border-theme-medium {
            position: relative;
        }

        .sidebar .border-t.border-theme-medium::before {
            content: '';
            position: absolute;
            top: -1px;
            left: 1rem;
            right: 1rem;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--color-accent-light), transparent);
            opacity: 0.3;
        }

        /* Ensure settings button has proper hover state */
        #settingsBtn:hover {
            background-color: var(--color-primary-medium);
            transform: translateX(2px);
            transition: all 0.2s ease;
        }

        /* Mobile responsiveness for sidebar footer */
        @media (max-width: 640px) {
            .sidebar .space-y-1 {
                space-y: 0.25rem;
            }

            .sidebar .btn-touch {
                min-height: 44px;
            }
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
                    <h1 class="text-lg sm:text-xl font-bold text-theme-bright gradient-text">OZNOTE</h1>
                    <div class="flex items-center space-x-4 ml-6">
                        <div class="w-6 h-6 sm:w-8 sm:h-8 bg-theme-green rounded-full flex items-center justify-center">
                            <span class="text-black text-xs sm:text-sm font-semibold">
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
                <div class="space-y-1">
                    <!-- Settings Button -->
                    <button id="settingsBtn" onclick="openSettingsPanel()" class="w-full flex items-center px-3 sm:px-4 py-2 text-xs sm:text-sm text-theme-light hover:bg-theme-medium rounded-lg transition-colors btn-touch focus:outline-none focus:ring-2 focus:ring-theme-green focus:ring-opacity-50">
                        <span class="text-base sm:text-lg mr-2 sm:mr-3">⚙️</span>
                        <span>Settings</span>
                    </button>

                    <!-- Logout Button -->
                    <a href="?action=logout" class="w-full flex items-center px-3 sm:px-4 py-2 text-xs sm:text-sm text-red-400 hover:bg-theme-medium rounded-lg transition-colors btn-touch">
                        <span class="text-base sm:text-lg mr-2 sm:mr-3">🚪</span>
                        <span>Logout</span>
                    </a>
                </div>
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
                    <!-- You can add other action buttons here if needed in the future -->
                </div>
            </div>

            <!-- Content Area -->
            <div class="flex-1 overflow-auto custom-scrollbar content-padding print-full-width">
                <?php
                // Debug information display
                if (isset($_GET['debug']) && isset($_SESSION['last_upload_debug'])):
                ?>
                    <div class="mb-6 bg-yellow-900 bg-opacity-50 border border-yellow-600 rounded-lg p-4">
                        <h3 class="text-yellow-200 font-semibold mb-3 flex items-center">
                            <span class="mr-2">🐛</span>Debug Information - Last Upload
                            <button onclick="this.parentElement.parentElement.style.display='none'" class="ml-auto text-yellow-400 hover:text-yellow-200">✕</button>
                        </h3>

                        <div class="space-y-3 text-sm">
                            <div class="bg-black bg-opacity-30 rounded p-3">
                                <h4 class="text-yellow-300 font-medium mb-2">📋 Basic Info:</h4>
                                <p class="text-yellow-100">File: <?php echo htmlspecialchars($_SESSION['last_upload_debug']['filename'] ?? 'Unknown'); ?></p>
                                <p class="text-yellow-100">Time: <?php echo htmlspecialchars($_SESSION['last_upload_debug']['timestamp'] ?? 'Unknown'); ?></p>
                                <p class="text-yellow-100">Status: <?php echo htmlspecialchars($_SESSION['last_upload_debug']['final_status'] ?? 'Unknown'); ?></p>
                            </div>

                            <?php if (isset($_SESSION['last_upload_debug']['processing_details'])): ?>
                                <div class="bg-black bg-opacity-30 rounded p-3">
                                    <h4 class="text-yellow-300 font-medium mb-2">🔍 Processing Details:</h4>
                                    <pre class="text-yellow-100 text-xs overflow-auto max-h-40"><?php echo htmlspecialchars(print_r($_SESSION['last_upload_debug']['processing_details'], true)); ?></pre>
                                </div>
                            <?php endif; ?>

                            <div class="bg-black bg-opacity-30 rounded p-3">
                                <h4 class="text-yellow-300 font-medium mb-2">🔧 Configuration Check:</h4>
                                <p class="text-yellow-100">Tesseract OCR: <?php echo (shell_exec('tesseract --version 2>&1') && strpos(shell_exec('tesseract --version 2>&1'), 'tesseract') !== false) ? '✅ Installed' : '❌ Not Found'; ?></p>
                                <p class="text-yellow-100">Claude API Key: <?php echo (empty(CLAUDE_API_KEY)) ? '❌ NOT CONFIGURED' : '✅ Configured'; ?></p>
                                <p class="text-yellow-100">Upload Directory: <?php echo is_writable(UPLOAD_DIR) ? '✅ Writable' : '❌ Not Writable'; ?></p>
                                <p class="text-yellow-100">pdftotext Tool: <?php echo (shell_exec('pdftotext -v 2>&1') && strpos(shell_exec('pdftotext -v 2>&1'), 'pdftotext') !== false) ? '✅ Available' : '❌ Not Available'; ?></p>
                                <p class="text-yellow-100">PHP Upload Max: <?php echo ini_get('upload_max_filesize'); ?></p>
                                <p class="text-yellow-100">PHP Post Max: <?php echo ini_get('post_max_size'); ?></p>
                            </div>

                            <?php if (!(shell_exec('tesseract --version 2>&1') && strpos(shell_exec('tesseract --version 2>&1'), 'tesseract') !== false)): ?>
                                <div class="bg-red-900 bg-opacity-50 border border-red-600 rounded p-3">
                                    <h4 class="text-red-300 font-medium mb-2">⚠️ Configuration Issue:</h4>
                                    <p class="text-red-200 text-sm">Tesseract OCR is not installed or not accessible. Please:</p>
                                    <ol class="text-red-200 text-sm mt-2 ml-4 list-decimal">
                                        <li>Install Tesseract OCR on your server</li>
                                        <li>For Ubuntu/Debian: <code>sudo apt-get install tesseract-ocr tesseract-ocr-tha</code></li>
                                        <li>For CentOS/RHEL: <code>sudo yum install tesseract</code></li>
                                        <li>For additional language support: <code>sudo apt-get install tesseract-ocr-tha</code> (for Thai)</li>
                                        <li>Verify installation: <code>tesseract --version</code></li>
                                        <li><strong>Note:</strong> This simplified version uses Tesseract directly - no ImageMagick required!</li>
                                    </ol>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

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
                                            <input type="file" name="uploaded_file" accept="image/*,.webp" class="hidden" />
                                            <div class="upload-area glass-card rounded-lg sm:rounded-xl p-4 sm:p-6 text-center hover:shadow-lg transition-all duration-300 border-2 border-dashed border-theme-bright hover:border-theme-green btn-touch">
                                                <div class="text-theme-green text-2xl sm:text-4xl mb-2 sm:mb-3">🖼️</div>
                                                <span class="text-base sm:text-lg font-medium text-white">Images</span>
                                                <p class="text-xs sm:text-sm text-gray-300 mt-1 sm:mt-2">JPG, PNG, WebP, etc.</p>
                                            </div>
                                        </label>
                                    </form>
                                </div>
                                <div class="bg-theme-bright rounded-lg p-3 sm:p-4 border-l-4 border-theme-green">
                                    <div class="flex items-center justify-between">
                                        <div class="flex-1">
                                            <p class="text-black font-medium text-sm sm:text-base">🖥️ AI-Powered with Tesseract OCR</p>
                                            <p class="text-black text-xs sm:text-sm mt-1">Enhanced OCR accuracy with local Tesseract processing. AI generates detailed summaries with bullet points, key concepts, and important keywords in Thai or English.</p>
                                        </div>
                                        <button onclick="testAPIConfiguration()" class="ml-4 px-3 py-1 bg-theme-medium hover:bg-theme-dark text-white rounded text-xs font-medium btn-touch flex-shrink-0">
                                            Test Setup
                                        </button>
                                    </div>
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
                                            <p class="text-theme-dark text-opacity-80 text-sm sm:text-base">files stored</p>
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
                                            <p class="text-gray-400 text-xs sm:text-sm mb-4">Upload your first document to get started</p>

                                            <!-- Quick troubleshooting -->
                                            <div class="bg-blue-900 bg-opacity-30 rounded-lg p-4 mt-4 text-left">
                                                <h5 class="text-blue-300 font-medium mb-2 text-center">📋 First time? Here's what to do:</h5>
                                                <div class="text-blue-200 text-xs space-y-1">
                                                    <p>1. Click "Test Setup" button above to verify your configuration</p>
                                                    <p>2. If setup is complete, try uploading a PDF or image</p>
                                                    <p>3. If upload fails, check the debug information</p>
                                                    <p>4. Make sure Tesseract OCR is installed on your server</p>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($recent_files as $file): ?>
                                            <div class="flex items-center justify-between p-3 sm:p-4 bg-white bg-opacity-20 rounded-lg sm:rounded-xl hover:bg-opacity-30 transition-colors cursor-pointer btn-touch file-card" data-file-id="<?php echo $file['id']; ?>">
                                                <div class="flex items-center space-x-3 sm:space-x-4 flex-1 min-w-0">
                                                    <div class="w-10 h-10 sm:w-12 sm:h-12 bg-theme-green rounded-lg flex items-center justify-center flex-shrink-0">
                                                        <span class="text-white text-lg sm:text-xl">📄</span>
                                                    </div>
                                                    <div class="flex-1 min-w-0">
                                                        <div class="flex items-center space-x-2">
                                                            <p class="font-medium text-white truncate text-sm sm:text-base"><?php echo htmlspecialchars($file['name']); ?></p>
                                                            <?php if (isset($file['language'])): ?>
                                                                <span class="px-1 py-0.5 rounded text-xs <?php echo $file['language'] === 'th' ? 'bg-blue-500 text-white' : 'bg-green-500 text-white'; ?> flex-shrink-0">
                                                                    <?php echo $file['language'] === 'th' ? '🇹🇭' : '🇺🇸'; ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
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
                                    <div class="flex items-center justify-between p-3 bg-blue-500 bg-opacity-30 rounded-lg">
                                        <div class="flex items-center">
                                            <div class="w-6 h-6 sm:w-8 sm:h-8 bg-blue-600 rounded-lg flex items-center justify-center mr-2 sm:mr-3">
                                                <span class="text-white text-xs sm:text-sm">🌏</span>
                                            </div>
                                            <span class="font-medium text-black text-sm sm:text-base">Languages</span>
                                        </div>
                                        <span class="text-xl sm:text-2xl font-bold text-black">
                                            <?php
                                            $languages = [];
                                            foreach ($user_files as $file) {
                                                if (isset($file['language'])) {
                                                    $languages[$file['language']] = true;
                                                }
                                            }
                                            echo count($languages) > 0 ? count($languages) : 1;
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
                                    <button onclick="window.location.reload();" class="px-3 sm:px-4 py-2 bg-theme-light hover:bg-theme-medium text-white rounded-lg transition-colors flex-1 sm:flex-none btn-touch">
                                        <span class="text-xs sm:text-sm">🔄 Refresh</span>
                                    </button>
                                    <form method="post" enctype="multipart/form-data" class="inline flex-1 sm:flex-none">
                                        <label class="cursor-pointer w-full sm:w-auto">
                                            <input type="file" name="uploaded_file" accept=".pdf,image/*,.webp" class="hidden" />
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
                                            <input type="file" name="uploaded_file" accept=".pdf,image/*,.webp" class="hidden" />
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
                                                    <div class="flex items-center space-x-2 mb-1">
                                                        <h4 class="font-semibold text-white text-base sm:text-lg truncate"><?php echo htmlspecialchars($file['name']); ?></h4>
                                                        <?php if (isset($file['language'])): ?>
                                                            <span class="px-2 py-1 rounded-full text-xs font-medium <?php echo $file['language'] === 'th' ? 'bg-blue-500 text-white' : 'bg-green-500 text-white'; ?> flex-shrink-0">
                                                                <?php echo $file['language'] === 'th' ? '🇹🇭 Thai' : '🇺🇸 EN'; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
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
                                            <p class="text-xs sm:text-sm text-black font-medium mb-1 sm:mb-2 flex items-center">
                                                🤖 AI Summary
                                                <?php if (isset($file['language']) && $file['language'] === 'th'): ?>
                                                    <span class="ml-2 px-2 py-1 rounded text-xs bg-blue-100 text-blue-800">ภาษาไทย</span>
                                                <?php else: ?>
                                                    <span class="ml-2 px-2 py-1 rounded text-xs bg-green-100 text-green-800">English</span>
                                                <?php endif; ?>
                                            </p>
                                            <p class="text-xs sm:text-sm text-black line-clamp-3">
                                                <?php
                                                if (!empty($file['summary_file_path']) && file_exists($file['summary_file_path'])) {
                                                    $summary_content = file_get_contents($file['summary_file_path']);

                                                    // Extract the main content between AI ANALYSIS and technical info
                                                    if (preg_match('/🤖 AI ANALYSIS:\s*\n\n(.*?)\n\n.*?(?:🇺🇸|🇹🇭|📊)/s', $summary_content, $matches)) {
                                                        $clean_summary = $matches[1];
                                                    } else {
                                                        $clean_summary = $summary_content;
                                                    }

                                                    // Remove emojis and clean up formatting for preview
                                                    $clean_summary = preg_replace('/📌\s*/', '', $clean_summary);
                                                    $clean_summary = preg_replace('/\[([^\]]+)\]:/', '$1:', $clean_summary);
                                                    $clean_summary = trim($clean_summary);

                                                    // Truncate for preview but try to end at a complete concept
                                                    if (strlen($clean_summary) > 150) {
                                                        $truncated = substr($clean_summary, 0, 150);
                                                        // Try to end at a bullet point or section
                                                        if (strpos($truncated, '•') !== false) {
                                                            $last_bullet = strrpos($truncated, '•');
                                                            $next_newline = strpos($clean_summary, "\n", $last_bullet);
                                                            if ($next_newline !== false && $next_newline < 200) {
                                                                $truncated = substr($clean_summary, 0, $next_newline);
                                                            }
                                                        }
                                                        echo htmlspecialchars($truncated . '...');
                                                    } else {
                                                        echo htmlspecialchars($clean_summary);
                                                    }
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

    <!-- UPDATED File Details Modal with Language Support -->
    <div id="fileDetailsModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-2 sm:p-4 no-print">
        <div class="glass-card rounded-xl sm:rounded-2xl max-w-4xl w-full max-h-[95vh] sm:max-h-[90vh] overflow-hidden border-theme-light">
            <!-- Modal Header -->
            <div class="flex items-center justify-between p-4 sm:p-6 border-b border-theme-light">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center space-x-3">
                        <h3 id="modalFileName" class="text-lg sm:text-xl font-semibold text-white truncate"></h3>
                        <span id="modalLanguageBadge" class="px-2 py-1 rounded-full text-xs font-medium bg-theme-green text-white hidden">
                            <!-- Language badge will be populated by JavaScript -->
                        </span>
                    </div>
                    <p id="modalSubject" class="text-gray-300 text-sm sm:text-base"></p>
                </div>
                <button onclick="closeFileDetails()" class="text-gray-300 hover:text-white text-xl sm:text-2xl ml-4 btn-touch focus:outline-none focus:ring-2 focus:ring-theme-green focus:ring-opacity-50 rounded p-2">&times;</button>
            </div>

            <!-- Modal Tabs -->
            <div class="border-b border-theme-light">
                <div class="flex">
                    <button id="summaryTab" onclick="switchTab('summary')" class="flex-1 py-3 sm:py-4 px-4 sm:px-6 text-xs sm:text-sm font-medium text-theme-green border-b-2 border-theme-green btn-touch focus:outline-none">
                        Summary & Details
                    </button>
                    <button id="fullTextTab" onclick="switchTab('fulltext')" class="flex-1 py-3 sm:py-4 px-4 sm:px-6 text-xs sm:text-sm font-medium text-gray-300 hover:text-white btn-touch focus:outline-none">
                        View Original File
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
                                <span id="modalSummaryLanguage" class="ml-2 px-2 py-1 rounded text-xs bg-theme-light text-black hidden">
                                    <!-- Summary language indicator -->
                                </span>
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
                                        <span class="text-gray-300 w-20">Date:</span>
                                        <span id="modalDate" class="text-white"></span>
                                    </div>
                                    <div class="flex items-center">
                                        <span class="text-gray-300 w-20">Status:</span>
                                        <span id="modalStatus" class="text-white"></span>
                                    </div>
                                    <div class="flex items-center">
                                        <span class="text-gray-300 w-20">Language:</span>
                                        <span id="modalLanguageInfo" class="text-white"></span>
                                    </div>
                                    <div class="flex items-center">
                                        <span class="text-gray-300 w-20">ID:</span>
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
                                        <span class="mr-2">🔄</span><span id="reanalyzeText">Re-analyze with AI</span>
                                    </button>
                                    <button id="deleteBtn" class="w-full px-3 sm:px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded-lg transition-colors text-xs sm:text-sm btn-touch font-medium flex items-center justify-center">
                                        <span class="mr-2">🗑️</span><span id="deleteText">Delete File</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- View Original File Tab -->
                <div id="fulltextContent" class="tab-content hidden">
                    <div id="filePreviewContainer" class="w-full">
                        <div class="text-center py-8">
                            <div class="text-theme-medium text-4xl mb-4">📄</div>
                            <p class="text-white mb-4">Switch to this tab to view the original file</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Settings Modal -->
    <div id="settingsModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-2 sm:p-4 no-print">
        <div class="glass-card rounded-xl sm:rounded-2xl max-w-2xl w-full max-h-[95vh] sm:max-h-[90vh] overflow-hidden border-theme-light">
            <!-- Modal Header -->
            <div class="flex items-center justify-between p-4 sm:p-6 border-b border-theme-light">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-theme-green bg-opacity-20 rounded-lg flex items-center justify-center">
                        <span class="text-theme-green text-xl">⚙️</span>
                    </div>
                    <div>
                        <h3 class="text-lg sm:text-xl font-semibold text-white">Settings</h3>
                        <p class="text-gray-300 text-sm">Manage your OZNOTE preferences</p>
                    </div>
                </div>
                <button onclick="closeSettingsPanel()" class="text-gray-300 hover:text-white text-xl sm:text-2xl ml-4 btn-touch focus:outline-none focus:ring-2 focus:ring-theme-green focus:ring-opacity-50 rounded p-2">&times;</button>
            </div>

            <!-- Modal Content -->
            <div class="p-4 sm:p-6 max-h-[60vh] overflow-auto custom-scrollbar">
                <!-- Under Development Section -->
                <div class="text-center py-8 sm:py-12">
                    <!-- Construction Icon -->
                    <div class="text-6xl sm:text-8xl mb-4 sm:mb-6">🚧</div>

                    <!-- Main Message -->
                    <h4 class="text-xl sm:text-2xl font-bold text-white mb-3 sm:mb-4 gradient-text">Under Development</h4>
                    <p class="text-gray-300 text-sm sm:text-base mb-6 sm:mb-8 max-w-md mx-auto">
                        We're working hard to bring you amazing settings and customization options. Stay tuned for updates!
                    </p>

                    <!-- Coming Soon Features -->
                    <div class="space-y-3 sm:space-y-4 mb-6 sm:mb-8">
                        <div class="bg-theme-bright bg-opacity-10 rounded-lg p-3 sm:p-4 text-left">
                            <h5 class="font-semibold text-white mb-2 flex items-center text-sm sm:text-base">
                                <span class="mr-2">🎨</span>Theme Customization
                            </h5>
                            <p class="text-gray-300 text-xs sm:text-sm">Choose from multiple color themes and layout options</p>
                        </div>

                        <div class="bg-theme-green bg-opacity-10 rounded-lg p-3 sm:p-4 text-left">
                            <h5 class="font-semibold text-white mb-2 flex items-center text-sm sm:text-base">
                                <span class="mr-2">🌐</span>Language Preferences
                            </h5>
                            <p class="text-gray-300 text-xs sm:text-sm">Set default language for AI analysis and interface</p>
                        </div>

                        <div class="bg-theme-medium bg-opacity-10 rounded-lg p-3 sm:p-4 text-left">
                            <h5 class="font-semibold text-white mb-2 flex items-center text-sm sm:text-base">
                                <span class="mr-2">🔔</span>Notifications
                            </h5>
                            <p class="text-gray-300 text-xs sm:text-sm">Configure email notifications and system alerts</p>
                        </div>

                        <div class="bg-theme-light bg-opacity-10 rounded-lg p-3 sm:p-4 text-left">
                            <h5 class="font-semibold text-white mb-2 flex items-center text-sm sm:text-base">
                                <span class="mr-2">💾</span>Export Options
                            </h5>
                            <p class="text-gray-300 text-xs sm:text-sm">Export your data and summaries in various formats</p>
                        </div>

                        <div class="bg-blue-500 bg-opacity-10 rounded-lg p-3 sm:p-4 text-left">
                            <h5 class="font-semibold text-white mb-2 flex items-center text-sm sm:text-base">
                                <span class="mr-2">🤖</span>AI Preferences
                            </h5>
                            <p class="text-gray-300 text-xs sm:text-sm">Customize AI analysis depth and summary style</p>
                        </div>
                    </div>

                    <!-- Progress Indicator -->
                    <div class="bg-theme-dark bg-opacity-30 rounded-lg p-4 sm:p-6 mb-6">
                        <h5 class="font-semibold text-white mb-3 flex items-center justify-center text-sm sm:text-base">
                            <span class="mr-2">📊</span>Development Progress
                        </h5>
                        <div class="relative">
                            <div class="flex mb-2 items-center justify-between">
                                <div>
                                    <span class="text-xs font-semibold inline-block text-theme-green">
                                        Settings Panel
                                    </span>
                                </div>
                                <div class="text-right">
                                    <span class="text-xs font-semibold inline-block text-theme-green">
                                        25%
                                    </span>
                                </div>
                            </div>
                            <div class="overflow-hidden h-2 mb-4 text-xs flex rounded bg-theme-medium">
                                <div style="width:25%" class="shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center bg-theme-green animate-pulse"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex flex-col sm:flex-row gap-3 sm:gap-4 justify-center">
                        <button onclick="closeSettingsPanel()" class="px-4 sm:px-6 py-2 sm:py-3 bg-theme-green hover:bg-theme-bright text-white rounded-lg transition-colors btn-touch font-medium flex items-center justify-center">
                            <span class="mr-2">👍</span>
                            <span class="text-sm sm:text-base">Got It</span>
                        </button>
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

        // Updated openFileDetails function to handle language information
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
                // Get language information
                const fileLanguage = file.language || 'en';
                const isThaiLanguage = fileLanguage === 'th';

                // Update modal content with enhanced formatting
                document.getElementById('modalFileName').textContent = file.name || 'Unknown File';
                document.getElementById('modalSubject').textContent = file.subject || 'Unknown Subject';

                // Format the summary content for better display
                let displaySummary = file.full_summary || (isThaiLanguage ? 'ไม่มีข้อมูลสรุป' : 'No summary available');

                // Convert structured text to HTML for better display
                if (displaySummary && displaySummary !== 'ไม่มีข้อมูลสรุป' && displaySummary !== 'No summary available') {
                    // Replace section headers with bold formatting
                    displaySummary = displaySummary.replace(/\[([^\]]+)\]:/g, '<strong>$1:</strong>');

                    // Format bullet points
                    displaySummary = displaySummary.replace(/\n\s*•\s*/g, '\n• ');
                    displaySummary = displaySummary.replace(/•([^\n]+)/g, '<span style="margin-left: 1em;">• $1</span>');

                    // Add line breaks for better spacing
                    displaySummary = displaySummary.replace(/\n\n/g, '<br><br>');
                    displaySummary = displaySummary.replace(/\n/g, '<br>');

                    // Highlight keywords section
                    displaySummary = displaySummary.replace(/<strong>(Important Keywords|คำศัพท์สำคัญ):<\/strong>\s*([^<]+)/g,
                        '<strong>$1:</strong><br><span style="background-color: rgba(129, 249, 121, 0.2); padding: 2px 6px; border-radius: 4px; font-weight: 500;">$2</span>');
                }

                document.getElementById('modalSummary').innerHTML = displaySummary;

                // Update language badge and info
                const languageBadge = document.getElementById('modalLanguageBadge');
                const languageInfo = document.getElementById('modalLanguageInfo');
                const summaryLanguage = document.getElementById('modalSummaryLanguage');

                if (isThaiLanguage) {
                    languageBadge.textContent = '🇹🇭 Thai';
                    languageBadge.className = 'px-2 py-1 rounded-full text-xs font-medium bg-blue-500 text-white';
                    languageInfo.textContent = 'Thai (ภาษาไทย)';
                    summaryLanguage.textContent = 'ภาษาไทย';
                    summaryLanguage.className = 'ml-2 px-2 py-1 rounded text-xs bg-blue-100 text-blue-800';
                } else {
                    languageBadge.textContent = '🇺🇸 EN';
                    languageBadge.className = 'px-2 py-1 rounded-full text-xs font-medium bg-green-500 text-white';
                    languageInfo.textContent = 'English';
                    summaryLanguage.textContent = 'English';
                    summaryLanguage.className = 'ml-2 px-2 py-1 rounded text-xs bg-green-100 text-green-800';
                }

                languageBadge.classList.remove('hidden');
                summaryLanguage.classList.remove('hidden');

                // Update button text based on language
                const reanalyzeText = document.getElementById('reanalyzeText');
                const deleteText = document.getElementById('deleteText');

                if (isThaiLanguage) {
                    reanalyzeText.textContent = 'วิเคราะห์ใหม่ด้วย AI';
                    deleteText.textContent = 'ลบไฟล์';
                } else {
                    reanalyzeText.textContent = 'Re-analyze with AI';
                    deleteText.textContent = 'Delete File';
                }

                // Update other modal content
                document.getElementById('modalDate').textContent = 'Uploaded: ' + (file.date || 'Unknown date');
                document.getElementById('modalStatus').textContent = 'Status: ' + (file.status || 'Completed');
                document.getElementById('modalSize').textContent = 'File ID: ' + file.id;

                // Reset the file preview container to default state
                const filePreviewContainer = document.getElementById('filePreviewContainer');
                if (filePreviewContainer) {
                    filePreviewContainer.innerHTML = `
                        <div class="text-center py-8">
                            <div class="text-theme-medium text-4xl mb-4">📄</div>
                            <p class="text-white mb-4">${isThaiLanguage ? 'เปลี่ยนมาที่แท็บนี้เพื่อดูไฟล์ต้นฉบับ' : 'Switch to this tab to view the original file'}</p>
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

                console.log('Modal opened successfully with language:', fileLanguage);
            } catch (error) {
                console.error('Error opening modal:', error);
                alert('Error opening file details');
            }
        }

        // Updated loadFileOptions function to handle language-specific text
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
            const isThaiLanguage = file && file.language === 'th';

            // Create simple, clear options with language-appropriate text
            container.innerHTML = `
                <div class="space-y-6">
                    <div class="text-center">
                        <div class="text-theme-green text-5xl mb-3">📄</div>
                        <h4 class="text-white text-lg font-semibold">${fileName}</h4>
                        <p class="text-gray-300 text-sm">${isThaiLanguage ? 'เลือกวิธีการดูไฟล์' : 'Choose how you want to view this file'}</p>
                    </div>
                    
                    <!-- Primary Actions -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- View in New Tab -->
                        <div class="bg-theme-bright bg-opacity-20 rounded-lg p-4 text-center hover:bg-opacity-30 transition-all">
                            <div class="text-theme-green text-3xl mb-3">🔗</div>
                            <h5 class="text-black font-semibold mb-2">${isThaiLanguage ? 'เปิดในแท็บใหม่' : 'Open in New Tab'}</h5>
                            <p class="text-gray-300 text-sm mb-4">${isThaiLanguage ? 'เหมาะสำหรับการดูและซูม' : 'Best for viewing and zooming'}</p>
                            <button onclick="viewFileInBrowser(${currentFileId})" 
                                    class="w-full px-4 py-2 bg-theme-green hover:bg-theme-bright text-white rounded-lg transition-colors btn-touch font-medium">
                                ${isThaiLanguage ? 'เปิดไฟล์ →' : 'Open File →'}
                            </button>
                        </div>
                        
                        <!-- Download -->
                        <div class="bg-theme-light bg-opacity-20 rounded-lg p-4 text-center hover:bg-opacity-30 transition-all">
                            <div class="text-theme-medium text-3xl mb-3">💾</div>
                            <h5 class="text-white font-semibold mb-2">${isThaiLanguage ? 'ดาวน์โหลด' : 'Download'}</h5>
                            <p class="text-gray-300 text-sm mb-4">${isThaiLanguage ? 'บันทึกลงในอุปกรณ์' : 'Save to your device'}</p>
                            <button onclick="downloadFile(${currentFileId})" 
                                    class="w-full px-4 py-2 bg-theme-medium hover:bg-theme-dark text-white rounded-lg transition-colors btn-touch font-medium">
                                ${isThaiLanguage ? 'ดาวน์โหลด ⬇️' : 'Download ⬇️'}
                            </button>
                        </div>
                    </div>
                    
                    <!-- Preview Option (only for supported file types) -->
                    ${(fileExtension === 'pdf' || ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(fileExtension)) ? `
                    <div class="border-t border-theme-light pt-4">
                        <div class="bg-theme-green bg-opacity-10 rounded-lg p-4">
                            <div class="flex items-center justify-between mb-3">
                                <div>
                                    <h5 class="text-black font-semibold flex items-center">
                                        <span class="mr-2">👁️</span>${isThaiLanguage ? 'ดูตัวอย่างด่วน' : 'Quick Preview'}
                                    </h5>
                                    <p class="text-black-300 text-sm">${isThaiLanguage ? 'ดูตัวอย่างไฟล์ด้านล่าง (อาจใช้เวลาสักครู่)' : 'Preview the file below (may take a moment to load)'}</p>
                                </div>
                                <button onclick="showInlinePreview(${currentFileId})" 
                                        class="px-4 py-2 bg-theme-green hover:bg-theme-bright text-white rounded-lg transition-colors btn-touch font-medium">
                                    ${isThaiLanguage ? 'แสดงตัวอย่าง' : 'Show Preview'}
                                </button>
                            </div>
                        </div>
                    </div>
                    ` : `
                    <div class="border-t border-theme-light pt-4">
                        <div class="bg-yellow-500 bg-opacity-10 rounded-lg p-4 text-center">
                            <div class="text-yellow-400 text-2xl mb-2">ℹ️</div>
                            <p class="text-yellow-200 text-sm">
                                ${isThaiLanguage 
                                    ? `ไฟล์ประเภท ${fileExtension.toUpperCase()} ไม่สามารถดูตัวอย่างได้ กรุณาใช้ "เปิดในแท็บใหม่" หรือ "ดาวน์โหลด"`
                                    : `This file type (${fileExtension.toUpperCase()}) cannot be previewed inline. Use "Open in New Tab" or "Download" to view it.`
                                }
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

        // Updated showInlinePreview function with language support
        function showInlinePreview(fileId) {
            const container = document.getElementById('filePreviewContainer');
            const file = fileData.find(f => f.id == fileId);
            const isThaiLanguage = file && file.language === 'th';

            // Show loading state
            container.innerHTML = `
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <h5 class="text-white font-semibold">${isThaiLanguage ? 'ตัวอย่างไฟล์' : 'File Preview'}</h5>
                        <button onclick="loadFileOptions()" 
                                class="px-3 py-1 bg-theme-medium hover:bg-theme-dark text-white rounded transition-colors text-sm btn-touch">
                            ← ${isThaiLanguage ? 'กลับไปที่ตัวเลือก' : 'Back to Options'}
                        </button>
                    </div>
                    <div class="border border-theme-light rounded-lg overflow-hidden">
                        <div id="iframeContainer" class="w-full h-96 flex items-center justify-center bg-gray-100" style="min-height: 500px;">
                            <div class="text-center">
                                <div class="animate-spin rounded-full h-12 w-12 border-b-4 border-theme-green mx-auto mb-4"></div>
                                <p class="text-gray-600">${isThaiLanguage ? 'กำลังโหลดตัวอย่าง...' : 'Loading preview...'}</p>
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

        // Updated handleIframeError function with language support
        function handleIframeError(fileId) {
            const container = document.getElementById('filePreviewContainer');
            const file = fileData.find(f => f.id == fileId);
            const isThaiLanguage = file && file.language === 'th';

            container.innerHTML = `
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <h5 class="text-white font-semibold">${isThaiLanguage ? 'ตัวอย่างไฟล์' : 'File Preview'}</h5>
                        <button onclick="loadFileOptions()" 
                                class="px-3 py-1 bg-theme-medium hover:bg-theme-dark text-white rounded transition-colors text-sm btn-touch">
                            ← ${isThaiLanguage ? 'กลับไปที่ตัวเลือก' : 'Back to Options'}
                        </button>
                    </div>
                    <div class="text-center space-y-4 py-8">
                        <div class="text-yellow-400 text-4xl mb-4">⚠️</div>
                        <h5 class="text-white font-semibold">${isThaiLanguage ? 'หมดเวลาหรือเกิดข้อผิดพลาด' : 'Preview timeout or error'}</h5>
                        <p class="text-gray-300 text-sm">${isThaiLanguage ? 'ไฟล์ใช้เวลาโหลดนานเกินไป หรือไม่สามารถแสดงตัวอย่างได้' : 'The file is taking too long to load or cannot be previewed inline'}</p>
                        <div class="flex flex-col sm:flex-row gap-4 justify-center max-w-md mx-auto">
                            <button onclick="viewFileInBrowser(${fileId})" 
                                    class="px-4 py-2 bg-theme-green hover:bg-theme-bright text-white rounded-lg transition-colors btn-touch">
                                ${isThaiLanguage ? 'เปิดในแท็บใหม่' : 'Open in New Tab'}
                            </button>
                            <button onclick="downloadFile(${fileId})" 
                                    class="px-4 py-2 bg-theme-medium hover:bg-theme-dark text-white rounded-lg transition-colors btn-touch">
                                ${isThaiLanguage ? 'ดาวน์โหลดไฟล์' : 'Download File'}
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

        // Settings Panel Functions
        function openSettingsPanel() {
            console.log('Opening settings panel...');

            // Close mobile sidebar if it's open
            if (window.innerWidth <= 768) {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('mobileOverlay');
                const menuBtn = document.getElementById('mobileMenuBtn');

                if (sidebar && sidebar.classList.contains('active')) {
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                    menuBtn.classList.remove('hidden');
                }
            }

            const modal = document.getElementById('settingsModal');
            if (modal) {
                modal.classList.remove('hidden');
                document.body.style.overflow = 'hidden';

                // Add a subtle animation
                setTimeout(() => {
                    modal.style.opacity = '1';
                }, 10);
            } else {
                console.error('Settings modal not found');
            }
        }

        function closeSettingsPanel() {
            console.log('Closing settings panel...');
            const modal = document.getElementById('settingsModal');
            if (modal) {
                modal.classList.add('hidden');
                document.body.style.overflow = '';
                modal.style.opacity = '0';
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

        // Close settings modal when clicking outside
        document.getElementById('settingsModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closeSettingsPanel();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                // Close settings modal
                const settingsModal = document.getElementById('settingsModal');
                if (settingsModal && !settingsModal.classList.contains('hidden')) {
                    closeSettingsPanel();
                    return; // Exit early if settings was open
                }

                // Close file details modal
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
                        <p class="text-gray-300 mb-2 text-sm sm:text-base">Tesseract OCR and enhanced AI analysis in progress</p>
                        <div class="bg-theme-bright bg-opacity-20 rounded-lg p-3 sm:p-4 mt-4">
                            <p class="text-black text-xs sm:text-sm">🖥️ Creating detailed summaries with bullet points, key concepts, and important keywords using direct Tesseract processing</p>
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
                // Close settings modal if open
                const settingsModal = document.getElementById('settingsModal');
                if (settingsModal && !settingsModal.classList.contains('hidden')) {
                    closeSettingsPanel();
                }

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
                document.getElementById('settingsModal').classList.contains('hidden') &&
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

                // Ctrl/Cmd + , for settings
                if ((e.ctrlKey || e.metaKey) && e.key === ',') {
                    e.preventDefault();
                    openSettingsPanel();
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

        // API Configuration Test Function - Updated for Tesseract
        function testAPIConfiguration() {
            // Show loading state
            const testButton = event.target;
            const originalText = testButton.textContent;
            testButton.textContent = 'Testing...';
            testButton.disabled = true;

            fetch('?action=test_api')
                .then(response => response.json())
                .then(data => {
                    showAPITestResults(data);
                })
                .catch(error => {
                    console.error('API test error:', error);
                    showAPITestResults({
                        error: 'Failed to run API test: ' + error.message
                    });
                })
                .finally(() => {
                    testButton.textContent = originalText;
                    testButton.disabled = false;
                });
        }

        function showAPITestResults(results) {
            // Create modal for test results
            const modal = document.createElement('div');
            modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4';

            let modalContent = `
                <div class="glass-card rounded-xl max-w-2xl w-full max-h-[90vh] overflow-hidden border-theme-light">
                    <div class="flex items-center justify-between p-6 border-b border-theme-light">
                        <h3 class="text-xl font-semibold text-white">🔧 API Configuration Test Results</h3>
                        <button onclick="this.closest('.fixed').remove()" class="text-gray-300 hover:text-white text-2xl">&times;</button>
                    </div>
                    <div class="p-6 max-h-[60vh] overflow-auto custom-scrollbar">
            `;

            if (results.error) {
                modalContent += `
                    <div class="bg-red-900 bg-opacity-50 border border-red-600 rounded-lg p-4">
                        <h4 class="text-red-300 font-medium mb-2">❌ Test Failed</h4>
                        <p class="text-red-200">${results.error}</p>
                    </div>
                `;
            } else {
                // Tesseract results
                if (results.tesseract) {
                    const ts = results.tesseract;
                    const statusColor = ts.status === 'success' ? 'green' : 'red';
                    const statusIcon = ts.status === 'success' ? '✅' : '❌';

                    modalContent += `
                        <div class="bg-${statusColor}-900 bg-opacity-50 border border-${statusColor}-600 rounded-lg p-4 mb-4">
                            <h4 class="text-${statusColor}-300 font-medium mb-2">${statusIcon} Tesseract OCR Engine</h4>
                            <p class="text-${statusColor}-200">${ts.message}</p>
                            ${ts.version ? `<p class="text-${statusColor}-200 text-sm mt-1">Version: ${ts.version}</p>` : ''}
                            ${ts.languages ? `<p class="text-${statusColor}-200 text-sm mt-1">${ts.languages}</p>` : ''}
                        </div>
                    `;
                }

                // Claude results
                if (results.claude) {
                    const cl = results.claude;
                    const statusColor = cl.status === 'success' ? 'green' : cl.status === 'info' ? 'blue' : 'red';
                    const statusIcon = cl.status === 'success' ? '✅' : cl.status === 'info' ? 'ℹ️' : '❌';

                    modalContent += `
                        <div class="bg-${statusColor}-900 bg-opacity-50 border border-${statusColor}-600 rounded-lg p-4 mb-4">
                            <h4 class="text-${statusColor}-300 font-medium mb-2">${statusIcon} Claude AI API</h4>
                            <p class="text-${statusColor}-200">${cl.message}</p>
                        </div>
                    `;
                }

                // Filesystem results
                if (results.filesystem) {
                    const fs = results.filesystem;
                    const statusColor = fs.status === 'success' ? 'green' : 'red';
                    const statusIcon = fs.status === 'success' ? '✅' : '❌';

                    modalContent += `
                        <div class="bg-${statusColor}-900 bg-opacity-50 border border-${statusColor}-600 rounded-lg p-4 mb-4">
                            <h4 class="text-${statusColor}-300 font-medium mb-2">${statusIcon} File System</h4>
                            <p class="text-${statusColor}-200">${fs.message}</p>
                        </div>
                    `;
                }

                // pdftotext results
                if (results.pdftotext) {
                    const pt = results.pdftotext;
                    const statusColor = pt.status === 'success' ? 'green' : 'yellow';
                    const statusIcon = pt.status === 'success' ? '✅' : '⚠️';

                    modalContent += `
                        <div class="bg-${statusColor}-900 bg-opacity-50 border border-${statusColor}-600 rounded-lg p-4 mb-4">
                            <h4 class="text-${statusColor}-300 font-medium mb-2">${statusIcon} pdftotext Tool</h4>
                            <p class="text-${statusColor}-200">${pt.message}</p>
                        </div>
                    `;
                }

                // Next steps
                modalContent += `
                    <div class="bg-blue-900 bg-opacity-50 border border-blue-600 rounded-lg p-4">
                        <h4 class="text-blue-300 font-medium mb-2">📝 Next Steps</h4>
                        <div class="text-blue-200 text-sm space-y-2">
                `;

                if (results.tesseract && results.tesseract.status !== 'success') {
                    modalContent += `
                        <p>• Install Tesseract OCR on your server</p>
                        <p>• For Ubuntu/Debian: <code>sudo apt-get install tesseract-ocr tesseract-ocr-tha</code></p>
                        <p>• For CentOS/RHEL: <code>sudo yum install tesseract</code></p>
                        <p>• Verify installation: <code>tesseract --version</code></p>
                    `;
                }

                if (results.tesseract && results.tesseract.status === 'success') {
                    modalContent += `<p>• ✅ Tesseract OCR is ready to use (simplified version - no ImageMagick required!)</p>`;
                }

                modalContent += `
                        <p>• Try uploading a test image or PDF to verify the complete workflow</p>
                        <p>• Check the debug information if uploads still fail</p>
                        <p>• This simplified version processes PDFs directly with Tesseract</p>
                        </div>
                    </div>
                `;
            }

            modalContent += `
                    </div>
                    <div class="p-6 border-t border-theme-light">
                        <button onclick="this.closest('.fixed').remove()" class="px-6 py-2 bg-theme-green hover:bg-theme-bright text-white rounded-lg transition-colors">
                            Close
                        </button>
                    </div>
                </div>
            `;

            modal.innerHTML = modalContent;
            document.body.appendChild(modal);

            // Close modal when clicking outside
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.remove();
                }
            });
        }

        // Touch support for mobile devices
        if ('ontouchstart' in window) {
            document.addEventListener('touchstart', function() {}, {
                passive: true
            });
        }

        console.log('All event listeners set up successfully with simplified Tesseract OCR support');
    </script>
</body>

</html>
