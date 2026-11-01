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
use FacturaScripts\Dinamic\Lib\BusinessDocumentGenerator;
use FacturaScripts\Dinamic\Model\EstadoDocumento;
use FacturaScripts\Dinamic\Model\Serie;
use FacturaScripts\Plugins\Megafacturador\Lib\MegafacturadorMailer;

/**
 * Controller to mass invoice pending delivery notes (albaranes)
 *
 * @author Carlos Garcia Gomez <neorazorx@gmail.com>
 */
class Megafacturador extends Controller
{
    /** Albaranes procesados en cada pasada (luego la página se recarga sola). */
    const BATCH_SIZE = 20;

    /**
     * @var array
     */
    public $opciones;

    /**
     * @var Serie
     */
    public $serie;

    /**
     * @var string|false
     */
    public $url_recarga;

    /**
     * @var string|false
     */
    public $url_enviar_emails;

    /**
     * Número de facturas de VENTA generadas en esta ejecución (las que se pueden enviar por email).
     *
     * @var int
     */
    public $facturas_generadas;

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'MegaFacturador';
        $data['icon'] = 'fas fa-magic';
        return $data;
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);

        $this->serie = new Serie();
        $this->url_recarga = false;
        $this->url_enviar_emails = false;
        $this->facturas_generadas = 0;
        $this->loadConfig();

        $isPost = $this->request->request->get('megafac_fecha');
        $isEmail = $this->request->query->get('enviar_emails') === 'TRUE';
        $isProcess = $this->request->query->get('procesar') === 'TRUE';
        if (!$isPost && !$isEmail && !$isProcess) {
            return;
        }

        // Facturar o enviar emails modifica datos: hace falta permiso de modificación
        if (empty($permissions->allowUpdate)) {
            Tools::log()->warning('No tienes permiso para facturar ni enviar emails desde el MegaFacturador.');
            return;
        }

        if ($isPost) {
            // Formulario de la propia página: comprobamos el token
            if (false === $this->validateFormToken()) {
                return;
            }
            $this->modificarConfig();
        } elseif ($isEmail) {
            $this->enviarEmailsFacturas();
        } else {
            // Continuación de una ejecución ya iniciada desde el formulario
            $this->generarFacturas();
        }
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
            'megafac_emitir' => Tools::settings('megafacturador', 'megafac_emitir', false),
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
        $this->opciones['megafac_emitir'] = $this->request->request->get('megafac_emitir') ? 1 : 0;
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
            // Convert Y-m-d format to dd-mm-yyyy format for database comparison
            $fechaHasta = date('d-m-Y', strtotime($this->opciones['megafac_hasta']));
            $where[] = new DataBaseWhere('fecha', $fechaHasta, '<=');
        }

        return $where;
    }

    /**
     * Pending delivery notes. If $grupo is given, only those that can go in the SAME invoice as it:
     * same customer/supplier, serie, currency, company, payment method and global discounts.
     *
     * @param string $modelName
     * @param object|null $grupo
     *
     * @return array
     */
    public function albaranesPendientes(string $modelName = 'AlbaranCliente', $grupo = null): array
    {
        $where = $this->getSqlConditions();

        if ($grupo !== null) {
            if ($modelName === 'AlbaranCliente') {
                $where[] = new DataBaseWhere('codcliente', $grupo->codcliente);
            } else {
                $where[] = new DataBaseWhere('codproveedor', $grupo->codproveedor);
            }
            $where[] = new DataBaseWhere('codserie', $grupo->codserie);
            $where[] = new DataBaseWhere('coddivisa', $grupo->coddivisa);
            $where[] = new DataBaseWhere('idempresa', $grupo->idempresa);
            $where[] = new DataBaseWhere('codpago', $grupo->codpago);
            $where[] = new DataBaseWhere('dtopor1', $grupo->dtopor1);
            $where[] = new DataBaseWhere('dtopor2', $grupo->dtopor2);
        }

        $className = 'FacturaScripts\\Dinamic\\Model\\' . $modelName;
        $model = new $className();
        return $model->all($where, ['fecha' => 'ASC', 'hora' => 'ASC'], 0, self::BATCH_SIZE);
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
     * Date of the last invoice of the same company, serie and year (to keep chronological order).
     *
     * @param string $modelName
     * @param string $codserie
     * @param int|null $idempresa
     * @param string $year YYYY
     *
     * @return string|null
     */
    private function getUltimaFechaFactura(string $modelName, string $codserie, $idempresa, string $year): ?string
    {
        $className = 'FacturaScripts\\Dinamic\\Model\\' . $modelName;
        $model = new $className();

        // Rango de fechas del año. OJO: no usar LIKE sobre la fecha, la base de datos la guarda
        // como AAAA-MM-DD y un LIKE '%-2025' no encuentra nunca nada.
        $where = [
            new DataBaseWhere('codserie', $codserie),
            new DataBaseWhere('fecha', '01-01-' . $year, '>='),
            new DataBaseWhere('fecha', '31-12-' . $year, '<='),
        ];
        if (!empty($idempresa)) {
            $where[] = new DataBaseWhere('idempresa', $idempresa);
        }

        $facturas = $model->all($where, ['fecha' => 'DESC'], 0, 1);
        return empty($facturas) ? null : $facturas[0]->fecha;
    }

    /**
     * Generate invoices from delivery notes
     */
    private function generarFacturas(): void
    {
        $recargar = false;

        $tmpFile = $this->getRunFile($runId);
        if ($tmpFile === null) {
            return;
        }

        // Determine invoice date based on user preference
        $fecha = null;
        if ($this->opciones['megafac_fecha'] === 'hoy') {
            // Use today's date in Y-m-d format (required by BusinessDocument)
            $fecha = date('Y-m-d');
        }
        // If 'albaran', $fecha stays null and will use delivery note's date

        $tipos = [];
        if ($this->opciones['megafac_ventas']) {
            $tipos['AlbaranCliente'] = 'FacturaCliente';
        }
        if ($this->opciones['megafac_compras']) {
            $tipos['AlbaranProveedor'] = 'FacturaProveedor';
        }

        foreach ($tipos as $albaranModel => $facturaModel) {
            if (!empty(Tools::log()->read('', ['critical', 'error']))) {
                break; // something failed with sales delivery notes: don't continue with purchases
            }

            $total = 0;
            foreach ($this->albaranesPendientes($albaranModel) as $alb) {
                // Group or not?
                $albaranes = [];
                if ($this->opciones['megafac_agrupar']) {
                    $albaranes2 = $this->albaranesPendientes($albaranModel, $alb);
                    if ($albaranes2 && $albaranes2[0]->primaryColumnValue() == $alb->primaryColumnValue()) {
                        $albaranes = $albaranes2;
                    }
                } else {
                    $albaranes[] = $alb;
                }

                if (empty($albaranes)) {
                    // Already invoiced in a previous group of this pass
                    continue;
                }

                if ($this->facturarAlbaranes($albaranes, $albaranModel, $facturaModel, $fecha, $tmpFile)) {
                    $total++;
                    $recargar = true;
                } else {
                    break;
                }
            }

            $texto = $albaranModel === 'AlbaranCliente' ? 'albaranes de venta facturados.' : 'albaranes de compra facturados.';
            Tools::log()->notice($total . ' ' . $texto);
        }

        // Reload?
        $errors = Tools::log()->read('', ['critical', 'error']);
        if (!empty($errors)) {
            Tools::log()->error('Se han producido errores. Proceso detenido.');
        } elseif ($recargar) {
            $this->url_recarga = $this->url() . '?procesar=TRUE&megafac_runid=' . $runId;
            Tools::log()->notice('Recargando...');
        } else {
            Tools::log()->notice('Terminado.');

            // Count generated invoices
            $docs = $this->readRunFile($tmpFile);
            $ventas = count($docs['FacturaCliente']);
            $compras = count($docs['FacturaProveedor']);
            $this->facturas_generadas = $ventas;
            if ($ventas + $compras > 0) {
                Tools::log()->notice('Facturas generadas: ' . $ventas . ' de venta y ' . $compras . ' de compra.');
            }

            if ($this->opciones['megafac_email'] && $ventas > 0) {
                // Show button to send emails (only sales invoices are emailed)
                $this->url_enviar_emails = $this->url() . '?enviar_emails=TRUE&runid=' . $runId;
            } else {
                // Clean up temp file if not sending emails
                @unlink($tmpFile);
            }
        }
    }

    /**
     * Temporary file that keeps the invoices generated along the reloads of one execution.
     * The run id is random and is only created from the form (POST with token), so a link
     * with an unknown or invented run id is rejected.
     *
     * @param string|null $runId
     *
     * @return string|null
     */
    private function getRunFile(?string &$runId): ?string
    {
        $runId = (string)$this->request->get('megafac_runid', '');
        if ($runId === '') {
            if (empty($this->request->request->get('megafac_fecha'))) {
                // A new execution can only be started from the form
                Tools::log()->warning('Pulsa el botón "Empezar" para iniciar la facturación.');
                return null;
            }

            $runId = 'mf_' . bin2hex(random_bytes(8));
            $tmpFile = $this->runFilePath($runId);
            file_put_contents($tmpFile, '');

            // Clean up old temp files (older than 1 hour)
            foreach (glob(sys_get_temp_dir() . '/megafac_mf_*.txt') as $oldFile) {
                if (filemtime($oldFile) < time() - 3600) {
                    @unlink($oldFile);
                }
            }
            return $tmpFile;
        }

        $tmpFile = $this->runFilePath($runId);
        if ($tmpFile === null || !file_exists($tmpFile)) {
            Tools::log()->warning('Ejecución no válida o caducada. Vuelve a pulsar "Empezar".');
            return null;
        }
        return $tmpFile;
    }

    /**
     * Validated path of the temp file (prevents ../ in the run id).
     */
    private function runFilePath(string $runId): ?string
    {
        if (1 !== preg_match('/^mf_[a-f0-9]{16}$/', $runId)) {
            return null;
        }
        return sys_get_temp_dir() . '/megafac_' . $runId . '.txt';
    }

    /**
     * Lines are "FacturaCliente:ID" or "FacturaProveedor:ID".
     */
    private function readRunFile(string $tmpFile): array
    {
        $docs = ['FacturaCliente' => [], 'FacturaProveedor' => []];
        if (!file_exists($tmpFile)) {
            return $docs;
        }

        foreach (explode("\n", trim((string)file_get_contents($tmpFile))) as $row) {
            $parts = explode(':', trim($row));
            if (count($parts) === 2 && isset($docs[$parts[0]]) && $parts[1] !== '') {
                $docs[$parts[0]][] = $parts[1];
            }
        }
        return $docs;
    }

    /**
     * Generate ONE invoice from one or more delivery notes, all inside a transaction:
     * if anything fails nothing is saved (no half-invoiced delivery notes, no duplicates).
     *
     * @param array $albaranes
     * @param string $albaranModel
     * @param string $facturaModel
     * @param string|null $fecha
     * @param string $tmpFile
     *
     * @return bool
     */
    private function facturarAlbaranes(array $albaranes, string $albaranModel, string $facturaModel, ?string $fecha, string $tmpFile): bool
    {
        // Use first albaran as prototype (oldest in the batch)
        $prototype = $albaranes[0];

        // Collect all lines from all albaranes. Only the PENDING quantity of each line is invoiced
        // (cantidad - servido), so partially served delivery notes are not invoiced twice.
        $newLines = [];
        $quantities = [];
        $hayPendiente = false;
        foreach ($albaranes as $alb) {
            // Add separator line showing albaran info (when grouping multiple albaranes)
            if (count($albaranes) > 1) {
                $infoLine = $alb->getNewLine();
                $infoLine->cantidad = 0;
                $infoLine->pvpunitario = 0;
                $infoLine->descripcion = 'Albarán ' . $alb->codigo . ' (' . $alb->numero2 . '), ' . $alb->fecha;
                $newLines[] = $infoLine;
                $quantities[$infoLine->primaryColumnValue()] = 0;
            }

            foreach ($alb->getLines() as $line) {
                $pendiente = $this->cantidadPendiente($line);
                if ($pendiente != 0) {
                    $hayPendiente = true;
                }
                $newLines[] = $line;
                $quantities[$line->primaryColumnValue()] = $pendiente;
            }
        }

        $generadas = [];
        $this->dataBase->beginTransaction();

        if ($hayPendiente) {
            $properties = ['fecha' => $fecha ?? $this->fechaFactura($prototype, $facturaModel)];

            // Generate invoice. BusinessDocumentGenerator already updates "servido" in the
            // delivery note lines, so it must NOT be updated again here.
            $generator = new BusinessDocumentGenerator();
            if (false === $generator->generate($prototype, $facturaModel, $newLines, $quantities, $properties)) {
                $this->dataBase->rollback();
                Tools::log()->error('No se pudo generar la factura del albarán ' . $prototype->codigo . '.');
                return false;
            }

            foreach ($generator->getLastDocs() as $factura) {
                if ($this->opciones['megafac_emitir']) {
                    $this->emitirFactura($factura, $facturaModel);
                }
                $generadas[] = $facturaModel . ':' . $factura->primaryColumnValue();
            }
        }

        // Close the delivery notes that are now fully served
        foreach ($albaranes as $alb) {
            if (false === $this->cerrarAlbaranSiServido($alb, $albaranModel, $facturaModel)) {
                $this->dataBase->rollback();
                Tools::log()->error('No se pudo marcar como facturado el albarán ' . $alb->codigo . '.');
                return false;
            }
        }

        if (false === $this->dataBase->commit()) {
            $this->dataBase->rollback();
            Tools::log()->error('No se pudo guardar la factura del albarán ' . $prototype->codigo . '.');
            return false;
        }

        // only after commit: these invoices really exist
        foreach ($generadas as $row) {
            file_put_contents($tmpFile, $row . "\n", FILE_APPEND);
        }
        return true;
    }

    /**
     * Pending quantity of a delivery note line (also works with negative quantities / returns).
     */
    private function cantidadPendiente($line): float
    {
        $cantidad = (float)$line->cantidad;
        $pendiente = $cantidad - (float)$line->servido;
        if ($cantidad >= 0) {
            return max(0.0, $pendiente);
        }
        return min(0.0, $pendiente);
    }

    /**
     * Invoice date when invoicing with the delivery note date: if there are later invoices of the
     * same company, serie and year, use the date of the last one (FacturaScripts does not allow
     * an invoice dated before the previous one). Never moves the date to another year.
     */
    private function fechaFactura($prototype, string $facturaModel): string
    {
        $fechaFactura = $prototype->fecha;
        $year = date('Y', strtotime($prototype->fecha));
        $ultima = $this->getUltimaFechaFactura($facturaModel, $prototype->codserie, $prototype->idempresa, $year);
        if ($ultima && strtotime($fechaFactura) < strtotime($ultima)) {
            $fechaFactura = $ultima;
        }
        return $fechaFactura;
    }

    /**
     * Change the invoice from draft (Boceto) to issued (Emitida / Recibida).
     */
    private function emitirFactura($factura, string $facturaModel): void
    {
        $estado = $this->getEstadoNoEditable($facturaModel);
        if ($estado === null) {
            Tools::log()->warning('No hay ningún estado "no editable" para ' . $facturaModel . '. La factura ' . $factura->codigo . ' queda como boceto.');
            return;
        }

        $factura->setDocumentGeneration(false);
        $factura->idestado = $estado->idestado;
        if (false === $factura->save()) {
            Tools::log()->warning('No se pudo emitir la factura ' . $factura->codigo . ', queda como boceto.');
        }
    }

    /**
     * Mark the delivery note as invoiced if all its lines are fully served.
     */
    private function cerrarAlbaranSiServido($alb, string $albaranModel, string $facturaModel): bool
    {
        // fresh lines from database (servido was updated by the generator)
        foreach ($alb->getLines() as $line) {
            if (abs((float)$line->servido) < abs((float)$line->cantidad)) {
                return true; // still partially pending, keep it open
            }
        }

        // reload so we don't overwrite changes made by the generator
        $alb->loadFromCode($alb->primaryColumnValue());

        $estado = $this->getEstadoNoEditable($albaranModel, $facturaModel);
        if ($estado !== null) {
            // Prevent auto-generation of another invoice when changing status
            $alb->setDocumentGeneration(false);
            $alb->idestado = $estado->idestado;
        }
        $alb->editable = false;

        return $alb->save();
    }

    /**
     * Non-editable status for a document type, chosen explicitly: if $generadoc is given, the one that
     * generates that document (e.g. "Facturar" for delivery notes); otherwise the first one by order
     * that does not generate anything (e.g. "Emitida"/"Recibida" for invoices). Never "Devuelto",
     * "Anulada"... unless there is nothing else.
     */
    private function getEstadoNoEditable(string $tipodoc, string $generadoc = ''): ?EstadoDocumento
    {
        $estadoModel = new EstadoDocumento();
        $estados = $estadoModel->all([
            new DataBaseWhere('tipodoc', $tipodoc),
            new DataBaseWhere('editable', false)
        ], ['orden' => 'ASC', 'idestado' => 'ASC'], 0, 0);

        foreach ($estados as $estado) {
            if ($generadoc !== '' && $estado->generadoc === $generadoc) {
                return $estado;
            }
        }
        foreach ($estados as $estado) {
            if (empty($estado->generadoc)) {
                return $estado;
            }
        }
        return $estados[0] ?? null;
    }

    /**
     * Send emails for the sales invoices generated in this execution
     */
    private function enviarEmailsFacturas(): void
    {
        $runId = (string)$this->request->get('runid', '');
        $tmpFile = $this->runFilePath($runId);
        if ($tmpFile === null || !file_exists($tmpFile)) {
            Tools::log()->error('No se encuentran las facturas de esta ejecución (puede que ya se enviaran o que haya caducado).');
            return;
        }

        $ids = $this->readRunFile($tmpFile)['FacturaCliente'];
        if (empty($ids)) {
            Tools::log()->warning('No hay facturas de venta que enviar.');
        } else {
            MegafacturadorMailer::sendMany('FacturaCliente', $ids);
        }

        // Clean up temp file after sending
        @unlink($tmpFile);
    }
}
