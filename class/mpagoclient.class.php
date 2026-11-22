<?php
/* Copyright (C) 2026 Briones Andres
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    class/mpagoclient.class.php
 * \ingroup mpago
 * \brief   REST client for the Mercado Pago Orders API.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
require_once __DIR__.'/../lib/mpago.lib.php';

/**
 * REST client for the Mercado Pago Orders API. Never throws: a failed call returns null and sets $error.
 */
class MpagoClient
{
	const DEFAULT_BASE_URL = 'https://api.mercadopago.com';

	/**
	 * @var string Message of the last error, safe to log and to show (never contains the access token)
	 */
	public $error = '';

	/**
	 * @var string Kind of the last error: 'config', 'network', 'unauthorized', 'not_found', 'conflict', 'bad_request', 'server_error' or 'bad_response'
	 */
	public $errorCode = '';

	/**
	 * @var int HTTP status of the last answer, 0 if there was none
	 */
	public $httpCode = 0;

	/**
	 * @var string
	 */
	private $accessToken;

	/**
	 * @var string
	 */
	private $baseUrl;

	/**
	 * Constructor
	 *
	 * @param	string|null	$accessToken	Access token, null to use the one of the setup
	 * @param	string|null	$baseUrl		Base URL of the API, null to use the setup (constant MPAGO_API_BASE_URL) or the official one
	 */
	public function __construct($accessToken = null, $baseUrl = null)
	{
		$this->accessToken = ($accessToken === null ? getDolGlobalString('MPAGO_ACCESS_TOKEN') : $accessToken);
		if ($baseUrl === null) {
			$baseUrl = getDolGlobalString('MPAGO_API_BASE_URL');
		}
		$this->baseUrl = ($baseUrl === '' ? self::DEFAULT_BASE_URL : rtrim($baseUrl, '/'));
	}

	/**
	 * Create an order (POST /v1/orders).
	 *
	 * @param	array<string,mixed>	$body				Request body
	 * @param	string				$idempotencyKey		Value of the X-Idempotency-Key header (1 to 128 characters)
	 * @return	array<string,mixed>|null				Order returned by Mercado Pago, null on error
	 */
	public function createOrder(array $body, $idempotencyKey)
	{
		$json = json_encode($body);
		if ($json === false || $idempotencyKey === '' || strlen($idempotencyKey) > 128) {
			return $this->fail('config', 'Invalid order request');
		}

		$order = $this->request('POSTALREADYFORMATED', '/v1/orders', $json, array('X-Idempotency-Key: '.$idempotencyKey));
		if ($order !== null && mpagoString($order['id'] ?? '') === '') {
			return $this->fail('bad_response', 'Answer without order id');
		}

		return $order;
	}

	/**
	 * Read an order (GET /v1/orders/{id}).
	 *
	 * @param	string	$id			Mercado Pago order id
	 * @return	array<string,mixed>|null	Order returned by Mercado Pago, null on error
	 */
	public function getOrder($id)
	{
		if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
			return $this->fail('config', 'Invalid order id');
		}

		$order = $this->request('GET', '/v1/orders/'.$id, '', array());
		if ($order !== null && mpagoString($order['id'] ?? '') !== $id) {
			return $this->fail('bad_response', 'Answer for another order id');
		}

		return $order;
	}

	/**
	 * Check that the access token is accepted, by reading an order that cannot exist.
	 *
	 * @return	int		1 if the token is accepted, 0 if it is refused, -1 if it could not be checked (see $error)
	 */
	public function checkCredentials()
	{
		$this->request('GET', '/v1/orders/ORD00000000000000000000000000', '', array());
		if ($this->errorCode === 'not_found' || $this->errorCode === '') {
			$this->error = '';
			$this->errorCode = '';
			return 1;
		}
		if ($this->errorCode === 'unauthorized') {
			return 0;
		}

		return -1;
	}

	/**
	 * Send a request and decode the answer.
	 *
	 * @param	'GET'|'POSTALREADYFORMATED'	$method		Method, as getURLContent() names it
	 * @param	string						$path		Path below the base URL
	 * @param	string						$payload	JSON body
	 * @param	string[]					$headers	Headers added to the authentication ones
	 * @return	array<string,mixed>|null				Decoded answer, null on error
	 */
	private function request($method, $path, $payload, array $headers)
	{
		$this->error = '';
		$this->errorCode = '';
		$this->httpCode = 0;

		if ($this->accessToken === '') {
			return $this->fail('config', 'No access token configured');
		}
		if (!preg_match('/^https?:\/\//', $this->baseUrl)) {
			return $this->fail('config', 'Invalid API base URL');
		}

		$headers[] = 'Authorization: Bearer '.$this->accessToken;
		$headers[] = 'Content-Type: application/json';
		$headers[] = 'Accept: application/json';

		// Local addresses are refused unless the base URL was overridden on purpose (tests against a fake API).
		$localurl = ($this->baseUrl === self::DEFAULT_BASE_URL ? 0 : 2);

		// getURLContent() writes the request headers, Authorization included, to the Dolibarr log at
		// LOG_INFO (and to the curl debug log at LOG_DEBUG): keep the log below that level during the call.
		$previouslevel = getDolGlobalInt('SYSLOG_LEVEL');
		if ($previouslevel >= LOG_INFO) {
			mpagoSetConfInMemory('SYSLOG_LEVEL', (string) LOG_NOTICE);
		}
		try {
			$result = getURLContent($this->baseUrl.$path, $method, $payload, 0, $headers, array('http', 'https'), $localurl, 1);
		} finally {
			if ($previouslevel >= LOG_INFO) {
				mpagoSetConfInMemory('SYSLOG_LEVEL', (string) $previouslevel);
			}
		}

		$this->httpCode = mpagoInt($result['http_code']);
		$content = mpagoString($result['content']);

		if (!empty($result['curl_error_no'])) {
			return $this->fail('network', 'Connection error: '.mpagoString($result['curl_error_msg']));
		}

		$data = json_decode($content, true);

		if ($this->httpCode < 200 || $this->httpCode >= 300) {
			$kind = 'bad_request';
			if ($this->httpCode == 401 || $this->httpCode == 403) {
				$kind = 'unauthorized';
			} elseif ($this->httpCode == 404) {
				$kind = 'not_found';
			} elseif ($this->httpCode == 409) {
				$kind = 'conflict';
			} elseif ($this->httpCode >= 500 || $this->httpCode < 200) {
				$kind = 'server_error';
			}
			return $this->fail($kind, trim('HTTP '.$this->httpCode.' '.$this->describeError($data)));
		}

		if (!is_array($data)) {
			return $this->fail('bad_response', 'HTTP '.$this->httpCode.' with a body that is not JSON');
		}

		$order = array();
		foreach ($data as $key => $value) {
			$order[(string) $key] = $value;
		}

		return $order;
	}

	/**
	 * Extract the error code and message from an error answer.
	 *
	 * @param	mixed	$data	Decoded body
	 * @return	string			Short description, '' if the body says nothing usable
	 */
	private function describeError($data)
	{
		if (!is_array($data)) {
			return '';
		}

		$parts = array();
		$sources = array($data);
		if (isset($data['errors']) && is_array($data['errors']) && isset($data['errors'][0]) && is_array($data['errors'][0])) {
			$sources[] = $data['errors'][0];
		}
		foreach ($sources as $source) {
			foreach (array('code', 'error', 'message') as $key) {
				$text = mpagoString($source[$key] ?? '');
				if ($text !== '' && !in_array($text, $parts, true)) {
					$parts[] = $text;
				}
			}
		}

		return dol_trunc(implode(': ', $parts), 300);
	}

	/**
	 * Record an error.
	 *
	 * @param	string	$code		Kind of error
	 * @param	string	$message	Message
	 * @return	null
	 */
	private function fail($code, $message)
	{
		if ($this->accessToken !== '') {
			$message = str_replace($this->accessToken, '***', $message);
		}
		$this->errorCode = $code;
		$this->error = $message;

		return null;
	}
}
