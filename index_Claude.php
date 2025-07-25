<?php
session_start();



header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');

// Include authentication functions
require_once 'auth.php'; // This should contain your login functions

// AUTHENTICATION CHECK - Redirect if not logged in
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

// Load secure configuration and database FIRST
require_once 'config.php';
require_once 'database.php';

// Configuration
define('UPLOAD_DIR', 'uploads/');
define('CLAUDE_API_KEY', '');

// Enhanced cleanForJson function (defined early to avoid conflicts)
function cleanForJsonSafe($text)
{
    if (!$text) return '';

    try {
        // Convert to UTF-8 if needed
        $clean = mb_convert_encoding($text, 'UTF-8', 'auto');

        // Remove control characters that break JSON
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $clean);

        // Handle problematic characters more carefully
        $clean = str_replace(["\r\n", "\r", "\n"], ' ', $clean);

        // Clean up multiple spaces
        $clean = preg_replace('/\s+/', ' ', trim($clean));

        // Additional safety: remove any remaining non-printable characters
        $clean = preg_replace('/[^\P{C}]+/u', '', $clean);

        return $clean;
    } catch (Exception $e) {
        error_log("Error in cleanForJsonSafe: " . $e->getMessage());
        return ''; // Return empty string on error
    }
}

// Safe JSON encoding function
function safeJsonEncode($data)
{
    try {
        // First attempt with our flags
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_QUOT | JSON_HEX_APOS | JSON_HEX_TAG | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($json === false) {
            error_log("JSON encoding failed: " . json_last_error_msg());

            // Clean the data more aggressively
            $cleaned_data = array_map(function ($item) {
                if (is_array($item)) {
                    return array_map(function ($value) {
                        if (is_string($value)) {
                            return cleanForJsonSafe($value);
                        }
                        return $value;
                    }, $item);
                }
                return $item;
            }, $data);

            // Try again with cleaned data
            $json = json_encode($cleaned_data, JSON_UNESCAPED_UNICODE | JSON_HEX_QUOT | JSON_HEX_APOS | JSON_HEX_TAG);
        }

        if ($json === false) {
            error_log("JSON encoding failed even after cleaning: " . json_last_error_msg());
            return '[]'; // Return empty array as fallback
        }

        return $json;
    } catch (Exception $e) {
        error_log("Exception in safeJsonEncode: " . $e->getMessage());
        return '[]';
    }
}

// Enhanced getUserFiles function - shows only one entry per PDF
function getUserFilesSafe($user_id)
{
    try {
        $pdo = getDBConnection();

        // Check if language column exists
        try {
            $check_column = $pdo->query("SHOW COLUMNS FROM user_files LIKE 'language'");
            if ($check_column->rowCount() == 0) {
                $pdo->exec("ALTER TABLE user_files ADD COLUMN language VARCHAR(5) DEFAULT 'en' AFTER file_type");
                error_log("Added language column to user_files table");
            }
        } catch (Exception $e) {
            error_log("Error checking/adding language column: " . $e->getMessage());
        }

        // MODIFIED QUERY: Exclude PDF pages from main view, but include regular files and original PDFs
        $sql = "SELECT *, DATE(created_at) as date FROM user_files 
        WHERE user_id = ? 
        ORDER BY created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id]);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted_files = [];
        foreach ($files as $file) {
            try {
                // Safe file reading with UTF-8 support
                $summary_content = 'No summary available';
                $full_summary = 'No summary available';
                $file_language = isset($file['language']) ? $file['language'] : 'en';

                // Replace this section in getUserFilesSafe() function:
                // FOR PDF FILES: Get AI analysis from the first converted page
                if ($file['file_type'] === 'pdf') {
                    $page_sql = "SELECT * FROM user_files WHERE user_id = ? AND is_pdf_page = 1 AND original_pdf_path = ? ORDER BY pdf_page_number ASC LIMIT 1";
                    $page_stmt = $pdo->prepare($page_sql);
                    $page_stmt->execute([$user_id, $file['original_file_path']]);
                    $first_page = $page_stmt->fetch();

                    if ($first_page && !empty($first_page['summary_file_path']) && file_exists($first_page['summary_file_path'])) {
                        // Get AI analysis from the converted page
                        $file_contents = file_get_contents($first_page['summary_file_path']);
                        if ($file_contents !== false) {
                            $full_summary = mb_convert_encoding($file_contents, 'UTF-8', 'auto');
                            $summary_content = mb_substr($full_summary, 0, 200, 'UTF-8') . '...';
                            $file_language = $first_page['language'] ?? 'en'; // Use language from AI analysis
                        }
                    } else {
                        // Fallback for PDFs without converted pages
                        $summary_content = ($file_language === 'th') ? 'ไฟล์ PDF ต้นฉบับ พร้อมสำหรับการดู' : 'Original PDF file ready for viewing';
                        $full_summary = $summary_content;
                    }
                } else {
                    // FOR NON-PDF FILES: Use regular summary
                    if (!empty($file['summary_file_path']) && file_exists($file['summary_file_path'])) {
                        $file_contents = file_get_contents($file['summary_file_path']);
                        if ($file_contents !== false) {
                            $full_summary = mb_convert_encoding($file_contents, 'UTF-8', 'auto');
                            $summary_content = mb_substr($full_summary, 0, 200, 'UTF-8') . '...';
                        }
                    }
                }

                // Set appropriate "No summary" message based on language
                if ($summary_content === 'No summary available') {
                    $summary_content = ($file_language === 'th') ? 'ไม่มีข้อมูลสรุป' : 'No summary available';
                    $full_summary = $summary_content;
                }

                // CRITICAL: Clean all text data for safe JSON encoding
                $formatted_files[] = [
                    'id' => (int)$file['id'],
                    'name' => cleanForJsonSafe($file['file_name'] ?? ''),
                    'subject' => cleanForJsonSafe($file['subject'] ?? ''),
                    'date' => $file['date'] ?? date('Y-m-d'),
                    'summary' => cleanForJsonSafe($summary_content),
                    'full_summary' => cleanForJsonSafe($full_summary),
                    'language' => $file_language,
                    'status' => 'completed',
                    'debug_info' => '',
                    'extracted_text' => '',
                    'summary_file_path' => $file['summary_file_path'] ?? '',
                    'original_file_path' => $file['original_file_path'] ?? ''
                ];
            } catch (Exception $e) {
                error_log("Error processing file ID {$file['id']}: " . $e->getMessage());
                continue;
            }
        }

        return $formatted_files;
    } catch (Exception $e) {
        error_log("Error in getUserFilesSafe: " . $e->getMessage());
        return [];
    }
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

// Handle request for latest PDF file ID
if (isset($_GET['get_latest_pdf']) && isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $pdo = getDBConnection();

    try {
        // Get the most recently uploaded PDF file that is NOT a page
        // NEW: Get the most recently uploaded PDF file
        $sql = "SELECT id FROM user_files WHERE user_id = ? AND file_type = 'pdf' ORDER BY created_at DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id]);
        $result = $stmt->fetch();

        if ($result) {
            echo $result['id'];
            error_log("Latest PDF ID returned: " . $result['id']);
        } else {
            echo '0';
            error_log("No PDF files found for user: " . $user_id);
        }
    } catch (Exception $e) {
        error_log("Error getting latest PDF: " . $e->getMessage());
        echo '0';
    }
    exit();
}
if (isset($_GET['debug_pdf_files']) && isset($_SESSION['user_id'])) {
    $pdo = getDBConnection();
    $sql = "SELECT id, file_name, file_type, is_pdf_page, original_pdf_path, pdf_page_number FROM user_files WHERE user_id = ? ORDER BY created_at DESC LIMIT 10";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$_SESSION['user_id']]);
    $files = $stmt->fetchAll();

    echo "<h2>Debug: PDF Files Structure</h2><pre>";
    print_r($files);
    echo "</pre>";
    exit();
}
function testClaudeAPIConnection()
{
    $api_key = CLAUDE_API_KEY;

    if (empty($api_key) || $api_key === 'your_api_key_here') {
        return [
            'success' => false,
            'http_code' => 0,
            'response' => 'API key not configured'
        ];
    }

    $test_request = [
        'model' => 'claude-3-5-sonnet-20241022',
        'max_tokens' => 100,
        'messages' => [
            [
                'role' => 'user',
                'content' => 'Respond with just "API connection successful"'
            ]
        ]
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://api.anthropic.com/v1/messages",
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01'
        ],
        CURLOPT_POSTFIELDS => json_encode($test_request),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false // For testing only
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return [
            'success' => false,
            'http_code' => 0,
            'response' => 'cURL Error: ' . $curl_error
        ];
    }

    return [
        'success' => $http_code === 200,
        'http_code' => $http_code,
        'response' => $response
    ];
}

// Add this test code right after the require_once statements at the top
if (isset($_GET['test_claude_api'])) {
    echo "<!DOCTYPE html><html><head><title>Claude API Test</title></head><body>";
    echo "<h2>🧪 Claude API Connection Test</h2>";

    $test_result = testClaudeAPIConnection();

    echo "<div style='background: #f5f5f5; padding: 20px; border-radius: 8px; font-family: monospace;'>";
    echo "<h3>Test Results:</h3>";
    echo "<strong>HTTP Code:</strong> " . $test_result['http_code'] . "<br>";
    echo "<strong>Success:</strong> " . ($test_result['success'] ? '✅ YES' : '❌ NO') . "<br>";
    echo "<strong>API Key Status:</strong> " . (empty(CLAUDE_API_KEY) ? '❌ Missing' : '✅ Present') . "<br>";

    if (!$test_result['success']) {
        echo "<br><strong>Error Details:</strong><br>";
        echo "<pre style='background: #ffe6e6; padding: 10px; border-radius: 4px;'>";

        // Try to decode JSON error response
        $error_response = json_decode($test_result['response'], true);
        if ($error_response && isset($error_response['error'])) {
            echo "Error Type: " . ($error_response['error']['type'] ?? 'unknown') . "\n";
            echo "Error Message: " . ($error_response['error']['message'] ?? 'unknown') . "\n";
        } else {
            echo htmlspecialchars($test_result['response']);
        }
        echo "</pre>";

        // Provide troubleshooting guidance
        echo "<br><h3>🔧 Troubleshooting:</h3>";
        echo "<ul>";

        switch ($test_result['http_code']) {
            case 0:
                echo "<li><strong>Connection Error:</strong> Check your internet connection and firewall settings</li>";
                break;
            case 400:
                echo "<li><strong>Bad Request:</strong> Check your API request format or model name</li>";
                break;
            case 401:
                echo "<li><strong>Unauthorized:</strong> Your API key is invalid or expired</li>";
                echo "<li>Go to <a href='https://console.anthropic.com' target='_blank'>Anthropic Console</a> to check your API key</li>";
                break;
            case 403:
                echo "<li><strong>Forbidden:</strong> Your API key doesn't have permission to access Claude API</li>";
                break;
            case 429:
                echo "<li><strong>Rate Limited:</strong> You've exceeded the API rate limits</li>";
                break;
            case 500:
            case 502:
            case 503:
            case 504:
                echo "<li><strong>Server Error:</strong> Anthropic's servers are having issues, try again later</li>";
                break;
            default:
                echo "<li><strong>Unknown Error:</strong> HTTP " . $test_result['http_code'] . "</li>";
        }

        echo "</ul>";
    } else {
        echo "<br><h3>✅ Connection Successful!</h3>";
        echo "<p>Your Claude API is working correctly.</p>";

        // Show actual response
        $response_data = json_decode($test_result['response'], true);
        if ($response_data && isset($response_data['content'][0]['text'])) {
            echo "<strong>Claude Response:</strong> " . htmlspecialchars($response_data['content'][0]['text']) . "<br>";
        }
    }

    echo "</div>";
    echo "<br><a href='dashboard.php' style='background: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;'>← Back to Dashboard</a>";
    echo "</body></html>";
    exit;
}

// ADD THIS DEBUG ENDPOINT - TEMPORARY FOR TESTING
if (isset($_GET['debug_classification']) && isset($_SESSION['user_id'])) {
    echo "<!DOCTYPE html><html><head><title>Claude Classification Debug</title></head><body>";
    echo "<h2>🐛 Claude Classification Debug Tool</h2>";
    echo "<style>body{font-family:monospace;margin:20px;} .debug{background:#f5f5f5;padding:15px;margin:10px 0;border-radius:5px;} .success{background:#d4edda;} .error{background:#f8d7da;} .warning{background:#fff3cd;}</style>";

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['debug_file'])) {
        $uploaded_file = $_FILES['debug_file'];

        if ($uploaded_file['error'] === UPLOAD_ERR_OK) {
            echo "<div class='debug'><h3>📁 File Info:</h3>";
            echo "Name: " . htmlspecialchars($uploaded_file['name']) . "<br>";
            echo "Size: " . round($uploaded_file['size'] / 1024, 2) . " KB<br>";
            echo "Type: " . $uploaded_file['type'] . "<br>";
            echo "</div>";

            // Process with Claude
            $temp_path = UPLOAD_DIR . 'debug_temp_' . time() . '_' . $uploaded_file['name'];
            if (move_uploaded_file($uploaded_file['tmp_name'], $temp_path)) {
                echo "<div class='debug success'><h3>✅ File uploaded successfully</h3></div>";

                // Call our Claude processing function
                $result = processWithClaudeOCRandNLP($temp_path, $uploaded_file['name']);

                echo "<div class='debug'><h3>🤖 Claude Processing Result:</h3>";
                echo "<strong>Subject:</strong> " . htmlspecialchars($result['subject']) . "<br>";
                echo "<strong>Language:</strong> " . htmlspecialchars($result['language']) . "<br>";
                echo "<strong>Debug Info:</strong> " . htmlspecialchars($result['debug']) . "<br>";
                echo "<strong>Summary Length:</strong> " . strlen($result['summary']) . " characters<br>";
                echo "</div>";

                if (!empty($result['summary'])) {
                    echo "<div class='debug'><h3>📝 Summary Content:</h3>";
                    echo "<textarea style='width:100%;height:200px;'>" . htmlspecialchars($result['summary']) . "</textarea>";
                    echo "</div>";
                }

                if (!empty($result['extracted_text'])) {
                    echo "<div class='debug'><h3>📄 Extracted Text:</h3>";
                    echo "<textarea style='width:100%;height:150px;'>" . htmlspecialchars(substr($result['extracted_text'], 0, 1000)) . "</textarea>";
                    echo "</div>";
                }

                // Clean up temp file
                unlink($temp_path);
            } else {
                echo "<div class='debug error'><h3>❌ Failed to upload file</h3></div>";
            }
        } else {
            echo "<div class='debug error'><h3>❌ Upload Error:</h3>";
            echo "Error code: " . $uploaded_file['error'] . "</div>";
        }
    }

    echo "<div class='debug'><h3>🔬 Test File Classification:</h3>";
    echo "<form method='post' enctype='multipart/form-data'>";
    echo "<input type='file' name='debug_file' accept='image/*' required>";
    echo "<button type='submit' style='margin-left:10px;padding:5px 10px;'>Debug This File</button>";
    echo "</form></div>";

    echo "<br><a href='dashboard.php' style='background: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;'>← Back to Dashboard</a>";
    echo "</body></html>";
    exit;
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

/**
 * Enhanced function using Claude for both OCR and NLP analysis
 */
function processWithClaudeOCRandNLP($file_path, $filename)
{
    $api_key = CLAUDE_API_KEY;

    if (empty($api_key)) {
        return [
            'subject' => 'Others',
            'summary' => 'Claude API key not configured',
            'debug' => 'API key missing',
            'language' => 'en',
            'extracted_text' => ''
        ];
    }

    // Validate file
    if (!file_exists($file_path) || !is_readable($file_path)) {
        return [
            'subject' => 'Others',
            'summary' => 'File not found or not readable',
            'debug' => 'File access error: ' . $file_path,
            'language' => 'en',
            'extracted_text' => ''
        ];
    }

    // Check file size (15MB limit for base64 encoding)
    $file_size = filesize($file_path);
    $max_size = 15 * 1024 * 1024; // 15MB

    if ($file_size > $max_size) {
        return [
            'subject' => 'Others',
            'summary' => 'File too large for Claude processing (max 15MB)',
            'debug' => "File size: " . round($file_size / (1024 * 1024), 2) . "MB exceeds 15MB limit",
            'language' => 'en',
            'extracted_text' => ''
        ];
    }

    $file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

    // FIXED: Only support image formats that Claude Vision API actually supports
    $supported_image_types = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp'
    ];


    // Check if file type is supported
    if (!isset($supported_image_types[$file_extension])) {
        return [
            'subject' => 'Others',
            'summary' => 'Unsupported file type. Supported formats: JPG, PNG, GIF, WebP',
            'debug' => 'Unsupported extension: ' . $file_extension,
            'language' => 'en',
            'extracted_text' => ''
        ];
    }

    $media_type = $supported_image_types[$file_extension];

    // Read and encode file with error handling
    try {
        $file_content = file_get_contents($file_path);
        if ($file_content === false) {
            throw new Exception("Could not read file content");
        }

        $file_data = base64_encode($file_content);
        if ($file_data === false) {
            throw new Exception("Could not encode file to base64");
        }

        // Check encoded size (base64 increases size by ~33%)
        if (strlen($file_data) > 20 * 1024 * 1024) {
            throw new Exception("Encoded file too large for API");
        }
    } catch (Exception $e) {
        return [
            'subject' => 'Others',
            'summary' => 'Error reading file: ' . $e->getMessage(),
            'debug' => 'File processing error: ' . $e->getMessage(),
            'language' => 'en',
            'extracted_text' => ''
        ];
    }

    // FIXED: Improved prompt with clearer instructions
    $prompt = "Analyze this educational document image and extract text with subject classification.

Document: {$filename}

CLASSIFICATION GUIDELINES:
🔬 **Biology**: Living organisms, cells, genetics, anatomy, ecology, evolution, biochemistry, microbiology, botany, zoology, human body systems, diseases, medical topics
🧪 **Chemistry**: Chemical reactions, molecules, atoms, periodic table, compounds, chemical formulas, organic/inorganic chemistry, chemical equations, lab procedures
⚛️ **Physics**: Motion, energy, forces, electricity, magnetism, waves, thermodynamics, mechanics, optics, nuclear physics, quantum physics, mathematical physics
🔢 **Mathematics**: Numbers, equations, calculus, algebra, geometry, statistics, trigonometry, mathematical proofs, mathematical symbols, graphs, mathematical formulas
📄 **Others**: Languages, literature, history, social studies, art, music, general knowledge, mixed subjects, unclear content

STRICT CLASSIFICATION RULES:
- If document contains primarily mathematical equations/formulas → Mathematics
- If document discusses living things, body parts, cells, diseases → Biology  
- If document has chemical formulas, reactions, molecular structures → Chemistry
- If document covers forces, energy, motion, electrical concepts → Physics
- If unsure or mixed content → Others
- Medical/health topics → Biology
- Engineering calculations → Physics or Mathematics (choose based on primary focus)

Tasks:
1. Extract ALL visible text exactly as shown
2. Detect language (Thai or English)
3. Classify subject using STRICT rules above - be very careful!
4. Create educational summary in the SAME language as the detected content

Respond with ONLY valid JSON in this format:

For Thai content:
{
    \"language\": \"th\",
    \"subject\": \"Biology\",
    \"extracted_text\": \"[all text from image]\",
    \"summary\": \"หัวข้อ: [topic]\\n\\nสาระสำคัญ:\\n• [point 1]\\n• [point 2]\\n\\nคำศัพท์สำคัญ: [keywords]\\n\\nหมวดวิชา: [subject with reasoning in Thai]\"
}

For English content:
{
    \"language\": \"en\", 
    \"subject\": \"Physics\",
    \"extracted_text\": \"[all text from image]\",
    \"summary\": \"Topic: [topic]\\n\\nKey Points:\\n• [point 1]\\n• [point 2]\\n\\nKeywords: [keywords]\\n\\nSubject Classification: [subject with reasoning in English]\"
}

IMPORTANT: 
- Provide summary ONLY in the detected language (no dual summaries)
- Choose subject classification very carefully based on PRIMARY content focus
- When in doubt, use 'Others'";

    // FIXED: Updated request structure with proper error handling
    $request_data = [
        'model' => 'claude-3-5-sonnet-20241022', // FIXED: Use confirmed working model
        'max_tokens' => 1500,
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $prompt
                    ],
                    [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $media_type,
                            'data' => $file_data
                        ]
                    ]
                ]
            ]
        ]
    ];

    // Add detailed logging for debugging
    error_log("=== CLAUDE API REQUEST DEBUG ===");
    error_log("File: $filename");
    error_log("Size: " . round($file_size / 1024, 2) . " KB");
    error_log("Type: $media_type");
    error_log("Base64 size: " . round(strlen($file_data) / 1024, 2) . " KB");

    return makeClaudeAPIRequest($request_data, $api_key, $filename);
}

// FIXED: Improved API request function with better error handling
function makeClaudeAPIRequest($request_data, $api_key, $filename)
{
    $url = "https://api.anthropic.com/v1/messages";
    $max_retries = 3;
    $base_delay = 2;

    for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
        error_log("Claude API attempt $attempt for: $filename");

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $api_key,
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_POSTFIELDS => json_encode($request_data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true, // FIXED: Enable SSL verification
            CURLOPT_USERAGENT => 'OZNOTE/1.0',
            CURLOPT_VERBOSE => false // Set to true for debugging
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);

        // FIXED: Better error logging
        if ($curl_error) {
            error_log("cURL Error (attempt $attempt): $curl_error");
        }

        if ($http_code !== 200) {
            error_log("HTTP Error $http_code: " . substr($response, 0, 200));
        }

        curl_close($ch);

        // Handle cURL errors
        if ($curl_error) {
            if ($attempt === $max_retries) {
                return [
                    'subject' => 'Others',
                    'summary' => 'Network connection error',
                    'debug' => 'cURL Error: ' . $curl_error,
                    'language' => 'en',
                    'extracted_text' => ''
                ];
            }
            sleep($base_delay * $attempt);
            continue;
        }

        // Handle HTTP errors with specific messages
        if ($http_code !== 200) {
            $error_details = '';
            if ($response) {
                $error_response = json_decode($response, true);
                if (isset($error_response['error']['message'])) {
                    $error_details = $error_response['error']['message'];
                }
            }

            // FIXED: More specific error messages
            switch ($http_code) {
                case 400:
                    return [
                        'subject' => 'Others',
                        'summary' => 'Invalid request format. This usually means the file type or size is not supported.',
                        'debug' => "HTTP 400 - Bad Request: $error_details",
                        'language' => 'en',
                        'extracted_text' => ''
                    ];
                case 401:
                    return [
                        'subject' => 'Others',
                        'summary' => 'Invalid Claude API key. Please check your API key.',
                        'debug' => "HTTP 401 - Unauthorized: $error_details",
                        'language' => 'en',
                        'extracted_text' => ''
                    ];
                case 403:
                    return [
                        'subject' => 'Others',
                        'summary' => 'API access forbidden. Check your subscription or permissions.',
                        'debug' => "HTTP 403 - Forbidden: $error_details",
                        'language' => 'en',
                        'extracted_text' => ''
                    ];
                case 413:
                    return [
                        'subject' => 'Others',
                        'summary' => 'File too large for Claude API.',
                        'debug' => "HTTP 413 - Payload Too Large: $error_details",
                        'language' => 'en',
                        'extracted_text' => ''
                    ];
                case 429:
                    if ($attempt < $max_retries) {
                        $wait_time = $base_delay * pow(2, $attempt);
                        error_log("Rate limited, waiting {$wait_time}s...");
                        sleep($wait_time);
                        continue;
                    }
                    return [
                        'subject' => 'Others',
                        'summary' => 'API rate limit exceeded. Please try again later.',
                        'debug' => "HTTP 429 - Rate Limited: $error_details",
                        'language' => 'en',
                        'extracted_text' => ''
                    ];
                case 500:
                case 502:
                case 503:
                case 504:
                    if ($attempt < $max_retries) {
                        $wait_time = $base_delay * $attempt;
                        error_log("Server error, retrying in {$wait_time}s...");
                        sleep($wait_time);
                        continue;
                    }
                    break;
            }

            return [
                'subject' => 'Others',
                'summary' => "Claude API error (HTTP $http_code). Please try again later.",
                'debug' => "HTTP $http_code: $error_details",
                'language' => 'en',
                'extracted_text' => ''
            ];
        }

        // Success - parse response
        return parseClaudeResponse($response, $filename);
    }

    return [
        'subject' => 'Others',
        'summary' => 'All retry attempts failed',
        'debug' => 'Exhausted all retries',
        'language' => 'en',
        'extracted_text' => ''
    ];
}

// FIXED: Better JSON parsing
// FIXED: Enhanced JSON parsing function that properly handles Thai content
function parseClaudeResponse($response, $filename)
{
    $result = json_decode($response, true);

    if (!$result || !isset($result['content'][0]['text'])) {
        return [
            'subject' => 'Others',
            'summary' => 'Invalid response from Claude API',
            'debug' => 'Malformed API response structure',
            'language' => 'en',
            'extracted_text' => ''
        ];
    }

    $claude_response = $result['content'][0]['text'];

    // FIXED: Ensure UTF-8 encoding
    $claude_response = mb_convert_encoding($claude_response, 'UTF-8', 'auto');

    // Store debug info
    $debug_info = [];
    $debug_info[] = "Claude response length: " . strlen($claude_response);
    $debug_info[] = "Claude response preview: " . mb_substr($claude_response, 0, 200, 'UTF-8') . "...";

    // FIXED: Enhanced JSON extraction patterns that handle Thai Unicode
    $json_patterns = [
        // Pattern 1: Direct JSON object (most common)
        '/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/u',
        // Pattern 2: More flexible with Unicode support
        '/\{[\s\S]*?\}/u',
        // Pattern 3: JSON in code blocks
        '/```json\s*(\{[\s\S]*?\})\s*```/u',
        // Pattern 4: JSON in code blocks without json tag
        '/```\s*(\{[\s\S]*?\})\s*```/u',
        // Pattern 5: NEW - Handle nested braces with Thai content
        '/\{(?:[^{}]|(?:\{[^{}]*\}))*\}/u',
        // Pattern 6: NEW - Match everything between first { and last }
        '/\{.*\}/su'
    ];

    $analysis = null;
    $json_str = '';

    foreach ($json_patterns as $i => $pattern) {
        if (preg_match($pattern, $claude_response, $matches)) {
            $json_str = isset($matches[1]) ? $matches[1] : $matches[0];

            // FIXED: Clean the JSON string for Thai content
            $json_str = cleanJsonString($json_str);

            $debug_info[] = "Found JSON with pattern #" . ($i + 1);
            $debug_info[] = "Extracted JSON length: " . strlen($json_str);

            // FIXED: Try to decode with proper error handling
            $analysis = json_decode($json_str, true, 512, JSON_UNESCAPED_UNICODE);

            if (json_last_error() === JSON_ERROR_NONE && $analysis && isset($analysis['subject'], $analysis['summary'])) {
                $debug_info[] = "✅ Successfully parsed JSON";
                break;
            } else {
                $debug_info[] = "❌ JSON decode error: " . json_last_error_msg();
                $analysis = null; // Reset for next attempt
            }
        }
    }

    // FIXED: If all patterns fail, try extracting key-value pairs manually
    if (!$analysis) {
        $debug_info[] = "⚠️ Attempting manual extraction...";
        $analysis = extractJsonManually($claude_response);
        if ($analysis) {
            $debug_info[] = "✅ Manual extraction successful";
        }
    }

    if ($analysis && isset($analysis['subject'], $analysis['summary'])) {
        // Validate and clean the extracted data
        $subject = validateSubject($analysis['subject']);
        $language = isset($analysis['language']) ? $analysis['language'] : detectLanguageFromContent($analysis['summary']);

        $debug_info[] = "FINAL - Subject: " . $subject;
        $debug_info[] = "FINAL - Language: " . $language;

        return [
            'subject' => $subject,
            'summary' => cleanForJsonSafe($analysis['summary'] ?? ''),
            'language' => $language,
            'extracted_text' => cleanForJsonSafe($analysis['extracted_text'] ?? ''),
            'debug' => implode(" | ", $debug_info)
        ];
    }

    // ENHANCED: Better fallback with language detection
    $detected_language = detectLanguageFromContent($claude_response);
    $debug_info[] = "❌ ALL PARSING FAILED - using enhanced fallback";

    // Try one more desperate attempt to extract usable content
    $fallback_summary = extractFallbackSummary($claude_response, $detected_language);

    return [
        'subject' => 'Others',
        'summary' => $fallback_summary,
        'language' => $detected_language,
        'extracted_text' => mb_substr($claude_response, 0, 500, 'UTF-8'),
        'debug' => implode(" | ", $debug_info)
    ];
}

// NEW: Helper function to clean JSON strings for Thai content
function cleanJsonString($json_str)
{
    // Remove any leading/trailing whitespace
    $json_str = trim($json_str);

    // Fix common JSON issues with Thai content
    $json_str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $json_str);

    // Ensure proper UTF-8 encoding
    $json_str = mb_convert_encoding($json_str, 'UTF-8', 'auto');

    // Fix unescaped quotes in Thai content
    $json_str = preg_replace('/(?<!\\\\)"(?=(?:[^"\\\\]|\\\\.)*"[^"]*$)/', '\\"', $json_str);

    return $json_str;
}

// NEW: Manual JSON extraction for when regex fails
function extractJsonManually($text)
{
    $analysis = [];

    // Extract language
    if (preg_match('/"language":\s*"(th|en)"/u', $text, $matches)) {
        $analysis['language'] = $matches[1];
    }

    // Extract subject
    if (preg_match('/"subject":\s*"([^"]+)"/u', $text, $matches)) {
        $analysis['subject'] = $matches[1];
    }

    // Extract summary (more complex due to potential newlines and Thai text)
    if (preg_match('/"summary":\s*"((?:[^"\\\\]|\\\\.)*)"/su', $text, $matches)) {
        $analysis['summary'] = stripcslashes($matches[1]);
    }

    // Extract extracted_text
    if (preg_match('/"extracted_text":\s*"((?:[^"\\\\]|\\\\.)*)"/su', $text, $matches)) {
        $analysis['extracted_text'] = stripcslashes($matches[1]);
    }

    // Only return if we have the minimum required fields
    if (isset($analysis['subject']) && isset($analysis['summary'])) {
        return $analysis;
    }

    return null;
}

// NEW: Validate and fix subject classification
function validateSubject($subject)
{
    $valid_subjects = ['Physics', 'Biology', 'Chemistry', 'Mathematics', 'Others'];

    // Direct match
    if (in_array($subject, $valid_subjects)) {
        return $subject;
    }

    // Case-insensitive match
    foreach ($valid_subjects as $valid) {
        if (strcasecmp($subject, $valid) === 0) {
            return $valid;
        }
    }

    // Partial match for common variations
    $subject_lower = strtolower($subject);
    if (strpos($subject_lower, 'physic') !== false) return 'Physics';
    if (strpos($subject_lower, 'biolog') !== false) return 'Biology';
    if (strpos($subject_lower, 'chemi') !== false) return 'Chemistry';
    if (strpos($subject_lower, 'math') !== false) return 'Mathematics';

    return 'Others';
}

// NEW: Enhanced fallback summary extraction
function extractFallbackSummary($response, $language)
{
    // Try to extract any meaningful content from the response
    $lines = explode("\n", $response);
    $content_lines = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if (strlen($line) > 20 && !preg_match('/^[\{\}\[\]",:]/', $line)) {
            $content_lines[] = $line;
        }
    }

    if (count($content_lines) > 0) {
        $extracted_content = implode("\n", array_slice($content_lines, 0, 3));
        return ($language === 'th')
            ? "เอกสารได้รับการประมวลผลแล้ว แต่การแยกข้อมูลมีปัญหา\n\nเนื้อหาที่พบ:\n" . $extracted_content
            : "Document processed but data extraction had issues\n\nFound content:\n" . $extracted_content;
    }

    return ($language === 'th')
        ? 'ประมวลผลเอกสารแล้ว แต่การแยกข้อมูล JSON ล้มเหลว - กรุณาลองวิเคราะห์ใหม่'
        : 'Document processed but JSON extraction failed - please try re-analyzing';
}

function processOriginalPdf($uploaded_file, $temp_upload_path, $final_filename, $user_id)
{
    error_log("Processing original PDF: " . $uploaded_file['name']);

    // FIXED: Start in Others folder but we'll move it later
    $subject = 'Others'; // Temporary placement
    $language = 'en';

    $subject_upload_path = getUserUploadPath($user_id, $subject);
    if (!file_exists($subject_upload_path)) {
        mkdir($subject_upload_path, 0755, true);
    }

    $final_upload_path = $subject_upload_path . $final_filename;

    if (rename($temp_upload_path, $final_upload_path)) {
        $summary_content = "Original PDF file: " . $uploaded_file['name'] . "\nThis PDF will be converted to individual pages for OCR analysis.\n\nNote: This file will be moved to the appropriate subject folder after page processing.";

        $summary_file_path = createSummaryTextFile(
            $summary_content,
            $uploaded_file['name'],
            $user_id,
            $subject,
            $language,
            ''
        );

        $file_id = saveFileToDatabase(
            $user_id,
            $uploaded_file['name'],
            $subject,
            'pdf',
            $final_upload_path,
            $summary_file_path,
            $language,
            null,  // No original PDF ID (this IS the original)
            null,  // No page number
            false  // Not a PDF page
        );

        error_log("Original PDF saved with ID: " . $file_id . " (temporarily in Others folder)");

        return [
            'subject' => $subject,
            'summary' => $summary_content,
            'language' => $language,
            'status' => 'completed',
            'file_id' => $file_id,
            'file_path' => $final_upload_path,
            'debug' => 'Original PDF stored successfully in Others folder (will be moved after page processing)'
        ];
    }

    error_log("Failed to rename temp file for original PDF");
    return [
        'subject' => 'Others',
        'summary' => 'Failed to store original PDF',
        'language' => 'en',
        'status' => 'error',
        'extracted_text' => '',
        'debug' => 'File move operation failed'
    ];
}

// NEW: Function to move original PDF to correct subject folder after page processing
function moveOriginalPdfToCorrectFolder($original_pdf_id, $new_subject, $new_language, $user_id)
{
    if (!$original_pdf_id || $new_subject === 'Others') {
        return; // Don't move if no ID or already in Others
    }

    try {
        $pdo = getDBConnection();

        // Get current PDF file info
        $sql = "SELECT * FROM user_files WHERE id = ? AND user_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$original_pdf_id, $user_id]);
        $pdf_file = $stmt->fetch();

        if (!$pdf_file || !file_exists($pdf_file['original_file_path'])) {
            error_log("Cannot move PDF - file not found: ID $original_pdf_id");
            return;
        }

        // Only move if subject is different
        if ($pdf_file['subject'] === $new_subject) {
            error_log("PDF already in correct folder: $new_subject");
            return;
        }

        $new_subject_path = getUserUploadPath($user_id, $new_subject);
        if (!file_exists($new_subject_path)) {
            mkdir($new_subject_path, 0755, true);
        }

        $new_file_path = $new_subject_path . basename($pdf_file['original_file_path']);

        // Move the file
        if (rename($pdf_file['original_file_path'], $new_file_path)) {
            // Update database
            $update_sql = "UPDATE user_files SET subject = ?, original_file_path = ?, language = ? WHERE id = ?";
            $update_stmt = $pdo->prepare($update_sql);
            $success = $update_stmt->execute([$new_subject, $new_file_path, $new_language, $original_pdf_id]);

            if ($success) {
                error_log("✅ Successfully moved original PDF to $new_subject folder");

                // Update summary file
                if (!empty($pdf_file['summary_file_path']) && file_exists($pdf_file['summary_file_path'])) {
                    $updated_summary = "Original PDF file: " . $pdf_file['file_name'] . "\n";
                    $updated_summary .= "Subject Classification: $new_subject\n";
                    $updated_summary .= "Language: " . ($new_language === 'th' ? 'Thai (ภาษาไทย)' : 'English') . "\n";
                    $updated_summary .= "Individual pages have been processed for OCR analysis.\n\n";
                    $updated_summary .= "Updated: " . date('Y-m-d H:i:s');

                    file_put_contents($pdf_file['summary_file_path'], $updated_summary);
                }
            } else {
                error_log("❌ Failed to update database for moved PDF");
            }
        } else {
            error_log("❌ Failed to move PDF file from {$pdf_file['original_file_path']} to $new_file_path");
        }
    } catch (Exception $e) {
        error_log("Error moving original PDF: " . $e->getMessage());
    }
}

// NEW: Helper function for upload error messages
function getUploadErrorMessage($error_code)
{
    switch ($error_code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'File too large for server';
        case UPLOAD_ERR_PARTIAL:
            return 'File upload was interrupted';
        case UPLOAD_ERR_NO_FILE:
            return 'No file was uploaded';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Server configuration error (no temp directory)';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Failed to write file to server';
        case UPLOAD_ERR_EXTENSION:
            return 'File upload stopped by server extension';
        default:
            return 'Unknown upload error';
    }
}
// FIXED: Updated processUploadedFile to handle errors better
function processUploadedFile($uploaded_file)
{
    $file_extension = strtolower(pathinfo($uploaded_file['name'], PATHINFO_EXTENSION));
    $user_id = $_SESSION['user_id'];

    // Check if this is an original PDF upload or PDF analysis
    $is_original_pdf = isset($_POST['is_original_pdf']) && $_POST['is_original_pdf'] === 'true';
    $original_pdf_id = isset($_POST['original_pdf_id']) ? intval($_POST['original_pdf_id']) : null;
    $is_pdf_analysis = isset($_POST['is_pdf_analysis']) && $_POST['is_pdf_analysis'] === 'true';

    // SERVER-SIDE VALIDATION
    // 1. Check file upload errors
    if ($uploaded_file['error'] !== UPLOAD_ERR_OK) {
        return [
            'subject' => 'Others',
            'summary' => 'File upload failed: ' . getUploadErrorMessage($uploaded_file['error']),
            'language' => 'en',
            'status' => 'error',
            'extracted_text' => '',
            'debug' => 'Upload error code: ' . $uploaded_file['error']
        ];
    }

    // 2. Check file size (15MB limit)
    if ($uploaded_file['size'] > 15 * 1024 * 1024) {
        return [
            'subject' => 'Others',
            'summary' => 'File too large: ' . round($uploaded_file['size'] / (1024 * 1024), 1) . 'MB. Maximum allowed: 15MB',
            'language' => 'en',
            'status' => 'error',
            'extracted_text' => '',
            'debug' => 'File size exceeds limit'
        ];
    }

    // 3. Check empty file
    if ($uploaded_file['size'] === 0) {
        return [
            'subject' => 'Others',
            'summary' => 'Empty file uploaded. Please select a valid file.',
            'language' => 'en',
            'status' => 'error',
            'extracted_text' => '',
            'debug' => 'Zero byte file'
        ];
    }

    // Filename sanitization - different approach for original PDFs vs analysis images
    $clean_filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $uploaded_file['name']);
    $clean_filename = substr($clean_filename, 0, 100); // Limit length

    // For original PDFs, keep original name; for others, add timestamp
    if ($is_original_pdf && $file_extension === 'pdf') {
        $final_filename = $clean_filename; // Keep original PDF name
    } else {
        $timestamp = time();
        $final_filename = $timestamp . '_' . $clean_filename;
    }

    $temp_upload_path = UPLOAD_DIR . 'temp_' . time() . '_' . $clean_filename;

    if (!move_uploaded_file($uploaded_file['tmp_name'], $temp_upload_path)) {
        error_log("Failed to move uploaded file: " . $uploaded_file['name']);
        return [
            'subject' => 'Others',
            'summary' => 'Failed to upload file to server',
            'language' => 'en',
            'status' => 'error',
            'extracted_text' => '',
            'debug' => 'File upload to temp directory failed'
        ];
    }

    // Supported file types
    $supported_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];

    if (in_array($file_extension, $supported_extensions)) {

        // HANDLE ORIGINAL PDF UPLOAD (with original filename)
        if ($is_original_pdf && $file_extension === 'pdf') {
            error_log("Uploading original PDF: " . $uploaded_file['name']);

            $subject = 'Others'; // Will be updated after analysis
            $language = 'en';

            $subject_upload_path = getUserUploadPath($user_id, $subject);
            if (!file_exists($subject_upload_path)) {
                mkdir($subject_upload_path, 0755, true);
            }

            $final_upload_path = $subject_upload_path . $final_filename;

            if (rename($temp_upload_path, $final_upload_path)) {
                $summary_content = "📄 PDF Document: " . $uploaded_file['name'] . "\n\n";
                $summary_content .= "This PDF has been uploaded and will be analyzed using AI.\n";
                $summary_content .= "The analysis will be updated once the PDF processing is complete.\n\n";
                $summary_content .= "Status: Processing...\n";
                $summary_content .= "Uploaded: " . date('Y-m-d H:i:s');

                $summary_file_path = createSummaryTextFile(
                    $summary_content,
                    $uploaded_file['name'],
                    $user_id,
                    $subject,
                    $language,
                    ''
                );

                $file_id = saveFileToDatabase(
                    $user_id,
                    $uploaded_file['name'], // Keep original name
                    $subject,
                    'pdf',
                    $final_upload_path,
                    $summary_file_path,
                    $language,
                    null,
                    null,
                    false
                );

                error_log("Original PDF saved with ID: " . $file_id . " and original filename: " . $uploaded_file['name']);

                return [
                    'subject' => $subject,
                    'summary' => $summary_content,
                    'language' => $language,
                    'status' => 'completed',
                    'file_id' => $file_id,
                    'file_path' => $final_upload_path,
                    'debug' => 'Original PDF stored with original filename'
                ];
            }

            return [
                'subject' => 'Others',
                'summary' => 'Failed to store PDF file',
                'language' => 'en',
                'status' => 'error',
                'extracted_text' => '',
                'debug' => 'File move operation failed'
            ];
        }

        // HANDLE PDF ANALYSIS RESULTS (from composite image)
        if ($is_pdf_analysis && $original_pdf_id) {
            error_log("Processing PDF analysis for original PDF ID: " . $original_pdf_id);

            $analysis = processWithClaudeOCRandNLP($temp_upload_path, $uploaded_file['name']);

            // Update the original PDF record with analysis results
            if (!empty(trim($analysis['summary']))) {
                $update_result = updatePdfAnalysis($original_pdf_id, $user_id, $analysis);

                // Clean up the temporary analysis image
                if (file_exists($temp_upload_path)) {
                    unlink($temp_upload_path);
                }

                if ($update_result) {
                    return [
                        'subject' => $analysis['subject'],
                        'summary' => $analysis['summary'],
                        'language' => $analysis['language'] ?? 'en',
                        'status' => 'completed',
                        'extracted_text' => substr($analysis['extracted_text'] ?? '', 0, 1000),
                        'debug' => 'PDF analysis completed and applied to original PDF'
                    ];
                }
            }

            // Analysis failed
            return [
                'subject' => 'Others',
                'summary' => 'PDF analysis failed: ' . ($analysis['debug'] ?? 'Unknown error'),
                'language' => 'en',
                'status' => 'error',
                'extracted_text' => '',
                'debug' => 'PDF analysis processing failed'
            ];
        }

        // HANDLE REGULAR IMAGE FILES
        error_log("Processing image file: " . $uploaded_file['name']);

        $analysis = processWithClaudeOCRandNLP($temp_upload_path, $uploaded_file['name']);

        // Check if processing was successful
        if (
            isset($analysis['subject']) && $analysis['subject'] !== 'Others' ||
            !empty(trim($analysis['summary'])) && !strpos($analysis['summary'], 'failed')
        ) {

            $subject = $analysis['subject'];
            $language = $analysis['language'] ?? 'en';
            $alt_summary = $analysis['summary_alt'] ?? '';

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
                    $subject,
                    $language,
                    $alt_summary
                );

                saveFileToDatabase(
                    $user_id,
                    $uploaded_file['name'],
                    $subject,
                    $file_extension,
                    $final_upload_path,
                    $summary_file_path,
                    $language,
                    null,
                    null,
                    false
                );

                error_log("Successfully processed file: " . $uploaded_file['name']);

                return [
                    'subject' => $subject,
                    'summary' => $analysis['summary'],
                    'language' => $language,
                    'status' => 'completed',
                    'extracted_text' => substr($analysis['extracted_text'] ?? '', 0, 1000),
                    'file_path' => $final_upload_path,
                    'debug' => $analysis['debug'] ?? 'Processed successfully'
                ];
            }
        } else {
            // Processing failed but file was uploaded
            error_log("Claude processing failed for file: " . $uploaded_file['name']);
            error_log("Analysis result: " . json_encode($analysis));
        }
    } else {
        // Clean up temp file for unsupported types
        if (file_exists($temp_upload_path)) {
            unlink($temp_upload_path);
        }

        return [
            'subject' => 'Others',
            'summary' => 'Unsupported file type. Please upload JPG, PNG, GIF, WebP, or PDF files.',
            'language' => 'en',
            'status' => 'error',
            'extracted_text' => '',
            'debug' => 'Unsupported file extension: ' . $file_extension
        ];
    }

    // Fallback - save file even if processing failed
    $subject = 'Others';
    $language = 'en';
    $subject_upload_path = getUserUploadPath($user_id, $subject);

    if (!file_exists($subject_upload_path)) {
        mkdir($subject_upload_path, 0755, true);
    }

    $final_upload_path = $subject_upload_path . $final_filename;

    if (rename($temp_upload_path, $final_upload_path)) {
        $fallback_summary = 'File uploaded but AI processing failed. You can try re-analyzing later.';

        $summary_file_path = createSummaryTextFile(
            $fallback_summary,
            $uploaded_file['name'],
            $user_id,
            $subject,
            $language,
            ''
        );

        saveFileToDatabase(
            $user_id,
            $uploaded_file['name'],
            $subject,
            $file_extension,
            $final_upload_path,
            $summary_file_path,
            $language,
            null,
            null,
            false
        );

        return [
            'subject' => $subject,
            'summary' => $fallback_summary,
            'language' => $language,
            'status' => 'partial',
            'extracted_text' => '',
            'file_path' => $final_upload_path,
            'debug' => isset($analysis) ? ($analysis['debug'] ?? 'Processing failed, file saved') : 'Processing failed, file saved'
        ];
    }

    return [
        'subject' => 'Others',
        'summary' => 'Unknown error occurred during file processing',
        'language' => 'en',
        'status' => 'error',
        'extracted_text' => '',
        'debug' => 'Reached final fallback'
    ];
}

// NEW: Update PDF analysis results
function updatePdfAnalysis($pdf_file_id, $user_id, $analysis)
{
    try {
        $pdo = getDBConnection();

        // Get the current PDF file record
        $sql = "SELECT * FROM user_files WHERE id = ? AND user_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$pdf_file_id, $user_id]);
        $pdf_file = $stmt->fetch();

        if (!$pdf_file) {
            error_log("PDF file not found for analysis update: ID $pdf_file_id");
            return false;
        }

        $new_subject = $analysis['subject'];
        $new_language = $analysis['language'] ?? 'en';

        // Move PDF to correct subject folder if needed
        if ($new_subject !== $pdf_file['subject']) {
            $new_subject_path = getUserUploadPath($user_id, $new_subject);
            if (!file_exists($new_subject_path)) {
                mkdir($new_subject_path, 0755, true);
            }

            $new_file_path = $new_subject_path . basename($pdf_file['original_file_path']);

            if (rename($pdf_file['original_file_path'], $new_file_path)) {
                // Update database with new subject and path
                $update_sql = "UPDATE user_files SET subject = ?, original_file_path = ?, language = ? WHERE id = ?";
                $update_stmt = $pdo->prepare($update_sql);
                $update_stmt->execute([$new_subject, $new_file_path, $new_language, $pdf_file_id]);

                error_log("Moved PDF to $new_subject folder: $new_file_path");
            }
        } else {
            // Just update language
            $update_sql = "UPDATE user_files SET language = ? WHERE id = ?";
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([$new_language, $pdf_file_id]);
        }

        // Update summary file
        if (!empty($pdf_file['summary_file_path']) && file_exists($pdf_file['summary_file_path'])) {
            $updated_summary = "📄 PDF Document: " . $pdf_file['file_name'] . "\n\n";
            $updated_summary .= "🤖 AI Analysis Results:\n";
            $updated_summary .= $analysis['summary'] . "\n\n";
            $updated_summary .= "Subject Classification: " . $new_subject . "\n";
            $updated_summary .= "Language: " . ($new_language === 'th' ? 'Thai (ภาษาไทย)' : 'English') . "\n";
            $updated_summary .= "Analysis completed: " . date('Y-m-d H:i:s') . "\n\n";
            $updated_summary .= "Debug: " . ($analysis['debug'] ?? 'Analysis completed successfully');

            file_put_contents($pdf_file['summary_file_path'], $updated_summary);
            error_log("Updated PDF summary file with analysis results");
        }

        return true;
    } catch (Exception $e) {
        error_log("Error updating PDF analysis: " . $e->getMessage());
        return false;
    }
}
function reanalyzeFile($file_id, $user_id)
{
    $pdo = getDBConnection();
    $sql = "SELECT * FROM user_files WHERE id = ? AND user_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$file_id, $user_id]);
    $file = $stmt->fetch();

    if (!$file || !file_exists($file['original_file_path'])) {
        error_log("Reanalyze failed: File not found - ID: $file_id, Path: " . ($file['original_file_path'] ?? 'null'));
        return false;
    }

    $file_extension = strtolower(pathinfo($file['original_file_path'], PATHINFO_EXTENSION));

    // Use Claude for both OCR and NLP analysis
    if (in_array($file_extension, ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'])) {
        error_log("Re-analyzing file with Claude: " . $file['file_name']);

        $analysis = processWithClaudeOCRandNLP($file['original_file_path'], $file['file_name']);

        // Check if analysis was successful
        if (!empty(trim($analysis['summary']))) {
            $new_subject = $analysis['subject'];
            $new_language = isset($analysis['language']) ? $analysis['language'] : 'en';
            $alt_summary = isset($analysis['summary_alt']) ? $analysis['summary_alt'] : '';

            // Update summary file with new analysis
            if (!empty($file['summary_file_path']) && file_exists($file['summary_file_path'])) {
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

                // Add metadata
                $summary_content .= "\n\n" . str_repeat("=", 50) . "\n";
                $summary_content .= "Language: " . ($new_language === 'th' ? 'Thai (ภาษาไทย)' : 'English') . "\n";
                $summary_content .= "Re-analyzed with Claude OCR+NLP: " . date('Y-m-d H:i:s') . "\n";
                $summary_content .= "Debug: " . ($analysis['debug'] ?? 'Re-analysis completed') . "\n";

                file_put_contents($file['summary_file_path'], $summary_content);
                error_log("Updated summary file: " . $file['summary_file_path']);
            }

            // Update database - ensure language column exists
            try {
                $check_column = $pdo->query("SHOW COLUMNS FROM user_files LIKE 'language'");
                if ($check_column->rowCount() == 0) {
                    $pdo->exec("ALTER TABLE user_files ADD COLUMN language VARCHAR(5) DEFAULT 'en' AFTER file_type");
                    error_log("Added language column to user_files table");
                }
            } catch (Exception $e) {
                error_log("Error checking/adding language column: " . $e->getMessage());
            }

            // Move file if subject classification changed
            if ($new_subject !== $file['subject']) {
                error_log("Subject changed from {$file['subject']} to $new_subject for file ID: $file_id");

                $new_subject_path = getUserUploadPath($user_id, $new_subject);
                if (!file_exists($new_subject_path)) {
                    mkdir($new_subject_path, 0755, true);
                }

                $new_file_path = $new_subject_path . basename($file['original_file_path']);

                if (rename($file['original_file_path'], $new_file_path)) {
                    // Update database with new subject, path, and language
                    $update_sql = "UPDATE user_files SET subject = ?, original_file_path = ?, language = ? WHERE id = ?";
                    $update_stmt = $pdo->prepare($update_sql);
                    $success = $update_stmt->execute([$new_subject, $new_file_path, $new_language, $file_id]);

                    if ($success) {
                        error_log("Successfully updated file record - new subject: $new_subject, new language: $new_language");
                    } else {
                        error_log("Failed to update database record for file ID: $file_id");
                    }
                } else {
                    error_log("Failed to move file from {$file['original_file_path']} to $new_file_path");
                    return false;
                }
            } else {
                // Subject didn't change, just update language
                $update_sql = "UPDATE user_files SET language = ? WHERE id = ?";
                $update_stmt = $pdo->prepare($update_sql);
                $success = $update_stmt->execute([$new_language, $file_id]);

                if ($success) {
                    error_log("Successfully updated language to: $new_language for file ID: $file_id");
                } else {
                    error_log("Failed to update language for file ID: $file_id");
                }
            }

            return true;
        } else {
            // Claude analysis failed
            error_log("Claude re-analysis failed for file ID: $file_id - " . ($analysis['debug'] ?? 'Unknown error'));
            return false;
        }
    } else {
        // Unsupported file type for Claude
        error_log("Unsupported file type for Claude re-analysis: $file_extension");
        return false;
    }
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
$user_files = getUserFilesSafe($_SESSION['user_id']);

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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script>
        // Configure PDF.js worker
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

        // PDF to Canvas conversion function
        async function convertPdfToImages(file) {
            const fileArrayBuffer = await file.arrayBuffer();
            const pdf = await pdfjsLib.getDocument(fileArrayBuffer).promise;
            const images = [];

            for (let pageNum = 1; pageNum <= Math.min(pdf.numPages, 5); pageNum++) { // Limit to 5 pages
                const page = await pdf.getPage(pageNum);
                const viewport = page.getViewport({
                    scale: 2.0
                }); // Higher scale for better OCR

                const canvas = document.createElement('canvas');
                const context = canvas.getContext('2d');
                canvas.height = viewport.height;
                canvas.width = viewport.width;

                await page.render({
                    canvasContext: context,
                    viewport: viewport
                }).promise;

                // Convert canvas to blob
                const blob = await new Promise(resolve => {
                    canvas.toBlob(resolve, 'image/png', 0.95);
                });

                images.push(blob);
            }

            return images;
        }
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OZNOTE</title>
    <link rel="icon" type="image/x-icon" href="/images/favicon.ico">
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
                                    <p class="text-black font-medium text-sm sm:text-base">🌏 AI-Powered Multilingual Organization</p>
                                    <p class="text-black text-xs sm:text-sm mt-1">Files are automatically categorized by subject and summarized in Thai or English based on content language</p>
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

    <!-- STEP 2: Add this Settings Modal right after the File Details Modal (around line 1200) -->
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

    <!-- STEP 3: Add this JavaScript at the end of your existing script section (around line 1800) -->
    <script>
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

        // The rest of the settings functions remain the same
        function closeSettingsPanel() {
            console.log('Closing settings panel...');
            const modal = document.getElementById('settingsModal');
            if (modal) {
                modal.classList.add('hidden');
                document.body.style.overflow = '';
                modal.style.opacity = '0';
            }
        }

        // Close settings modal when clicking outside
        document.getElementById('settingsModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closeSettingsPanel();
            }
        });

        // Update the existing escape key handler to also close settings modal
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                // Close settings modal
                const settingsModal = document.getElementById('settingsModal');
                if (settingsModal && !settingsModal.classList.contains('hidden')) {
                    closeSettingsPanel();
                    return; // Exit early if settings was open
                }

                // Existing escape key functionality for file details modal and mobile sidebar
                closeFileDetails();

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

        // Optional: Add keyboard shortcut for settings (Ctrl/Cmd + ,)
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === ',') {
                // Don't trigger when modal is already open or when typing in inputs
                if (document.getElementById('fileDetailsModal').classList.contains('hidden') &&
                    document.getElementById('settingsModal').classList.contains('hidden') &&
                    !e.target.matches('input, textarea, select')) {
                    e.preventDefault();
                    openSettingsPanel();
                }
            }
        });

        console.log('Settings panel functionality initialized');
    </script>

    <!-- STEP 4: Add this CSS to your existing style section (around line 200) -->
    <style>
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
            .sidebar .space-y-1 {
                space-y: 0.25rem;
            }

            .sidebar .btn-touch {
                min-height: 44px;
            }
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

        #settingsBtn:hover {
            background-color: var(--color-primary-medium);
            transform: translateX(2px);
            transition: all 0.2s ease;
        }
    </style>

    <script>
        // Store file data globally
        const fileData = <?php echo safeJsonEncode($user_files); ?>;
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

                // Update modal content
                document.getElementById('modalFileName').textContent = file.name || 'Unknown File';
                document.getElementById('modalSubject').textContent = file.subject || 'Unknown Subject';
                document.getElementById('modalSummary').textContent = file.full_summary || (isThaiLanguage ? 'ไม่มีข้อมูลสรุป' : 'No summary available');

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
                    ${(fileExtension === 'pdf' || ['jpg', 'jpeg', 'png', 'gif'].includes(fileExtension)) ? `
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
        // Enhanced file upload handler with comprehensive validation
        document.addEventListener('change', async function(e) {
            if (e.target.type === 'file' && e.target.files.length > 0) {
                const file = e.target.files[0];
                const fileExtension = file.name.split('.').pop().toLowerCase();

                // VALIDATION 1: File size check
                if (file.size > 15 * 1024 * 1024) {
                    showErrorMessage('File Too Large!', `Your file is ${(file.size / (1024 * 1024)).toFixed(1)}MB. Maximum allowed size is 15MB.`);
                    e.target.value = '';
                    return;
                }

                // VALIDATION 2: File type check
                const supportedTypes = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'];
                if (!supportedTypes.includes(fileExtension)) {
                    showErrorMessage('Unsupported File Type!', `File type "${fileExtension.toUpperCase()}" is not supported. Please use: PDF, JPG, PNG, GIF, or WebP files.`);
                    e.target.value = '';
                    return;
                }

                // VALIDATION 3: Empty file check
                if (file.size === 0) {
                    showErrorMessage('Empty File!', 'The selected file appears to be empty or corrupted.');
                    e.target.value = '';
                    return;
                }

                // VALIDATION 4: PDF-specific validation
                if (fileExtension === 'pdf') {
                    try {
                        e.preventDefault(); // Prevent normal form submission

                        // Validate PDF before processing
                        const isValidPdf = await validatePdfFile(file);
                        if (!isValidPdf.valid) {
                            showErrorMessage('Invalid PDF!', isValidPdf.message);
                            e.target.value = '';
                            return;
                        }

                        await processPdfFile(file, e.target.closest('form'));
                        return;
                    } catch (error) {
                        showErrorMessage('PDF Processing Error!', error.message);
                        e.target.value = '';
                        return;
                    }
                }

                // VALIDATION 5: Image-specific validation
                if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(fileExtension)) {
                    try {
                        const isValidImage = await validateImageFile(file);
                        if (!isValidImage.valid) {
                            showErrorMessage('Invalid Image!', isValidImage.message);
                            e.target.value = '';
                            return;
                        }
                    } catch (error) {
                        showErrorMessage('Image Validation Error!', 'Unable to validate image file.');
                        e.target.value = '';
                        return;
                    }
                }

                // All validations passed - proceed with upload
                showLoadingOverlay();
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
        // FIXED: Simplified PDF processing function
        // FIXED: Complete PDF processing function with proper linking
        // UPDATED: Process PDF as single image for Claude analysis
        async function processPdfFile(file, form) {
            try {
                console.log('Processing PDF file for single-image analysis:', file.name);

                // Show processing overlay
                const overlay = document.createElement('div');
                overlay.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4';
                overlay.innerHTML = `
            <div class="glass-card rounded-xl p-6 text-center max-w-md mx-auto border-theme-light">
                <div class="text-4xl mb-4">📄</div>
                <h3 class="text-xl font-semibold text-white mb-2">Processing PDF...</h3>
                <p class="text-gray-300 mb-4">Creating single image for AI analysis</p>
                <div class="w-full bg-gray-200 rounded-full h-2 mb-4">
                    <div id="pdfProgress" class="bg-theme-green h-2 rounded-full transition-all duration-300" style="width: 0%"></div>
                </div>
                <p id="pdfStatus" class="text-sm text-gray-400">Initializing...</p>
            </div>
        `;
                document.body.appendChild(overlay);

                const updateProgress = (progress, status) => {
                    const progressBar = document.getElementById('pdfProgress');
                    const statusText = document.getElementById('pdfStatus');
                    if (progressBar) progressBar.style.width = progress + '%';
                    if (statusText) statusText.textContent = status;
                };

                // STEP 1: Upload original PDF first (with original name)
                updateProgress(10, 'Uploading original PDF...');
                const originalPdfResult = await uploadOriginalPdf(file);

                if (!originalPdfResult) {
                    throw new Error('Failed to upload original PDF');
                }

                // STEP 2: Convert PDF to single composite image for analysis
                updateProgress(30, 'Loading PDF pages...');
                const pdf = await pdfjsLib.getDocument(await file.arrayBuffer()).promise;

                updateProgress(50, 'Creating composite image...');
                const compositeImage = await createCompositeImageFromPdf(pdf);

                if (!compositeImage) {
                    throw new Error('Failed to create composite image from PDF');
                }

                // STEP 3: Send composite image to Claude for analysis
                updateProgress(70, 'Analyzing with Claude AI...');
                const imageFile = new File([compositeImage], `${file.name}_analysis.png`, {
                    type: 'image/png'
                });

                const formData = new FormData();
                formData.append('uploaded_file', imageFile);

                // Link this analysis to the original PDF
                if (typeof originalPdfResult === 'number' && originalPdfResult > 0) {
                    formData.append('original_pdf_id', originalPdfResult);
                    formData.append('is_pdf_analysis', 'true');
                    console.log(`Linking analysis to original PDF ID: ${originalPdfResult}`);
                }

                await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });

                updateProgress(100, 'PDF processing complete!');

                setTimeout(() => {
                    document.body.removeChild(overlay);
                    window.location.reload();
                }, 1500);

            } catch (error) {
                console.error('PDF processing error:', error);
                alert('Error processing PDF: ' + error.message);
                const overlay = document.querySelector('.fixed.inset-0');
                if (overlay) document.body.removeChild(overlay);
            }
        }

        // NEW: Create a single composite image from all PDF pages
        async function createCompositeImageFromPdf(pdf) {
            try {
                const maxPages = Math.min(pdf.numPages, 3); // Limit to first 3 pages for performance
                const scale = 1.5; // Good quality for OCR
                const pageImages = [];

                // Render each page
                for (let pageNum = 1; pageNum <= maxPages; pageNum++) {
                    const page = await pdf.getPage(pageNum);
                    const viewport = page.getViewport({
                        scale
                    });

                    const canvas = document.createElement('canvas');
                    const context = canvas.getContext('2d');
                    canvas.height = viewport.height;
                    canvas.width = viewport.width;

                    await page.render({
                        canvasContext: context,
                        viewport: viewport
                    }).promise;

                    pageImages.push({
                        canvas: canvas,
                        width: viewport.width,
                        height: viewport.height
                    });
                }

                // Create composite canvas
                const maxWidth = Math.max(...pageImages.map(img => img.width));
                const totalHeight = pageImages.reduce((sum, img) => sum + img.height + 20, 0); // 20px spacing

                const compositeCanvas = document.createElement('canvas');
                compositeCanvas.width = maxWidth;
                compositeCanvas.height = totalHeight;
                const compositeContext = compositeCanvas.getContext('2d');

                // Fill with white background
                compositeContext.fillStyle = 'white';
                compositeContext.fillRect(0, 0, maxWidth, totalHeight);

                // Draw each page
                let currentY = 0;
                for (const pageImage of pageImages) {
                    const x = (maxWidth - pageImage.width) / 2; // Center horizontally
                    compositeContext.drawImage(pageImage.canvas, x, currentY);
                    currentY += pageImage.height + 20; // Add spacing
                }

                // Convert to blob
                return new Promise(resolve => {
                    compositeCanvas.toBlob(resolve, 'image/png', 0.95);
                });

            } catch (error) {
                console.error('Error creating composite image:', error);
                return null;
            }
        }
        // FIXED: Function to upload original PDF and return the file ID
        // FIXED: Improved function to upload original PDF and get the ID
        // UPDATED: Upload original PDF with original filename
        async function uploadOriginalPdf(file) {
            try {
                const formData = new FormData();
                formData.append('uploaded_file', file); // Keep original file and name
                formData.append('is_original_pdf', 'true');

                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });

                if (response.ok) {
                    // Wait for database update
                    await new Promise(resolve => setTimeout(resolve, 500));

                    // Get the latest PDF file ID
                    const checkResponse = await fetch(window.location.href + '&get_latest_pdf=1');
                    if (checkResponse.ok) {
                        const latestId = await checkResponse.text();
                        const fileId = parseInt(latestId.trim());
                        if (!isNaN(fileId) && fileId > 0) {
                            console.log('Successfully uploaded original PDF with ID:', fileId);
                            return fileId;
                        }
                    }
                }

                console.log('PDF uploaded successfully but could not extract ID');
                return 'success';

            } catch (error) {
                console.error('Error uploading original PDF:', error);
                return false;
            }
        }
        // PDF validation function
        async function validatePdfFile(file) {
            try {
                const fileArrayBuffer = await file.arrayBuffer();
                const pdf = await pdfjsLib.getDocument(fileArrayBuffer).promise;

                // Check if PDF has pages
                if (pdf.numPages === 0) {
                    return {
                        valid: false,
                        message: 'PDF file contains no pages.'
                    };
                }

                // Check page count warning
                if (pdf.numPages > 5) {
                    const proceed = confirm(`This PDF has ${pdf.numPages} pages. Only the first 5 pages will be processed. Continue?`);
                    if (!proceed) {
                        return {
                            valid: false,
                            message: 'Upload cancelled by user.'
                        };
                    }
                }

                // Try to render first page to verify PDF integrity
                const page = await pdf.getPage(1);
                const viewport = page.getViewport({
                    scale: 0.1
                }); // Small scale for validation

                if (viewport.width === 0 || viewport.height === 0) {
                    return {
                        valid: false,
                        message: 'PDF pages appear to be corrupted or empty.'
                    };
                }

                return {
                    valid: true,
                    message: 'PDF is valid.'
                };

            } catch (error) {
                console.error('PDF validation error:', error);
                return {
                    valid: false,
                    message: 'PDF file is corrupted or not a valid PDF format.'
                };
            }
        }

        // Image validation function
        async function validateImageFile(file) {
            return new Promise((resolve) => {
                const img = new Image();
                const url = URL.createObjectURL(file);

                img.onload = function() {
                    URL.revokeObjectURL(url);

                    // Check minimum dimensions
                    if (this.width < 50 || this.height < 50) {
                        resolve({
                            valid: false,
                            message: 'Image is too small. Minimum size is 50x50 pixels.'
                        });
                        return;
                    }

                    // Check maximum dimensions (optional)
                    if (this.width > 10000 || this.height > 10000) {
                        resolve({
                            valid: false,
                            message: 'Image is too large. Maximum size is 10000x10000 pixels.'
                        });
                        return;
                    }

                    resolve({
                        valid: true,
                        message: 'Image is valid.'
                    });
                };

                img.onerror = function() {
                    URL.revokeObjectURL(url);
                    resolve({
                        valid: false,
                        message: 'File is not a valid image or is corrupted.'
                    });
                };

                img.src = url;
            });
        }

        // Error message display function
        function showErrorMessage(title, message) {
            const overlay = document.createElement('div');
            overlay.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4';
            overlay.innerHTML = `
        <div class="glass-card rounded-xl p-6 text-center max-w-md mx-auto border-red-500">
            <div class="text-red-400 text-4xl mb-4">❌</div>
            <h3 class="text-xl font-semibold text-white mb-3">${title}</h3>
            <p class="text-gray-300 mb-6 leading-relaxed">${message}</p>
            <button onclick="this.closest('.fixed').remove()" 
                    class="px-6 py-3 bg-red-500 hover:bg-red-600 text-white rounded-lg transition-colors btn-touch font-medium">
                Try Again
            </button>
        </div>
    `;
            document.body.appendChild(overlay);

            // Auto-remove after 10 seconds
            setTimeout(() => {
                if (overlay.parentNode) {
                    overlay.remove();
                }
            }, 10000);
        }

        // Loading overlay function
        function showLoadingOverlay() {
            const overlay = document.createElement('div');
            overlay.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4';
            overlay.innerHTML = `
        <div class="glass-card rounded-xl p-6 text-center max-w-md mx-auto border-theme-light">
            <div class="animate-spin rounded-full h-16 w-16 border-b-4 border-theme-green mx-auto mb-6"></div>
            <h3 class="text-xl font-semibold text-white mb-2">Processing your file...</h3>
            <p class="text-gray-300 mb-2">OCR and AI analysis in progress</p>
            <div class="bg-theme-bright bg-opacity-20 rounded-lg p-4 mt-4">
                <p class="text-black text-sm">🌏 Automatic language detection and multilingual summarization</p>
            </div>
        </div>
    `;
            document.body.appendChild(overlay);
        }

        function openSettingsPanel() {
            console.log('Opening settings panel...');
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

        // Close settings modal when clicking outside
        document.getElementById('settingsModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closeSettingsPanel();
            }
        });

        // Touch interactions for mobile
        if ('ontouchstart' in window) {
            document.addEventListener('touchstart', function() {}, {
                passive: true
            });
        }

        console.log('All event listeners set up successfully with multilingual support');
        document.addEventListener('DOMContentLoaded', function() {
            // Monitor all file input changes
            document.querySelectorAll('input[type="file"]').forEach(input => {
                input.addEventListener('change', function(e) {
                    console.log('File selected:', e.target.files[0]);
                    if (e.target.files[0]) {
                        console.log('File name:', e.target.files[0].name);
                        console.log('File size:', e.target.files[0].size, 'bytes');
                        console.log('File type:', e.target.files[0].type);

                        // Check file size (15MB limit)
                        if (e.target.files[0].size > 15 * 1024 * 1024) {
                            alert('File too large! Maximum size is 15MB');
                            e.target.value = '';
                            return;
                        }
                    }
                });
            });

            // Monitor form submissions
            document.querySelectorAll('form').forEach(form => {
                if (form.querySelector('input[type="file"]')) {
                    form.addEventListener('submit', function(e) {
                        console.log('Form submitting...');
                        console.log('Form action:', form.action);
                        console.log('Form method:', form.method);
                        console.log('Form enctype:', form.enctype);

                        const fileInput = form.querySelector('input[type="file"]');
                        if (fileInput && fileInput.files.length === 0) {
                            alert('Please select a file first!');
                            e.preventDefault();
                            return;
                        }

                        // Show that form is submitting
                        console.log('Form submission proceeding...');
                    });
                }
            });
        });
    </script>
</body>

</html>
