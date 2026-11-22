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
 * \file    class/actions_mpago.class.php
 * \ingroup mpago
 * \brief   Hooks of the Mpago module on the public payment page.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';
require_once __DIR__.'/../lib/mpago.lib.php';
require_once __DIR__.'/mpagoorder.class.php';
require_once __DIR__.'/mpagocheckout.class.php';

/**
 * Hooks of the Mpago module on the public payment page (context newpayment).
 */
class ActionsMpago extends CommonHookActions
{
	const METHOD = 'mpago';

	/**
	 * @var DoliDB Database handler.
	 */
	public $db;

	/**
	 * @var string Error code (or message)
	 */
	public $error = '';

	/**
	 * @var string[] Errors
	 */
	public $errors = array();

	/**
	 * @var mixed[] Hook results. Propagated to $hookmanager->resArray for later reuse
	 */
	public $results = array();

	/**
	 * @var ?string String displayed by executeHook() immediately after return
	 */
	public $resprints;

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
	 * Add Mercado Pago to the payment methods of the public payment page.
	 *
	 * @param	array<string,mixed>	$parameters		Hook parameters
	 * @param	mixed				$object			Not used
	 * @param	?string				$action			Current action
	 * @param	HookManager			$hookmanager	Hook manager
	 * @return	int									0 to add the method to the ones of core
	 */
	public function getValidPayment($parameters, &$object, &$action, $hookmanager)
	{
		$this->results = array();
		$this->resprints = '';

		$wanted = mpagoString($parameters['paymentmethod'] ?? '');
		if ($wanted !== '' && $wanted !== self::METHOD) {
			return 0;
		}
		if (!in_array($this->getSource(), array(MpagoOrder::SOURCE_INVOICE, MpagoOrder::SOURCE_ORDER), true)) {
			return 0;
		}
		if (!empty(mpagoGetConfigErrors($this->db))) {
			return 0;
		}

		if (!empty($parameters['mode'])) {
			$this->results = array('validpaymentmethod' => array(self::METHOD => array('label' => 'Mercado Pago', 'status' => 'valid')));
		} else {
			$this->results = array('validpaymentmethod' => array(self::METHOD => 'valid'));
		}

		return 0;
	}

	/**
	 * Print the Mercado Pago button.
	 *
	 * @param	array<string,mixed>	$parameters		Hook parameters
	 * @param	mixed				$object			Invoice or order shown by the page
	 * @param	?string				$action			Current action
	 * @param	HookManager			$hookmanager	Hook manager
	 * @return	int									0
	 */
	public function doAddButton($parameters, &$object, &$action, $hookmanager)
	{
		/** @var Translate $langs */
		global $langs;

		$this->results = array();
		$this->resprints = '';

		$wanted = mpagoString($parameters['paymentmethod'] ?? '');
		if ($wanted !== '' && $wanted !== self::METHOD) {
			return 0;
		}
		if (!($object instanceof Facture) && !($object instanceof Commande)) {
			return 0;
		}
		if (!empty(mpagoGetConfigErrors($this->db))) {
			return 0;
		}
		$checkout = new MpagoCheckout($this->db);
		if ($checkout->getAmountDue($object) <= 0) {
			return 0;
		}

		$langs->load('mpago@mpago');

		$out = '';
		$hasemail = (is_object($object->thirdparty) && trim((string) $object->thirdparty->email) !== '');
		if (!$hasemail) {
			$out .= '<div class="marginbottomonly" id="div_mpago_email">';
			$out .= '<label for="mpago_email">'.$langs->trans('MpagoYourEmail').'</label> ';
			$out .= '<input type="email" class="flat minwidth200" id="mpago_email" name="mpago_email" maxlength="255" value="'.dol_escape_htmltag(mpagoString(GETPOST('mpago_email', 'alphanohtml'))).'">';
			$out .= '</div>';
		}
		$out .= '<div class="button buttonpayment" id="div_dopayment_mpago">';
		$out .= '<span class="fa fa-credit-card"></span> ';
		$out .= '<input class="" type="submit" id="dopayment_mpago" name="dopayment_mpago" value="'.dol_escape_htmltag($langs->trans('MpagoDoPayment')).'">';
		$out .= '<br>';
		$out .= '<span class="buttonpaymentsmall">'.$langs->trans('MpagoDoPaymentSub').'</span>';
		$out .= '</div>';
		$out .= '<script>
				$( document ).ready(function() {
					$("#div_dopayment_mpago").click(function(){
						$("#dopayment_mpago").click();
					});
					$("#dopayment_mpago").click(function(e){
						$("#div_dopayment_mpago").css( \'cursor\', \'wait\' );
						e.stopPropagation();
						return true;
					});
				});
			  </script>
		';

		$this->resprints = $out;

		return 0;
	}

	/**
	 * Start the payment when the buyer clicked the Mercado Pago button: create the order and redirect to its checkout.
	 * The page calls this hook twice; only the call made before any output can redirect.
	 *
	 * @param	array<string,mixed>	$parameters		Hook parameters
	 * @param	mixed				$object			Not used (the page has not loaded it yet)
	 * @param	?string				$action			Current action, reset on error so that the page shows the buttons again
	 * @param	HookManager			$hookmanager	Hook manager
	 * @return	int									0 if nothing was done, <0 on error; does not return after a redirect
	 */
	public function doPayment($parameters, &$object, &$action, $hookmanager)
	{
		/** @var Translate $langs */
		global $langs;

		$this->results = array();
		$this->resprints = '';

		if (!array_key_exists('validpaymentmethod', $parameters) || headers_sent()) {
			return 0;
		}
		if ($action !== 'dopayment' || mpagoString($parameters['paymentmethod'] ?? '') !== self::METHOD) {
			return 0;
		}

		$langs->load('mpago@mpago');

		// The page has already refused the request if its securekey did not match source and ref.
		$checkout = new MpagoCheckout($this->db);
		$url = $checkout->start($this->getSource(), mpagoString(GETPOST('ref', 'alpha')), mpagoString(GETPOST('mpago_email', 'alphanohtml')));
		if ($url === '') {
			$this->errors[] = $langs->trans($checkout->error);
			$action = '';
			return -1;
		}

		header('Location: '.$url);
		exit;
	}

	/**
	 * Tell the buyer when the object was paid, or is being paid, through Mercado Pago.
	 *
	 * @param	array<string,mixed>	$parameters		Hook parameters
	 * @param	mixed				$object			Invoice or order shown by the page
	 * @param	?string				$action			Current action
	 * @param	HookManager			$hookmanager	Hook manager
	 * @return	int									1 when a message is printed, else 0
	 */
	public function doCheckStatus($parameters, &$object, &$action, $hookmanager)
	{
		/** @var Translate $langs */
		global $langs;

		$this->results = array();
		$this->resprints = '';

		if ($object instanceof Facture) {
			$source = MpagoOrder::SOURCE_INVOICE;
		} elseif ($object instanceof Commande) {
			$source = MpagoOrder::SOURCE_ORDER;
		} else {
			return 0;
		}

		$row = new MpagoOrder($this->db);
		if ($row->fetchLastForSource($source, (int) $object->id) <= 0) {
			return 0;
		}

		$langs->load('mpago@mpago');
		if ($row->isRecorded()) {
			$message = $langs->trans('MpagoPaidThroughMercadoPago');
		} elseif ($row->isPaidAtMercadoPago() || $row->status === MpagoOrder::STATUS_PROCESSING) {
			$message = $langs->trans('MpagoPaymentBeingProcessed');
		} else {
			return 0;
		}

		$this->resprints = '<br><div class="opacitymedium" id="mpago_status">'.$message.'</div>';

		return 1;
	}

	/**
	 * Source of the payment page ('invoice', 'order', ...), read the way the page reads it.
	 *
	 * @return	string
	 */
	private function getSource()
	{
		$source = mpagoString(GETPOST('s', 'aZ09'));

		return ($source !== '' ? $source : mpagoString(GETPOST('source', 'aZ09')));
	}
}
