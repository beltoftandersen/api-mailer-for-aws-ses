<?php
use PHPUnit\Framework\TestCase;
use SesMailer\Mail\Mailer;

class MailerHelpersTest extends TestCase {

    public function test_parse_recipients_keeps_names_and_drops_invalid() {
        $this->assertSame(
            array('John Doe <john@example.com>', 'jane@example.com'),
            Mailer::parse_recipients('John Doe <john@example.com>, jane@example.com, not-an-address')
        );
        $this->assertSame(array('Ann B <ann@example.com>'), Mailer::parse_recipients(array('"Ann B" <ann@example.com>')));
    }

    public function test_header_lines_accepts_string_and_array() {
        $this->assertSame(array('A: 1', 'B: 2'), Mailer::header_lines("A: 1\r\n\r\nB: 2\n"));
        $this->assertSame(array('A: 1'), Mailer::header_lines(array(' A: 1 ', '')));
    }

    public function test_normalize_files_handles_keys_and_strings() {
        $this->assertSame(
            array(array('path' => '/a.pdf', 'name' => 'Invoice.pdf'), array('path' => '/b.pdf', 'name' => '')),
            Mailer::normalize_files(array('Invoice.pdf' => '/a.pdf', '/b.pdf', ''))
        );
        $this->assertCount(2, Mailer::normalize_files("/a.pdf\r\n/b.pdf"));
        // Already-normalized input passes through unchanged.
        $norm = array(array('path' => '/a.pdf', 'name' => 'x'));
        $this->assertSame($norm, Mailer::normalize_files($norm));
    }

    public function test_extract_tag() {
        $this->assertSame('order-1', Mailer::extract_tag(array('X-SES-Mailer-Tag: order-1<>')));
        $this->assertSame('', Mailer::extract_tag(array('X-Other: 1')));
    }
}
