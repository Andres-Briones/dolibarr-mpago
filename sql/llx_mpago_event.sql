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

-- Inbox of Mercado Pago webhook notifications, processed later by the scheduled job.
CREATE TABLE llx_mpago_event(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	mp_order_id varchar(64) NOT NULL,
	action varchar(64),
	request_id varchar(128),
	live_mode smallint,
	payload text,
	date_creation datetime NOT NULL,
	date_processed datetime,
	status smallint DEFAULT 0 NOT NULL,
	attempts integer DEFAULT 0 NOT NULL,
	last_error text
) ENGINE=innodb;
