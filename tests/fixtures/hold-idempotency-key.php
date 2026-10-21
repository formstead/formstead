<?php
/**
 * Holds a database transaction open so a PHPUnit request can race it.
 *
 * PHPUnit starts this file with proc_open. It is not part of the plugin.
 * `hold` inserts a submission and its key, prints `ready`, waits, then commits.
 * `gap` deletes a missing expired key (the old claim prefix), prints `ready`,
 * waits, then inserts. A duplicate or a lock wait in `gap` rolls back and exits
 * 0, because the PHPUnit request may have stored the key first.
 *
 * @package Formstead
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.RestrictedFunctions.mysql_mysqli_query, WordPress.DB.RestrictedFunctions.mysql_mysqli_real_connect, WordPress.DB.RestrictedFunctions.mysql_mysqli_init, WordPress.DB.RestrictedFunctions.mysql_mysqli_report, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.Security.EscapeOutput.OutputNotEscaped -- standalone process, no WordPress.

mysqli_report( MYSQLI_REPORT_OFF );

/**
 * Read one environment value.
 *
 * @param string $name     Variable name.
 * @param bool   $required Whether an empty value is a failure.
 */
function formstead_hold_env( string $name, bool $required = true ): string {
	$value = getenv( $name );
	if ( ! is_string( $value ) ) {
		$value = '';
	}
	if ( $required && '' === $value ) {
		formstead_hold_fail( 'Missing ' . $name . '.' );
	}

	return $value;
}

/**
 * Quote a table name that is limited to safe characters.
 *
 * @param string $name Table name from the test.
 */
function formstead_hold_table( string $name ): string {
	if ( 1 !== preg_match( '/\A[A-Za-z0-9_]+\z/', $name ) ) {
		formstead_hold_fail( 'Unsafe table name.' );
	}

	return '`' . $name . '`';
}

/**
 * Write a message and stop.
 *
 * @param string $message Error text.
 */
function formstead_hold_fail( string $message ): void {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

/**
 * Run one statement.
 *
 * @param mysqli $db  Open connection.
 * @param string $sql Statement to run.
 */
function formstead_hold_exec( mysqli $db, string $sql ): bool {
	$result = $db->query( $sql );
	if ( $result instanceof mysqli_result ) {
		$result->free();
		return true;
	}

	return true === $result;
}

/**
 * Whether this error is a duplicate key, a deadlock, or a lock wait.
 *
 * @param mysqli $db Open connection.
 */
function formstead_hold_is_contention( mysqli $db ): bool {
	return in_array( $db->errno, array( 1062, 1205, 1213 ), true );
}

/**
 * Open a transaction, signal readiness, then commit after the pause.
 */
function formstead_hold_main(): void {
	$mode = formstead_hold_env( 'FORMSTEAD_MODE' );
	if ( ! in_array( $mode, array( 'hold', 'gap' ), true ) ) {
		formstead_hold_fail( 'Unknown mode.' );
	}

	$port   = (int) formstead_hold_env( 'FORMSTEAD_DB_PORT', false );
	$socket = formstead_hold_env( 'FORMSTEAD_DB_SOCKET', false );
	$host   = formstead_hold_env( 'FORMSTEAD_DB_HOST' );

	// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_init -- a second connection must commit outside PHPUnit.
	$mysqli = mysqli_init();
	if ( ! $mysqli instanceof mysqli ) {
		formstead_hold_fail( 'mysqli_init failed.' );
	}

	$connected = $mysqli->real_connect(
		$host,
		formstead_hold_env( 'FORMSTEAD_DB_USER' ),
		formstead_hold_env( 'FORMSTEAD_DB_PASSWORD', false ),
		formstead_hold_env( 'FORMSTEAD_DB_NAME' ),
		$port > 0 ? $port : null,
		'' !== $socket ? $socket : null
	);
	if ( ! $connected ) {
		formstead_hold_fail( 'Database connection failed: ' . $mysqli->connect_error );
	}

	$mysqli->set_charset( 'utf8mb4' );
	formstead_hold_exec( $mysqli, 'SET SESSION innodb_lock_wait_timeout = 3' );

	$submissions = formstead_hold_table( formstead_hold_env( 'FORMSTEAD_SUBMISSIONS_TABLE' ) );
	$keys        = formstead_hold_table( formstead_hold_env( 'FORMSTEAD_KEYS_TABLE' ) );
	$form_id     = (int) formstead_hold_env( 'FORMSTEAD_FORM_ID' );
	$version     = (int) formstead_hold_env( 'FORMSTEAD_SCHEMA_VERSION' );
	$key_hash    = $mysqli->real_escape_string( formstead_hold_env( 'FORMSTEAD_KEY_HASH' ) );
	$body_hash   = $mysqli->real_escape_string( formstead_hold_env( 'FORMSTEAD_BODY_HASH' ) );
	$receipt     = $mysqli->real_escape_string( formstead_hold_env( 'FORMSTEAD_RECEIPT' ) );
	$payload     = $mysqli->real_escape_string( formstead_hold_env( 'FORMSTEAD_PAYLOAD' ) );
	$created_at  = $mysqli->real_escape_string( formstead_hold_env( 'FORMSTEAD_CREATED_AT' ) );
	$hold        = (int) formstead_hold_env( 'FORMSTEAD_HOLD_SECONDS', false );
	$hold        = max( 1, min( 5, $hold ) );
	$now         = time();

	if ( ! formstead_hold_exec( $mysqli, 'START TRANSACTION' ) ) {
		formstead_hold_fail( 'START TRANSACTION failed: ' . $mysqli->error );
	}

	if ( 'gap' === $mode ) {
		$deleted = formstead_hold_exec(
			$mysqli,
			"DELETE FROM {$keys} WHERE form_id = {$form_id} AND key_hash = '{$key_hash}' AND expires_at <= {$now}"
		);
		if ( ! $deleted ) {
			$mysqli->query( 'ROLLBACK' );
			formstead_hold_fail( 'DELETE failed: ' . $mysqli->error );
		}
	} else {
		formstead_hold_insert( $mysqli, $submissions, $keys, $form_id, $version, $receipt, $payload, $created_at, $key_hash, $body_hash, false );
	}

	fwrite( STDOUT, "ready\n" );
	fflush( STDOUT );
	sleep( $hold );

	if ( 'gap' === $mode ) {
		formstead_hold_insert( $mysqli, $submissions, $keys, $form_id, $version, $receipt, $payload, $created_at, $key_hash, $body_hash, true );
	}

	if ( ! formstead_hold_exec( $mysqli, 'COMMIT' ) ) {
		$mysqli->query( 'ROLLBACK' );
		formstead_hold_fail( 'COMMIT failed: ' . $mysqli->error );
	}

	$mysqli->close();
}

/**
 * Insert a submission and the key that points at it.
 *
 * In gap mode a duplicate or a lock wait is an acceptable ending: the PHPUnit
 * request stored the key first, so this process rolls back and exits.
 *
 * @param mysqli $db             Open connection.
 * @param string $submissions    Quoted submissions table.
 * @param string $keys           Quoted keys table.
 * @param int    $form_id        Form id.
 * @param int    $version        Schema version.
 * @param string $receipt        Escaped receipt id.
 * @param string $payload        Escaped payload JSON.
 * @param string $created_at     Escaped created-at timestamp.
 * @param string $key_hash       Escaped key hash.
 * @param string $body_hash      Escaped body hash.
 * @param bool   $allow_conflict Whether a lock conflict ends the process.
 */
function formstead_hold_insert(
	mysqli $db,
	string $submissions,
	string $keys,
	int $form_id,
	int $version,
	string $receipt,
	string $payload,
	string $created_at,
	string $key_hash,
	string $body_hash,
	bool $allow_conflict
): void {
	$saved = formstead_hold_exec(
		$db,
		"INSERT INTO {$submissions} (form_id, schema_version, receipt_id, payload, status, created_at) VALUES ({$form_id}, {$version}, '{$receipt}', '{$payload}', 'new', '{$created_at}')"
	);
	if ( ! $saved ) {
		formstead_hold_finish_conflict( $db, $allow_conflict, 'Submission insert failed: ' );
	}

	$submission_id = (int) $db->insert_id;
	$expires_at    = time() + 86400;
	$reserved      = formstead_hold_exec(
		$db,
		"INSERT INTO {$keys} (form_id, key_hash, body_hash, submission_id, expires_at) VALUES ({$form_id}, '{$key_hash}', '{$body_hash}', {$submission_id}, {$expires_at})"
	);
	if ( ! $reserved ) {
		formstead_hold_finish_conflict( $db, $allow_conflict, 'Key insert failed: ' );
	}
}

/**
 * Roll back and either exit or report a real error.
 *
 * @param mysqli $db             Open connection.
 * @param bool   $allow_conflict Whether a lock conflict is success.
 * @param string $prefix         Error prefix when this is a real failure.
 */
function formstead_hold_finish_conflict( mysqli $db, bool $allow_conflict, string $prefix ): void {
	$conflict = $allow_conflict && formstead_hold_is_contention( $db );
	$message  = $db->error;
	$db->query( 'ROLLBACK' );
	if ( $conflict ) {
		exit( 0 );
	}

	formstead_hold_fail( $prefix . $message );
}

formstead_hold_main();
