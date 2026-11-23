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
 * \file    test/phpunit/MpagoModuleTest.php
 * \ingroup mpago
 * \brief   Tests of the module descriptor, the translations and the storage of secrets.
 */

require_once __DIR__.'/MpagoTestCase.php';


/**
 * Class MpagoModuleTest
 *
 * Enabling a module creates tables, which commits: this class cannot run inside a transaction
 * and puts back by hand what it changes.
 */
class MpagoModuleTest extends MpagoTestCase
{
	/**
	 * Global test setup: no transaction
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void
	{
		self::prepareOnce();
	}

	/**
	 * Global test teardown: no transaction
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void
	{
	}

	/**
	 * @var array<int,array<string,string|null>> Payment modes with the module's code before the test
	 */
	private $paymentModesBefore = array();

	/**
	 * Remember the payment modes the instance has.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		$this->paymentModesBefore = $this->paymentModeRows();
		parent::setUp();
	}

	/**
	 * Check that the payment modes are back as they were: this class runs outside a transaction.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		parent::tearDown();
		$this->assertSame($this->paymentModesBefore, $this->paymentModeRows(), 'The payment modes dictionary must be left as it was found');
	}

	/**
	 * Tell whether the module is enabled in the database.
	 *
	 * @return	bool
	 */
	private function isEnabled()
	{
		return $this->countRows("const WHERE name = 'MAIN_MODULE_MPAGO' AND value = '1'") > 0;
	}

	/**
	 * The descriptor instantiates and exposes the expected identity.
	 *
	 * @return void
	 */
	public function testDescriptorIdentity(): void
	{
		/** @var DoliDB $db */
		global $db;

		$module = new modMpago($db);

		$this->assertSame(510101, $module->numero);
		$this->assertSame('mpago', $module->rights_class);
		$this->assertSame('Mpago', $module->name);
		$this->assertSame('MAIN_MODULE_MPAGO', $module->const_name);
		$this->assertSame(array('hooks' => array('newpayment', 'tiendacron')), $module->module_parts);
		$this->assertCount(2, $module->cronjobs);
		foreach ($module->cronjobs as $job) {
			$this->assertSame('MpagoCron', $job['objectname'] ?? '');
			$this->assertSame('/mpago/class/mpagocron.class.php', $job['class'] ?? '');
			$this->assertTrue(method_exists('MpagoCron', $job['method'] ?? ''));
		}
	}

	/**
	 * Enabling the module creates its tables, constant, hook, scheduled jobs and payment mode; disabling it
	 * removes the constant and hook and keeps the tables and the payment mode. The instance ends in the state it was found in.
	 *
	 * @return void
	 */
	public function testInitAndRemove(): void
	{
		/**
		 * @var Conf $conf
		 * @var DoliDB $db
		 */
		global $conf, $db;

		$entity = (int) $conf->entity;
		$wasenabled = $this->isEnabled();
		$hadjobs = ($this->countRows("cronjob WHERE module_name = 'mpago' AND entity = ".$entity) > 0);
		$paymentmodes = $this->paymentModeRows();
		$module = new modMpago($db);

		try {
			$this->assertSame(1, $module->init());

			$this->assertSame(1, $this->countRows("c_paiement WHERE code = 'MPAGO' AND entity = ".$entity));
			$rows = $this->paymentModeRows();
			$this->assertCount(1, $rows);
			$row = (array) reset($rows);
			$paymentmodeid = mpagoInt($row['id']);
			if (empty($paymentmodes)) {
				$this->recordCreatedPaymentMode($paymentmodeid);
				$this->assertSame(array('entity' => (string) $entity, 'code' => 'MPAGO', 'libelle' => 'Mercado Pago', 'type' => '0', 'active' => '1', 'accountancy_code' => null, 'module' => 'mpago', 'position' => '0'), array_slice($row, 1));
			} else {
				$this->assertSame($paymentmodes, $rows, 'The payment mode the entity had is left as it is');
			}

			$this->assertSame('1', dolibarr_get_const($db, 'MAIN_MODULE_MPAGO', $entity));
			$this->assertSame(1, $this->countRows("const WHERE name = 'MAIN_MODULE_MPAGO_HOOKS' AND entity = ".$entity));
			$this->assertSame(2, $this->countRows("cronjob WHERE module_name = 'mpago' AND classesname = '/mpago/class/mpagocron.class.php' AND objectname = 'MpagoCron' AND entity = ".$entity));
			$this->assertSame(1, $this->countRows("cronjob WHERE module_name = 'mpago' AND methodename = 'processEvents' AND frequency = 1 AND unitfrequency = '60' AND status = 1"));
			$this->assertSame(1, $this->countRows("cronjob WHERE module_name = 'mpago' AND methodename = 'pollOpenOrders' AND frequency = 15 AND unitfrequency = '60' AND status = 1"));

			$columns = mpagoFetchAll($db, "SHOW COLUMNS FROM ".MAIN_DB_PREFIX."mpago_order");
			$this->assertNotNull($columns);
			$names = array();
			foreach ($columns as $column) {
				$names[] = mpagoString($column['Field']);
			}
			foreach (array('rowid', 'entity', 'source', 'fk_source', 'ref_source', 'external_reference', 'return_token', 'mp_order_id', 'checkout_url', 'amount', 'currency', 'status', 'status_detail', 'live_mode', 'fk_paiement', 'fk_facture', 'date_creation', 'date_expiration', 'date_last_check', 'tms', 'last_error') as $name) {
				$this->assertContains($name, $names);
			}
			$indexes = mpagoFetchAll($db, "SHOW INDEX FROM ".MAIN_DB_PREFIX."mpago_order WHERE Non_unique = 0");
			$this->assertNotNull($indexes);
			$unique = array();
			foreach ($indexes as $index) {
				$unique[] = mpagoString($index['Column_name']);
			}
			foreach (array('external_reference', 'return_token', 'mp_order_id') as $name) {
				$this->assertContains($name, $unique);
			}
			$this->assertSame(1, $this->countRows("mpago_event WHERE 1 = 0") + 1, 'Table mpago_event must exist');

			// Enabling twice changes nothing
			$this->assertSame(1, $module->init());
			$this->assertSame(2, $this->countRows("cronjob WHERE module_name = 'mpago' AND entity = ".$entity));
			$this->assertSame($rows, $this->paymentModeRows());

			// Disabled even when it was enabled before the test, which enables it again
			$this->assertSame(1, $module->remove());
			$this->assertSame('', dolibarr_get_const($db, 'MAIN_MODULE_MPAGO', $entity));
			// Core keeps the jobs that carry a test on the module (DolibarrModules::delete_cronjobs): they do not run while it is disabled
			$this->assertSame(2, $this->countRows("cronjob WHERE module_name = 'mpago' AND test = 'isModEnabled(\"mpago\")' AND entity = ".$entity));
			$this->assertSame(0, $this->countRows("const WHERE name LIKE 'MAIN_MODULE_MPAGO%' AND entity = ".$entity));
			$this->assertSame(0, $this->countRows("mpago_order WHERE 1 = 0"), 'Disabling keeps the tables');
			// Payments may use it: disabling the module keeps the payment mode
			$this->assertSame($rows, $this->paymentModeRows());
		} finally {
			$this->restoreModule($module, $wasenabled, $hadjobs);
		}

		$this->assertSame($wasenabled, $this->isEnabled());
		$this->assertSame($hadjobs, $this->countRows("cronjob WHERE module_name = 'mpago' AND entity = ".$entity) > 0);
	}

	/**
	 * Enabling the module leaves the payment mode with its code alone when the entity has one,
	 * even disabled and relabelled by an admin.
	 *
	 * @return void
	 */
	public function testInitKeepsExistingPaymentMode(): void
	{
		/**
		 * @var Conf $conf
		 * @var DoliDB $db
		 */
		global $conf, $db;

		$entity = (int) $conf->entity;
		$wasenabled = $this->isEnabled();
		$hadjobs = ($this->countRows("cronjob WHERE module_name = 'mpago' AND entity = ".$entity) > 0);
		$module = new modMpago($db);

		try {
			$id = $this->givePaymentMode(0, 'MP (relabelled)');
			$this->assertNotFalse($db->query("UPDATE ".MAIN_DB_PREFIX."c_paiement SET type = 2, position = 7 WHERE id = ".$id));
			$before = $this->paymentModeRows();

			foreach (array(1, 2) as $run) {
				$this->assertSame(1, $module->init(), 'Activation '.$run);
			}

			$this->assertSame($before, $this->paymentModeRows());
			$this->assertSame('MP (relabelled)', $before[$id]['libelle']);
			$this->assertSame('0', $before[$id]['active']);
			$this->assertSame(mpagoInt(dol_getIdFromCode($db, 'CB', 'c_paiement', 'code', 'id', 1)), mpagoGetPaymentModeId($db), 'A disabled mode is not used');
		} finally {
			$this->restoreModule($module, $wasenabled, $hadjobs);
		}
	}

	/**
	 * Put the module back in the state it had: enabled again when the test disabled it, disabled when
	 * the test enabled it, and delete the scheduled jobs it left
	 * when the instance had none.
	 *
	 * @param	modMpago	$module		Descriptor
	 * @param	bool		$wasenabled	Module enabled before the test
	 * @param	bool		$hadjobs	Scheduled jobs of the module before the test
	 * @return	void
	 */
	private function restoreModule(modMpago $module, $wasenabled, $hadjobs)
	{
		/**
		 * @var Conf $conf
		 * @var DoliDB $db
		 */
		global $conf, $db;

		if ($wasenabled && !$this->isEnabled()) {
			$module->init();
		}
		if (!$wasenabled && $this->isEnabled()) {
			$module->remove();
		}
		if (!$wasenabled && !$hadjobs) {
			$db->query("DELETE FROM ".MAIN_DB_PREFIX."cronjob WHERE module_name = 'mpago' AND entity = ".((int) $conf->entity));
		}
	}

	/**
	 * Both language files define the same keys, each once, and every key the code uses.
	 *
	 * @return void
	 */
	public function testLangFiles(): void
	{
		$root = dirname(__DIR__, 2);

		$keys = array('en_US' => array(), 'es_AR' => array());
		foreach (array('en_US', 'es_AR') as $langcode) {
			$file = $root.'/langs/'.$langcode.'/mpago.lang';
			$this->assertFileExists($file);

			foreach ((array) file($file, FILE_IGNORE_NEW_LINES) as $line) {
				$line = (string) $line;
				if ($line === '' || $line[0] === '#') {
					continue;
				}
				$this->assertMatchesRegularExpression('/^[A-Za-z0-9_]+ = \S/', $line, $file);
				$key = (string) strstr($line, ' = ', true);
				$this->assertNotContains($key, $keys[$langcode], 'Duplicate key '.$key.' in '.$file);
				$keys[$langcode][] = $key;
			}
			$this->assertContains('ModuleMpagoName', $keys[$langcode]);
			$this->assertContains('ModuleMpagoDesc', $keys[$langcode]);
		}
		$this->assertSame($keys['en_US'], $keys['es_AR'], 'Both files must have the same keys in the same order');

		$used = array();
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			if (!($file instanceof SplFileInfo) || $file->getExtension() !== 'php' || strpos($file->getPathname(), '/test/') !== false) {
				continue;
			}
			$matches = array();
			preg_match_all('/\'(Mpago[A-Z][A-Za-z]+)\'/', (string) file_get_contents($file->getPathname()), $matches);
			foreach ($matches[1] as $key) {
				$used[$key] = $file->getPathname();
			}
			if ($file->getFilename() === 'setup.php') {
				// Keys built as $label.'Help' for the two secrets
				$matches = array();
				preg_match_all('/=> \'(Mpago[A-Z][A-Za-z]+)\'/', (string) file_get_contents($file->getPathname()), $matches);
				foreach ($matches[1] as $key) {
					$used[$key.'Help'] = $file->getPathname();
				}
			}
		}
		unset($used['MpagoCron'], $used['MpagoDescription']);
		$this->assertGreaterThan(40, count($used));
		foreach ($used as $key => $file) {
			$this->assertContains($key, $keys['en_US'], 'Key '.$key.' used in '.$file.' is not translated');
		}
		foreach ($keys['en_US'] as $key) {
			// PaymentType* keys are read by core with the code of the payment mode
			if (strpos($key, 'Module') !== 0 && strpos($key, 'PaymentType') !== 0) {
				$this->assertArrayHasKey($key, $used, 'Key '.$key.' is translated but not used');
			}
		}
	}

	/**
	 * Secrets are stored encrypted on every Dolibarr version (core only encrypts names ending in _TOKEN from 24)
	 * and read back in clear.
	 *
	 * @return void
	 */
	public function testSecretsAreStoredEncrypted(): void
	{
		/**
		 * @var Conf $conf
		 * @var DoliDB $db
		 */
		global $conf, $db;

		$entity = (int) $conf->entity;
		$name = 'MPAGO_TEST_'.strtoupper(bin2hex(random_bytes(4))).'_ACCESS_TOKEN';
		$secret = 'APP_USR-secret-'.bin2hex(random_bytes(8));

		try {
			$this->assertSame(1, mpagoSetSecretConst($db, $name, $secret, $entity));
			$this->assertSame($secret, getDolGlobalString($name));

			$lines = mpagoFetchAll($db, "SELECT value FROM ".MAIN_DB_PREFIX."const WHERE name = '".$db->escape($name)."' AND entity = ".$entity);
			$this->assertNotNull($lines);
			$this->assertCount(1, $lines);
			$stored = mpagoString($lines[0]['value']);
			$this->assertStringStartsWith('dolcrypt:', $stored);
			$this->assertStringNotContainsString($secret, $stored);

			$this->assertSame($secret, dolibarr_get_const($db, $name, $entity));
			$conf->setValues($db);
			$this->assertSame($secret, getDolGlobalString($name), 'The value loaded with the configuration must be in clear');

			$this->assertGreaterThan(0, mpagoSetSecretConst($db, $name, '', $entity));
			$this->assertSame('', getDolGlobalString($name));
			$this->assertSame(0, $this->countRows("const WHERE name = '".$db->escape($name)."'"));
		} finally {
			dolibarr_del_const($db, $name, $entity);
		}
	}
}
