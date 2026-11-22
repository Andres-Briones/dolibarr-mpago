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
 * \file    class/mpagocheckout.class.php
 * \ingroup mpago
 * \brief   Starts the payment of an invoice or order: creates the Mercado Pago order and gives the URL to send the buyer to.
 */

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
require_once __DIR__.'/../lib/mpago.lib.php';
require_once __DIR__.'/mpagoclient.class.php';
require_once __DIR__.'/mpagoorder.class.php';
require_once __DIR__.'/mpagorecorder.class.php';

/**
 * Starts the payment of an invoice or order: creates the Mercado Pago order and gives the URL to send the buyer to.
 */
class MpagoCheckout
{
	/**
	 * @var DoliDB
	 */
	public $db;

	/**
	 * @var string Translation key of the last error, to show to the buyer
	 */
	public $error = '';

	/**
	 * @var MpagoOrder|null Row used by the last call of start()
	 */
	public $row = null;

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
	 * Load the invoice or order to pay, with its third party.
	 *
	 * @param	string	$source		'invoice' or 'order'
	 * @param	string	$ref		Ref of the object
	 * @return	Facture|Commande|null	Object, null if not found
	 */
	public function loadSource($source, $ref)
	{
		if ($ref === '') {
			return null;
		}
		if ($source === MpagoOrder::SOURCE_INVOICE) {
			$object = new Facture($this->db);
		} elseif ($source === MpagoOrder::SOURCE_ORDER) {
			$object = new Commande($this->db);
		} else {
			return null;
		}
		if ($object->fetch(0, $ref) <= 0) {
			return null;
		}
		$object->fetch_thirdparty();

		return $object;
	}

	/**
	 * Amount to request for an invoice or order, 0 when nothing can be paid.
	 * Sets $error to say why.
	 *
	 * @param	Facture|Commande	$object		Invoice or order
	 * @return	float							Amount, 0 if the object cannot be paid
	 */
	public function getAmountDue($object)
	{
		/** @var Conf $conf */
		global $conf;

		$this->error = '';

		if (!empty($object->multicurrency_code) && $object->multicurrency_code !== $conf->currency) {
			$this->error = 'MpagoErrorCurrencyNotARS';
			return 0.0;
		}

		if ($object instanceof Facture) {
			if ($object->paye || !in_array((int) $object->status, array(Facture::STATUS_DRAFT, Facture::STATUS_VALIDATED), true) || $object->type == Facture::TYPE_CREDIT_NOTE) {
				$this->error = 'MpagoErrorNothingToPay';
				return 0.0;
			}
			$alreadypaid = mpagoFloat($object->getSommePaiement()) + mpagoFloat($object->getSumCreditNotesUsed()) + mpagoFloat($object->getSumDepositsUsed());
			$amount = mpagoFloat(price2num(mpagoFloat($object->total_ttc) - $alreadypaid, 'MT'));
		} else {
			if ($object->billed || (int) $object->status == Commande::STATUS_CANCELED) {
				$this->error = 'MpagoErrorNothingToPay';
				return 0.0;
			}
			$amount = mpagoFloat(price2num($object->total_ttc, 'MT'));
		}

		if ($amount <= 0) {
			$this->error = 'MpagoErrorNothingToPay';
			return 0.0;
		}

		return $amount;
	}

	/**
	 * Start (or resume) the payment of an invoice or order.
	 *
	 * @param	string	$source		'invoice' or 'order'
	 * @param	string	$ref		Ref of the object
	 * @param	string	$email		E-mail given by the buyer, used when the third party has none
	 * @return	string				URL to redirect the buyer to, '' on error (see $error)
	 */
	public function start($source, $ref, $email = '')
	{
		/** @var Conf $conf */
		global $conf;

		$this->error = '';
		$this->row = null;
		$now = dol_now();

		if (!empty(mpagoGetConfigErrors($this->db))) {
			$this->error = 'MpagoErrorNotConfigured';
			return '';
		}

		$object = $this->loadSource($source, $ref);
		if ($object === null) {
			$this->error = 'MpagoErrorObjectNotFound';
			return '';
		}

		$amount = $this->getAmountDue($object);
		if ($amount <= 0) {
			return '';
		}

		$thirdpartyemail = (is_object($object->thirdparty) ? trim((string) $object->thirdparty->email) : '');
		$payeremail = ($thirdpartyemail !== '' ? $thirdpartyemail : trim($email));
		if (!isValidEmail($payeremail)) {
			$this->error = 'MpagoErrorEmailRequired';
			return '';
		}

		$row = new MpagoOrder($this->db);
		$found = $row->fetchLastForSource($source, (int) $object->id);
		if ($found < 0) {
			$this->error = 'MpagoErrorCannotStartPayment';
			return '';
		}

		if ($found > 0 && !$row->isRecorded()) {
			$this->row = $row;
			if ($row->mp_order_id !== '') {
				// The buyer may have paid already without the payment having reached Dolibarr yet.
				$recorder = new MpagoRecorder($this->db, $this->client);
				$recorder->sync($row);
				if ($row->fk_paiement > 0 || $row->isPaidAtMercadoPago() || $row->status === MpagoOrder::STATUS_PROCESSING) {
					return mpagoGetPublicUrl('return', array('t' => $row->return_token));
				}
				if ($row->isCheckoutUsable($now) && mpagoFormatAmount($row->amount) === mpagoFormatAmount($amount)) {
					return $row->checkout_url;
				}
			} elseif (mpagoFormatAmount($row->amount) === mpagoFormatAmount($amount) && ($row->date_expiration <= 0 || $row->date_expiration > $now)) {
				// The previous request to Mercado Pago failed: try again on the same row
				return $this->createAtMercadoPago($row, $object, $payeremail);
			}
		}

		$hours = getDolGlobalInt('MPAGO_EXPIRATION_HOURS');
		if ($hours <= 0) {
			$hours = 24;
		}

		$row = new MpagoOrder($this->db);
		$row->entity = (int) $conf->entity;
		$row->source = $source;
		$row->fk_source = (int) $object->id;
		$row->ref_source = (string) $object->ref;
		$row->amount = $amount;
		$row->currency = (string) $conf->currency;
		$row->date_creation = $now;
		$row->date_expiration = $now + $hours * 3600;
		if ($row->create() < 0) {
			dol_syslog(__METHOD__.' '.$row->error, LOG_ERR);
			$this->error = 'MpagoErrorCannotStartPayment';
			return '';
		}
		$this->row = $row;

		return $this->createAtMercadoPago($row, $object, $payeremail);
	}

	/**
	 * Body of the order creation request.
	 *
	 * @param	MpagoOrder			$row		Row of the order
	 * @param	Facture|Commande	$object		Invoice or order to pay
	 * @param	string				$email		E-mail of the payer
	 * @return	array<string,mixed>				Body
	 */
	public function buildOrderBody(MpagoOrder $row, $object, $email)
	{
		/** @var Societe $mysoc */
		global $mysoc;

		$amount = mpagoFormatAmount($row->amount);
		$hours = max(1, (int) ceil(($row->date_expiration - $row->date_creation) / 3600));

		$online = array('auto_return' => 'all');
		foreach (array('success', 'failure', 'pending') as $result) {
			$online[$result.'_url'] = mpagoGetPublicUrl('return', array('t' => $row->return_token, 'r' => $result));
		}
		$config = array('online' => $online);

		$maxinstallments = getDolGlobalInt('MPAGO_MAX_INSTALLMENTS');
		if ($maxinstallments >= 1 && $maxinstallments <= 36) {
			$config['payment_method'] = array('max_installments' => $maxinstallments);
		}
		$descriptor = trim(getDolGlobalString('MPAGO_STATEMENT_DESCRIPTOR'));
		if ($descriptor !== '') {
			$config['statement_descriptor'] = $descriptor;
		}

		return array(
			'type' => 'online',
			'processing_mode' => 'manual',
			'total_amount' => $amount,
			'external_reference' => $row->external_reference,
			'payer' => array('email' => $email),
			'items' => array(
				array(
					'title' => dol_trunc(trim($mysoc->name.' '.$object->ref), 250, 'right', 'UTF-8', 1),
					'quantity' => 1,
					'unit_price' => $amount,
				),
			),
			'expiration_time' => 'PT'.$hours.'H',
			'config' => $config,
		);
	}

	/**
	 * Create the order at Mercado Pago for a row and store what comes back.
	 *
	 * @param	MpagoOrder			$row		Row of the order
	 * @param	Facture|Commande	$object		Invoice or order to pay
	 * @param	string				$email		E-mail of the payer
	 * @return	string							Checkout URL, '' on error
	 */
	private function createAtMercadoPago(MpagoOrder $row, $object, $email)
	{
		// A key cannot be used twice at Mercado Pago, even after a timeout: each request gets its own.
		$row->attempt++;
		$row->last_error = '';
		if ($row->update() < 0) {
			dol_syslog(__METHOD__.' '.$row->error, LOG_ERR);
			$this->error = 'MpagoErrorCannotStartPayment';
			return '';
		}

		$mporder = $this->client->createOrder($this->buildOrderBody($row, $object, $email), $row->external_reference.'-'.$row->attempt);

		$checkouturl = ($mporder === null ? '' : mpagoString($mporder['checkout_url'] ?? ''));
		if ($mporder !== null && !preg_match('/^https?:\/\/[^\s]+$/', $checkouturl)) {
			$this->client->error = 'Answer without a usable checkout_url';
			$mporder = null;
		}
		if ($mporder === null) {
			dol_syslog(__METHOD__.' order '.$row->external_reference.' not created at Mercado Pago: '.$this->client->error, LOG_ERR);
			$row->last_error = 'Mercado Pago: '.$this->client->error;
			$row->update();
			$this->error = 'MpagoErrorCannotStartPayment';
			return '';
		}

		$row->mp_order_id = mpagoString($mporder['id'] ?? '');
		$row->checkout_url = $checkouturl;
		$row->status = mpagoString($mporder['status'] ?? MpagoOrder::STATUS_CREATED);
		$row->status_detail = mpagoString($mporder['status_detail'] ?? '');
		// Test orders are the ones whose id starts with ORDTST
		$row->live_mode = (strpos($row->mp_order_id, 'ORDTST') === 0 ? 0 : 1);
		if ($row->update() < 0) {
			dol_syslog(__METHOD__.' '.$row->error, LOG_ERR);
			$this->error = 'MpagoErrorCannotStartPayment';
			return '';
		}

		return $checkouturl;
	}
}
