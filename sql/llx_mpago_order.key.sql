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

ALTER TABLE llx_mpago_order ADD UNIQUE INDEX uk_mpago_order_external_reference (external_reference);
ALTER TABLE llx_mpago_order ADD UNIQUE INDEX uk_mpago_order_return_token (return_token);
ALTER TABLE llx_mpago_order ADD UNIQUE INDEX uk_mpago_order_mp_order_id (mp_order_id);
ALTER TABLE llx_mpago_order ADD INDEX idx_mpago_order_source (entity, source, fk_source);
ALTER TABLE llx_mpago_order ADD INDEX idx_mpago_order_status (status);
