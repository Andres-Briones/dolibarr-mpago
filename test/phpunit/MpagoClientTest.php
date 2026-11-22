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
 * \file    test/phpunit/MpagoClientTest.php
 * \ingroup mpago
 * \brief   Tests of the REST client against the fake Mercado Pago API.
 */

require_once __DIR__.'/MpagoTestCase.php';
require_once DOL_DOCUMENT_ROOT.'/core/modules/syslog/logHandler.php';


/**
 * Log handler that keeps the messages in memory.
 */
class MpagoTestLogHandler extends LogHandler
{
	/**
	 * @var string
	 */
	public $code = 'mpagotest';

	/**
	 * @var string[]
	 */
	public $messages = array();

	/**
	 * Keep the message
	 *
	 * @param	array<string,mixed>	$content			Array containing the info about the message
	 * @param	string				$suffixinfilename	Not used
	 * @return	void
	 */
	public function export($content, $suffixinfilename = '')
	{
		$this->messages[] = mpagoString($content['message'] ?? '');
	}
}


/**
 * Class MpagoClientTest
 */
class MpagoClientTest extends MpagoTestCase
{
	/**
	 * Body accepted by the fake API.
	 *
	 * @param	string	$reference	External reference
	 * @return	array<string,mixed>
	 */
	private function body($reference)
	{
		return array(
			'type' => 'online',
			'processing_mode' => 'manual',
			'total_amount' => '150.00',
			'external_reference' => $reference,
			'payer' => array('email' => 'buyer@example.com'),
		);
	}

	/**
	 * createOrder() sends the token and the idempotency key and returns the decoded order; getOrder() reads it back.
	 *
	 * @return void
	 */
	public function testCreateAndGetOrder(): void
	{
		$client = new MpagoClient();
		$order = $client->createOrder($this->body('1-i900-1'), '1-i900-1-1');

		$this->assertNotNull($order, $client->error);
		$this->assertSame(201, $client->httpCode);
		$this->assertSame('created', $order['status']);
		$this->assertStringStartsWith('ORDTST', mpagoString($order['id']));
		$this->assertStringStartsWith(self::fakeUrl().'/checkout/', mpagoString($order['checkout_url']));

		$requests = self::fakeRequests();
		$sent = end($requests);
		$this->assertIsArray($sent);
		$this->assertSame('POST', $sent['method']);
		$this->assertSame('/v1/orders', $sent['path']);
		$this->assertSame('Bearer '.self::FAKE_TOKEN, $sent['authorization']);
		$this->assertSame('1-i900-1-1', $sent['idempotency_key']);
		$this->assertSame('application/json', $sent['content_type']);
		$this->assertSame($this->body('1-i900-1'), $sent['body']);

		$read = $client->getOrder(mpagoString($order['id']));
		$this->assertNotNull($read, $client->error);
		$this->assertSame($order['id'], $read['id']);
		$this->assertSame('1-i900-1', $read['external_reference']);
		$this->assertSame('', $client->error);
	}

	/**
	 * Each kind of failure gives null, an error kind and a message, never an exception.
	 *
	 * @return void
	 */
	public function testErrorMapping(): void
	{
		$client = new MpagoClient();

		$this->assertNull($client->getOrder('ORDTSTDOESNOTEXIST'));
		$this->assertSame('not_found', $client->errorCode);
		$this->assertSame(404, $client->httpCode);
		$this->assertStringContainsString('order_not_found', $client->error);

		$this->assertNotNull($client->createOrder($this->body('1-i901-1'), 'same-key'));
		$this->assertNull($client->createOrder($this->body('1-i901-2'), 'same-key'));
		$this->assertSame('conflict', $client->errorCode);
		$this->assertStringContainsString('idempotency_key_already_used', $client->error);

		$this->assertNull($client->createOrder(array('type' => 'online'), 'incomplete-body'));
		$this->assertSame('bad_request', $client->errorCode);
		$this->assertSame(400, $client->httpCode);

		self::fake('POST', '/__test/config', array('fail_next' => 503));
		$this->assertNull($client->getOrder('ORDTSTDOESNOTEXIST'));
		$this->assertSame('server_error', $client->errorCode);

		self::fake('POST', '/__test/config', array('fail_next' => -1));
		$this->assertNull($client->getOrder('ORDTSTDOESNOTEXIST'));
		$this->assertSame('bad_response', $client->errorCode);

		$this->assertNull($client->getOrder('../v1/orders'));
		$this->assertSame('config', $client->errorCode);
		$this->assertNull($client->createOrder($this->body('1-i901-3'), ''));
		$this->assertSame('config', $client->errorCode);

		$notoken = new MpagoClient('');
		$this->assertNull($notoken->getOrder('ORDTSTDOESNOTEXIST'));
		$this->assertSame('config', $notoken->errorCode);
	}

	/**
	 * A refused token and an unreachable server.
	 *
	 * @return void
	 */
	public function testUnauthorizedAndNetworkError(): void
	{
		$secret = 'APP_USR-wrong-token-9876543210';
		$client = new MpagoClient($secret);
		$this->assertNull($client->getOrder('ORDTSTDOESNOTEXIST'));
		$this->assertSame('unauthorized', $client->errorCode);
		$this->assertSame(401, $client->httpCode);
		$this->assertStringNotContainsString($secret, $client->error);
		$this->assertSame(0, $client->checkCredentials());

		$good = new MpagoClient();
		$this->assertSame(1, $good->checkCredentials());
		$this->assertSame('', $good->error);

		// Port 9 (discard) is closed on the loopback interface
		$unreachable = new MpagoClient(self::FAKE_TOKEN, 'http://127.0.0.1:9');
		$this->assertNull($unreachable->getOrder('ORDTSTDOESNOTEXIST'));
		$this->assertSame('network', $unreachable->errorCode);
		$this->assertSame(-1, $unreachable->checkCredentials());
		$this->assertStringNotContainsString(self::FAKE_TOKEN, $unreachable->error);
	}

	/**
	 * The official address refuses nothing by itself, but local addresses stay forbidden for it:
	 * only an overridden base URL may point to this machine.
	 *
	 * @return void
	 */
	public function testDefaultBaseUrl(): void
	{
		mpagoSetConfInMemory('MPAGO_API_BASE_URL', '');
		$client = new MpagoClient(self::FAKE_TOKEN);
		$reflection = new ReflectionProperty(MpagoClient::class, 'baseUrl');
		$reflection->setAccessible(true);
		$this->assertSame('https://api.mercadopago.com', $reflection->getValue($client));
	}

	/**
	 * With the Dolibarr log at its most verbose level, the access token is not written to it.
	 *
	 * @return void
	 */
	public function testAccessTokenIsNotLogged(): void
	{
		/** @var Conf $conf */
		global $conf;

		$handler = new MpagoTestLogHandler();
		$savedhandlers = $conf->loghandlers;
		$savedmodules = $conf->modules;
		$savedlevel = getDolGlobalString('SYSLOG_LEVEL');
		$conf->loghandlers = array('mpagotest' => $handler);
		$conf->modules['syslog'] = 'syslog';
		mpagoSetConfInMemory('SYSLOG_LEVEL', (string) LOG_DEBUG);
		try {
			dol_syslog('MpagoClientTest marker');
			$client = new MpagoClient();
			$order = $client->createOrder($this->body('1-i902-1'), '1-i902-1-1');
			$this->assertNotNull($order);
			$this->assertNotNull($client->getOrder(mpagoString($order['id'])));
			$this->assertNull($client->getOrder('ORDTSTDOESNOTEXIST'));
			$this->assertSame((string) LOG_DEBUG, getDolGlobalString('SYSLOG_LEVEL'));
		} finally {
			$conf->loghandlers = $savedhandlers;
			$conf->modules = $savedmodules;
			mpagoSetConfInMemory('SYSLOG_LEVEL', $savedlevel);
		}

		$this->assertContains('MpagoClientTest marker', $handler->messages, 'The test handler did not receive the log');
		foreach ($handler->messages as $message) {
			$this->assertStringNotContainsString(self::FAKE_TOKEN, $message);
			$this->assertStringNotContainsString('Bearer', $message);
		}
	}
}
