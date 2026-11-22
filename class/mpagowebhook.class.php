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
 * \file    class/mpagowebhook.class.php
 * \ingroup mpago
 * \brief   Receives the notifications of Mercado Pago and stores them for later processing.
 */

require_once __DIR__.'/../lib/mpago.lib.php';
require_once __DIR__.'/mpagosignature.class.php';

/**
 * Receives the notifications of Mercado Pago and stores them for later processing.
 */
class MpagoWebhook
{
	const EVENT_PENDING = 0;
	const EVENT_DONE = 1;
	const EVENT_ERROR = -1;

	/**
	 * @var DoliDB
	 */
	public $db;

	/**
	 * @var string Short text that goes with the HTTP status of the last call of handle()
	 */
	public $message = '';

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
	 * Check a notification and store it. Does no remote call and records nothing in Dolibarr.
	 *
	 * @param	string		$dataId			Value of the data.id query parameter ('' if absent)
	 * @param	string		$type			Value of the type query parameter ('' if absent)
	 * @param	string		$signature		Value of the x-signature header
	 * @param	string		$requestId		Value of the x-request-id header
	 * @param	string		$body			Raw body of the request
	 * @param	int|null	$now			Current Unix time, null for time()
	 * @return	int							HTTP status to answer: 200, 401, 500 or 503
	 */
	public function handle($dataId, $type, $signature, $requestId, $body, $now = null)
	{
		/** @var Conf $conf */
		global $conf;

		$secret = getDolGlobalString('MPAGO_WEBHOOK_SECRET');
		if ($secret === '') {
			return $this->answer(503, 'Webhook secret not configured');
		}

		$tolerance = (getDolGlobalString('MPAGO_WEBHOOK_TOLERANCE') === '' ? MpagoSignature::DEFAULT_TOLERANCE : getDolGlobalInt('MPAGO_WEBHOOK_TOLERANCE'));
		$verifier = new MpagoSignature();
		if (!$verifier->verify($signature, $dataId, $requestId, $secret, $tolerance, $now)) {
			dol_syslog(__METHOD__.' notification refused: '.$verifier->error, LOG_WARNING);
			return $this->answer(401, 'Invalid signature');
		}

		$data = json_decode($body, true);
		// Only a body that decoded is known to be valid UTF-8, which the column requires
		$payload = (is_array($data) && strlen($body) <= 60000 ? $body : '');
		if (!is_array($data)) {
			$data = array();
		}
		if ($type === '') {
			$type = mpagoString($data['type'] ?? '');
		}
		$orderid = $dataId;
		if ($orderid === '' && isset($data['data']) && is_array($data['data'])) {
			$orderid = mpagoString($data['data']['id'] ?? '');
		}

		// Other topics and unusable ids are acknowledged: an error status would only make Mercado Pago send them again.
		if ($type !== 'order') {
			dol_syslog(__METHOD__.' notification ignored, type='.dol_trunc($type, 32), LOG_WARNING);
			return $this->answer(200, 'Ignored');
		}
		if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $orderid)) {
			dol_syslog(__METHOD__.' notification ignored, no usable order id', LOG_WARNING);
			return $this->answer(200, 'Ignored');
		}

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."mpago_event (entity, mp_order_id, action, request_id, live_mode, payload, date_creation, status, attempts)";
		$sql .= " VALUES (".((int) $conf->entity).", '".$this->db->escape($orderid)."'";
		$sql .= ", '".$this->db->escape(dol_trunc(mpagoString($data['action'] ?? ''), 64, 'right', 'UTF-8', 1))."'";
		$sql .= ", ".($requestId === '' ? "NULL" : "'".$this->db->escape(dol_trunc($requestId, 128, 'right', 'UTF-8', 1))."'");
		$sql .= ", ".(isset($data['live_mode']) ? (empty($data['live_mode']) ? '0' : '1') : "NULL");
		$sql .= ", '".$this->db->escape($payload)."'";
		$sql .= ", '".$this->db->idate(dol_now())."', ".self::EVENT_PENDING.", 0)";

		if (!$this->db->query($sql)) {
			if ($this->db->lasterrno() === 'DB_ERROR_RECORD_ALREADY_EXISTS') {
				return $this->answer(200, 'Already received');
			}
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			return $this->answer(500, 'Not stored');
		}

		return $this->answer(200, 'OK');
	}

	/**
	 * Set the message and return the status.
	 *
	 * @param	int		$code		HTTP status
	 * @param	string	$message	Message
	 * @return	int					HTTP status
	 */
	private function answer($code, $message)
	{
		$this->message = $message;
		return $code;
	}
}
