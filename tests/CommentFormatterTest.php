<?php

namespace Ado\Tests;

use Ado\Comment\CommentFormatter;
use PHPUnit\Framework\TestCase;

class CommentFormatterTest extends TestCase
{
    private function resolver(array $map): callable
    {
        return fn (string $name): ?string => $map[$name] ?? null;
    }

    public function testPlainCommentIsWrappedInDiv(): void
    {
        $f = new CommentFormatter();
        $this->assertSame(
            '<div>Hello world</div>',
            $f->format('Hello world', $this->resolver([]))
        );
    }

    public function testSpecialCharsAreEscaped(): void
    {
        $f = new CommentFormatter();
        $this->assertSame(
            '<div>a &lt; b &amp; c &gt; d</div>',
            $f->format('a < b & c > d', $this->resolver([]))
        );
    }

    public function testSingleWordMentionIsReplacedWithAnchor(): void
    {
        $f = new CommentFormatter();
        $this->assertSame(
            '<div>Hi <a href="#" data-vss-mention="version:2.0,TFID1">@John</a>!</div>',
            $f->format('Hi @John!', $this->resolver(['John' => 'TFID1']))
        );
    }

    public function testMultiWordNameIsResolved(): void
    {
        $f = new CommentFormatter();
        $this->assertSame(
            '<div>cc <a href="#" data-vss-mention="version:2.0,GUID-9">@John Doe</a> please</div>',
            $f->format('cc @John Doe please', $this->resolver(['John Doe' => 'GUID-9']))
        );
    }

    public function testUnresolvedMentionIsLeftAsText(): void
    {
        $f = new CommentFormatter();
        $this->assertSame(
            '<div>Hi @Unknown Person here</div>',
            $f->format('Hi @Unknown Person here', $this->resolver([]))
        );
    }

    public function testGreedyMatchStopsAtLowercaseWord(): void
    {
        // "and" is lowercase, so it must not be swallowed into the name.
        $f = new CommentFormatter();
        $this->assertSame(
            '<div><a href="#" data-vss-mention="version:2.0,A">@John Doe</a> and <a href="#" data-vss-mention="version:2.0,B">@Jane</a></div>',
            $f->format('@John Doe and @Jane', $this->resolver(['John Doe' => 'A', 'Jane' => 'B']))
        );
    }

    public function testReturnsListOfMentionedNames(): void
    {
        $f = new CommentFormatter();
        $f->format('@John Doe and @Jane', $this->resolver(['John Doe' => 'A', 'Jane' => 'B']));
        $this->assertSame(['John Doe', 'Jane'], $f->lastResolvedNames());
    }
}
