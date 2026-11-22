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
 * \file    lib/mpago.lib.php
 * \ingroup mpago
 * \brief   Shared functions of the Mpago module.
 */

/**
 * Cast a value of unknown type (database field, decoded JSON) to string.
 *
 * @param	mixed	$value	Value
 * @return	string			String, '' when the value is not a scalar
 */
function mpagoString($value)
{
	return is_scalar($value) ? (string) $value : '';
}

/**
 * Cast a value of unknown type (database field, decoded JSON) to int.
 *
 * @param	mixed	$value	Value
 * @return	int				Integer, 0 when the value is not numeric
 */
function mpagoInt($value)
{
	return is_numeric($value) ? (int) $value : 0;
}

/**
 * Cast a value of unknown type (database field, decoded JSON) to float.
 *
 * @param	mixed	$value	Value
 * @return	float			Float, 0 when the value is not numeric
 */
function mpagoFloat($value)
{
	return is_numeric($value) ? (float) $value : 0.0;
}

/**
 * Run a SELECT and return all its lines.
 *
 * @param	DoliDB	$db		Database handler
 * @param	string	$sql	Query
 * @return	array<int,array<int|string,mixed>>|null		Lines, null on error
 */
function mpagoFetchAll($db, $sql)
{
	$resql = $db->query($sql);
	if (is_bool($resql)) {
		return null;
	}
	$lines = array();
	while (is_array($line = $db->fetch_array($resql))) {
		$lines[] = $line;
	}
	$db->free($resql);

	return $lines;
}

/**
 * Format an amount the way the Mercado Pago API expects it: a string with 2 decimals.
 *
 * @param	float|int|string	$amount		Amount
 * @return	string							Amount like "1234.50"
 */
function mpagoFormatAmount($amount)
{
	return number_format(mpagoFloat(price2num($amount, 'MT')), 2, '.', '');
}

/**
 * Change a setup value for the current request only, without touching the database.
 *
 * @param	string	$name	Constant name
 * @param	string	$value	Value
 * @return	void
 */
function mpagoSetConfInMemory($name, $value)
{
	/** @var Conf $conf */
	global $conf;

	$conf->global->$name = $value;
}

/**
 * Save a secret constant encrypted, whatever its name.
 *
 * dolibarr_set_const() only encrypts names that match a list of suffixes, and "_TOKEN" is
 * in that list from Dolibarr 24 only. dolEncrypt() leaves an already encrypted value
 * untouched and $conf->setValues() decrypts every constant, so encrypting first is safe
 * on every version.
 *
 * @param	DoliDB	$db		Database handler
 * @param	string	$name	Constant name
 * @param	string	$value	Clear value ('' deletes the constant)
 * @param	int		$entity	Entity
 * @return	int				1 if OK, <0 if KO
 */
function mpagoSetSecretConst($db, $name, $value, $entity)
{
	/** @var Conf $conf */
	global $conf;

	require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
	require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';

	if ($value === '') {
		$result = dolibarr_del_const($db, $name, $entity);
		unset($conf->global->$name);
		return $result;
	}

	$result = dolibarr_set_const($db, $name, dolEncrypt($value), 'chaine', 0, '', $entity);
	if ($result > 0) {
		// dolibarr_set_const() left the encrypted form in memory
		mpagoSetConfInMemory($name, $value);
	}
	return $result;
}

/**
 * Id of the payment mode used for the payments recorded by the module.
 *
 * @param	DoliDB	$db		Database handler
 * @return	int				Id in the payment modes dictionary, 0 if none can be found
 */
function mpagoGetPaymentModeId($db)
{
	$id = getDolGlobalInt('MPAGO_PAYMENT_MODE');
	if ($id > 0) {
		return $id;
	}
	return max(0, mpagoInt(dol_getIdFromCode($db, 'CB', 'c_paiement', 'code', 'id', 1)));
}

/**
 * List what is missing before a payment can be offered and recorded.
 *
 * @param	DoliDB		$db		Database handler
 * @return	string[]			Translation keys of the problems found, empty when the setup is complete
 */
function mpagoGetConfigErrors($db)
{
	/** @var Conf $conf */
	global $conf;

	$errors = array();

	if (getDolGlobalString('MPAGO_ACCESS_TOKEN') === '') {
		$errors[] = 'MpagoErrorNoAccessToken';
	}
	if ($conf->currency !== 'ARS') {
		$errors[] = 'MpagoErrorCurrencyNotARS';
	}

	$accountid = getDolGlobalInt('MPAGO_BANK_ACCOUNT');
	if ($accountid <= 0) {
		$errors[] = 'MpagoErrorNoBankAccount';
	} else {
		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
		$account = new Account($db);
		if ($account->fetch($accountid) <= 0 || (int) $account->status != Account::STATUS_OPEN) {
			$errors[] = 'MpagoErrorBankAccountNotUsable';
		} elseif (!empty($account->currency_code) && $account->currency_code !== 'ARS') {
			$errors[] = 'MpagoErrorBankAccountNotARS';
		}
	}

	$userid = getDolGlobalInt('MPAGO_USER_ID');
	if ($userid <= 0) {
		$errors[] = 'MpagoErrorNoUser';
	} else {
		require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
		$actinguser = new User($db);
		if ($actinguser->fetch($userid) <= 0 || (int) $actinguser->status != User::STATUS_ENABLED) {
			$errors[] = 'MpagoErrorUserNotUsable';
		}
	}

	if (mpagoGetPaymentModeId($db) <= 0) {
		$errors[] = 'MpagoErrorNoPaymentMode';
	}

	return $errors;
}

/**
 * Public URL of one of the module's public pages.
 *
 * @param	string					$page		'return' or 'webhook'
 * @param	array<string,string>	$params		Query parameters
 * @return	string								Absolute URL
 */
function mpagoGetPublicUrl($page, $params = array())
{
	/** @var Conf $conf */
	global $conf;

	$url = dol_buildpath('/mpago/public/'.$page.'.php', 3);
	$params['entity'] = (string) $conf->entity;

	return $url.'?'.http_build_query($params);
}
