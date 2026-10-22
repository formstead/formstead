<?php
/**
 * Stable webhook delivery error codes.
 *
 * @package Formstead
 */

namespace Formstead\Webhook;

/**
 * Codes stored on a webhook delivery. They do not contain the address or the response.
 */
final class WebhookFailure {

	/**
	 * The transport cannot guarantee a connection to the checked address.
	 */
	public const UNSAFE_TRANSPORT = 'webhook_unsafe_transport';

	/**
	 * The URL is not HTTPS, has userinfo, or points at a blocked address.
	 */
	public const BLOCKED_URL = 'webhook_blocked_url';

	/**
	 * The receiver answered with a redirect. It is not followed.
	 */
	public const REDIRECT = 'webhook_redirect';

	/**
	 * The connection exceeded the timeout.
	 */
	public const TIMEOUT = 'webhook_timeout';

	/**
	 * The connection could not be opened.
	 */
	public const CONNECT_FAILED = 'webhook_connect_failed';

	/**
	 * The receiver answered 429.
	 */
	public const HTTP_429 = 'webhook_http_429';

	/**
	 * The receiver answered 5xx.
	 */
	public const HTTP_5XX = 'webhook_http_5xx';

	/**
	 * The receiver answered another 4xx.
	 */
	public const HTTP_4XX = 'webhook_http_4xx';
}
