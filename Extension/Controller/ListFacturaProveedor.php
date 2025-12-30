<?php
namespace FacturaScripts\Plugins\Megafacturador\Extension\Controller;

use Closure;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Lib\Email\NewMail;
use FacturaScripts\Dinamic\Lib\ExportManager;
use FacturaScripts\Dinamic\Model\FacturaProveedor as FacturaProveedorModel;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Dinamic\Model\Empresa;

class ListFacturaProveedor
{
    public function createViews(): Closure
    {
        return function() {
            // Force load JavaScript
            \FacturaScripts\Dinamic\Lib\AssetManager::addJs(FS_ROUTE . '/Plugins/Megafacturador/Assets/JS/ListFacturaProveedor.js');
        };
    }

    public function execPreviousAction(): Closure
    {
        return function($action) {
            if ($action === 'megafac-send-emails-proveedor') {
                $this->processMegafacSendEmails();
                return true;
            }
            return true;
        };
    }

    protected function processMegafacSendEmails(): Closure
    {
        return function() {
            $codes = $this->request->request->get('codes', []);

            if (empty($codes)) {
                Tools::log()->warning('No se seleccionaron facturas');
                return;
            }

            Tools::log()->notice('MEGAFAC DEBUG: Iniciando envío de emails para ' . count($codes) . ' facturas proveedor');

            $enviados = 0;
            $errores = 0;

            foreach ($codes as $code) {
                Tools::log()->notice('MEGAFAC DEBUG: Procesando factura proveedor: ' . $code);

                $factura = new FacturaProveedorModel();
                if (!$factura->loadFromCode($code)) {
                    Tools::log()->warning('Factura no encontrada: ' . $code);
                    $errores++;
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
                    Tools::log()->warning('Factura sin email: ' . $factura->codigo);
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
                    Tools::log()->notice('MEGAFAC DEBUG: PDF guardado, tamaño: ' . strlen($pdfContent) . ' bytes');

                    // Prepare email body
                    $bodyHtml = '<p>Estimado/a <strong>' . $factura->nombre . '</strong>,</p>' .
                               '<p>Le informamos que adjuntamos la factura <strong>' . $factura->codigo . '</strong> en formato PDF.</p>' .
                               '<p>Atentamente,<br>' . $empresaNombre . '</p>';

                    // Create and send email
                    Tools::log()->notice('MEGAFAC DEBUG: Creando email para ' . $factura->email);
                    $mail = NewMail::create()
                        ->to($factura->email, $factura->nombre)
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

                        Tools::log()->info('Email enviado OK: ' . $factura->codigo . ' a ' . $factura->email);
                    } else {
                        $errores++;
                        Tools::log()->error('Email FALLÓ: ' . $factura->codigo);
                    }

                    // Clean up temporary PDF file
                    @unlink($pdfPath);

                } catch (\Exception $e) {
                    $errores++;
                    Tools::log()->error('Error enviando email: ' . $factura->codigo . ' - ' . $e->getMessage());
                }
            }

            Tools::log()->notice('MEGAFAC DEBUG: Finalizado - Enviados: ' . $enviados . ', Errores: ' . $errores);

            if ($enviados > 0) {
                Tools::log()->notice('Se enviaron ' . $enviados . ' emails correctamente');
            }
            if ($errores > 0) {
                Tools::log()->warning('Hubo ' . $errores . ' errores al enviar emails');
            }
        };
    }
}
