-- Copyright (C) 2026 Briones Andres
--
-- This program is free software; you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program. If not, see <https://www.gnu.org/licenses/>.

-- One row per Mercado Pago order created by this module.
CREATE TABLE llx_mpago_order(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	source varchar(16) NOT NULL,
	fk_source integer NOT NULL,
	ref_source varchar(128),
	external_reference varchar(64) NOT NULL,
	return_token varchar(64) NOT NULL,
	attempt integer DEFAULT 0 NOT NULL,
	mp_order_id varchar(64),
	checkout_url varchar(2000),
	amount double(24,8) DEFAULT 0 NOT NULL,
	currency varchar(3),
	status varchar(32),
	status_detail varchar(64),
	live_mode smallint,
	fk_paiement integer,
	fk_facture integer,
	date_creation datetime NOT NULL,
	date_expiration datetime,
	date_last_check datetime,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	last_error text
) ENGINE=innodb;
