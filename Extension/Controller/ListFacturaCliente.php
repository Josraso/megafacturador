<?php
namespace FacturaScripts\Plugins\Megafacturador\Extension\Controller;

use Closure;

class ListFacturaCliente
{
    public function createViews(): Closure
    {
        return function() {
            // Force load JavaScript
            \FacturaScripts\Dinamic\Lib\AssetManager::addJs(FS_ROUTE . '/Plugins/Megafacturador/Assets/JS/ListFacturaCliente.js');
        };
    }
}
