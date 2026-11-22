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
 * 	\defgroup   mpago     Module Mpago
 *  \brief      Mpago module descriptor.
 *
 *  \file       core/modules/modMpago.class.php
 *  \ingroup    mpago
 *  \brief      Description and activation file for module Mpago
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';


/**
 *  Description and activation class for module Mpago
 */
class modMpago extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->numero = 510101;
		$this->rights_class = 'mpago';
		$this->family = 'financial';
		$this->name = 'Mpago';
		$this->description = 'MpagoDescription';
		$this->descriptionlong = 'MpagoDescription';

		$this->editor_name = 'Briones Andres';
		$this->editor_url = '';

		$this->version = '0.1.0';
		// Must match the name core derives from the class name in admin/modules.php
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'fa-credit-card';

		$this->module_parts = array(
			'hooks' => array('newpayment'),
		);
		$this->dirs = array();
		$this->config_page_url = array('setup.php@mpago');
		$this->depends = array('modFacture', 'modBanque');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('mpago@mpago');
		$this->phpmin = array(8, 1);
		$this->need_dolibarr_version = array(22, 0);

		$this->const = array();
		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array(
			0 => array(
				'label' => 'MpagoCronProcessEvents',
				'jobtype' => 'method',
				'class' => '/mpago/class/mpagocron.class.php',
				'objectname' => 'MpagoCron',
				'method' => 'processEvents',
				'parameters' => '',
				'comment' => 'MpagoCronProcessEventsDesc',
				'frequency' => 1,
				'unitfrequency' => 60,
				'status' => 1,
				'test' => 'isModEnabled("mpago")',
				'priority' => 50,
			),
			1 => array(
				'label' => 'MpagoCronPollOpenOrders',
				'jobtype' => 'method',
				'class' => '/mpago/class/mpagocron.class.php',
				'objectname' => 'MpagoCron',
				'method' => 'pollOpenOrders',
				'parameters' => '',
				'comment' => 'MpagoCronPollOpenOrdersDesc',
				'frequency' => 15,
				'unitfrequency' => 60,
				'status' => 1,
				'test' => 'isModEnabled("mpago")',
				'priority' => 51,
			),
		);
		$this->rights = array();
		$this->menu = array();
	}

	/**
	 * Function called when module is enabled: creates the tables, then the constants and scheduled jobs.
	 *
	 * @param	string		$options	Options when enabling module ('', 'noboxes')
	 * @return	int<-1,1>				1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/mpago/sql/');
		if ($result < 0) {
			return -1;
		}

		$result = $this->_init(array(), $options);

		return ($result > 0 ? 1 : ($result < 0 ? -1 : 0));
	}
}
