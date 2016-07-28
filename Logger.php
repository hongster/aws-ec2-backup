<?php

/*
 * This logger captures all the output (from `echo()`) and mail it to list of
 * email addresses. If recipient is not specified, log will be sent to STDOUT.
 *
 * Example usage
 * ```php
 * $logger->setRecipients($config['log']['recipients']);
 * $logger->startLogging();
 * $logger->handleError();
 * echo "abc\n";
 * echo "123\n";
 * $logger->sendLog();
 * $logger->endLogging();
 * ```
 */
class Logger {

	protected $recipients = [];

	public function setRecipients($recipients = []) {
		$this->recipients = $recipients;
	}

	/**
	 * Overwrite default error handler. Will capture error information in log.
	 */
	public function handleError() {
		set_error_handler(array($this, 'errorHandler'));
	}

	protected function errorHandler($errNo, $errStr, $errFile = null, $errLine = null, $errContext = null) {
		$output = "[{$errNo}] {$errStr}";
		if ($errFile) {
			$output .= " {$errFile}";
		}
		if ($errLine) {
			$output .= ":{$errLine}";
		}
		echo "{$output}\n";

		debug_print_backtrace();

		if (($errNo != E_NOTICE) && ($errNo < 2048)) {
			$this->sendLog();
			$this->endLogging();
			exit(1);
		}

		// Don't execute PHP internal error handler
		return true;
	}

	public function startLogging() {
		ob_start();
	}

	public function endLogging() {
		ob_end_clean();
	}

	/**
	 * Call this before `endLogging()`.
	 */
	public function sendLog() {
		if (count($this->recipients) == 0) {
			$this->sendLogStdout();
		}
		else {
			$this->sendLogMail($this->recipients);
		}
	}

	/**
	 * @param array $recipients List of email addresses.
	 */
	protected function sendLogMail($recipients) {
		$content = ob_get_contents();
		ob_clean();
		mail(
			implode(',', $recipients),
			'EC2 Backup Log '.date('r'),
			$content
		);
	}

	/**
	 * Send log to STDOUT
	 */
	protected function sendLogStdout() {
		ob_flush();
	}

}
