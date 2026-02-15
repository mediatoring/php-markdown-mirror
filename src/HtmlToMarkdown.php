<?php
declare(strict_types=1);

/**
 * HtmlToMarkdown – Structural HTML-to-Markdown converter via DOMDocument.
 *
 * Features:
 *  - Schema.org JSON-LD extraction → YAML frontmatter
 *  - Full tag coverage (headings, lists, tables, inline formatting, semantic tags)
 *  - Universal heuristic filtering (buttons, nav, widgets, icons…)
 *  - Single DOM parse when used with MarkdownMiddleware
 *  - No Composer, no external dependencies, pure PHP 7.4+
 *
 * Usage:
 *   $converter = new HtmlToMarkdown();
 *   $markdown  = $converter->convert('<h1>Hello</h1><p>World</p>');
 *   // or pass a DOMDocument + DOMElement to avoid double parsing:
 *   $markdown  = $converter->convertDomElement($element, $document);
 */
class HtmlToMarkdown
{
    /** @var array<string, string> Inline tag → Markdown marker */
    private array $inlineTags = [
        'strong' => '**',
        'b'      => '**',
        'em'     => '*',
        'i'      => '*',
        'del'    => '~~',
        's'      => '~~',
        'code'   => '`',
        'kbd'    => '`',
        'samp'   => '`',
        'mark'   => '==',
        'cite'   => '*',
        'var'    => '*',
        'dfn'    => '*',
    ];

    /** @var array<string, true> Tags always skipped – O(1) lookup */
    private array $skipTagsMap;

    /** @var array<string, true> ARIA roles to skip – O(1) lookup */
    private array $skipRolesMap;

    /** @var list<string> CSS class fragments typical for UI widgets */
    private array $skipClassFragments = [
        'btn', 'button', 'cta', 'countdown', 'timer', 'cookie',
        'popup', 'modal', 'overlay', 'widget', 'social-share',
        'breadcrumb', 'pagination', 'sidebar',
    ];

    /** @var int Tracks <pre> nesting depth to avoid upward DOM traversal */
    private int $preDepth = 0;

    public function __construct()
    {
        $this->skipTagsMap = array_flip([
            'button', 'form', 'input', 'select', 'textarea', 'label',
            'nav', 'script', 'style', 'noscript', 'svg', 'iframe',
            'video', 'audio', 'canvas', 'map', 'object', 'embed',
        ]);
        $this->skipRolesMap = array_flip([
            'navigation', 'banner', 'complementary', 'contentinfo',
            'search', 'form', 'toolbar', 'menubar', 'menu', 'dialog',
        ]);
    }

    /**
     * Convert an HTML string to Markdown.
     */
    public function convert(string $html): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        try {
            $doc->loadHTML(
                '<html><head><meta charset="UTF-8"></head><body><div id="__md_root__">'
                . $html . '</div></body></html>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
            );
        } finally {
            libxml_clear_errors();
        }
        $root = $doc->getElementById('__md_root__');
        if ($root === null) {
            return trim(strip_tags($html));
        }
        $frontmatter = $this->extractJsonLd($doc);
        $body = $this->finalize($this->processChildren($root));
        return $this->assembleFrontmatter($frontmatter) . $body;
    }

    /**
     * Convert a DOMElement directly (no re-parsing).
     * Pass the owning DOMDocument to enable JSON-LD extraction from <head>.
     */
    public function convertDomElement(\DOMElement $element, ?\DOMDocument $doc = null): string
    {
        $frontmatter = ($doc !== null) ? $this->extractJsonLd($doc) : [];
        $body = $this->finalize($this->processChildren($element));
        return $this->assembleFrontmatter($frontmatter) . $body;
    }

    /**
     * Clean up the raw Markdown output.
     */
    private function finalize(string $md): string
    {
        $md = preg_replace("/^[ \t]+$/m", '', $md);
        $md = preg_replace("/\n{3,}/", "\n\n", $md);
        return trim($md);
    }

    // ──────────────────────────────────────────────────────────────
    //  Schema.org JSON-LD → YAML frontmatter
    // ──────────────────────────────────────────────────────────────

    /**
     * Extract all JSON-LD blocks from <script type="application/ld+json">.
     * @return list<array<string, mixed>>
     */
    private function extractJsonLd(\DOMDocument $doc): array
    {
        $results = [];
        $scripts = $doc->getElementsByTagName('script');
        foreach ($scripts as $script) {
            if (!($script instanceof \DOMElement)) {
                continue;
            }
            if (strtolower($script->getAttribute('type')) !== 'application/ld+json') {
                continue;
            }
            $json = trim($script->textContent);
            if ($json === '') {
                continue;
            }
            $data = json_decode($json, true, 64, JSON_BIGINT_AS_STRING);
            if (!is_array($data)) {
                continue;
            }
            if (isset($data['@graph']) && is_array($data['@graph'])) {
                foreach ($data['@graph'] as $item) {
                    if (is_array($item)) {
                        $results[] = $item;
                    }
                }
            } else {
                $results[] = $data;
            }
        }
        return $results;
    }

    /**
     * Render JSON-LD data as YAML-like frontmatter block.
     */
    private function assembleFrontmatter(array $items): string
    {
        if ($items === []) {
            return '';
        }
        $lines = ['---'];
        foreach ($items as $index => $item) {
            if ($index > 0) {
                $lines[] = '';
            }
            $this->renderYamlItem($item, 0, $lines);
        }
        $lines[] = '---';
        $lines[] = '';
        $lines[] = '';
        return implode("\n", $lines);
    }

    /**
     * Recursively render a JSON structure as YAML-like text.
     * @param list<string> &$lines
     */
    private function renderYamlItem($data, int $indent, array &$lines, string $prefix = ''): void
    {
        if (!is_array($data)) {
            $val = $this->yamlScalar($data);
            $lines[] = str_repeat('  ', $indent) . $prefix . $val;
            return;
        }
        $isSequential = array_keys($data) === range(0, count($data) - 1);
        if ($isSequential) {
            foreach ($data as $item) {
                if (is_array($item)) {
                    $lines[] = str_repeat('  ', $indent) . $prefix . '-';
                    $this->renderYamlItem($item, $indent + 1, $lines);
                } else {
                    $lines[] = str_repeat('  ', $indent) . $prefix . '- ' . $this->yamlScalar($item);
                }
            }
        } else {
            $skip = ['@context', '@id', '@graph'];
            foreach ($data as $key => $value) {
                if (in_array($key, $skip, true)) {
                    continue;
                }
                $keyStr = (string) $key;
                if (is_array($value)) {
                    $lines[] = str_repeat('  ', $indent) . $prefix . $keyStr . ':';
                    $this->renderYamlItem($value, $indent + 1, $lines);
                } else {
                    $lines[] = str_repeat('  ', $indent) . $prefix . $keyStr . ': ' . $this->yamlScalar($value);
                }
            }
        }
    }

    private function yamlScalar($value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        $str = (string) $value;
        if (preg_match('/[:#\[\]{}&*!|>\'"%@`,\n]/', $str) || $str === '') {
            return '"' . addcslashes($str, "\"\\\n") . '"';
        }
        return $str;
    }

    // ──────────────────────────────────────────────────────────────
    //  DOM traversal
    // ──────────────────────────────────────────────────────────────

    private function processChildren(\DOMNode $node): string
    {
        $parts = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $text = $child->textContent;
                if ($this->preDepth === 0) {
                    $text = preg_replace('/[ \t]+/', ' ', $text);
                }
                $parts[] = $text;
                continue;
            }
            if ($child instanceof \DOMComment || !($child instanceof \DOMElement)) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if ($this->shouldSkip($child, $tag)) {
                continue;
            }
            $parts[] = $this->handleElement($child, $tag);
        }
        return implode('', $parts);
    }

    private function shouldSkip(\DOMElement $el, string $tag): bool
    {
        if ($el->hasAttribute('data-md-skip')) {
            return true;
        }
        if (isset($this->skipTagsMap[$tag])) {
            return true;
        }
        $role = strtolower($el->getAttribute('role'));
        if ($role !== '' && isset($this->skipRolesMap[$role])) {
            return true;
        }
        if ($el->getAttribute('aria-hidden') === 'true') {
            return true;
        }
        $class = strtolower($el->getAttribute('class'));
        if ($class !== '') {
            foreach ($this->skipClassFragments as $fragment) {
                if (strpos($class, $fragment) !== false) {
                    return true;
                }
            }
        }
        if ($tag === 'img') {
            $src = strtolower($el->getAttribute('src'));
            if (strpos($src, '/icon') !== false || strpos($src, 'icon.') !== false) {
                return true;
            }
            $w = (int) $el->getAttribute('width');
            $h = (int) $el->getAttribute('height');
            if (($w > 0 && $w < 50) || ($h > 0 && $h < 50)) {
                return true;
            }
            if (preg_match('/\.svg(\?|$)/', $src)) {
                return true;
            }
        }
        return false;
    }

    // ──────────────────────────────────────────────────────────────
    //  Element conversion
    // ──────────────────────────────────────────────────────────────

    private function handleElement(\DOMElement $el, string $tag): string
    {
        if (preg_match('/^h([1-6])$/', $tag, $m)) {
            $prefix = str_repeat('#', (int) $m[1]);
            return "\n\n{$prefix} " . trim($this->processChildren($el)) . "\n\n";
        }
        if ($tag === 'p') {
            $content = trim($this->processChildren($el));
            return ($content === '') ? '' : "\n\n{$content}\n\n";
        }
        if (isset($this->inlineTags[$tag])) {
            $mark = $this->inlineTags[$tag];
            $content = trim($this->processChildren($el));
            return ($content === '') ? '' : "{$mark}{$content}{$mark}";
        }
        if ($tag === 'u' || $tag === 'ins') {
            $content = trim($this->processChildren($el));
            return ($content === '') ? '' : "*{$content}*";
        }
        if ($tag === 'sub') {
            $content = trim($this->processChildren($el));
            return ($content === '') ? '' : "<sub>{$content}</sub>";
        }
        if ($tag === 'sup') {
            $content = trim($this->processChildren($el));
            return ($content === '') ? '' : "<sup>{$content}</sup>";
        }
        if ($tag === 'q') {
            $content = trim($this->processChildren($el));
            return ($content === '') ? '' : "\"{$content}\"";
        }
        if ($tag === 'abbr') {
            $content = trim($this->processChildren($el));
            $title = $el->getAttribute('title');
            return ($title !== '' && $content !== '') ? "{$content} ({$title})" : $content;
        }
        if ($tag === 'time') {
            $content = trim($this->processChildren($el));
            $datetime = $el->getAttribute('datetime');
            return ($datetime !== '' && $content === '') ? $datetime : $content;
        }
        if ($tag === 'small' || $tag === 'address') {
            return $this->processChildren($el);
        }
        if ($tag === 'a') {
            return $this->handleLink($el);
        }
        if ($tag === 'img') {
            return '![' . $el->getAttribute('alt') . '](' . $el->getAttribute('src') . ')';
        }
        if ($tag === 'br') {
            return "  \n";
        }
        if ($tag === 'hr') {
            return "\n\n---\n\n";
        }
        if ($tag === 'blockquote') {
            $content = trim($this->processChildren($el));
            $lines = explode("\n", $content);
            return "\n\n" . implode("\n", array_map(
                static fn(string $line): string => '> ' . $line,
                $lines
            )) . "\n\n";
        }
        if ($tag === 'pre') {
            return $this->handlePre($el);
        }
        if ($tag === 'ul' || $tag === 'ol') {
            return $this->handleList($el, $tag);
        }
        if ($tag === 'li') {
            return trim($this->processChildren($el)) . "\n";
        }
        if ($tag === 'table') {
            return $this->handleTable($el);
        }
        if ($tag === 'figure') {
            return $this->processChildren($el);
        }
        if ($tag === 'figcaption') {
            return "\n\n*" . trim($this->processChildren($el)) . "*\n\n";
        }
        if ($tag === 'dl') {
            return $this->handleDefinitionList($el);
        }
        if ($tag === 'details') {
            return $this->handleDetails($el);
        }
        return $this->processChildren($el);
    }

    private function handleLink(\DOMElement $el): string
    {
        $href = $el->getAttribute('href');
        $content = trim($this->processChildren($el));
        if ($content === '') {
            return '';
        }
        if ($href === '' || $href === '#') {
            return $content;
        }
        $title = $el->getAttribute('title');
        return ($title !== '')
            ? "[{$content}]({$href} \"{$title}\")"
            : "[{$content}]({$href})";
    }

    private function handlePre(\DOMElement $el): string
    {
        $this->preDepth++;
        $codeEl = null;
        foreach ($el->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->tagName) === 'code') {
                $codeEl = $child;
                break;
            }
        }
        if ($codeEl !== null) {
            $lang = $this->detectCodeLanguage($codeEl);
            $content = $codeEl->textContent;
        } else {
            $lang = '';
            $content = $el->textContent;
        }
        $this->preDepth--;
        return "\n\n```{$lang}\n" . rtrim($content) . "\n```\n\n";
    }

    private function handleList(\DOMElement $listEl, string $type, int $depth = 0): string
    {
        $output = '';
        $counter = (int) ($listEl->getAttribute('start') ?: 1);
        $indent = str_repeat('    ', $depth);
        foreach ($listEl->childNodes as $child) {
            if (!($child instanceof \DOMElement) || strtolower($child->tagName) !== 'li') {
                continue;
            }
            $bullet = ($type === 'ol') ? "{$counter}. " : '- ';
            $counter++;
            $liContent = '';
            $nestedLists = '';
            foreach ($child->childNodes as $liChild) {
                if ($liChild instanceof \DOMElement) {
                    $liTag = strtolower($liChild->tagName);
                    if ($liTag === 'ul' || $liTag === 'ol') {
                        $nestedLists .= $this->handleList($liChild, $liTag, $depth + 1);
                        continue;
                    }
                    $liContent .= $this->handleElement($liChild, $liTag);
                } elseif ($liChild instanceof \DOMText) {
                    $text = $liChild->textContent;
                    if ($this->preDepth === 0) {
                        $text = preg_replace('/[ \t]+/', ' ', $text);
                    }
                    $liContent .= $text;
                }
            }
            $output .= "{$indent}{$bullet}" . trim($liContent) . "\n";
            if ($nestedLists !== '') {
                $output .= $nestedLists;
            }
        }
        return ($depth === 0) ? "\n\n{$output}\n" : $output;
    }

    private function handleTable(\DOMElement $table): string
    {
        $rows = [];
        $maxCols = 0;
        foreach ($this->getDirectTableRows($table) as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if (!($cell instanceof \DOMElement)) {
                    continue;
                }
                $cellTag = strtolower($cell->tagName);
                if ($cellTag === 'td' || $cellTag === 'th') {
                    $cells[] = trim($this->processChildren($cell));
                }
            }
            if ($cells !== []) {
                $rows[] = $cells;
                $maxCols = max($maxCols, count($cells));
            }
        }
        if ($rows === []) {
            return '';
        }
        foreach ($rows as &$row) {
            while (count($row) < $maxCols) {
                $row[] = '';
            }
        }
        unset($row);
        $widths = array_fill(0, $maxCols, 3);
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i], mb_strlen($cell, 'UTF-8'));
            }
        }
        $output = "\n\n";
        $output .= $this->formatTableRow($rows[0], $widths) . "\n";
        $output .= '| ' . implode(' | ', array_map(
            static fn(int $w): string => str_repeat('-', $w),
            $widths
        )) . " |\n";
        for ($r = 1, $cnt = count($rows); $r < $cnt; $r++) {
            $output .= $this->formatTableRow($rows[$r], $widths) . "\n";
        }
        return $output . "\n";
    }

    /** @param list<string> $cells @param list<int> $widths */
    private function formatTableRow(array $cells, array $widths): string
    {
        $padded = [];
        foreach ($cells as $i => $cell) {
            $padded[] = $cell . str_repeat(' ', $widths[$i] - mb_strlen($cell, 'UTF-8'));
        }
        return '| ' . implode(' | ', $padded) . ' |';
    }

    /** @return \DOMElement[] */
    private function getDirectTableRows(\DOMElement $table): array
    {
        $rows = [];
        foreach ($table->childNodes as $child) {
            if (!($child instanceof \DOMElement)) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if ($tag === 'tr') {
                $rows[] = $child;
            } elseif ($tag === 'thead' || $tag === 'tbody' || $tag === 'tfoot') {
                foreach ($child->childNodes as $sectionChild) {
                    if ($sectionChild instanceof \DOMElement && strtolower($sectionChild->tagName) === 'tr') {
                        $rows[] = $sectionChild;
                    }
                }
            }
        }
        return $rows;
    }

    private function handleDefinitionList(\DOMElement $dl): string
    {
        $output = "\n\n";
        foreach ($dl->childNodes as $child) {
            if (!($child instanceof \DOMElement)) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if ($tag === 'dt') {
                $output .= '**' . trim($this->processChildren($child)) . "**\n";
            } elseif ($tag === 'dd') {
                $output .= ': ' . trim($this->processChildren($child)) . "\n\n";
            }
        }
        return $output;
    }

    private function handleDetails(\DOMElement $details): string
    {
        $summary = '';
        $body = '';
        foreach ($details->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->tagName) === 'summary') {
                $summary = trim($this->processChildren($child));
            } elseif ($child instanceof \DOMText) {
                $body .= $child->textContent;
            } elseif ($child instanceof \DOMElement) {
                $body .= $this->handleElement($child, strtolower($child->tagName));
            }
        }
        return "\n\n**{$summary}**\n\n" . trim($body) . "\n\n";
    }

    private function detectCodeLanguage(\DOMElement $codeEl): string
    {
        $class = $codeEl->getAttribute('class');
        if (preg_match('/(?:language|lang|highlight)-(\w+)/', $class, $m)) {
            return $m[1];
        }
        return '';
    }
}
