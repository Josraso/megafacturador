<?php
/**
 * This file is part of megafacturador
 * Copyright (C) 2014-2025 Carlos Garcia Gomez <neorazorx@gmail.com>
 */

namespace FacturaScripts\Plugins\Megafacturador\Extension\Controller;

use Closure;

/**
 * Extension for ListAlbaranCliente
 */
class ListAlbaranCliente
{
    public function createViews(): Closure
    {
        return function() {
            // Future: Add email functionality for delivery notes
        };
    }
}
