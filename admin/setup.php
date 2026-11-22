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
 * \file    admin/setup.php
 * \ingroup mpago
 * \brief   Setup page of the Mpago module.
 */

require __DIR__.'/../lib/mpagoloader.inc.php';
/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once __DIR__.'/../lib/mpago.lib.php';
require_once __DIR__.'/../class/mpagoclient.class.php';
require_once __DIR__.'/../class/mpagoorder.class.php';

$langs->loadLangs(array('admin', 'banks', 'bills', 'mpago@mpago'));

if (!$user->admin) {
	accessforbidden();
}
if (!isModEnabled('mpago')) {
	accessforbidden('Module not enabled');
}

$action = mpagoString(GETPOST('action', 'aZ09'));
$self = dol_buildpath('/mpago/admin/setup.php', 1);


/*
 * Actions
 */

if ($action == 'update') {
	$error = 0;

	// Secrets are never printed back in the form: an empty field keeps the stored value.
	foreach (array('MPAGO_ACCESS_TOKEN', 'MPAGO_WEBHOOK_SECRET') as $name) {
		$value = trim(mpagoString(GETPOST($name, 'alphanohtml')));
		if ($value !== '' && mpagoSetSecretConst($db, $name, $value, (int) $conf->entity) <= 0) {
			$error++;
		}
		if ($value === '' && GETPOSTINT($name.'_delete') && mpagoSetSecretConst($db, $name, '', (int) $conf->entity) < 0) {
			$error++;
		}
	}

	$maxinstallments = GETPOSTINT('MPAGO_MAX_INSTALLMENTS');
	if ($maxinstallments < 0 || $maxinstallments > 36) {
		setEventMessages($langs->trans('MpagoErrorBadInstallments'), null, 'errors');
		$error++;
		$maxinstallments = 0;
	}
	$hours = GETPOSTINT('MPAGO_EXPIRATION_HOURS');
	if ($hours <= 0 || $hours > 720) {
		$hours = 24;
	}

	$values = array(
		'MPAGO_BANK_ACCOUNT' => (GETPOSTINT('MPAGO_BANK_ACCOUNT') > 0 ? (string) GETPOSTINT('MPAGO_BANK_ACCOUNT') : ''),
		'MPAGO_PAYMENT_MODE' => (GETPOSTINT('MPAGO_PAYMENT_MODE') > 0 ? (string) GETPOSTINT('MPAGO_PAYMENT_MODE') : ''),
		'MPAGO_USER_ID' => (GETPOSTINT('MPAGO_USER_ID') > 0 ? (string) GETPOSTINT('MPAGO_USER_ID') : ''),
		'MPAGO_EXPIRATION_HOURS' => (string) $hours,
		'MPAGO_MAX_INSTALLMENTS' => ($maxinstallments > 0 ? (string) $maxinstallments : ''),
		'MPAGO_STATEMENT_DESCRIPTOR' => trim(mpagoString(GETPOST('MPAGO_STATEMENT_DESCRIPTOR', 'alphanohtml'))),
	);
	foreach ($values as $name => $value) {
		if ($value === '') {
			$result = dolibarr_del_const($db, $name, (int) $conf->entity);
		} else {
			$result = dolibarr_set_const($db, $name, $value, 'chaine', 0, '', (int) $conf->entity);
		}
		if ($result < 0) {
			$error++;
		}
	}

	if ($error) {
		setEventMessages($langs->trans('Error'), null, 'errors');
	} else {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
		header('Location: '.$self);
		exit;
	}
}

if ($action == 'testconnection') {
	$client = new MpagoClient();
	$result = $client->checkCredentials();
	if ($result > 0) {
		$lastorder = new MpagoOrder($db);
		$sql = "SELECT MAX(rowid) as rowid FROM ".MAIN_DB_PREFIX."mpago_order WHERE entity = ".((int) $conf->entity)." AND mp_order_id IS NOT NULL";
		$lines = mpagoFetchAll($db, $sql);
		$lastid = (isset($lines[0]) ? mpagoInt($lines[0]['rowid']) : 0);

		$message = $langs->trans('MpagoTestConnectionOk');
		if (strpos(getDolGlobalString('MPAGO_ACCESS_TOKEN'), 'TEST-') === 0) {
			$message .= ' '.$langs->trans('MpagoTestConnectionTestToken');
		} elseif ($lastid > 0 && $lastorder->fetch($lastid) > 0 && $lastorder->live_mode !== null) {
			$message .= ' '.$langs->trans($lastorder->live_mode ? 'MpagoTestConnectionLastOrderLive' : 'MpagoTestConnectionLastOrderTest');
		} else {
			$message .= ' '.$langs->trans('MpagoTestConnectionModeUnknown');
		}
		setEventMessages($message, null, 'mesgs');
	} elseif ($result == 0) {
		setEventMessages($langs->trans('MpagoTestConnectionRefused'), null, 'errors');
	} else {
		setEventMessages($langs->trans('MpagoTestConnectionError', $client->error), null, 'errors');
	}
}


/*
 * View
 */

$form = new Form($db);

llxHeader('', $langs->trans('MpagoSetup'), '', '', 0, 0, '', '', '', 'mod-mpago page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('MpagoSetup'), $linkback, 'title_setup');

print '<span class="opacitymedium">'.$langs->trans('MpagoSetupDesc').'</span><br><br>';

$configerrors = mpagoGetConfigErrors($db);
if (getDolGlobalString('MPAGO_WEBHOOK_SECRET') === '') {
	$configerrors[] = 'MpagoErrorNoWebhookSecret';
}
if (!empty($configerrors)) {
	$messages = array();
	foreach ($configerrors as $key) {
		$messages[] = $langs->trans($key);
	}
	print '<div class="warning" id="mpago_config_errors">'.$langs->trans('MpagoSetupIncomplete').'<br>'.implode('<br>', $messages).'</div>';
}
if (getDolGlobalString('MPAGO_API_BASE_URL') !== '') {
	print '<div class="warning">'.$langs->trans('MpagoApiBaseUrlOverridden', dol_escape_htmltag(getDolGlobalString('MPAGO_API_BASE_URL'))).'</div>';
}

print '<form method="POST" action="'.$self.'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td class="titlefieldmiddle">'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';

foreach (array('MPAGO_ACCESS_TOKEN' => 'MpagoAccessToken', 'MPAGO_WEBHOOK_SECRET' => 'MpagoWebhookSecret') as $name => $label) {
	$isset = (getDolGlobalString($name) !== '');
	print '<tr class="oddeven"><td><span class="fieldrequired">'.$langs->trans($label).'</span><br><span class="opacitymedium small">'.$langs->trans($label.'Help').'</span></td><td>';
	print '<input type="password" class="flat minwidth400" name="'.$name.'" value="" autocomplete="new-password" placeholder="'.dol_escape_htmltag($langs->trans($isset ? 'MpagoSecretSaved' : 'MpagoSecretNotSet')).'">';
	if ($isset) {
		print ' <input type="checkbox" id="'.$name.'_delete" name="'.$name.'_delete" value="1"> <label for="'.$name.'_delete">'.$langs->trans('Delete').'</label>';
	}
	print '</td></tr>';
}

print '<tr class="oddeven"><td><span class="fieldrequired">'.$langs->trans('MpagoBankAccount').'</span><br><span class="opacitymedium small">'.$langs->trans('MpagoBankAccountHelp').'</span></td><td>';
print $form->select_comptes(getDolGlobalInt('MPAGO_BANK_ACCOUNT'), 'MPAGO_BANK_ACCOUNT', 0, '', 1, '', 0, '', 1);
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('MpagoPaymentMode').'<br><span class="opacitymedium small">'.$langs->trans('MpagoPaymentModeHelp').'</span></td><td>';
$form->select_types_paiements((string) mpagoGetPaymentModeId($db), 'MPAGO_PAYMENT_MODE', '', 0, 1, 1);
print '</td></tr>';

print '<tr class="oddeven"><td><span class="fieldrequired">'.$langs->trans('MpagoUser').'</span><br><span class="opacitymedium small">'.$langs->trans('MpagoUserHelp').'</span></td><td>';
$selectusers = $form->select_dolusers(getDolGlobalInt('MPAGO_USER_ID'), 'MPAGO_USER_ID', 1);
print (is_string($selectusers) ? $selectusers : '');
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('MpagoExpirationHours').'<br><span class="opacitymedium small">'.$langs->trans('MpagoExpirationHoursHelp').'</span></td><td>';
print '<input type="number" class="flat width75" min="1" max="720" name="MPAGO_EXPIRATION_HOURS" value="'.(getDolGlobalInt('MPAGO_EXPIRATION_HOURS') > 0 ? getDolGlobalInt('MPAGO_EXPIRATION_HOURS') : 24).'">';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('MpagoMaxInstallments').'<br><span class="opacitymedium small">'.$langs->trans('MpagoMaxInstallmentsHelp').'</span></td><td>';
print '<input type="number" class="flat width75" min="1" max="36" name="MPAGO_MAX_INSTALLMENTS" value="'.(getDolGlobalInt('MPAGO_MAX_INSTALLMENTS') > 0 ? getDolGlobalInt('MPAGO_MAX_INSTALLMENTS') : '').'">';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('MpagoStatementDescriptor').'<br><span class="opacitymedium small">'.$langs->trans('MpagoStatementDescriptorHelp').'</span></td><td>';
print '<input type="text" class="flat minwidth200" maxlength="22" name="MPAGO_STATEMENT_DESCRIPTOR" value="'.dol_escape_htmltag(getDolGlobalString('MPAGO_STATEMENT_DESCRIPTOR')).'">';
print '</td></tr>';

print '</table>';
print '</div>';

print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div>';
print '</form>';

print '<br>';
print '<form method="POST" action="'.$self.'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="testconnection">';
print '<input type="submit" class="button small" id="mpago_testconnection" value="'.dol_escape_htmltag($langs->trans('MpagoTestConnection')).'">';
print ' <span class="opacitymedium">'.$langs->trans('MpagoTestConnectionHelp').'</span>';
print '</form>';

print '<br>';
print load_fiche_titre($langs->trans('MpagoUrls'), '', '');
print '<span class="opacitymedium">'.$langs->trans('MpagoUrlsDesc').'</span><br><br>';
print '<table class="noborder centpercent">';
print '<tr class="oddeven"><td class="titlefieldmiddle">'.$langs->trans('MpagoWebhookUrl').'</td><td>';
print '<input type="text" class="flat quatrevingtpercent" id="mpago_webhook_url" readonly value="'.dol_escape_htmltag(mpagoGetPublicUrl('webhook')).'" onclick="this.select()">';
print '</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('MpagoReturnUrl').'</td><td>';
print '<input type="text" class="flat quatrevingtpercent" id="mpago_return_url" readonly value="'.dol_escape_htmltag(mpagoGetPublicUrl('return')).'" onclick="this.select()">';
print '</td></tr>';
print '</table>';

// Payments that Mercado Pago reports and that are not, or not cleanly, recorded in Dolibarr
$sql = "SELECT rowid, ref_source, source, mp_order_id, amount, status, status_detail, fk_paiement, date_last_check, last_error";
$sql .= " FROM ".MAIN_DB_PREFIX."mpago_order";
$sql .= " WHERE entity = ".((int) $conf->entity)." AND last_error IS NOT NULL";
$sql .= " ORDER BY rowid DESC";
$sql .= $db->plimit(20);
$lines = mpagoFetchAll($db, $sql);
if (!empty($lines)) {
	print '<br>';
	print load_fiche_titre($langs->trans('MpagoProblems'), '', '');
	print '<table class="noborder centpercent" id="mpago_problems">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Ref').'</td><td>'.$langs->trans('MpagoOrderId').'</td><td class="right">'.$langs->trans('Amount').'</td><td>'.$langs->trans('Status').'</td><td>'.$langs->trans('MpagoLastCheck').'</td><td>'.$langs->trans('Error').'</td></tr>';
	foreach ($lines as $line) {
		print '<tr class="oddeven">';
		print '<td>'.dol_escape_htmltag(mpagoString($line['ref_source'])).'</td>';
		print '<td>'.dol_escape_htmltag(mpagoString($line['mp_order_id'])).'</td>';
		print '<td class="right">'.price(mpagoFloat($line['amount'])).'</td>';
		print '<td>'.dol_escape_htmltag(trim(mpagoString($line['status']).' '.mpagoString($line['status_detail']))).(mpagoInt($line['fk_paiement']) > 0 ? ' ('.$langs->trans('MpagoRecorded').')' : '').'</td>';
		print '<td>'.dol_print_date($db->jdate(mpagoString($line['date_last_check'])), 'dayhour').'</td>';
		print '<td>'.dol_escape_htmltag(mpagoString($line['last_error'])).'</td>';
		print '</tr>';
	}
	print '</table>';
}

llxFooter();
$db->close();
