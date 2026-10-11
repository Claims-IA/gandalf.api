<?php
/**
 * Doubles for the account administration unit test (tests/unit/AccountsTest.php).
 *
 * Kept out of the test file: Codeception 2.1 reads every `class` keyword of a
 * test file as a test class name.
 */

namespace Helper;

use App\Services\Mail;

/** Mail service whose provider refuses every temporary password email. */
class RefusingMail extends Mail
{
    public function __construct()
    {
    }

    public function sendTemporaryPassword($email, $password, $name)
    {
        throw new \RuntimeException('Recipient refused');
    }
}

/** Exception handler that keeps what it is asked to report. */
class KeepingHandler extends \App\Exceptions\Handler
{
    public $reported = [];

    public function report(\Exception $e)
    {
        $this->reported[] = $e;
    }
}
