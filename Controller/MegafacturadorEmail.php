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
use FacturaScripts\Core\Lib\Email\NewMail;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\ExportManager;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Dinamic\Model\Empresa;

/**
 * Controller for mass email sending from lists
 */
class MegafacturadorEmail extends Controller
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'Mass Email Sending';
        $data['icon'] = 'fas fa-envelope';
        $data['showonmenu'] = false;
        return $data;
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);

        $action = $this->request->request->get('action', '');
        $modelType = $this->request->request->get('model', '');
        $codes = $this->request->request->get('code', []);

        // Ensure $codes is always an array
        if (!is_array($codes)) {
            $codes = [$codes];
        }

        if ($action === 'send-emails' && !empty($codes)) {
            Tools::log()->notice('MEGAFAC DEBUG: Iniciando envío de emails para ' . count($codes) . ' documentos');

            $result = ['enviados' => 0, 'errores' => 0];

            if ($modelType === 'FacturaCliente') {
                $result = $this->sendFacturaClienteEmails($codes);
            } elseif ($modelType === 'FacturaProveedor') {
                $result = $this->sendFacturaProveedorEmails($codes);
            }

            // Show summary message
            if ($result['enviados'] > 0) {
                Tools::log()->notice('Se enviaron ' . $result['enviados'] . ' emails correctamente');
            }
            if ($result['errores'] > 0) {
                Tools::log()->warning('Hubo ' . $result['errores'] . ' errores al enviar emails');
            }

            // Redirect back to list
            $returnUrl = $this->request->request->get('return_url', '');
            if (!empty($returnUrl)) {
                header('Location: ' . $returnUrl);
                exit;
            }
        }
    }

    private function sendFacturaClienteEmails(array $codes): array
    {
        $enviados = 0;
        $errores = 0;

        Tools::log()->notice('MEGAFAC DEBUG: sendFacturaClienteEmails recibió ' . count($codes) . ' códigos');

        foreach ($codes as $code) {
            Tools::log()->notice('MEGAFAC DEBUG: Procesando factura: ' . $code);
            $factura = new FacturaCliente();
            if (!$factura->loadFromCode($code)) {
                continue;
            }

            // Get customer email if not in invoice
            if (empty($factura->email)) {
                $cliente = new Cliente();
                if ($cliente->loadFromCode($factura->codcliente)) {
                    $factura->email = $cliente->email;
                }
            }

            // Skip if no email
            if (empty($factura->email)) {
                Tools::log()->warning('invoice-without-email', ['%invoice%' => $factura->codigo]);
                $errores++;
                continue;
            }

            // Get company info
            $empresa = new Empresa();
            $empresa->loadFromCode($factura->idempresa);
            $empresaNombre = $empresa->nombrecorto ?? 'Su empresa';

            // Generate PDF and send email
            try {
                Tools::log()->notice('MEGAFAC DEBUG: Generando PDF para ' . $factura->codigo);
                $export = new ExportManager();
                $export->newDoc('PDF');
                $export->addBusinessDocPage($factura);
                $pdfContent = $export->getDoc();

                // Save to temporary file
                $pdfFileName = 'factura_' . $factura->codigo . '.pdf';
                $pdfPath = sys_get_temp_dir() . '/' . $pdfFileName;
                file_put_contents($pdfPath, $pdfContent);
                Tools::log()->notice('MEGAFAC DEBUG: PDF guardado en ' . $pdfPath . ', tamaño: ' . strlen($pdfContent) . ' bytes');

                // Prepare email body
                $bodyHtml = '<p>Estimado/a <strong>' . $factura->nombrecliente . '</strong>,</p>' .
                           '<p>Le informamos que adjuntamos su factura <strong>' . $factura->codigo . '</strong> en formato PDF.</p>' .
                           '<p>Atentamente,<br>' . $empresaNombre . '</p>';

                // Create and send email
                Tools::log()->notice('MEGAFAC DEBUG: Creando email para ' . $factura->email);
                $mail = NewMail::create()
                    ->to($factura->email, $factura->nombrecliente)
                    ->subject($empresaNombre . ' - Factura ' . $factura->codigo)
                    ->body($bodyHtml)
                    ->addAttachment($pdfPath, $pdfFileName);

                // Send
                Tools::log()->notice('MEGAFAC DEBUG: Intentando enviar email...');
                $sendResult = $mail->send();
                Tools::log()->notice('MEGAFAC DEBUG: Resultado del send(): ' . ($sendResult ? 'TRUE' : 'FALSE'));

                if ($sendResult) {
                    $enviados++;

                    // Mark invoice as sent
                    $factura->femail = date('d-m-Y');
                    $factura->horamail = date('H:i:s');
                    $factura->save();

                    Tools::log()->info('MEGAFAC DEBUG: Email enviado OK - invoice-email-sent', ['%invoice%' => $factura->codigo, '%email%' => $factura->email]);
                } else {
                    $errores++;
                    Tools::log()->error('MEGAFAC DEBUG: Email FALLÓ - invoice-email-failed', ['%invoice%' => $factura->codigo]);
                }

                // Clean up temporary PDF file
                @unlink($pdfPath);

            } catch (\Exception $e) {
                $errores++;
                Tools::log()->error('invoice-email-error', ['%invoice%' => $factura->codigo, '%error%' => $e->getMessage()]);
            }
        }

        Tools::log()->notice('MEGAFAC DEBUG: Finalizado - Enviados: ' . $enviados . ', Errores: ' . $errores);
        return ['enviados' => $enviados, 'errores' => $errores];
    }

    private function sendFacturaProveedorEmails(array $codes): array
    {
        $enviados = 0;
        $errores = 0;

        Tools::log()->notice('MEGAFAC DEBUG: sendFacturaProveedorEmails recibió ' . count($codes) . ' códigos');

        foreach ($codes as $code) {
            Tools::log()->notice('MEGAFAC DEBUG: Procesando factura proveedor: ' . $code);
            $factura = new FacturaProveedor();
            if (!$factura->loadFromCode($code)) {
                continue;
            }

            // Get supplier email if not in invoice
            if (empty($factura->email)) {
                $proveedor = new Proveedor();
                if ($proveedor->loadFromCode($factura->codproveedor)) {
                    $factura->email = $proveedor->email;
                }
            }

            // Skip if no email
            if (empty($factura->email)) {
                Tools::log()->warning('invoice-without-email', ['%invoice%' => $factura->codigo]);
                $errores++;
                continue;
            }

            // Get company info
            $empresa = new Empresa();
            $empresa->loadFromCode($factura->idempresa);
            $empresaNombre = $empresa->nombrecorto ?? 'Su empresa';

            // Generate PDF and send email
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
                $bodyHtml = '<p>Estimado/a <strong>' . $factura->nombre . '</strong>,</p>' .
                           '<p>Le informamos que adjuntamos la factura <strong>' . $factura->codigo . '</strong> en formato PDF.</p>' .
                           '<p>Atentamente,<br>' . $empresaNombre . '</p>';

                // Create and send email
                $mail = NewMail::create()
                    ->to($factura->email, $factura->nombre)
                    ->subject($empresaNombre . ' - Factura ' . $factura->codigo)
                    ->body($bodyHtml)
                    ->addAttachment($pdfPath, $pdfFileName);

                // Send
                if ($mail->send()) {
                    $enviados++;

                    // Mark invoice as sent
                    $factura->femail = date('d-m-Y');
                    $factura->horamail = date('H:i:s');
                    $factura->save();

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

        Tools::log()->notice('MEGAFAC DEBUG: Finalizado - Enviados: ' . $enviados . ', Errores: ' . $errores);
        return ['enviados' => $enviados, 'errores' => $errores];
    }
}
