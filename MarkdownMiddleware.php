<?php
declare(strict_types=1);

/**
 * Middleware for dual HTML/Markdown output via content negotiation.
 *
 * Activate in your entry point:
 *   MarkdownMiddleware::register();
 *
 * When the client requests Markdown (via Accept header or ?v=md query param),
 * captures the HTML output, extracts main content and Schema.org JSON-LD,
 * converts everything to Markdown via a single DOM parse, and responds
 * with Content-Type: text/markdown.
 */
final class MarkdownMiddleware
{
    /** Content selectors in priority order: [tag, attribute, value] */
    private const CONTENT_SELECTORS = [
        ['main',    null,    null],
        ['article', null,    null],
        ['div',     'id',    'content'],
        ['div',     'class', 'content'],
        ['div',     'id',    'main'],
        ['div',     'id',    'main-content'],
        ['div',     'class', 'main-content'],
        ['body',    null,    null],
    ];

    /**
     * Detect whether the client is requesting Markdown output.
     */
    public static function isMarkdownRequested(): bool
    {
        if (isset($_GET['v']) && strtolower($_GET['v']) === 'md') {
            return true;
        }
        if (isset($_SERVER['HTTP_ACCEPT'])) {
            if (strpos(strtolower($_SERVER['HTTP_ACCEPT']), 'text/markdown') !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Activate the middleware. Call once at the top of your entry point.
     */
    public static function register(): void
    {
        header('Vary: Accept');

        if (!self::isMarkdownRequested()) {
            return;
        }

        ob_start();

        register_shutdown_function(static function (): void {
            $html = ob_get_clean();
            if ($html === false || trim($html) === '') {
                if (!headers_sent()) {
                    header('Content-Type: text/markdown; charset=utf-8');
                }
                return;
            }

            $doc = new \DOMDocument('1.0', 'UTF-8');
            libxml_use_internal_errors(true);
            try {
                $doc->loadHTML(
                    '<html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>',
                    LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
                );
            } finally {
                libxml_clear_errors();
            }

            $contentElement = self::findContentElement($doc);

            if (!class_exists('HtmlToMarkdown', false)) {
                require_once __DIR__ . '/HtmlToMarkdown.php';
            }
            $converter = new \HtmlToMarkdown();
            $markdown = ($contentElement !== null)
                ? $converter->convertDomElement($contentElement, $doc)
                : $converter->convert($html);

            if (!headers_sent()) {
                header('Content-Type: text/markdown; charset=utf-8');
                header('Vary: Accept');
                header('Cache-Control: no-transform');
            }
            echo $markdown;
        });
    }

    /**
     * Find the main content element using priority-based selectors.
     */
    private static function findContentElement(\DOMDocument $doc): ?\DOMElement
    {
        foreach (self::CONTENT_SELECTORS as [$tag, $attr, $val]) {
            $elements = $doc->getElementsByTagName($tag);
            if ($elements->length === 0) {
                continue;
            }
            foreach ($elements as $el) {
                if ($attr === null) {
                    return $el;
                }
                $attrVal = $el->getAttribute($attr);
                if ($attr === 'class') {
                    if (in_array($val, preg_split('/\s+/', $attrVal, -1, PREG_SPLIT_NO_EMPTY), true)) {
                        return $el;
                    }
                } elseif ($attrVal === $val) {
                    return $el;
                }
            }
        }
        return null;
    }
}
