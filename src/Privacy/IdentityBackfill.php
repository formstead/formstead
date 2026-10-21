<?php
/**
 * Builds the identity index for submissions stored before a field was marked.
 *
 * @package Formstead
 */

namespace Formstead\Privacy;

use Formstead\Database\Migrator;
use Formstead\Database\Schema;
use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are identifiers.

/**
 * Indexes identifying emails in batches. The migration only records that work
 * is waiting, because indexing a large inbox inside the upgrade request would
 * block it. A later submission is indexed when it is stored.
 */
final class IdentityBackfill {

	/**
	 * Cron hook that indexes one bounded slice.
	 */
	public const HOOK = 'formstead_identity_backfill';

	/**
	 * Option that keeps a second run from indexing the same slice.
	 */
	public const LOCK_OPTION = 'formstead_identity_backfill_lock';

	/**
	 * Seconds after which a crashed run may be taken over.
	 */
	public const LOCK_TTL = 900;

	/**
	 * Submissions read in one batch.
	 */
	public const BATCH = 500;

	/**
	 * Seconds a cron run may spend before it schedules the rest.
	 */
	public const CRON_SECONDS = 20;

	/**
	 * Register the hook and schedule it while work is waiting.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run_cron' ) );
		add_action( 'init', array( self::class, 'ensure' ) );
	}

	/**
	 * Schedule one event when the index is behind and nothing is scheduled.
	 */
	public static function ensure(): void {
		if ( ! self::is_pending() || false !== wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
	}

	/**
	 * Index until the time budget runs out, then schedule the remainder.
	 */
	public static function run_cron(): void {
		( new self() )->run( time() + self::CRON_SECONDS, null );
		self::ensure();
	}

	/**
	 * Remove scheduled indexing. Called on deactivation and uninstall.
	 */
	public static function clear(): void {
		wp_unschedule_hook( self::HOOK );
	}

	/**
	 * Whether submissions are still waiting to be indexed.
	 */
	public static function is_pending(): bool {
		return self::has_work( self::read() );
	}

	/**
	 * Sentence for the settings screen.
	 */
	public static function message(): string {
		$cursor = self::read();
		if ( ! self::has_work( $cursor ) ) {
			return __( 'The identity index is up to date.', 'formstead' );
		}

		return sprintf(
			/* translators: 1: last indexed submission id, 2: highest submission id in this job, 3: WP-CLI command. */
			__( 'The identity index is still being built (submission %1$s of %2$s). Run %3$s to finish it now.', 'formstead' ),
			number_format_i18n( $cursor['after_id'] ),
			number_format_i18n( $cursor['until_id'] ),
			'wp formstead identities rebuild'
		);
	}

	/**
	 * Queue a backfill for one form.
	 *
	 * Rows stored after the cursor was created are indexed when they arrive.
	 * This covers rows stored before the field was marked. A job that has not
	 * started yet already sees the new mark, so it is not queued twice.
	 *
	 * @param int $form_id Form id.
	 */
	public static function request_form( int $form_id ): void {
		if ( $form_id < 1 ) {
			return;
		}

		$until = self::max_id_for_form( $form_id );
		if ( $until < 1 ) {
			return;
		}

		$cursor = self::read();
		if ( ! self::has_work( $cursor ) ) {
			self::save(
				array(
					'status'   => 'pending',
					'after_id' => 0,
					'until_id' => $until,
					'form_id'  => $form_id,
					'queue'    => array(),
				)
			);
			self::ensure();
			return;
		}

		if ( $cursor['form_id'] === $form_id ) {
			if ( $until > $cursor['until_id'] ) {
				$cursor['until_id'] = $until;
				$cursor['status']   = 'pending';
				self::save( $cursor );
			}
			self::ensure();
			return;
		}

		if ( 0 === $cursor['form_id'] && 0 === $cursor['after_id'] && $until <= $cursor['until_id'] ) {
			self::ensure();
			return;
		}

		foreach ( $cursor['queue'] as $index => $queued ) {
			if ( $queued['form_id'] !== $form_id ) {
				continue;
			}

			if ( $until > $queued['until_id'] ) {
				$cursor['queue'][ $index ]['until_id'] = $until;
				$cursor['status']                      = 'pending';
				self::save( $cursor );
			}
			self::ensure();
			return;
		}

		$cursor['queue'][] = array(
			'form_id'  => $form_id,
			'after_id' => 0,
			'until_id' => $until,
		);
		$cursor['status']  = 'pending';
		self::save( $cursor );
		self::ensure();
	}

	/**
	 * Index waiting submissions.
	 *
	 * A null deadline runs until the cursor is finished. A null batch limit
	 * does the same. A cron run passes both so a large inbox cannot hold the
	 * request. `locked` means another run holds the lock.
	 *
	 * @param int|null $deadline    Unix time at which to stop, or null to finish.
	 * @param int|null $batch_limit How many batches to run, or null to finish.
	 * @param int      $batch_size  Submissions per batch.
	 * @return string `done`, `pending`, or `locked`.
	 */
	public function run( ?int $deadline, ?int $batch_limit, int $batch_size = self::BATCH ): string {
		$token = $this->acquire_lock( time() );
		if ( ! is_string( $token ) ) {
			return 'locked';
		}

		try {
			return $this->drain( $deadline, $batch_limit, max( 1, $batch_size ), $token );
		} finally {
			$this->release_lock( $token );
		}
	}

	/**
	 * Index batches until the cursor, the deadline, or the batch limit stops it.
	 *
	 * @param int|null $deadline    Unix time at which to stop, or null to finish.
	 * @param int|null $batch_limit How many batches to run, or null to finish.
	 * @param int      $batch_size  Submissions per batch.
	 * @param string   $token       Lock token returned by acquire_lock().
	 * @return string `done` or `pending`.
	 */
	private function drain( ?int $deadline, ?int $batch_limit, int $batch_size, string $token ): string {
		$batches = 0;
		$forms   = array();

		while ( true ) {
			if ( null !== $deadline && time() >= $deadline ) {
				return self::is_pending() ? 'pending' : 'done';
			}

			if ( null !== $batch_limit && $batches >= $batch_limit ) {
				return self::is_pending() ? 'pending' : 'done';
			}

			$cursor = self::read();
			if ( ! self::has_work( $cursor ) ) {
				self::finish( $cursor );
				return 'done';
			}

			if ( $cursor['after_id'] >= $cursor['until_id'] ) {
				self::save( $this->promote( $cursor ) );
				continue;
			}

			$rows = $this->next_batch( $cursor, $batch_size );
			if ( array() === $rows ) {
				self::save( $this->promote( $cursor ) );
				continue;
			}

			foreach ( $rows as $row ) {
				if ( ! $this->index_row( $row, $forms ) ) {
					self::save( $cursor );
					return 'pending';
				}

				$cursor['after_id'] = $row['id'];
				$cursor['status']   = 'pending';
			}

			self::save( $cursor );
			$this->touch_lock( $token );
			++$batches;
		}
	}

	/**
	 * Mark the cursor finished and drop the cron event.
	 *
	 * @param array{status: string, after_id: int, until_id: int, form_id: int, queue: array<int, array{form_id: int, after_id: int, until_id: int}>} $cursor Stored cursor.
	 */
	private static function finish( array $cursor ): void {
		if ( false !== get_option( Migrator::IDENTITY_BACKFILL_OPTION ) ) {
			self::save( self::finished( $cursor ) );
		}

		self::clear();
	}

	/**
	 * Read the cursor. A missing form id means every form.
	 *
	 * @return array{status: string, after_id: int, until_id: int, form_id: int, queue: array<int, array{form_id: int, after_id: int, until_id: int}>}
	 */
	private static function read(): array {
		$stored = get_option( Migrator::IDENTITY_BACKFILL_OPTION );
		if ( ! is_array( $stored ) ) {
			return array(
				'status'   => 'done',
				'after_id' => 0,
				'until_id' => 0,
				'form_id'  => 0,
				'queue'    => array(),
			);
		}

		$status = $stored['status'] ?? '';
		return array(
			'status'   => is_string( $status ) && '' !== $status ? $status : 'pending',
			'after_id' => isset( $stored['after_id'] ) && is_numeric( $stored['after_id'] ) ? (int) $stored['after_id'] : 0,
			'until_id' => isset( $stored['until_id'] ) && is_numeric( $stored['until_id'] ) ? (int) $stored['until_id'] : 0,
			'form_id'  => isset( $stored['form_id'] ) && is_numeric( $stored['form_id'] ) ? (int) $stored['form_id'] : 0,
			'queue'    => self::queue( $stored['queue'] ?? null ),
		);
	}

	/**
	 * Whether this cursor still has submissions to index.
	 *
	 * @param array{status: string, after_id: int, until_id: int, form_id: int, queue: array<int, array{form_id: int, after_id: int, until_id: int}>} $cursor Stored cursor.
	 */
	private static function has_work( array $cursor ): bool {
		if ( 'done' === $cursor['status'] ) {
			return false;
		}

		if ( $cursor['after_id'] < $cursor['until_id'] ) {
			return true;
		}

		return array() !== $cursor['queue'];
	}

	/**
	 * Persist the cursor without autoloading it.
	 *
	 * @param array{status: string, after_id: int, until_id: int, form_id: int, queue: array<int, array{form_id: int, after_id: int, until_id: int}>} $cursor Cursor to store.
	 */
	private static function save( array $cursor ): void {
		update_option( Migrator::IDENTITY_BACKFILL_OPTION, $cursor, false );
	}

	/**
	 * Highest submission id for one form, or zero when it has none.
	 *
	 * @param int $form_id Form id.
	 */
	private static function max_id_for_form( int $form_id ): int {
		global $wpdb;

		$table = Schema::submissions_table();
		$until = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(id) FROM {$table} WHERE form_id = %d",
				$form_id
			)
		);
		if ( '' !== (string) $wpdb->last_error || ! is_numeric( $until ) ) {
			return 0;
		}

		return (int) $until;
	}

	/**
	 * Follow-up jobs stored behind the active cursor.
	 *
	 * @param mixed $stored Raw queue.
	 * @return array<int, array{form_id: int, after_id: int, until_id: int}>
	 */
	private static function queue( mixed $stored ): array {
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$queue = array();
		foreach ( $stored as $job ) {
			if ( ! is_array( $job ) ) {
				continue;
			}

			$form_id = isset( $job['form_id'] ) && is_numeric( $job['form_id'] ) ? (int) $job['form_id'] : 0;
			$after   = isset( $job['after_id'] ) && is_numeric( $job['after_id'] ) ? (int) $job['after_id'] : 0;
			$until   = isset( $job['until_id'] ) && is_numeric( $job['until_id'] ) ? (int) $job['until_id'] : 0;
			if ( $form_id < 1 || $until < 1 || $after >= $until ) {
				continue;
			}

			$queue[] = array(
				'form_id'  => $form_id,
				'after_id' => $after,
				'until_id' => $until,
			);
		}

		return $queue;
	}

	/**
	 * Cursor after the active job has no rows left.
	 *
	 * @param array{status: string, after_id: int, until_id: int, form_id: int, queue: array<int, array{form_id: int, after_id: int, until_id: int}>} $cursor Active cursor.
	 * @return array{status: string, after_id: int, until_id: int, form_id: int, queue: array<int, array{form_id: int, after_id: int, until_id: int}>}
	 */
	private function promote( array $cursor ): array {
		if ( array() === $cursor['queue'] ) {
			return self::finished( $cursor );
		}

		$next = array_shift( $cursor['queue'] );
		if ( ! is_array( $next ) ) {
			return self::finished( $cursor );
		}

		return array(
			'status'   => 'pending',
			'after_id' => $next['after_id'],
			'until_id' => $next['until_id'],
			'form_id'  => $next['form_id'],
			'queue'    => array_values( $cursor['queue'] ),
		);
	}

	/**
	 * Cursor that records a finished job.
	 *
	 * @param array{status: string, after_id: int, until_id: int, form_id: int, queue: array<int, array{form_id: int, after_id: int, until_id: int}>} $cursor Active cursor.
	 * @return array{status: string, after_id: int, until_id: int, form_id: int, queue: array<int, array{form_id: int, after_id: int, until_id: int}>}
	 */
	private static function finished( array $cursor ): array {
		return array(
			'status'   => 'done',
			'after_id' => $cursor['until_id'],
			'until_id' => $cursor['until_id'],
			'form_id'  => $cursor['form_id'],
			'queue'    => array(),
		);
	}

	/**
	 * Next submissions in this job, oldest id first.
	 *
	 * @param array{status: string, after_id: int, until_id: int, form_id: int, queue: array<int, array{form_id: int, after_id: int, until_id: int}>} $cursor Active cursor.
	 * @param int                                                                                                                                     $limit  Maximum rows.
	 * @return list<array{id: int, form_id: int, payload: string, created_at: string}>
	 */
	private function next_batch( array $cursor, int $limit ): array {
		global $wpdb;

		$table = Schema::submissions_table();
		if ( $cursor['form_id'] > 0 ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, form_id, payload, created_at FROM {$table} WHERE id > %d AND id <= %d AND form_id = %d ORDER BY id ASC LIMIT %d",
					$cursor['after_id'],
					$cursor['until_id'],
					$cursor['form_id'],
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, form_id, payload, created_at FROM {$table} WHERE id > %d AND id <= %d ORDER BY id ASC LIMIT %d",
					$cursor['after_id'],
					$cursor['until_id'],
					$limit
				),
				ARRAY_A
			);
		}
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$batch = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! is_numeric( $row['id'] ?? null ) || ! is_numeric( $row['form_id'] ?? null ) ) {
				continue;
			}

			$batch[] = array(
				'id'         => (int) $row['id'],
				'form_id'    => (int) $row['form_id'],
				'payload'    => isset( $row['payload'] ) && is_string( $row['payload'] ) ? $row['payload'] : '',
				'created_at' => isset( $row['created_at'] ) && is_string( $row['created_at'] ) ? $row['created_at'] : gmdate( 'Y-m-d H:i:s' ),
			);
		}

		return $batch;
	}

	/**
	 * Store hashes for one submission. A missing form is skipped.
	 *
	 * The form's current marks are used. A repeated pass skips fields that
	 * are already stored, so stopping mid-batch is safe.
	 *
	 * @param array{id: int, form_id: int, payload: string, created_at: string} $row   Submission row.
	 * @param array<int, Form|null>                                             $forms Forms already loaded during this run.
	 */
	private function index_row( array $row, array &$forms ): bool {
		if ( ! array_key_exists( $row['form_id'], $forms ) ) {
			$forms[ $row['form_id'] ] = ( new FormRepository() )->find_by_id( $row['form_id'] );
		}

		$form = $forms[ $row['form_id'] ];
		if ( ! $form instanceof Form || array() === $form->identity_fields() ) {
			return true;
		}

		$decoded = json_decode( $row['payload'], true );
		if ( ! is_array( $decoded ) ) {
			return true;
		}

		$hashes = IdentityHasher::hashes( $form, self::values( $decoded ) );
		return ( new IdentityRepository() )->sync_submission( $row['id'], $row['form_id'], $hashes, $row['created_at'] );
	}

	/**
	 * Payload values the hasher can read.
	 *
	 * @param array<mixed, mixed> $decoded Decoded submission payload.
	 * @return array<string, string|bool|list<string>>
	 */
	private static function values( array $decoded ): array {
		$values = array();
		foreach ( $decoded as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}

			if ( is_string( $value ) || is_bool( $value ) ) {
				$values[ $key ] = $value;
				continue;
			}

			if ( ! is_array( $value ) ) {
				continue;
			}

			$items = array();
			foreach ( $value as $item ) {
				if ( is_string( $item ) ) {
					$items[] = $item;
				}
			}
			$values[ $key ] = $items;
		}

		return $values;
	}

	/**
	 * Take the lock, or take over a lock older than LOCK_TTL.
	 *
	 * @param int $now Unix time.
	 */
	private function acquire_lock( int $now ): ?string {
		$token = $this->lock_token( wp_generate_uuid4(), $now );
		if ( add_option( self::LOCK_OPTION, $token, '', false ) ) {
			return $token;
		}

		$held = $this->lock_time( get_option( self::LOCK_OPTION, '' ) );
		if ( $held > 0 && ( $now - $held ) < self::LOCK_TTL ) {
			return null;
		}

		delete_option( self::LOCK_OPTION );
		$token = $this->lock_token( wp_generate_uuid4(), $now );
		if ( ! add_option( self::LOCK_OPTION, $token, '', false ) ) {
			return null;
		}

		return $token;
	}

	/**
	 * Move this run's lock time forward after a batch.
	 *
	 * @param string $token Lock token returned by acquire_lock().
	 */
	private function touch_lock( string $token ): void {
		$current = get_option( self::LOCK_OPTION, '' );
		if ( ! $this->owns_lock( $current, $token ) ) {
			return;
		}

		update_option( self::LOCK_OPTION, $this->lock_token( $this->lock_id( $token ), time() ), false );
	}

	/**
	 * Remove the lock when it still belongs to this run.
	 *
	 * @param string $token Lock token returned by acquire_lock().
	 */
	private function release_lock( string $token ): void {
		$current = get_option( self::LOCK_OPTION, '' );
		if ( $this->owns_lock( $current, $token ) ) {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Whether the stored lock still carries this run's id.
	 *
	 * @param mixed  $current Value stored in the lock option.
	 * @param string $token   Lock token returned by acquire_lock().
	 */
	private function owns_lock( mixed $current, string $token ): bool {
		$id = $this->lock_id( $token );
		if ( '' === $id || ! is_string( $current ) ) {
			return false;
		}

		return str_starts_with( $current, $id . '|' );
	}

	/**
	 * Id prefix of a lock token.
	 *
	 * @param string $token Lock token.
	 */
	private function lock_id( string $token ): string {
		$id = strstr( $token, '|', true );
		return is_string( $id ) ? $id : '';
	}

	/**
	 * Unix time stored in a lock, or zero when the value has none.
	 *
	 * @param mixed $value Stored lock.
	 */
	private function lock_time( mixed $value ): int {
		if ( ! is_string( $value ) ) {
			return 0;
		}

		$separator = strrpos( $value, '|' );
		if ( false === $separator ) {
			return 0;
		}

		$time = substr( $value, $separator + 1 );
		if ( ! is_numeric( $time ) ) {
			return 0;
		}

		return (int) $time;
	}

	/**
	 * Lock value for one run at one moment.
	 *
	 * @param string $id Lock id.
	 * @param int    $at Unix time.
	 */
	private function lock_token( string $id, int $at ): string {
		return $id . '|' . $at;
	}
}
