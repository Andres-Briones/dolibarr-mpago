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
 * \file    test/fake/router.php
 * \ingroup mpago
 * \brief   Fake Mercado Pago Orders API for the tests. Never used by the module itself.
 *
 * Run with: MPAGO_FAKE_STATE=/path/state.json php -S 127.0.0.1:<port> test/fake/router.php
 *
 * API:        POST /v1/orders, GET /v1/orders/{id}
 * Checkout:   GET /checkout/v1/redirect?order_id={id}
 * Test only:  POST /__test/orders/{id}  body {"pay":true,"amount":"12.00"} or any of status, status_detail,
 *                                        total_paid_amount, currency, external_reference
 *             POST /__test/config        body {"token":"...","fail_next":500} (fail_next -1 answers a body that is not JSON)
 *             GET  /__test/state         everything, with the requests received
 */

/**
 * Send a JSON answer.
 *
 * @param	int		$code	HTTP status
 * @param	mixed	$data	Body
 * @return	void
 */
function mpagoFakeAnswer($code, $data)
{
	http_response_code($code);
	header('Content-Type: application/json');
	print json_encode($data);
}

/**
 * Send an error answer in the shape of the Orders API.
 *
 * @param	int		$code		HTTP status
 * @param	string	$errorcode	Error code
 * @return	void
 */
function mpagoFakeError($code, $errorcode)
{
	mpagoFakeAnswer($code, array('errors' => array(array('code' => $errorcode, 'message' => 'Fake API: '.$errorcode))));
}

/**
 * Random upper case identifier.
 *
 * @param	string	$prefix		Prefix
 * @return	string				Identifier of 26 characters after the prefix
 */
function mpagoFakeId($prefix)
{
	return $prefix.strtoupper(substr(bin2hex(random_bytes(13)), 0, 26));
}

/**
 * Read a value of unknown type as a string.
 *
 * @param	mixed	$value	Value
 * @return	string
 */
function mpagoFakeString($value)
{
	return is_scalar($value) ? (string) $value : '';
}

/**
 * Handle one request.
 *
 * @param	array<string,mixed>	$state		State of the fake, modified in place
 * @param	string				$method		HTTP method
 * @param	string				$path		Path of the request
 * @param	string				$rawbody	Body of the request
 * @return	void
 */
function mpagoFakeHandle(array &$state, $method, $path, $rawbody)
{
	$body = json_decode($rawbody, true);
	if (!is_array($body)) {
		$body = array();
	}
	$orders = (isset($state['orders']) && is_array($state['orders']) ? $state['orders'] : array());
	$keys = (isset($state['idempotency']) && is_array($state['idempotency']) ? $state['idempotency'] : array());
	$requests = (isset($state['requests']) && is_array($state['requests']) ? $state['requests'] : array());
	$token = mpagoFakeString($state['token'] ?? 'APP_USR-fake-token');
	$matches = array();

	if ($path === '/__test/state' && $method === 'GET') {
		mpagoFakeAnswer(200, $state);
		return;
	}
	if ($path === '/__test/config' && $method === 'POST') {
		foreach (array('token', 'fail_next') as $key) {
			if (array_key_exists($key, $body)) {
				$state[$key] = $body[$key];
			}
		}
		mpagoFakeAnswer(200, array('ok' => true));
		return;
	}
	if ($method === 'POST' && preg_match('#^/__test/orders/([A-Za-z0-9]+)$#', $path, $matches)) {
		$id = $matches[1];
		if (!isset($orders[$id]) || !is_array($orders[$id])) {
			mpagoFakeError(404, 'order_not_found');
			return;
		}
		$order = $orders[$id];
		if (!empty($body['pay'])) {
			$amount = mpagoFakeString($body['amount'] ?? $order['total_amount']);
			$order['status'] = 'processed';
			$order['status_detail'] = 'accredited';
			$order['total_paid_amount'] = $amount;
			$order['transactions'] = array('payments' => array(array(
				'id' => mpagoFakeId('PAY01'),
				'amount' => $amount,
				'paid_amount' => $amount,
				'status' => 'processed',
				'status_detail' => 'accredited',
				'payment_method' => array('id' => 'visa', 'type' => 'credit_card', 'installments' => 3),
			)));
		}
		foreach (array('status', 'status_detail', 'total_paid_amount', 'currency', 'external_reference') as $key) {
			if (array_key_exists($key, $body)) {
				$order[$key] = $body[$key];
			}
		}
		$orders[$id] = $order;
		$state['orders'] = $orders;
		mpagoFakeAnswer(200, $order);
		return;
	}
	if ($path === '/checkout/v1/redirect' && $method === 'GET') {
		header('Content-Type: text/html; charset=UTF-8');
		print '<html><body><h1>Fake Mercado Pago checkout</h1></body></html>';
		return;
	}

	if (strpos($path, '/v1/') !== 0) {
		mpagoFakeError(404, 'not_found');
		return;
	}

	$idempotencykey = mpagoFakeString($_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? '');
	$requests[] = array(
		'method' => $method,
		'path' => $path,
		'authorization' => mpagoFakeString($_SERVER['HTTP_AUTHORIZATION'] ?? ''),
		'idempotency_key' => $idempotencykey,
		'content_type' => mpagoFakeString($_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? '')),
		'body' => $body,
	);
	$state['requests'] = $requests;

	$failnext = (isset($state['fail_next']) && is_numeric($state['fail_next']) ? (int) $state['fail_next'] : 0);
	if ($failnext != 0) {
		$state['fail_next'] = 0;
		if ($failnext < 0) {
			header('Content-Type: text/html');
			print '<html>not json</html>';
		} else {
			mpagoFakeError($failnext, 'internal_error');
		}
		return;
	}

	if (mpagoFakeString($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer '.$token) {
		mpagoFakeError(401, 'unauthorized');
		return;
	}

	if ($path === '/v1/orders' && $method === 'POST') {
		if ($idempotencykey === '') {
			mpagoFakeError(400, 'empty_required_header');
			return;
		}
		if (isset($keys[$idempotencykey])) {
			mpagoFakeError(409, 'idempotency_key_already_used');
			return;
		}
		$total = $body['total_amount'] ?? null;
		$reference = mpagoFakeString($body['external_reference'] ?? '');
		$payer = (isset($body['payer']) && is_array($body['payer']) ? $body['payer'] : array());
		if (($body['type'] ?? '') !== 'online' || ($body['processing_mode'] ?? '') !== 'manual' || !is_string($total) || !preg_match('/^[0-9]+\.[0-9]{2}$/', $total)
			|| !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $reference) || mpagoFakeString($payer['email'] ?? '') === '') {
			mpagoFakeError(400, 'invalid_properties');
			return;
		}

		$id = mpagoFakeId('ORDTST');
		$host = mpagoFakeString($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
		$order = array(
			'id' => $id,
			'type' => 'online',
			'processing_mode' => 'manual',
			'external_reference' => $reference,
			'total_amount' => $total,
			'total_paid_amount' => '0.00',
			'currency' => 'ARS',
			'country_code' => 'ARG',
			'status' => 'created',
			'status_detail' => 'created',
			'checkout_url' => 'http://'.$host.'/checkout/v1/redirect?order_id='.$id,
			'transactions' => array('payments' => array()),
			'config' => $body['config'] ?? null,
		);
		$orders[$id] = $order;
		$keys[$idempotencykey] = $id;
		$state['orders'] = $orders;
		$state['idempotency'] = $keys;
		mpagoFakeAnswer(201, $order);
		return;
	}

	if ($method === 'GET' && preg_match('#^/v1/orders/([A-Za-z0-9_-]+)$#', $path, $matches)) {
		if (!isset($orders[$matches[1]])) {
			mpagoFakeError(404, 'order_not_found');
			return;
		}
		mpagoFakeAnswer(200, $orders[$matches[1]]);
		return;
	}

	mpagoFakeError(404, 'not_found');
}

if (PHP_SAPI !== 'cli-server') {
	fwrite(STDERR, "This script is a router for PHP's built-in web server\n");
	exit(1);
}

$mpagofakefile = getenv('MPAGO_FAKE_STATE');
if (!is_string($mpagofakefile) || $mpagofakefile === '') {
	mpagoFakeError(500, 'MPAGO_FAKE_STATE is not set');
	return true;
}

// One request at a time: the state is read, changed and written under an exclusive lock.
$mpagofakehandle = fopen($mpagofakefile, 'c+');
if ($mpagofakehandle === false || !flock($mpagofakehandle, LOCK_EX)) {
	mpagoFakeError(500, 'state file not usable');
	return true;
}
$mpagofakestate = json_decode((string) stream_get_contents($mpagofakehandle), true);
$mpagofakeclean = array();
if (is_array($mpagofakestate)) {
	foreach ($mpagofakestate as $mpagofakekey => $mpagofakevalue) {
		$mpagofakeclean[(string) $mpagofakekey] = $mpagofakevalue;
	}
}

mpagoFakeHandle(
	$mpagofakeclean,
	mpagoFakeString($_SERVER['REQUEST_METHOD'] ?? 'GET'),
	mpagoFakeString(parse_url(mpagoFakeString($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH)),
	(string) file_get_contents('php://input')
);

ftruncate($mpagofakehandle, 0);
rewind($mpagofakehandle);
fwrite($mpagofakehandle, (string) json_encode($mpagofakeclean));
flock($mpagofakehandle, LOCK_UN);
fclose($mpagofakehandle);

return true;
