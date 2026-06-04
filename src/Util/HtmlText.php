<?php

namespace Ado\Util;

class HtmlText
{
    public static function convert(string $html, int $width = 100): string
    {
        // Normalize line endings
        $html = str_replace(["\r\n", "\r"], "\n", $html);

        // Block elements → newlines before converting
        $html = preg_replace('/<(p|div|br|h[1-6]|li|tr)[^>]*>/i', "\n", $html);
        $html = preg_replace('/<\/(p|div|h[1-6]|ul|ol|table)>/i', "\n", $html);

        // List items
        $html = preg_replace('/<li[^>]*>/i', "\n  • ", $html);

        // Bold / italic → keep readable
        $html = preg_replace('/<(strong|b)[^>]*>(.*?)<\/(strong|b)>/is', '*$2*', $html);
        $html = preg_replace('/<(em|i)[^>]*>(.*?)<\/(em|i)>/is', '_$2_', $html);

        // Links → text (url)
        $html = preg_replace('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', '$2 ($1)', $html);

        // Mentions (@name links with data-vss-mention)
        $html = preg_replace('/<a[^>]+data-vss-mention[^>]*>(.*?)<\/a>/is', '$1', $html);

        // Strip remaining tags
        $html = strip_tags($html);

        // Decode HTML entities
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Collapse excessive blank lines
        $html = preg_replace('/\n{3,}/', "\n\n", $html);

        // Wrap long lines
        $lines = explode("\n", trim($html));
        $wrapped = array_map(fn($l) => strlen($l) > $width ? wordwrap($l, $width, "\n", false) : $l, $lines);

        return implode("\n", $wrapped);
    }
}
