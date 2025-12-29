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

namespace FacturaScripts\Plugins\Megafacturador\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\Accounting\AccountingAccounts;
use FacturaScripts\Dinamic\Lib\BusinessDocumentGenerator;
use FacturaScripts\Dinamic\Model\AlbaranCliente;
use FacturaScripts\Dinamic\Model\AlbaranProveedor;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\Empresa;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\FormaPago;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Dinamic\Model\Serie;

/**
 * Controller to mass invoice pending delivery notes (albaranes)
 *
 * @author Carlos Garcia Gomez <neorazorx@gmail.com>
 */
class Megafacturador extends Controller
{
    /**
     * @var Ejercicio
     */
    private $ejercicio;

    /**
     * @var Ejercicio[]
     */
    private $ejercicios;

    /**
     * @var FormaPago
     */
    private $forma_pago;

    /**
     * @var FormaPago[]
     */
    private $formas_pago;

    /**
     * @var int
     */
    public $numasientos;

    /**
     * @var array
     */
    public $opciones;

    /**
     * @var Serie
     */
    public $serie;

    /**
     * @var string
     */
    public $url_recarga;

    /**
     * Returns basic page attributes
     *
     * @return array
     */
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'MegaFacturador';
        $data['icon'] = 'fas fa-magic';
        return $data;
    }

    /**
     * Runs the controller's private logic
     *
     * @param mixed $response
     * @param mixed $user
     * @param mixed $permissions
     */
    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);

        $this->ejercicio = new Ejercicio();
        $this->ejercicios = [];
        $this->forma_pago = new FormaPago();
        $this->formas_pago = $this->forma_pago->all();
        $this->numasientos = 0;
        $this->serie = new Serie();
        $this->url_recarga = false;
        $this->loadConfig();

        if ($this->request->request->get('megafac_fecha')) {
            $this->modificarConfig();
        } elseif ($this->request->query->get('procesar') === 'TRUE') {
            $this->generarFacturas();
        } elseif ($this->request->query->get('genasientos')) {
            $this->generarAsientos();
        } elseif ($this->request->query->get('activar_contintegrada')) {
            $this->activarContabilidadIntegrada();
        }

        $this->numasientos = $this->numAsientosAGenerar();
    }

    /**
     * Load configuration from settings
     */
    private function loadConfig(): void
    {
        $this->opciones = [
            'megafac_agrupar' => Tools::settings('megafacturador', 'megafac_agrupar', false),
            'megafac_codserie' => Tools::settings('megafacturador', 'megafac_codserie', ''),
            'megafac_compras' => Tools::settings('megafacturador', 'megafac_compras', 1),
            'megafac_email' => Tools::settings('megafacturador', 'megafac_email', false),
            'megafac_fecha' => Tools::settings('megafacturador', 'megafac_fecha', 'albaran'),
            'megafac_hasta' => Tools::settings('megafacturador', 'megafac_hasta', date('Y-m-d')),
            'megafac_ventas' => Tools::settings('megafacturador', 'megafac_ventas', 1),
        ];

        // Fix date format for HTML5 input type="date" (Y-m-d format)
        $this->opciones['megafac_hasta'] = date('Y-m-d', strtotime($this->opciones['megafac_hasta']));
    }

    /**
     * Save configuration to settings
     */
    private function modificarConfig(): void
    {
        $this->opciones['megafac_agrupar'] = $this->request->request->get('megafac_agrupar') ? 1 : 0;
        $this->opciones['megafac_codserie'] = $this->request->request->get('megafac_codserie');
        $this->opciones['megafac_compras'] = $this->request->request->get('megafac_compras') ? 1 : 0;
        $this->opciones['megafac_email'] = $this->request->request->get('megafac_email') ? 1 : 0;
        $this->opciones['megafac_fecha'] = $this->request->request->get('megafac_fecha');
        $this->opciones['megafac_hasta'] = $this->request->request->get('megafac_hasta');
        $this->opciones['megafac_ventas'] = $this->request->request->get('megafac_ventas') ? 1 : 0;

        foreach ($this->opciones as $key => $value) {
            Tools::settingsSet('megafacturador', $key, $value);
        }

        if ($this->request->request->get('procesar') === 'TRUE') {
            $this->generarFacturas();
        }
    }

    /**
     * Enable integrated accounting
     */
    private function activarContabilidadIntegrada(): void
    {
        $this->empresa->contintegrada = true;

        if ($this->empresa->save()) {
            Tools::log()->notice('record-updated-correctly');
        } else {
            Tools::log()->error('record-save-error');
        }
    }

    /**
     * Get SQL conditions for filtering delivery notes
     *
     * @return DataBaseWhere[]
     */
    private function getSqlConditions(): array
    {
        $where = [
            new DataBaseWhere('editable', true),
            new DataBaseWhere('total', 0, '!=')
        ];

        if (!empty($this->opciones['megafac_codserie'])) {
            $where[] = new DataBaseWhere('codserie', $this->opciones['megafac_codserie']);
        }

        if (!empty($this->opciones['megafac_hasta'])) {
            $where[] = new DataBaseWhere('fecha', $this->opciones['megafac_hasta'], '<=');
        }

        return $where;
    }

    /**
     * Get pending delivery notes to invoice
     *
     * @param string $modelName
     * @param string $codcliente
     * @param string $codproveedor
     * @param string $codserie
     * @param string $coddivisa
     *
     * @return array
     */
    public function albaranesPendientes(string $modelName = 'AlbaranCliente', string $codcliente = '', string $codproveedor = '', string $codserie = '', string $coddivisa = ''): array
    {
        $where = $this->getSqlConditions();

        if ($codcliente) {
            $where[] = new DataBaseWhere('codcliente', $codcliente);
            $where[] = new DataBaseWhere('codserie', $codserie);
            $where[] = new DataBaseWhere('coddivisa', $coddivisa);
        } elseif ($codproveedor) {
            $where[] = new DataBaseWhere('codproveedor', $codproveedor);
            $where[] = new DataBaseWhere('codserie', $codserie);
            $where[] = new DataBaseWhere('coddivisa', $coddivisa);
        }

        $className = 'FacturaScripts\\Dinamic\\Model\\' . $modelName;
        $model = new $className();
        return $model->all($where, ['fecha' => 'ASC', 'hora' => 'ASC'], 0, 20);
    }

    /**
     * Get total pending delivery notes
     *
     * @param string $modelName
     *
     * @return int
     */
    public function totalPendientes(string $modelName = 'AlbaranCliente'): int
    {
        $where = $this->getSqlConditions();
        $className = 'FacturaScripts\\Dinamic\\Model\\' . $modelName;
        $model = new $className();
        return $model->count($where);
    }

    /**
     * Generate invoices from delivery notes
     */
    private function generarFacturas(): void
    {
        $recargar = false;
        // Use today's date in d-m-Y format for invoices
        $fecha = date('d-m-Y');
        if ($this->opciones['megafac_fecha'] === 'albaran') {
            $fecha = null;
        }

        if ($this->opciones['megafac_ventas']) {
            $total1 = 0;
            $generator = new BusinessDocumentGenerator();

            foreach ($this->albaranesPendientes('AlbaranCliente') as $alb) {
                // Group by customer or not?
                $albaranes = [];
                if ($this->opciones['megafac_agrupar']) {
                    $albaranes2 = $this->albaranesPendientes('AlbaranCliente', $alb->codcliente, '', $alb->codserie, $alb->coddivisa);
                    if ($albaranes2 && $albaranes2[0]->idalbaran === $alb->idalbaran) {
                        $albaranes = $albaranes2;
                    }
                } else {
                    $albaranes[] = $alb;
                }

                if (empty($albaranes)) {
                    // Already invoiced when grouping, skip
                } elseif ($this->facturarAlbaranCliente($generator, $albaranes, $fecha)) {
                    $total1++;
                    $recargar = true;
                } else {
                    break;
                }
            }

            Tools::log()->notice($total1 . ' delivery notes invoiced.');
        }

        if ($this->opciones['megafac_compras']) {
            $total2 = 0;
            $generator = new BusinessDocumentGenerator();

            foreach ($this->albaranesPendientes('AlbaranProveedor') as $alb) {
                // Group by supplier or not?
                $albaranes = [];
                if ($this->opciones['megafac_agrupar']) {
                    $albaranes2 = $this->albaranesPendientes('AlbaranProveedor', '', $alb->codproveedor, $alb->codserie, $alb->coddivisa);
                    if ($albaranes2 && $albaranes2[0]->idalbaran === $alb->idalbaran) {
                        $albaranes = $albaranes2;
                    }
                } else {
                    $albaranes[] = $alb;
                }

                if (empty($albaranes)) {
                    // Already invoiced when grouping, skip
                } elseif ($this->facturarAlbaranProveedor($generator, $albaranes, $fecha)) {
                    $total2++;
                    $recargar = true;
                } else {
                    break;
                }
            }

            Tools::log()->notice($total2 . ' supplier delivery notes invoiced.');
        }

        // Reload?
        $errors = Tools::log()->read('', ['critical', 'error']);
        if (!empty($errors)) {
            Tools::log()->error('Errors occurred. Process stopped.');
        } elseif ($recargar) {
            $this->url_recarga = $this->url() . '&procesar=TRUE';
            Tools::log()->notice('Reloading...');
        } else {
            Tools::log()->notice('Finished.');
            if ($this->opciones['megafac_email']) {
                $this->enviarFacturas();
            }
        }
    }

    /**
     * Generate invoice from customer delivery notes
     *
     * @param BusinessDocumentGenerator $generator
     * @param array $albaranes
     * @param string|null $fecha
     *
     * @return bool
     */
    private function facturarAlbaranCliente($generator, array $albaranes, ?string $fecha): bool
    {
        $nuevaFactura = new FacturaCliente();

        if ($fecha) {
            $nuevaFactura->setDate($fecha, $nuevaFactura->hora);
        }

        $docs = [];
        foreach ($albaranes as $alb) {
            $docs[] = $alb;
        }

        return $generator->generate($nuevaFactura, $docs);
    }

    /**
     * Generate invoice from supplier delivery notes
     *
     * @param BusinessDocumentGenerator $generator
     * @param array $albaranes
     * @param string|null $fecha
     *
     * @return bool
     */
    private function facturarAlbaranProveedor($generator, array $albaranes, ?string $fecha): bool
    {
        $nuevaFactura = new FacturaProveedor();

        if ($fecha) {
            $nuevaFactura->setDate($fecha, $nuevaFactura->hora);
        }

        $docs = [];
        foreach ($albaranes as $alb) {
            $docs[] = $alb;
        }

        return $generator->generate($nuevaFactura, $docs);
    }

    /**
     * Redirect to send invoices page
     */
    private function enviarFacturas(): void
    {
        if ($this->permissions->onlyOwnerData === false) {
            $this->redirect('SendMail?model=FacturaCliente');
        } else {
            Tools::log()->error('access-denied');
        }
    }

    /**
     * Generate accounting entries for invoices
     */
    private function generarAsientos(): void
    {
        $nuevos = 0;
        $accountingAccounts = new AccountingAccounts();

        $facturaCliente = new FacturaCliente();
        $where = [new DataBaseWhere('idasiento', null, 'IS')];
        $invoices = $facturaCliente->all($where, [], 0, 50);

        foreach ($invoices as $factura) {
            if (empty($factura->idasiento)) {
                if ($accountingAccounts->generate($factura)) {
                    $nuevos++;
                } else {
                    break;
                }
            }
        }
        Tools::log()->notice($nuevos . ' accounting entries generated for sales invoices.');

        $nuevos2 = 0;
        $facturaProveedor = new FacturaProveedor();
        $invoices2 = $facturaProveedor->all($where, [], 0, 50);

        foreach ($invoices2 as $factura) {
            if (empty($factura->idasiento)) {
                if ($accountingAccounts->generate($factura)) {
                    $nuevos2++;
                } else {
                    break;
                }
            }
        }
        Tools::log()->notice($nuevos2 . ' accounting entries generated for purchase invoices.');

        // Reload?
        $errors = Tools::log()->read('', ['critical', 'error']);
        if (!empty($errors)) {
            Tools::log()->error('Errors occurred. Process stopped.');
        } elseif ($this->numAsientosAGenerar() > 0) {
            $this->url_recarga = $this->url() . '&genasientos=TRUE';
            Tools::log()->notice('Reloading...');
        }
    }

    /**
     * Count invoices without accounting entry
     *
     * @return int
     */
    private function numAsientosAGenerar(): int
    {
        $num = 0;
        $where = [new DataBaseWhere('idasiento', null, 'IS')];

        $facturaCliente = new FacturaCliente();
        $num += $facturaCliente->count($where);

        $facturaProveedor = new FacturaProveedor();
        $num += $facturaProveedor->count($where);

        return $num;
    }
}
