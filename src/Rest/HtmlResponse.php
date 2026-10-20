<?php
/**
 * REST response whose body is an HTML page.
 *
 * @package Formstead
 */

namespace Formstead\Rest;

use WP_REST_Response;

/**
 * Carries a finished HTML document for {@see HtmlResponder}.
 */
final class HtmlResponse extends WP_REST_Response {

	/**
	 * Escaped HTML document.
	 *
	 * @var string
	 */
	private string $html;

	/**
	 * Create an HTML response.
	 *
	 * @param string $html   Finished document.
	 * @param int    $status HTTP status code.
	 */
	public function __construct( string $html, int $status ) {
		parent::__construct(
			null,
			$status,
			array(
				'Content-Type'  => 'text/html; charset=utf-8',
				'Cache-Control' => 'no-store',
			)
		);
		$this->html = $html;
	}

	/**
	 * HTML document to send as the body.
	 */
	public function html(): string {
		return $this->html;
	}
}
