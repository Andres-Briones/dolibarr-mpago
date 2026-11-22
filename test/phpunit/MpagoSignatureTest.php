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
 * \file    test/phpunit/MpagoSignatureTest.php
 * \ingroup mpago
 * \brief   Tests of the verification of the x-signature header.
 */

require_once dirname(__DIR__, 2).'/class/mpagosignature.class.php';


/**
 * Class MpagoSignatureTest
 */
class MpagoSignatureTest extends PHPUnit\Framework\TestCase
{
	const SECRET = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
	const ORDER_ID = 'ORD01JQ4S4KY8HWQ6NA5PXB65B3D3';
	const REQUEST_ID = 'bb56a2f1-6aae-46ac-982e-9dcd3581d08e';
	const TS_MS = '1742505638683';
	const NOW = 1742505700;

	/**
	 * Header as Mercado Pago builds it.
	 *
	 * @param	string	$manifest	Signed text
	 * @param	string	$ts			Timestamp
	 * @param	string	$secret		Secret
	 * @return	string
	 */
	private function header($manifest, $ts, $secret = self::SECRET)
	{
		return 'ts='.$ts.',v1='.hash_hmac('sha256', $manifest, $secret);
	}

	/**
	 * The documented shape: 13-digit timestamp, id signed in lower case although it is received in upper case.
	 *
	 * @return void
	 */
	public function testOfficialShapeWithLowercasedId(): void
	{
		$manifest = 'id:ord01jq4s4ky8hwq6na5pxb65b3d3;request-id:'.self::REQUEST_ID.';ts:'.self::TS_MS.';';
		$this->assertSame($manifest, MpagoSignature::buildManifest(strtolower(self::ORDER_ID), self::REQUEST_ID, self::TS_MS));

		$verifier = new MpagoSignature();
		$this->assertTrue($verifier->verify($this->header($manifest, self::TS_MS), self::ORDER_ID, self::REQUEST_ID, self::SECRET, 600, self::NOW));
		$this->assertSame('', $verifier->error);
	}

	/**
	 * The official PHP SDK signs the id as received: accepted too.
	 *
	 * @return void
	 */
	public function testIdSignedAsReceivedIsAccepted(): void
	{
		$manifest = 'id:'.self::ORDER_ID.';request-id:'.self::REQUEST_ID.';ts:'.self::TS_MS.';';

		$verifier = new MpagoSignature();
		$this->assertTrue($verifier->verify($this->header($manifest, self::TS_MS), self::ORDER_ID, self::REQUEST_ID, self::SECRET, 600, self::NOW));
	}

	/**
	 * Parts without a value are left out of the signed text.
	 *
	 * @return void
	 */
	public function testMissingParts(): void
	{
		$verifier = new MpagoSignature();

		$manifest = 'id:ord01jq4s4ky8hwq6na5pxb65b3d3;ts:'.self::TS_MS.';';
		$this->assertSame($manifest, MpagoSignature::buildManifest(strtolower(self::ORDER_ID), '', self::TS_MS));
		$this->assertTrue($verifier->verify($this->header($manifest, self::TS_MS), self::ORDER_ID, '', self::SECRET, 600, self::NOW));
		// The same signature must not pass once a request id is present
		$this->assertFalse($verifier->verify($this->header($manifest, self::TS_MS), self::ORDER_ID, self::REQUEST_ID, self::SECRET, 600, self::NOW));

		$manifest = 'request-id:'.self::REQUEST_ID.';ts:'.self::TS_MS.';';
		$this->assertSame($manifest, MpagoSignature::buildManifest('', self::REQUEST_ID, self::TS_MS));
		$this->assertTrue($verifier->verify($this->header($manifest, self::TS_MS), '', self::REQUEST_ID, self::SECRET, 600, self::NOW));
	}

	/**
	 * Timestamps in milliseconds (13 digits) and in seconds (10 digits) are both understood.
	 *
	 * @return void
	 */
	public function testTimestampUnits(): void
	{
		$this->assertSame(1742505638, MpagoSignature::timestampToSeconds('1742505638683'));
		$this->assertSame(1742505638, MpagoSignature::timestampToSeconds('1742505638'));

		$verifier = new MpagoSignature();
		foreach (array('1742505638683', '1742505638') as $ts) {
			$manifest = MpagoSignature::buildManifest(strtolower(self::ORDER_ID), self::REQUEST_ID, $ts);
			$this->assertTrue($verifier->verify($this->header($manifest, $ts), self::ORDER_ID, self::REQUEST_ID, self::SECRET, 600, self::NOW), 'ts '.$ts);
		}
	}

	/**
	 * A valid signature is refused when its timestamp is too far from now, in either unit and in either direction.
	 *
	 * @return void
	 */
	public function testStaleTimestamp(): void
	{
		$verifier = new MpagoSignature();
		foreach (array('1742505638683', '1742505638') as $ts) {
			$header = $this->header(MpagoSignature::buildManifest(strtolower(self::ORDER_ID), self::REQUEST_ID, $ts), $ts);

			$this->assertFalse($verifier->verify($header, self::ORDER_ID, self::REQUEST_ID, self::SECRET, 600, 1742505638 + 601), 'ts '.$ts);
			$this->assertSame('stale', $verifier->error);
			$this->assertFalse($verifier->verify($header, self::ORDER_ID, self::REQUEST_ID, self::SECRET, 600, 1742505638 - 601), 'ts '.$ts);
			$this->assertTrue($verifier->verify($header, self::ORDER_ID, self::REQUEST_ID, self::SECRET, 600, 1742505638 + 600), 'ts '.$ts);
			// A tolerance of 0 switches the check off
			$this->assertTrue($verifier->verify($header, self::ORDER_ID, self::REQUEST_ID, self::SECRET, 0, 1742505638 + 86400 * 30), 'ts '.$ts);
		}
	}

	/**
	 * Wrong or missing secret, altered values.
	 *
	 * @return void
	 */
	public function testWrongSecretAndTampering(): void
	{
		$manifest = MpagoSignature::buildManifest(strtolower(self::ORDER_ID), self::REQUEST_ID, self::TS_MS);
		$header = $this->header($manifest, self::TS_MS);
		$verifier = new MpagoSignature();

		$this->assertFalse($verifier->verify($this->header($manifest, self::TS_MS, 'another-secret'), self::ORDER_ID, self::REQUEST_ID, self::SECRET, 600, self::NOW));
		$this->assertSame('mismatch', $verifier->error);

		$this->assertFalse($verifier->verify($header, self::ORDER_ID, self::REQUEST_ID, '', 600, self::NOW));
		$this->assertSame('no_secret', $verifier->error);

		$this->assertFalse($verifier->verify($header, 'ORD01OTHER', self::REQUEST_ID, self::SECRET, 600, self::NOW));
		$this->assertFalse($verifier->verify($header, self::ORDER_ID, 'another-request', self::SECRET, 600, self::NOW));
		// A newer timestamp glued on an old signature
		$this->assertFalse($verifier->verify(str_replace(self::TS_MS, '1742505699000', $header), self::ORDER_ID, self::REQUEST_ID, self::SECRET, 600, self::NOW));
		$this->assertSame('mismatch', $verifier->error);
	}

	/**
	 * Headers that cannot be used.
	 *
	 * @return void
	 */
	public function testBadHeader(): void
	{
		$verifier = new MpagoSignature();
		foreach (array('', 'v1=abc', 'ts=1742505638683', 'ts=abc,v1='.str_repeat('a', 64), 'ts=1742505638683,v1=zz') as $header) {
			$this->assertNull(MpagoSignature::parseHeader($header), $header);
			$this->assertFalse($verifier->verify($header, self::ORDER_ID, self::REQUEST_ID, self::SECRET, 600, self::NOW));
			$this->assertSame('bad_header', $verifier->error);
		}

		$this->assertSame(array('ts' => '1742505638683', 'v1' => str_repeat('a', 64)), MpagoSignature::parseHeader(' ts=1742505638683 , v1='.str_repeat('A', 64)));
	}
}
