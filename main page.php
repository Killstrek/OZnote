<?php
require_once 'auth.php';

// If user is already logged in, redirect to dashboard
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StudyOrganizer - AI-Powered Document Organization</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        // Configure Tailwind for dark mode
        tailwind.config = {
            darkMode: 'media', // Automatically follows system preference
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#f0f9ff',
                            100: '#e0f2fe',
                            200: '#bae6fd',
                            300: '#7dd3fc',
                            400: '#38bdf8',
                            500: '#0ea5e9',
                            600: '#0284c7',
                            700: '#0369a1',
                            800: '#075985',
                            900: '#0c4a6e',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        /* Custom animations */
        .animate-float {
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {

            0%,
            100% {
                transform: translateY(0px);
            }

            50% {
                transform: translateY(-20px);
            }
        }

        .animate-pulse-slow {
            animation: pulse 3s ease-in-out infinite;
        }

        /* Smooth transitions */
        * {
            transition: background-color 0.3s ease, border-color 0.3s ease, color 0.3s ease;
        }

        /* Custom gradient backgrounds */
        .gradient-bg {
            /* Light mode: Light gradient background */
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 50%, #f1f5f9 100%);
        }

        /* Dark mode gradient */
        @media (prefers-color-scheme: dark) {
            .gradient-bg {
                /* Dark mode: Dark gradient background */
                background: linear-gradient(135deg, #1e293b 0%, #334155 50%, #475569 100%);
            }
        }

        /* Hero background pattern */
        .hero-pattern {
            background-image:
                radial-gradient(circle at 25% 25%, rgba(255, 255, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 75% 75%, rgba(255, 255, 255, 0.1) 0%, transparent 50%);
        }

        /* Card hover effects */
        .card-hover {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .card-hover:hover {
            transform: translateY(-8px) scale(1.02);
        }

        /* Responsive text sizing */
        @media (max-width: 640px) {
            .hero-title {
                font-size: 2.5rem;
                line-height: 1.1;
            }
        }
    </style>
</head>

<body class="bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-gray-100 transition-colors duration-300">
    <!-- Header -->
    <header class="gradient-bg hero-pattern">
        <nav class="container mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <!-- Logo -->
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-white/90 dark:bg-gray-800/90 backdrop-blur-sm rounded-xl flex items-center justify-center shadow-lg">
                        <span class="text-2xl">📚</span>
                    </div>
                    <span class="text-gray-900 dark:text-white text-xl font-bold tracking-wide">StudyOrganizer</span>
                </div>

                <!-- Navigation -->
                <div class="flex items-center space-x-4">
                    <a href="login.php" class="text-gray-700 dark:text-white/90 hover:text-gray-900 dark:hover:text-white transition-colors font-medium">
                        Login
                    </a>
                    <a href="login.php" class="bg-primary-600 hover:bg-primary-700 text-white px-6 py-2 rounded-lg font-semibold transition-all shadow-lg hover:shadow-xl">
                        Get Started
                    </a>
                </div>
            </div>
        </nav>

        <!-- Hero Section -->
        <div class="container mx-auto px-6 py-20 text-center">
            <div class="max-w-5xl mx-auto">
                <h1 class="hero-title text-4xl md:text-6xl font-bold text-gray-900 dark:text-white mb-6 leading-tight">
                    Organize Your Study Documents with
                    <span class="text-primary-600 dark:text-yellow-300 animate-pulse-slow block md:inline">AI Power</span>
                </h1>
                <p class="text-lg md:text-xl text-gray-700 dark:text-white/90 mb-8 leading-relaxed max-w-3xl mx-auto">
                    Upload PDFs and images, get automatic subject categorization and smart summaries.
                    Never lose track of your study materials again.
                </p>

                <!-- CTA Buttons -->
                <div class="flex flex-col sm:flex-row gap-4 justify-center mb-16">
                    <a href="login.php" class="bg-primary-600 hover:bg-primary-700 text-white px-8 py-4 rounded-xl font-bold text-lg transition-all transform hover:scale-105 shadow-lg hover:shadow-xl">
                        🚀 Start Organizing Now
                    </a>
                    <a href="#features" class="bg-white/80 dark:bg-white/10 hover:bg-white dark:hover:bg-white/20 backdrop-blur-sm border-2 border-gray-300 dark:border-white/20 text-gray-900 dark:text-white px-8 py-4 rounded-xl font-bold text-lg transition-all hover:border-gray-400 dark:hover:border-white/40">
                        Learn More
                    </a>
                </div>
            </div>

            <!-- Floating Demo Card -->
            <div class="max-w-lg mx-auto">
                <div class="animate-float">
                    <div class="bg-white/90 dark:bg-white/10 backdrop-blur-md rounded-2xl p-6 border border-gray-200 dark:border-white/20 shadow-2xl">
                        <div class="flex items-center space-x-4 mb-4">
                            <div class="w-12 h-12 bg-primary-500 rounded-xl flex items-center justify-center shadow-lg">
                                <span class="text-2xl text-white">🤖</span>
                            </div>
                            <div class="text-left">
                                <div class="text-gray-900 dark:text-white font-semibold">AI Analysis Complete</div>
                                <div class="text-gray-600 dark:text-white/70 text-sm">Physics_Chapter_5.pdf categorized</div>
                            </div>
                        </div>
                        <div class="bg-gray-100 dark:bg-white/10 backdrop-blur-sm rounded-lg p-4 border border-gray-200 dark:border-white/10">
                            <div class="text-gray-700 dark:text-white/90 text-sm">
                                "This document covers electromagnetic waves, including frequency, wavelength, and the electromagnetic spectrum..."
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Features Section -->
    <section id="features" class="py-20 bg-white dark:bg-gray-900">
        <div class="container mx-auto px-6">
            <div class="text-center mb-16">
                <h2 class="text-4xl font-bold text-gray-900 dark:text-white mb-4">
                    Why Choose StudyOrganizer?
                </h2>
                <p class="text-xl text-gray-600 dark:text-gray-300 max-w-2xl mx-auto">
                    Powered by advanced AI to make your study life easier and more organized
                </p>
            </div>

            <div class="grid md:grid-cols-3 gap-8">
                <!-- Feature 1: Smart Categorization -->
                <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl p-8 text-center shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="w-16 h-16 bg-primary-500 rounded-2xl flex items-center justify-center mx-auto mb-6 shadow-lg">
                        <span class="text-3xl text-white">🧠</span>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-900 dark:text-white mb-4">Smart Categorization</h3>
                    <p class="text-gray-600 dark:text-gray-300 mb-6">
                        AI automatically sorts your documents into Physics, Biology, Chemistry, Mathematics, and more.
                    </p>
                    <div class="bg-primary-50 dark:bg-gray-700 rounded-lg p-4">
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-primary-600 dark:text-primary-400 font-semibold">Physics</span>
                            <span class="bg-primary-100 dark:bg-primary-900/50 text-primary-600 dark:text-primary-400 px-3 py-1 rounded-full">23 files</span>
                        </div>
                    </div>
                </div>

                <!-- Feature 2: OCR & Text Extraction -->
                <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl p-8 text-center shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="w-16 h-16 bg-green-500 rounded-2xl flex items-center justify-center mx-auto mb-6 shadow-lg">
                        <span class="text-3xl text-white">📄</span>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-900 dark:text-white mb-4">OCR & Text Extraction</h3>
                    <p class="text-gray-600 dark:text-gray-300 mb-6">
                        Upload PDFs, images, and handwritten notes. We extract and analyze all text content.
                    </p>
                    <div class="bg-green-50 dark:bg-gray-700 rounded-lg p-4">
                        <div class="text-sm text-gray-600 dark:text-gray-300 space-y-2">
                            <div class="flex items-center">
                                <span class="w-2 h-2 bg-green-400 rounded-full mr-3"></span>
                                PDF Text: ✓ Extracted
                            </div>
                            <div class="flex items-center">
                                <span class="w-2 h-2 bg-green-400 rounded-full mr-3"></span>
                                Image OCR: ✓ Processed
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Feature 3: AI Summaries -->
                <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl p-8 text-center shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="w-16 h-16 bg-purple-500 rounded-2xl flex items-center justify-center mx-auto mb-6 shadow-lg">
                        <span class="text-3xl text-white">✨</span>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-900 dark:text-white mb-4">AI Summaries</h3>
                    <p class="text-gray-600 dark:text-gray-300 mb-6">
                        Get instant, intelligent summaries of your documents to quickly understand key concepts.
                    </p>
                    <div class="bg-purple-50 dark:bg-gray-700 rounded-lg p-4 text-left">
                        <div class="text-sm text-gray-600 dark:text-gray-300">
                            <div class="font-semibold mb-2 text-purple-600 dark:text-purple-400">Quick Summary:</div>
                            <div>"This covers calculus derivatives including power rule, chain rule..."</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works -->
    <section class="py-20 bg-gray-50 dark:bg-gray-800">
        <div class="container mx-auto px-6">
            <div class="text-center mb-16">
                <h2 class="text-4xl font-bold text-gray-900 dark:text-white mb-4">How It Works</h2>
                <p class="text-xl text-gray-600 dark:text-gray-300">Simple steps to organized studying</p>
            </div>

            <div class="grid md:grid-cols-4 gap-8">
                <div class="text-center group">
                    <div class="w-20 h-20 bg-primary-500 rounded-full flex items-center justify-center mx-auto mb-4 shadow-lg group-hover:scale-110 transition-transform">
                        <span class="text-3xl text-white">📤</span>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">1. Upload</h3>
                    <p class="text-gray-600 dark:text-gray-300">Drag and drop your PDFs, images, or scan documents</p>
                </div>

                <div class="text-center group">
                    <div class="w-20 h-20 bg-green-500 rounded-full flex items-center justify-center mx-auto mb-4 shadow-lg group-hover:scale-110 transition-transform">
                        <span class="text-3xl text-white">🔍</span>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">2. AI Analysis</h3>
                    <p class="text-gray-600 dark:text-gray-300">Our AI reads and understands your document content</p>
                </div>

                <div class="text-center group">
                    <div class="w-20 h-20 bg-yellow-500 rounded-full flex items-center justify-center mx-auto mb-4 shadow-lg group-hover:scale-110 transition-transform">
                        <span class="text-3xl text-white">📊</span>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">3. Categorize</h3>
                    <p class="text-gray-600 dark:text-gray-300">Documents are automatically sorted by subject</p>
                </div>

                <div class="text-center group">
                    <div class="w-20 h-20 bg-purple-500 rounded-full flex items-center justify-center mx-auto mb-4 shadow-lg group-hover:scale-110 transition-transform">
                        <span class="text-3xl text-white">🎯</span>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">4. Organize</h3>
                    <p class="text-gray-600 dark:text-gray-300">Access organized summaries and search your content</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="py-20 bg-white dark:bg-gray-900">
        <div class="container mx-auto px-6">
            <div class="text-center mb-12">
                <h2 class="text-3xl font-bold text-gray-900 dark:text-white mb-4">Trusted by Students Worldwide</h2>
            </div>
            <div class="grid md:grid-cols-3 gap-8 max-w-4xl mx-auto">
                <div class="text-center">
                    <div class="text-4xl font-bold text-primary-600 dark:text-primary-400 mb-2">10,000+</div>
                    <div class="text-gray-600 dark:text-gray-300 font-medium">Documents Processed</div>
                </div>
                <div class="text-center">
                    <div class="text-4xl font-bold text-primary-600 dark:text-primary-400 mb-2">95%</div>
                    <div class="text-gray-600 dark:text-gray-300 font-medium">Accuracy Rate</div>
                </div>
                <div class="text-center">
                    <div class="text-4xl font-bold text-primary-600 dark:text-primary-400 mb-2">24/7</div>
                    <div class="text-gray-600 dark:text-gray-300 font-medium">AI Processing</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Call to Action -->
    <section class="gradient-bg py-20">
        <div class="container mx-auto px-6 text-center">
            <h2 class="text-4xl font-bold text-gray-900 dark:text-white mb-6">Ready to Transform Your Study Habits?</h2>
            <p class="text-xl text-gray-700 dark:text-white/90 mb-8 max-w-2xl mx-auto">
                Join thousands of students who've revolutionized their document organization with AI
            </p>
            <a href="login.php" class="inline-block bg-primary-600 hover:bg-primary-700 text-white px-8 py-4 rounded-xl font-bold text-lg transition-all transform hover:scale-105 shadow-lg hover:shadow-xl">
                🚀 Start Free Today
            </a>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-gray-900 dark:bg-black text-white py-12">
        <div class="container mx-auto px-6">
            <div class="grid md:grid-cols-4 gap-8">
                <div>
                    <div class="flex items-center space-x-3 mb-4">
                        <div class="w-8 h-8 bg-primary-600 rounded-lg flex items-center justify-center">
                            <span class="text-lg">📚</span>
                        </div>
                        <span class="text-lg font-bold">StudyOrganizer</span>
                    </div>
                    <p class="text-gray-400">
                        AI-powered document organization for smarter studying.
                    </p>
                </div>

                <div>
                    <h4 class="font-bold mb-4 text-white">Features</h4>
                    <ul class="space-y-2 text-gray-400">
                        <li class="hover:text-white transition-colors cursor-pointer">AI Categorization</li>
                        <li class="hover:text-white transition-colors cursor-pointer">OCR Processing</li>
                        <li class="hover:text-white transition-colors cursor-pointer">Smart Summaries</li>
                        <li class="hover:text-white transition-colors cursor-pointer">Search & Filter</li>
                    </ul>
                </div>

                <div>
                    <h4 class="font-bold mb-4 text-white">Support</h4>
                    <ul class="space-y-2 text-gray-400">
                        <li class="hover:text-white transition-colors cursor-pointer">Help Center</li>
                        <li class="hover:text-white transition-colors cursor-pointer">Documentation</li>
                        <li class="hover:text-white transition-colors cursor-pointer">Contact Us</li>
                        <li class="hover:text-white transition-colors cursor-pointer">Status</li>
                    </ul>
                </div>

                <div>
                    <h4 class="font-bold mb-4 text-white">Get Started</h4>
                    <a href="login.php" class="bg-primary-600 hover:bg-primary-700 text-white px-6 py-3 rounded-lg font-semibold transition-colors inline-block shadow-lg hover:shadow-xl">
                        Sign Up Now
                    </a>
                </div>
            </div>

            <div class="border-t border-gray-800 mt-8 pt-8 text-center text-gray-400">
                <p>&copy; 2025 StudyOrganizer. All rights reserved. Made with ❤️ for students.</p>
            </div>
        </div>
    </footer>

    <script>
        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Enhanced scroll effect for header
        window.addEventListener('scroll', function() {
            const nav = document.querySelector('header nav');
            if (window.scrollY > 50) {
                nav.classList.add('bg-black', 'bg-opacity-20', 'backdrop-blur-md');
            } else {
                nav.classList.remove('bg-black', 'bg-opacity-20', 'backdrop-blur-md');
            }
        });

        // Add intersection observer for animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('animate-fade-in-up');
                }
            });
        }, observerOptions);

        // Observe all sections for animations
        document.querySelectorAll('section').forEach(section => {
            observer.observe(section);
        });

        // Theme detection logging (for debugging)
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            console.log('Dark mode detected');
        } else {
            console.log('Light mode detected');
        }

        // Listen for theme changes
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', event => {
            console.log('Theme changed to:', event.matches ? 'dark' : 'light');
        });
    </script>
</body>

</html>
