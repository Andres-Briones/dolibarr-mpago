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
 * \file    public/return.php
 * \ingroup mpago
 * \brief   Page the buyer comes back to after the Mercado Pago checkout.
 *          The state of the payment is read at Mercado Pago, never taken from the URL.
 */

if (!defined('NOLOGIN')) {
	define('NOLOGIN', '1');
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}
if (!defined('NOIPCHECK')) {
	define('NOIPCHECK', '1');
}
if (!defined('NOBROWSERNOTIF')) {
	define('NOBROWSERNOTIF', '1');
}

// Do not use GETPOST here, function is not defined and the entity must be known before including main.inc.php
$entity = (!empty($_GET['entity']) && is_numeric($_GET['entity']) ? (int) $_GET['entity'] : 1);
define('DOLENTITY', $entity);

require __DIR__.'/../lib/mpagoloader.inc.php';
/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Societe $mysoc
 * @var Translate $langs
 */
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/payments.lib.php';
require_once __DIR__.'/../class/mpagoorder.class.php';
require_once __DIR__.'/../class/mpagorecorder.class.php';

$langs->loadLangs(array('main', 'other', 'dict', 'bills', 'companies', 'errors', 'mpago@mpago'));

if (!isModEnabled('mpago')) {
	httponly_accessforbidden('Module not enabled', 404);
}

$token = mpagoString(GETPOST('t', 'aZ09'));


/*
 * Actions
 */

$row = new MpagoOrder($db);
$found = ($row->fetchByReturnToken($token) > 0 && $row->entity == (int) $conf->entity);

$syncfailed = false;
if ($found && !$row->isRecorded() && $row->mp_order_id !== '') {
	$recorder = new MpagoRecorder($db);
	$syncfailed = ($recorder->sync($row) < 0);
}

$retry = false;
if (!$found) {
	$state = 'failed';
	$title = $langs->trans('MpagoReturnUnknown');
	$text = '';
} elseif ($row->isRecorded()) {
	$state = 'paid';
	$title = $langs->trans('MpagoReturnPaid');
	$text = $langs->trans('MpagoReturnPaidText', $row->ref_source);
} elseif ($row->isPaidAtMercadoPago()) {
	$state = 'pending';
	$title = $langs->trans('MpagoReturnReceived');
	$text = $langs->trans('MpagoReturnReceivedText', $row->ref_source);
} elseif (in_array($row->status, array(MpagoOrder::STATUS_PROCESSING, MpagoOrder::STATUS_ACTION_REQUIRED), true) || ($syncfailed && $row->status !== MpagoOrder::STATUS_CREATED)) {
	$state = 'pending';
	$title = $langs->trans('MpagoReturnPending');
	$text = $langs->trans('MpagoReturnPendingText', $row->ref_source);
} else {
	$state = 'failed';
	$title = $langs->trans('MpagoReturnFailed');
	$text = $langs->trans('MpagoReturnFailedText', $row->ref_source);
	$retry = true;
}


// Lets the module that created the document (a shop, for instance) send the buyer back to its own page.
$backurl = '';
$backlabel = '';
if ($found) {
	/** @var HookManager $hookmanager */
	$hookmanager->initHooks(array('mpagoreturn'));
	$parameters = array('source' => $row->source, 'fk_source' => $row->fk_source, 'ref_source' => $row->ref_source, 'result' => $state);
	$hookaction = '';
	// A copy: the hook manager takes the object by reference and core types it loosely.
	$hookobject = $row;
	$reshook = $hookmanager->executeHooks('getReturnLink', $parameters, $hookobject, $hookaction);
	if ($reshook >= 0 && !empty($hookmanager->resArray['url']) && is_string($hookmanager->resArray['url'])) {
		$backurl = $hookmanager->resArray['url'];
		$backlabel = (!empty($hookmanager->resArray['label']) && is_string($hookmanager->resArray['label']) ? $hookmanager->resArray['label'] : $langs->trans('MpagoReturnBack'));
	}
}


/*
 * View
 */

$head = '';
if (getDolGlobalString('ONLINE_PAYMENT_CSS_URL')) {
	$head = '<link rel="stylesheet" type="text/css" href="'.dol_escape_htmltag(getDolGlobalString('ONLINE_PAYMENT_CSS_URL')).'?lang='.$langs->defaultlang.'">'."\n";
}

$conf->dol_hide_topmenu = 1;
$conf->dol_hide_leftmenu = 1;

if (!$found) {
	http_response_code(404);
}

llxHeader($head, $langs->trans('PaymentForm'), '', '', 0, 0, '', '', '', 'onlinepaymentbody', '<div>');

print '<span id="dolpaymentspan"></span>'."\n";
print '<div id="dolpaymentdiv" class="center">'."\n";

// As on core's payment page: the company logo if there is one, else its name, never the Dolibarr logo.
$haslogo = (!empty($mysoc->logo_small) || !empty($mysoc->logo) || getDolGlobalString('ONLINE_PAYMENT_LOGO') !== '');
htmlPrintOnlineHeader($mysoc, $langs, ($haslogo ? 1 : 0), getDolGlobalString('ONLINE_PAYMENT_CREDITOR', (string) $mysoc->name), '', 'ONLINE_PAYMENT_LOGO');

print '<br><br>'."\n";
print '<div id="mpago_result" class="mpago_'.$state.' '.($state == 'paid' ? 'amountpaymentcomplete' : 'warning').' size12x wrapimp">'.dol_escape_htmltag($title).'</div>'."\n";
if ($text !== '') {
	print '<br><div id="mpago_result_text">'.dol_escape_htmltag($text).'</div>'."\n";
}
if ($retry) {
	$url = getOnlinePaymentUrl(0, $row->source, $row->ref_source);
	print '<br><div><a class="button" id="mpago_retry" href="'.dol_escape_htmltag($url).'">'.$langs->trans('MpagoReturnTryAgain').'</a></div>'."\n";
}
if ($backurl !== '') {
	print '<br><div><a class="button" id="mpago_back" href="'.dol_escape_htmltag($backurl).'">'.dol_escape_htmltag($backlabel).'</a></div>'."\n";
}
print '<br>'."\n";

print '</div>'."\n";

htmlPrintOnlineFooter($mysoc, $langs, 0, '');

llxFooter('', 'public');

$db->close();
