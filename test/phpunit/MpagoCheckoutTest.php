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
 * \file    test/phpunit/MpagoCheckoutTest.php
 * \ingroup mpago
 * \brief   Tests of the start of a payment and of the hooks of the payment page.
 */

require_once __DIR__.'/MpagoTestCase.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';


/**
 * Class MpagoCheckoutTest
 */
class MpagoCheckoutTest extends MpagoTestCase
{
	/**
	 * Last request received by the fake API.
	 *
	 * @return	array<string,mixed>
	 */
	private function lastRequest()
	{
		$requests = self::fakeRequests();
		$this->assertNotEmpty($requests);

		return $requests[count($requests) - 1];
	}

	/**
	 * Number of orders created at the fake API.
	 *
	 * @return	int
	 */
	private function countOrdersCreated()
	{
		$count = 0;
		foreach (self::fakeRequests() as $request) {
			if ($request['method'] === 'POST' && $request['path'] === '/v1/orders') {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Starting a payment creates the row and the order at Mercado Pago with the documented body.
	 *
	 * @return void
	 */
	public function testStartCreatesOrder(): void
	{
		/**
		 * @var DoliDB $db
		 * @var Societe $mysoc
		 */
		global $db, $mysoc;

		mpagoSetConfInMemory('MPAGO_MAX_INSTALLMENTS', '6');
		mpagoSetConfInMemory('MPAGO_STATEMENT_DESCRIPTOR', 'TALLER');
		mpagoSetConfInMemory('MPAGO_EXPIRATION_HOURS', '48');

		$invoice = $this->createInvoice($this->createThirdparty('buyer.name@example.com'), 1234.5);
		$checkout = new MpagoCheckout($db);
		$url = $checkout->start('invoice', (string) $invoice->ref);

		$row = $checkout->row;
		$this->assertInstanceOf(MpagoOrder::class, $row);
		$this->assertSame($row->checkout_url, $url);
		$this->assertStringStartsWith(self::fakeUrl().'/checkout/v1/redirect?order_id=ORDTST', $url);
		$this->assertSame('1-i'.$invoice->id.'-1', $row->external_reference);
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,64}$/', $row->external_reference);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $row->return_token);
		$this->assertSame('invoice', $row->source);
		$this->assertSame((int) $invoice->id, $row->fk_source);
		$this->assertSame($invoice->ref, $row->ref_source);
		$this->assertEqualsWithDelta(1234.5, $row->amount, 0.001);
		$this->assertSame('ARS', $row->currency);
		$this->assertSame('created', $row->status);
		$this->assertSame(0, $row->live_mode);
		$this->assertSame(1, $row->attempt);
		$this->assertEqualsWithDelta(48 * 3600, $row->date_expiration - $row->date_creation, 1);

		$stored = new MpagoOrder($db);
		$this->assertSame(1, $stored->fetchByExternalReference($row->external_reference));
		$this->assertSame($row->mp_order_id, $stored->mp_order_id);
		$this->assertSame(1, $stored->fetchByMpOrderId($row->mp_order_id));
		$this->assertSame($row->id, $stored->id);

		$request = $this->lastRequest();
		$this->assertSame('Bearer '.self::FAKE_TOKEN, $request['authorization']);
		$this->assertSame($row->external_reference.'-1', $request['idempotency_key']);

		$returnurl = dol_buildpath('/mpago/public/return.php', 3).'?t='.$row->return_token.'&r=';
		$this->assertSame(array(
			'type' => 'online',
			'processing_mode' => 'manual',
			'total_amount' => '1234.50',
			'external_reference' => $row->external_reference,
			'payer' => array('email' => 'buyer.name@example.com'),
			'items' => array(array('title' => $mysoc->name.' '.$invoice->ref, 'quantity' => 1, 'unit_price' => '1234.50')),
			'expiration_time' => 'PT48H',
			'config' => array(
				'online' => array(
					'auto_return' => 'all',
					'success_url' => $returnurl.'success&entity=1',
					'failure_url' => $returnurl.'failure&entity=1',
					'pending_url' => $returnurl.'pending&entity=1',
				),
				'payment_method' => array('max_installments' => 6),
				'statement_descriptor' => 'TALLER',
			),
		), $request['body']);
	}

	/**
	 * A second click reuses the open checkout; a new order is only created once the first cannot be used any more.
	 *
	 * @return void
	 */
	public function testOpenCheckoutIsReused(): void
	{
		/** @var DoliDB $db */
		global $db;

		$invoice = $this->createInvoice($this->createThirdparty(), 500);
		$checkout = new MpagoCheckout($db);

		$first = $checkout->start('invoice', (string) $invoice->ref);
		$this->assertNotSame('', $first, $checkout->error);
		$created = $this->countOrdersCreated();

		$this->assertSame($first, $checkout->start('invoice', (string) $invoice->ref));
		$this->assertSame($created, $this->countOrdersCreated());
		$this->assertSame(1, $this->countRows("mpago_order WHERE source = 'invoice' AND fk_source = ".((int) $invoice->id)));

		// Expired on our side: a new order, with the next reference
		$row = $checkout->row;
		$this->assertInstanceOf(MpagoOrder::class, $row);
		$row->date_expiration = dol_now() - 10;
		$row->update();
		$second = $checkout->start('invoice', (string) $invoice->ref);
		$this->assertNotSame('', $second, $checkout->error);
		$this->assertNotSame($first, $second);
		$this->assertInstanceOf(MpagoOrder::class, $checkout->row);
		$this->assertSame('1-i'.$invoice->id.'-2', $checkout->row->external_reference);
		$this->assertSame($created + 1, $this->countOrdersCreated());

		// Failed at Mercado Pago: a new order again
		self::fake('POST', '/__test/orders/'.$checkout->row->mp_order_id, array('status' => 'failed', 'status_detail' => 'rejected_by_issuer'));
		$third = $checkout->start('invoice', (string) $invoice->ref);
		$this->assertNotSame('', $third, $checkout->error);
		$this->assertInstanceOf(MpagoOrder::class, $checkout->row);
		$this->assertSame('1-i'.$invoice->id.'-3', $checkout->row->external_reference);
	}

	/**
	 * A buyer who already paid and clicks again is not sent to pay a second time.
	 *
	 * @return void
	 */
	public function testAlreadyPaidIsNotChargedAgain(): void
	{
		/** @var DoliDB $db */
		global $db;

		$invoice = $this->createInvoice($this->createThirdparty(), 210);
		$row = $this->startPayment('invoice', (string) $invoice->ref);

		// Payment in review at Mercado Pago: sent to our return page, no new order
		self::fake('POST', '/__test/orders/'.$row->mp_order_id, array('status' => 'processing', 'status_detail' => 'in_process'));
		$created = $this->countOrdersCreated();
		$checkout = new MpagoCheckout($db);
		$url = $checkout->start('invoice', (string) $invoice->ref);
		$this->assertStringContainsString('/mpago/public/return.php?t='.$row->return_token, $url);
		$this->assertSame($created, $this->countOrdersCreated());

		// Paid but not known to Dolibarr yet: the click records it
		self::payAtFake($row->mp_order_id);
		$url = $checkout->start('invoice', (string) $invoice->ref);
		$this->assertStringContainsString('/mpago/public/return.php?t='.$row->return_token, $url);
		$this->assertSame(1, $this->countPayments((int) $invoice->id));
		$this->assertSame($created, $this->countOrdersCreated());

		// Now the invoice is paid: nothing left to start
		$this->assertSame('', $checkout->start('invoice', (string) $invoice->ref));
		$this->assertSame('MpagoErrorNothingToPay', $checkout->error);
	}

	/**
	 * A failed creation keeps the row and is retried with a new idempotency key.
	 *
	 * @return void
	 */
	public function testFailedCreationIsRetriedWithNewKey(): void
	{
		/** @var DoliDB $db */
		global $db;

		$invoice = $this->createInvoice($this->createThirdparty(), 77);
		$checkout = new MpagoCheckout($db);

		self::fake('POST', '/__test/config', array('fail_next' => 500));
		$this->assertSame('', $checkout->start('invoice', (string) $invoice->ref));
		$this->assertSame('MpagoErrorCannotStartPayment', $checkout->error);
		$row = $checkout->row;
		$this->assertInstanceOf(MpagoOrder::class, $row);
		$this->assertSame('', $row->mp_order_id);
		$this->assertSame(1, $row->attempt);
		$this->assertStringContainsString('HTTP 500', $row->last_error);
		$this->assertSame($row->external_reference.'-1', $this->lastRequest()['idempotency_key']);

		$url = $checkout->start('invoice', (string) $invoice->ref);
		$this->assertNotSame('', $url, $checkout->error);
		$this->assertInstanceOf(MpagoOrder::class, $checkout->row);
		$this->assertSame($row->id, $checkout->row->id);
		$this->assertSame(2, $checkout->row->attempt);
		$this->assertSame('', $checkout->row->last_error);
		$this->assertSame($row->external_reference.'-2', $this->lastRequest()['idempotency_key']);
		$this->assertSame(1, $this->countRows("mpago_order WHERE source = 'invoice' AND fk_source = ".((int) $invoice->id)));
	}

	/**
	 * Without an e-mail on the third party the buyer must give one.
	 *
	 * @return void
	 */
	public function testEmailIsRequired(): void
	{
		/** @var DoliDB $db */
		global $db;

		$invoice = $this->createInvoice($this->createThirdparty(''), 20);
		$checkout = new MpagoCheckout($db);

		$this->assertSame('', $checkout->start('invoice', (string) $invoice->ref));
		$this->assertSame('MpagoErrorEmailRequired', $checkout->error);
		$this->assertSame('', $checkout->start('invoice', (string) $invoice->ref, 'not an e-mail'));
		$this->assertSame('MpagoErrorEmailRequired', $checkout->error);
		$this->assertSame(0, $this->countRows("mpago_order WHERE source = 'invoice' AND fk_source = ".((int) $invoice->id)));

		$this->assertNotSame('', $checkout->start('invoice', (string) $invoice->ref, ' typed@example.com '), $checkout->error);
		$body = $this->lastRequest()['body'];
		$this->assertIsArray($body);
		$this->assertSame(array('email' => 'typed@example.com'), $body['payer']);
	}

	/**
	 * Objects that cannot be paid, unknown refs and incomplete setup start nothing.
	 *
	 * @return void
	 */
	public function testNothingToStart(): void
	{
		/**
		 * @var DoliDB $db
		 * @var User $user
		 */
		global $db, $user;

		$thirdparty = $this->createThirdparty();
		$checkout = new MpagoCheckout($db);
		$created = $this->countOrdersCreated();

		$this->assertSame('', $checkout->start('invoice', 'NO-SUCH-REF'));
		$this->assertSame('MpagoErrorObjectNotFound', $checkout->error);
		$this->assertSame('', $checkout->start('member', 'whatever'));
		$this->assertSame('MpagoErrorObjectNotFound', $checkout->error);

		$order = $this->createOrder($thirdparty, 90);
		$order->classifyBilled($user);
		$this->assertSame('', $checkout->start('order', (string) $order->ref));
		$this->assertSame('MpagoErrorNothingToPay', $checkout->error);

		$invoice = $this->createInvoice($thirdparty, 90);
		mpagoSetConfInMemory('MPAGO_ACCESS_TOKEN', '');
		$this->assertSame('', $checkout->start('invoice', (string) $invoice->ref));
		$this->assertSame('MpagoErrorNotConfigured', $checkout->error);

		$this->assertSame($created, $this->countOrdersCreated());
		$this->assertSame(0, $this->countRows("mpago_order WHERE (source = 'invoice' AND fk_source = ".((int) $invoice->id).") OR (source = 'order' AND fk_source = ".((int) $order->id).")"));
	}

	/**
	 * The amount requested is what remains to pay on the invoice.
	 *
	 * @return void
	 */
	public function testAmountIsWhatRemainsToPay(): void
	{
		/**
		 * @var DoliDB $db
		 * @var User $user
		 */
		global $db, $user;

		$invoice = $this->createInvoice($this->createThirdparty(), 100);
		$payment = new Paiement($db);
		$payment->datepaye = dol_now();
		$payment->amounts = array((int) $invoice->id => 30.0);
		$payment->paiementid = mpagoGetPaymentModeId($db);
		$this->assertGreaterThan(0, $payment->create($user, 1));

		$row = $this->startPayment('invoice', (string) $invoice->ref);
		$this->assertEqualsWithDelta(70.0, $row->amount, 0.001);
		$body = $this->lastRequest()['body'];
		$this->assertIsArray($body);
		$this->assertSame('70.00', $body['total_amount']);
	}

	/**
	 * Hooks of the payment page: the method is offered only when the setup is complete and for invoices and orders,
	 * and the button is printed for an object that can be paid.
	 *
	 * @return void
	 */
	public function testHooks(): void
	{
		/** @var DoliDB $db */
		global $db;

		$hookmanager = new HookManager($db);
		$actions = new ActionsMpago($db);
		$action = '';
		$object = null;

		$_GET['source'] = 'invoice';
		try {
			$this->assertSame(0, $actions->getValidPayment(array('paymentmethod' => '', 'mode' => 0), $object, $action, $hookmanager));
			$this->assertSame(array('validpaymentmethod' => array('mpago' => 'valid')), $actions->results);
			$this->assertSame(0, $actions->getValidPayment(array('paymentmethod' => 'mpago', 'mode' => 1), $object, $action, $hookmanager));
			$this->assertSame(array('validpaymentmethod' => array('mpago' => array('label' => 'Mercado Pago', 'status' => 'valid'))), $actions->results);

			$actions->getValidPayment(array('paymentmethod' => 'stripe', 'mode' => 0), $object, $action, $hookmanager);
			$this->assertSame(array(), $actions->results);

			mpagoSetConfInMemory('MPAGO_BANK_ACCOUNT', '');
			$actions->getValidPayment(array('paymentmethod' => '', 'mode' => 0), $object, $action, $hookmanager);
			$this->assertSame(array(), $actions->results, 'Not offered without a bank account');
			mpagoSetConfInMemory('MPAGO_BANK_ACCOUNT', (string) self::$bankAccountId);

			$_GET['source'] = 'member';
			$actions->getValidPayment(array('paymentmethod' => '', 'mode' => 0), $object, $action, $hookmanager);
			$this->assertSame(array(), $actions->results, 'Not offered for other sources');
		} finally {
			unset($_GET['source']);
		}

		$invoice = $this->createInvoice($this->createThirdparty(''), 15);
		$invoice->fetch_thirdparty();
		$this->assertSame(0, $actions->doAddButton(array('paymentmethod' => ''), $invoice, $action, $hookmanager));
		$this->assertStringContainsString('name="dopayment_mpago"', (string) $actions->resprints);
		$this->assertStringContainsString('name="mpago_email"', (string) $actions->resprints, 'The third party has no e-mail: the buyer is asked for one');

		$actions->doAddButton(array('paymentmethod' => 'stripe'), $invoice, $action, $hookmanager);
		$this->assertSame('', $actions->resprints);
		mpagoSetConfInMemory('MPAGO_BANK_ACCOUNT', '');
		$actions->doAddButton(array('paymentmethod' => ''), $invoice, $action, $hookmanager);
		$this->assertSame('', $actions->resprints, 'No button without a bank account');
		mpagoSetConfInMemory('MPAGO_BANK_ACCOUNT', (string) self::$bankAccountId);

		// The call of doPayment made after the page was printed never redirects
		$action = 'dopayment';
		$this->assertSame(0, $actions->doPayment(array('paymentmethod' => 'mpago', 'amount' => '15', 'currency' => 'ARS', 'tag' => '', 'dopayment' => ''), $invoice, $action, $hookmanager));
		$action = '';

		// Nothing to say about an object without payment, a message once it was paid through Mercado Pago
		$paid = $this->createInvoice($this->createThirdparty(), 16);
		// The hooks take the object by reference: give them their own variable
		$hookobject = $paid;
		$this->assertSame(0, $actions->doCheckStatus(array('source' => 'invoice', 'object' => $paid), $hookobject, $action, $hookmanager));
		$this->assertSame('', $actions->resprints);
		$row = $this->startPayment('invoice', (string) $paid->ref);
		self::payAtFake($row->mp_order_id);
		$recorder = new MpagoRecorder($db);
		$this->assertSame(MpagoRecorder::RESULT_RECORDED, $recorder->sync($row), $recorder->error);
		$this->assertSame(1, $actions->doCheckStatus(array('source' => 'invoice', 'object' => $paid), $hookobject, $action, $hookmanager));
		$this->assertStringContainsString('id="mpago_status"', (string) $actions->resprints);

		$paid->fetch((int) $paid->id);
		$paid->fetch_thirdparty();
		$hookobject = $paid;
		$actions->doAddButton(array('paymentmethod' => ''), $hookobject, $action, $hookmanager);
		$this->assertSame('', $actions->resprints, 'No button on a paid invoice');
	}
}
