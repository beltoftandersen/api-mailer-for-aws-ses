<?php
use PHPUnit\Framework\TestCase;
use SesMailer\Mail\Mailer;

class AttachmentTest extends TestCase {

    private $uploads_dir;
    private $content_dir;

    protected function setUp(): void {
        $this->uploads_dir = sys_get_temp_dir() . '/wp-test-uploads';
        $this->content_dir = sys_get_temp_dir() . '/wp-test-content';
        @mkdir($this->uploads_dir, 0755, true);
        @mkdir($this->content_dir, 0755, true);
    }

    protected function tearDown(): void {
        // Clean up test files
        array_map('unlink', glob($this->uploads_dir . '/*.txt'));
        array_map('unlink', glob($this->content_dir . '/*.txt'));
        $outside = sys_get_temp_dir() . '/ses-test-outside.txt';
        if ( file_exists($outside) ) unlink($outside);
    }

    public function test_allows_file_in_uploads_dir() {
        $file = $this->uploads_dir . '/allowed.txt';
        file_put_contents($file, 'test content');

        $phpmailer = $this->createMock(MockPHPMailer::class);
        $phpmailer->expects($this->once())->method('addAttachment');

        $blocked = Mailer::attach_files($phpmailer, array($file));
        $this->assertEmpty($blocked, 'File in uploads should be allowed');
    }

    public function test_allows_file_in_wp_content_dir() {
        $file = $this->content_dir . '/allowed.txt';
        file_put_contents($file, 'test content');

        $phpmailer = $this->createMock(MockPHPMailer::class);
        $phpmailer->expects($this->once())->method('addAttachment');

        $blocked = Mailer::attach_files($phpmailer, array($file));
        $this->assertEmpty($blocked, 'File in wp-content should be allowed');
    }

    public function test_blocks_file_outside_allowed_dirs() {
        $file = sys_get_temp_dir() . '/ses-test-outside.txt';
        file_put_contents($file, 'secret data');

        $phpmailer = $this->createMock(MockPHPMailer::class);
        $phpmailer->expects($this->never())->method('addAttachment');

        $blocked = Mailer::attach_files($phpmailer, array($file));
        $this->assertContains($file, $blocked, 'File outside allowed dirs should be blocked');
    }

    public function test_blocks_nonexistent_file() {
        $file = $this->uploads_dir . '/nonexistent.txt';

        $phpmailer = $this->createMock(MockPHPMailer::class);
        $phpmailer->expects($this->never())->method('addAttachment');

        $blocked = Mailer::attach_files($phpmailer, array($file));
        $this->assertContains($file, $blocked, 'Nonexistent file should be blocked');
    }

    public function test_skips_empty_paths() {
        $phpmailer = $this->createMock(MockPHPMailer::class);
        $phpmailer->expects($this->never())->method('addAttachment');

        $blocked = Mailer::attach_files($phpmailer, array('', '  '));
        // Empty string skipped (not counted as blocked), whitespace-only will fail realpath
        $this->assertCount(1, $blocked); // '  ' is blocked (realpath fails)
    }

    public function test_blocks_symlink_escape() {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $this->markTestSkipped('Symlink test not reliable on Windows');
        }

        $outside_file = sys_get_temp_dir() . '/ses-test-outside.txt';
        file_put_contents($outside_file, 'secret');

        $link = $this->uploads_dir . '/symlink-escape.txt';
        if ( file_exists($link) ) unlink($link);
        symlink($outside_file, $link);

        $phpmailer = $this->createMock(MockPHPMailer::class);
        $phpmailer->expects($this->never())->method('addAttachment');

        $blocked = Mailer::attach_files($phpmailer, array($link));
        $this->assertContains($link, $blocked, 'Symlink escaping outside allowed dirs should be blocked');

        unlink($link);
    }

    public function test_mixed_allow_and_block() {
        $allowed = $this->uploads_dir . '/good.txt';
        $denied  = sys_get_temp_dir() . '/ses-test-outside.txt';
        file_put_contents($allowed, 'ok');
        file_put_contents($denied, 'nope');

        $phpmailer = $this->createMock(MockPHPMailer::class);
        $phpmailer->expects($this->once())->method('addAttachment');

        $blocked = Mailer::attach_files($phpmailer, array($allowed, $denied));
        $this->assertCount(1, $blocked);
        $this->assertContains($denied, $blocked);
    }
}

/**
 * Minimal mock target — PHPMailer's addAttachment signature.
 */
class MockPHPMailer {
    public function addAttachment($path, $name = '', $encoding = 'base64', $type = '', $disposition = 'attachment') {}
}
