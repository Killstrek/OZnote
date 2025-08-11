    <?php
    // Database functions with UTF-8 support and Thailand timezone (UTC+7)
    // Set default timezone to Thailand
    date_default_timezone_set('Asia/Bangkok');

    // UPDATED: Database connection with UTF-8 support and Thailand timezone
    function getDBConnection()
    {
        static $pdo = null;

        if ($pdo === null) {
            try {
                $host = env('DB_HOST', 'localhost');
                $dbname = env('DB_NAME', 'your_default_db');
                $username = env('DB_USER', 'root');
                $password = env('DB_PASS', '');

                $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";

                $pdo = new PDO($dsn, $username, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                ]);
            } catch (PDOException $e) {
                error_log("Database connection failed: " . $e->getMessage());
                throw new Exception("Database connection failed");
            }
        }

        return $pdo;
    }

    // FIXED: Save file to database with proper timestamp and UTF-8 handling
    function saveFileToDatabase($user_id, $file_name, $subject, $file_type, $file_path, $summary_path, $language, $original_pdf_id = null, $pdf_page_number = null, $is_pdf_page = false)
    {
        try {
            $pdo = getDBConnection();

            // Ensure new columns exist
            try {
                $pdo->exec("ALTER TABLE user_files ADD COLUMN IF NOT EXISTS original_pdf_path VARCHAR(500) DEFAULT NULL");
                $pdo->exec("ALTER TABLE user_files ADD COLUMN IF NOT EXISTS pdf_page_number INT DEFAULT NULL");
                $pdo->exec("ALTER TABLE user_files ADD COLUMN IF NOT EXISTS is_pdf_page BOOLEAN DEFAULT FALSE");
            } catch (Exception $e) {
                // Columns might already exist
            }

            // Get original PDF path if this is a PDF page
            $original_pdf_path = null;
            if ($original_pdf_id) {
                $stmt = $pdo->prepare("SELECT original_file_path FROM user_files WHERE id = ? AND user_id = ?");
                $stmt->execute([$original_pdf_id, $user_id]);
                $result = $stmt->fetch();
                $original_pdf_path = $result ? $result['original_file_path'] : null;

                // Debug log
                error_log("Linking PDF page to original: PDF ID=$original_pdf_id, Path=$original_pdf_path");
            }

            $sql = "INSERT INTO user_files (user_id, file_name, subject, file_type, original_file_path, summary_file_path, language, original_pdf_path, pdf_page_number, is_pdf_page, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $stmt = $pdo->prepare($sql);
            $success = $stmt->execute([
                $user_id,
                $file_name,
                $subject,
                $file_type,
                $file_path,
                $summary_path,
                $language,
                $original_pdf_path,
                $pdf_page_number,
                $is_pdf_page ? 1 : 0
            ]);

            if ($success) {
                $file_id = $pdo->lastInsertId();
                error_log("Saved file to database: ID=$file_id, is_pdf_page=" . ($is_pdf_page ? 'true' : 'false'));
                return $file_id;
            }

            return false;
        } catch (Exception $e) {
            error_log("Database save error: " . $e->getMessage());
            return false;
        }
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

    // ENHANCED: Professional summary text file creation with proper formatting
    function createSummaryTextFile($summary_content, $filename, $user_id, $subject, $language, $alt_summary = '')
    {
        try {
            $user_folder = UPLOAD_DIR . 'user_' . $user_id . '/summaries/';

            // Create summaries folder if it doesn't exist
            if (!file_exists($user_folder)) {
                mkdir($user_folder, 0755, true);
            }

            $timestamp = time();
            $clean_filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
            $clean_filename = substr($clean_filename, 0, 50); // Limit length
            $summary_filename = $timestamp . '_summary_' . $clean_filename . '.txt';
            $summary_path = $user_folder . $summary_filename;

            // Generate professional formatted content
            $formatted_content = generateProfessionalSummary($summary_content, $filename, $subject, $language, $alt_summary);

            // Write to file with UTF-8 encoding
            $result = file_put_contents($summary_path, $formatted_content, LOCK_EX);

            if ($result === false) {
                error_log("Failed to create summary file: " . $summary_path);
                return null;
            }

            error_log("Summary file created successfully: " . $summary_path);
            return $summary_path;
        } catch (Exception $e) {
            error_log("Error creating summary file: " . $e->getMessage());
            return null;
        }
    }

    // UPDATED: Function to generate professionally formatted summary with better readability
    function generateProfessionalSummary($raw_summary, $filename, $subject, $language, $alt_summary = '')
    {
        // Get current time in Thailand timezone
        date_default_timezone_set('Asia/Bangkok');
        $thai_time = date('Y-m-d H:i:s');
        $readable_time = date('l, F j, Y \a\t g:i A');

        // Get subject icon
        $subject_icons = [
            'Physics' => '⚛️',
            'Biology' => '🔬',
            'Chemistry' => '🧪',
            'Mathematics' => '🔢',
            'Others' => '📄'
        ];
        $subject_icon = $subject_icons[$subject] ?? '📄';

        // Language flag and name
        $language_info = ($language === 'th') ? '🇹🇭 Thai (ภาษาไทย)' : '🇺🇸 English';

        if ($language === 'th') {
            // Thai format with better readability
            $formatted = "📄 สรุปการวิเคราะห์เอกสาร\n";
            $formatted .= str_repeat("=", 60) . "\n\n";

            $formatted .= "📋 ชื่อไฟล์: {$filename}\n";
            $formatted .= "{$subject_icon} หมวดวิชา: {$subject}\n";
            $formatted .= "🌐 ภาษา: {$language_info}\n";
            $formatted .= "📅 วันที่สร้าง: {$readable_time} +07\n\n";

            $formatted .= str_repeat("-", 50) . "\n";
            $formatted .= "🤖 การวิเคราะห์ด้วย AI\n";
            $formatted .= str_repeat("-", 50) . "\n\n";

            // Process and format the AI summary content
            $formatted .= formatAiSummaryContent($raw_summary) . "\n\n";

            $formatted .= str_repeat("=", 60) . "\n";
            $formatted .= "📊 ข้อมูลเทคนิค\n";
            $formatted .= str_repeat("=", 60) . "\n";
            $formatted .= "• เครื่องมือ AI: Claude (Anthropic)\n";
            $formatted .= "• ภาษาที่ประมวลผล: ภาษาไทย\n";
            $formatted .= "• เวลาที่สร้าง: {$readable_time} +07\n";
            $formatted .= str_repeat("=", 60) . "\n";
        } else {
            // English format with better readability
            $formatted = "📄 DOCUMENT ANALYSIS SUMMARY\n";
            $formatted .= str_repeat("=", 60) . "\n\n";

            $formatted .= "📋 Document: {$filename}\n";
            $formatted .= "{$subject_icon} Subject: {$subject}\n";
            $formatted .= "🌐 Language: {$language_info}\n";
            $formatted .= "📅 Generated: {$readable_time} +07\n\n";

            $formatted .= str_repeat("-", 50) . "\n";
            $formatted .= "🤖 AI ANALYSIS\n";
            $formatted .= str_repeat("-", 50) . "\n\n";

            // Process and format the AI summary content
            $formatted .= formatAiSummaryContent($raw_summary) . "\n\n";

            $formatted .= str_repeat("=", 60) . "\n";
            $formatted .= "📊 TECHNICAL INFO\n";
            $formatted .= str_repeat("=", 60) . "\n";
            $formatted .= "• AI Analysis: Claude (Anthropic)\n";
            $formatted .= "• Processing Language: English\n";
            $formatted .= "• Generated Time: {$readable_time} +07\n";
            $formatted .= str_repeat("=", 60) . "\n";
        }

        // Add bilingual summary if available (but only if it's different and substantial)
        if (!empty($alt_summary) && strlen(trim($alt_summary)) > 50 && $alt_summary !== $raw_summary) {
            $formatted .= "\n" . str_repeat("-", 50) . "\n";
            if ($language === 'th') {
                $formatted .= "🇺🇸 ENGLISH SUMMARY\n";
            } else {
                $formatted .= "🇹🇭 สรุปภาษาไทย\n";
            }
            $formatted .= str_repeat("-", 50) . "\n\n";
            $formatted .= formatAiSummaryContent($alt_summary) . "\n\n";
            $formatted .= str_repeat("=", 60) . "\n";
        }

        return $formatted;
    }

    // NEW: Helper function to format AI summary content with proper line breaks
    function formatAiSummaryContent($summary_text)
    {
        // Clean up the summary text
        $cleaned = trim($summary_text);

        // Replace literal \n with actual line breaks
        $cleaned = str_replace('\\n', "\n", $cleaned);

        // Ensure proper spacing around sections
        $cleaned = preg_replace('/\n\n+/', "\n\n", $cleaned);

        // Fix bullet points to ensure they're on new lines
        $cleaned = preg_replace('/([^\n])(\n•)/', '$1' . "\n\n" . '•', $cleaned);

        // Add spacing after section headers (lines with emojis and colons)
        $cleaned = preg_replace('/([📚🔑🏷️📋][^:]*:)\s*\n/', '$1' . "\n\n", $cleaned);

        // Clean up any excessive spacing
        $cleaned = preg_replace('/\n{3,}/', "\n\n", $cleaned);

        return $cleaned;
    }

    // NEW: Helper function to parse AI summary content into structured components
    function parseAiSummaryContent($summary_text, $language)
    {
        $parsed = [
            'topic' => '',
            'key_points' => [],
            'keywords' => '',
            'classification_reason' => '',
            'additional_content' => ''
        ];

        // Clean and normalize the text
        $text = trim($summary_text);
        $lines = explode("\n", $text);

        $current_section = '';
        $additional_lines = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Detect different sections based on common patterns
            if ($language === 'th') {
                // Thai patterns
                if (preg_match('/^หัวข้อ\s*[:：]\s*(.+)$/u', $line, $matches)) {
                    $parsed['topic'] = trim($matches[1]);
                    $current_section = 'topic';
                } elseif (preg_match('/^(ประเด็นสำคัญ|จุดสำคัญ|สาระสำคัญ)\s*[:：]/u', $line)) {
                    $current_section = 'key_points';
                } elseif (preg_match('/^คำ(สำคัญ|ศัพท์สำคัญ)\s*[:：]\s*(.+)$/u', $line, $matches)) {
                    $parsed['keywords'] = trim($matches[2]);
                    $current_section = 'keywords';
                } elseif (preg_match('/^(หมวดวิชา|การจัดหมวด)\s*[:：]\s*(.+)$/u', $line, $matches)) {
                    $parsed['classification_reason'] = trim($matches[2]);
                    $current_section = 'classification';
                } elseif (preg_match('/^•\s*(.+)$/u', $line, $matches)) {
                    if ($current_section === 'key_points') {
                        $parsed['key_points'][] = trim($matches[1]);
                    } else {
                        $additional_lines[] = $line;
                    }
                } else {
                    $additional_lines[] = $line;
                }
            } else {
                // English patterns
                if (preg_match('/^Topic\s*[:：]\s*(.+)$/i', $line, $matches)) {
                    $parsed['topic'] = trim($matches[1]);
                    $current_section = 'topic';
                } elseif (preg_match('/^Key\s+Points\s*[:：]/i', $line)) {
                    $current_section = 'key_points';
                } elseif (preg_match('/^Keywords?\s*[:：]\s*(.+)$/i', $line, $matches)) {
                    $parsed['keywords'] = trim($matches[1]);
                    $current_section = 'keywords';
                } elseif (preg_match('/^Subject\s+Classification\s*[:：]\s*(.+)$/i', $line, $matches)) {
                    $parsed['classification_reason'] = trim($matches[1]);
                    $current_section = 'classification';
                } elseif (preg_match('/^•\s*(.+)$/', $line, $matches)) {
                    if ($current_section === 'key_points') {
                        $parsed['key_points'][] = trim($matches[1]);
                    } else {
                        $additional_lines[] = $line;
                    }
                } else {
                    $additional_lines[] = $line;
                }
            }
        }

        // If we couldn't parse structured content, treat everything as additional content
        if (empty($parsed['topic']) && empty($parsed['key_points']) && empty($parsed['keywords'])) {
            $parsed['additional_content'] = $text;
        } else {
            // Add any unparsed lines as additional content
            if (!empty($additional_lines)) {
                $parsed['additional_content'] = implode("\n", $additional_lines);
            }
        }

        return $parsed;
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

    function ensurePdfColumns()
    {
        try {
            $pdo = getDBConnection();

            // Check and add columns if they don't exist
            $columns_to_add = [
                'original_pdf_path' => 'ALTER TABLE user_files ADD COLUMN original_pdf_path VARCHAR(500) DEFAULT NULL',
                'pdf_page_number' => 'ALTER TABLE user_files ADD COLUMN pdf_page_number INT DEFAULT NULL',
                'is_pdf_page' => 'ALTER TABLE user_files ADD COLUMN is_pdf_page BOOLEAN DEFAULT FALSE'
            ];

            foreach ($columns_to_add as $column => $sql) {
                $check = $pdo->query("SHOW COLUMNS FROM user_files LIKE '$column'");
                if ($check->rowCount() == 0) {
                    $pdo->exec($sql);
                    error_log("Added column: $column");
                }
            }
        } catch (Exception $e) {
            error_log("Error adding PDF columns: " . $e->getMessage());
        }
    }

    // Call this function when initializing your database connection
    ensurePdfColumns();

    function serveOriginalFile($file_id, $user_id)
    {
        error_log("=== Serving original file ===");
        error_log("File ID: $file_id, User ID: $user_id");

        try {
            $pdo = getDBConnection();
            $sql = "SELECT * FROM user_files WHERE id = ? AND user_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$file_id, $user_id]);
            $file = $stmt->fetch();

            if (!$file) {
                error_log("File not found in database");
                http_response_code(404);
                echo "File not found";
                return;
            }

            // ALWAYS serve the original file path (which contains the PDF with original name)
            $file_to_serve = $file['original_file_path'];

            error_log("Serving file: " . $file_to_serve);
            error_log("File type: " . $file['file_type']);

            if (!file_exists($file_to_serve)) {
                error_log("Physical file not found: " . $file_to_serve);
                http_response_code(404);
                echo "Physical file not found";
                return;
            }

            // Determine content type
            $file_extension = strtolower(pathinfo($file_to_serve, PATHINFO_EXTENSION));
            $content_types = [
                'pdf' => 'application/pdf',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp'
            ];

            $content_type = $content_types[$file_extension] ?? 'application/octet-stream';

            // Set headers for inline viewing
            header('Content-Type: ' . $content_type);
            header('Content-Length: ' . filesize($file_to_serve));
            header('Content-Disposition: inline; filename="' . basename($file_to_serve) . '"');

            // Serve the file
            readfile($file_to_serve);

            error_log("File served successfully");
        } catch (Exception $e) {
            error_log("Error serving file: " . $e->getMessage());
            http_response_code(500);
            echo "Error serving file";
        }
    }

    function getMimeType($extension)
    {
        $mime_types = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp'
        ];

        return $mime_types[$extension] ?? 'application/octet-stream';
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

    ?>
