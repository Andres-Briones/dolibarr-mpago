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
 * \file    class/mpagoorder.class.php
 * \ingroup mpago
 * \brief   A Mercado Pago order created for a Dolibarr invoice or order (table llx_mpago_order).
 */

require_once __DIR__.'/../lib/mpago.lib.php';

/**
 * A Mercado Pago order created for a Dolibarr invoice or order (table llx_mpago_order).
 */
class MpagoOrder
{
	const SOURCE_INVOICE = 'invoice';
	const SOURCE_ORDER = 'order';

	const STATUS_CREATED = 'created';
	const STATUS_ACTION_REQUIRED = 'action_required';
	const STATUS_PROCESSING = 'processing';
	const STATUS_PROCESSED = 'processed';
	const DETAIL_ACCREDITED = 'accredited';

	/**
	 * Statuses after which Mercado Pago will never report the order as paid.
	 */
	const FINAL_UNPAID_STATUSES = array('failed', 'canceled', 'expired', 'refunded', 'charged_back');

	/**
	 * @var DoliDB
	 */
	public $db;

	/**
	 * @var string
	 */
	public $error = '';

	/**
	 * @var int
	 */
	public $id = 0;

	/**
	 * @var int
	 */
	public $entity = 1;

	/**
	 * @var string 'invoice' or 'order'
	 */
	public $source = '';

	/**
	 * @var int Id of the invoice or order to pay
	 */
	public $fk_source = 0;

	/**
	 * @var string
	 */
	public $ref_source = '';

	/**
	 * @var string Reference sent to Mercado Pago, unique
	 */
	public $external_reference = '';

	/**
	 * @var string Random value that identifies the row in the return URL
	 */
	public $return_token = '';

	/**
	 * @var int Number of creation requests sent to Mercado Pago, part of the idempotency key
	 */
	public $attempt = 0;

	/**
	 * @var string Order id at Mercado Pago, '' until created
	 */
	public $mp_order_id = '';

	/**
	 * @var string
	 */
	public $checkout_url = '';

	/**
	 * @var float Amount requested
	 */
	public $amount = 0.0;

	/**
	 * @var string
	 */
	public $currency = '';

	/**
	 * @var string Last status seen at Mercado Pago
	 */
	public $status = '';

	/**
	 * @var string
	 */
	public $status_detail = '';

	/**
	 * @var int|null 1 production, 0 test, null unknown
	 */
	public $live_mode = null;

	/**
	 * @var int Id of the payment recorded in Dolibarr, 0 if none
	 */
	public $fk_paiement = 0;

	/**
	 * @var int Id of the invoice that was paid, 0 if none
	 */
	public $fk_facture = 0;

	/**
	 * @var int
	 */
	public $date_creation = 0;

	/**
	 * @var int 0 if unknown
	 */
	public $date_expiration = 0;

	/**
	 * @var int 0 if never checked
	 */
	public $date_last_check = 0;

	/**
	 * @var string
	 */
	public $last_error = '';

	/**
	 * Constructor
	 *
	 * @param	DoliDB	$db		Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Tell whether the payment was recorded in Dolibarr.
	 *
	 * @return	bool
	 */
	public function isRecorded()
	{
		return $this->fk_paiement > 0;
	}

	/**
	 * Tell whether Mercado Pago reports the money as received.
	 *
	 * @return	bool
	 */
	public function isPaidAtMercadoPago()
	{
		return $this->status === self::STATUS_PROCESSED && $this->status_detail === self::DETAIL_ACCREDITED;
	}

	/**
	 * Tell whether the buyer can still be sent to the checkout of this order.
	 *
	 * @param	int		$now	Current time
	 * @return	bool
	 */
	public function isCheckoutUsable($now)
	{
		if ($this->isRecorded() || $this->mp_order_id === '' || $this->checkout_url === '') {
			return false;
		}
		if (!in_array($this->status, array(self::STATUS_CREATED, self::STATUS_ACTION_REQUIRED), true)) {
			return false;
		}

		return $this->date_expiration <= 0 || $this->date_expiration > $now;
	}

	/**
	 * Insert the row. Sets $id, $external_reference and $return_token.
	 *
	 * @return	int		Id of the row, <0 on error
	 */
	public function create()
	{
		/** @var Conf $conf */
		global $conf;

		if (!in_array($this->source, array(self::SOURCE_INVOICE, self::SOURCE_ORDER), true) || $this->fk_source <= 0) {
			$this->error = 'Bad source';
			return -1;
		}
		if (empty($this->entity)) {
			$this->entity = (int) $conf->entity;
		}
		if (empty($this->date_creation)) {
			$this->date_creation = dol_now();
		}
		$this->return_token = bin2hex(random_bytes(24));

		$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."mpago_order";
		$sql .= " WHERE entity = ".((int) $this->entity)." AND source = '".$this->db->escape($this->source)."' AND fk_source = ".((int) $this->fk_source);
		$lines = mpagoFetchAll($this->db, $sql);
		if ($lines === null) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$number = (isset($lines[0]) ? mpagoInt($lines[0]['nb']) : 0) + 1;

		// Two buyers can ask for the same number at the same time: the unique key refuses one of them, which then takes the next number.
		for ($try = 0; $try < 5; $try++) {
			$this->external_reference = $this->entity.'-'.substr($this->source, 0, 1).$this->fk_source.'-'.($number + $try);

			$sql = "INSERT INTO ".MAIN_DB_PREFIX."mpago_order (entity, source, fk_source, ref_source, external_reference, return_token, attempt, amount, currency, status, date_creation, date_expiration)";
			$sql .= " VALUES (".((int) $this->entity).", '".$this->db->escape($this->source)."', ".((int) $this->fk_source).", '".$this->db->escape($this->ref_source)."'";
			$sql .= ", '".$this->db->escape($this->external_reference)."', '".$this->db->escape($this->return_token)."', ".((int) $this->attempt);
			$sql .= ", ".((float) $this->amount).", '".$this->db->escape($this->currency)."', ".($this->status === '' ? "NULL" : "'".$this->db->escape($this->status)."'");
			$sql .= ", '".$this->db->idate($this->date_creation)."', ".($this->date_expiration > 0 ? "'".$this->db->idate($this->date_expiration)."'" : "NULL").")";

			$resql = $this->db->query($sql);
			if ($resql) {
				$this->id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'mpago_order');
				return $this->id;
			}
			if ($this->db->lasterrno() !== 'DB_ERROR_RECORD_ALREADY_EXISTS') {
				break;
			}
		}

		$this->error = $this->db->lasterror();
		return -1;
	}

	/**
	 * Save every field that changes after creation.
	 *
	 * @return	int		1 if OK, <0 on error
	 */
	public function update()
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."mpago_order SET";
		$sql .= " attempt = ".((int) $this->attempt);
		$sql .= ", mp_order_id = ".($this->mp_order_id === '' ? "NULL" : "'".$this->db->escape($this->mp_order_id)."'");
		$sql .= ", checkout_url = ".($this->checkout_url === '' ? "NULL" : "'".$this->db->escape($this->checkout_url)."'");
		$sql .= ", status = ".($this->status === '' ? "NULL" : "'".$this->db->escape($this->status)."'");
		$sql .= ", status_detail = ".($this->status_detail === '' ? "NULL" : "'".$this->db->escape($this->status_detail)."'");
		$sql .= ", live_mode = ".($this->live_mode === null ? "NULL" : (string) ((int) $this->live_mode));
		$sql .= ", fk_paiement = ".($this->fk_paiement > 0 ? (string) ((int) $this->fk_paiement) : "NULL");
		$sql .= ", fk_facture = ".($this->fk_facture > 0 ? (string) ((int) $this->fk_facture) : "NULL");
		$sql .= ", date_expiration = ".($this->date_expiration > 0 ? "'".$this->db->idate($this->date_expiration)."'" : "NULL");
		$sql .= ", date_last_check = ".($this->date_last_check > 0 ? "'".$this->db->idate($this->date_last_check)."'" : "NULL");
		$sql .= ", last_error = ".($this->last_error === '' ? "NULL" : "'".$this->db->escape(dol_trunc($this->last_error, 2000))."'");
		$sql .= " WHERE rowid = ".((int) $this->id);

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return 1;
	}

	/**
	 * Load a row by id.
	 *
	 * @param	int		$id			Id of the row
	 * @param	bool	$forupdate	Lock the row until the end of the current transaction
	 * @return	int					1 if found, 0 if not found, <0 on error
	 */
	public function fetch($id, $forupdate = false)
	{
		return $this->fetchWhere("rowid = ".((int) $id), $forupdate);
	}

	/**
	 * Load a row by the reference sent to Mercado Pago.
	 *
	 * @param	string	$externalReference	Reference
	 * @return	int							1 if found, 0 if not found, <0 on error
	 */
	public function fetchByExternalReference($externalReference)
	{
		return $this->fetchWhere("external_reference = '".$this->db->escape($externalReference)."'");
	}

	/**
	 * Load a row by the token of its return URL.
	 *
	 * @param	string	$token	Token
	 * @return	int				1 if found, 0 if not found, <0 on error
	 */
	public function fetchByReturnToken($token)
	{
		if ($token === '') {
			return 0;
		}
		return $this->fetchWhere("return_token = '".$this->db->escape($token)."'");
	}

	/**
	 * Load a row by the Mercado Pago order id.
	 *
	 * @param	string	$mpOrderId	Order id at Mercado Pago
	 * @return	int					1 if found, 0 if not found, <0 on error
	 */
	public function fetchByMpOrderId($mpOrderId)
	{
		if ($mpOrderId === '') {
			return 0;
		}
		return $this->fetchWhere("mp_order_id = '".$this->db->escape($mpOrderId)."'");
	}

	/**
	 * Load the most recent row of an invoice or order.
	 *
	 * @param	string	$source			'invoice' or 'order'
	 * @param	int		$fkSource		Id of the invoice or order
	 * @param	bool	$recordedonly	Only a row whose payment was recorded
	 * @return	int						1 if found, 0 if not found, <0 on error
	 */
	public function fetchLastForSource($source, $fkSource, $recordedonly = false)
	{
		/** @var Conf $conf */
		global $conf;

		$where = "entity = ".((int) $conf->entity)." AND source = '".$this->db->escape($source)."' AND fk_source = ".((int) $fkSource);
		if ($recordedonly) {
			$where .= " AND fk_paiement IS NOT NULL";
		}

		return $this->fetchWhere($where." ORDER BY rowid DESC");
	}

	/**
	 * Load the first row that matches a condition.
	 *
	 * @param	string	$where		SQL condition, already escaped
	 * @param	bool	$forupdate	Lock the row until the end of the current transaction
	 * @return	int					1 if found, 0 if not found, <0 on error
	 */
	private function fetchWhere($where, $forupdate = false)
	{
		$sql = "SELECT rowid, entity, source, fk_source, ref_source, external_reference, return_token, attempt, mp_order_id, checkout_url,";
		$sql .= " amount, currency, status, status_detail, live_mode, fk_paiement, fk_facture, date_creation, date_expiration, date_last_check, last_error";
		$sql .= " FROM ".MAIN_DB_PREFIX."mpago_order";
		$sql .= " WHERE ".$where;
		$sql .= " LIMIT 1";
		if ($forupdate) {
			$sql .= " FOR UPDATE";
		}

		$lines = mpagoFetchAll($this->db, $sql);
		if ($lines === null) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if (!isset($lines[0])) {
			return 0;
		}
		$row = $lines[0];

		$this->id = mpagoInt($row['rowid']);
		$this->entity = mpagoInt($row['entity']);
		$this->source = mpagoString($row['source']);
		$this->fk_source = mpagoInt($row['fk_source']);
		$this->ref_source = mpagoString($row['ref_source']);
		$this->external_reference = mpagoString($row['external_reference']);
		$this->return_token = mpagoString($row['return_token']);
		$this->attempt = mpagoInt($row['attempt']);
		$this->mp_order_id = mpagoString($row['mp_order_id']);
		$this->checkout_url = mpagoString($row['checkout_url']);
		$this->amount = mpagoFloat($row['amount']);
		$this->currency = mpagoString($row['currency']);
		$this->status = mpagoString($row['status']);
		$this->status_detail = mpagoString($row['status_detail']);
		$this->live_mode = ($row['live_mode'] === null ? null : mpagoInt($row['live_mode']));
		$this->fk_paiement = mpagoInt($row['fk_paiement']);
		$this->fk_facture = mpagoInt($row['fk_facture']);
		$this->date_creation = mpagoInt($this->db->jdate(mpagoString($row['date_creation'])));
		$this->date_expiration = mpagoInt($this->db->jdate(mpagoString($row['date_expiration'])));
		$this->date_last_check = mpagoInt($this->db->jdate(mpagoString($row['date_last_check'])));
		$this->last_error = mpagoString($row['last_error']);

		return 1;
	}
}
