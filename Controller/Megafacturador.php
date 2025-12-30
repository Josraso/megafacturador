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
use FacturaScripts\Core\Lib\Email\NewMail;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\Accounting\AccountingAccounts;
use FacturaScripts\Dinamic\Lib\BusinessDocumentGenerator;
use FacturaScripts\Dinamic\Lib\ExportManager;
use FacturaScripts\Dinamic\Model\AlbaranCliente;
use FacturaScripts\Dinamic\Model\AlbaranProveedor;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\Empresa;
use FacturaScripts\Dinamic\Model\EstadoDocumento;
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
     * @var string
     */
    public $url_enviar_emails;

    /**
     * @var int
     */
    public $facturas_generadas;

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
        $this->url_enviar_emails = false;
        $this->facturas_generadas = 0;
        $this->loadConfig();

        if ($this->request->request->get('megafac_fecha')) {
            $this->modificarConfig();
        } elseif ($this->request->query->get('enviar_emails') === 'TRUE') {
            $this->enviarEmailsFacturas();
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

        // CRITICAL: Save all settings to database
        Tools::settingsSave();

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
     * Get the date of the last invoice to maintain chronological order
     *
     * @param string $modelName
     * @return string|null
     */
    private function getUltimaFechaFactura(string $modelName): ?string
    {
        $className = 'FacturaScripts\\Dinamic\\Model\\' . $modelName;
        $model = new $className();

        // Get last invoice ordered by date DESC
        $facturas = $model->all([], ['fecha' => 'DESC'], 0, 1);

        if (!empty($facturas)) {
            return $facturas[0]->fecha;
        }

        return null;
    }

    /**
     * Generate invoices from delivery notes
     */
    private function generarFacturas(): void
    {
        $recargar = false;

        // Use a temporary file to store invoice IDs across page reloads
        $runId = $this->request->get('megafac_runid');
        if (empty($runId)) {
            // First run - create new ID and file
            $runId = 'mf_' . time() . '_' . rand(1000, 9999);
            $tmpFile = sys_get_temp_dir() . '/megafac_' . $runId . '.txt';
            file_put_contents($tmpFile, '');

            // Clean up old temp files (older than 1 hour)
            $tmpDir = sys_get_temp_dir();
            foreach (glob($tmpDir . '/megafac_mf_*.txt') as $oldFile) {
                if (filemtime($oldFile) < time() - 3600) {
                    @unlink($oldFile);
                }
            }
        } else {
            $tmpFile = sys_get_temp_dir() . '/megafac_' . $runId . '.txt';
        }

        // Determine invoice date based on user preference
        $fecha = null;
        if ($this->opciones['megafac_fecha'] === 'hoy') {
            // Use today's date in Y-m-d format (required by BusinessDocument)
            $fecha = date('Y-m-d');
        }
        // If 'albaran', $fecha stays null and will use delivery note's date

        // Get last invoice dates to avoid chronological issues
        $ultimaFechaCliente = $this->getUltimaFechaFactura('FacturaCliente');
        $ultimaFechaProveedor = $this->getUltimaFechaFactura('FacturaProveedor');

        if ($this->opciones['megafac_ventas']) {
            $total1 = 0;

            $pendientes = $this->albaranesPendientes('AlbaranCliente');

            foreach ($pendientes as $alb) {
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
                } else {
                    // Create NEW generator for each invoice to avoid reusing same instance
                    $generator = new BusinessDocumentGenerator();
                    if ($this->facturarAlbaranCliente($generator, $albaranes, $fecha, $ultimaFechaCliente, $tmpFile)) {
                        $total1++;
                        $recargar = true;
                    } else {
                        break;
                    }
                }
            }

            Tools::log()->notice($total1 . ' delivery notes invoiced.');
        }

        if ($this->opciones['megafac_compras']) {
            $total2 = 0;

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
                } else {
                    // Create NEW generator for each invoice to avoid reusing same instance
                    $generator = new BusinessDocumentGenerator();
                    if ($this->facturarAlbaranProveedor($generator, $albaranes, $fecha, $ultimaFechaProveedor, $tmpFile)) {
                        $total2++;
                        $recargar = true;
                    } else {
                        break;
                    }
                }
            }

            Tools::log()->notice($total2 . ' supplier delivery notes invoiced.');
        }

        // Reload?
        $errors = Tools::log()->read('', ['critical', 'error']);
        if (!empty($errors)) {
            Tools::log()->error('Errors occurred. Process stopped.');
        } elseif ($recargar) {
            $this->url_recarga = $this->url() . '?procesar=TRUE&megafac_runid=' . $runId;
            Tools::log()->notice('Reloading...');
        } else {
            Tools::log()->notice('Finished.');

            // Count generated invoices
            if (file_exists($tmpFile)) {
                $fileContent = file_get_contents($tmpFile);
                $facturasIds = array_filter(explode("\n", trim($fileContent)));
                $this->facturas_generadas = count($facturasIds);

                if ($this->opciones['megafac_email'] && $this->facturas_generadas > 0) {
                    // Show button to send emails
                    $this->url_enviar_emails = $this->url() . '?enviar_emails=TRUE&runid=' . $runId;
                    Tools::log()->notice('Click the button below to send ' . $this->facturas_generadas . ' emails.');
                } else {
                    // Clean up temp file if not sending emails
                    @unlink($tmpFile);
                }
            }
        }
    }

    /**
     * Generate invoice from customer delivery notes
     *
     * @param BusinessDocumentGenerator $generator
     * @param array $albaranes
     * @param string|null $fecha
     * @param string|null $ultimaFechaFactura
     * @param string $tmpFile
     *
     * @return bool
     */
    private function facturarAlbaranCliente($generator, array $albaranes, ?string $fecha, ?string $ultimaFechaFactura, string $tmpFile): bool
    {
        if (empty($albaranes)) {
            return false;
        }

        // Use first albaran as prototype (oldest in the batch)
        $prototype = $albaranes[0];

        // Collect all lines from all albaranes and track quantities
        $newLines = [];
        $quantities = [];

        foreach ($albaranes as $alb) {
            // Add separator line showing albaran info (when grouping multiple albaranes)
            if (count($albaranes) > 1) {
                // Create info line with albaran number and date
                $infoLine = $alb->getNewLine();
                $infoLine->cantidad = 0;
                $infoLine->pvpunitario = 0;
                $infoLine->descripcion = 'Albarán ' . $alb->codigo . ' (' . $alb->numero2 . '), ' . $alb->fecha;
                $newLines[] = $infoLine;
                $quantities[$infoLine->primaryColumnValue()] = 0;
            }

            // Add all product lines from this albaran
            foreach ($alb->getLines() as $line) {
                $newLines[] = $line;
                $quantities[$line->primaryColumnValue()] = $line->cantidad;
            }
        }

        // Prepare properties with date
        $properties = [];
        if ($fecha) {
            // Use custom date (today)
            $properties['fecha'] = $fecha;
        } else {
            // CRITICAL: Check if albaran is older than last invoice
            $fechaFactura = $prototype->fecha;

            // If albaran is delayed (older than last invoice)
            if ($ultimaFechaFactura && $prototype->fecha < $ultimaFechaFactura) {
                // Find first valid date from ALL pending albaranes
                $todosAlbaranes = $this->albaranesPendientes('AlbaranCliente');
                $fechaEncontrada = false;

                foreach ($todosAlbaranes as $alb) {
                    if ($alb->fecha >= $ultimaFechaFactura) {
                        $fechaFactura = $alb->fecha;
                        $fechaEncontrada = true;
                        break;
                    }
                }

                // If no valid date found in pending albaranes, use configured limit date
                if (!$fechaEncontrada) {
                    if (!empty($this->opciones['megafac_hasta']) && $this->opciones['megafac_hasta'] >= $ultimaFechaFactura) {
                        $fechaFactura = $this->opciones['megafac_hasta'];
                    } else {
                        // Use last invoice date as minimum to avoid chronological errors
                        $fechaFactura = $ultimaFechaFactura;
                        Tools::log()->warning('delayed-albaran-using-last-invoice-date', [
                            '%albaran%' => $prototype->codigo,
                            '%albaran-date%' => $prototype->fecha,
                            '%invoice-date%' => $fechaFactura
                        ]);
                    }
                }
            }

            $properties['fecha'] = $fechaFactura;
        }

        // Generate invoice from all lines
        $success = $generator->generate($prototype, 'FacturaCliente', $newLines, $quantities, $properties);

        if (!$success) {
            Tools::log()->error('failed-to-generate-invoice');
            return false;
        }

        // Store generated invoice ID in temp file for later email sending
        $facturas = $generator->getLastDocs();
        if (!empty($facturas)) {
            $factura = $facturas[0];
            $facturaId = $factura->primaryColumnValue();
            file_put_contents($tmpFile, $facturaId . "\n", FILE_APPEND);
        }

        // CRITICAL: Mark lines as served and check if albaran is fully invoiced
        foreach ($albaranes as $alb) {
            $allServed = true;

            // Get fresh lines from database
            $lines = $alb->getLines();
            foreach ($lines as $line) {
                // Update servido field
                if (isset($quantities[$line->primaryColumnValue()])) {
                    $line->servido += $quantities[$line->primaryColumnValue()];
                    if (!$line->save()) {
                        Tools::log()->error('failed-to-update-servido');
                        return false;
                    }
                }

                // Check if this line is fully served
                if ($line->servido < $line->cantidad) {
                    $allServed = false;
                }
            }

            // If all lines fully served, mark document as non-editable
            if ($allServed) {
                // Prevent auto-generation when changing status
                $alb->setDocumentGeneration(false);

                // Find non-editable status for this document type
                $estadoModel = new EstadoDocumento();
                $whereEstado = [
                    new DataBaseWhere('tipodoc', 'AlbaranCliente'),
                    new DataBaseWhere('editable', false)
                ];
                $estados = $estadoModel->all($whereEstado, [], 0, 1);

                if (!empty($estados)) {
                    $alb->idestado = $estados[0]->idestado;
                }

                $alb->editable = false;
                if (!$alb->save()) {
                    Tools::log()->error('failed-to-mark-as-invoiced');
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Generate invoice from supplier delivery notes
     *
     * @param BusinessDocumentGenerator $generator
     * @param array $albaranes
     * @param string|null $fecha
     * @param string|null $ultimaFechaFactura
     * @param string $tmpFile
     *
     * @return bool
     */
    private function facturarAlbaranProveedor($generator, array $albaranes, ?string $fecha, ?string $ultimaFechaFactura, string $tmpFile): bool
    {
        if (empty($albaranes)) {
            return false;
        }

        // Use first albaran as prototype (oldest in the batch)
        $prototype = $albaranes[0];

        // Collect all lines from all albaranes and track quantities
        $newLines = [];
        $quantities = [];

        foreach ($albaranes as $alb) {
            // Add separator line showing albaran info (when grouping multiple albaranes)
            if (count($albaranes) > 1) {
                // Create info line with albaran number and date
                $infoLine = $alb->getNewLine();
                $infoLine->cantidad = 0;
                $infoLine->pvpunitario = 0;
                $infoLine->descripcion = 'Albarán ' . $alb->codigo . ' (' . $alb->numero2 . '), ' . $alb->fecha;
                $newLines[] = $infoLine;
                $quantities[$infoLine->primaryColumnValue()] = 0;
            }

            // Add all product lines from this albaran
            foreach ($alb->getLines() as $line) {
                $newLines[] = $line;
                $quantities[$line->primaryColumnValue()] = $line->cantidad;
            }
        }

        // Prepare properties with date
        $properties = [];
        if ($fecha) {
            // Use custom date (today)
            $properties['fecha'] = $fecha;
        } else {
            // CRITICAL: Check if albaran is older than last invoice
            $fechaFactura = $prototype->fecha;

            // If albaran is delayed (older than last invoice)
            if ($ultimaFechaFactura && $prototype->fecha < $ultimaFechaFactura) {
                // Find first valid date from ALL pending albaranes
                $todosAlbaranes = $this->albaranesPendientes('AlbaranProveedor');
                $fechaEncontrada = false;

                foreach ($todosAlbaranes as $alb) {
                    if ($alb->fecha >= $ultimaFechaFactura) {
                        $fechaFactura = $alb->fecha;
                        $fechaEncontrada = true;
                        break;
                    }
                }

                // If no valid date found in pending albaranes, use configured limit date
                if (!$fechaEncontrada) {
                    if (!empty($this->opciones['megafac_hasta']) && $this->opciones['megafac_hasta'] >= $ultimaFechaFactura) {
                        $fechaFactura = $this->opciones['megafac_hasta'];
                    } else {
                        // Use last invoice date as minimum to avoid chronological errors
                        $fechaFactura = $ultimaFechaFactura;
                        Tools::log()->warning('delayed-albaran-using-last-invoice-date', [
                            '%albaran%' => $prototype->codigo,
                            '%albaran-date%' => $prototype->fecha,
                            '%invoice-date%' => $fechaFactura
                        ]);
                    }
                }
            }

            $properties['fecha'] = $fechaFactura;
        }

        // Generate invoice from all lines
        $success = $generator->generate($prototype, 'FacturaProveedor', $newLines, $quantities, $properties);

        if (!$success) {
            Tools::log()->error('failed-to-generate-invoice');
            return false;
        }

        // Store generated invoice ID in temp file (for potential future use)
        $facturas = $generator->getLastDocs();
        if (!empty($facturas)) {
            $factura = $facturas[0];
            $facturaId = $factura->primaryColumnValue();
            file_put_contents($tmpFile, $facturaId . "\n", FILE_APPEND);
        }

        // CRITICAL: Mark lines as served and check if albaran is fully invoiced
        foreach ($albaranes as $alb) {
            $allServed = true;

            // Get fresh lines from database
            $lines = $alb->getLines();
            foreach ($lines as $line) {
                // Update servido field
                if (isset($quantities[$line->primaryColumnValue()])) {
                    $line->servido += $quantities[$line->primaryColumnValue()];
                    if (!$line->save()) {
                        Tools::log()->error('failed-to-update-servido');
                        return false;
                    }
                }

                // Check if this line is fully served
                if ($line->servido < $line->cantidad) {
                    $allServed = false;
                }
            }

            // If all lines fully served, mark document as non-editable
            if ($allServed) {
                // Prevent auto-generation when changing status
                $alb->setDocumentGeneration(false);

                // Find non-editable status for this document type
                $estadoModel = new EstadoDocumento();
                $whereEstado = [
                    new DataBaseWhere('tipodoc', 'AlbaranProveedor'),
                    new DataBaseWhere('editable', false)
                ];
                $estados = $estadoModel->all($whereEstado, [], 0, 1);

                if (!empty($estados)) {
                    $alb->idestado = $estados[0]->idestado;
                }

                $alb->editable = false;
                if (!$alb->save()) {
                    Tools::log()->error('failed-to-mark-as-invoiced');
                    return false;
                }
            }
        }

        return true;
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
            $this->url_recarga = $this->url() . '?genasientos=TRUE';
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

    /**
     * Send emails for generated invoices
     */
    private function enviarEmailsFacturas(): void
    {
        $runId = $this->request->get('runid', '');
        $tmpFile = sys_get_temp_dir() . '/megafac_' . $runId . '.txt';

        if (!file_exists($tmpFile)) {
            Tools::log()->error('no-temp-file-found');
            return;
        }

        // Read invoice IDs from temp file
        $fileContent = file_get_contents($tmpFile);
        $facturasIds = array_filter(explode("\n", trim($fileContent)));

        if (empty($facturasIds)) {
            Tools::log()->warning('no-invoices-to-send');
            @unlink($tmpFile);
            return;
        }

        // Load invoices by their IDs
        $facturas = [];
        foreach ($facturasIds as $id) {
            $factura = new FacturaCliente();
            if ($factura->loadFromCode($id)) {
                $facturas[] = $factura;
            }
        }

        $enviados = 0;
        $errores = 0;

        foreach ($facturas as $factura) {
            // Get customer to get email if not in invoice
            if (empty($factura->email)) {
                $cliente = new Cliente();
                if ($cliente->loadFromCode($factura->codcliente)) {
                    $factura->email = $cliente->email;
                }
            }

            // Get customer email
            if (empty($factura->email)) {
                Tools::log()->warning('invoice-without-email', ['%invoice%' => $factura->codigo]);
                $errores++;
                continue;
            }

            // Get company info
            $empresa = new Empresa();
            $empresa->loadFromCode($factura->idempresa);
            $empresaNombre = $empresa->nombrecorto ?? 'Su empresa';

            // Generate PDF using ExportManager (like native FacturaScripts)
            try {
                $export = new ExportManager();
                $export->newDoc('PDF');
                $export->addBusinessDocPage($factura);
                $pdfContent = $export->getDoc();

                // Save to temporary file
                $pdfFileName = 'factura_' . $factura->codigo . '.pdf';
                $pdfPath = sys_get_temp_dir() . '/' . $pdfFileName;
                file_put_contents($pdfPath, $pdfContent);

                // Prepare email body
                $bodyHtml = '<p>Estimado/a <strong>' . $factura->nombrecliente . '</strong>,</p>' .
                           '<p>Le informamos que adjuntamos su factura <strong>' . $factura->codigo . '</strong> en formato PDF.</p>' .
                           '<p>Atentamente,<br>' . $empresaNombre . '</p>';

                // Create and send email with PDF attachment
                $mail = NewMail::create()
                    ->to($factura->email, $factura->nombrecliente)
                    ->subject($empresaNombre . ' - Factura ' . $factura->codigo)
                    ->body($bodyHtml)
                    ->addAttachment($pdfPath, $pdfFileName);

                // Send
                if ($mail->send()) {
                    $enviados++;
                    Tools::log()->info('invoice-email-sent', ['%invoice%' => $factura->codigo, '%email%' => $factura->email]);
                } else {
                    $errores++;
                    Tools::log()->error('invoice-email-failed', ['%invoice%' => $factura->codigo]);
                }

                // Clean up temporary PDF file
                @unlink($pdfPath);

            } catch (\Exception $e) {
                $errores++;
                Tools::log()->error('invoice-email-error', ['%invoice%' => $factura->codigo, '%error%' => $e->getMessage()]);
            }
        }

        Tools::log()->notice($enviados . ' emails sent, ' . $errores . ' errors.');

        // Clean up temp file after sending
        @unlink($tmpFile);
    }
}
