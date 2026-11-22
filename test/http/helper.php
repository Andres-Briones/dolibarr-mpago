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
 * \file    test/http/helper.php
 * \ingroup mpago
 * \brief   Command line helper of test/http/run.sh: prepares and inspects the development instance.
 *          Not a PHPUnit test and never used by the module itself.
 *
 * Usage: DOLI_SRC=/path/to/dolibarr php helper.php <command> [arguments]
 *   enable                      enable the module; prints the id of the bank account to use
 *   const NAME [VALUE]          set a constant (delete it without value)
 *   secret NAME                 prints how a secret is stored: "encrypted", "clear" or "missing"
 *   create invoice|order AMOUNT prints "<id> <ref> <payment url>"
 *   status invoice|order ID     prints the state of the object as key=value pairs
 *   events ORDERID              prints "<number of notifications stored> <number pending>"
 *   cron                        runs both scheduled jobs; prints their results
 */

if (PHP_SAPI !== 'cli') {
	exit(1);
}

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var User $user
 */
global $conf, $user, $db;

$doliSrc = getenv('DOLI_SRC');
if (empty($doliSrc) || !is_file($doliSrc.'/htdocs/master.inc.php')) {
	fwrite(STDERR, "DOLI_SRC must point to the root of a configured Dolibarr source tree\n");
	exit(1);
}

define('NOSESSION', 1);
require_once $doliSrc.'/htdocs/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/payments.lib.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
require_once dirname(__DIR__, 2).'/lib/mpago.lib.php';
require_once dirname(__DIR__, 2).'/class/mpagoorder.class.php';
require_once dirname(__DIR__, 2).'/class/mpagocron.class.php';

$user->fetch(1);
$user->loadRights();

/**
 * Stop with a message.
 *
 * @param	string	$message	Message
 * @return	never
 */
function mpagoHelperFail($message)
{
	fwrite(STDERR, $message."\n");
	exit(1);
}

/**
 * Count lines.
 *
 * @param	DoliDB	$db			Database handler
 * @param	string	$fromwhere	"table WHERE condition", without the prefix of the table
 * @return	int
 */
function mpagoHelperCount($db, $fromwhere)
{
	$lines = mpagoFetchAll($db, "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX.$fromwhere);

	return (isset($lines[0]) ? mpagoInt($lines[0]['nb']) : -1);
}

$arguments = $argv;
$command = mpagoString($arguments[1] ?? '');
$first = mpagoString($arguments[2] ?? '');
$second = mpagoString($arguments[3] ?? '');

if ($command === 'enable') {
	$result = activateModule('modMpago');
	if (!empty($result['errors'])) {
		mpagoHelperFail('Module not enabled: '.implode(', ', $result['errors']));
	}

	$account = new Account($db);
	if ($account->fetch(0, 'MPAGO-TEST') <= 0) {
		$account->ref = 'MPAGO-TEST';
		$account->label = 'Mercado Pago (test/http)';
		$account->type = Account::TYPE_CURRENT;
		$account->courant = Account::TYPE_CURRENT;
		$account->currency_code = 'ARS';
		$account->country_id = 23;
		$account->date_solde = dol_now();
		$account->balance = 0;
		if ($account->create($user) <= 0) {
			mpagoHelperFail('Bank account not created: '.$account->error);
		}
	}
	print $account->id."\n";
} elseif ($command === 'const') {
	if ($second === '') {
		dolibarr_del_const($db, $first, (int) $conf->entity);
	} elseif (dolibarr_set_const($db, $first, $second, 'chaine', 0, '', (int) $conf->entity) <= 0) {
		mpagoHelperFail('Constant not saved');
	}
} elseif ($command === 'secret') {
	$lines = mpagoFetchAll($db, "SELECT value FROM ".MAIN_DB_PREFIX."const WHERE name = '".$db->escape($first)."' AND entity = ".((int) $conf->entity));
	if (!isset($lines[0])) {
		print "missing\n";
	} else {
		print(strpos(mpagoString($lines[0]['value']), 'dolcrypt:') === 0 ? "encrypted\n" : "clear\n");
	}
} elseif ($command === 'create') {
	$thirdparty = new Societe($db);
	$thirdparty->name = 'Mpago HTTP check '.dol_print_date(dol_now(), 'dayhourlog').' '.bin2hex(random_bytes(2));
	$thirdparty->client = 1;
	$thirdparty->code_client = 'auto';
	$thirdparty->email = 'buyer@example.com';
	$thirdparty->country_id = 23;
	if ($thirdparty->create($user) <= 0) {
		mpagoHelperFail('Third party not created: '.$thirdparty->error);
	}

	if ($first === 'invoice') {
		$object = new Facture($db);
		$object->type = Facture::TYPE_STANDARD;
	} elseif ($first === 'order') {
		$object = new Commande($db);
	} else {
		mpagoHelperFail('Unknown source');
	}
	$object->socid = (int) $thirdparty->id;
	$object->date = dol_now();
	$object->cond_reglement_id = 1;
	if ($object->create($user) <= 0 || $object->addline('Mpago HTTP check', mpagoFloat($second), 1, 21) <= 0) {
		mpagoHelperFail('Object not created: '.$object->error);
	}
	$result = ($object instanceof Facture ? $object->validate($user) : $object->valid($user));
	if ($result <= 0) {
		mpagoHelperFail('Object not validated: '.$object->error);
	}
	$object->fetch((int) $object->id);
	print $object->id.' '.$object->ref.' '.getOnlinePaymentUrl(0, $first, (string) $object->ref)."\n";
} elseif ($command === 'status') {
	$id = (int) $second;
	if ($first === 'order') {
		$order = new Commande($db);
		if ($order->fetch($id) <= 0) {
			mpagoHelperFail('Order not found');
		}
		print 'billed='.((int) $order->billed)."\n";
		print 'invoices='.mpagoHelperCount($db, "element_element WHERE sourcetype = 'commande' AND fk_source = ".$id." AND targettype = 'facture'")."\n";
		$lines = mpagoFetchAll($db, "SELECT fk_target FROM ".MAIN_DB_PREFIX."element_element WHERE sourcetype = 'commande' AND fk_source = ".$id." AND targettype = 'facture'");
		$invoiceid = (isset($lines[0]) ? mpagoInt($lines[0]['fk_target']) : 0);
	} else {
		$invoiceid = $id;
	}
	$invoice = new Facture($db);
	if ($invoiceid > 0 && $invoice->fetch($invoiceid) > 0) {
		print 'invoice='.$invoice->ref."\n";
		print 'paye='.((int) $invoice->paye)."\n";
		print 'total='.mpagoFormatAmount($invoice->total_ttc)."\n";
		print 'remain='.mpagoFormatAmount($invoice->getRemainToPay())."\n";
		print 'payments='.mpagoHelperCount($db, "paiement_facture WHERE fk_facture = ".$invoiceid)."\n";
		print 'banklines='.mpagoHelperCount($db, "bank_url as bu INNER JOIN ".MAIN_DB_PREFIX."paiement_facture as pf ON pf.fk_paiement = bu.url_id WHERE bu.type = 'payment' AND pf.fk_facture = ".$invoiceid)."\n";
		$lines = mpagoFetchAll($db, "SELECT p.amount, p.ext_payment_id, p.ext_payment_site, p.num_paiement, b.fk_account FROM ".MAIN_DB_PREFIX."paiement as p INNER JOIN ".MAIN_DB_PREFIX."paiement_facture as pf ON pf.fk_paiement = p.rowid LEFT JOIN ".MAIN_DB_PREFIX."bank as b ON b.rowid = p.fk_bank WHERE pf.fk_facture = ".$invoiceid);
		foreach ((array) $lines as $line) {
			print 'payment_amount='.mpagoFormatAmount(mpagoFloat($line['amount']))."\n";
			print 'payment_ext_id='.mpagoString($line['ext_payment_id'])."\n";
			print 'payment_site='.mpagoString($line['ext_payment_site'])."\n";
			print 'payment_num='.mpagoString($line['num_paiement'])."\n";
			print 'payment_account='.mpagoString($line['fk_account'])."\n";
		}
	} else {
		print "invoice=\npayments=0\nbanklines=0\n";
	}
} elseif ($command === 'events') {
	print mpagoHelperCount($db, "mpago_event WHERE mp_order_id = '".$db->escape($first)."'").' '.mpagoHelperCount($db, "mpago_event WHERE status = 0 AND mp_order_id = '".$db->escape($first)."'")."\n";
} elseif ($command === 'cron') {
	$cron = new MpagoCron($db);
	$result = $cron->processEvents();
	print 'processEvents='.$result.' '.$cron->output.' '.$cron->error."\n";
	$result = $cron->pollOpenOrders();
	print 'pollOpenOrders='.$result.' '.$cron->output.' '.$cron->error."\n";
} else {
	mpagoHelperFail('Unknown command');
}

$db->close();
