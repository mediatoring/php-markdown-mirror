<?php
/**
 * Bootstrap for manual installation (without Composer).
 *
 * Add this single line to the top of your index.php:
 *   require_once __DIR__ . '/path/to/markdown_output.php';
 */
require_once __DIR__ . '/src/MarkdownMiddleware.php';
require_once __DIR__ . '/src/HtmlToMarkdown.php';

MarkdownMiddleware::register();
