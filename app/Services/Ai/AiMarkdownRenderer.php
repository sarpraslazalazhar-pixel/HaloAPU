<?php

namespace App\Services\Ai;

/**
 * XSS-safe Markdown-to-HTML converter for PDF exports and server-side rendering.
 * Always escapes HTML first before converting any Markdown syntax.
 */
class AiMarkdownRenderer
{
    public function toHtml(string $markdown): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $markdown);
        $lines = explode("\n", $normalized);

        $htmlBlocks = [];
        $inCodeBlock = false;
        $codeLines = [];
        $tableRows = [];
        $paragraphLines = [];
        $quoteLines = [];
        $listItems = [];
        $listType = null; // 'ul' | 'ol'

        $flushParagraph = function () use (&$paragraphLines, &$htmlBlocks): void {
            if (empty($paragraphLines)) {
                return;
            }
            $rendered = array_map(fn (string $l) => $this->renderInline($l), $paragraphLines);
            $htmlBlocks[] = '<p>' . implode('<br />', $rendered) . '</p>';
            $paragraphLines = [];
        };

        $flushQuote = function () use (&$quoteLines, &$htmlBlocks): void {
            if (empty($quoteLines)) {
                return;
            }
            $rendered = array_map(fn (string $l) => $this->renderInline($l), $quoteLines);
            $htmlBlocks[] = '<blockquote>' . implode('<br />', $rendered) . '</blockquote>';
            $quoteLines = [];
        };

        $flushList = function () use (&$listItems, &$listType, &$htmlBlocks): void {
            if (empty($listItems) || $listType === null) {
                return;
            }
            $tag = $listType;
            $itemsHtml = implode('', array_map(
                fn (string $item) => '<li>' . $this->renderInline($item) . '</li>',
                $listItems
            ));
            $htmlBlocks[] = "<{$tag}>{$itemsHtml}</{$tag}>";
            $listItems = [];
            $listType = null;
        };

        $flushTable = function () use (&$tableRows, &$htmlBlocks): void {
            if (empty($tableRows)) {
                return;
            }

            $rows = $tableRows;
            $tableRows = [];

            $header = array_shift($rows);
            $bodyRows = [];
            foreach ($rows as $idx => $cols) {
                if ($idx === 0 && $this->isTableSeparator($cols)) {
                    continue;
                }
                $bodyRows[] = $cols;
            }

            $thead = '<thead><tr>' . implode('', array_map(
                fn (string $cell) => '<th>' . $this->renderInline(trim($cell)) . '</th>',
                $header
            )) . '</tr></thead>';

            $tbody = '';
            if (! empty($bodyRows)) {
                $tbodyRows = array_map(function (array $cols): string {
                    return '<tr>' . implode('', array_map(
                        fn (string $cell) => '<td>' . $this->renderInline(trim($cell)) . '</td>',
                        $cols
                    )) . '</tr>';
                }, $bodyRows);
                $tbody = '<tbody>' . implode('', $tbodyRows) . '</tbody>';
            }

            $htmlBlocks[] = '<table class="md-table">' . $thead . $tbody . '</table>';
        };

        $flushAllNonCode = function () use ($flushParagraph, $flushQuote, $flushList, $flushTable): void {
            $flushParagraph();
            $flushQuote();
            $flushList();
            $flushTable();
        };

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '```')) {
                if ($inCodeBlock) {
                    $escapedCode = $this->escapeHtml(implode("\n", $codeLines));
                    $htmlBlocks[] = '<pre class="md-code"><code>' . $escapedCode . '</code></pre>';
                    $codeLines = [];
                    $inCodeBlock = false;
                } else {
                    $flushAllNonCode();
                    $inCodeBlock = true;
                }
                continue;
            }

            if ($inCodeBlock) {
                $codeLines[] = $line;
                continue;
            }

            if (str_starts_with($trimmed, '|') && str_ends_with($trimmed, '|') && strlen($trimmed) > 1) {
                $flushParagraph();
                $flushQuote();
                $flushList();
                $cols = array_slice(explode('|', $trimmed), 1, -1);
                $tableRows[] = $cols;
                continue;
            }
            $flushTable();

            if ($trimmed === '') {
                $flushParagraph();
                $flushQuote();
                $flushList();
                continue;
            }

            if (preg_match('/^(#{1,4})\s+(.+)$/', $trimmed, $m)) {
                $flushParagraph();
                $flushQuote();
                $flushList();
                $level = strlen($m[1]);
                $htmlBlocks[] = "<h{$level}>" . $this->renderInline($m[2]) . "</h{$level}>";
                continue;
            }

            if (preg_match('/^([-*_])\1{2,}$/', $trimmed)) {
                $flushParagraph();
                $flushQuote();
                $flushList();
                $htmlBlocks[] = '<hr />';
                continue;
            }

            if (str_starts_with($trimmed, '> ') || $trimmed === '>') {
                $flushParagraph();
                $flushList();
                $quoteLines[] = $trimmed === '>' ? '' : substr($trimmed, 2);
                continue;
            }
            $flushQuote();

            if (preg_match('/^[-*+]\s+(.+)$/', $trimmed, $m)) {
                $flushParagraph();
                if ($listType !== 'ul') {
                    $flushList();
                    $listType = 'ul';
                }
                $listItems[] = $m[1];
                continue;
            }

            if (preg_match('/^\d+\.\s+(.+)$/', $trimmed, $m)) {
                $flushParagraph();
                if ($listType !== 'ol') {
                    $flushList();
                    $listType = 'ol';
                }
                $listItems[] = $m[1];
                continue;
            }

            $flushList();
            $paragraphLines[] = $trimmed;
        }

        if ($inCodeBlock && ! empty($codeLines)) {
            $escapedCode = $this->escapeHtml(implode("\n", $codeLines));
            $htmlBlocks[] = '<pre class="md-code"><code>' . $escapedCode . '</code></pre>';
        }
        $flushAllNonCode();

        return implode("\n", $htmlBlocks);
    }

    /**
     * Escape raw HTML first, then convert inline Markdown tokens safely.
     */
    private function renderInline(string $text): string
    {
        $escaped = $this->escapeHtml($text);

        // 1. Protect inline code spans first
        $codePlaceholders = [];
        $escaped = (string) preg_replace_callback('/`([^`]+)`/', function (array $m) use (&$codePlaceholders): string {
            $token = '%%CODE_' . count($codePlaceholders) . '%%';
            $codePlaceholders[$token] = '<code class="md-inline-code">' . $m[1] . '</code>';

            return $token;
        }, $escaped);

        // 2. Convert safe Markdown links [label](url)
        $linkPlaceholders = [];
        $escaped = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function (array $m) use (&$linkPlaceholders): string {
            $label = $m[1];
            $rawUrl = html_entity_decode($m[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            if (! $this->isSafeUrl($rawUrl)) {
                return $label;
            }

            $safeHref = $this->escapeHtml($rawUrl);
            $token = '%%LINK_' . count($linkPlaceholders) . '%%';
            $linkPlaceholders[$token] = '<a href="' . $safeHref . '">' . $label . '</a>';

            return $token;
        }, $escaped);

        // 3. Bold (**text** or __text__)
        $escaped = (string) preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped);
        $escaped = (string) preg_replace('/__(.+?)__/', '<strong>$1</strong>', $escaped);

        // 4. Italic (*text*)
        $escaped = (string) preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $escaped);

        // 5. Restore placeholders
        if (! empty($linkPlaceholders)) {
            $escaped = strtr($escaped, $linkPlaceholders);
        }
        if (! empty($codePlaceholders)) {
            $escaped = strtr($escaped, $codePlaceholders);
        }

        return $escaped;
    }

    private function isSafeUrl(string $url): bool
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return false;
        }

        // Allow single-slash internal paths (reject protocol-relative '//...')
        if (str_starts_with($trimmed, '/')) {
            return ! str_starts_with($trimmed, '//')
                && (bool) preg_match('/^\/[a-zA-Z0-9_\-\/\?\=\&\.#%~:]*$/', $trimmed);
        }

        // Allow only explicit http/https URLs
        if (preg_match('/^https?:\/\/[^\s<>"\']+$/i', $trimmed)) {
            return filter_var($trimmed, FILTER_VALIDATE_URL) !== false;
        }

        return false;
    }

    /**
     * @param  array<int, string>  $cols
     */
    private function isTableSeparator(array $cols): bool
    {
        foreach ($cols as $col) {
            if (! preg_match('/^[:\-\s]+$/', trim($col))) {
                return false;
            }
        }

        return true;
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
