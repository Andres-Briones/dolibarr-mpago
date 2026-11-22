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
 * \file    class/mpagosignature.class.php
 * \ingroup mpago
 * \brief   Verification of the x-signature header of Mercado Pago notifications.
 */

/**
 * Verification of the x-signature header of Mercado Pago notifications. No dependency on Dolibarr.
 */
class MpagoSignature
{
	const DEFAULT_TOLERANCE = 600;

	/**
	 * @var string Reason of the last refusal: 'no_secret', 'bad_header', 'stale' or 'mismatch'
	 */
	public $error = '';

	/**
	 * Split the header "ts=1742505638683,v1=ced36ab6...".
	 *
	 * @param	string	$header		Value of the x-signature header
	 * @return	array{ts:string,v1:string}|null		Parts, null when the header is not usable
	 */
	public static function parseHeader($header)
	{
		$ts = '';
		$v1 = '';
		foreach (explode(',', $header) as $part) {
			$keyvalue = explode('=', $part, 2);
			if (count($keyvalue) != 2) {
				continue;
			}
			$key = trim($keyvalue[0]);
			$value = trim($keyvalue[1]);
			if ($key === 'ts') {
				$ts = $value;
			} elseif ($key === 'v1') {
				$v1 = strtolower($value);
			}
		}

		if (!preg_match('/^[0-9]{9,14}$/', $ts) || !preg_match('/^[0-9a-f]{64}$/', $v1)) {
			return null;
		}

		return array('ts' => $ts, 'v1' => $v1);
	}

	/**
	 * Build the signed text "id:<data.id>;request-id:<x-request-id>;ts:<ts>;". Parts without a value are left out.
	 *
	 * @param	string	$dataId		Value of the data.id query parameter, as it must be signed
	 * @param	string	$requestId	Value of the x-request-id header
	 * @param	string	$ts			Timestamp taken from the x-signature header
	 * @return	string				Manifest
	 */
	public static function buildManifest($dataId, $requestId, $ts)
	{
		$manifest = '';
		if ($dataId !== '') {
			$manifest .= 'id:'.$dataId.';';
		}
		if ($requestId !== '') {
			$manifest .= 'request-id:'.$requestId.';';
		}
		if ($ts !== '') {
			$manifest .= 'ts:'.$ts.';';
		}

		return $manifest;
	}

	/**
	 * Convert the timestamp of the header to seconds. The documentation says milliseconds (13 digits),
	 * the official SDK and one documented example use seconds (10 digits).
	 *
	 * @param	string	$ts		Timestamp taken from the x-signature header
	 * @return	int				Unix time in seconds
	 */
	public static function timestampToSeconds($ts)
	{
		if (strlen($ts) >= 12) {
			return (int) floor(((float) $ts) / 1000);
		}

		return (int) $ts;
	}

	/**
	 * Check a notification.
	 *
	 * @param	string		$header		Value of the x-signature header
	 * @param	string		$dataId		Value of the data.id query parameter ('' if absent)
	 * @param	string		$requestId	Value of the x-request-id header ('' if absent)
	 * @param	string		$secret		Secret signature of the Mercado Pago application
	 * @param	int			$tolerance	Accepted difference in seconds between the timestamp and now, 0 to accept any
	 * @param	int|null	$now		Current Unix time, null for time()
	 * @return	bool					True when the signature is valid and recent enough
	 */
	public function verify($header, $dataId, $requestId, $secret, $tolerance = self::DEFAULT_TOLERANCE, $now = null)
	{
		$this->error = '';

		if ($secret === '') {
			$this->error = 'no_secret';
			return false;
		}

		$parts = self::parseHeader($header);
		if ($parts === null) {
			$this->error = 'bad_header';
			return false;
		}

		// The documentation asks for the lowercased id, the official PHP SDK signs it as received: accept both.
		$valid = false;
		foreach (array_unique(array(strtolower($dataId), $dataId)) as $candidate) {
			$expected = hash_hmac('sha256', self::buildManifest($candidate, $requestId, $parts['ts']), $secret);
			if (hash_equals($expected, $parts['v1'])) {
				$valid = true;
			}
		}
		if (!$valid) {
			$this->error = 'mismatch';
			return false;
		}

		if ($tolerance > 0) {
			$now = ($now === null ? time() : $now);
			if (abs($now - self::timestampToSeconds($parts['ts'])) > $tolerance) {
				$this->error = 'stale';
				return false;
			}
		}

		return true;
	}
}
