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
 * \file    class/mpagorecorder.class.php
 * \ingroup mpago
 * \brief   Records in Dolibarr the payment of a Mercado Pago order, exactly once.
 */

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once __DIR__.'/../lib/mpago.lib.php';
require_once __DIR__.'/mpagoclient.class.php';
require_once __DIR__.'/mpagoorder.class.php';

/**
 * Records in Dolibarr the payment of a Mercado Pago order, exactly once.
 * The return page, the webhook processing and the polling job all go through sync().
 */
class MpagoRecorder
{
	const RESULT_ERROR = -1;
	const RESULT_NOT_PAID = 0;
	const RESULT_RECORDED = 1;
	const RESULT_ALREADY_RECORDED = 2;

	const PAYMENT_SITE = 'mercadopago';

	/**
	 * @var DoliDB
	 */
	public $db;

	/**
	 * @var string Message of the last error (never contains a secret)
	 */
	public $error = '';

	/**
	 * @var MpagoClient
	 */
	private $client;

	/**
	 * Constructor
	 *
	 * @param	DoliDB				$db			Database handler
	 * @param	MpagoClient|null	$client		API client, null to build it from the setup
	 */
	public function __construct($db, $client = null)
	{
		$this->db = $db;
		$this->client = ($client === null ? new MpagoClient() : $client);
	}

	/**
	 * Read the order at Mercado Pago, update the row and record the payment if the money was received
	 * and it is not recorded yet.
	 *
	 * @param	MpagoOrder	$row	Row to synchronise; reloaded from the database before returning
	 * @return	int					One of the RESULT_* constants
	 */
	public function sync(MpagoOrder $row)
	{
		/**
		 * @var Conf $conf
		 * @var Translate $langs
		 */
		global $conf, $langs;

		$this->error = '';
		$now = dol_now();

		if ($row->id <= 0 || $row->mp_order_id === '') {
			$this->error = 'No Mercado Pago order to read';
			return self::RESULT_ERROR;
		}

		// The remote call is made before any transaction is opened.
		$mporder = $this->client->getOrder($row->mp_order_id);
		if ($mporder === null) {
			return $this->failRow($row, 'Mercado Pago: '.$this->client->error, $now);
		}
		if (mpagoString($mporder['external_reference'] ?? '') !== $row->external_reference) {
			return $this->failRow($row, 'The order read at Mercado Pago has another external reference', $now);
		}

		$row->status = mpagoString($mporder['status'] ?? '');
		$row->status_detail = mpagoString($mporder['status_detail'] ?? '');
		$row->date_last_check = $now;

		if (!$row->isPaidAtMercadoPago()) {
			if ($row->isRecorded()) {
				dol_syslog(__METHOD__.' order '.$row->mp_order_id.' was recorded as paid and is now '.$row->status.'/'.$row->status_detail.' at Mercado Pago: nothing is changed in Dolibarr', LOG_WARNING);
			} else {
				$row->last_error = '';
			}
			$row->update();
			return self::RESULT_NOT_PAID;
		}

		if ($row->isRecorded()) {
			$row->update();
			return self::RESULT_ALREADY_RECORDED;
		}

		$currency = mpagoString($mporder['currency'] ?? '');
		if ($currency !== $conf->currency) {
			return $this->failRow($row, 'Paid in '.$currency.' but the company currency is '.$conf->currency.': not recorded', $now);
		}
		$paidamount = mpagoFloat(price2num(mpagoString($mporder['total_paid_amount'] ?? ''), 'MT'));
		if ($paidamount <= 0) {
			return $this->failRow($row, 'Order reported as paid without a paid amount: not recorded', $now);
		}

		$langs->load('mpago@mpago');
		$configerrors = mpagoGetConfigErrors($this->db);
		if (!empty($configerrors)) {
			$messages = array();
			foreach ($configerrors as $key) {
				$messages[] = $langs->transnoentities($key);
			}
			return $this->failRow($row, 'Payment received but not recorded, the setup is incomplete: '.implode(' ', $messages), $now);
		}
		$actor = new User($this->db);
		$actor->fetch(getDolGlobalInt('MPAGO_USER_ID'));
		$actor->loadRights();

		$warning = '';
		if (mpagoFormatAmount($paidamount) !== mpagoFormatAmount($row->amount)) {
			$warning = 'Amount paid '.mpagoFormatAmount($paidamount).' differs from the amount requested '.mpagoFormatAmount($row->amount);
			dol_syslog(__METHOD__.' order '.$row->mp_order_id.': '.$warning, LOG_WARNING);
		}

		$this->db->begin();

		$locked = new MpagoOrder($this->db);
		if ($locked->fetch($row->id, true) <= 0) {
			$this->db->rollback();
			return $this->failRow($row, 'Row not found when locking it', $now);
		}
		if ($locked->isRecorded()) {
			$this->db->commit();
			$row->fetch($row->id);
			return self::RESULT_ALREADY_RECORDED;
		}

		$locked->status = $row->status;
		$locked->status_detail = $row->status_detail;
		$locked->date_last_check = $now;
		$locked->last_error = $warning;

		$recorderror = '';
		$existing = $this->findExistingPayment($row->mp_order_id);
		if ($existing < 0) {
			$recorderror = $this->db->lasterror();
		} elseif ($existing > 0) {
			// Second defence: the payment exists although the row does not point to it
			$locked->fk_paiement = $existing;
			$locked->fk_facture = $this->findInvoiceOfPayment($existing);
		} else {
			try {
				$recorderror = $this->record($locked, $mporder, $paidamount, $actor);
			} catch (Throwable $e) {
				$recorderror = get_class($e).': '.$e->getMessage();
			}
		}

		if ($recorderror === '' && $locked->update() < 0) {
			$recorderror = $locked->error;
		}
		if ($recorderror !== '') {
			$this->db->rollback();
			return $this->failRow($row, 'Payment received but not recorded: '.$recorderror, $now);
		}

		$this->db->commit();
		$row->fetch($row->id);

		return ($existing > 0 ? self::RESULT_ALREADY_RECORDED : self::RESULT_RECORDED);
	}

	/**
	 * Create the payment and its bank line. Runs inside the transaction opened by sync().
	 *
	 * @param	MpagoOrder			$row			Locked row; fk_paiement and fk_facture are set on success
	 * @param	array<string,mixed>	$mporder		Order as returned by Mercado Pago
	 * @param	float				$paidamount		Amount received
	 * @param	User				$actor			User the documents are created with
	 * @return	string								'' on success, else the error message
	 */
	private function record(MpagoOrder $row, array $mporder, $paidamount, User $actor)
	{
		/** @var User $user */
		global $user;

		// Triggers and some core methods read the global user (core's own webhook, public/stripe/ipn.php, replaces it too).
		$saveduser = $user;
		$user = $actor;
		try {
			$invoice = new Facture($this->db);

			if ($row->source === MpagoOrder::SOURCE_INVOICE) {
				if ($invoice->fetch($row->fk_source) <= 0) {
					return 'Invoice '.$row->fk_source.' not found';
				}
			} else {
				$order = new Commande($this->db);
				if ($order->fetch($row->fk_source) <= 0) {
					return 'Order '.$row->fk_source.' not found';
				}

				if ((int) $order->status == Commande::STATUS_CANCELED) {
					// The money was received: it is recorded anyway, and the row is flagged so that somebody decides what to do with the order.
					$row->last_error = trim('Paid although order '.$order->ref.' was cancelled: check stock and delivery, or refund. '.$row->last_error);
					dol_syslog(__METHOD__.' order '.$row->mp_order_id.': '.$row->last_error, LOG_WARNING);
				}

				$openinvoiceid = $this->findOpenInvoiceOfOrder((int) $order->id);
				if ($openinvoiceid > 0) {
					// The order was invoiced by hand while the buyer was paying: pay that invoice instead of creating a second one
					if ($invoice->fetch($openinvoiceid) <= 0) {
						return 'Invoice '.$openinvoiceid.' not found';
					}
				} else {
					if ($invoice->createFromOrder($order, $actor) <= 0) {
						return 'Invoice not created from order '.$order->ref.': '.$this->describe($invoice);
					}
					if ($order->classifyBilled($actor) < 0) {
						return 'Order '.$order->ref.' not classified billed: '.$this->describe($order);
					}
				}
			}

			if ((int) $invoice->status == Facture::STATUS_DRAFT) {
				if ($invoice->validate($actor) <= 0) {
					return 'Invoice '.$invoice->ref.' not validated: '.$this->describe($invoice);
				}
			}

			$mppayment = $this->firstPayment($mporder);

			$payment = new Paiement($this->db);
			$payment->datepaye = dol_now();
			$payment->amounts = array((int) $invoice->id => $paidamount);
			$payment->paiementid = mpagoGetPaymentModeId($this->db);
			$payment->num_payment = mpagoString($mppayment['id'] ?? '');
			$payment->ext_payment_id = $row->mp_order_id;
			$payment->ext_payment_site = self::PAYMENT_SITE;
			$payment->note_private = $this->buildNote($row, $mppayment);

			if ($payment->create($actor, 1) < 0) {
				return 'Payment not created: '.$this->describe($payment);
			}
			if ($payment->addPaymentToBank($actor, 'payment', '(CustomerInvoicePayment)', getDolGlobalInt('MPAGO_BANK_ACCOUNT'), '', '') < 0) {
				return 'Bank line not created: '.$this->describe($payment);
			}

			$row->fk_paiement = (int) $payment->id;
			$row->fk_facture = (int) $invoice->id;

			return '';
		} finally {
			$user = $saveduser;
		}
	}

	/**
	 * Save an error on the row and return the error result.
	 *
	 * @param	MpagoOrder	$row		Row
	 * @param	string		$message	Error message
	 * @param	int			$now		Time of the check
	 * @return	int						RESULT_ERROR
	 */
	private function failRow(MpagoOrder $row, $message, $now)
	{
		$this->error = $message;
		dol_syslog(get_class($this).' order '.$row->mp_order_id.' ('.$row->external_reference.'): '.$message, LOG_ERR);

		$status = $row->status;
		$statusdetail = $row->status_detail;
		// A rollback may have undone changes that the object still carries.
		$row->fetch($row->id);
		$row->status = $status;
		$row->status_detail = $statusdetail;
		$row->date_last_check = $now;
		$row->last_error = $message;
		$row->update();

		return self::RESULT_ERROR;
	}

	/**
	 * Look for a payment already recorded for a Mercado Pago order.
	 *
	 * @param	string	$mpOrderId	Order id at Mercado Pago
	 * @return	int					Id of the payment, 0 if none, <0 on error
	 */
	private function findExistingPayment($mpOrderId)
	{
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."paiement";
		$sql .= " WHERE ext_payment_id = '".$this->db->escape($mpOrderId)."' AND ext_payment_site = '".$this->db->escape(self::PAYMENT_SITE)."'";
		$sql .= " ORDER BY rowid LIMIT 1";

		return $this->queryId($sql);
	}

	/**
	 * Find the invoice a payment was recorded on.
	 *
	 * @param	int		$paymentId	Id of the payment
	 * @return	int					Id of the invoice, 0 if none
	 */
	private function findInvoiceOfPayment($paymentId)
	{
		return max(0, $this->queryId("SELECT fk_facture as rowid FROM ".MAIN_DB_PREFIX."paiement_facture WHERE fk_paiement = ".((int) $paymentId)." ORDER BY rowid LIMIT 1"));
	}

	/**
	 * Find the invoice of an order when exactly one exists and it is still to pay.
	 *
	 * @param	int		$orderId	Id of the order
	 * @return	int					Id of the invoice, 0 if there is none or more than one
	 */
	private function findOpenInvoiceOfOrder($orderId)
	{
		$sql = "SELECT f.rowid FROM ".MAIN_DB_PREFIX."element_element as ee";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = ee.fk_target";
		$sql .= " WHERE ee.sourcetype = 'commande' AND ee.fk_source = ".((int) $orderId)." AND ee.targettype = 'facture'";
		$sql .= " AND f.paye = 0 AND f.fk_statut IN (".Facture::STATUS_DRAFT.", ".Facture::STATUS_VALIDATED.")";

		$lines = mpagoFetchAll($this->db, $sql);

		return ($lines !== null && count($lines) == 1 ? mpagoInt($lines[0]['rowid']) : 0);
	}

	/**
	 * Run a query that returns one id in a column named rowid.
	 *
	 * @param	string	$sql	Query
	 * @return	int				Id, 0 if no line, <0 on error
	 */
	private function queryId($sql)
	{
		$lines = mpagoFetchAll($this->db, $sql);
		if ($lines === null) {
			return -1;
		}

		return (isset($lines[0]) ? mpagoInt($lines[0]['rowid']) : 0);
	}

	/**
	 * First payment transaction of a Mercado Pago order.
	 *
	 * @param	array<string,mixed>	$mporder	Order as returned by Mercado Pago
	 * @return	array<mixed>					Payment, empty if the order has none
	 */
	private function firstPayment(array $mporder)
	{
		$transactions = $mporder['transactions'] ?? null;
		if (is_array($transactions) && isset($transactions['payments']) && is_array($transactions['payments'])) {
			$first = reset($transactions['payments']);
			if (is_array($first)) {
				return $first;
			}
		}

		return array();
	}

	/**
	 * Private note of the payment: order, payment method and instalments.
	 *
	 * @param	MpagoOrder		$row		Row
	 * @param	array<mixed>	$mppayment	Payment transaction of the Mercado Pago order
	 * @return	string						Note
	 */
	private function buildNote(MpagoOrder $row, array $mppayment)
	{
		$note = 'Mercado Pago '.$row->mp_order_id.' ('.$row->external_reference.')';

		$method = $mppayment['payment_method'] ?? null;
		if (is_array($method)) {
			$label = trim(mpagoString($method['id'] ?? '').' '.mpagoString($method['type'] ?? ''));
			if ($label !== '') {
				$note .= ' - '.$label;
			}
			$installments = mpagoInt($method['installments'] ?? 0);
			if ($installments > 0) {
				$note .= ' - '.$installments.'x';
			}
		}

		return $note;
	}

	/**
	 * Error text of a Dolibarr object.
	 *
	 * @param	CommonObject	$object		Object that reported an error
	 * @return	string						Error text
	 */
	private function describe($object)
	{
		$parts = array();
		if (!empty($object->error)) {
			$parts[] = $object->error;
		}
		foreach ($object->errors as $error) {
			if (is_string($error) && $error !== '' && !in_array($error, $parts, true)) {
				$parts[] = $error;
			}
		}

		return (empty($parts) ? 'unknown error' : implode(', ', $parts));
	}
}
