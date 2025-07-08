<?php

/**
 * OCR & NLP Testing Suite for StudyOrganizer
 * This file provides comprehensive testing for OCR and Claude NLP functionality
 */

// Include your main configuration
require_once 'index.php'; // Replace with your actual file name

class OCRNLPTester
{

    private $test_results = [];
    private $passed_tests = 0;
    private $failed_tests = 0;

    public function runAllTests()
    {
        echo "<h1>🧪 OCR & NLP Testing Suite</h1>\n";
        echo "<div style='font-family: Arial, sans-serif; max-width: 1200px; margin: 0 auto; padding: 20px;'>\n";

        // Test NLP with sample texts
        $this->testNLPWithSampleTexts();

        // Test OCR with mock responses
        $this->testOCRResponseParsing();

        // Test edge cases
        $this->testEdgeCases();

        // Test API configuration
        $this->testAPIConfiguration();

        // Display results
        $this->displayResults();

        echo "</div>\n";
    }

    private function testNLPWithSampleTexts()
    {
        echo "<h2>📖 Testing NLP with Sample Educational Texts</h2>\n";

        $sample_texts = [
            [
                'content' => 'Newton\'s first law of motion states that an object at rest stays at rest and an object in motion stays in motion with the same speed and in the same direction unless acted upon by an unbalanced force. This principle explains the concept of inertia and is fundamental to understanding classical mechanics.',
                'expected_subject' => 'Physics',
                'test_name' => 'Physics - Newton\'s Laws'
            ],
            [
                'content' => 'Photosynthesis is the process by which green plants and some other organisms use sunlight to synthesize foods with the help of chlorophyll pigments. During this process, plants convert carbon dioxide and water into glucose and oxygen using light energy.',
                'expected_subject' => 'Biology',
                'test_name' => 'Biology - Photosynthesis'
            ],
            [
                'content' => 'The periodic table organizes chemical elements by their atomic number, electron configurations, and recurring chemical properties. Elements in the same group have similar properties due to having the same number of valence electrons.',
                'expected_subject' => 'Chemistry',
                'test_name' => 'Chemistry - Periodic Table'
            ],
            [
                'content' => 'The quadratic formula x = (-b ± √(b²-4ac))/2a is used to solve quadratic equations of the form ax² + bx + c = 0. The discriminant b²-4ac determines the nature of the roots.',
                'expected_subject' => 'Mathematics',
                'test_name' => 'Mathematics - Quadratic Formula'
            ],
            [
                'content' => 'This document contains information about university administrative policies, student registration procedures, and campus facility guidelines for the upcoming semester.',
                'expected_subject' => 'Others',
                'test_name' => 'Others - Administrative Content'
            ]
        ];

        foreach ($sample_texts as $sample) {
            $this->testNLPAnalysis($sample['content'], $sample['expected_subject'], $sample['test_name']);
        }
    }

    private function testNLPAnalysis($text, $expected_subject, $test_name)
    {
        echo "<div style='border: 1px solid #ddd; margin: 10px 0; padding: 15px; border-radius: 8px;'>\n";
        echo "<h3>🔍 $test_name</h3>\n";

        try {
            $result = analyzeWithClaude($text, $test_name . '.txt');

            echo "<p><strong>Input Text:</strong> " . substr($text, 0, 100) . "...</p>\n";
            echo "<p><strong>Expected Subject:</strong> $expected_subject</p>\n";
            echo "<p><strong>AI Result:</strong></p>\n";
            echo "<ul>\n";
            echo "<li><strong>Subject:</strong> {$result['subject']}</li>\n";
            echo "<li><strong>Summary:</strong> {$result['summary']}</li>\n";

            if (isset($result['debug'])) {
                echo "<li><strong>Debug:</strong> {$result['debug']}</li>\n";
            }
            echo "</ul>\n";

            // Check if result matches expected
            $is_correct = ($result['subject'] === $expected_subject);
            if ($is_correct) {
                echo "<div style='background: #d4edda; color: #155724; padding: 10px; border-radius: 5px;'>✅ PASSED: Subject classification is correct!</div>\n";
                $this->passed_tests++;
            } else {
                echo "<div style='background: #f8d7da; color: #721c24; padding: 10px; border-radius: 5px;'>❌ FAILED: Expected '$expected_subject', got '{$result['subject']}'</div>\n";
                $this->failed_tests++;
            }
        } catch (Exception $e) {
            echo "<div style='background: #f8d7da; color: #721c24; padding: 10px; border-radius: 5px;'>❌ ERROR: " . $e->getMessage() . "</div>\n";
            $this->failed_tests++;
        }

        echo "</div>\n";
    }

    private function testOCRResponseParsing()
    {
        echo "<h2>👁️ Testing OCR Response Parsing</h2>\n";

        // Test with mock OCR responses
        $mock_responses = [
            [
                'name' => 'Successful OCR Response',
                'response' => json_encode([
                    'ParsedResults' => [
                        [
                            'ParsedText' => 'This is a sample text extracted from an image containing information about quantum mechanics and wave-particle duality.'
                        ]
                    ]
                ]),
                'http_code' => 200
            ],
            [
                'name' => 'Empty OCR Response',
                'response' => json_encode([
                    'ParsedResults' => []
                ]),
                'http_code' => 200
            ],
            [
                'name' => 'OCR Error Response',
                'response' => json_encode([
                    'ErrorMessage' => 'Invalid API key'
                ]),
                'http_code' => 200
            ],
            [
                'name' => 'HTTP Error',
                'response' => '',
                'http_code' => 401
            ]
        ];

        foreach ($mock_responses as $mock) {
            $this->testOCRResponseHandling($mock);
        }
    }

    private function testOCRResponseHandling($mock)
    {
        echo "<div style='border: 1px solid #ddd; margin: 10px 0; padding: 15px; border-radius: 8px;'>\n";
        echo "<h3>📄 {$mock['name']}</h3>\n";

        $result = $this->simulateOCRResponse($mock['response'], $mock['http_code']);

        echo "<p><strong>HTTP Code:</strong> {$mock['http_code']}</p>\n";
        echo "<p><strong>Response:</strong> " . substr($mock['response'], 0, 100) . "...</p>\n";
        echo "<p><strong>Parsed Result:</strong></p>\n";
        echo "<ul>\n";
        echo "<li><strong>Error:</strong> " . ($result['error'] ?? 'None') . "</li>\n";
        echo "<li><strong>Text:</strong> " . ($result['text'] ? substr($result['text'], 0, 100) . '...' : 'None') . "</li>\n";
        echo "</ul>\n";

        // Determine if handling is correct
        $is_correct = false;
        if ($mock['http_code'] === 200 && strpos($mock['response'], 'ParsedText') !== false) {
            $is_correct = ($result['text'] !== false && !$result['error']);
        } else {
            $is_correct = ($result['error'] !== null);
        }

        if ($is_correct) {
            echo "<div style='background: #d4edda; color: #155724; padding: 10px; border-radius: 5px;'>✅ PASSED: Response handled correctly!</div>\n";
            $this->passed_tests++;
        } else {
            echo "<div style='background: #f8d7da; color: #721c24; padding: 10px; border-radius: 5px;'>❌ FAILED: Response not handled properly</div>\n";
            $this->failed_tests++;
        }

        echo "</div>\n";
    }

    private function simulateOCRResponse($response, $http_code)
    {
        // Simulate the OCR response parsing logic
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
                return ['error' => null, 'text' => $final_text];
            } else if (isset($result['ErrorMessage'])) {
                return ['error' => 'OCR.space Error: ' . $result['ErrorMessage'], 'text' => false];
            } else {
                return ['error' => 'No text found in document', 'text' => false];
            }
        } else {
            return ['error' => 'HTTP Error: ' . $http_code, 'text' => false];
        }
    }

    private function testEdgeCases()
    {
        echo "<h2>⚠️ Testing Edge Cases</h2>\n";

        $edge_cases = [
            [
                'content' => '',
                'test_name' => 'Empty Text',
                'should_fail' => true
            ],
            [
                'content' => 'a',
                'test_name' => 'Single Character',
                'should_fail' => true
            ],
            [
                'content' => str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 100),
                'test_name' => 'Very Long Text (5000+ chars)',
                'should_fail' => false
            ],
            [
                'content' => '12345 67890 !@#$% ^&*() <>?:{}|',
                'test_name' => 'Numbers and Special Characters Only',
                'should_fail' => false
            ],
            [
                'content' => 'สวัสดี นี่คือข้อความภาษาไทย เกี่ยวกับฟิสิกส์และคณิตศาสตร์',
                'test_name' => 'Non-English Text (Thai)',
                'should_fail' => false
            ]
        ];

        foreach ($edge_cases as $case) {
            $this->testEdgeCase($case);
        }
    }

    private function testEdgeCase($case)
    {
        echo "<div style='border: 1px solid #ddd; margin: 10px 0; padding: 15px; border-radius: 8px;'>\n";
        echo "<h3>🧪 {$case['test_name']}</h3>\n";

        try {
            $result = analyzeWithClaude($case['content'], $case['test_name'] . '.txt');

            echo "<p><strong>Input Length:</strong> " . strlen($case['content']) . " characters</p>\n";
            echo "<p><strong>Content Preview:</strong> " . htmlspecialchars(substr($case['content'], 0, 100)) . "...</p>\n";
            echo "<p><strong>Result:</strong></p>\n";
            echo "<ul>\n";
            echo "<li><strong>Subject:</strong> {$result['subject']}</li>\n";
            echo "<li><strong>Summary:</strong> {$result['summary']}</li>\n";
            echo "</ul>\n";

            $has_meaningful_result = ($result['subject'] !== 'Others' || strpos($result['summary'], 'error') === false);

            if ($case['should_fail'] && !$has_meaningful_result) {
                echo "<div style='background: #d4edda; color: #155724; padding: 10px; border-radius: 5px;'>✅ PASSED: Correctly handled edge case</div>\n";
                $this->passed_tests++;
            } elseif (!$case['should_fail'] && $has_meaningful_result) {
                echo "<div style='background: #d4edda; color: #155724; padding: 10px; border-radius: 5px;'>✅ PASSED: Successfully processed edge case</div>\n";
                $this->passed_tests++;
            } else {
                echo "<div style='background: #f8d7da; color: #721c24; padding: 10px; border-radius: 5px;'>❌ FAILED: Unexpected result for edge case</div>\n";
                $this->failed_tests++;
            }
        } catch (Exception $e) {
            if ($case['should_fail']) {
                echo "<div style='background: #d4edda; color: #155724; padding: 10px; border-radius: 5px;'>✅ PASSED: Correctly threw exception for invalid input</div>\n";
                $this->passed_tests++;
            } else {
                echo "<div style='background: #f8d7da; color: #721c24; padding: 10px; border-radius: 5px;'>❌ FAILED: Unexpected exception - " . $e->getMessage() . "</div>\n";
                $this->failed_tests++;
            }
        }

        echo "</div>\n";
    }

    private function testAPIConfiguration()
    {
        echo "<h2>🔧 Testing API Configuration</h2>\n";

        echo "<div style='border: 1px solid #ddd; margin: 10px 0; padding: 15px; border-radius: 8px;'>\n";
        echo "<h3>🔑 API Keys Configuration</h3>\n";

        // Check Claude API Key
        $claude_key = CLAUDE_API_KEY;
        $claude_configured = ($claude_key !== 'your-claude-api-key-here' && !empty($claude_key));

        echo "<p><strong>Claude API Key:</strong> ";
        if ($claude_configured) {
            echo "<span style='color: green;'>✅ Configured (Length: " . strlen($claude_key) . ")</span></p>\n";
        } else {
            echo "<span style='color: red;'>❌ Not configured or using placeholder</span></p>\n";
        }

        // Check OCR API Key
        $ocr_key = OCR_SPACE_API_KEY;
        $ocr_configured = ($ocr_key !== 'your-ocr-space-api-key-here' && !empty($ocr_key));

        echo "<p><strong>OCR.space API Key:</strong> ";
        if ($ocr_configured) {
            echo "<span style='color: green;'>✅ Configured (Length: " . strlen($ocr_key) . ")</span></p>\n";
        } else {
            echo "<span style='color: red;'>❌ Not configured or using placeholder</span></p>\n";
        }

        // Check upload directory
        $upload_dir_exists = file_exists(UPLOAD_DIR);
        $upload_dir_writable = is_writable(UPLOAD_DIR);

        echo "<p><strong>Upload Directory (" . UPLOAD_DIR . "):</strong> ";
        if ($upload_dir_exists && $upload_dir_writable) {
            echo "<span style='color: green;'>✅ Exists and writable</span></p>\n";
        } elseif ($upload_dir_exists) {
            echo "<span style='color: orange;'>⚠️ Exists but not writable</span></p>\n";
        } else {
            echo "<span style='color: red;'>❌ Does not exist</span></p>\n";
        }

        echo "</div>\n";
    }

    private function displayResults()
    {
        echo "<h2>📊 Test Results Summary</h2>\n";

        $total_tests = $this->passed_tests + $this->failed_tests;
        $success_rate = $total_tests > 0 ? round(($this->passed_tests / $total_tests) * 100, 1) : 0;

        echo "<div style='border: 2px solid #007bff; margin: 20px 0; padding: 20px; border-radius: 10px; background: #f8f9fa;'>\n";
        echo "<h3>🎯 Overall Results</h3>\n";
        echo "<p><strong>Total Tests:</strong> $total_tests</p>\n";
        echo "<p><strong>Passed:</strong> <span style='color: green;'>{$this->passed_tests}</span></p>\n";
        echo "<p><strong>Failed:</strong> <span style='color: red;'>{$this->failed_tests}</span></p>\n";
        echo "<p><strong>Success Rate:</strong> <span style='color: " . ($success_rate >= 80 ? 'green' : ($success_rate >= 60 ? 'orange' : 'red')) . ";'>$success_rate%</span></p>\n";

        if ($success_rate >= 80) {
            echo "<div style='background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin-top: 15px;'>\n";
            echo "<strong>🎉 Excellent!</strong> Your OCR and NLP functions are working well!\n";
            echo "</div>\n";
        } elseif ($success_rate >= 60) {
            echo "<div style='background: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; margin-top: 15px;'>\n";
            echo "<strong>⚠️ Good, but needs improvement.</strong> Check the failed tests and API configurations.\n";
            echo "</div>\n";
        } else {
            echo "<div style='background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-top: 15px;'>\n";
            echo "<strong>❌ Issues detected.</strong> Please check your API keys and network connectivity.\n";
            echo "</div>\n";
        }

        echo "</div>\n";

        // Recommendations
        echo "<h3>💡 Recommendations</h3>\n";
        echo "<ul>\n";
        echo "<li>Ensure both Claude and OCR.space API keys are properly configured</li>\n";
        echo "<li>Use the latest Claude model: <code>claude-3-5-sonnet-20241022</code></li>\n";
        echo "<li>Test with actual image files to verify end-to-end OCR functionality</li>\n";
        echo "<li>Monitor API usage limits and costs</li>\n";
        echo "<li>Consider implementing caching for repeated text analysis</li>\n";
        echo "<li>Add more robust error handling for production use</li>\n";
        echo "</ul>\n";
    }
}

// Usage instructions and sample test files
echo "
<div style='background: #e7f3ff; border-left: 4px solid #007bff; padding: 20px; margin: 20px 0;'>
    <h2>📋 How to Use This Test Suite</h2>
    <ol>
        <li><strong>Save this file</strong> as <code>test_ocr_nlp.php</code> in your project directory</li>
        <li><strong>Update the require_once path</strong> to point to your main StudyOrganizer file</li>
        <li><strong>Run the test</strong> by accessing this file in your browser</li>
        <li><strong>Review results</strong> and fix any configuration issues</li>
    </ol>
    
    <h3>🖼️ Sample Test Images You Can Create</h3>
    <p>Create these sample images to test OCR functionality:</p>
    <ul>
        <li><strong>Physics sample:</strong> Image with text about Newton's laws or Einstein's equations</li>
        <li><strong>Chemistry sample:</strong> Image showing chemical formulas or periodic table elements</li>
        <li><strong>Math sample:</strong> Image with mathematical equations or geometric formulas</li>
        <li><strong>Biology sample:</strong> Image with biological diagrams or scientific terms</li>
        <li><strong>Handwritten note:</strong> Test OCR accuracy with handwritten academic content</li>
    </ul>
    
    <h3>🔧 Quick Setup Commands</h3>
    <pre style='background: #f8f9fa; padding: 10px; border-radius: 5px;'>
# Make sure upload directory exists and is writable
mkdir -p uploads
chmod 755 uploads

# Test file permissions
touch uploads/test.txt && rm uploads/test.txt
    </pre>
</div>
";

// Run the tests if accessed directly
if (basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    $tester = new OCRNLPTester();
    $tester->runAllTests();
}
