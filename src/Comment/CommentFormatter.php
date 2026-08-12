<?php

namespace Ado\Comment;

class CommentFormatter
{
    /**
     * Matches an @mention: "@" followed by one or more capitalised, space-separated
     * words (e.g. "@John", "@John Doe", "@Johannes Hejslet Jørgensen"). Lowercase
     * connector words stop the run, so "@John Doe and @Jane" yields two mentions.
     * The whole mention (including "@") is captured so preg_split keeps it.
     */
    private const MENTION_PATTERN = '/(@\p{Lu}[\p{L}.\'-]*(?:\s+\p{Lu}[\p{L}.\'-]*)*)/u';

    /** @var list<string> Display names resolved during the last format() call. */
    private array $lastResolvedNames = [];

    /**
     * Build the comment HTML for the ADO comments API.
     *
     * $comment is treated as plain text, not HTML: every part of it other than
     * a recognized "@Name" mention is run through htmlspecialchars(). Passing
     * markup here renders as literal escaped tags in the RTE, not real HTML —
     * unlike the description field, which the API accepts as raw HTML.
     *
     * @param callable(string):?string $resolver Maps a display name to its tfid
     *                                            (storageKey), or null if unknown.
     */
    public function format(string $comment, callable $resolver): string
    {
        $this->lastResolvedNames = [];

        $parts = preg_split(
            self::MENTION_PATTERN,
            $comment,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        $html = '';
        foreach ($parts as $i => $part) {
            // Odd indices are the captured mentions ("@Name"); even are literal text.
            if ($i % 2 === 0) {
                $html .= $this->escape($part);
                continue;
            }

            $name = substr($part, 1); // strip leading "@"
            $tfid = $resolver($name);
            if ($tfid === null) {
                // Unknown user: leave the literal text untouched.
                $html .= $this->escape($part);
                continue;
            }

            $this->lastResolvedNames[] = $name;
            $html .= sprintf(
                '<a href="#" data-vss-mention="version:2.0,%s">@%s</a>',
                $this->escape($tfid),
                $this->escape($name)
            );
        }

        return '<div>' . $html . '</div>';
    }

    /** @return list<string> */
    public function lastResolvedNames(): array
    {
        return $this->lastResolvedNames;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
