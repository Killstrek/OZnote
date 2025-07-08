<?php
session_start();

// Configuration
define('UPLOAD_DIR', 'uploads/');
define('GEMINI_API_KEY', 'your-gemini-api-key-here'); // Add your Gemini API key
define('OCR_SPACE_API_KEY', 'K83046822188957'); // Add your OCR.space API key

// Create upload directory if it doesn't exist
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0777, true);
}

// Initialize uploaded files in session if not exists
if (!isset($_SESSION['uploaded_files'])) {
    $_SESSION['uploaded_files'] = [];
}

// OCR Function using OCR.space API with detailed debugging
function performOCR($file_path)
{
    $api_key = OCR_SPACE_API_KEY;

    // Check if API key is set
    if ($api_key === 'your-ocr-space-api-key-here' || empty($api_key)) {
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
        'language' => 'eng', // You can change to 'tha' for Thai or use 'eng+tha' for both
        'isOverlayRequired' => 'false',
        'file' => new CURLFile($file_path),
        'detectOrientation' => 'true',
        'isTable' => 'true', // Better for structured content
        'OCREngine' => '2' // Use OCR Engine 2 for better accuracy
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60); // OCR can take time for large files
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For development only

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    // Log debug information
    error_log("OCR API Response Code: " . $http_code);
    error_log("OCR API Response: " . substr($response, 0, 500)); // First 500 chars

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
    // For production, use a proper PDF library like PDFParser or similar
    // This is a simplified version that works with some PDFs
    $content = shell_exec("pdftotext '$file_path' -");
    return $content ? trim($content) : false;
}

// Gemini API function for categorization and summarization with debugging
function analyzeWithGemini($text_content, $filename)
{
    $api_key = GEMINI_API_KEY;

    // Check if API key is set
    if ($api_key === 'your-gemini-api-key-here' || empty($api_key)) {
        error_log("Gemini API key not configured");
        return [
            'subject' => 'Others',
            'summary' => 'Gemini API key not configured',
            'debug' => 'API key missing'
        ];
    }

    // Check if we have content to analyze
    if (empty(trim($text_content))) {
        return [
            'subject' => 'Others',
            'summary' => 'No text content to analyze',
            'debug' => 'Empty text content'
        ];
    }

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $api_key;

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
        'contents' => [
            [
                'parts' => [
                    [
                        'text' => $prompt
                    ]
                ]
            ]
        ],
        'generationConfig' => [
            'maxOutputTokens' => 300,
            'temperature' => 0.1
        ]
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For development only

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    // Log debug information
    error_log("Gemini API Response Code: " . $http_code);
    error_log("Gemini API Response: " . substr($response, 0, 500)); // First 500 chars

    if ($curl_error) {
        error_log("CURL Error for Gemini: " . $curl_error);
        return [
            'subject' => 'Others',
            'summary' => 'Network error connecting to Gemini API',
            'debug' => 'CURL Error: ' . $curl_error
        ];
    }

    if ($http_code === 200) {
        $result = json_decode($response, true);
        if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            $gemini_response = $result['candidates'][0]['content']['parts'][0]['text'];

            // Extract JSON from Gemini's response
            preg_match('/\{[^{}]*"subject"[^{}]*"summary"[^{}]*\}/', $gemini_response, $matches);
            if ($matches) {
                $analysis = json_decode($matches[0], true);
                if ($analysis && isset($analysis['subject']) && isset($analysis['summary'])) {
                    return [
                        'subject' => $analysis['subject'],
                        'summary' => $analysis['summary'],
                        'debug' => 'Gemini analysis successful'
                    ];
                }
            }

            // If JSON parsing failed, create fallback analysis
            return [
                'subject' => 'Others',
                'summary' => 'Document analyzed but format parsing failed',
                'debug' => 'JSON parsing failed from: ' . substr($gemini_response, 0, 200)
            ];
        }
    } else {
        $error_response = json_decode($response, true);
        $error_message = 'Unknown API error';

        if (isset($error_response['error']['message'])) {
            $error_message = $error_response['error']['message'];
        } elseif (isset($error_response['error']['details'][0]['reason'])) {
            $error_message = $error_response['error']['details'][0]['reason'];
        }

        return [
            'subject' => 'Others',
            'summary' => 'Gemini API error: ' . $error_message,
            'debug' => 'HTTP ' . $http_code . ': ' . $error_message
        ];
    }

    // Fallback analysis if everything fails
    return [
        'subject' => 'Others',
        'summary' => 'Document uploaded successfully but AI analysis failed',
        'debug' => 'All analysis methods failed'
    ];
}

// Process uploaded file with comprehensive debugging
function processUploadedFile($uploaded_file)
{
    $file_extension = strtolower(pathinfo($uploaded_file['name'], PATHINFO_EXTENSION));
    $upload_path = UPLOAD_DIR . time() . '_' . $uploaded_file['name'];

    $debug_info = [];
    $debug_info[] = "File extension: " . $file_extension;
    $debug_info[] = "Upload path: " . $upload_path;

    if (move_uploaded_file($uploaded_file['tmp_name'], $upload_path)) {
        $debug_info[] = "File moved successfully";
        $extracted_text = '';
        $ocr_debug = '';

        // Extract text based on file type
        if ($file_extension === 'pdf') {
            $debug_info[] = "Processing PDF file";
            // First try to extract text directly from PDF
            $extracted_text = extractTextFromPDF($upload_path);

            if ($extracted_text && strlen(trim($extracted_text)) > 10) {
                $debug_info[] = "PDF text extraction successful";
            } else {
                $debug_info[] = "PDF text extraction failed, trying OCR";
                // If PDF text extraction fails, try OCR
                $ocr_result = performOCR($upload_path);
                $extracted_text = $ocr_result['text'];
                $ocr_debug = $ocr_result['error'] ?? $ocr_result['debug'] ?? '';
            }
        } elseif (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff'])) {
            $debug_info[] = "Processing image file";
            $ocr_result = performOCR($upload_path);
            $extracted_text = $ocr_result['text'];
            $ocr_debug = $ocr_result['error'] ?? $ocr_result['debug'] ?? '';
        } else {
            $debug_info[] = "Unsupported file type";
            return [
                'subject' => 'Others',
                'summary' => 'Unsupported file type: ' . $file_extension,
                'status' => 'error',
                'debug_info' => implode('; ', $debug_info)
            ];
        }

        $debug_info[] = "Extracted text length: " . strlen($extracted_text);
        $debug_info[] = "OCR debug: " . $ocr_debug;

        if ($extracted_text && strlen(trim($extracted_text)) > 10) { // Ensure we have meaningful content
            $debug_info[] = "Text extraction successful, analyzing with Claude";

            // Analyze with Gemini API
            $analysis = analyzeWithGemini($extracted_text, $uploaded_file['name']);
            $debug_info[] = "Gemini debug: " . ($analysis['debug'] ?? 'no debug info');

            return [
                'subject' => $analysis['subject'],
                'summary' => $analysis['summary'],
                'status' => 'completed',
                'extracted_text' => substr($extracted_text, 0, 1000), // Store first 1000 chars for reference
                'debug_info' => implode('; ', $debug_info),
                'file_path' => $upload_path // Store file path for original file access
            ];
        } else {
            $debug_info[] = "Text extraction failed or insufficient content";
            return [
                'subject' => 'Others',
                'summary' => 'Unable to extract readable text from this file. OCR debug: ' . $ocr_debug,
                'status' => 'error',
                'extracted_text' => '',
                'debug_info' => implode('; ', $debug_info),
                'file_path' => $upload_path // Store file path even for errors
            ];
        }
    } else {
        $debug_info[] = "File move failed";
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
        // Create initial file entry
        $file_info = [
            'id' => count($_SESSION['uploaded_files']) + 1,
            'name' => $uploaded_file['name'],
            'subject' => 'Processing...',
            'date' => date('Y-m-d'),
            'summary' => 'AI is analyzing this document...',
            'status' => 'processing',
            'debug_info' => '', // Add debug information
            'file_path' => '', // Store the file path for access
            'file_size' => $uploaded_file['size'],
            'file_type' => $uploaded_file['type']
        ];

        // Add to beginning of array
        array_unshift($_SESSION['uploaded_files'], $file_info);

        // Process the file
        $processing_result = processUploadedFile($uploaded_file);

        if ($processing_result) {
            // Update the file info with AI analysis results
            $_SESSION['uploaded_files'][0]['subject'] = $processing_result['subject'];
            $_SESSION['uploaded_files'][0]['summary'] = $processing_result['summary'];
            $_SESSION['uploaded_files'][0]['status'] = $processing_result['status'];
            $_SESSION['uploaded_files'][0]['debug_info'] = $processing_result['debug_info'] ?? '';
            $_SESSION['uploaded_files'][0]['file_path'] = $processing_result['file_path'] ?? '';
        } else {
            $_SESSION['uploaded_files'][0]['subject'] = 'Others';
            $_SESSION['uploaded_files'][0]['summary'] = 'Error processing file - could not process uploaded file';
            $_SESSION['uploaded_files'][0]['status'] = 'error';
            $_SESSION['uploaded_files'][0]['debug_info'] = 'processUploadedFile returned false';
        }
    } else {
        // Handle upload errors
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

        $file_info = [
            'id' => count($_SESSION['uploaded_files']) + 1,
            'name' => $uploaded_file['name'] ?? 'Unknown file',
            'subject' => 'Others',
            'date' => date('Y-m-d'),
            'summary' => 'Upload failed: ' . $error_message,
            'status' => 'error',
            'debug_info' => 'Upload error code: ' . $uploaded_file['error']
        ];

        array_unshift($_SESSION['uploaded_files'], $file_info);
    }
}

// Handle file actions (re-analyze, delete)
if (isset($_POST['action']) && isset($_POST['file_id'])) {
    $file_id = intval($_POST['file_id']);
    $action = $_POST['action'];

    // Find the file in session
    $file_index = null;
    foreach ($_SESSION['uploaded_files'] as $index => $file) {
        if ($file['id'] == $file_id) {
            $file_index = $index;
            break;
        }
    }

    if ($file_index !== null) {
        if ($action === 'reanalyze') {
            $file = $_SESSION['uploaded_files'][$file_index];

            // Update status to processing
            $_SESSION['uploaded_files'][$file_index]['status'] = 'processing';
            $_SESSION['uploaded_files'][$file_index]['summary'] = 'Re-analyzing document...';

            // Re-process the file if it exists
            if (isset($file['file_path']) && file_exists($file['file_path'])) {
                $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $extracted_text = '';
                $debug_info = ['Re-analysis initiated'];

                // Extract text again
                if ($file_extension === 'pdf') {
                    $extracted_text = extractTextFromPDF($file['file_path']);
                    if (!$extracted_text || strlen(trim($extracted_text)) <= 10) {
                        $ocr_result = performOCR($file['file_path']);
                        $extracted_text = $ocr_result['text'];
                        $debug_info[] = 'PDF text extraction failed, used OCR: ' . ($ocr_result['error'] ?? 'success');
                    } else {
                        $debug_info[] = 'PDF text extraction successful';
                    }
                } elseif (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff'])) {
                    $ocr_result = performOCR($file['file_path']);
                    $extracted_text = $ocr_result['text'];
                    $debug_info[] = 'OCR processing: ' . ($ocr_result['error'] ?? 'success');
                }

                // Re-analyze with Gemini
                if ($extracted_text && strlen(trim($extracted_text)) > 10) {
                    $analysis = analyzeWithGemini($extracted_text, $file['name']);

                    $_SESSION['uploaded_files'][$file_index]['subject'] = $analysis['subject'];
                    $_SESSION['uploaded_files'][$file_index]['summary'] = $analysis['summary'];
                    $_SESSION['uploaded_files'][$file_index]['status'] = 'completed';
                    $_SESSION['uploaded_files'][$file_index]['extracted_text'] = substr($extracted_text, 0, 1000);
                    $_SESSION['uploaded_files'][$file_index]['debug_info'] = implode('; ', array_merge($debug_info, ['Gemini re-analysis: ' . ($analysis['debug'] ?? 'completed')]));
                } else {
                    $_SESSION['uploaded_files'][$file_index]['subject'] = 'Others';
                    $_SESSION['uploaded_files'][$file_index]['summary'] = 'Re-analysis failed: Unable to extract readable text';
                    $_SESSION['uploaded_files'][$file_index]['status'] = 'error';
                    $_SESSION['uploaded_files'][$file_index]['debug_info'] = implode('; ', array_merge($debug_info, ['Text extraction failed on re-analysis']));
                }
            } else {
                $_SESSION['uploaded_files'][$file_index]['subject'] = 'Others';
                $_SESSION['uploaded_files'][$file_index]['summary'] = 'Re-analysis failed: Original file not found';
                $_SESSION['uploaded_files'][$file_index]['status'] = 'error';
                $_SESSION['uploaded_files'][$file_index]['debug_info'] = 'Re-analysis failed: File path not found or file deleted';
            }

            // Redirect to refresh the page
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        } elseif ($action === 'delete') {
            $file = $_SESSION['uploaded_files'][$file_index];

            // Delete the physical file if it exists
            if (isset($file['file_path']) && file_exists($file['file_path'])) {
                if (unlink($file['file_path'])) {
                    error_log("File deleted successfully: " . $file['file_path']);
                } else {
                    error_log("Failed to delete file: " . $file['file_path']);
                }
            }

            // Remove from session
            array_splice($_SESSION['uploaded_files'], $file_index, 1);

            // Redirect to refresh the page
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }
    }
}

// Handle file viewing/download requests
if (isset($_GET['action']) && $_GET['action'] === 'view_file' && isset($_GET['file_id'])) {
    $file_id = intval($_GET['file_id']);
    $file = null;

    // Find the file in session
    foreach ($_SESSION['uploaded_files'] as $uploaded_file) {
        if ($uploaded_file['id'] == $file_id) {
            $file = $uploaded_file;
            break;
        }
    }

    if ($file && isset($file['file_path']) && file_exists($file['file_path'])) {
        $file_path = $file['file_path'];
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        // Set appropriate headers based on file type
        if ($file_extension === 'pdf') {
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . basename($file['name']) . '"');
        } elseif (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp'])) {
            $mime_types = [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'bmp' => 'image/bmp'
            ];
            header('Content-Type: ' . ($mime_types[$file_extension] ?? 'application/octet-stream'));
            header('Content-Disposition: inline; filename="' . basename($file['name']) . '"');
        } else {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($file['name']) . '"');
        }

        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        exit;
    } else {
        http_response_code(404);
        echo "File not found";
        exit;
    }
}

// Get current tab and selected subject
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';
$selected_subject = isset($_GET['subject']) ? $_GET['subject'] : null;

// Define subjects with their counts
$subjects = [
    ['name' => 'Physics', 'color' => 'bg-blue-500', 'count' => 0],
    ['name' => 'Biology', 'color' => 'bg-green-500', 'count' => 0],
    ['name' => 'Chemistry', 'color' => 'bg-purple-500', 'count' => 0],
    ['name' => 'Mathematics', 'color' => 'bg-orange-500', 'count' => 0],
    ['name' => 'Others', 'color' => 'bg-gray-500', 'count' => 0]
];

// Count files by subject
foreach ($_SESSION['uploaded_files'] as $file) {
    foreach ($subjects as &$subject) {
        if ($subject['name'] === $file['subject']) {
            $subject['count']++;
        }
    }
}

// Get recent files (first 5 for desktop)
$recent_files = array_slice($_SESSION['uploaded_files'], 0, 5);

// Filter files by subject if selected
$filtered_files = $_SESSION['uploaded_files'];
if ($selected_subject) {
    $filtered_files = array_filter($_SESSION['uploaded_files'], function ($file) use ($selected_subject) {
        return $file['subject'] === $selected_subject;
    });
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

        /* Custom scrollbar for webkit browsers */
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

        /* Sidebar transition */
        .sidebar-transition {
            transition: transform 0.3s ease-in-out;
        }

        /* Upload area hover effects */
        .upload-area:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        /* Card hover effects */
        .card-hover:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }
    </style>
</head>

<body class="bg-gray-50 overflow-hidden">
    <div class="flex h-screen">
        <!-- Sidebar -->
        <div class="w-64 bg-white shadow-sm border-r border-gray-200 flex flex-col">
            <!-- Header -->
            <div class="px-6 py-6 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h1 class="text-xl font-bold text-gray-800">StudyOrganizer</h1>
                    <div class="w-8 h-8 bg-blue-500 rounded-full flex items-center justify-center">
                        <span class="text-white text-sm font-semibold">K</span>
                    </div>
                </div>
            </div>

            <!-- Navigation -->
            <nav class="flex-1 px-4 py-6">
                <div class="space-y-2">
                    <a href="?tab=dashboard" class="flex items-center px-4 py-3 rounded-lg transition-colors <?php echo $active_tab === 'dashboard' ? 'bg-blue-100 text-blue-600' : 'text-gray-600 hover:bg-gray-100'; ?>">
                        <span class="text-xl mr-3">📚</span>
                        <span class="font-medium">Dashboard</span>
                    </a>
                    <a href="?tab=subjects" class="flex items-center px-4 py-3 rounded-lg transition-colors <?php echo $active_tab === 'subjects' ? 'bg-blue-100 text-blue-600' : 'text-gray-600 hover:bg-gray-100'; ?>">
                        <span class="text-xl mr-3">📁</span>
                        <span class="font-medium">All Subjects</span>
                    </a>
                </div>

                <!-- Subjects Quick Access -->
                <div class="mt-8">
                    <h3 class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Quick Access</h3>
                    <div class="space-y-1">
                        <?php foreach ($subjects as $subject): ?>
                            <a href="?tab=subjects&subject=<?php echo urlencode($subject['name']); ?>"
                                class="flex items-center justify-between px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg transition-colors">
                                <div class="flex items-center">
                                    <span class="mr-3"><?php echo getSubjectIcon($subject['name']); ?></span>
                                    <span><?php echo $subject['name']; ?></span>
                                </div>
                                <span class="text-xs bg-gray-200 text-gray-600 px-2 py-1 rounded-full"><?php echo $subject['count']; ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </nav>

            <!-- Footer -->
            <div class="px-4 py-4 border-t border-gray-200">
                <button class="w-full flex items-center px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg transition-colors">
                    <span class="text-lg mr-3">🔍</span>
                    <span>Search Files</span>
                </button>
            </div>
        </div>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col overflow-hidden">
            <!-- Top Bar -->
            <div class="bg-white px-8 py-4 border-b border-gray-200 flex items-center justify-between">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800">
                        <?php if ($active_tab === 'dashboard'): ?>
                            Welcome Back!
                        <?php elseif ($selected_subject): ?>
                            <?php echo htmlspecialchars($selected_subject); ?> Files
                        <?php else: ?>
                            All Subjects
                        <?php endif; ?>
                    </h2>
                    <p class="text-gray-600 mt-1">
                        <?php if ($active_tab === 'dashboard'): ?>
                            Manage your study documents with AI-powered organization
                        <?php else: ?>
                            <?php echo count($filtered_files); ?> files found
                        <?php endif; ?>
                    </p>
                </div>
                <div class="flex items-center space-x-4">
                    <button class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                        <span class="text-gray-600">⚙️ Settings</span>
                    </button>
                    <button class="px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-lg transition-colors">
                        <span>📤 Upload</span>
                    </button>
                </div>
            </div>

            <!-- Content Area -->
            <div class="flex-1 overflow-auto custom-scrollbar p-8">
                <?php if ($active_tab === 'dashboard'): ?>
                    <!-- Dashboard View -->
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                        <!-- Left Column - Upload and Stats -->
                        <div class="lg:col-span-2 space-y-8">
                            <!-- Upload Section -->
                            <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-2xl p-8 border border-blue-100">
                                <h3 class="text-2xl font-semibold text-gray-800 mb-6 flex items-center">
                                    <span class="mr-3">📤</span>
                                    Upload Documents
                                </h3>
                                <div class="grid grid-cols-3 gap-6 mb-6">
                                    <form method="post" enctype="multipart/form-data">
                                        <label class="cursor-pointer block">
                                            <input type="file" name="uploaded_file" accept=".pdf" onChange="this.form.submit()" class="hidden" />
                                            <div class="upload-area bg-white rounded-xl p-6 text-center hover:shadow-lg transition-all duration-300 border-2 border-dashed border-gray-200 hover:border-blue-300">
                                                <div class="text-red-500 text-4xl mb-3">📄</div>
                                                <span class="text-lg font-medium text-gray-800">PDF Files</span>
                                                <p class="text-sm text-gray-500 mt-2">Click to upload</p>
                                            </div>
                                        </label>
                                    </form>
                                    <form method="post" enctype="multipart/form-data">
                                        <label class="cursor-pointer block">
                                            <input type="file" name="uploaded_file" accept="image/*" onChange="this.form.submit()" class="hidden" />
                                            <div class="upload-area bg-white rounded-xl p-6 text-center hover:shadow-lg transition-all duration-300 border-2 border-dashed border-gray-200 hover:border-green-300">
                                                <div class="text-green-500 text-4xl mb-3">🖼️</div>
                                                <span class="text-lg font-medium text-gray-800">Images</span>
                                                <p class="text-sm text-gray-500 mt-2">JPG, PNG, etc.</p>
                                            </div>
                                        </label>
                                    </form>
                                    <div class="bg-gray-100 rounded-xl p-6 text-center border-2 border-dashed border-gray-200 opacity-50">
                                        <div class="text-gray-400 text-4xl mb-3">🎤</div>
                                        <span class="text-lg font-medium text-gray-500">Audio</span>
                                        <p class="text-sm text-gray-400 mt-2">Coming Soon</p>
                                    </div>
                                </div>
                                <div class="bg-blue-50 rounded-lg p-4 border-l-4 border-blue-500">
                                    <p class="text-blue-800 font-medium">💡 AI-Powered Organization</p>
                                    <p class="text-blue-700 text-sm mt-1">Files are automatically categorized by subject and summarized using advanced AI</p>
                                </div>
                            </div>

                            <!-- Subject Overview Grid -->
                            <div class="bg-white rounded-2xl p-8 shadow-sm border border-gray-200">
                                <h3 class="text-xl font-semibold text-gray-800 mb-6">Subject Overview</h3>
                                <div class="grid grid-cols-2 lg:grid-cols-3 gap-6">
                                    <?php foreach ($subjects as $subject): ?>
                                        <a href="?tab=subjects&subject=<?php echo urlencode($subject['name']); ?>"
                                            class="card-hover bg-gradient-to-br from-white to-gray-50 rounded-xl p-6 border border-gray-200 transition-all duration-300 block group">
                                            <div class="flex items-center justify-between mb-4">
                                                <div class="w-12 h-12 <?php echo $subject['color']; ?> rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform">
                                                    <span class="text-white text-xl"><?php echo getSubjectIcon($subject['name']); ?></span>
                                                </div>
                                                <span class="text-3xl font-bold text-gray-800"><?php echo $subject['count']; ?></span>
                                            </div>
                                            <p class="font-semibold text-gray-800 text-lg"><?php echo $subject['name']; ?></p>
                                            <p class="text-gray-600">files stored</p>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Right Column - Recent Files -->
                        <div class="space-y-8">
                            <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-200">
                                <h3 class="text-xl font-semibold text-gray-800 mb-6">Recent Files</h3>
                                <div class="space-y-4">
                                    <?php if (empty($recent_files)): ?>
                                        <div class="text-center py-8">
                                            <div class="text-gray-400 text-4xl mb-3">📂</div>
                                            <p class="text-gray-500">No files uploaded yet</p>
                                            <p class="text-gray-400 text-sm">Upload your first document to get started</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($recent_files as $file): ?>
                                            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-xl hover:bg-gray-100 transition-colors cursor-pointer" onclick="openFileDetails(<?php echo $file['id']; ?>)">
                                                <div class="flex items-center space-x-4">
                                                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                                                        <span class="text-blue-600 text-xl">📄</span>
                                                    </div>
                                                    <div class="flex-1">
                                                        <p class="font-medium text-gray-800 truncate"><?php echo htmlspecialchars($file['name']); ?></p>
                                                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($file['subject']); ?> • <?php echo $file['date']; ?></p>
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
                                                <span class="text-gray-400">›</span>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <?php if (count($_SESSION['uploaded_files']) > 5): ?>
                                    <div class="mt-6 text-center">
                                        <a href="?tab=subjects" class="text-blue-600 hover:text-blue-700 font-medium">View All Files →</a>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Quick Stats -->
                            <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-200">
                                <h3 class="text-xl font-semibold text-gray-800 mb-6">Quick Stats</h3>
                                <div class="space-y-4">
                                    <div class="flex items-center justify-between p-3 bg-blue-50 rounded-lg">
                                        <div class="flex items-center">
                                            <div class="w-8 h-8 bg-blue-500 rounded-lg flex items-center justify-center mr-3">
                                                <span class="text-white text-sm">📊</span>
                                            </div>
                                            <span class="font-medium text-gray-800">Total Files</span>
                                        </div>
                                        <span class="text-2xl font-bold text-blue-600"><?php echo count($_SESSION['uploaded_files']); ?></span>
                                    </div>
                                    <div class="flex items-center justify-between p-3 bg-green-50 rounded-lg">
                                        <div class="flex items-center">
                                            <div class="w-8 h-8 bg-green-500 rounded-lg flex items-center justify-center mr-3">
                                                <span class="text-white text-sm">🤖</span>
                                            </div>
                                            <span class="font-medium text-gray-800">AI Processed</span>
                                        </div>
                                        <span class="text-2xl font-bold text-green-600">
                                            <?php
                                            $processed = 0;
                                            foreach ($_SESSION['uploaded_files'] as $file) {
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
                                            <div class="w-8 h-8 bg-purple-500 rounded-lg flex items-center justify-center mr-3">
                                                <span class="text-white text-sm">📚</span>
                                            </div>
                                            <span class="font-medium text-gray-800">Subjects</span>
                                        </div>
                                        <span class="text-2xl font-bold text-purple-600">
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
                    <div class="space-y-8">
                        <!-- Filter Bar -->
                        <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-200">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-4">
                                    <?php if ($selected_subject): ?>
                                        <a href="?tab=subjects" class="text-blue-600 hover:text-blue-700 font-medium">← All Subjects</a>
                                        <span class="text-gray-400">|</span>
                                    <?php endif; ?>
                                    <div class="flex items-center space-x-2">
                                        <span class="text-gray-600">Filter by:</span>
                                        <div class="flex space-x-2">
                                            <a href="?tab=subjects" class="px-3 py-1 rounded-full text-sm <?php echo !$selected_subject ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?> transition-colors">
                                                All
                                            </a>
                                            <?php foreach ($subjects as $subject): ?>
                                                <?php if ($subject['count'] > 0): ?>
                                                    <a href="?tab=subjects&subject=<?php echo urlencode($subject['name']); ?>"
                                                        class="px-3 py-1 rounded-full text-sm <?php echo $selected_subject === $subject['name'] ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?> transition-colors">
                                                        <?php echo $subject['name']; ?> (<?php echo $subject['count']; ?>)
                                                    </a>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-3">
                                    <button class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                                        <span class="text-gray-600">🔄 Refresh</span>
                                    </button>
                                    <form method="post" enctype="multipart/form-data" class="inline">
                                        <label class="cursor-pointer">
                                            <input type="file" name="uploaded_file" accept=".pdf,image/*" onChange="this.form.submit()" class="hidden" />
                                            <div class="px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-lg transition-colors cursor-pointer">
                                                <span>📤 Upload File</span>
                                            </div>
                                        </label>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Files Grid -->
                        <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6">
                            <?php if (empty($filtered_files)): ?>
                                <div class="col-span-full text-center py-16">
                                    <div class="text-gray-400 text-6xl mb-4">📂</div>
                                    <h3 class="text-xl font-semibold text-gray-600 mb-2">No files found</h3>
                                    <p class="text-gray-500 mb-6">
                                        <?php echo $selected_subject ? "No files in " . htmlspecialchars($selected_subject) . " subject yet." : "Upload your first document to get started."; ?>
                                    </p>
                                    <form method="post" enctype="multipart/form-data" class="inline">
                                        <label class="cursor-pointer">
                                            <input type="file" name="uploaded_file" accept=".pdf,image/*" onChange="this.form.submit()" class="hidden" />
                                            <div class="px-6 py-3 bg-blue-500 hover:bg-blue-600 text-white rounded-lg transition-colors cursor-pointer inline-flex items-center">
                                                <span class="mr-2">📤</span>
                                                <span>Upload Your First File</span>
                                            </div>
                                        </label>
                                    </form>
                                </div>
                            <?php else: ?>
                                <?php foreach ($filtered_files as $file): ?>
                                    <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-200 hover:shadow-md transition-all duration-300 cursor-pointer card-hover" onclick="openFileDetails(<?php echo $file['id']; ?>)">
                                        <div class="flex items-start justify-between mb-4">
                                            <div class="flex items-center space-x-3">
                                                <div class="w-14 h-14 bg-blue-100 rounded-xl flex items-center justify-center">
                                                    <span class="text-blue-600 text-2xl">📄</span>
                                                </div>
                                                <div class="flex-1">
                                                    <h4 class="font-semibold text-gray-800 text-lg truncate"><?php echo htmlspecialchars($file['name']); ?></h4>
                                                    <p class="text-sm text-gray-600"><?php echo htmlspecialchars($file['subject']); ?> • <?php echo $file['date']; ?></p>
                                                </div>
                                            </div>
                                            <button onclick="event.stopPropagation(); openFileDetails(<?php echo $file['id']; ?>)" class="text-gray-400 hover:text-gray-600 p-1">⋯</button>
                                        </div>

                                        <div class="bg-gray-50 rounded-lg p-4 mb-4">
                                            <p class="text-sm text-gray-700 font-medium mb-2">AI Summary:</p>
                                            <p class="text-sm text-gray-600 line-clamp-3"><?php echo htmlspecialchars($file['summary']); ?></p>
                                        </div>

                                        <div class="flex items-center justify-between">
                                            <?php if (isset($file['status'])): ?>
                                                <div>
                                                    <?php if ($file['status'] === 'processing'): ?>
                                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs bg-yellow-100 text-yellow-800">
                                                            🔄 Processing...
                                                        </span>
                                                    <?php elseif ($file['status'] === 'completed'): ?>
                                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs bg-green-100 text-green-800">
                                                            ✅ Analyzed
                                                        </span>
                                                    <?php elseif ($file['status'] === 'error'): ?>
                                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs bg-red-100 text-red-800">
                                                            ❌ Error
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <div></div>
                                            <?php endif; ?>
                                            <button onclick="event.stopPropagation(); openFileDetails(<?php echo $file['id']; ?>)" class="text-blue-600 hover:text-blue-700 text-sm font-medium">
                                                View Details →
                                            </button>
                                        </div>

                                        <?php if (isset($file['debug_info']) && !empty($file['debug_info']) && $file['status'] === 'error'): ?>
                                            <div class="mt-4 p-3 bg-red-50 rounded-lg border-l-4 border-red-500">
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
    <div id="fileDetailsModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-2xl max-w-4xl w-full max-h-[90vh] overflow-hidden">
            <!-- Modal Header -->
            <div class="flex items-center justify-between p-6 border-b border-gray-200">
                <div>
                    <h3 id="modalFileName" class="text-xl font-semibold text-gray-800"></h3>
                    <p id="modalSubject" class="text-gray-600"></p>
                </div>
                <button onclick="closeFileDetails()" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
            </div>

            <!-- Modal Tabs -->
            <div class="border-b border-gray-200">
                <div class="flex">
                    <button id="summaryTab" onclick="switchTab('summary')" class="flex-1 py-4 px-6 text-sm font-medium text-blue-600 border-b-2 border-blue-600">
                        Summary & Details
                    </button>
                    <button id="fullTextTab" onclick="switchTab('fulltext')" class="flex-1 py-4 px-6 text-sm font-medium text-gray-500 hover:text-gray-700">
                        Original File
                    </button>
                </div>
            </div>

            <!-- Modal Content -->
            <div class="p-6 max-h-[60vh] overflow-auto custom-scrollbar">
                <!-- Summary Tab -->
                <div id="summaryContent" class="tab-content">
                    <div class="space-y-6">
                        <div>
                            <h4 class="font-semibold text-gray-800 mb-2">AI Summary</h4>
                            <div class="bg-gray-50 rounded-lg p-4">
                                <p id="modalSummary" class="text-gray-700"></p>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-6">
                            <div>
                                <h4 class="font-semibold text-gray-800 mb-2">File Information</h4>
                                <div class="space-y-2 text-sm">
                                    <p id="modalDate" class="text-gray-600"></p>
                                    <p id="modalStatus" class="text-gray-600"></p>
                                </div>
                            </div>
                            <div>
                                <h4 class="font-semibold text-gray-800 mb-2">Actions</h4>
                                <div class="space-y-2">
                                    <button onclick="reanalyzeFile()" class="w-full px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-lg transition-colors text-sm">
                                        <span class="mr-2">🔄</span>Re-analyze with AI
                                    </button>
                                    <button onclick="confirmDeleteFile()" class="w-full px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded-lg transition-colors text-sm">
                                        <span class="mr-2">🗑️</span>Delete File
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Full Text Tab -->
                <div id="fulltextContent" class="tab-content hidden">
                    <div>
                        <h4 class="font-semibold text-gray-800 mb-4">Original File Content</h4>
                        <div class="bg-gray-50 rounded-lg p-4">
                            <div id="fileViewer" class="text-center">
                                <div class="mb-4">
                                    <div class="inline-flex items-center space-x-2 text-gray-600 mb-3">
                                        <span class="text-2xl">📄</span>
                                        <span id="modalFileInfo" class="text-sm"></span>
                                    </div>
                                </div>
                                <div class="space-y-3">
                                    <button id="viewFileBtn" onclick="viewOriginalFile()" class="w-full px-4 py-3 bg-blue-500 hover:bg-blue-600 text-white rounded-lg transition-colors">
                                        <span class="mr-2">👁️</span>
                                        View Original File
                                    </button>
                                    <button id="downloadFileBtn" onclick="downloadOriginalFile()" class="w-full px-4 py-2 bg-green-500 hover:bg-green-600 text-white rounded-lg transition-colors">
                                        <span class="mr-2">⬇️</span>
                                        Download File
                                    </button>
                                </div>

                                <!-- File preview area -->
                                <div id="filePreviewArea" class="mt-4 hidden">
                                    <iframe id="filePreviewFrame" class="w-full h-96 border rounded-lg" style="min-height: 500px;"></iframe>
                                </div>

                                <!-- Extracted text preview -->
                                <div class="mt-4 border-t pt-4">
                                    <h5 class="font-medium text-gray-700 mb-2">Extracted Text Preview (for AI analysis)</h5>
                                    <div class="bg-white rounded border p-3 max-h-32 overflow-auto text-left">
                                        <pre id="extractedTextPreview" class="text-xs text-gray-600 whitespace-pre-wrap font-mono"></pre>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // File details modal functionality
        const fileData = <?php echo json_encode($_SESSION['uploaded_files']); ?>;
        let currentFileId = null;

        function openFileDetails(fileId) {
            const file = fileData.find(f => f.id == fileId);
            if (!file) return;

            currentFileId = fileId;

            // Update modal content
            document.getElementById('modalFileName').textContent = file.name;
            document.getElementById('modalSubject').textContent = file.subject;
            document.getElementById('modalSummary').textContent = file.summary;
            document.getElementById('modalDate').textContent = 'Uploaded: ' + file.date;
            document.getElementById('modalStatus').textContent = file.status ? 'Status: ' + file.status : '';

            // Update file info
            const fileSize = file.file_size ? formatFileSize(file.file_size) : 'Unknown size';
            const fileType = file.file_type || 'Unknown type';
            document.getElementById('modalFileInfo').textContent = `${fileSize} • ${fileType}`;

            // Update extracted text preview
            const extractedTextDiv = document.getElementById('extractedTextPreview');
            if (file.extracted_text && file.extracted_text.trim()) {
                extractedTextDiv.textContent = file.extracted_text;
            } else {
                extractedTextDiv.textContent = 'No extracted text available - this may be due to OCR processing failure.';
            }

            // Show/hide view button based on file availability
            const viewBtn = document.getElementById('viewFileBtn');
            const downloadBtn = document.getElementById('downloadFileBtn');

            if (file.file_path) {
                viewBtn.style.display = 'block';
                downloadBtn.style.display = 'block';

                // Show different text based on file type
                const extension = file.name.toLowerCase().split('.').pop();
                if (['pdf'].includes(extension)) {
                    viewBtn.innerHTML = '<span class="mr-2">👁️</span>View PDF';
                } else if (['jpg', 'jpeg', 'png', 'gif', 'bmp'].includes(extension)) {
                    viewBtn.innerHTML = '<span class="mr-2">🖼️</span>View Image';
                } else {
                    viewBtn.innerHTML = '<span class="mr-2">📄</span>View File';
                }
            } else {
                viewBtn.style.display = 'none';
                downloadBtn.style.display = 'none';
            }

            // Reset to summary tab
            switchTab('summary');

            // Show modal
            document.getElementById('fileDetailsModal').classList.remove('hidden');
        }

        function viewOriginalFile() {
            if (!currentFileId) return;

            const file = fileData.find(f => f.id == currentFileId);
            if (!file || !file.file_path) {
                alert('File not available for viewing');
                return;
            }

            const extension = file.name.toLowerCase().split('.').pop();
            const viewUrl = `?action=view_file&file_id=${currentFileId}`;

            if (['pdf', 'jpg', 'jpeg', 'png', 'gif', 'bmp'].includes(extension)) {
                // Show in iframe for viewable files
                const previewArea = document.getElementById('filePreviewArea');
                const iframe = document.getElementById('filePreviewFrame');

                iframe.src = viewUrl;
                previewArea.classList.remove('hidden');

                // Scroll to preview
                previewArea.scrollIntoView({
                    behavior: 'smooth'
                });
            } else {
                // Open in new tab for other files
                window.open(viewUrl, '_blank');
            }
        }

        function downloadOriginalFile() {
            if (!currentFileId) return;

            const file = fileData.find(f => f.id == currentFileId);
            if (!file || !file.file_path) {
                alert('File not available for download');
                return;
            }

            // Create a temporary link and click it to download
            const link = document.createElement('a');
            link.href = `?action=view_file&file_id=${currentFileId}`;
            link.download = file.name;
            link.style.display = 'none';

            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        function reanalyzeFile() {
            if (!currentFileId) return;

            const file = fileData.find(f => f.id == currentFileId);
            if (!file) {
                alert('File not found');
                return;
            }

            if (confirm(`Are you sure you want to re-analyze "${file.name}"?\n\nThis will send the file through OCR and AI analysis again.`)) {
                // Show loading state
                const reanalyzeBtn = document.querySelector('button[onclick="reanalyzeFile()"]');
                const originalText = reanalyzeBtn.innerHTML;
                reanalyzeBtn.innerHTML = '<span class="mr-2">⏳</span>Re-analyzing...';
                reanalyzeBtn.disabled = true;

                // Create form and submit
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'reanalyze';

                const fileIdInput = document.createElement('input');
                fileIdInput.type = 'hidden';
                fileIdInput.name = 'file_id';
                fileIdInput.value = currentFileId;

                form.appendChild(actionInput);
                form.appendChild(fileIdInput);
                document.body.appendChild(form);

                form.submit();
            }
        }

        function confirmDeleteFile() {
            if (!currentFileId) return;

            const file = fileData.find(f => f.id == currentFileId);
            if (!file) {
                alert('File not found');
                return;
            }

            if (confirm(`Are you sure you want to permanently delete "${file.name}"?\n\nThis action cannot be undone. The original file and all associated data will be removed.`)) {
                // Show loading state
                const deleteBtn = document.querySelector('button[onclick="confirmDeleteFile()"]');
                const originalText = deleteBtn.innerHTML;
                deleteBtn.innerHTML = '<span class="mr-2">⏳</span>Deleting...';
                deleteBtn.disabled = true;

                // Create form and submit
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete';

                const fileIdInput = document.createElement('input');
                fileIdInput.type = 'hidden';
                fileIdInput.name = 'file_id';
                fileIdInput.value = currentFileId;

                form.appendChild(actionInput);
                form.appendChild(fileIdInput);
                document.body.appendChild(form);

                form.submit();
            }
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        function closeFileDetails() {
            document.getElementById('fileDetailsModal').classList.add('hidden');
        }

        function switchTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.add('hidden');
            });

            // Remove active styles from all tabs
            document.getElementById('summaryTab').className = 'flex-1 py-4 px-6 text-sm font-medium text-gray-500 hover:text-gray-700';
            document.getElementById('fullTextTab').className = 'flex-1 py-4 px-6 text-sm font-medium text-gray-500 hover:text-gray-700';

            // Show selected tab content and apply active styles
            if (tabName === 'summary') {
                document.getElementById('summaryContent').classList.remove('hidden');
                document.getElementById('summaryTab').className = 'flex-1 py-4 px-6 text-sm font-medium text-blue-600 border-b-2 border-blue-600';
            } else if (tabName === 'fulltext') {
                document.getElementById('fulltextContent').classList.remove('hidden');
                document.getElementById('fullTextTab').className = 'flex-1 py-4 px-6 text-sm font-medium text-blue-600 border-b-2 border-blue-600';
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
            }
        });

        // Auto-refresh the page when processing files to show updated status
        <?php
        $hasProcessingFiles = false;
        if (isset($_SESSION['uploaded_files']) && !empty($_SESSION['uploaded_files'])) {
            foreach ($_SESSION['uploaded_files'] as $file) {
                if (isset($file['status']) && $file['status'] === 'processing') {
                    $hasProcessingFiles = true;
                    break; // This break is now in PHP context
                }
            }
        }
        if ($hasProcessingFiles): ?>
            setTimeout(function() {
                window.location.reload();
            }, 3000);

            // Show processing notification
            const processingNotification = document.createElement('div');
            processingNotification.id = 'processingNotification';
            processingNotification.className = 'fixed top-4 right-4 bg-blue-500 text-white px-4 py-2 rounded-lg shadow-lg z-50';
            processingNotification.innerHTML = `
                <div class="flex items-center space-x-2">
                    <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-white"></div>
                    <span>Processing files... Page will refresh automatically</span>
                </div>
            `;
            document.body.appendChild(processingNotification);
        <?php endif; ?>

        // Show loading state when uploading files
        document.addEventListener('change', function(e) {
            if (e.target.type === 'file' && e.target.files.length > 0) {
                // Create loading overlay
                const overlay = document.createElement('div');
                overlay.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
                overlay.innerHTML = `
                <div class="bg-white rounded-2xl p-8 text-center max-w-md">
                    <div class="animate-spin rounded-full h-16 w-16 border-b-4 border-blue-500 mx-auto mb-6"></div>
                    <h3 class="text-xl font-semibold text-gray-800 mb-2">Processing your file...</h3>
                    <p class="text-gray-600 mb-2">OCR and AI analysis in progress</p>
                    <div class="bg-blue-50 rounded-lg p-4 mt-4">
                        <p class="text-blue-800 text-sm">⚡ This may take a few moments for larger files</p>
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
            });
        });

        // Add keyboard shortcuts
        document.addEventListener('keydown', function(e) {
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
        });
    </script>
</body>

</html>
