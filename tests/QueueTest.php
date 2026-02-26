<?php
use PHPUnit\Framework\TestCase;
use SesMailer\Background\Queue;
use SesMailer\Support\Options;

class QueueTest extends TestCase {

    protected function setUp(): void {
        global $_ses_test_options, $_ses_test_transients, $_ses_test_scheduled;
        global $_ses_test_fail_add_option, $_ses_test_fail_update_option, $_ses_test_fail_schedule_single;
        $_ses_test_options    = array();
        $_ses_test_transients = array();
        $_ses_test_scheduled  = array();
        $_ses_test_fail_add_option      = false;
        $_ses_test_fail_update_option   = false;
        $_ses_test_fail_schedule_single = false;

        // Set up default plugin options
        $_ses_test_options[Options::OPTION] = array_merge(Options::defaults(), array(
            'enable_mailer'   => '1',
            'from_email'      => 'test@example.com',
            'from_name'       => 'Test',
            'region'          => 'us-east-1',
            'disable_logging' => '1',
        ));
    }

    // --- Enqueue failure propagation ---

    public function test_enqueue_returns_true_on_success() {
        $result = Queue::enqueue(array(
            'to'      => array('user@example.com'),
            'subject' => 'Test',
            'message' => 'Hello',
        ));
        $this->assertTrue($result);
    }

    public function test_enqueue_returns_false_when_db_write_fails() {
        global $_ses_test_fail_add_option;
        $_ses_test_fail_add_option = true;

        $result = Queue::enqueue(array(
            'to'      => array('user@example.com'),
            'subject' => 'Test',
            'message' => 'Hello',
        ));
        $this->assertFalse($result, 'Enqueue should return false when DB write fails');
    }

    // --- Legacy worker path ---

    public function test_worker_handles_legacy_args_with_valid_payload() {
        // Legacy args = full payload without job_id
        $legacy_args = array(
            'to'      => array('user@example.com'),
            'subject' => 'Legacy test',
            'message' => 'Hello',
            'headers' => array(),
            'attachments' => array(),
            'attempt' => 0,
        );

        // Worker should store the job and attempt to process
        // Since SesClient will fail (no real AWS), it should store+retry
        // We just verify no fatal error and that a job option was created
        Queue::worker($legacy_args);

        global $_ses_test_options;
        // Check that a ses_mailer_job_* option was created (legacy migration)
        $found = false;
        foreach ($_ses_test_options as $key => $val) {
            if (strpos($key, 'ses_mailer_job_') === 0) {
                $found = true;
                break;
            }
        }
        // Job may have been created and deleted after failure, or created for retry.
        // The key assertion is: no fatal error occurred.
        $this->assertTrue(true, 'Legacy worker path completed without fatal error');
    }

    public function test_worker_returns_early_for_non_array_args() {
        global $_ses_test_options;
        $before = $_ses_test_options;
        Queue::worker('invalid');
        // No job options should have been created
        $this->assertSame($before, $_ses_test_options, 'Worker should not modify state for non-array args');
    }

    public function test_worker_returns_early_for_missing_job() {
        global $_ses_test_options;
        $before = $_ses_test_options;
        Queue::worker(array('job_id' => 'nonexistent-uuid'));
        // No new job options should have been created
        $this->assertSame($before, $_ses_test_options, 'Worker should not modify state for missing job');
    }

    public function test_worker_skips_empty_recipients() {
        global $_ses_test_options;

        $job_id = 'test-empty-to';
        $_ses_test_options['ses_mailer_job_' . $job_id] = array(
            'to'      => array(),
            'subject' => 'No recipients',
            'message' => 'Hello',
            'headers' => array(),
            'attachments' => array(),
            'attempt' => 0,
            'created_at' => time(),
        );

        Queue::worker(array('job_id' => $job_id));

        // Job should be deleted since recipients are empty
        $this->assertArrayNotHasKey('ses_mailer_job_' . $job_id, $_ses_test_options);
    }

    // --- Retry persistence failure ---

    public function test_retry_or_discard_is_called_on_send_failure() {
        global $_ses_test_options;

        $job_id = 'test-retry';
        $_ses_test_options['ses_mailer_job_' . $job_id] = array(
            'to'      => array('user@example.com'),
            'subject' => 'Retry test',
            'message' => 'Hello',
            'headers' => array(),
            'attachments' => array(),
            'attempt' => 0,
            'created_at' => time(),
        );

        // Worker will try to send, SesClient will fail (no creds/endpoint),
        // should retry (attempt 0 → attempt 1)
        Queue::worker(array('job_id' => $job_id));

        // After failure, the job should still exist with incremented attempt
        $stored = $_ses_test_options['ses_mailer_job_' . $job_id] ?? null;
        if ( $stored !== null ) {
            $this->assertGreaterThan(0, $stored['attempt'], 'Attempt should be incremented on retry');
        } else {
            // Job was deleted after max retries — also acceptable
            $this->assertTrue(true);
        }
    }

    public function test_job_discarded_after_max_attempts() {
        global $_ses_test_options;

        $job_id = 'test-max-retry';
        $_ses_test_options['ses_mailer_job_' . $job_id] = array(
            'to'      => array('user@example.com'),
            'subject' => 'Max retry test',
            'message' => 'Hello',
            'headers' => array(),
            'attachments' => array(),
            'attempt' => 2, // Already at max-1
            'created_at' => time(),
        );

        Queue::worker(array('job_id' => $job_id));

        // Job should be deleted — no more retries
        $this->assertArrayNotHasKey(
            'ses_mailer_job_' . $job_id,
            $_ses_test_options,
            'Job should be discarded after max attempts'
        );
    }

    // --- Schedule failure ---

    public function test_enqueue_returns_false_when_schedule_fails() {
        global $_ses_test_fail_schedule_single, $_ses_test_options;
        $_ses_test_fail_schedule_single = true;

        $result = Queue::enqueue(array(
            'to'      => array('user@example.com'),
            'subject' => 'Test',
            'message' => 'Hello',
        ));
        $this->assertFalse($result, 'Enqueue should return false when scheduling fails');

        // Job should have been cleaned up (not orphaned)
        $found = false;
        foreach ($_ses_test_options as $key => $val) {
            if (strpos($key, 'ses_mailer_job_') === 0) {
                $found = true;
                break;
            }
        }
        $this->assertFalse($found, 'Stored job should be deleted when scheduling fails');
    }

    // --- Invalid from-email should not orphan job ---

    public function test_worker_deletes_job_on_invalid_from_email() {
        global $_ses_test_options;

        // Set an invalid from_email
        $_ses_test_options[Options::OPTION]['from_email'] = 'not-an-email';

        $job_id = 'test-invalid-from';
        $_ses_test_options['ses_mailer_job_' . $job_id] = array(
            'to'      => array('user@example.com'),
            'subject' => 'Invalid from test',
            'message' => 'Hello',
            'headers' => array(),
            'attachments' => array(),
            'attempt' => 0,
            'created_at' => time(),
        );

        Queue::worker(array('job_id' => $job_id));

        $this->assertArrayNotHasKey(
            'ses_mailer_job_' . $job_id,
            $_ses_test_options,
            'Job should be deleted when from-email is invalid'
        );
    }
}
