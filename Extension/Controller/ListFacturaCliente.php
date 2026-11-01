<?php
/**
 * This file is part of megafacturador
 * Copyright (C) 2014-2025 Carlos Garcia Gomez <neorazorx@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\Megafacturador\Extension\Controller;

use Closure;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\AssetManager;
use FacturaScripts\Plugins\Megafacturador\Lib\MegafacturadorMailer;

/**
 * Adds the "send by email" button to the FacturaCliente list.
 */
class ListFacturaCliente
{
    public function createViews(): Closure
    {
        return function () {
            AssetManager::addJs(FS_ROUTE . '/Plugins/Megafacturador/Assets/JS/ListFacturaCliente.js');
        };
    }

    public function execAfterAction(): Closure
    {
        return function ($action) {
            // Read directly from $_POST: on this installation the request object does not see these fields
            if (($_POST['action'] ?? '') !== 'megafac-send-emails') {
                return;
            }

            if (false === MegafacturadorMailer::isSameOriginRequest()) {
                Tools::log()->warning('Petición no válida.');
                return;
            }

            if (isset($this->permissions) && empty($this->permissions->allowUpdate)) {
                Tools::log()->warning('No tienes permiso para enviar facturas por email.');
                return;
            }

            $codes = $_POST['codes'] ?? [];
            if (!is_array($codes)) {
                $codes = [$codes];
            }
            if (empty($codes)) {
                Tools::log()->warning('No se seleccionaron facturas.');
                return;
            }

            MegafacturadorMailer::sendMany('FacturaCliente', $codes);
        };
    }
}
