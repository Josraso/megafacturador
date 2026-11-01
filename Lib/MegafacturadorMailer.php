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

namespace FacturaScripts\Plugins\Megafacturador\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Lib\Email\NewMail;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\ExportManager;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Empresa;
use FacturaScripts\Dinamic\Model\Proveedor;

/**
 * Sends invoices by email with the PDF attached and marks them as sent.
 * Single place used by the MegaFacturador and by the buttons of the invoice lists.
 */
class MegafacturadorMailer
{
    /**
     * @param string $modelName FacturaCliente or FacturaProveedor
     * @param array $ids invoice ids
     *
     * @return array ['enviados' => int, 'errores' => int]
     */
    public static function sendMany(string $modelName, array $ids): array
    {
        $result = ['enviados' => 0, 'errores' => 0];
        if (false === in_array($modelName, ['FacturaCliente', 'FacturaProveedor'], true)) {
            return $result;
        }

        $className = 'FacturaScripts\\Dinamic\\Model\\' . $modelName;
        foreach (array_unique(array_map('strval', $ids)) as $id) {
            $factura = new $className();
            if ('' === $id || false === $factura->loadFromCode($id)) {
                Tools::log()->warning('No se encuentra la factura con id ' . $id . '.');
                $result['errores']++;
                continue;
            }

            if (static::sendInvoice($factura, $modelName)) {
                $result['enviados']++;
            } else {
                $result['errores']++;
            }
        }

        // summary
        if ($result['enviados'] > 0 && $result['errores'] === 0) {
            Tools::log()->notice($result['enviados'] . ' emails enviados correctamente.');
        } elseif ($result['enviados'] > 0) {
            Tools::log()->warning($result['enviados'] . ' emails enviados, ' . $result['errores'] . ' con errores (mira los avisos de arriba).');
        } else {
            Tools::log()->error('No se ha enviado ningún email. ' . $result['errores'] . ' con errores (mira los avisos de arriba).');
        }

        return $result;
    }

    public static function sendInvoice($factura, string $modelName): bool
    {
        // recipient: the invoice has no email field, it comes from the customer / supplier
        if ($modelName === 'FacturaCliente') {
            $nombre = $factura->nombrecliente;
            $subject = new Cliente();
            $email = $subject->loadFromCode($factura->codcliente) ? trim((string)$subject->email) : '';
        } else {
            $nombre = $factura->nombre;
            $subject = new Proveedor();
            $email = $subject->loadFromCode($factura->codproveedor) ? trim((string)$subject->email) : '';
        }

        if ($email === '') {
            Tools::log()->warning('Factura ' . $factura->codigo . ' NO enviada: ' . $nombre . ' no tiene email en su ficha.');
            return false;
        }

        $empresa = new Empresa();
        $empresa->loadFromCode($factura->idempresa);
        $empresaNombre = $empresa->nombrecorto ?: ($empresa->nombre ?: 'Su empresa');

        $pdfPath = sys_get_temp_dir() . '/megafac_' . bin2hex(random_bytes(6)) . '.pdf';
        $pdfFileName = 'factura_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$factura->codigo) . '.pdf';

        try {
            $export = new ExportManager();
            $export->newDoc('PDF');
            $export->addBusinessDocPage($factura);
            file_put_contents($pdfPath, $export->getDoc());

            $bodyHtml = '<p>Estimado/a <strong>' . htmlspecialchars((string)$nombre) . '</strong>,</p>'
                . '<p>Le adjuntamos la factura <strong>' . htmlspecialchars((string)$factura->codigo) . '</strong> en formato PDF.</p>'
                . '<p>Atentamente,<br>' . htmlspecialchars((string)$empresaNombre) . '</p>';

            $mail = NewMail::create()
                ->to($email, $nombre)
                ->subject($empresaNombre . ' - Factura ' . $factura->codigo)
                ->body($bodyHtml)
                ->addAttachment($pdfPath, $pdfFileName);

            $sent = $mail->send();
        } catch (\Throwable $e) {
            @unlink($pdfPath);
            Tools::log()->error('Factura ' . $factura->codigo . ' NO enviada: ' . $e->getMessage());
            return false;
        }
        @unlink($pdfPath);

        if (false === $sent) {
            Tools::log()->error('Factura ' . $factura->codigo . ' NO enviada: falló el envío a ' . $email . ' (revisa la configuración de email).');
            return false;
        }

        if (false === static::markAsSent($factura)) {
            Tools::log()->error('Factura ' . $factura->codigo . ' enviada a ' . $email . ', pero NO se pudo marcar como enviada.');
            return true;
        }

        Tools::log()->info('Factura ' . $factura->codigo . ' enviada a ' . $email . '.');
        return true;
    }

    /**
     * Sets femail (the "email sent" column of the lists) directly in the database.
     *
     * We don't use $factura->save() because saving the whole invoice runs all the validations
     * of FacturaScripts (customer risk limit, totals, fiscal year...) and if ANY of them fails the
     * invoice is not marked, even though the email was already sent. That is why some invoices
     * were marked and others were not. Updating only this column always works.
     */
    public static function markAsSent($factura): bool
    {
        $db = new DataBase();
        $sql = 'UPDATE ' . $factura::tableName()
            . ' SET femail = ' . $db->var2str(Tools::date())
            . ' WHERE ' . $factura::primaryColumn() . ' = ' . $db->var2str($factura->primaryColumnValue()) . ';';

        if ($db->exec($sql)) {
            $factura->femail = Tools::date();
            return true;
        }
        return false;
    }

    /**
     * Basic protection against requests sent from other websites (CSRF):
     * modern browsers always send Sec-Fetch-Site; it must be same-origin.
     */
    public static function isSameOriginRequest(): bool
    {
        $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
        return $site === '' || $site === 'same-origin';
    }
}
