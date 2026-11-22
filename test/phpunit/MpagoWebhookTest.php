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
 * \file    test/phpunit/MpagoWebhookTest.php
 * \ingroup mpago
 * \brief   Tests of the reception of notifications and of the scheduled jobs.
 */

require_once __DIR__.'/MpagoTestCase.php';


/**
 * Class MpagoWebhookTest
 */
class MpagoWebhookTest extends MpagoTestCase
{
	/**
	 * The jobs process every pending row: start each test without the rows of the previous ones.
	 * This happens inside the transaction of the test class.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		/** @var DoliDB $db */
		global $db;

		parent::setUp();

		$db->query("DELETE FROM ".MAIN_DB_PREFIX."mpago_event");
		$db->query("DELETE FROM ".MAIN_DB_PREFIX."mpago_order");
	}

	/**
	 * Deliver a notification the way Mercado Pago does.
	 *
	 * @param	string	$orderId	Order id, in the query string and in the body
	 * @param	string	$requestId	Value of x-request-id
	 * @param	string	$secret		Secret used to sign
	 * @param	string	$type		Topic
	 * @return	int					HTTP status answered
	 */
	private function deliver($orderId, $requestId, $secret = self::WEBHOOK_SECRET, $type = 'order')
	{
		/** @var DoliDB $db */
		global $db;

		$ts = (string) (time() * 1000);
		$signature = 'ts='.$ts.',v1='.hash_hmac('sha256', MpagoSignature::buildManifest(strtolower($orderId), $requestId, $ts), $secret);
		$body = (string) json_encode(array('action' => 'order.processed', 'api_version' => 'v1', 'live_mode' => false, 'type' => $type, 'data' => array('id' => $orderId)));

		$webhook = new MpagoWebhook($db);

		return $webhook->handle($orderId, $type, $signature, $requestId, $body);
	}

	/**
	 * A signed notification is stored, pending, without touching the order.
	 *
	 * @return void
	 */
	public function testSignedNotificationIsStored(): void
	{
		$this->assertSame(200, $this->deliver('ORDTSTWEBHOOK0000000000000000001', 'req-stored-1'));

		$where = "mpago_event WHERE request_id = 'req-stored-1'";
		$this->assertSame(1, $this->countRows($where));
		$this->assertSame(1, $this->countRows($where." AND mp_order_id = 'ORDTSTWEBHOOK0000000000000000001' AND action = 'order.processed' AND live_mode = 0 AND status = 0 AND attempts = 0 AND payload LIKE '%order.processed%'"));
	}

	/**
	 * The same notification delivered twice is stored once and acknowledged both times.
	 *
	 * @return void
	 */
	public function testDuplicateDeliveryIsStoredOnce(): void
	{
		$codes = array();
		for ($i = 0; $i < 3; $i++) {
			$codes[] = $this->deliver('ORDTSTWEBHOOK0000000000000000002', 'req-duplicate');
		}
		$this->assertSame(array(200, 200, 200), $codes);

		$this->assertSame(1, $this->countRows("mpago_event WHERE mp_order_id = 'ORDTSTWEBHOOK0000000000000000002'"));
	}

	/**
	 * A bad signature is answered 401 and nothing is stored; without a configured secret the answer is 503.
	 *
	 * @return void
	 */
	public function testBadSignatureIsRejected(): void
	{
		/** @var DoliDB $db */
		global $db;

		$this->assertSame(401, $this->deliver('ORDTSTWEBHOOK0000000000000000003', 'req-bad-1', 'not-the-secret'));

		$webhook = new MpagoWebhook($db);
		$body = '{"type":"order","data":{"id":"ORDTSTWEBHOOK0000000000000000003"}}';
		$this->assertSame(401, $webhook->handle('ORDTSTWEBHOOK0000000000000000003', 'order', '', 'req-bad-2', $body));
		$this->assertSame(401, $webhook->handle('ORDTSTWEBHOOK0000000000000000003', 'order', 'ts=1,v1=abc', 'req-bad-3', $body));

		// Valid signature, but too old
		$ts = (string) ((time() - 3600) * 1000);
		$stale = 'ts='.$ts.',v1='.hash_hmac('sha256', MpagoSignature::buildManifest('ordtstwebhook0000000000000000003', 'req-bad-4', $ts), self::WEBHOOK_SECRET);
		$this->assertSame(401, $webhook->handle('ORDTSTWEBHOOK0000000000000000003', 'order', $stale, 'req-bad-4', $body));
		mpagoSetConfInMemory('MPAGO_WEBHOOK_TOLERANCE', '7200');
		$this->assertSame(200, $webhook->handle('ORDTSTWEBHOOK0000000000000000003', 'order', $stale, 'req-bad-4', $body));
		$this->assertSame(1, $this->countRows("mpago_event WHERE mp_order_id = 'ORDTSTWEBHOOK0000000000000000003'"));

		mpagoSetConfInMemory('MPAGO_WEBHOOK_SECRET', '');
		$this->assertSame(503, $this->deliver('ORDTSTWEBHOOK0000000000000000003', 'req-bad-5'));
		$this->assertSame(503, $this->deliver('ORDTSTWEBHOOK0000000000000000003', 'req-bad-6', ''));
		$this->assertSame(1, $this->countRows("mpago_event WHERE mp_order_id = 'ORDTSTWEBHOOK0000000000000000003'"));
	}

	/**
	 * Other topics are acknowledged and not stored; a notification without request id is stored.
	 *
	 * @return void
	 */
	public function testOtherTopicsAndMissingRequestId(): void
	{
		$this->assertSame(200, $this->deliver('ORDTSTWEBHOOK0000000000000000004', 'req-topic', self::WEBHOOK_SECRET, 'payment'));
		$this->assertSame(0, $this->countRows("mpago_event WHERE mp_order_id = 'ORDTSTWEBHOOK0000000000000000004'"));

		$codes = array();
		for ($i = 0; $i < 2; $i++) {
			$codes[] = $this->deliver('ORDTSTWEBHOOK0000000000000000004', '');
		}
		$this->assertSame(array(200, 200), $codes);
		$this->assertSame(2, $this->countRows("mpago_event WHERE mp_order_id = 'ORDTSTWEBHOOK0000000000000000004' AND request_id IS NULL"));
	}

	/**
	 * The job records the payment announced by a notification, once, whatever the number of notifications.
	 *
	 * @return void
	 */
	public function testProcessEventsRecordsPayment(): void
	{
		/** @var DoliDB $db */
		global $db;

		$invoice = $this->createInvoice($this->createThirdparty(), 45);
		$row = $this->startPayment('invoice', (string) $invoice->ref);
		self::payAtFake($row->mp_order_id);

		$this->assertSame(200, $this->deliver($row->mp_order_id, 'req-process-1'));
		$this->assertSame(200, $this->deliver($row->mp_order_id, 'req-process-2'));
		$this->assertSame(200, $this->deliver('ORDTSTUNKNOWN00000000000000000001', 'req-process-3'));
		$this->assertSame(0, $this->countPayments((int) $invoice->id), 'The webhook itself must record nothing');

		$requestsbefore = count(self::fakeRequests());
		$cron = new MpagoCron($db);
		$this->assertSame(0, $cron->processEvents(), $cron->error);
		$this->assertStringContainsString('3 notification(s): 3 processed (1 for unknown orders), 0 failed', $cron->output);
		$this->assertSame($requestsbefore + 1, count(self::fakeRequests()), 'One read at Mercado Pago for the two notifications of the same order');

		$this->assertSame(1, $this->countPayments((int) $invoice->id));
		$this->assertSame(0, $this->countRows("mpago_event WHERE status = 0"));
		$this->assertSame(1, $this->countRows("mpago_event WHERE request_id = 'req-process-3' AND status = 1 AND last_error = 'Unknown order'"));

		$this->assertSame(0, $cron->processEvents());
		$this->assertSame(1, $this->countPayments((int) $invoice->id));
	}

	/**
	 * A notification that arrives after the return page recorded the payment does not record it again.
	 *
	 * @return void
	 */
	public function testLateWebhookAfterReturnPage(): void
	{
		/** @var DoliDB $db */
		global $db;

		$invoice = $this->createInvoice($this->createThirdparty(), 65);
		$row = $this->startPayment('invoice', (string) $invoice->ref);
		self::payAtFake($row->mp_order_id);
		$banklines = $this->countBankLines();

		// What public/return.php does when the buyer comes back
		$returned = new MpagoOrder($db);
		$this->assertSame(1, $returned->fetchByReturnToken($row->return_token));
		$recorder = new MpagoRecorder($db);
		$this->assertSame(MpagoRecorder::RESULT_RECORDED, $recorder->sync($returned), $recorder->error);

		$this->assertSame(200, $this->deliver($row->mp_order_id, 'req-late-1'));
		$cron = new MpagoCron($db);
		$this->assertSame(0, $cron->processEvents(), $cron->error);
		$this->assertSame(0, $cron->pollOpenOrders(), $cron->error);

		$this->assertSame(1, $this->countPayments((int) $invoice->id));
		$this->assertSame($banklines + 1, $this->countBankLines());
		$this->assertSame(1, $this->countRows("paiement WHERE ext_payment_id = '".$db->escape($row->mp_order_id)."'"));
		$this->assertSame(1, $this->countRows("mpago_event WHERE request_id = 'req-late-1' AND status = 1"));
	}

	/**
	 * A notification whose payment cannot be recorded stays pending and is retried.
	 *
	 * @return void
	 */
	public function testFailedEventIsRetried(): void
	{
		/** @var DoliDB $db */
		global $db;

		$invoice = $this->createInvoice($this->createThirdparty(), 35);
		$row = $this->startPayment('invoice', (string) $invoice->ref);
		self::payAtFake($row->mp_order_id);
		$this->assertSame(200, $this->deliver($row->mp_order_id, 'req-retry-1'));

		$cron = new MpagoCron($db);
		self::fake('POST', '/__test/config', array('fail_next' => 500));
		$this->assertSame(-1, $cron->processEvents());
		$this->assertSame(1, $this->countRows("mpago_event WHERE request_id = 'req-retry-1' AND status = 0 AND attempts = 1 AND last_error LIKE '%HTTP 500%'"));
		$this->assertSame(0, $this->countPayments((int) $invoice->id));

		$this->assertSame(0, $cron->processEvents(), $cron->error);
		$this->assertSame(1, $this->countRows("mpago_event WHERE request_id = 'req-retry-1' AND status = 1 AND attempts = 2 AND last_error IS NULL"));
		$this->assertSame(1, $this->countPayments((int) $invoice->id));
	}

	/**
	 * Without any notification, the polling job finds the payment; orders checked recently, expired or settled are left alone.
	 *
	 * @return void
	 */
	public function testPollOpenOrders(): void
	{
		/** @var DoliDB $db */
		global $db;

		$thirdparty = $this->createThirdparty();
		$paid = $this->createInvoice($thirdparty, 10);
		$recent = $this->createInvoice($thirdparty, 11);
		$expired = $this->createInvoice($thirdparty, 12);
		$failed = $this->createInvoice($thirdparty, 13);

		$rows = array();
		foreach (array('paid' => $paid, 'recent' => $recent, 'expired' => $expired, 'failed' => $failed) as $key => $invoice) {
			$rows[$key] = $this->startPayment('invoice', (string) $invoice->ref);
			self::payAtFake($rows[$key]->mp_order_id);
		}
		$now = dol_now();
		$rows['recent']->date_last_check = $now - 60;
		$rows['recent']->update();
		$rows['expired']->date_expiration = $now - 7200;
		$rows['expired']->update();
		$rows['failed']->status = 'failed';
		$rows['failed']->update();

		$cron = new MpagoCron($db);
		$this->assertSame(0, $cron->pollOpenOrders(), $cron->error);
		$this->assertStringContainsString('1 order(s) checked: 1 payment(s) recorded, 0 failed', $cron->output);

		$this->assertSame(1, $this->countPayments((int) $paid->id));
		$this->assertSame(0, $this->countPayments((int) $recent->id));
		$this->assertSame(0, $this->countPayments((int) $expired->id));
		$this->assertSame(0, $this->countPayments((int) $failed->id));

		// Ten minutes later the order checked recently is due; a payment received but not recorded is retried even after its expiry
		$rows['recent']->date_last_check = $now - 601;
		$rows['recent']->update();
		$rows['expired']->status = 'processed';
		$rows['expired']->update();
		$this->assertSame(0, $cron->pollOpenOrders(), $cron->error);
		$this->assertSame(1, $this->countPayments((int) $recent->id));
		$this->assertSame(1, $this->countPayments((int) $expired->id));
		$this->assertSame(1, $this->countPayments((int) $paid->id));
	}
}
