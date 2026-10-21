<?php
/**
 * Stores a validated submission, with its idempotency key.
 *
 * @package Formstead
 */

namespace Formstead\Submissions;

use Formstead\Database\Rollback;
use Formstead\Database\Transaction;
use Formstead\Delivery\DeliveryRepository;
use Formstead\Delivery\Scheduler;
use Formstead\Forms\Form;
use WP_Error;

/**
 * Writes the key and the submission in one transaction.
 *
 * A replay or a conflict rolls the transaction back, so a second request
 * does not leave a new row. A form with recipients gets its delivery row
 * in this same transaction.
 */
final class SubmissionIntake {

	/**
	 * Accept normalized values.
	 *
	 * @param Form                                    $form   Form that received the submission.
	 * @param array<string, string|bool|list<string>> $values Normalized field values.
	 * @param IdempotencyKey|null                     $key    Idempotency key, or null when the client did not send one.
	 */
	public function accept( Form $form, array $values, ?IdempotencyKey $key ): IntakeResult {
		$result = Transaction::run(
			function () use ( $form, $values, $key ) {
				return $this->store( $form, $values, $key );
			}
		);

		( new IdempotencyRepository() )->maybe_prune( time() );

		if ( $result instanceof IntakeResult ) {
			if ( $result->is_accepted() && is_int( $result->delivery_id() ) ) {
				Scheduler::schedule( $result->delivery_id() );
			}

			return $result;
		}

		return IntakeResult::failed();
	}

	/**
	 * Insert the key and the submission. The caller owns the transaction.
	 *
	 * @param Form                                    $form   Form that received the submission.
	 * @param array<string, string|bool|list<string>> $values Normalized field values.
	 * @param IdempotencyKey|null                     $key    Idempotency key, or null.
	 */
	private function store( Form $form, array $values, ?IdempotencyKey $key ): IntakeResult|Rollback|WP_Error {
		$claim = null;
		if ( $key instanceof IdempotencyKey ) {
			$body_hash = IdempotencyKey::body_hash( $values );
			if ( ! is_string( $body_hash ) ) {
				return new WP_Error(
					'formstead_submission_save_failed',
					__( 'The submission could not be saved.', 'formstead' )
				);
			}

			$keys  = new IdempotencyRepository();
			$claim = $keys->claim( $form->id(), $key->hash(), $body_hash );
			if ( $claim->is_failed() ) {
				return new WP_Error(
					'formstead_submission_save_failed',
					__( 'The submission could not be saved.', 'formstead' )
				);
			}
			if ( $claim->is_conflict() ) {
				return new Rollback( IntakeResult::conflict() );
			}
			if ( $claim->is_replay() ) {
				$existing = ( new SubmissionRepository() )->find( $claim->submission_id(), true );
				if ( $existing instanceof Submission && $existing->form_id() === $form->id() ) {
					return new Rollback( IntakeResult::replayed( $existing ) );
				}

				$claim = $keys->reopen( $form->id(), $key->hash(), $body_hash );
				if ( ! $claim->is_inserted() ) {
					return new WP_Error(
						'formstead_submission_save_failed',
						__( 'The submission could not be saved.', 'formstead' )
					);
				}
			}
		}

		$saved = ( new SubmissionRepository() )->insert( $form, $values );
		if ( $saved instanceof WP_Error ) {
			return $saved;
		}

		if ( $claim instanceof IdempotencyClaim && $claim->is_inserted() ) {
			$attached = ( new IdempotencyRepository() )->attach( $claim->id(), $saved->id() );
			if ( ! $attached ) {
				return new WP_Error(
					'formstead_submission_save_failed',
					__( 'The submission could not be saved.', 'formstead' )
				);
			}
		}

		$delivery_id = null;
		if ( $form->notifications()->has_recipients() ) {
			$created = ( new DeliveryRepository() )->create( $saved->id() );
			if ( $created instanceof WP_Error ) {
				return $created;
			}
			$delivery_id = $created;
		}

		return IntakeResult::accepted( $saved, $delivery_id );
	}
}
