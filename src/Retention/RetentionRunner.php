<?php
/**
 * Deletes rows that have passed their retention period.
 *
 * @package Formstead
 */

namespace Formstead\Retention;

use Formstead\Delivery\DeliveryRepository;
use Formstead\Forms\FormRepository;
use Formstead\Security\RateLimiter;
use Formstead\Submissions\IdempotencyRepository;
use Formstead\Submissions\SubmissionDeleter;
use Formstead\Submissions\SubmissionRepository;
use WP_Error;

/**
 * Deletes expired submissions through the single deletion service.
 *
 * One run holds a lock. Cron stops after ten batches or twenty seconds.
 * A command without a batch limit continues until nothing expired remains.
 * The stored report has counts and error codes, never submission text.
 */
final class RetentionRunner {

	public const LOCK_OPTION = 'formstead_retention_lock';

	public const REPORT_OPTION = 'formstead_retention_report';

	public const LOCK_TTL = 900;

	public const BATCH_SIZE = 500;

	public const CRON_BATCHES = 10;

	public const CRON_SECONDS = 20;

	/**
	 * Rows deleted in one query.
	 *
	 * @var int
	 */
	private int $batch_size;

	/**
	 * Form store.
	 *
	 * @var FormRepository
	 */
	private FormRepository $forms;

	/**
	 * Submission store.
	 *
	 * @var SubmissionRepository
	 */
	private SubmissionRepository $submissions;

	/**
	 * The only path that deletes submissions.
	 *
	 * @var SubmissionDeleter
	 */
	private SubmissionDeleter $deleter;

	/**
	 * Delivery store.
	 *
	 * @var DeliveryRepository
	 */
	private DeliveryRepository $deliveries;

	/**
	 * Idempotency key store.
	 *
	 * @var IdempotencyRepository
	 */
	private IdempotencyRepository $keys;

	/**
	 * Rate-limit counters.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $rates;

	/**
	 * Create a runner.
	 *
	 * @param int $batch_size Rows deleted in one query.
	 */
	public function __construct( int $batch_size = self::BATCH_SIZE ) {
		$this->batch_size  = max( 1, $batch_size );
		$this->forms       = new FormRepository();
		$this->submissions = new SubmissionRepository();
		$this->deleter     = new SubmissionDeleter();
		$this->deliveries  = new DeliveryRepository();
		$this->keys        = new IdempotencyRepository();
		$this->rates       = new RateLimiter();
	}

	/**
	 * Delete expired submissions and spam.
	 *
	 * Null means another run holds the lock. The previous report is left as it is.
	 *
	 * @param int|null $batch_limit Maximum batches. Null means no limit.
	 * @param int|null $deadline    Unix time at which to stop. Null means no time limit.
	 * @param int|null $now         Unix time used to decide what has expired.
	 * @return array{started_at: int, finished_at: int, submissions: int, spam: int, deliveries: int, keys: int, rate_limits: int, backlog: bool, backlog_since: int, errors: list<string>}|null
	 */
	public function run( ?int $batch_limit, ?int $deadline, ?int $now = null ): ?array {
		$now     = $now ?? time();
		$started = time();
		if ( ! $this->acquire_lock( $started ) ) {
			return null;
		}

		try {
			$report                  = $this->purge( $batch_limit, $deadline, $now, $started );
			$report['backlog_since'] = $this->backlog_since( $report['backlog'], $report['finished_at'] );
			update_option( self::REPORT_OPTION, $report, false );
			return $report;
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Delete every expired series that the budget allows.
	 *
	 * @param int|null $batch_limit Maximum batches.
	 * @param int|null $deadline    Unix time at which to stop.
	 * @param int      $now         Unix time used to decide what has expired.
	 * @param int      $started     Wall-clock start of this run.
	 * @return array{started_at: int, finished_at: int, submissions: int, spam: int, deliveries: int, keys: int, rate_limits: int, backlog: bool, errors: list<string>}
	 */
	private function purge( ?int $batch_limit, ?int $deadline, int $now, int $started ): array {
		$submissions = 0;
		$spam        = 0;
		$deliveries  = 0;
		$keys        = 0;
		$rates       = 0;
		$errors      = array();
		$batches     = 0;
		$stopped     = false;

		foreach ( $this->forms->retention_periods() as $form ) {
			$outcome      = $this->delete_batches( 'form', $form['id'], $this->before( $now, $form['days'] ), $batch_limit, $deadline, $batches );
			$submissions += $outcome['deleted'];
			$batches      = $outcome['batches'];
			$errors       = $this->merge_error( $errors, $outcome['error'] );
			if ( $outcome['stopped'] ) {
				$stopped = true;
				break;
			}
		}

		if ( ! $stopped ) {
			$outcome = $this->delete_batches( 'spam', 0, $this->before( $now, RetentionPeriods::spam_days() ), $batch_limit, $deadline, $batches );
			$spam   += $outcome['deleted'];
			$batches = $outcome['batches'];
			$errors  = $this->merge_error( $errors, $outcome['error'] );
			$stopped = $outcome['stopped'];
		}

		if ( ! $stopped ) {
			$outcome    = $this->drain(
				fn (): int => $this->deliveries->delete_finished_before( $this->before( $now, RetentionPeriods::delivery_days() ), $this->batch_size ),
				$batch_limit,
				$deadline,
				$batches
			);
			$deliveries = $outcome['deleted'];
			$batches    = $outcome['batches'];
			$stopped    = $outcome['stopped'];
		}

		if ( ! $stopped ) {
			$outcome = $this->drain(
				fn (): int => $this->keys->prune_expired( $now, $this->batch_size ),
				$batch_limit,
				$deadline,
				$batches
			);
			$keys    = $outcome['deleted'];
			$batches = $outcome['batches'];
			$stopped = $outcome['stopped'];
		}

		if ( ! $stopped ) {
			$outcome = $this->drain(
				fn (): int => $this->rates->prune_expired( $now, $this->batch_size ),
				$batch_limit,
				$deadline,
				$batches
			);
			$rates   = $outcome['deleted'];
			$batches = $outcome['batches'];
			$stopped = $outcome['stopped'];
		}

		return array(
			'started_at'  => $started,
			'finished_at' => time(),
			'submissions' => $submissions,
			'spam'        => $spam,
			'deliveries'  => $deliveries,
			'keys'        => $keys,
			'rate_limits' => $rates,
			'backlog'     => $stopped && $this->has_expired( $now ),
			'errors'      => array_values( $errors ),
		);
	}

	/**
	 * When the current backlog began, or zero when nothing is waiting.
	 *
	 * A later run that still has work keeps the original time, so the screen
	 * can warn once the leftovers have waited for a day.
	 *
	 * @param bool $backlog  Whether this run left rows behind.
	 * @param int  $finished Unix time when this run finished.
	 */
	private function backlog_since( bool $backlog, int $finished ): int {
		if ( ! $backlog ) {
			return 0;
		}

		$previous = get_option( self::REPORT_OPTION );
		if ( ! is_array( $previous ) || empty( $previous['backlog'] ) ) {
			return $finished;
		}

		$since = $previous['backlog_since'] ?? 0;
		if ( is_numeric( $since ) && (int) $since > 0 ) {
			return (int) $since;
		}

		return $finished;
	}

	/**
	 * Delete batches for one form, or for spam across every form.
	 *
	 * @param string   $kind        `form` or `spam`.
	 * @param int      $form_id     Form id. Unused for spam.
	 * @param string   $before      UTC `Y-m-d H:i:s` cutoff. Older rows are deleted.
	 * @param int|null $batch_limit Maximum batches for the whole run.
	 * @param int|null $deadline    Unix time at which to stop.
	 * @param int      $batches     Batches already used.
	 * @return array{deleted: int, batches: int, stopped: bool, error: string}
	 */
	private function delete_batches( string $kind, int $form_id, string $before, ?int $batch_limit, ?int $deadline, int $batches ): array {
		$deleted = 0;

		while ( $this->within_budget( $batches, $batch_limit, $deadline ) ) {
			$ids = 'spam' === $kind
				? $this->submissions->spam_ids_created_before( $before, $this->batch_size )
				: $this->submissions->ids_created_before( $form_id, $before, $this->batch_size );
			if ( array() === $ids ) {
				break;
			}

			$result = $this->deleter->delete( $ids );
			++$batches;
			if ( $result instanceof WP_Error ) {
				return array(
					'deleted' => $deleted,
					'batches' => $batches,
					'stopped' => true,
					'error'   => 'submission_delete_failed',
				);
			}

			$deleted += $result;
			if ( count( $ids ) < $this->batch_size ) {
				break;
			}
		}

		return array(
			'deleted' => $deleted,
			'batches' => $batches,
			'stopped' => ! $this->within_budget( $batches, $batch_limit, $deadline ),
			'error'   => '',
		);
	}

	/**
	 * Whether another expired row is still stored.
	 *
	 * Called only after a run stops early, so a finished run does not pay for the probe.
	 *
	 * @param int $now Unix time used to decide what has expired.
	 */
	private function has_expired( int $now ): bool {
		foreach ( $this->forms->retention_periods() as $form ) {
			$ids = $this->submissions->ids_created_before( $form['id'], $this->before( $now, $form['days'] ), 1 );
			if ( array() !== $ids ) {
				return true;
			}
		}

		$spam = $this->submissions->spam_ids_created_before( $this->before( $now, RetentionPeriods::spam_days() ), 1 );
		if ( array() !== $spam ) {
			return true;
		}

		if ( $this->deliveries->has_finished_before( $this->before( $now, RetentionPeriods::delivery_days() ) ) ) {
			return true;
		}

		if ( $this->keys->has_expired( $now ) ) {
			return true;
		}

		return $this->rates->has_expired( $now );
	}

	/**
	 * Delete batches until one comes back short or the budget runs out.
	 *
	 * @param callable(): int $batch       Deletes one batch and returns the row count.
	 * @param int|null        $batch_limit Maximum batches for the whole run.
	 * @param int|null        $deadline    Unix time at which to stop.
	 * @param int             $batches     Batches already used.
	 * @return array{deleted: int, batches: int, stopped: bool}
	 */
	private function drain( callable $batch, ?int $batch_limit, ?int $deadline, int $batches ): array {
		$deleted = 0;

		while ( $this->within_budget( $batches, $batch_limit, $deadline ) ) {
			$rows = $batch();
			if ( $rows < 1 ) {
				break;
			}

			$deleted += $rows;
			++$batches;
			if ( $rows < $this->batch_size ) {
				break;
			}
		}

		return array(
			'deleted' => $deleted,
			'batches' => $batches,
			'stopped' => ! $this->within_budget( $batches, $batch_limit, $deadline ),
		);
	}

	/**
	 * UTC cutoff. A row stored at this exact time is kept.
	 *
	 * @param int $now  Unix time.
	 * @param int $days Retention period.
	 */
	private function before( int $now, int $days ): string {
		return gmdate( 'Y-m-d H:i:s', $now - ( $days * DAY_IN_SECONDS ) );
	}

	/**
	 * Whether this run may delete another batch.
	 *
	 * @param int      $batches     Batches already used.
	 * @param int|null $batch_limit Maximum batches.
	 * @param int|null $deadline    Unix time at which to stop.
	 */
	private function within_budget( int $batches, ?int $batch_limit, ?int $deadline ): bool {
		if ( null !== $batch_limit && $batches >= $batch_limit ) {
			return false;
		}

		if ( null !== $deadline && time() >= $deadline ) {
			return false;
		}

		return true;
	}

	/**
	 * Add an error code once.
	 *
	 * @param string[] $errors Existing codes.
	 * @param string   $error  New code, or an empty string.
	 * @return string[]
	 */
	private function merge_error( array $errors, string $error ): array {
		if ( '' === $error || in_array( $error, $errors, true ) ) {
			return $errors;
		}

		$errors[] = $error;
		return $errors;
	}

	/**
	 * Take the lock, or take over a lock older than LOCK_TTL.
	 *
	 * @param int $now Unix time.
	 */
	private function acquire_lock( int $now ): bool {
		if ( add_option( self::LOCK_OPTION, $now, '', false ) ) {
			return true;
		}

		$existing = get_option( self::LOCK_OPTION, 0 );
		if ( is_numeric( $existing ) && ( $now - (int) $existing ) < self::LOCK_TTL ) {
			return false;
		}

		delete_option( self::LOCK_OPTION );
		return add_option( self::LOCK_OPTION, $now, '', false );
	}
}
