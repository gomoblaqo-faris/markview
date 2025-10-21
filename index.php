<?php
/**
 * MarkView - Standalone PHP Markdown Viewer
 * A simple, secure markdown file viewer with link navigation
 */

// Check if this is a search API request
if (isset($_GET['search']) && isset($_GET['q'])) {
    handleSearchAPI();
    exit;
}

// Check if this is a file content API request
if (isset($_GET['api']) && $_GET['api'] === 'content' && isset($_GET['file'])) {
    handleFileContentAPI();
    exit;
}

// Security: Prevent directory traversal attacks
function sanitizePath($path) {
    // Remove any directory traversal attempts
    $path = str_replace(['../', '..\\'], '', $path);
    // Remove leading slashes
    $path = ltrim($path, '/\\');
    return $path;
}

// Highlight search term in text
function highlightMatch($text, $query) {
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $pattern = '/(' . preg_quote($query, '/') . ')/i';
    return preg_replace($pattern, '<mark>$1</mark>', $text);
}

// Handle file content API requests
function handleFileContentAPI() {
    header('Content-Type: application/json');

    $file = isset($_GET['file']) ? sanitizePath($_GET['file']) : '';
    $baseDir = __DIR__;
    $filePath = $baseDir . '/' . $file;

    // Verify file exists and is valid
    $realBase = realpath($baseDir);

    if (is_link($filePath)) {
        $target = readlink($filePath);
        if ($target === false) {
            echo json_encode(['success' => false, 'error' => 'Invalid symlink']);
            return;
        }
        if ($target[0] !== '/') {
            $target = dirname($filePath) . '/' . $target;
        }
        $filePath = $target;
    }

    $realPath = realpath($filePath);

    if ($realPath === false || strpos($realPath, $realBase) !== 0 || pathinfo($realPath, PATHINFO_EXTENSION) !== 'md') {
        echo json_encode(['success' => false, 'error' => 'File not found or invalid']);
        return;
    }

    // Read and parse markdown
    $markdown = file_get_contents($realPath);
    $html = parseMarkdown($markdown);

    echo json_encode([
        'success' => true,
        'file' => $file,
        'html' => $html
    ]);
}

// Handle search API requests
function handleSearchAPI() {
    header('Content-Type: application/json');

    // Get search query
    $query = isset($_GET['q']) ? trim($_GET['q']) : '';

    if (empty($query) || strlen($query) < 2) {
        echo json_encode([
            'success' => false,
            'error' => 'Search query must be at least 2 characters',
            'results' => []
        ]);
        return;
    }

    // Define base directory
    $baseDir = __DIR__;

    // Get all markdown files
    $allFiles = scanMarkdownFiles($baseDir);
    $results = [];

    // Search through files
    foreach ($allFiles as $file) {
        $filePath = $baseDir . '/' . $file;

        // Skip if file doesn't exist or is not readable
        if (!file_exists($filePath) || !is_readable($filePath)) {
            continue;
        }

        // Handle symlinks
        if (is_link($filePath)) {
            $target = readlink($filePath);
            if ($target === false) {
                continue;
            }
            if ($target[0] !== '/') {
                $target = dirname($filePath) . '/' . $target;
            }
            $filePath = $target;
        }

        // Read file content
        $content = file_get_contents($filePath);

        // Search case-insensitive
        $lowerContent = strtolower($content);
        $lowerQuery = strtolower($query);

        if (strpos($lowerContent, $lowerQuery) !== false) {
            // Find all matches
            $lines = explode("\n", $content);
            $matches = [];

            foreach ($lines as $lineNum => $line) {
                if (stripos($line, $query) !== false) {
                    // Get context (line with match)
                    $matches[] = [
                        'line' => $lineNum + 1,
                        'text' => trim($line),
                        'preview' => highlightMatch($line, $query)
                    ];

                    // Limit to 5 matches per file
                    if (count($matches) >= 5) {
                        break;
                    }
                }
            }

            if (!empty($matches)) {
                $results[] = [
                    'file' => $file,
                    'fileName' => basename($file),
                    'matchCount' => count($matches),
                    'matches' => $matches
                ];
            }
        }
    }

    // Sort results by match count (most matches first)
    usort($results, function($a, $b) {
        return $b['matchCount'] - $a['matchCount'];
    });

    // Return results
    echo json_encode([
        'success' => true,
        'query' => $query,
        'totalResults' => count($results),
        'results' => $results
    ], JSON_PRETTY_PRINT);
}

// Function to recursively scan directory for markdown files
function scanMarkdownFiles($dir, $baseDir = null, &$visited = []) {
    if ($baseDir === null) {
        $baseDir = $dir;
    }

    // Get real path to handle symlinks and prevent infinite loops
    $realDir = realpath($dir);
    if ($realDir === false || isset($visited[$realDir])) {
        return [];
    }
    $visited[$realDir] = true;

    $files = [];
    $items = scandir($dir);

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . '/' . $item;

        // Skip hidden files and directories (but allow symlinks to non-hidden targets)
        if (strpos($item, '.') === 0 && !is_link($path)) {
            continue;
        }

        // Follow symlinks
        if (is_link($path)) {
            $target = readlink($path);
            if ($target === false) {
                continue;
            }

            // If relative symlink, make it absolute
            if ($target[0] !== '/') {
                $target = $dir . '/' . $target;
            }

            // Get real path of the target
            $realTarget = realpath($target);
            $realBaseDir = realpath($baseDir);

            // Check if symlink points to a directory or file
            if (is_dir($target)) {
                // Only scan symlinked directories if they're within or point to baseDir subdirectories
                // For symlinked directories, use the symlink path as base for relative paths
                $symlinkBase = dirname($path);
                $subFiles = scanMarkdownFiles($target, $path, $visited);

                // Rewrite paths to be relative to original baseDir
                foreach ($subFiles as $subFile) {
                    $fullPath = str_replace($target, $path, $subFile);
                    $relativePath = str_replace($baseDir . '/', '', $symlinkBase . '/' . $fullPath);
                    $files[] = $item . '/' . $subFile;
                }
            } elseif (pathinfo($target, PATHINFO_EXTENSION) === 'md') {
                // Add symlinked markdown file
                $relativePath = str_replace($baseDir . '/', '', $path);
                $files[] = $relativePath;
            }
        } elseif (is_dir($path)) {
            // Recursively scan subdirectories
            $subFiles = scanMarkdownFiles($path, $baseDir, $visited);
            $files = array_merge($files, $subFiles);
        } elseif (pathinfo($path, PATHINFO_EXTENSION) === 'md') {
            // Get relative path from base directory
            $relativePath = str_replace($baseDir . '/', '', $path);
            $files[] = $relativePath;
        }
    }

    sort($files);
    return $files;
}

// Get the requested file from URL parameter
$file = isset($_GET['file']) ? sanitizePath($_GET['file']) : 'README.md';

// Define base directory for markdown files (current directory)
$baseDir = __DIR__;
$filePath = $baseDir . '/' . $file;

// Get all markdown files for the file browser
$allMarkdownFiles = scanMarkdownFiles($baseDir);

// Verify file exists and check if it's a symlink or regular file
$realBase = realpath($baseDir);

// Handle both symlinks and regular files
if (is_link($filePath)) {
    // For symlinks, resolve the target
    $target = readlink($filePath);
    if ($target === false) {
        $error = "Invalid symlink";
        $content = "# Error\n\nThe symlink could not be resolved.";
    } else {
        // If relative symlink, make it absolute
        if ($target[0] !== '/') {
            $target = dirname($filePath) . '/' . $target;
        }
        $realPath = realpath($target);

        if (!$realPath || !file_exists($target)) {
            $error = "File not found";
            $content = "# File Not Found\n\nThe file `" . htmlspecialchars($file) . "` could not be found.";
        } elseif (pathinfo($target, PATHINFO_EXTENSION) !== 'md') {
            $error = "Invalid file type";
            $content = "# Error\n\nOnly markdown (.md) files are allowed.";
        } else {
            $content = file_get_contents($target);
            $error = null;
        }
    }
} else {
    // Regular file handling
    $realPath = realpath($filePath);

    if (!$realPath || strpos($realPath, $realBase) !== 0) {
        $error = "Invalid file path";
        $content = "# Error\n\nThe requested file path is invalid or not allowed.";
    } elseif (!file_exists($filePath)) {
        $error = "File not found";
        $content = "# File Not Found\n\nThe file `" . htmlspecialchars($file) . "` could not be found.";
    } elseif (pathinfo($filePath, PATHINFO_EXTENSION) !== 'md') {
        $error = "Invalid file type";
        $content = "# Error\n\nOnly markdown (.md) files are allowed.";
    } else {
        $content = file_get_contents($filePath);
        $error = null;
    }
}

// Simple markdown parser
function parseMarkdown($text) {
    // Extract mermaid diagrams BEFORE HTML escaping to preserve syntax
    $mermaidBlocks = [];
    $mermaidCounter = 0;
    $text = preg_replace_callback('/```mermaid\n(.*?)```/s', function($matches) use (&$mermaidBlocks, &$mermaidCounter) {
        $placeholder = "MERMAIDDIAGRAMPLACEHOLDER" . $mermaidCounter . "ENDPLACEHOLDER";
        $mermaidBlocks[$placeholder] = '<div class="mermaid">' . trim($matches[1]) . '</div>';
        $mermaidCounter++;
        return $placeholder;
    }, $text);

    // Extract code blocks BEFORE HTML escaping to preserve syntax
    $codeBlocks = [];
    $codeCounter = 0;
    $text = preg_replace_callback('/```(\w+)?\n(.*?)```/s', function($matches) use (&$codeBlocks, &$codeCounter) {
        $lang = !empty($matches[1]) ? $matches[1] : '';
        $code = $matches[2];

        // HTML escape the code content but preserve structure
        $code = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');

        $placeholder = "CODEBLOCKPLACEHOLDER" . $codeCounter . "ENDPLACEHOLDER";
        $langClass = $lang ? ' class="language-' . $lang . '"' : '';
        $codeBlocks[$placeholder] = '<pre><code' . $langClass . '>' . $code . '</code></pre>';
        $codeCounter++;
        return $placeholder;
    }, $text);

    // Escape HTML after extracting code blocks and mermaid diagrams
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    // Tables - process before other elements
    $text = preg_replace_callback('/^(\|.+\|)\n(\|[\s\-:|]+\|)\n((?:\|.+\|\n?)+)/m', function($matches) {
        $headerRow = trim($matches[1]);
        $alignRow = trim($matches[2]);
        $bodyRows = trim($matches[3]);

        // Parse header
        $headers = array_map('trim', explode('|', trim($headerRow, '|')));

        // Parse alignment
        $alignments = array_map(function($cell) {
            $cell = trim($cell);
            if (preg_match('/^:-+:$/', $cell)) return 'center';
            if (preg_match('/^-+:$/', $cell)) return 'right';
            return 'left';
        }, explode('|', trim($alignRow, '|')));

        // Build table
        $table = '<table>';

        // Header
        $table .= '<thead><tr>';
        foreach ($headers as $i => $header) {
            $align = isset($alignments[$i]) ? ' style="text-align:' . $alignments[$i] . '"' : '';
            $table .= '<th' . $align . '>' . $header . '</th>';
        }
        $table .= '</tr></thead>';

        // Body
        $table .= '<tbody>';
        $rows = explode("\n", $bodyRows);
        foreach ($rows as $row) {
            if (empty(trim($row))) continue;
            $cells = array_map('trim', explode('|', trim($row, '|')));
            $table .= '<tr>';
            foreach ($cells as $i => $cell) {
                $align = isset($alignments[$i]) ? ' style="text-align:' . $alignments[$i] . '"' : '';
                $table .= '<td' . $align . '>' . $cell . '</td>';
            }
            $table .= '</tr>';
        }
        $table .= '</tbody></table>';

        return $table;
    }, $text);

    // Headers
    $text = preg_replace('/^######\s+(.+)$/m', '<h6>$1</h6>', $text);
    $text = preg_replace('/^#####\s+(.+)$/m', '<h5>$1</h5>', $text);
    $text = preg_replace('/^####\s+(.+)$/m', '<h4>$1</h4>', $text);
    $text = preg_replace('/^###\s+(.+)$/m', '<h3>$1</h3>', $text);
    $text = preg_replace('/^##\s+(.+)$/m', '<h2>$1</h2>', $text);
    $text = preg_replace('/^#\s+(.+)$/m', '<h1>$1</h1>', $text);

    // Bold and italic
    $text = preg_replace('/\*\*\*(.+?)\*\*\*/s', '<strong><em>$1</em></strong>', $text);
    $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);
    $text = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $text);
    $text = preg_replace('/___(.+?)___/s', '<strong><em>$1</em></strong>', $text);
    $text = preg_replace('/__(.+?)__/s', '<strong>$1</strong>', $text);
    $text = preg_replace('/_(.+?)_/s', '<em>$1</em>', $text);

    // Inline code
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);

    // Links - handle both relative .md files and external URLs
    $text = preg_replace_callback('/\[([^\]]+)\]\(([^\)]+)\)/', function($matches) {
        $linkText = $matches[1];
        $linkUrl = $matches[2];

        // Check if it's a markdown file link
        if (preg_match('/\.md$/i', $linkUrl)) {
            return '<a href="?file=' . urlencode($linkUrl) . '">' . $linkText . '</a>';
        } else {
            return '<a href="' . $linkUrl . '" target="_blank" rel="noopener">' . $linkText . '</a>';
        }
    }, $text);

    // Images
    $text = preg_replace('/!\[([^\]]*)\]\(([^\)]+)\)/', '<img src="$2" alt="$1" />', $text);

    // Horizontal rules
    $text = preg_replace('/^(\*\*\*|---|___)$/m', '<hr>', $text);

    // Blockquotes
    $text = preg_replace('/^&gt;\s+(.+)$/m', '<blockquote>$1</blockquote>', $text);

    // Unordered lists
    $text = preg_replace_callback('/((?:^[\*\-\+]\s+.+$\n?)+)/m', function($matches) {
        $items = preg_replace('/^[\*\-\+]\s+(.+)$/m', '<li>$1</li>', $matches[1]);
        return '<ul>' . $items . '</ul>';
    }, $text);

    // Ordered lists
    $text = preg_replace_callback('/((?:^\d+\.\s+.+$\n?)+)/m', function($matches) {
        $items = preg_replace('/^\d+\.\s+(.+)$/m', '<li>$1</li>', $matches[1]);
        return '<ol>' . $items . '</ol>';
    }, $text);

    // Line breaks and paragraphs
    $text = preg_replace('/\n\n+/', '</p><p>', $text);
    $text = '<p>' . $text . '</p>';

    // Clean up empty paragraphs
    $text = preg_replace('/<p><\/p>/', '', $text);
    $text = preg_replace('/<p>(<h[1-6]>)/', '$1', $text);
    $text = preg_replace('/(<\/h[1-6]>)<\/p>/', '$1', $text);
    $text = preg_replace('/<p>(<ul>|<ol>|<pre>|<blockquote>|<hr>|<table>)/', '$1', $text);
    $text = preg_replace('/(<\/ul>|<\/ol>|<\/pre>|<\/blockquote>|<hr>|<\/table>)<\/p>/', '$1', $text);

    // Restore code blocks (replace placeholders with actual code HTML)
    foreach ($codeBlocks as $placeholder => $codeHtml) {
        $text = str_replace($placeholder, $codeHtml, $text);
    }

    // Restore mermaid blocks (replace placeholders with actual mermaid HTML)
    foreach ($mermaidBlocks as $placeholder => $mermaidHtml) {
        $text = str_replace($placeholder, $mermaidHtml, $text);
    }

    // Clean up code and mermaid paragraphs
    $text = preg_replace('/<p>(<pre>)/', '$1', $text);
    $text = preg_replace('/(<\/pre>)<\/p>/', '$1', $text);
    $text = preg_replace('/<p>(<div class="mermaid">)/', '$1', $text);
    $text = preg_replace('/(<\/div>)<\/p>/', '$1', $text);

    return $text;
}

$htmlContent = parseMarkdown($content);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MarkView - <?php echo htmlspecialchars($file); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/php.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/bash.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/python.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.min.js"></script>
    <style type="text/tailwindcss">
        @layer components {
            .prose h1 {
                @apply text-3xl font-bold my-6 pb-2 border-b border-gray-200;
            }
            .prose h2 {
                @apply text-2xl font-bold my-6 pb-2 border-b border-gray-200;
            }
            .prose h3 {
                @apply text-xl font-bold my-4;
            }
            .prose h4, .prose h5, .prose h6 {
                @apply text-lg font-semibold my-4;
            }
            .prose p {
                @apply my-4 leading-7;
            }
            .prose a {
                @apply text-blue-600 hover:underline;
            }
            .prose code {
                @apply px-1.5 py-0.5 bg-gray-100 rounded text-sm font-mono text-red-600;
            }
            .prose pre {
                @apply p-0 rounded-lg overflow-x-auto my-4;
                background: #0d1117 !important;
            }
            .prose pre code {
                @apply p-4 block bg-transparent text-sm;
                background: transparent !important;
            }
            .prose blockquote {
                @apply pl-4 my-4 border-l-4 border-gray-300 text-gray-600;
            }
            .prose ul, .prose ol {
                @apply my-4 pl-8;
            }
            .prose ul {
                @apply list-disc;
            }
            .prose ol {
                @apply list-decimal;
            }
            .prose li {
                @apply my-1;
            }
            .prose hr {
                @apply my-6 border-t-2 border-gray-200;
            }
            .prose img {
                @apply max-w-full h-auto my-4 rounded;
            }
            .prose table {
                @apply w-full my-6 border-collapse;
            }
            .prose table thead {
                @apply bg-gray-100;
            }
            .prose table th {
                @apply px-4 py-3 text-left font-semibold text-gray-700 border border-gray-300;
            }
            .prose table td {
                @apply px-4 py-3 border border-gray-300;
            }
            .prose table tbody tr:nth-child(even) {
                @apply bg-gray-50;
            }
            .prose table tbody tr:hover {
                @apply bg-blue-50;
            }
            .prose .mermaid {
                @apply my-6 p-4 bg-white rounded-lg border border-gray-200;
                display: flex;
                justify-content: center;
                align-items: center;
            }
        }

        /* Content search highlighting */
        .search-highlight {
            background-color: #fef08a !important;
            padding: 2px 0;
            border-radius: 2px;
        }

        .search-highlight-current {
            background-color: #fb923c !important;
            padding: 2px 0;
            border-radius: 2px;
            box-shadow: 0 0 0 2px #f97316;
        }

        /* Global search result highlighting */
        mark {
            background-color: #fef08a;
            padding: 1px 2px;
            border-radius: 2px;
            font-weight: 500;
        }

        /* Folder collapse */
        .folder-content {
            max-height: 2000px;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }

        .folder-content.collapsed {
            max-height: 0;
        }

        .folder-icon {
            transition: transform 0.3s ease;
            transform: rotate(90deg);
        }

        /* Dark mode styles */
        .dark {
            background-color: #1a1a1a;
            color: #e5e5e5;
        }

        .dark aside {
            background-color: #262626;
            border-color: #404040;
        }

        .dark .prose {
            color: #e5e5e5;
        }

        .dark .prose h1, .dark .prose h2, .dark .prose h3,
        .dark .prose h4, .dark .prose h5, .dark .prose h6 {
            color: #f5f5f5;
        }

        .dark .prose a {
            color: #60a5fa;
        }

        .dark .prose a:hover {
            color: #93c5fd;
        }

        .dark .prose code {
            background-color: #374151;
            color: #e5e5e5;
        }

        .dark .prose pre {
            background-color: #1f2937;
        }

        .dark .prose table th {
            background-color: #374151;
            color: #f5f5f5;
            border-color: #4b5563;
        }

        .dark .prose table td {
            border-color: #4b5563;
        }

        .dark .prose table tbody tr:nth-child(even) {
            background-color: #262626;
        }

        .dark .prose table tbody tr:hover {
            background-color: #374151;
        }

        .dark .prose blockquote {
            border-color: #4b5563;
            color: #d1d5db;
        }

        .dark .prose .mermaid {
            background-color: #262626;
            border-color: #404040;
        }

        .dark .file-item:hover {
            background-color: #374151 !important;
        }

        .dark .file-dir-header {
            color: #9ca3af;
        }

        .dark .file-dir-header:hover {
            background-color: #374151;
        }

        .dark input {
            background-color: #374151;
            border-color: #4b5563;
            color: #e5e5e5;
        }

        .dark input::placeholder {
            color: #9ca3af;
        }

        .dark button {
            color: #e5e5e5;
        }

        .dark mark {
            background-color: #854d0e;
            color: #fef08a;
        }

        .dark .toc-container {
            background-color: #1e3a5f;
            border-color: #2563eb;
        }

        .dark .toc-container .font-semibold {
            color: #93c5fd;
        }

        .dark .toc-container a {
            color: #60a5fa;
        }

        .dark .toc-container a:hover {
            color: #93c5fd;
        }

        .dark .bg-white {
            background-color: #262626 !important;
        }

        .dark .border-gray-200 {
            border-color: #404040 !important;
        }

        .dark .shadow-md {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.3), 0 2px 4px -1px rgba(0, 0, 0, 0.2);
        }

        /* Copy button styles */
        .copy-button {
            z-index: 10;
        }

        .dark .copy-button {
            background-color: #374151;
        }

        .dark .copy-button:hover {
            background-color: #4b5563;
        }

        /* Dark mode text colors */
        .dark .text-gray-800 {
            color: #f5f5f5 !important;
        }

        .dark .text-gray-700 {
            color: #e5e5e5 !important;
        }

        .dark .text-gray-600 {
            color: #d1d5db !important;
        }

        .dark .text-gray-500 {
            color: #9ca3af !important;
        }

        .dark .text-gray-400 {
            color: #6b7280 !important;
        }

        .dark .bg-blue-50 {
            background-color: #1e3a5f !important;
        }

        .dark .bg-blue-100 {
            background-color: #1e40af !important;
        }

        .dark .text-blue-700 {
            color: #93c5fd !important;
        }

        .dark .text-blue-600 {
            color: #60a5fa !important;
        }

        .dark .hover\:bg-gray-100:hover {
            background-color: #374151 !important;
        }

        .dark .hover\:bg-blue-50:hover {
            background-color: #1e3a5f !important;
        }

        .dark .border-gray-100 {
            border-color: #374151 !important;
        }

        .dark .bg-gray-50 {
            background-color: #1a1a1a !important;
        }

        .dark .text-red-500 {
            color: #f87171 !important;
        }

        .dark .text-red-700 {
            color: #ef4444 !important;
        }

        /* Dark mode for navigation controls */
        .dark select {
            background-color: #374151;
            border-color: #4b5563;
            color: #e5e5e5;
        }

        .dark #jumpToTop {
            background-color: #2563eb;
        }

        .dark #jumpToTop:hover {
            background-color: #1d4ed8;
        }

        /* Loading skeleton */
        @keyframes shimmer {
            0% { background-position: -1000px 0; }
            100% { background-position: 1000px 0; }
        }

        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 1000px 100%;
            animation: shimmer 2s infinite;
        }

        .dark .skeleton {
            background: linear-gradient(90deg, #2a2a2a 25%, #3a3a3a 50%, #2a2a2a 75%);
            background-size: 1000px 100%;
        }

        /* Fade transitions */
        .prose {
            animation: fadeIn 0.3s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Bookmark button styles */
        .bookmark-btn.bookmarked svg {
            fill: #eab308;
            stroke: #eab308;
        }

        .dark .bookmark-btn:hover {
            background-color: #404040;
        }

        /* Theme Styles */
        .theme-sepia {
            background-color: #f4f1ea !important;
            color: #5c4a2f !important;
        }

        .theme-sepia aside,
        .theme-sepia header {
            background-color: #ebe6d8 !important;
            border-color: #d4c9b3 !important;
        }

        .theme-sepia .prose {
            color: #5c4a2f !important;
        }

        .theme-sepia .text-gray-700 {
            color: #7a6747 !important;
        }

        .theme-sepia .text-gray-600 {
            color: #8a7757 !important;
        }

        .theme-sepia .bg-white {
            background-color: #ebe6d8 !important;
        }

        .theme-sepia .bg-gray-50 {
            background-color: #f4f1ea !important;
        }

        .theme-sepia .hover\:bg-gray-100:hover {
            background-color: #e6dfc9 !important;
        }

        .theme-sepia .bg-blue-50 {
            background-color: #ddd0b8 !important;
        }

        .theme-sepia .text-blue-700 {
            color: #8a6b3d !important;
        }

        .theme-forest {
            background-color: #f0f7f0 !important;
            color: #1e3a1e !important;
        }

        .theme-forest aside,
        .theme-forest header {
            background-color: #e6f2e6 !important;
            border-color: #c4d8c4 !important;
        }

        .theme-forest .prose {
            color: #1e3a1e !important;
        }

        .theme-forest .text-gray-700 {
            color: #2e4a2e !important;
        }

        .theme-forest .bg-blue-50 {
            background-color: #d4e6d4 !important;
        }

        .theme-forest .text-blue-700 {
            color: #2e5a2e !important;
        }

        .theme-ocean {
            background-color: #f0f8fc !important;
            color: #1a3a4a !important;
        }

        .theme-ocean aside,
        .theme-ocean header {
            background-color: #e6f4f9 !important;
            border-color: #c4dce6 !important;
        }

        .theme-ocean .prose {
            color: #1a3a4a !important;
        }

        .theme-ocean .text-gray-700 {
            color: #2a4a5a !important;
        }

        .theme-ocean .bg-blue-50 {
            background-color: #d4e8f0 !important;
        }

        .theme-ocean .text-blue-700 {
            color: #2a5a7a !important;
        }

        .theme-sunset {
            background-color: #fff5f0 !important;
            color: #4a2a1a !important;
        }

        .theme-sunset aside,
        .theme-sunset header {
            background-color: #ffeee6 !important;
            border-color: #e6c9b4 !important;
        }

        .theme-sunset .prose {
            color: #4a2a1a !important;
        }

        .theme-sunset .text-gray-700 {
            color: #5a3a2a !important;
        }

        .theme-sunset .bg-blue-50 {
            background-color: #f0d8c4 !important;
        }

        .theme-sunset .text-blue-700 {
            color: #8a5a3a !important;
        }

        /* Active theme indicator */
        .theme-option.active {
            background-color: #dbeafe;
            font-weight: 600;
        }

        .dark .theme-option.active {
            background-color: #1e3a5f;
        }

        /* Presentation Mode */
        #presentationOverlay {
            transition: opacity 0.3s ease;
        }

        #presentationSlide {
            transition: transform 0.3s ease;
        }

        #slideContent {
            font-size: 1.5rem;
            line-height: 1.8;
        }

        #slideContent h1 {
            font-size: 4rem;
            margin-bottom: 2rem;
            color: white;
        }

        #slideContent h2 {
            font-size: 3rem;
            margin-bottom: 1.5rem;
            color: white;
        }

        #slideContent h3 {
            font-size: 2rem;
            color: white;
        }

        #slideContent p,
        #slideContent li {
            color: #e5e5e5;
            font-size: 1.5rem;
        }

        #slideContent code {
            background: #2a2a2a;
            color: #60a5fa;
            padding: 0.2rem 0.5rem;
            border-radius: 0.25rem;
        }

        #slideContent pre {
            background: #1a1a1a;
            padding: 1.5rem;
            border-radius: 0.5rem;
            overflow-x: auto;
        }

        #slideContent pre code {
            background: none;
            color: #e5e5e5;
            font-size: 1.2rem;
        }

        #slideContent ul,
        #slideContent ol {
            margin-left: 2rem;
        }

        #slideContent img {
            max-height: 60vh;
            margin: 0 auto;
        }

        /* Line numbers for code */
        .show-line-numbers pre {
            position: relative;
            padding-left: 3.8em !important;
        }

        .show-line-numbers pre code {
            padding-left: 0 !important;
        }

        /* Line number display */
        .show-line-numbers pre[data-line-numbers]::before {
            content: attr(data-line-numbers);
            position: absolute;
            left: 0;
            top: 0;
            width: 3.5em;
            height: 100%;
            padding: 1em 0.5em 1em 0;
            text-align: right;
            color: #888;
            font-size: 0.875em;
            line-height: 1.45;
            white-space: pre;
            user-select: none;
            background: #f8f8f8;
            border-right: 1px solid #e0e0e0;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            z-index: 1;
        }

        .dark .show-line-numbers pre[data-line-numbers]::before {
            color: #6b7280;
            background: #1a1a1a;
            border-right-color: #374151;
        }

        /* Raw markdown view */
        #rawMarkdownView {
            display: none;
        }

        #rawMarkdownView.active {
            display: block;
        }

        .prose.hidden-for-raw {
            display: none;
        }

        /* Accessibility - Focus Indicators */
        *:focus {
            outline: 2px solid #3b82f6;
            outline-offset: 2px;
        }

        .dark *:focus {
            outline-color: #60a5fa;
        }

        /* Skip to content link */
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border-width: 0;
        }

        .focus\:not-sr-only:focus {
            position: static;
            width: auto;
            height: auto;
            padding: 0.5rem 1rem;
            margin: 0;
            overflow: visible;
            clip: auto;
            white-space: normal;
        }

        /* File list keyboard navigation */
        .file-item:focus,
        .file-item.keyboard-focus {
            outline: 2px solid #3b82f6;
            outline-offset: -2px;
            background-color: #dbeafe !important;
        }

        .dark .file-item:focus,
        .dark .file-item.keyboard-focus {
            outline-color: #60a5fa;
            background-color: #1e3a5f !important;
        }

        /* Better button focus states */
        button:focus-visible {
            outline: 2px solid #3b82f6;
            outline-offset: 2px;
        }

        .dark button:focus-visible {
            outline-color: #60a5fa;
        }

        /* High contrast mode support */
        @media (prefers-contrast: high) {
            * {
                border-width: 2px;
            }

            *:focus {
                outline-width: 3px;
            }
        }

        /* Print Styles */
        @media print {
            /* Hide non-content elements */
            aside,
            header,
            #sidebar,
            #contentSearchBar,
            #jumpToTop,
            .no-print,
            button {
                display: none !important;
            }

            /* Reset body and layout */
            body {
                background: white !important;
                color: black !important;
                margin: 0;
                padding: 0;
            }

            /* Make content full width */
            main {
                max-width: 100% !important;
                margin: 0 !important;
                padding: 1cm !important;
            }

            .prose {
                max-width: 100% !important;
                padding: 0 !important;
                animation: none !important;
            }

            /* Optimize typography for print */
            .prose {
                font-size: 11pt !important;
                line-height: 1.5 !important;
            }

            .prose h1 {
                font-size: 24pt !important;
                page-break-after: avoid;
                margin-top: 0;
            }

            .prose h2 {
                font-size: 18pt !important;
                page-break-after: avoid;
            }

            .prose h3 {
                font-size: 14pt !important;
                page-break-after: avoid;
            }

            .prose h4, .prose h5, .prose h6 {
                font-size: 12pt !important;
                page-break-after: avoid;
            }

            /* Code blocks */
            .prose pre {
                page-break-inside: avoid;
                border: 1px solid #ddd !important;
                background: #f5f5f5 !important;
                padding: 0.5cm !important;
                font-size: 9pt !important;
            }

            .prose code {
                background: #f0f0f0 !important;
                color: black !important;
                padding: 2px 4px !important;
            }

            /* Tables */
            .prose table {
                page-break-inside: avoid;
                border-collapse: collapse !important;
                width: 100% !important;
            }

            .prose table th,
            .prose table td {
                border: 1px solid #ddd !important;
                padding: 0.3cm !important;
            }

            .prose table th {
                background: #f0f0f0 !important;
            }

            /* Links */
            .prose a {
                color: black !important;
                text-decoration: underline !important;
            }

            .prose a[href]:after {
                content: " (" attr(href) ")";
                font-size: 9pt;
                font-style: italic;
            }

            /* Images */
            .prose img {
                max-width: 100% !important;
                page-break-inside: avoid;
            }

            /* Blockquotes */
            .prose blockquote {
                border-left: 3px solid #ddd !important;
                page-break-inside: avoid;
            }

            /* Lists */
            .prose ul,
            .prose ol {
                page-break-inside: avoid;
            }

            /* Page breaks */
            .prose h1,
            .prose h2 {
                page-break-before: auto;
            }

            /* Print header with filename */
            @page {
                margin: 1.5cm;
            }

            .prose::before {
                content: attr(data-filename);
                display: block;
                font-size: 10pt;
                color: #666;
                margin-bottom: 1cm;
                padding-bottom: 0.3cm;
                border-bottom: 1px solid #ddd;
            }
        }
    </style>
</head>
<body class="bg-gray-50 transition-colors duration-200">
    <!-- Skip to Content Link -->
    <a href="#mainContent" class="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:px-4 focus:py-2 focus:bg-blue-600 focus:text-white focus:rounded focus:shadow-lg">
        Skip to main content
    </a>

    <div class="flex min-h-screen">
        <!-- File Browser Sidebar -->
        <aside id="sidebar" class="w-64 bg-white border-r border-gray-200 flex-shrink-0 flex flex-col" role="complementary" aria-label="File navigation sidebar">
            <div class="p-4 border-b border-gray-200">
                <h2 class="text-lg font-bold text-gray-800 mb-2">📁 Files</h2>
                <button onclick="toggleSidebar()" class="md:hidden text-sm text-blue-600 hover:underline mb-2">
                    Close
                </button>

                <!-- Search Tabs -->
                <div class="flex gap-1 mb-2 mt-2" role="tablist" aria-label="Search modes">
                    <button onclick="switchSearchMode('files')" id="filesTabBtn" role="tab" aria-selected="true" aria-controls="fileSearchBox" class="flex-1 px-3 py-1 text-xs font-medium rounded transition-colors bg-blue-100 text-blue-700">
                        Files
                    </button>
                    <button onclick="switchSearchMode('content')" id="contentTabBtn" role="tab" aria-selected="false" aria-controls="globalSearchBox" class="flex-1 px-3 py-1 text-xs font-medium rounded transition-colors text-gray-600 hover:bg-gray-100">
                        Content
                    </button>
                    <button onclick="switchSearchMode('bookmarks')" id="bookmarksTabBtn" role="tab" aria-selected="false" aria-controls="bookmarksBox" class="flex-1 px-3 py-1 text-xs font-medium rounded transition-colors text-gray-600 hover:bg-gray-100">
                        Bookmarks
                    </button>
                    <button onclick="switchSearchMode('history')" id="historyTabBtn" role="tab" aria-selected="false" aria-controls="historyBox" class="flex-1 px-3 py-1 text-xs font-medium rounded transition-colors text-gray-600 hover:bg-gray-100">
                        History
                    </button>
                </div>

                <!-- File Name Search -->
                <div id="fileSearchBox" class="relative" role="tabpanel" aria-labelledby="filesTabBtn">
                    <label for="fileSearch" class="sr-only">Search files by name</label>
                    <input
                        type="text"
                        id="fileSearch"
                        placeholder="Search files..."
                        aria-label="Search files by name"
                        class="w-full px-3 py-2 pr-8 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        onkeyup="debounceFileSearch()"
                        onkeydown="handleFileListKeyNav(event)"
                    />
                    <button onclick="clearFileSearch()" id="clearFileSearchBtn" class="absolute right-8 top-2.5 text-gray-400 hover:text-gray-600 hidden">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                    <svg class="absolute right-3 top-2.5 h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    <div id="searchResults" class="text-xs text-gray-500 mt-1 hidden"></div>

                    <!-- File Controls -->
                    <div class="flex items-center justify-between mt-2 text-xs">
                        <div class="flex gap-1">
                            <button onclick="toggleAllFolders(true)" class="text-blue-600 hover:text-blue-800" title="Expand all folders">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </button>
                            <button onclick="toggleAllFolders(false)" class="text-blue-600 hover:text-blue-800" title="Collapse all folders">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>
                        </div>
                        <select onchange="sortFiles(this.value)" class="text-xs border border-gray-300 rounded px-2 py-1 bg-white">
                            <option value="name">Sort: Name</option>
                            <option value="name-desc">Sort: Name (Z-A)</option>
                            <option value="modified">Sort: Modified</option>
                        </select>
                    </div>

                    <!-- Compare Mode Toggle -->
                    <div class="mt-2 p-2 bg-blue-50 dark:bg-gray-700 rounded hidden" id="compareMode">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-medium text-blue-700 dark:text-blue-300">Compare Mode</span>
                            <button onclick="cancelCompareMode()" class="text-xs text-red-600 hover:text-red-800">Cancel</button>
                        </div>
                        <div class="text-xs text-gray-600 dark:text-gray-300 mb-2" id="compareStatus">Select two files to compare</div>
                        <button onclick="showComparison()" id="compareBtn" class="w-full px-3 py-2 text-xs bg-blue-600 text-white rounded hover:bg-blue-700 disabled:bg-gray-400 disabled:cursor-not-allowed" disabled>
                            Compare Files
                        </button>
                    </div>
                    <button onclick="enterCompareMode()" id="enterCompareModeBtn" class="mt-2 w-full px-3 py-2 text-xs bg-gray-100 hover:bg-gray-200 rounded transition-colors">
                        <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                        </svg>
                        Compare Files
                    </button>
                </div>

                <!-- Content Search -->
                <div id="globalSearchBox" class="relative hidden">
                    <input
                        type="text"
                        id="globalSearch"
                        placeholder="Search in all files..."
                        class="w-full px-3 py-2 pr-8 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        onkeyup="debounceGlobalSearch()"
                    />
                    <button onclick="clearGlobalSearch()" id="clearGlobalSearchBtn" class="absolute right-8 top-2.5 text-gray-400 hover:text-gray-600 hidden">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                    <svg class="absolute right-3 top-2.5 h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    <div id="globalSearchStatus" class="text-xs text-gray-500 mt-1 hidden"></div>
                </div>

                <!-- Bookmarks Panel -->
                <div id="bookmarksBox" class="hidden">
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-xs text-gray-500">Bookmarked files</span>
                        <button onclick="clearBookmarks()" class="text-xs text-red-500 hover:text-red-700 hover:underline">Clear All</button>
                    </div>
                    <div id="bookmarksList" class="space-y-1"></div>
                    <div id="emptyBookmarks" class="text-xs text-gray-400 text-center py-4 hidden">
                        No bookmarks yet. Click the bookmark icon on any file to save it.
                    </div>
                </div>

                <!-- History Panel -->
                <div id="historyBox" class="hidden">
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-xs text-gray-500">Recent files</span>
                        <button onclick="clearHistory()" class="text-xs text-red-500 hover:text-red-700 hover:underline">Clear</button>
                    </div>
                </div>
            </div>
            <nav class="p-2 overflow-y-auto flex-1" id="fileList">
                <?php if (empty($allMarkdownFiles)): ?>
                    <div class="px-3 py-2 text-sm text-gray-500">No markdown files found</div>
                <?php else: ?>
                    <?php
                    $currentDir = '';
                    foreach ($allMarkdownFiles as $mdFile):
                        $dir = dirname($mdFile);
                        $fileName = basename($mdFile);
                        $isActive = ($mdFile === $file);

                        // Show directory header if changed
                        if ($dir !== $currentDir && $dir !== '.') {
                            if ($currentDir !== '') {
                                echo '</div>'; // Close previous folder
                            }
                            $dirId = 'dir-' . md5($dir);
                            echo '<div class="folder-container" data-dir="' . htmlspecialchars($dir) . '">';
                            echo '<div class="file-dir-header px-3 py-1.5 text-xs font-semibold text-gray-600 uppercase tracking-wider mt-2 cursor-pointer hover:bg-gray-50 rounded flex items-center gap-1" onclick="toggleFolder(\'' . $dirId . '\')" data-dir="' . htmlspecialchars($dir) . '">';
                            echo '<svg class="w-3 h-3 transition-transform folder-icon" data-folder-id="' . $dirId . '" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path></svg>';
                            echo '<span>📁 ' . htmlspecialchars($dir) . '</span>';
                            echo '</div>';
                            echo '<div id="' . $dirId . '" class="folder-content">';
                            $currentDir = $dir;
                        } elseif ($dir === '.' && $currentDir !== '.') {
                            if ($currentDir !== '') {
                                echo '</div></div>'; // Close previous folder and container
                            }
                            $dirId = 'dir-root';
                            echo '<div class="folder-container" data-dir="root">';
                            echo '<div class="file-dir-header px-3 py-1.5 text-xs font-semibold text-gray-600 uppercase tracking-wider mt-2 cursor-pointer hover:bg-gray-50 rounded flex items-center gap-1" onclick="toggleFolder(\'' . $dirId . '\')" data-dir="root">';
                            echo '<svg class="w-3 h-3 transition-transform folder-icon" data-folder-id="' . $dirId . '" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path></svg>';
                            echo '<span>📁 Root</span>';
                            echo '</div>';
                            echo '<div id="' . $dirId . '" class="folder-content">';
                            $currentDir = '.';
                        }
                    ?>
                        <div class="file-item-wrapper group relative">
                            <a href="?file=<?php echo urlencode($mdFile); ?>"
                               class="file-item block px-4 py-1.5 pr-8 rounded text-xs transition-colors <?php echo $isActive ? 'bg-blue-50 text-blue-700 font-medium' : 'text-gray-700 hover:bg-gray-100'; ?>"
                               data-filename="<?php echo htmlspecialchars(strtolower($fileName)); ?>"
                               data-filepath="<?php echo htmlspecialchars(strtolower($mdFile)); ?>"
                               data-file="<?php echo htmlspecialchars($mdFile); ?>"
                               data-dir="<?php echo htmlspecialchars($dir === '.' ? 'root' : $dir); ?>"
                               onclick="loadFile('<?php echo htmlspecialchars($mdFile, ENT_QUOTES); ?>'); return false;">
                                <?php echo $isActive ? '📄 ' : '📃 '; ?>
                                <?php echo htmlspecialchars($fileName); ?>
                            </a>
                            <button onclick="toggleBookmark('<?php echo htmlspecialchars($mdFile, ENT_QUOTES); ?>'); event.stopPropagation();"
                                    class="bookmark-btn absolute right-2 top-1/2 -translate-y-1/2 opacity-0 group-hover:opacity-100 transition-opacity p-1 hover:bg-gray-200 rounded"
                                    data-file="<?php echo htmlspecialchars($mdFile); ?>"
                                    title="Bookmark this file">
                                <svg class="w-3 h-3 text-gray-400 hover:text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z"></path>
                                </svg>
                            </button>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!empty($allMarkdownFiles)): ?>
                        </div></div> <!-- Close last folder -->
                    <?php endif; ?>
                <?php endif; ?>
            </nav>
        </aside>

        <!-- Floating Content Search Bar -->
        <div id="contentSearchBar" class="hidden fixed top-4 left-1/2 transform -translate-x-1/2 z-50 w-full max-w-2xl px-4">
            <div class="flex items-center gap-2 bg-white p-4 rounded-lg shadow-2xl border border-gray-300">
                                <div class="relative flex-1">
                                    <input
                                        type="text"
                                        id="contentSearch"
                                        placeholder="Search in content..."
                                        class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                        onkeyup="handleSearchKeyup(event)"
                                        onkeydown="handleSearchKeydown(event)"
                                    />
                                    <svg class="absolute right-3 top-2.5 h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                    </svg>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span id="searchCounter" class="text-xs text-gray-500 whitespace-nowrap"></span>
                                    <button onclick="previousMatch()" class="p-2 text-gray-600 hover:text-blue-600 hover:bg-blue-50 rounded transition-colors" title="Previous (Shift+Enter)">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"></path>
                                        </svg>
                                    </button>
                                    <button onclick="nextMatch()" class="p-2 text-gray-600 hover:text-blue-600 hover:bg-blue-50 rounded transition-colors" title="Next (Enter)">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                        </svg>
                                    </button>
                                    <button onclick="toggleContentSearch()" class="p-2 text-gray-600 hover:text-red-600 hover:bg-red-50 rounded transition-colors" title="Close (Esc)">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                    </button>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <main class="flex-1 flex flex-col">
            <!-- Mobile Toggle Button -->
            <div class="md:hidden bg-white border-b border-gray-200 p-4 flex justify-between items-center">
                <button onclick="toggleSidebar()" class="text-blue-600 hover:underline text-sm font-medium">
                    ☰ Browse Files
                </button>
                <button onclick="toggleContentSearch()" class="px-3 py-2 text-sm text-gray-600 hover:text-blue-600 hover:bg-blue-50 rounded transition-colors" title="Search in page (Ctrl+F)">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </button>
            </div>

            <!-- Content Area -->
            <main class="flex-1 overflow-y-auto p-5" id="contentArea" role="main" aria-label="Markdown content viewer">
                <!-- Jump to Top Button -->
                <button id="jumpToTop" onclick="scrollToTop()" class="fixed bottom-8 right-8 bg-blue-600 text-white p-3 rounded-full shadow-lg hover:bg-blue-700 transition-all opacity-0 pointer-events-none z-50" title="Jump to top" aria-label="Jump to top of page">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"></path>
                    </svg>
                </button>

                <!-- File Comparison Overlay -->
                <div id="comparisonOverlay" class="hidden fixed inset-0 bg-white dark:bg-gray-900 z-[100] overflow-auto">
                    <div class="sticky top-0 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 p-4 z-10">
                        <div class="flex justify-between items-center max-w-7xl mx-auto">
                            <h2 class="text-lg font-bold text-gray-800 dark:text-gray-200">File Comparison</h2>
                            <button onclick="exitComparisonMode()" class="px-4 py-2 bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 rounded transition-colors">
                                Close
                            </button>
                        </div>
                        <div class="max-w-7xl mx-auto mt-2 flex gap-4 text-sm">
                            <div class="flex-1 font-mono text-blue-600 dark:text-blue-400" id="compareFile1Name"></div>
                            <div class="flex-1 font-mono text-green-600 dark:text-green-400" id="compareFile2Name"></div>
                        </div>
                    </div>
                    <div class="max-w-7xl mx-auto p-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 bg-blue-50 dark:bg-gray-800">
                                <div id="compareFile1Content" class="prose dark:prose-invert max-w-none text-gray-800 dark:text-gray-200"></div>
                            </div>
                            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 bg-green-50 dark:bg-gray-800">
                                <div id="compareFile2Content" class="prose dark:prose-invert max-w-none text-gray-800 dark:text-gray-200"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Presentation Mode Overlay -->
                <div id="presentationOverlay" class="hidden fixed inset-0 bg-black z-[100]">
                    <div id="presentationSlide" class="h-full w-full flex items-center justify-center p-16">
                        <div id="slideContent" class="prose prose-lg max-w-5xl text-white"></div>
                    </div>
                    <div class="fixed bottom-8 left-1/2 -translate-x-1/2 flex items-center gap-4 bg-gray-900 bg-opacity-80 px-6 py-3 rounded-full">
                        <button onclick="previousSlide()" class="text-white hover:text-blue-400 transition-colors" title="Previous (←)">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                            </svg>
                        </button>
                        <span id="slideCounter" class="text-white text-sm font-mono"></span>
                        <button onclick="nextSlide()" class="text-white hover:text-blue-400 transition-colors" title="Next (→)">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </button>
                        <button onclick="exitPresentationMode()" class="text-white hover:text-red-400 transition-colors ml-4" title="Exit (Esc)">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="max-w-4xl mx-auto bg-white rounded-lg shadow-md p-8 md:p-12" id="mainContent">
                    <!-- Header -->
                    <div class="mb-8 pb-5 border-b-2 border-gray-200">
                        <div class="flex justify-between items-start">
                            <div class="flex-1">
                                <h1 class="text-2xl font-bold text-blue-600 mb-2">MarkView</h1>
                                <!-- Breadcrumb Navigation -->
                                <nav id="breadcrumb" class="text-sm text-gray-600 font-mono mb-2"></nav>
                            </div>
                            <div class="flex gap-2">
                                <!-- Export to HTML -->
                                <button onclick="exportToHTML()" class="hidden md:block px-3 py-2 text-sm text-gray-600 hover:text-blue-600 hover:bg-blue-50 rounded transition-colors" title="Export to HTML">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                                    </svg>
                                </button>
                                <!-- Toggle Raw Markdown -->
                                <button onclick="toggleRawMarkdown()" id="rawMdBtn" class="hidden md:block px-3 py-2 text-sm text-gray-600 hover:text-blue-600 hover:bg-blue-50 rounded transition-colors" title="View raw markdown">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path>
                                    </svg>
                                </button>
                                <!-- Toggle Line Numbers -->
                                <button onclick="toggleLineNumbers()" id="lineNumBtn" class="hidden md:block px-3 py-2 text-sm text-gray-600 hover:text-blue-600 hover:bg-blue-50 rounded transition-colors" title="Toggle line numbers">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h7"></path>
                                    </svg>
                                </button>
                                <!-- Print -->
                                <button onclick="printPage()" class="hidden md:block px-3 py-2 text-sm text-gray-600 hover:text-blue-600 hover:bg-blue-50 rounded transition-colors" title="Print (Ctrl+P)">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                                    </svg>
                                </button>
                                <!-- Presentation Mode -->
                                <button onclick="enterPresentationMode()" class="hidden md:block px-3 py-2 text-sm text-gray-600 hover:text-blue-600 hover:bg-blue-50 rounded transition-colors" title="Presentation mode (F5)">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 4v16M17 4v16M3 8h4m10 0h4M3 12h18M3 16h4m10 0h4M4 20h16a1 1 0 001-1V5a1 1 0 00-1-1H4a1 1 0 00-1 1v14a1 1 0 001 1z"></path>
                                    </svg>
                                </button>
                                <!-- Theme Selector -->
                                <div class="relative">
                                    <button onclick="toggleThemeMenu()" class="px-3 py-2 text-sm text-gray-600 hover:text-blue-600 hover:bg-blue-50 rounded transition-colors" title="Change theme">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"></path>
                                        </svg>
                                    </button>
                                    <div id="themeMenu" class="hidden absolute right-0 mt-2 w-48 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg z-50">
                                        <div class="p-2">
                                            <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 px-2 py-1">Select Theme</div>
                                            <button onclick="setTheme('default')" class="theme-option w-full text-left px-3 py-2 text-sm rounded hover:bg-gray-100 dark:hover:bg-gray-700 flex items-center justify-between" data-theme="default">
                                                <span>Default</span>
                                                <div class="flex gap-1">
                                                    <div class="w-4 h-4 rounded-full bg-gray-50 border border-gray-300"></div>
                                                    <div class="w-4 h-4 rounded-full bg-blue-600"></div>
                                                </div>
                                            </button>
                                            <button onclick="setTheme('dark')" class="theme-option w-full text-left px-3 py-2 text-sm rounded hover:bg-gray-100 dark:hover:bg-gray-700 flex items-center justify-between" data-theme="dark">
                                                <span>Dark</span>
                                                <div class="flex gap-1">
                                                    <div class="w-4 h-4 rounded-full bg-gray-900 border border-gray-700"></div>
                                                    <div class="w-4 h-4 rounded-full bg-blue-500"></div>
                                                </div>
                                            </button>
                                            <button onclick="setTheme('sepia')" class="theme-option w-full text-left px-3 py-2 text-sm rounded hover:bg-gray-100 dark:hover:bg-gray-700 flex items-center justify-between" data-theme="sepia">
                                                <span>Sepia</span>
                                                <div class="flex gap-1">
                                                    <div class="w-4 h-4 rounded-full bg-amber-50 border border-amber-300"></div>
                                                    <div class="w-4 h-4 rounded-full bg-amber-700"></div>
                                                </div>
                                            </button>
                                            <button onclick="setTheme('forest')" class="theme-option w-full text-left px-3 py-2 text-sm rounded hover:bg-gray-100 dark:hover:bg-gray-700 flex items-center justify-between" data-theme="forest">
                                                <span>Forest</span>
                                                <div class="flex gap-1">
                                                    <div class="w-4 h-4 rounded-full bg-green-50 border border-green-300"></div>
                                                    <div class="w-4 h-4 rounded-full bg-green-700"></div>
                                                </div>
                                            </button>
                                            <button onclick="setTheme('ocean')" class="theme-option w-full text-left px-3 py-2 text-sm rounded hover:bg-gray-100 dark:hover:bg-gray-700 flex items-center justify-between" data-theme="ocean">
                                                <span>Ocean</span>
                                                <div class="flex gap-1">
                                                    <div class="w-4 h-4 rounded-full bg-cyan-50 border border-cyan-300"></div>
                                                    <div class="w-4 h-4 rounded-full bg-cyan-700"></div>
                                                </div>
                                            </button>
                                            <button onclick="setTheme('sunset')" class="theme-option w-full text-left px-3 py-2 text-sm rounded hover:bg-gray-100 dark:hover:bg-gray-700 flex items-center justify-between" data-theme="sunset">
                                                <span>Sunset</span>
                                                <div class="flex gap-1">
                                                    <div class="w-4 h-4 rounded-full bg-orange-50 border border-orange-300"></div>
                                                    <div class="w-4 h-4 rounded-full bg-orange-700"></div>
                                                </div>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <!-- Dark Mode Toggle -->
                                <button onclick="toggleDarkMode()" class="px-3 py-2 text-sm text-gray-600 hover:text-blue-600 hover:bg-blue-50 rounded transition-colors" title="Toggle dark mode">
                                    <svg id="darkModeIcon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
                                    </svg>
                                </button>
                                <!-- Content Search Toggle -->
                                <button onclick="toggleContentSearch()" class="hidden md:block px-3 py-2 text-sm text-gray-600 hover:text-blue-600 hover:bg-blue-50 rounded transition-colors" title="Search in page (Ctrl+F)">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Error Message -->
                    <?php if ($error): ?>
                    <div class="bg-yellow-50 border border-yellow-400 text-yellow-800 px-4 py-3 rounded mb-5">
                        <strong class="font-semibold">Error:</strong> <?php echo htmlspecialchars($error); ?>
                    </div>
                    <?php endif; ?>

                    <!-- Content -->
                    <div class="prose text-gray-800" id="proseContent">
                        <?php echo $htmlContent; ?>
                    </div>

                    <!-- Raw Markdown View -->
                    <div id="rawMarkdownView" class="font-mono text-sm bg-gray-50 p-4 rounded border border-gray-300 whitespace-pre-wrap"></div>

                    <!-- Footer -->
                    <div class="mt-10 pt-5 border-t border-gray-200 text-center text-xs text-gray-500">
                        MarkView - Simple PHP Markdown Viewer
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Initialize syntax highlighting and mermaid
        document.addEventListener('DOMContentLoaded', function() {
            // Syntax highlighting
            hljs.highlightAll();

            // Initialize and render Mermaid diagrams
            if (typeof mermaid !== 'undefined') {
                mermaid.initialize({
                    startOnLoad: false,
                    theme: 'default',
                    securityLevel: 'loose',
                    flowchart: {
                        useMaxWidth: true,
                        htmlLabels: true
                    }
                });

                // Manually render all mermaid elements
                const mermaidElements = document.querySelectorAll('.mermaid');
                console.log('Found mermaid elements:', mermaidElements.length);

                mermaidElements.forEach((element, index) => {
                    try {
                        const graphDefinition = element.textContent;
                        console.log('Rendering diagram', index, ':', graphDefinition);
                        const id = 'mermaid-' + index;
                        mermaid.render(id, graphDefinition).then(result => {
                            element.innerHTML = result.svg;
                        }).catch(error => {
                            console.error('Mermaid render error:', error);
                            element.innerHTML = '<pre>Error rendering diagram: ' + error.message + '</pre>';
                        });
                    } catch (error) {
                        console.error('Mermaid error:', error);
                    }
                });
            } else {
                console.error('Mermaid library not loaded');
            }
        });

        // Folder toggle functionality
        function toggleFolder(folderId) {
            const folderContent = document.getElementById(folderId);
            const folderIcon = document.querySelector(`[data-folder-id="${folderId}"]`);

            if (folderContent.classList.contains('collapsed')) {
                folderContent.classList.remove('collapsed');
                folderIcon.style.transform = 'rotate(90deg)';
            } else {
                folderContent.classList.add('collapsed');
                folderIcon.style.transform = 'rotate(0deg)';
            }
        }

        // File search/filter functionality with debouncing
        let fileSearchTimeout;

        function debounceFileSearch() {
            const searchInput = document.getElementById('fileSearch');
            const clearBtn = document.getElementById('clearFileSearchBtn');

            // Show/hide clear button
            if (searchInput.value.trim()) {
                clearBtn.classList.remove('hidden');
            } else {
                clearBtn.classList.add('hidden');
            }

            clearTimeout(fileSearchTimeout);
            fileSearchTimeout = setTimeout(filterFiles, 200);
        }

        function clearFileSearch() {
            const searchInput = document.getElementById('fileSearch');
            const clearBtn = document.getElementById('clearFileSearchBtn');
            searchInput.value = '';
            clearBtn.classList.add('hidden');
            filterFiles();
            searchInput.focus();
        }

        function filterFiles() {
            const searchInput = document.getElementById('fileSearch');
            const searchTerm = searchInput.value.toLowerCase().trim();
            const fileItems = document.querySelectorAll('.file-item');
            const folderContainers = document.querySelectorAll('.folder-container');
            const searchResults = document.getElementById('searchResults');

            if (searchTerm === '') {
                // Show all files and folders
                fileItems.forEach(item => {
                    item.style.display = 'block';
                });
                folderContainers.forEach(container => {
                    container.style.display = 'block';
                });
                searchResults.classList.add('hidden');
                return;
            }

            // Track which directories have visible files
            const visibleDirs = new Set();
            let visibleCount = 0;

            // Filter files
            fileItems.forEach(item => {
                const filename = item.getAttribute('data-filename');
                const filepath = item.getAttribute('data-filepath');
                const dir = item.getAttribute('data-dir');

                // Search in both filename and full path
                if (filename.includes(searchTerm) || filepath.includes(searchTerm)) {
                    item.style.display = 'block';
                    visibleDirs.add(dir);
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            });

            // Show/hide folder containers based on whether they have visible files
            folderContainers.forEach(container => {
                const dir = container.getAttribute('data-dir');
                if (visibleDirs.has(dir)) {
                    container.style.display = 'block';
                    // Auto-expand folders with search results
                    const folderContent = container.querySelector('.folder-content');
                    if (folderContent && folderContent.classList.contains('collapsed')) {
                        const folderId = folderContent.id;
                        toggleFolder(folderId);
                    }
                } else {
                    container.style.display = 'none';
                }
            });

            // Update search results count
            searchResults.classList.remove('hidden');
            if (visibleCount === 0) {
                searchResults.textContent = 'No files found';
                searchResults.classList.add('text-red-500');
                searchResults.classList.remove('text-gray-500');
            } else {
                searchResults.textContent = `Found ${visibleCount} file${visibleCount === 1 ? '' : 's'}`;
                searchResults.classList.add('text-gray-500');
                searchResults.classList.remove('text-red-500');
            }
        }

        // Content search variables
        let contentSearchMatches = [];
        let currentMatchIndex = -1;
        let originalProseContent = null;

        // Toggle content search bar
        function toggleContentSearch() {
            const searchBar = document.getElementById('contentSearchBar');
            const searchInput = document.getElementById('contentSearch');

            if (searchBar.classList.contains('hidden')) {
                searchBar.classList.remove('hidden');
                searchInput.focus();
                // Store original content on first open
                if (!originalProseContent) {
                    const prose = document.getElementById('proseContent');
                    originalProseContent = prose.innerHTML;
                }
            } else {
                searchBar.classList.add('hidden');
                clearContentSearch();
            }
        }

        // Handle keydown events in search input
        function handleSearchKeydown(event) {
            if (event.key === 'Enter') {
                event.preventDefault(); // Prevent form submission
                if (event.shiftKey) {
                    previousMatch();
                } else {
                    nextMatch();
                }
            }
        }

        // Handle keyup events in search input
        function handleSearchKeyup(event) {
            // Skip if it's Enter key (handled by keydown)
            if (event.key === 'Enter') {
                return;
            }
            searchContent();
        }

        // Search content and highlight matches
        function searchContent() {
            const searchInput = document.getElementById('contentSearch');
            const searchTerm = searchInput.value.trim();
            const prose = document.getElementById('proseContent');
            const counter = document.getElementById('searchCounter');

            // Store original content if not already stored
            if (!originalProseContent) {
                originalProseContent = prose.innerHTML;
            }

            // Restore original content before new search
            prose.innerHTML = originalProseContent;

            // Reinitialize Mermaid diagrams after restoring content
            if (typeof mermaid !== 'undefined') {
                const mermaidElements = prose.querySelectorAll('.mermaid');
                if (mermaidElements.length > 0) {
                    mermaid.init(undefined, mermaidElements);
                }
            }

            contentSearchMatches = [];
            currentMatchIndex = -1;

            if (!searchTerm || searchTerm.length < 2) {
                counter.textContent = '';
                return;
            }

            // Find and highlight all matches using a simpler approach
            highlightInElement(prose, searchTerm);

            // Get all highlighted elements
            contentSearchMatches = Array.from(prose.querySelectorAll('.search-highlight'));

            // Apply highlights
            if (contentSearchMatches.length > 0) {
                currentMatchIndex = 0;
                scrollToMatch(0);
                updateCounter();
            } else {
                counter.textContent = 'No matches';
                counter.classList.add('text-red-500');
                counter.classList.remove('text-gray-500');
            }
        }

        // Recursively highlight text in element and children
        function highlightInElement(element, searchTerm) {
            const regex = new RegExp('(' + escapeRegex(searchTerm) + ')', 'gi');

            // Walk through child nodes
            const childNodes = Array.from(element.childNodes);
            childNodes.forEach(node => {
                if (node.nodeType === Node.TEXT_NODE) {
                    // Text node - check for matches
                    const text = node.textContent;
                    if (regex.test(text)) {
                        // Create replacement with highlights
                        const fragment = document.createDocumentFragment();
                        const parts = text.split(regex);

                        parts.forEach((part, index) => {
                            if (index % 2 === 0) {
                                // Non-match
                                if (part) fragment.appendChild(document.createTextNode(part));
                            } else {
                                // Match
                                const span = document.createElement('span');
                                span.className = 'search-highlight';
                                span.textContent = part;
                                fragment.appendChild(span);
                            }
                        });

                        node.parentNode.replaceChild(fragment, node);
                    }
                } else if (node.nodeType === Node.ELEMENT_NODE) {
                    // Skip code blocks, scripts, and mermaid diagrams
                    if (node.tagName !== 'SCRIPT' &&
                        node.tagName !== 'STYLE' &&
                        node.tagName !== 'CODE' &&
                        !node.classList.contains('mermaid')) {
                        highlightInElement(node, searchTerm);
                    }
                }
            });
        }

        // Clear all highlights
        function clearContentSearch() {
            const prose = document.getElementById('proseContent');
            if (originalProseContent) {
                prose.innerHTML = originalProseContent;
                // Reinitialize Mermaid diagrams
                if (typeof mermaid !== 'undefined') {
                    mermaid.init(undefined, prose.querySelectorAll('.mermaid'));
                }
            }
            contentSearchMatches = [];
            currentMatchIndex = -1;

            const counter = document.getElementById('searchCounter');
            counter.textContent = '';
            counter.classList.remove('text-red-500');
            counter.classList.add('text-gray-500');
        }

        // Navigate to next match
        function nextMatch() {
            if (contentSearchMatches.length === 0) return;
            currentMatchIndex = (currentMatchIndex + 1) % contentSearchMatches.length;
            scrollToMatch(currentMatchIndex);
            updateCounter();
        }

        // Navigate to previous match
        function previousMatch() {
            if (contentSearchMatches.length === 0) return;
            currentMatchIndex = (currentMatchIndex - 1 + contentSearchMatches.length) % contentSearchMatches.length;
            scrollToMatch(currentMatchIndex);
            updateCounter();
        }

        // Scroll to match and highlight it
        function scrollToMatch(index) {
            // Remove current highlight from all
            document.querySelectorAll('.search-highlight-current').forEach(el => {
                el.classList.remove('search-highlight-current');
                el.classList.add('search-highlight');
            });

            // Add current highlight
            const match = contentSearchMatches[index];
            if (match) {
                match.classList.remove('search-highlight');
                match.classList.add('search-highlight-current');
                match.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        // Update counter display
        function updateCounter() {
            const counter = document.getElementById('searchCounter');
            if (contentSearchMatches.length > 0) {
                counter.textContent = `${currentMatchIndex + 1} of ${contentSearchMatches.length}`;
                counter.classList.remove('text-red-500');
                counter.classList.add('text-gray-500');
            }
        }

        // Escape regex special characters
        function escapeRegex(string) {
            return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+K or Cmd+K to focus file search
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                switchSearchMode('files');
                document.getElementById('fileSearch').focus();
                return;
            }

            // Ctrl+F or Cmd+F to open content search
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                toggleContentSearch();
                return;
            }

            // Ctrl+B or Cmd+B to show bookmarks
            if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
                e.preventDefault();
                switchSearchMode('bookmarks');
                return;
            }

            // Ctrl+H or Cmd+H to show history
            if ((e.ctrlKey || e.metaKey) && e.key === 'h') {
                e.preventDefault();
                switchSearchMode('history');
                return;
            }

            // Ctrl+D or Cmd+D to toggle bookmark for current file
            if ((e.ctrlKey || e.metaKey) && e.key === 'd') {
                e.preventDefault();
                if (currentFile) {
                    toggleBookmark(currentFile);
                }
                return;
            }

            // Ctrl+P or Cmd+P to print
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                printPage();
                return;
            }

            // Ctrl+/ or Cmd+/ to toggle dark mode
            if ((e.ctrlKey || e.metaKey) && e.key === '/') {
                e.preventDefault();
                toggleDarkMode();
                return;
            }

            // Escape to clear/close searches
            if (e.key === 'Escape') {
                const contentSearchBar = document.getElementById('contentSearchBar');
                const fileSearch = document.getElementById('fileSearch');
                const globalSearch = document.getElementById('globalSearch');

                if (!contentSearchBar.classList.contains('hidden')) {
                    toggleContentSearch();
                } else if (fileSearch && fileSearch.value !== '') {
                    clearFileSearch();
                } else if (globalSearch && globalSearch.value !== '') {
                    clearGlobalSearch();
                }
                return;
            }
        });

        // Global search functionality
        let globalSearchTimeout;
        let currentSearchMode = 'files';

        function switchSearchMode(mode) {
            currentSearchMode = mode;
            const filesTab = document.getElementById('filesTabBtn');
            const contentTab = document.getElementById('contentTabBtn');
            const bookmarksTab = document.getElementById('bookmarksTabBtn');
            const historyTab = document.getElementById('historyTabBtn');
            const fileSearchBox = document.getElementById('fileSearchBox');
            const globalSearchBox = document.getElementById('globalSearchBox');
            const bookmarksBox = document.getElementById('bookmarksBox');
            const historyBox = document.getElementById('historyBox');
            const fileList = document.getElementById('fileList');

            // Reset all tabs and ARIA states
            filesTab.className = 'flex-1 px-3 py-1 text-xs font-medium rounded transition-colors text-gray-600 hover:bg-gray-100';
            contentTab.className = 'flex-1 px-3 py-1 text-xs font-medium rounded transition-colors text-gray-600 hover:bg-gray-100';
            bookmarksTab.className = 'flex-1 px-3 py-1 text-xs font-medium rounded transition-colors text-gray-600 hover:bg-gray-100';
            historyTab.className = 'flex-1 px-3 py-1 text-xs font-medium rounded transition-colors text-gray-600 hover:bg-gray-100';
            filesTab.setAttribute('aria-selected', 'false');
            contentTab.setAttribute('aria-selected', 'false');
            bookmarksTab.setAttribute('aria-selected', 'false');
            historyTab.setAttribute('aria-selected', 'false');
            fileSearchBox.classList.add('hidden');
            globalSearchBox.classList.add('hidden');
            bookmarksBox.classList.add('hidden');
            historyBox.classList.add('hidden');

            if (mode === 'files') {
                filesTab.className = 'flex-1 px-3 py-1 text-xs font-medium rounded transition-colors bg-blue-100 text-blue-700';
                filesTab.setAttribute('aria-selected', 'true');
                fileSearchBox.classList.remove('hidden');
                fileList.innerHTML = document.getElementById('originalFileList').innerHTML;
                updateBookmarkIcons();
            } else if (mode === 'content') {
                contentTab.className = 'flex-1 px-3 py-1 text-xs font-medium rounded transition-colors bg-blue-100 text-blue-700';
                contentTab.setAttribute('aria-selected', 'true');
                globalSearchBox.classList.remove('hidden');
                document.getElementById('globalSearch').focus();
            } else if (mode === 'bookmarks') {
                bookmarksTab.className = 'flex-1 px-3 py-1 text-xs font-medium rounded transition-colors bg-blue-100 text-blue-700';
                bookmarksTab.setAttribute('aria-selected', 'true');
                bookmarksBox.classList.remove('hidden');
                displayBookmarks();
            } else if (mode === 'history') {
                historyTab.className = 'flex-1 px-3 py-1 text-xs font-medium rounded transition-colors bg-blue-100 text-blue-700';
                historyTab.setAttribute('aria-selected', 'true');
                historyBox.classList.remove('hidden');
                displayHistory();
            }
        }

        function debounceGlobalSearch() {
            const searchInput = document.getElementById('globalSearch');
            const clearBtn = document.getElementById('clearGlobalSearchBtn');

            // Show/hide clear button
            if (searchInput.value.trim()) {
                clearBtn.classList.remove('hidden');
            } else {
                clearBtn.classList.add('hidden');
            }

            clearTimeout(globalSearchTimeout);
            globalSearchTimeout = setTimeout(performGlobalSearch, 500);
        }

        function clearGlobalSearch() {
            const searchInput = document.getElementById('globalSearch');
            const clearBtn = document.getElementById('clearGlobalSearchBtn');
            const fileList = document.getElementById('fileList');
            const status = document.getElementById('globalSearchStatus');

            searchInput.value = '';
            clearBtn.classList.add('hidden');
            status.classList.add('hidden');
            fileList.innerHTML = '<div class="px-3 py-2 text-sm text-gray-500">Type at least 2 characters to search</div>';
            searchInput.focus();
        }

        async function performGlobalSearch() {
            const searchInput = document.getElementById('globalSearch');
            const query = searchInput.value.trim();
            const status = document.getElementById('globalSearchStatus');
            const fileList = document.getElementById('fileList');

            if (query.length < 2) {
                status.classList.add('hidden');
                fileList.innerHTML = '<div class="px-3 py-2 text-sm text-gray-500">Type at least 2 characters to search</div>';
                return;
            }

            // Show loading
            status.textContent = 'Searching...';
            status.classList.remove('hidden', 'text-red-500');
            status.classList.add('text-gray-500');

            try {
                const response = await fetch(`index.php?search=1&q=${encodeURIComponent(query)}`);
                const data = await response.json();

                if (data.success) {
                    displayGlobalSearchResults(data.results, query);
                    status.textContent = `Found ${data.totalResults} file${data.totalResults === 1 ? '' : 's'}`;
                } else {
                    status.textContent = data.error || 'Search failed';
                    status.classList.add('text-red-500');
                    fileList.innerHTML = '<div class="px-3 py-2 text-sm text-red-500">Search error</div>';
                }
            } catch (error) {
                console.error('Search error:', error);
                status.textContent = 'Search failed';
                status.classList.add('text-red-500');
                fileList.innerHTML = '<div class="px-3 py-2 text-sm text-red-500">Connection error</div>';
            }
        }

        function displayGlobalSearchResults(results, query) {
            const fileList = document.getElementById('fileList');

            if (results.length === 0) {
                fileList.innerHTML = '<div class="px-3 py-2 text-sm text-gray-500">No matches found</div>';
                return;
            }

            let html = '';
            results.forEach(result => {
                html += `
                    <div class="mb-4 border-b border-gray-100 pb-3">
                        <a href="?file=${encodeURIComponent(result.file)}"
                           onclick="loadFile('${escapeHtml(result.file)}'); return false;"
                           class="block px-3 py-2 hover:bg-blue-50 rounded transition-colors cursor-pointer">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium text-gray-800">📄 ${escapeHtml(result.fileName)}</span>
                                <span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">${result.matchCount}</span>
                            </div>
                            <div class="text-xs text-gray-500 mt-1">${escapeHtml(result.file)}</div>
                        </a>
                        <div class="px-3 mt-2 space-y-1">
                            ${result.matches.map(match => `
                                <div class="text-xs">
                                    <span class="text-gray-400">L${match.line}:</span>
                                    <span class="text-gray-700">${match.preview}</span>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;
            });

            fileList.innerHTML = html;
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Store original file list for switching back
        document.addEventListener('DOMContentLoaded', function() {
            const fileList = document.getElementById('fileList');
            const originalList = document.createElement('div');
            originalList.id = 'originalFileList';
            originalList.innerHTML = fileList.innerHTML;
            originalList.style.display = 'none';
            document.body.appendChild(originalList);
        });

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('hidden');
        }

        // Hide sidebar by default on mobile
        if (window.innerWidth < 768) {
            document.getElementById('sidebar').classList.add('hidden');
        }

        // Handle window resize
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth >= 768) {
                sidebar.classList.remove('hidden');
            } else {
                sidebar.classList.add('hidden');
            }
        });

        // AJAX file loading - load files without page reload
        let currentFile = '<?php echo htmlspecialchars($file, ENT_QUOTES); ?>';

        async function loadFile(fileName) {
            try {
                // Show loading skeleton - use ID to target the main content area
                const prose = document.getElementById('proseContent');
                prose.innerHTML = `
                    <div class="space-y-4">
                        <div class="skeleton h-8 rounded"></div>
                        <div class="skeleton h-4 rounded w-3/4"></div>
                        <div class="skeleton h-4 rounded"></div>
                        <div class="skeleton h-4 rounded w-5/6"></div>
                        <div class="skeleton h-32 rounded mt-6"></div>
                        <div class="skeleton h-4 rounded"></div>
                        <div class="skeleton h-4 rounded w-4/5"></div>
                    </div>
                `;

                // Reset search context and raw view
                originalProseContent = null;
                clearContentSearch();
                if (showingRawMarkdown) toggleRawMarkdown();

                // Fetch file content
                const response = await fetch(`index.php?api=content&file=${encodeURIComponent(fileName)}`);
                const data = await response.json();

                if (data.success) {
                    // Update content
                    prose.innerHTML = data.html;

                    // Update URL without reload
                    const url = new URL(window.location);
                    url.searchParams.set('file', fileName);
                    window.history.pushState({ file: fileName }, '', url);
                    currentFile = fileName;

                    // Update active state in sidebar
                    updateActiveFileInSidebar(fileName);

                    // Reinitialize syntax highlighting
                    if (typeof hljs !== 'undefined') {
                        prose.querySelectorAll('pre code').forEach((block) => {
                            hljs.highlightElement(block);
                        });
                    }

                    // Reinitialize Mermaid diagrams
                    if (typeof mermaid !== 'undefined') {
                        const mermaidElements = prose.querySelectorAll('.mermaid');
                        if (mermaidElements.length > 0) {
                            mermaid.init(undefined, mermaidElements);
                        }
                    }
                } else {
                    prose.innerHTML = '<div class="text-center py-8 text-red-500">Error: ' + escapeHtml(data.error) + '</div>';
                }
            } catch (error) {
                console.error('Failed to load file:', error);
                const prose = document.getElementById('proseContent');
                prose.innerHTML = '<div class="text-center py-8 text-red-500">Failed to load file</div>';
            }
        }

        // Update active state in sidebar
        function updateActiveFileInSidebar(fileName) {
            // Remove active class from all items
            document.querySelectorAll('.file-item').forEach(item => {
                item.classList.remove('bg-blue-50', 'text-blue-700', 'font-medium');
                item.classList.add('text-gray-700', 'hover:bg-gray-100');
                // Change icon
                const icon = item.childNodes[0];
                if (icon && icon.textContent && icon.textContent.includes('📄')) {
                    icon.textContent = icon.textContent.replace('📄', '📃');
                }
            });

            // Add active class to current file
            document.querySelectorAll('.file-item').forEach(item => {
                const itemFile = item.getAttribute('data-file');
                if (itemFile === fileName) {
                    item.classList.remove('text-gray-700', 'hover:bg-gray-100');
                    item.classList.add('bg-blue-50', 'text-blue-700', 'font-medium');
                    // Change icon
                    const icon = item.childNodes[0];
                    if (icon && icon.textContent && icon.textContent.includes('📃')) {
                        icon.textContent = icon.textContent.replace('📃', '📄');
                    }
                }
            });
        }

        // Handle browser back/forward buttons
        window.addEventListener('popstate', function(event) {
            if (event.state && event.state.file) {
                loadFile(event.state.file);
            }
        });

        // Set initial state
        window.history.replaceState({ file: currentFile }, '', window.location.href);

        // ===== DARK MODE =====
        function toggleDarkMode() {
            document.body.classList.toggle('dark');
            const isDark = document.body.classList.contains('dark');
            localStorage.setItem('darkMode', isDark ? 'enabled' : 'disabled');
            updateDarkModeIcon(isDark);
        }

        function updateDarkModeIcon(isDark) {
            const icon = document.getElementById('darkModeIcon');
            if (isDark) {
                // Sun icon
                icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path>';
            } else {
                // Moon icon
                icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>';
            }
        }

        // Load dark mode preference
        if (localStorage.getItem('darkMode') === 'enabled') {
            document.body.classList.add('dark');
            updateDarkModeIcon(true);
        }

        // ===== THEME SYSTEM =====
        const themes = ['default', 'dark', 'sepia', 'forest', 'ocean', 'sunset'];
        let currentTheme = localStorage.getItem('theme') || 'default';

        function toggleThemeMenu() {
            const menu = document.getElementById('themeMenu');
            menu.classList.toggle('hidden');
        }

        function setTheme(themeName) {
            // Remove all theme classes
            themes.forEach(theme => {
                if (theme !== 'default') {
                    document.body.classList.remove(`theme-${theme}`);
                }
            });

            // Handle dark mode separately
            if (themeName === 'dark') {
                document.body.classList.add('dark');
                localStorage.setItem('darkMode', 'enabled');
                updateDarkModeIcon(true);
            } else {
                document.body.classList.remove('dark');
                localStorage.setItem('darkMode', 'disabled');
                updateDarkModeIcon(false);

                // Add new theme class if not default
                if (themeName !== 'default') {
                    document.body.classList.add(`theme-${themeName}`);
                }
            }

            // Save theme preference
            currentTheme = themeName;
            localStorage.setItem('theme', themeName);

            // Update active indicator
            document.querySelectorAll('.theme-option').forEach(btn => {
                btn.classList.remove('active');
            });
            document.querySelector(`[data-theme="${themeName}"]`).classList.add('active');

            // Close menu
            toggleThemeMenu();

            announceToScreenReader(`Theme changed to ${themeName}`);
        }

        // Load theme preference on page load
        function loadTheme() {
            const savedTheme = localStorage.getItem('theme') || 'default';
            if (savedTheme !== 'default') {
                setTheme(savedTheme);
            }
            // Mark active theme
            document.querySelector(`[data-theme="${savedTheme}"]`)?.classList.add('active');
        }

        // Close theme menu when clicking outside
        document.addEventListener('click', function(event) {
            const themeMenu = document.getElementById('themeMenu');
            const themeButton = event.target.closest('button[onclick="toggleThemeMenu()"]');

            if (!themeMenu.contains(event.target) && !themeButton) {
                themeMenu.classList.add('hidden');
            }
        });

        // Load theme on page load
        loadTheme();

        // ===== BREADCRUMB NAVIGATION =====
        function updateBreadcrumb(filePath) {
            const breadcrumb = document.getElementById('breadcrumb');
            const parts = filePath.split('/');
            let html = '';
            let currentPath = '';

            parts.forEach((part, index) => {
                if (index > 0) currentPath += '/';
                currentPath += part;

                if (index < parts.length - 1) {
                    // Directory - make it clickable to show files in that folder
                    html += `<span class="text-gray-400">/</span> <span class="text-gray-500">${escapeHtml(part)}</span> `;
                } else {
                    // Current file
                    html += `<span class="text-gray-400">/</span> <span class="text-gray-700 font-semibold">📄 ${escapeHtml(part)}</span>`;
                }
            });

            breadcrumb.innerHTML = html || '<span class="text-gray-500">📄 Root</span>';
        }

        // Initialize breadcrumb
        updateBreadcrumb(currentFile);

        // ===== COPY CODE BUTTONS =====
        function addCopyButtons() {
            document.querySelectorAll('pre code').forEach((codeBlock) => {
                const pre = codeBlock.parentElement;
                if (pre.querySelector('.copy-button')) return; // Already has button

                const button = document.createElement('button');
                button.className = 'copy-button absolute top-2 right-2 px-2 py-1 text-xs bg-gray-700 text-white rounded opacity-0 hover:opacity-100 transition-opacity';
                button.textContent = 'Copy';
                button.onclick = function() {
                    navigator.clipboard.writeText(codeBlock.textContent).then(() => {
                        button.textContent = 'Copied!';
                        setTimeout(() => {
                            button.textContent = 'Copy';
                        }, 2000);
                    });
                };

                pre.style.position = 'relative';
                pre.appendChild(button);
                pre.addEventListener('mouseenter', () => button.classList.add('opacity-100'));
                pre.addEventListener('mouseleave', () => button.classList.remove('opacity-100'));
            });
        }

        // ===== TABLE OF CONTENTS =====
        function generateTableOfContents() {
            const prose = document.getElementById('proseContent');
            const headings = prose.querySelectorAll('h1, h2, h3, h4, h5, h6');

            if (headings.length < 3) return; // Don't show TOC for short documents

            let tocHTML = '<div class="toc-container mb-8 p-4 bg-blue-50 rounded-lg border border-blue-200"><div class="font-semibold text-blue-800 mb-2">📑 Table of Contents</div><ul class="space-y-1">';

            headings.forEach((heading, index) => {
                const level = parseInt(heading.tagName[1]);
                const text = heading.textContent;
                const id = 'heading-' + index;
                heading.id = id;

                const indent = (level - 1) * 1;
                tocHTML += `<li style="margin-left: ${indent}rem"><a href="#${id}" class="text-sm text-blue-600 hover:text-blue-800 hover:underline" onclick="scrollToHeading('${id}'); return false;">${escapeHtml(text)}</a></li>`;
            });

            tocHTML += '</ul></div>';

            // Insert TOC after the first heading or at the beginning
            const firstHeading = prose.querySelector('h1, h2');
            if (firstHeading && firstHeading.nextElementSibling) {
                firstHeading.insertAdjacentHTML('afterend', tocHTML);
            } else {
                prose.insertAdjacentHTML('afterbegin', tocHTML);
            }
        }

        function scrollToHeading(id) {
            const element = document.getElementById(id);
            if (element) {
                element.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        // ===== ANCHOR LINKS FOR HEADERS =====
        function addAnchorLinks() {
            document.querySelectorAll('.prose h1, .prose h2, .prose h3, .prose h4, .prose h5, .prose h6').forEach((heading) => {
                if (!heading.id) return;

                heading.style.position = 'relative';
                heading.classList.add('group');

                const anchor = document.createElement('a');
                anchor.href = '#' + heading.id;
                anchor.className = 'anchor-link absolute -left-6 opacity-0 group-hover:opacity-100 transition-opacity text-blue-400 hover:text-blue-600';
                anchor.innerHTML = '🔗';
                anchor.title = 'Copy link to this section';
                anchor.onclick = function(e) {
                    e.preventDefault();
                    const url = new URL(window.location);
                    url.hash = heading.id;
                    navigator.clipboard.writeText(url.toString()).then(() => {
                        anchor.innerHTML = '✓';
                        setTimeout(() => {
                            anchor.innerHTML = '🔗';
                        }, 2000);
                    });
                };

                heading.appendChild(anchor);
            });
        }

        // ===== INITIALIZE ALL FEATURES =====
        function initializeFeatures() {
            addCopyButtons();
            generateTableOfContents();
            addAnchorLinks();
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', initializeFeatures);

        // Re-initialize after loading new file
        const originalLoadFile = loadFile;
        loadFile = async function(fileName) {
            await originalLoadFile(fileName);
            updateBreadcrumb(fileName);
            initializeFeatures();
            addToHistory(fileName);
        };

        // ===== FILE HISTORY =====
        function addToHistory(filePath) {
            let history = JSON.parse(localStorage.getItem('fileHistory') || '[]');

            // Remove if already exists
            history = history.filter(item => item.path !== filePath);

            // Add to beginning with timestamp
            history.unshift({
                path: filePath,
                name: filePath.split('/').pop(),
                timestamp: Date.now()
            });

            // Keep only last 10 files
            history = history.slice(0, 10);

            localStorage.setItem('fileHistory', JSON.stringify(history));
        }

        function displayHistory() {
            const history = JSON.parse(localStorage.getItem('fileHistory') || '[]');
            const fileList = document.getElementById('fileList');

            if (history.length === 0) {
                fileList.innerHTML = '<div class="px-3 py-2 text-sm text-gray-500">No recently viewed files</div>';
                return;
            }

            let html = '';
            history.forEach((item, index) => {
                const timeAgo = getTimeAgo(item.timestamp);
                const isActive = item.path === currentFile;

                html += `
                    <div class="mb-2 border-b border-gray-100 pb-2">
                        <a href="?file=${encodeURIComponent(item.path)}"
                           onclick="loadFile('${escapeHtml(item.path)}'); return false;"
                           class="block px-3 py-2 rounded text-xs transition-colors ${isActive ? 'bg-blue-50 text-blue-700 font-medium' : 'text-gray-700 hover:bg-gray-100'} cursor-pointer">
                            <div class="flex items-center justify-between">
                                <span class="font-medium">${isActive ? '📄' : '📃'} ${escapeHtml(item.name)}</span>
                                <span class="text-xs text-gray-400">${index + 1}</span>
                            </div>
                            <div class="text-xs text-gray-500 mt-1">${escapeHtml(item.path)}</div>
                            <div class="text-xs text-gray-400 mt-0.5">${timeAgo}</div>
                        </a>
                    </div>
                `;
            });

            fileList.innerHTML = html;
        }

        function clearHistory() {
            if (confirm('Clear all file history?')) {
                localStorage.removeItem('fileHistory');
                displayHistory();
            }
        }

        function getTimeAgo(timestamp) {
            const seconds = Math.floor((Date.now() - timestamp) / 1000);

            if (seconds < 60) return 'just now';
            if (seconds < 3600) return Math.floor(seconds / 60) + ' min ago';
            if (seconds < 86400) return Math.floor(seconds / 3600) + ' hr ago';
            if (seconds < 604800) return Math.floor(seconds / 86400) + ' days ago';
            return new Date(timestamp).toLocaleDateString();
        }

        // Add current file to history on page load
        addToHistory(currentFile);

        // ===== BOOKMARKS =====

        function toggleBookmark(filePath) {
            let bookmarks = JSON.parse(localStorage.getItem('fileBookmarks') || '[]');
            const index = bookmarks.findIndex(b => b.path === filePath);

            if (index >= 0) {
                // Remove bookmark
                bookmarks.splice(index, 1);
                announceToScreenReader('Bookmark removed');
            } else {
                // Add bookmark
                bookmarks.push({
                    path: filePath,
                    name: filePath.split('/').pop(),
                    timestamp: Date.now()
                });
                announceToScreenReader('Bookmark added');
            }

            localStorage.setItem('fileBookmarks', JSON.stringify(bookmarks));
            updateBookmarkIcons();

            // If we're on the bookmarks tab, refresh the display
            if (currentSearchMode === 'bookmarks') {
                displayBookmarks();
            }
        }

        function updateBookmarkIcons() {
            const bookmarks = JSON.parse(localStorage.getItem('fileBookmarks') || '[]');
            const bookmarkPaths = bookmarks.map(b => b.path);

            document.querySelectorAll('.bookmark-btn').forEach(btn => {
                const filePath = btn.getAttribute('data-file');
                if (bookmarkPaths.includes(filePath)) {
                    btn.classList.add('bookmarked');
                    btn.classList.remove('opacity-0');
                    btn.title = 'Remove bookmark';
                } else {
                    btn.classList.remove('bookmarked');
                    btn.title = 'Bookmark this file';
                }
            });
        }

        function displayBookmarks() {
            const bookmarks = JSON.parse(localStorage.getItem('fileBookmarks') || '[]');
            const bookmarksList = document.getElementById('bookmarksList');
            const emptyMessage = document.getElementById('emptyBookmarks');

            if (bookmarks.length === 0) {
                bookmarksList.innerHTML = '';
                emptyMessage.classList.remove('hidden');
                return;
            }

            emptyMessage.classList.add('hidden');

            // Sort by timestamp (newest first)
            bookmarks.sort((a, b) => b.timestamp - a.timestamp);

            let html = '';
            bookmarks.forEach((item, index) => {
                const isActive = item.path === currentFile;

                html += `
                    <div class="flex items-center gap-2 group">
                        <a href="?file=${encodeURIComponent(item.path)}"
                           onclick="loadFile('${escapeHtml(item.path)}'); return false;"
                           class="flex-1 block px-3 py-2 rounded text-xs transition-colors ${isActive ? 'bg-blue-50 text-blue-700 font-medium' : 'text-gray-700 hover:bg-gray-100'} cursor-pointer">
                            <div class="flex items-center gap-2">
                                <span>${isActive ? '📄' : '📃'}</span>
                                <div class="flex-1">
                                    <div class="font-medium">${escapeHtml(item.name)}</div>
                                    <div class="text-xs text-gray-500">${escapeHtml(item.path)}</div>
                                </div>
                            </div>
                        </a>
                        <button onclick="toggleBookmark('${escapeHtml(item.path)}')"
                                class="p-1 text-yellow-500 hover:text-red-500 opacity-0 group-hover:opacity-100 transition-opacity"
                                title="Remove bookmark">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                            </svg>
                        </button>
                    </div>
                `;
            });

            bookmarksList.innerHTML = html;
        }

        function clearBookmarks() {
            if (confirm('Clear all bookmarks?')) {
                localStorage.removeItem('fileBookmarks');
                displayBookmarks();
                updateBookmarkIcons();
                announceToScreenReader('All bookmarks cleared');
            }
        }

        // Initialize bookmark icons on page load
        updateBookmarkIcons();

        // ===== NAVIGATION & ORGANIZATION =====

        // Keyboard navigation for file list
        let currentFocusedFileIndex = -1;

        function handleFileListKeyNav(event) {
            const fileItems = Array.from(document.querySelectorAll('.file-item:not([style*="display: none"])'));

            if (fileItems.length === 0) return;

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                currentFocusedFileIndex = Math.min(currentFocusedFileIndex + 1, fileItems.length - 1);
                focusFileItem(fileItems[currentFocusedFileIndex]);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                currentFocusedFileIndex = Math.max(currentFocusedFileIndex - 1, 0);
                focusFileItem(fileItems[currentFocusedFileIndex]);
            } else if (event.key === 'Enter' && currentFocusedFileIndex >= 0) {
                event.preventDefault();
                fileItems[currentFocusedFileIndex].click();
            }
        }

        function focusFileItem(item) {
            // Remove previous focus
            document.querySelectorAll('.file-item.keyboard-focus').forEach(el => {
                el.classList.remove('keyboard-focus');
            });

            // Add focus to current item
            item.classList.add('keyboard-focus');
            item.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            item.setAttribute('aria-current', 'location');

            // Announce to screen readers
            const fileName = item.textContent.trim();
            announceToScreenReader(`Focused on ${fileName}`);
        }

        // Screen reader announcements
        function announceToScreenReader(message) {
            let announcer = document.getElementById('srAnnouncer');
            if (!announcer) {
                announcer = document.createElement('div');
                announcer.id = 'srAnnouncer';
                announcer.className = 'sr-only';
                announcer.setAttribute('role', 'status');
                announcer.setAttribute('aria-live', 'polite');
                announcer.setAttribute('aria-atomic', 'true');
                document.body.appendChild(announcer);
            }
            announcer.textContent = message;
            setTimeout(() => announcer.textContent = '', 1000);
        }

        // Toggle all folders
        function toggleAllFolders(expand) {
            const folderContents = document.querySelectorAll('.folder-content');
            const folderIcons = document.querySelectorAll('.folder-icon');

            folderContents.forEach((content) => {
                if (expand) {
                    content.classList.remove('collapsed');
                } else {
                    content.classList.add('collapsed');
                }
            });

            folderIcons.forEach((icon) => {
                if (expand) {
                    icon.style.transform = 'rotate(90deg)';
                } else {
                    icon.style.transform = 'rotate(0deg)';
                }
            });
        }

        // Sort files
        function sortFiles(sortBy) {
            const fileList = document.getElementById('fileList');
            const folderContainers = Array.from(fileList.querySelectorAll('.folder-container'));

            folderContainers.forEach(container => {
                const fileItems = Array.from(container.querySelectorAll('.file-item'));
                const folderContent = container.querySelector('.folder-content');

                fileItems.sort((a, b) => {
                    const nameA = a.getAttribute('data-filename');
                    const nameB = b.getAttribute('data-filename');

                    if (sortBy === 'name') {
                        return nameA.localeCompare(nameB);
                    } else if (sortBy === 'name-desc') {
                        return nameB.localeCompare(nameA);
                    } else if (sortBy === 'modified') {
                        // For now, sort by name as we don't have modification dates in the DOM
                        // This would require fetching file metadata from the server
                        return nameA.localeCompare(nameB);
                    }
                    return 0;
                });

                // Re-append sorted items
                fileItems.forEach(item => folderContent.appendChild(item));
            });
        }

        // Jump to top button
        const jumpToTopBtn = document.getElementById('jumpToTop');
        const contentArea = document.getElementById('contentArea');

        if (contentArea) {
            contentArea.addEventListener('scroll', function() {
                if (contentArea.scrollTop > 300) {
                    jumpToTopBtn.style.opacity = '1';
                    jumpToTopBtn.style.pointerEvents = 'auto';
                } else {
                    jumpToTopBtn.style.opacity = '0';
                    jumpToTopBtn.style.pointerEvents = 'none';
                }
            });
        }

        function scrollToTop() {
            const contentArea = document.getElementById('contentArea');
            if (contentArea) {
                contentArea.scrollTo({ top: 0, behavior: 'smooth' });
            }
        }

        // Add keyboard shortcut for jump to top
        document.addEventListener('keydown', function(e) {
            // Home key to jump to top
            if (e.key === 'Home' && !e.ctrlKey && !e.metaKey) {
                const activeElement = document.activeElement;
                // Don't trigger if user is typing in an input
                if (activeElement.tagName !== 'INPUT' && activeElement.tagName !== 'TEXTAREA') {
                    e.preventDefault();
                    scrollToTop();
                }
            }
        });

        // ===== DEVELOPER EXPERIENCE & ADVANCED FEATURES =====

        // Export to HTML
        function exportToHTML() {
            const prose = document.getElementById('proseContent');
            const fileName = currentFile.replace('.md', '.html');

            const html = `<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>${escapeHtml(currentFile)}</title>
    <script src="https://cdn.tailwindcss.com"><\/script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"><\/script>
    <script src="https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.min.js"><\/script>
    <style>
        .prose { max-width: 65ch; margin: 0 auto; padding: 2rem; }
    </style>
</head>
<body class="bg-gray-50">
    <div class="prose text-gray-800">
        ${prose.innerHTML}
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            hljs.highlightAll();
            if (typeof mermaid !== 'undefined') {
                mermaid.initialize({ startOnLoad: true });
            }
        });
    <\/script>
</body>
</html>`;

            const blob = new Blob([html], { type: 'text/html' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = fileName;
            a.click();
            URL.revokeObjectURL(url);
        }

        // Print page function
        function printPage() {
            // Set the filename attribute for the print header
            const prose = document.getElementById('proseContent');
            prose.setAttribute('data-filename', currentFile);

            // Trigger print dialog
            window.print();
        }

        // ===== PRESENTATION MODE =====
        let presentationSlides = [];
        let currentSlideIndex = 0;

        function enterPresentationMode() {
            const prose = document.getElementById('proseContent');
            const overlay = document.getElementById('presentationOverlay');

            // Create slides from content
            // Split by h1 and h2 headers
            const content = prose.cloneNode(true);
            const elements = Array.from(content.children);
            presentationSlides = [];
            let currentSlide = [];

            elements.forEach((el, index) => {
                if ((el.tagName === 'H1' || el.tagName === 'H2') && currentSlide.length > 0) {
                    // Start new slide
                    presentationSlides.push(currentSlide);
                    currentSlide = [el];
                } else {
                    currentSlide.push(el);
                }
            });

            // Add last slide
            if (currentSlide.length > 0) {
                presentationSlides.push(currentSlide);
            }

            // If no slides created, make the whole content one slide
            if (presentationSlides.length === 0) {
                presentationSlides = [elements];
            }

            currentSlideIndex = 0;
            showSlide(0);
            overlay.classList.remove('hidden');

            announceToScreenReader('Entered presentation mode');
        }

        function exitPresentationMode() {
            const overlay = document.getElementById('presentationOverlay');
            overlay.classList.add('hidden');
            presentationSlides = [];
            currentSlideIndex = 0;

            announceToScreenReader('Exited presentation mode');
        }

        function showSlide(index) {
            if (index < 0 || index >= presentationSlides.length) return;

            currentSlideIndex = index;
            const slideContent = document.getElementById('slideContent');
            const slideCounter = document.getElementById('slideCounter');

            // Clear and populate slide content
            slideContent.innerHTML = '';
            presentationSlides[index].forEach(el => {
                slideContent.appendChild(el.cloneNode(true));
            });

            // Update counter
            slideCounter.textContent = `${index + 1} / ${presentationSlides.length}`;

            // Re-highlight code blocks
            if (typeof hljs !== 'undefined') {
                slideContent.querySelectorAll('pre code').forEach(block => {
                    hljs.highlightElement(block);
                });
            }

            // Re-render Mermaid diagrams
            if (typeof mermaid !== 'undefined') {
                slideContent.querySelectorAll('.mermaid').forEach((el, i) => {
                    el.removeAttribute('data-processed');
                });
                mermaid.init(undefined, slideContent.querySelectorAll('.mermaid'));
            }

            announceToScreenReader(`Slide ${index + 1} of ${presentationSlides.length}`);
        }

        function nextSlide() {
            if (currentSlideIndex < presentationSlides.length - 1) {
                showSlide(currentSlideIndex + 1);
            }
        }

        function previousSlide() {
            if (currentSlideIndex > 0) {
                showSlide(currentSlideIndex - 1);
            }
        }

        // Presentation mode keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            const overlay = document.getElementById('presentationOverlay');
            if (!overlay.classList.contains('hidden')) {
                if (e.key === 'ArrowRight' || e.key === ' ' || e.key === 'PageDown') {
                    e.preventDefault();
                    nextSlide();
                } else if (e.key === 'ArrowLeft' || e.key === 'PageUp') {
                    e.preventDefault();
                    previousSlide();
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    exitPresentationMode();
                } else if (e.key === 'Home') {
                    e.preventDefault();
                    showSlide(0);
                } else if (e.key === 'End') {
                    e.preventDefault();
                    showSlide(presentationSlides.length - 1);
                }
            } else {
                // F5 to enter presentation mode when not in it
                if (e.key === 'F5') {
                    e.preventDefault();
                    enterPresentationMode();
                }
            }
        });

        // ===== FILE COMPARISON =====
        let compareFiles = [];
        let isCompareMode = false;

        function enterCompareMode() {
            isCompareMode = true;
            compareFiles = [];
            document.getElementById('enterCompareModeBtn').classList.add('hidden');
            document.getElementById('compareMode').classList.remove('hidden');
            updateCompareStatus();

            // Add checkboxes to file items
            document.querySelectorAll('.file-item-wrapper').forEach(wrapper => {
                const fileItem = wrapper.querySelector('.file-item');
                const filePath = fileItem.getAttribute('data-file');
                if (!wrapper.querySelector('.compare-checkbox')) {
                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.className = 'compare-checkbox mr-2 absolute left-2 top-1/2 -translate-y-1/2';
                    checkbox.onchange = (e) => toggleFileForComparison(filePath, e.target.checked);
                    wrapper.insertBefore(checkbox, wrapper.firstChild);
                    // Add padding to file item to make room for checkbox
                    fileItem.style.paddingLeft = '2rem';
                }
            });

            announceToScreenReader('Entered compare mode. Select two files to compare.');
        }

        function cancelCompareMode() {
            isCompareMode = false;
            compareFiles = [];
            document.getElementById('enterCompareModeBtn').classList.remove('hidden');
            document.getElementById('compareMode').classList.add('hidden');

            // Remove checkboxes and reset padding
            document.querySelectorAll('.compare-checkbox').forEach(cb => cb.remove());
            document.querySelectorAll('.file-item').forEach(item => {
                item.style.paddingLeft = '';
            });

            announceToScreenReader('Compare mode cancelled');
        }

        function toggleFileForComparison(filePath, checked) {
            if (checked) {
                if (compareFiles.length < 2) {
                    compareFiles.push(filePath);
                } else {
                    // Uncheck this one if we already have 2
                    event.target.checked = false;
                    return;
                }
            } else {
                compareFiles = compareFiles.filter(f => f !== filePath);
            }

            updateCompareStatus();
        }

        function updateCompareStatus() {
            const status = document.getElementById('compareStatus');
            const btn = document.getElementById('compareBtn');

            if (compareFiles.length === 0) {
                status.textContent = 'Select two files to compare';
                btn.disabled = true;
            } else if (compareFiles.length === 1) {
                status.textContent = `Selected: ${compareFiles[0]}. Select one more.`;
                btn.disabled = true;
            } else {
                status.textContent = `Ready to compare: ${compareFiles[0]} ↔ ${compareFiles[1]}`;
                btn.disabled = false;
            }
        }

        async function showComparison() {
            if (compareFiles.length !== 2) return;

            const overlay = document.getElementById('comparisonOverlay');
            const file1Name = document.getElementById('compareFile1Name');
            const file2Name = document.getElementById('compareFile2Name');
            const file1Content = document.getElementById('compareFile1Content');
            const file2Content = document.getElementById('compareFile2Content');

            // Set file names
            file1Name.textContent = compareFiles[0];
            file2Name.textContent = compareFiles[1];

            // Show loading
            file1Content.innerHTML = '<div class="text-gray-500">Loading...</div>';
            file2Content.innerHTML = '<div class="text-gray-500">Loading...</div>';

            // Show overlay
            overlay.classList.remove('hidden');

            try {
                // Fetch both files
                const [response1, response2] = await Promise.all([
                    fetch(`index.php?api=content&file=${encodeURIComponent(compareFiles[0])}`),
                    fetch(`index.php?api=content&file=${encodeURIComponent(compareFiles[1])}`)
                ]);

                const [data1, data2] = await Promise.all([
                    response1.json(),
                    response2.json()
                ]);

                if (data1.success && data2.success) {
                    file1Content.innerHTML = data1.html;
                    file2Content.innerHTML = data2.html;

                    // Re-apply syntax highlighting
                    if (typeof hljs !== 'undefined') {
                        file1Content.querySelectorAll('pre code').forEach(block => hljs.highlightElement(block));
                        file2Content.querySelectorAll('pre code').forEach(block => hljs.highlightElement(block));
                    }

                    // Re-render Mermaid diagrams
                    if (typeof mermaid !== 'undefined') {
                        mermaid.init(undefined, file1Content.querySelectorAll('.mermaid'));
                        mermaid.init(undefined, file2Content.querySelectorAll('.mermaid'));
                    }

                    announceToScreenReader('Comparison loaded');
                } else {
                    file1Content.innerHTML = '<div class="text-red-500">Error loading file</div>';
                    file2Content.innerHTML = '<div class="text-red-500">Error loading file</div>';
                }
            } catch (error) {
                console.error('Comparison error:', error);
                file1Content.innerHTML = '<div class="text-red-500">Error loading file</div>';
                file2Content.innerHTML = '<div class="text-red-500">Error loading file</div>';
            }
        }

        function exitComparisonMode() {
            document.getElementById('comparisonOverlay').classList.add('hidden');
            announceToScreenReader('Exited comparison view');
        }

        // Toggle raw markdown view
        let showingRawMarkdown = false;
        let rawMarkdownContent = '';

        async function toggleRawMarkdown() {
            const proseContent = document.getElementById('proseContent');
            const rawView = document.getElementById('rawMarkdownView');
            const btn = document.getElementById('rawMdBtn');

            if (!showingRawMarkdown) {
                // Fetch raw markdown
                try {
                    const response = await fetch(`${currentFile}`);
                    rawMarkdownContent = await response.text();
                    rawView.textContent = rawMarkdownContent;
                    proseContent.classList.add('hidden-for-raw');
                    rawView.classList.add('active');
                    btn.classList.add('bg-blue-100', 'text-blue-700');
                    showingRawMarkdown = true;
                } catch (error) {
                    console.error('Failed to load raw markdown:', error);
                }
            } else {
                // Show rendered view
                proseContent.classList.remove('hidden-for-raw');
                rawView.classList.remove('active');
                btn.classList.remove('bg-blue-100', 'text-blue-700');
                showingRawMarkdown = false;
            }
        }

        // Toggle line numbers
        let showingLineNumbers = false;

        function toggleLineNumbers() {
            const prose = document.getElementById('proseContent');
            const btn = document.getElementById('lineNumBtn');

            if (!showingLineNumbers) {
                // Add line numbers to each code block
                prose.querySelectorAll('pre').forEach(pre => {
                    const code = pre.querySelector('code');
                    if (code) {
                        // Count the number of lines
                        const text = code.textContent;
                        const lineCount = text.split('\n').length;

                        // Generate line numbers string
                        const lineNumbers = Array.from({length: lineCount}, (_, i) => i + 1).join('\n');
                        pre.setAttribute('data-line-numbers', lineNumbers);
                    }
                });

                prose.classList.add('show-line-numbers');
                btn.classList.add('bg-blue-100', 'text-blue-700');
                showingLineNumbers = true;
            } else {
                // Remove line numbers
                prose.querySelectorAll('pre').forEach(pre => {
                    pre.removeAttribute('data-line-numbers');
                });

                prose.classList.remove('show-line-numbers');
                btn.classList.remove('bg-blue-100', 'text-blue-700');
                showingLineNumbers = false;
            }
        }
    </script>
</body>
</html>
