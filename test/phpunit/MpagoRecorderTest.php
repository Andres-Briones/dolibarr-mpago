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
 * \file    test/phpunit/MpagoRecorderTest.php
 * \ingroup mpago
 * \brief   Tests of the recording of payments, with real Dolibarr objects.
 */

require_once __DIR__.'/MpagoTestCase.php';


/**
 * Class MpagoRecorderTest
 */
class MpagoRecorderTest extends MpagoTestCase
{
	/**
	 * An invoice paid at Mercado Pago gets one payment and one bank line, and is closed.
	 *
	 * @return void
	 */
	public function testInvoicePaidOnce(): void
	{
		/** @var DoliDB $db */
		global $db;

		$invoice = $this->createInvoice($this->createThirdparty(), 250.5);
		$row = $this->startPayment('invoice', (string) $invoice->ref);
		$banklines = $this->countBankLines();

		$recorder = new MpagoRecorder($db);
		$this->assertSame(MpagoRecorder::RESULT_NOT_PAID, $recorder->sync($row));
		$this->assertSame('created', $row->status);
		$this->assertSame(0, $this->countPayments((int) $invoice->id));

		self::payAtFake($row->mp_order_id);
		$this->assertSame(MpagoRecorder::RESULT_RECORDED, $recorder->sync($row), $recorder->error);

		$this->assertTrue($row->isRecorded());
		$this->assertSame((int) $invoice->id, $row->fk_facture);
		$this->assertSame('processed', $row->status);
		$this->assertSame('accredited', $row->status_detail);
		$this->assertSame('', $row->last_error);
		$this->assertSame(1, $this->countPayments((int) $invoice->id));
		$this->assertSame($banklines + 1, $this->countBankLines());

		$invoice->fetch((int) $invoice->id);
		$this->assertEquals(1, $invoice->paye);
		$this->assertEquals(Facture::STATUS_CLOSED, $invoice->status);

		$payment = new Paiement($db);
		$this->assertGreaterThan(0, $payment->fetch($row->fk_paiement));
		$this->assertEqualsWithDelta(250.5, (float) $payment->amount, 0.001);
		$this->assertSame($row->mp_order_id, $payment->ext_payment_id);
		$this->assertSame('mercadopago', $payment->ext_payment_site);
		$this->assertStringStartsWith('PAY01', (string) $payment->num_payment);
		$this->assertSame(1, $this->countRows("paiement WHERE rowid = ".((int) $row->fk_paiement)." AND fk_paiement = ".mpagoGetPaymentModeId($db)));
		$this->assertSame('CB', $payment->type_code);
		$this->assertGreaterThan(0, (int) $payment->bank_line);
		$this->assertSame(1, $this->countRows("bank WHERE rowid = ".((int) $payment->bank_line)." AND fk_account = ".((int) self::$bankAccountId)." AND amount = 250.5"));
		$this->assertSame(1, $this->countRows("paiement WHERE rowid = ".((int) $row->fk_paiement)." AND note LIKE '%visa credit_card%3x%'"));
	}

	/**
	 * sync() called five times records one payment and one bank line.
	 *
	 * @return void
	 */
	public function testSyncFiveTimesRecordsOnce(): void
	{
		/** @var DoliDB $db */
		global $db;

		$invoice = $this->createInvoice($this->createThirdparty(), 80);
		$row = $this->startPayment('invoice', (string) $invoice->ref);
		self::payAtFake($row->mp_order_id);
		$banklines = $this->countBankLines();

		$results = array();
		for ($i = 0; $i < 5; $i++) {
			// A new object each time, as separate requests would have
			$fresh = new MpagoOrder($db);
			$this->assertSame(1, $fresh->fetch($row->id));
			$recorder = new MpagoRecorder($db);
			$results[] = $recorder->sync($fresh);
		}

		$this->assertSame(array(MpagoRecorder::RESULT_RECORDED, MpagoRecorder::RESULT_ALREADY_RECORDED, MpagoRecorder::RESULT_ALREADY_RECORDED, MpagoRecorder::RESULT_ALREADY_RECORDED, MpagoRecorder::RESULT_ALREADY_RECORDED), $results);
		$this->assertSame(1, $this->countPayments((int) $invoice->id));
		$this->assertSame($banklines + 1, $this->countBankLines());
		$this->assertSame(1, $this->countRows("paiement WHERE ext_payment_id = '".$db->escape($row->mp_order_id)."'"));

		$invoice->fetch((int) $invoice->id);
		$this->assertEquals(1, $invoice->paye);
	}

	/**
	 * Second defence: a payment that exists for the order is found even when the row does not point to it.
	 *
	 * @return void
	 */
	public function testExistingPaymentIsNotDuplicated(): void
	{
		/** @var DoliDB $db */
		global $db;

		$invoice = $this->createInvoice($this->createThirdparty(), 60);
		$row = $this->startPayment('invoice', (string) $invoice->ref);
		self::payAtFake($row->mp_order_id);

		$recorder = new MpagoRecorder($db);
		$this->assertSame(MpagoRecorder::RESULT_RECORDED, $recorder->sync($row), $recorder->error);
		$paymentid = $row->fk_paiement;

		$row->fk_paiement = 0;
		$row->fk_facture = 0;
		$this->assertSame(1, $row->update());

		$this->assertSame(MpagoRecorder::RESULT_ALREADY_RECORDED, $recorder->sync($row));
		$this->assertSame($paymentid, $row->fk_paiement);
		$this->assertSame((int) $invoice->id, $row->fk_facture);
		$this->assertSame(1, $this->countPayments((int) $invoice->id));
	}

	/**
	 * A draft invoice is validated before being paid.
	 *
	 * @return void
	 */
	public function testDraftInvoiceIsValidated(): void
	{
		/** @var DoliDB $db */
		global $db;

		$invoice = $this->createInvoice($this->createThirdparty(), 40, false);
		$this->assertEquals(Facture::STATUS_DRAFT, $invoice->status);
		$row = $this->startPayment('invoice', (string) $invoice->ref);
		self::payAtFake($row->mp_order_id);

		$recorder = new MpagoRecorder($db);
		$this->assertSame(MpagoRecorder::RESULT_RECORDED, $recorder->sync($row), $recorder->error);

		$invoice->fetch((int) $invoice->id);
		$this->assertEquals(1, $invoice->paye);
		$this->assertStringNotContainsString('PROV', (string) $invoice->ref);
	}

	/**
	 * Paying an order creates its invoice, validates it, pays it and classifies the order as billed.
	 *
	 * @return void
	 */
	public function testOrderSource(): void
	{
		/** @var DoliDB $db */
		global $db;

		$order = $this->createOrder($this->createThirdparty(), 300);
		$row = $this->startPayment('order', (string) $order->ref);
		$this->assertSame('1-o'.$order->id.'-1', $row->external_reference);
		self::payAtFake($row->mp_order_id);
		$banklines = $this->countBankLines();

		$recorder = new MpagoRecorder($db);
		$this->assertSame(MpagoRecorder::RESULT_RECORDED, $recorder->sync($row), $recorder->error);
		$this->assertSame(MpagoRecorder::RESULT_ALREADY_RECORDED, $recorder->sync($row));

		$this->assertGreaterThan(0, $row->fk_facture);
		$invoice = new Facture($db);
		$this->assertGreaterThan(0, $invoice->fetch($row->fk_facture));
		$this->assertEquals($order->socid, $invoice->socid);
		$this->assertEqualsWithDelta(300.0, (float) $invoice->total_ttc, 0.001);
		$this->assertEquals(1, $invoice->paye);
		$this->assertEquals(Facture::STATUS_CLOSED, $invoice->status);
		$this->assertStringNotContainsString('PROV', (string) $invoice->ref);
		$this->assertSame(1, $this->countPayments((int) $invoice->id));
		$this->assertSame($banklines + 1, $this->countBankLines());
		$this->assertSame(1, $this->countRows("element_element WHERE sourcetype = 'commande' AND fk_source = ".((int) $order->id)." AND targettype = 'facture'"));

		$order->fetch((int) $order->id);
		$this->assertEquals(1, $order->billed);
	}

	/**
	 * An order invoiced by hand while the buyer was paying: the payment goes to that invoice, no second invoice.
	 *
	 * @return void
	 */
	public function testOrderAlreadyInvoiced(): void
	{
		/**
		 * @var DoliDB $db
		 * @var User $user
		 */
		global $db, $user;

		$order = $this->createOrder($this->createThirdparty(), 120);
		$row = $this->startPayment('order', (string) $order->ref);
		self::payAtFake($row->mp_order_id);

		$manual = new Facture($db);
		$this->assertGreaterThan(0, $manual->createFromOrder($order, $user));
		$order->classifyBilled($user);

		$recorder = new MpagoRecorder($db);
		$this->assertSame(MpagoRecorder::RESULT_RECORDED, $recorder->sync($row), $recorder->error);
		$this->assertSame((int) $manual->id, $row->fk_facture);
		$this->assertSame(1, $this->countRows("element_element WHERE sourcetype = 'commande' AND fk_source = ".((int) $order->id)." AND targettype = 'facture'"));
		$manual->fetch((int) $manual->id);
		$this->assertEquals(1, $manual->paye);
	}

	/**
	 * Statuses that do not mean "money received" record nothing.
	 *
	 * @return void
	 */
	public function testNotPaidStatusesRecordNothing(): void
	{
		/** @var DoliDB $db */
		global $db;

		$invoice = $this->createInvoice($this->createThirdparty(), 70);
		$row = $this->startPayment('invoice', (string) $invoice->ref);
		$banklines = $this->countBankLines();
		$recorder = new MpagoRecorder($db);

		$statuses = array(
			array('created', 'created'),
			array('action_required', 'waiting_payment'),
			array('processing', 'in_process'),
			array('processed', 'partially_refunded'),
			array('failed', 'rejected_by_issuer'),
			array('refunded', 'refunded'),
			array('canceled', 'canceled'),
			array('expired', 'expired'),
		);
		foreach ($statuses as $status) {
			self::fake('POST', '/__test/orders/'.$row->mp_order_id, array('status' => $status[0], 'status_detail' => $status[1], 'total_paid_amount' => '70.00'));
			$this->assertSame(MpagoRecorder::RESULT_NOT_PAID, $recorder->sync($row), implode('/', $status));
			$this->assertSame($status[0], $row->status);
			$this->assertSame($status[1], $row->status_detail);
			$this->assertFalse($row->isRecorded());
			$this->assertGreaterThan(0, $row->date_last_check);
		}

		$this->assertSame(0, $this->countPayments((int) $invoice->id));
		$this->assertSame($banklines, $this->countBankLines());
		$invoice->fetch((int) $invoice->id);
		$this->assertEquals(0, $invoice->paye);
	}

	/**
	 * A paid amount that differs from the requested one is recorded as paid, with a warning on the row.
	 *
	 * @return void
	 */
	public function testAmountMismatchIsRecordedWithWarning(): void
	{
		/** @var DoliDB $db */
		global $db;

		$invoice = $this->createInvoice($this->createThirdparty(), 100);
		$row = $this->startPayment('invoice', (string) $invoice->ref);
		self::payAtFake($row->mp_order_id, '90.00');

		$recorder = new MpagoRecorder($db);
		$this->assertSame(MpagoRecorder::RESULT_RECORDED, $recorder->sync($row), $recorder->error);

		$this->assertTrue($row->isRecorded());
		$this->assertStringContainsString('90.00', $row->last_error);
		$this->assertStringContainsString('100.00', $row->last_error);
		$this->assertSame(1, $this->countRows("paiement_facture WHERE fk_facture = ".((int) $invoice->id)." AND amount = 90"));

		$invoice->fetch((int) $invoice->id);
		$this->assertEquals(0, $invoice->paye, 'A partial payment must not close the invoice');
		$this->assertEqualsWithDelta(10.0, (float) $invoice->getRemainToPay(), 0.001);
	}

	/**
	 * Without a bank account nothing is written, the error stays on the row, and the next try works.
	 *
	 * @return void
	 */
	public function testMissingBankAccountLeavesNothingHalfWritten(): void
	{
		/** @var DoliDB $db */
		global $db;

		$invoice = $this->createInvoice($this->createThirdparty(), 55, false);
		$row = $this->startPayment('invoice', (string) $invoice->ref);
		self::payAtFake($row->mp_order_id);
		$payments = $this->countRows("paiement");
		$banklines = $this->countRows("bank");

		$recorder = new MpagoRecorder($db);
		foreach (array('', '999999999') as $account) {
			mpagoSetConfInMemory('MPAGO_BANK_ACCOUNT', $account);
			$this->assertSame(MpagoRecorder::RESULT_ERROR, $recorder->sync($row), 'account "'.$account.'"');
			$this->assertNotSame('', $recorder->error);

			$this->assertFalse($row->isRecorded());
			$this->assertSame('processed', $row->status);
			$this->assertStringContainsString('not recorded', $row->last_error);
			$this->assertSame($payments, $this->countRows("paiement"));
			$this->assertSame($banklines, $this->countRows("bank"));
			$invoice->fetch((int) $invoice->id);
			$this->assertEquals(Facture::STATUS_DRAFT, $invoice->status, 'The invoice must not be validated when the payment cannot be recorded');
		}

		mpagoSetConfInMemory('MPAGO_BANK_ACCOUNT', (string) self::$bankAccountId);
		$this->assertSame(MpagoRecorder::RESULT_RECORDED, $recorder->sync($row), $recorder->error);
		$this->assertSame('', $row->last_error);
		$this->assertSame(1, $this->countPayments((int) $invoice->id));
	}

	/**
	 * An order whose external reference or currency does not match is never recorded.
	 *
	 * @return void
	 */
	public function testMismatchingOrderIsRefused(): void
	{
		/** @var DoliDB $db */
		global $db;

		$invoice = $this->createInvoice($this->createThirdparty(), 30);
		$row = $this->startPayment('invoice', (string) $invoice->ref);
		self::payAtFake($row->mp_order_id);
		$recorder = new MpagoRecorder($db);

		self::fake('POST', '/__test/orders/'.$row->mp_order_id, array('external_reference' => 'someone-else'));
		$this->assertSame(MpagoRecorder::RESULT_ERROR, $recorder->sync($row));
		$this->assertStringContainsString('external reference', $row->last_error);

		self::fake('POST', '/__test/orders/'.$row->mp_order_id, array('external_reference' => $row->external_reference, 'currency' => 'USD'));
		$this->assertSame(MpagoRecorder::RESULT_ERROR, $recorder->sync($row));
		$this->assertStringContainsString('USD', $row->last_error);

		self::fake('POST', '/__test/config', array('fail_next' => 500));
		$this->assertSame(MpagoRecorder::RESULT_ERROR, $recorder->sync($row));
		$this->assertStringContainsString('HTTP 500', $row->last_error);

		$this->assertSame(0, $this->countPayments((int) $invoice->id));
	}
}
