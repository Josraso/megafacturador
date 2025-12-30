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

    public function execAfterAction(): Closure
    {
        return function($action) {
            if ($action === 'megafac-send-emails-proveedor') {
                $codes = $this->request->request->get('codes', []);

                if (empty($codes)) {
                    Tools::log()->warning('No se seleccionaron facturas');
                    return;
                }

                $enviados = 0;
                $errores = 0;

                foreach ($codes as $code) {
                    $factura = new FacturaProveedorModel();
                    if (!$factura->loadFromCode($code)) {
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
                        Tools::log()->warning('invoice-without-email', ['%invoice%' => $factura->codigo]);
                        $errores++;
                        continue;
                    }

                    // Get company info
                    $empresa = new Empresa();
                    $empresa->loadFromCode($factura->idempresa);
                    $empresaNombre = $empresa->nombrecorto ?? 'Su empresa';

                    // Generate PDF and send email (EXACTLY as in Megafacturador)
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

                        // Create and send email with PDF attachment
                        $mail = NewMail::create()
                            ->to($factura->email, $factura->nombre)
                            ->subject($empresaNombre . ' - Factura ' . $factura->codigo)
                            ->body($bodyHtml)
                            ->addAttachment($pdfPath, $pdfFileName);

                        // Send
                        if ($mail->send()) {
                            $enviados++;
                            Tools::log()->info('invoice-email-sent', ['%invoice%' => $factura->codigo, '%email%' => $factura->email]);

                            // Mark invoice as sent
                            $factura->femail = date('d-m-Y');
                            $factura->horamail = date('H:i:s');
                            $factura->save();
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
            }
        };
    }
}
