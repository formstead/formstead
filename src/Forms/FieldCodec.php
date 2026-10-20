<?php
/**
 * JSON for a list of form fields.
 *
 * @package Formstead
 */

namespace Formstead\Forms;

/**
 * Encodes and decodes the field schema stored on a form and its snapshots.
 */
final class FieldCodec {

	/**
	 * JSON for a field list.
	 *
	 * @param Field[] $fields Fields to store.
	 */
	public static function encode( array $fields ): string {
		$rows = array();
		foreach ( $fields as $field ) {
			$rows[] = $field->to_array();
		}

		$json = wp_json_encode( $rows, JSON_UNESCAPED_UNICODE );

		return is_string( $json ) ? $json : '[]';
	}

	/**
	 * Fields from stored JSON. Invalid rows are skipped.
	 *
	 * @param string $json Stored fields JSON.
	 * @return Field[]
	 */
	public static function decode( string $json ): array {
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$fields = array();
		foreach ( $decoded as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			if ( isset( $row['max_length'] ) && is_numeric( $row['max_length'] ) ) {
				$row['max_length'] = (int) $row['max_length'];
			}
			if ( isset( $row['scale'] ) && is_numeric( $row['scale'] ) ) {
				$row['scale'] = (int) $row['scale'];
			}
			$row['required'] = (bool) ( $row['required'] ?? false );
			$row['multiple'] = (bool) ( $row['multiple'] ?? false );

			$field = Field::from_array( $row );
			if ( $field instanceof Field ) {
				$fields[] = $field;
			}
		}

		return $fields;
	}
}
