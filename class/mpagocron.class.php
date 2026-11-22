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
 * \file    class/mpagocron.class.php
 * \ingroup mpago
 * \brief   Scheduled jobs of the Mpago module.
 */

require_once __DIR__.'/../lib/mpago.lib.php';
require_once __DIR__.'/mpagoorder.class.php';
require_once __DIR__.'/mpagorecorder.class.php';
require_once __DIR__.'/mpagowebhook.class.php';

/**
 * Scheduled jobs of the Mpago module.
 */
class MpagoCron
{
	const MAX_EVENT_ATTEMPTS = 10;
	const MAX_PER_RUN = 100;
	const POLL_MIN_INTERVAL = 600;
	const POLL_CREATED_GRACE = 3600;
	const POLL_MAX_AGE = 2592000;

	/**
	 * @var DoliDB
	 */
	public $db;

	/**
	 * @var string
	 */
	public $error = '';

	/**
	 * @var string[]
	 */
	public $errors = array();

	/**
	 * @var string Text shown as the result of the job
	 */
	public $output = '';

	/**
	 * @var MpagoRecorder|null
	 */
	private $recorder;

	/**
	 * Constructor
	 *
	 * @param	DoliDB				$db			Database handler
	 * @param	MpagoRecorder|null	$recorder	Recorder, null to build it from the setup when a job runs
	 */
	public function __construct($db, $recorder = null)
	{
		$this->db = $db;
		$this->recorder = $recorder;
	}

	/**
	 * Process the notifications stored by the webhook: synchronise the order each one is about.
	 *
	 * @return	int		0 if OK, <0 on error
	 */
	public function processEvents()
	{
		/** @var Conf $conf */
		global $conf;

		$this->error = '';
		$this->errors = array();
		$now = dol_now();

		$sql = "SELECT rowid, mp_order_id, attempts FROM ".MAIN_DB_PREFIX."mpago_event";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND status = ".MpagoWebhook::EVENT_PENDING;
		$sql .= " ORDER BY rowid";
		$sql .= $this->db->plimit(self::MAX_PER_RUN);
		$lines = mpagoFetchAll($this->db, $sql);
		if ($lines === null) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$events = array();
		foreach ($lines as $line) {
			$events[] = array('id' => mpagoInt($line['rowid']), 'mp_order_id' => mpagoString($line['mp_order_id']), 'attempts' => mpagoInt($line['attempts']));
		}

		$done = 0;
		$failed = 0;
		$unknown = 0;
		/** @var array<string,array{0:int,1:string}> $results */
		$results = array();
		foreach ($events as $event) {
			$orderid = $event['mp_order_id'];

			// Several notifications about the same order need one synchronisation only
			if (!isset($results[$orderid])) {
				$row = new MpagoOrder($this->db);
				$found = $row->fetchByMpOrderId($orderid);
				if ($found > 0 && $row->entity != (int) $conf->entity) {
					$found = 0;
				}
				if ($found == 0) {
					// The Mercado Pago application may be shared with another system
					$results[$orderid] = array(MpagoRecorder::RESULT_NOT_PAID, 'Unknown order');
					$unknown++;
				} elseif ($found < 0) {
					$results[$orderid] = array(MpagoRecorder::RESULT_ERROR, $row->error);
				} else {
					$recorder = $this->getRecorder();
					$results[$orderid] = array($recorder->sync($row), $recorder->error);
				}
			}

			list($result, $message) = $results[$orderid];
			$attempts = $event['attempts'] + 1;
			if ($result >= 0) {
				$status = MpagoWebhook::EVENT_DONE;
				$done++;
			} else {
				// Kept pending for the next runs; after too many failures the polling job remains the safety net
				$status = ($attempts >= self::MAX_EVENT_ATTEMPTS ? MpagoWebhook::EVENT_ERROR : MpagoWebhook::EVENT_PENDING);
				$failed++;
			}

			$sql = "UPDATE ".MAIN_DB_PREFIX."mpago_event SET status = ".((int) $status).", attempts = ".((int) $attempts);
			$sql .= ", date_processed = '".$this->db->idate($now)."'";
			$sql .= ", last_error = ".($message === '' ? "NULL" : "'".$this->db->escape(dol_trunc($message, 2000))."'");
			$sql .= " WHERE rowid = ".((int) $event['id']);
			if (!$this->db->query($sql)) {
				$this->errors[] = $this->db->lasterror();
			}
		}

		$this->output = count($events).' notification(s): '.$done.' processed ('.$unknown.' for unknown orders), '.$failed.' failed';
		if ($failed > 0) {
			$this->errors[] = $failed.' notification(s) could not be processed, see the setup page of the module';
		}
		if (!empty($this->errors)) {
			$this->error = $this->errors[0];
			return -1;
		}

		return 0;
	}

	/**
	 * Safety net for missed notifications and closed browsers: synchronise the orders that are not
	 * settled yet and were not checked recently.
	 *
	 * @return	int		0 if OK, <0 on error
	 */
	public function pollOpenOrders()
	{
		/** @var Conf $conf */
		global $conf;

		$this->error = '';
		$this->errors = array();
		$now = dol_now();

		$finalstatuses = array();
		foreach (MpagoOrder::FINAL_UNPAID_STATUSES as $status) {
			$finalstatuses[] = "'".$this->db->escape($status)."'";
		}

		// Unpaid orders are followed until they expire, payments in progress (offline methods, reviews) for
		// 30 days, and payments received that could not be recorded until they are.
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."mpago_order";
		$sql .= " WHERE entity = ".((int) $conf->entity);
		$sql .= " AND fk_paiement IS NULL AND mp_order_id IS NOT NULL";
		$sql .= " AND (status IS NULL OR status NOT IN (".implode(', ', $finalstatuses)."))";
		$sql .= " AND (date_last_check IS NULL OR date_last_check < '".$this->db->idate($now - self::POLL_MIN_INTERVAL)."')";
		$sql .= " AND (status = '".MpagoOrder::STATUS_PROCESSED."'";
		$sql .= " OR (status IN ('".MpagoOrder::STATUS_ACTION_REQUIRED."', '".MpagoOrder::STATUS_PROCESSING."') AND date_creation > '".$this->db->idate($now - self::POLL_MAX_AGE)."')";
		$sql .= " OR date_expiration IS NULL OR date_expiration > '".$this->db->idate($now - self::POLL_CREATED_GRACE)."')";
		$sql .= " ORDER BY date_last_check, rowid";
		$sql .= $this->db->plimit(self::MAX_PER_RUN);
		$lines = mpagoFetchAll($this->db, $sql);
		if ($lines === null) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$ids = array();
		foreach ($lines as $line) {
			$ids[] = mpagoInt($line['rowid']);
		}

		$recorded = 0;
		$failed = 0;
		foreach ($ids as $id) {
			$row = new MpagoOrder($this->db);
			if ($row->fetch($id) <= 0) {
				continue;
			}
			$result = $this->getRecorder()->sync($row);
			if ($result == MpagoRecorder::RESULT_RECORDED) {
				$recorded++;
			} elseif ($result < 0) {
				$failed++;
			}
		}

		$this->output = count($ids).' order(s) checked: '.$recorded.' payment(s) recorded, '.$failed.' failed';
		if ($failed > 0) {
			$this->error = $failed.' order(s) could not be synchronised, see the setup page of the module';
			$this->errors[] = $this->error;
			return -1;
		}

		return 0;
	}

	/**
	 * Recorder used by the jobs.
	 *
	 * @return	MpagoRecorder
	 */
	private function getRecorder()
	{
		if ($this->recorder === null) {
			$this->recorder = new MpagoRecorder($this->db);
		}

		return $this->recorder;
	}
}
