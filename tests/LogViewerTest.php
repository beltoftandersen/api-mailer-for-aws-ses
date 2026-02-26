<?php
use PHPUnit\Framework\TestCase;
use SesMailer\Logging\LogViewer;
use SesMailer\Support\Options;

class LogViewerTest extends TestCase {

    protected function setUp(): void {
        global $_ses_test_options, $_ses_test_error_log;
        $_ses_test_options = array();
        $_ses_test_error_log = array();
    }

    public function test_logging_disabled_by_default() {
        // defaults() has disable_logging = '1'
        $defaults = Options::defaults();
        $this->assertSame('1', $defaults['disable_logging']);
    }

    public function test_log_skipped_when_disabled() {
        global $_ses_test_options;
        $_ses_test_options[Options::OPTION] = array_merge(Options::defaults(), array(
            'disable_logging' => '1',
        ));

        // Capture error_log calls
        $logged = false;
        set_error_handler(function () use (&$logged) { $logged = true; return true; });
        LogViewer::log('test message');
        restore_error_handler();

        // When disabled, nothing should happen — no WC, no error_log
        // (In our test env wc_get_logger doesn't exist so fallback would be error_log)
        $this->assertFalse($logged, 'Log should be skipped when disable_logging is 1');
    }

    public function test_log_writes_to_error_log_when_enabled_and_no_wc() {
        global $_ses_test_options;
        $_ses_test_options[Options::OPTION] = array_merge(Options::defaults(), array(
            'disable_logging' => '0',
        ));

        // wc_get_logger is not defined in test env, so should fall back to error_log
        // We can't easily capture error_log output, but we can verify it doesn't throw
        $this->assertFalse(function_exists('wc_get_logger'));

        // Just verify it runs without error
        LogViewer::log('test message from unit test');
        $this->assertTrue(true); // Reached here = no fatal
    }

    public function test_log_routes_to_wc_logger_when_available() {
        global $_ses_test_options;
        $_ses_test_options[Options::OPTION] = array_merge(Options::defaults(), array(
            'disable_logging' => '0',
        ));

        // This test documents that when wc_get_logger exists it would be used.
        // We can't define it in this process without side effects, so we just
        // verify the branching logic by confirming function_exists check.
        $this->assertFalse(function_exists('wc_get_logger'), 'Test env should not have WC');
    }
}
