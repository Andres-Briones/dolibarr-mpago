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
 * \file    public/webhook.php
 * \ingroup mpago
 * \brief   Endpoint that receives the notifications (webhooks) of Mercado Pago.
 *          It checks the signature, stores the notification and answers at once;
 *          the scheduled job of the module does the rest.
 */

if (!defined('NOLOGIN')) {
	define('NOLOGIN', '1');
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}
if (!defined('NOSESSION')) {
	define('NOSESSION', '1');
}
if (!defined('NOIPCHECK')) {
	define('NOIPCHECK', '1');
}
if (!defined('NOBROWSERNOTIF')) {
	define('NOBROWSERNOTIF', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

// Do not use GETPOST here, function is not defined and the entity must be known before including main.inc.php
$entity = (!empty($_GET['entity']) && is_numeric($_GET['entity']) ? (int) $_GET['entity'] : 1);
define('DOLENTITY', $entity);

require __DIR__.'/../lib/mpagoloader.inc.php';
/**
 * @var DoliDB $db
 */
require_once __DIR__.'/../class/mpagowebhook.class.php';


/*
 * Actions
 */

$code = 503;
$message = 'Module not enabled';

if (isModEnabled('mpago')) {
	$signature = (isset($_SERVER['HTTP_X_SIGNATURE']) && is_string($_SERVER['HTTP_X_SIGNATURE']) ? $_SERVER['HTTP_X_SIGNATURE'] : '');
	$requestid = (isset($_SERVER['HTTP_X_REQUEST_ID']) && is_string($_SERVER['HTTP_X_REQUEST_ID']) ? $_SERVER['HTTP_X_REQUEST_ID'] : '');

	// Mercado Pago sends "data.id" in the query string, which PHP exposes as "data_id"
	$webhook = new MpagoWebhook($db);
	$code = $webhook->handle(mpagoString(GETPOST('data_id', 'aZ09')), mpagoString(GETPOST('type', 'aZ09')), $signature, $requestid, (string) file_get_contents('php://input'));
	$message = $webhook->message;
}


/*
 * View
 */

http_response_code($code);
header('Content-Type: text/plain; charset=UTF-8');
print $message;

$db->close();
