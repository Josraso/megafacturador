<?php
namespace FacturaScripts\Plugins\Megafacturador\Extension\Controller;

use Closure;

class ListFacturaProveedor
{
    public function createViews(): Closure
    {
        return function() {
            // Force load JavaScript
            \FacturaScripts\Dinamic\Lib\AssetManager::addJs(FS_ROUTE . '/Plugins/Megafacturador/Assets/JS/ListFacturaProveedor.js');
        };
    }
}
