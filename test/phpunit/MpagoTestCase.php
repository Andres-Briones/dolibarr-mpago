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
 * \file    test/phpunit/MpagoTestCase.php
 * \ingroup mpago
 * \brief   Common ground of the PHPUnit tests of the Mpago module: Dolibarr environment,
 *          fake Mercado Pago API, tables, fixtures.
 */

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var User $user
 */
global $conf, $user, $db;

$doliSrc = getenv('DOLI_SRC');
if (empty($doliSrc) || !is_file($doliSrc.'/htdocs/master.inc.php')) {
	fwrite(STDERR, "DOLI_SRC must point to the root of a configured Dolibarr source tree (containing htdocs/master.inc.php).\n");
	exit(1);
}

require_once $doliSrc.'/htdocs/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once dirname(__DIR__, 2).'/core/modules/modMpago.class.php';
require_once dirname(__DIR__, 2).'/lib/mpago.lib.php';
require_once dirname(__DIR__, 2).'/class/mpagosignature.class.php';
require_once dirname(__DIR__, 2).'/class/mpagoclient.class.php';
require_once dirname(__DIR__, 2).'/class/mpagoorder.class.php';
require_once dirname(__DIR__, 2).'/class/mpagorecorder.class.php';
require_once dirname(__DIR__, 2).'/class/mpagocheckout.class.php';
require_once dirname(__DIR__, 2).'/class/mpagowebhook.class.php';
require_once dirname(__DIR__, 2).'/class/mpagocron.class.php';
require_once dirname(__DIR__, 2).'/class/actions_mpago.class.php';

if (empty($user->id)) {
	$user->fetch(1);
	$user->loadRights();
}


/**
 * Class MpagoTestCase
 *
 * Each test class runs inside one database transaction that is rolled back at the end,
 * and starts from empty module tables (the rows of the instance come back with the rollback).
 *
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
abstract class MpagoTestCase extends PHPUnit\Framework\TestCase
{
	const FAKE_TOKEN = 'APP_USR-1234567890-fake-token-for-tests';
	const WEBHOOK_SECRET = 'fake-webhook-secret-for-tests';

	/**
	 * Setup values forced during a test, null to remove the value.
	 */
	const CONF_NAMES = array(
		'MPAGO_ACCESS_TOKEN', 'MPAGO_API_BASE_URL', 'MPAGO_WEBHOOK_SECRET', 'MPAGO_WEBHOOK_TOLERANCE', 'MPAGO_BANK_ACCOUNT', 'MPAGO_PAYMENT_MODE',
		'MPAGO_USER_ID', 'MPAGO_EXPIRATION_HOURS', 'MPAGO_MAX_INSTALLMENTS', 'MPAGO_STATEMENT_DESCRIPTOR', 'MAIN_DISABLE_PDF_AUTOUPDATE',
	);

	/**
	 * @var bool
	 */
	private static $prepared = false;

	/**
	 * @var resource|null Process of the fake API
	 */
	private static $fakeProcess = null;

	/**
	 * @var string
	 */
	private static $fakeUrl = '';

	/**
	 * @var string
	 */
	private static $fakeStateFile = '';

	/**
	 * @var string[] Tables created by the tests, dropped when they end
	 */
	private static $createdTables = array();

	/**
	 * @var int Bank account created for the current test class
	 */
	protected static $bankAccountId = 0;

	/**
	 * @var array<string,string> Setup values in memory before the test
	 */
	private $savedConf = array();

	/**
	 * Global test setup
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void
	{
		/**
		 * @var DoliDB $db
		 * @var User $user
		 */
		global $db, $user;

		self::prepareOnce();

		$db->begin();
		$db->query("DELETE FROM ".MAIN_DB_PREFIX."mpago_event");
		$db->query("DELETE FROM ".MAIN_DB_PREFIX."mpago_order");

		$account = new Account($db);
		$account->ref = 'MPT'.substr(bin2hex(random_bytes(4)), 0, 8);
		$account->label = 'Mpago test account';
		$account->type = Account::TYPE_CURRENT;
		$account->courant = Account::TYPE_CURRENT;
		$account->currency_code = 'ARS';
		$account->country_id = 23;
		$account->date_solde = dol_now();
		$account->balance = 0;
		self::$bankAccountId = (int) $account->create($user);
		if (self::$bankAccountId <= 0) {
			throw new RuntimeException('Bank account not created: '.$account->error);
		}
	}

	/**
	 * Global test teardown
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void
	{
		/** @var DoliDB $db */
		global $db;

		$db->rollback();
	}

	/**
	 * Point the module at the fake API with a complete setup, in memory only.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		$this->savedConf = array();
		foreach (self::CONF_NAMES as $name) {
			$this->savedConf[$name] = getDolGlobalString($name);
		}

		mpagoSetConfInMemory('MPAGO_ACCESS_TOKEN', self::FAKE_TOKEN);
		mpagoSetConfInMemory('MPAGO_API_BASE_URL', self::$fakeUrl);
		mpagoSetConfInMemory('MPAGO_WEBHOOK_SECRET', self::WEBHOOK_SECRET);
		mpagoSetConfInMemory('MPAGO_WEBHOOK_TOLERANCE', '');
		mpagoSetConfInMemory('MPAGO_BANK_ACCOUNT', (string) self::$bankAccountId);
		mpagoSetConfInMemory('MPAGO_PAYMENT_MODE', '');
		mpagoSetConfInMemory('MPAGO_USER_ID', '1');
		mpagoSetConfInMemory('MPAGO_EXPIRATION_HOURS', '');
		mpagoSetConfInMemory('MPAGO_MAX_INSTALLMENTS', '');
		mpagoSetConfInMemory('MPAGO_STATEMENT_DESCRIPTOR', '');
		// Recording a payment would otherwise write a PDF in the documents directory of the instance
		mpagoSetConfInMemory('MAIN_DISABLE_PDF_AUTOUPDATE', '1');

		self::fake('POST', '/__test/config', array('token' => self::FAKE_TOKEN, 'fail_next' => 0));
	}

	/**
	 * Put the setup back.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		foreach ($this->savedConf as $name => $value) {
			mpagoSetConfInMemory($name, $value);
		}
	}

	/**
	 * Create the tables if the instance does not have them and start the fake API, once per PHPUnit run.
	 * Must run before any transaction: creating a table commits.
	 *
	 * @return void
	 */
	protected static function prepareOnce()
	{
		/** @var DoliDB $db */
		global $db;

		if (self::$prepared) {
			return;
		}
		self::$prepared = true;

		register_shutdown_function(array(self::class, 'shutdown'));

		foreach (array('mpago_order', 'mpago_event') as $table) {
			$existing = mpagoFetchAll($db, "SHOW TABLES LIKE '".$db->escape(MAIN_DB_PREFIX.$table)."'");
			if ($existing !== null && count($existing) == 0) {
				self::$createdTables[] = $table;
				foreach (array('.sql', '.key.sql') as $suffix) {
					if (run_sql(dirname(__DIR__, 2).'/sql/llx_'.$table.$suffix, 1, 0, 0) <= 0) {
						throw new RuntimeException('Table '.$table.' not created');
					}
				}
			}
		}

		self::startFake();
	}

	/**
	 * Stop the fake API and drop the tables the tests created.
	 *
	 * @return void
	 */
	public static function shutdown()
	{
		/** @var DoliDB $db */
		global $db;

		if (is_resource(self::$fakeProcess)) {
			proc_terminate(self::$fakeProcess);
			proc_close(self::$fakeProcess);
			self::$fakeProcess = null;
		}
		if (self::$fakeStateFile !== '' && is_file(self::$fakeStateFile)) {
			unlink(self::$fakeStateFile);
		}
		if (!empty(self::$createdTables) && dolibarr_get_const($db, 'MAIN_MODULE_MPAGO', 1) === '') {
			foreach (self::$createdTables as $table) {
				$db->query("DROP TABLE IF EXISTS ".MAIN_DB_PREFIX.$table);
			}
		}
		self::$createdTables = array();
	}

	/**
	 * Start the fake API on a free local port and wait until it answers.
	 *
	 * @return void
	 */
	private static function startFake()
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		if ($socket === false) {
			throw new RuntimeException('No free port for the fake API');
		}
		$address = (string) stream_socket_get_name($socket, false);
		fclose($socket);
		$port = (int) substr($address, (int) strrpos($address, ':') + 1);

		self::$fakeStateFile = (string) tempnam(sys_get_temp_dir(), 'mpagofake');
		self::$fakeUrl = 'http://127.0.0.1:'.$port;

		$environment = getenv();
		$environment['MPAGO_FAKE_STATE'] = self::$fakeStateFile;
		$pipes = array();
		$process = proc_open(
			array(PHP_BINARY, '-S', '127.0.0.1:'.$port, dirname(__DIR__).'/fake/router.php'),
			array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')),
			$pipes,
			null,
			$environment
		);
		if (!is_resource($process)) {
			throw new RuntimeException('Fake API not started');
		}
		self::$fakeProcess = $process;

		for ($try = 0; $try < 100; $try++) {
			$connection = @fsockopen('127.0.0.1', $port);
			if ($connection !== false) {
				fclose($connection);
				return;
			}
			usleep(50000);
		}
		throw new RuntimeException('Fake API does not answer on port '.$port);
	}

	/**
	 * Base URL of the fake API.
	 *
	 * @return string
	 */
	protected static function fakeUrl()
	{
		return self::$fakeUrl;
	}

	/**
	 * Call the fake API directly (test endpoints).
	 *
	 * @param	string				$method		HTTP method
	 * @param	string				$path		Path
	 * @param	array<string,mixed>	$body		JSON body
	 * @return	array<mixed>					Decoded answer
	 */
	protected static function fake($method, $path, array $body = array())
	{
		$context = stream_context_create(array('http' => array(
			'method' => $method,
			'header' => "Content-Type: application/json\r\n",
			'content' => ($method === 'GET' ? '' : (string) json_encode($body)),
			'ignore_errors' => true,
			'timeout' => 10,
		)));
		$answer = file_get_contents(self::$fakeUrl.$path, false, $context);
		$data = json_decode((string) $answer, true);

		return (is_array($data) ? $data : array());
	}

	/**
	 * Requests the fake API received on /v1/, oldest first.
	 *
	 * @return	array<int,array<string,mixed>>
	 */
	protected static function fakeRequests()
	{
		$state = self::fake('GET', '/__test/state');
		$requests = array();
		if (isset($state['requests']) && is_array($state['requests'])) {
			foreach ($state['requests'] as $request) {
				if (is_array($request)) {
					$clean = array();
					foreach ($request as $key => $value) {
						$clean[(string) $key] = $value;
					}
					$requests[] = $clean;
				}
			}
		}

		return $requests;
	}

	/**
	 * Mark an order as paid at the fake API.
	 *
	 * @param	string		$mpOrderId	Order id
	 * @param	string|null	$amount		Amount paid, null for the amount of the order
	 * @return	void
	 */
	protected static function payAtFake($mpOrderId, $amount = null)
	{
		$body = array('pay' => true);
		if ($amount !== null) {
			$body['amount'] = $amount;
		}
		self::fake('POST', '/__test/orders/'.$mpOrderId, $body);
	}

	/**
	 * Create a customer.
	 *
	 * @param	string	$email	E-mail of the customer
	 * @return	Societe
	 */
	protected function createThirdparty($email = 'buyer@example.com')
	{
		/**
		 * @var DoliDB $db
		 * @var User $user
		 */
		global $db, $user;

		$thirdparty = new Societe($db);
		$thirdparty->name = 'Mpago test '.bin2hex(random_bytes(4));
		$thirdparty->client = 1;
		$thirdparty->code_client = 'auto';
		$thirdparty->email = $email;
		$thirdparty->country_id = 23;
		$this->assertGreaterThan(0, $thirdparty->create($user), 'Third party not created: '.$thirdparty->error);

		return $thirdparty;
	}

	/**
	 * Create an invoice with one line.
	 *
	 * @param	Societe	$thirdparty		Customer
	 * @param	float	$amount			Amount of the line, without tax
	 * @param	bool	$validate		Validate the invoice
	 * @return	Facture
	 */
	protected function createInvoice(Societe $thirdparty, $amount = 100.0, $validate = true)
	{
		/**
		 * @var DoliDB $db
		 * @var User $user
		 */
		global $db, $user;

		$invoice = new Facture($db);
		$invoice->socid = (int) $thirdparty->id;
		$invoice->type = Facture::TYPE_STANDARD;
		$invoice->date = dol_now();
		$invoice->cond_reglement_id = 1;
		$this->assertGreaterThan(0, $invoice->create($user), 'Invoice not created: '.$invoice->error);
		$this->assertGreaterThan(0, $invoice->addline('Mpago test line', $amount, 1, 0), 'Invoice line not added: '.$invoice->error);
		if ($validate) {
			$this->assertGreaterThan(0, $invoice->validate($user), 'Invoice not validated: '.$invoice->error);
		}
		$this->assertGreaterThan(0, $invoice->fetch((int) $invoice->id));

		return $invoice;
	}

	/**
	 * Create a validated customer order with one line.
	 *
	 * @param	Societe	$thirdparty		Customer
	 * @param	float	$amount			Amount of the line, without tax
	 * @return	Commande
	 */
	protected function createOrder(Societe $thirdparty, $amount = 100.0)
	{
		/**
		 * @var DoliDB $db
		 * @var User $user
		 */
		global $db, $user;

		$order = new Commande($db);
		$order->socid = (int) $thirdparty->id;
		$order->date = dol_now();
		$order->cond_reglement_id = 1;
		$this->assertGreaterThan(0, $order->create($user), 'Order not created: '.$order->error);
		$this->assertGreaterThan(0, $order->addline('Mpago test line', $amount, 1, 0), 'Order line not added: '.$order->error);
		$this->assertGreaterThan(0, $order->valid($user), 'Order not validated: '.$order->error);
		$this->assertGreaterThan(0, $order->fetch((int) $order->id));

		return $order;
	}

	/**
	 * Start a payment the way the payment page does and return the row.
	 *
	 * @param	string	$source		'invoice' or 'order'
	 * @param	string	$ref		Ref of the object
	 * @return	MpagoOrder
	 */
	protected function startPayment($source, $ref)
	{
		/** @var DoliDB $db */
		global $db;

		$checkout = new MpagoCheckout($db);
		$url = $checkout->start($source, $ref);
		$this->assertNotSame('', $url, 'Payment not started: '.$checkout->error);
		$this->assertInstanceOf(MpagoOrder::class, $checkout->row);

		return $checkout->row;
	}

	/**
	 * Count lines.
	 *
	 * @param	string	$fromwhere	"table WHERE condition", without the prefix of the table
	 * @return	int
	 */
	protected function countRows($fromwhere)
	{
		/** @var DoliDB $db */
		global $db;

		$lines = mpagoFetchAll($db, "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX.$fromwhere);
		$this->assertNotNull($lines);

		return mpagoInt($lines[0]['nb']);
	}

	/**
	 * Number of payments recorded on an invoice.
	 *
	 * @param	int		$invoiceId	Id of the invoice
	 * @return	int
	 */
	protected function countPayments($invoiceId)
	{
		return $this->countRows("paiement_facture WHERE fk_facture = ".((int) $invoiceId));
	}

	/**
	 * Number of lines on the bank account of the test class.
	 *
	 * @return	int
	 */
	protected function countBankLines()
	{
		return $this->countRows("bank WHERE fk_account = ".((int) self::$bankAccountId));
	}
}
