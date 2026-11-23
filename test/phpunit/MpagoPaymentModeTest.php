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
 * \file    test/phpunit/MpagoPaymentModeTest.php
 * \ingroup mpago
 * \brief   Tests of the choice of the payment mode written on the recorded payments.
 */

require_once __DIR__.'/MpagoTestCase.php';


/**
 * Class MpagoPaymentModeTest
 */
class MpagoPaymentModeTest extends MpagoTestCase
{
	/**
	 * Id of the credit card payment mode, the last resort.
	 *
	 * @return	int
	 */
	private function creditCardId()
	{
		/** @var DoliDB $db */
		global $db;

		$id = mpagoInt(dol_getIdFromCode($db, 'CB', 'c_paiement', 'code', 'id', 1));
		$this->assertGreaterThan(0, $id, 'The instance has no CB payment mode');

		return $id;
	}

	/**
	 * Without a setting, the active Mercado Pago payment mode of the entity is used.
	 *
	 * @return void
	 */
	public function testDefaultIsMercadoPago(): void
	{
		/** @var DoliDB $db */
		global $db;

		$id = $this->givePaymentMode();

		$this->assertSame($id, mpagoGetPaymentModeId($db));
	}

	/**
	 * The payment mode chosen in the setup wins over the Mercado Pago one.
	 *
	 * @return void
	 */
	public function testSettingWins(): void
	{
		/** @var DoliDB $db */
		global $db;

		$this->givePaymentMode();
		$cb = $this->creditCardId();
		mpagoSetConfInMemory('MPAGO_PAYMENT_MODE', (string) $cb);

		$this->assertSame($cb, mpagoGetPaymentModeId($db));
	}

	/**
	 * A Mercado Pago payment mode an admin disabled is not used: credit card is.
	 *
	 * @return void
	 */
	public function testDisabledFallsBackToCreditCard(): void
	{
		/** @var DoliDB $db */
		global $db;

		$this->givePaymentMode(0);

		$this->assertSame($this->creditCardId(), mpagoGetPaymentModeId($db));
	}

	/**
	 * Without a Mercado Pago payment mode, credit card is used.
	 *
	 * @return void
	 */
	public function testMissingFallsBackToCreditCard(): void
	{
		/** @var DoliDB $db */
		global $db;

		$this->hidePaymentMode();

		$this->assertSame($this->creditCardId(), mpagoGetPaymentModeId($db));
	}
}
