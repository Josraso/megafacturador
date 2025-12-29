<?php
/**
 * This file is part of megafacturador
 * Copyright (C) 2014-2019 Carlos Garcia Gomez <neorazorx@gmail.com>
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
require_once 'plugins/facturacion_base/extras/fbase_controller.php';

class megafacturador extends fbase_controller
{
    /**
     *
     * @var asiento_factura
     */
    private $asiento_factura;
    
    /**
     *
     * @var cliente
     */
    private $cliente;
    
    /**
     *
     * @var ejercicio
     */
    private $ejercicio;
    
    /**
     *
     * @var ejercicio[]
     */
    private $ejercicios;
    
    /**
     *
     * @var forma_pago
     */
    private $forma_pago;
    
    /**
     *
     * @var forma_pago[]
     */
    private $formas_pago;
    
    /**
     *
     * @var fs_var
     */
    private $fsvar;

    /**
     *
     * @var int
     */
    public $numasientos;
    
    /**
     *
     * @var array
     */
    public $opciones;
    
    /**
     *
     * @var proveedor
     */
    private $proveedor;
    
    /**
     *
     * @var regularizacion_iva
     */
    private $regularizacion;
    
    /**
     *
     * @var serie
     */
    public $serie;
    
    /**
     *
     * @var string
     */
    public $url_recarga;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'MegaFacturador', 'ventas', FALSE, TRUE, TRUE);
    }

    protected function private_core()
    {
        $this->asiento_factura = new asiento_factura();
        $this->cliente = new cliente();
        $this->ejercicio = new ejercicio();
        $this->ejercicios = array();
        $this->forma_pago = new forma_pago();
        $this->formas_pago = $this->forma_pago->all();
        $this->fsvar = new fs_var();
        $this->numasientos = 0;
        $this->proveedor = new proveedor();
        $this->regularizacion = new regularizacion_iva();
        $this->serie = new serie();
        $this->url_recarga = FALSE;
        $this->load_config();

        if (filter_input(INPUT_POST, 'megafac_fecha')) {
            $this->modificar_config();
        } else if (filter_input(INPUT_GET, 'procesar') == 'TRUE') {
            $this->generar_facturas();
        } else if (isset($_GET['genasientos'])) {
            $this->generar_asientos();
        } else if (isset($_GET['activar_contintegrada'])) {
            $this->activar_contabilidad_integrada();
        } else {
            $this->share_extensions();
        }

        $this->numasientos = $this->num_asientos_a_generar();
    }

    private function load_config()
    {
        $this->opciones = array(
            'megafac_agrupar' => FALSE,
            'megafac_codserie' => '',
            'megafac_compras' => 1,
            'megafac_email' => FALSE,
            'megafac_fecha' => 'albaran',
            'megafac_hasta' => date('d-m-Y'),
            'megafac_ventas' => 1,
        );
        $this->opciones = $this->fsvar->array_get($this->opciones, FALSE);

        /// corregimos el formato de la fecha
        $this->opciones['megafac_hasta'] = date('d-m-Y', strtotime($this->opciones['megafac_hasta']));
    }

    private function modificar_config()
    {
        $this->opciones['megafac_agrupar'] = filter_input(INPUT_POST, 'megafac_agrupar') ? 1 : 0;
        $this->opciones['megafac_codserie'] = filter_input(INPUT_POST, 'megafac_codserie');
        $this->opciones['megafac_compras'] = filter_input(INPUT_POST, 'megafac_compras') ? 1 : 0;
        $this->opciones['megafac_email'] = filter_input(INPUT_POST, 'megafac_email') ? 1 : 0;
        $this->opciones['megafac_fecha'] = filter_input(INPUT_POST, 'megafac_fecha');
        $this->opciones['megafac_hasta'] = filter_input(INPUT_POST, 'megafac_hasta');
        $this->opciones['megafac_ventas'] = filter_input(INPUT_POST, 'megafac_ventas') ? 1 : 0;

        $this->fsvar->array_save($this->opciones);

        if (filter_input(INPUT_POST, 'procesar') == 'TRUE') {
            $this->generar_facturas();
        }
    }

    private function activar_contabilidad_integrada()
    {
        $this->empresa->contintegrada = TRUE;
        if ($this->empresa->save()) {
            $this->new_message('Datos guardados correctamente.');
        } else {
            $this->new_error_msg('Error al guardar los datos.');
        }
    }

    private function get_sql_aux()
    {
        $sql = '';

        if ($this->opciones['megafac_codserie'] != '') {
            $sql .= " AND codserie = " . $this->serie->var2str($this->opciones['megafac_codserie']);
        }
        if ($this->opciones['megafac_hasta']) {
            $sql .= " AND fecha <= " . $this->serie->var2str($this->opciones['megafac_hasta']);
        }

        return $sql;
    }

    public function albaranes_pendientes($tabla = 'albaranescli', $codcliente = '', $codproveedor = '', $codserie = '', $coddivisa = '')
    {
        $alblist = array();
        $sql = "SELECT * FROM " . $tabla . " WHERE ptefactura = true AND total != 0" . $this->get_sql_aux();
        if ($codcliente) {
            $sql .= " AND codcliente = " . $this->serie->var2str($codcliente)
                . " AND codserie = " . $this->serie->var2str($codserie)
                . " AND coddivisa = " . $this->serie->var2str($coddivisa);
        } else if ($codproveedor) {
            $sql .= " AND codproveedor = " . $this->serie->var2str($codproveedor)
                . " AND codserie = " . $this->serie->var2str($codserie)
                . " AND coddivisa = " . $this->serie->var2str($coddivisa);
        }
        $sql .= ' ORDER BY fecha ASC, hora ASC';

        $data = $this->db->select_limit($sql, 20, 0);
        if ($data && $tabla == 'albaranescli') {
            foreach ($data as $d) {
                $alblist[] = new albaran_cliente($d);
            }
        } else if ($data && $tabla == 'albaranesprov') {
            foreach ($data as $d) {
                $alblist[] = new albaran_proveedor($d);
            }
        }

        return $alblist;
    }

    public function total_pendientes($tabla = 'albaranescli')
    {
        $sql = "SELECT count(idalbaran) as total FROM " . $tabla . " WHERE ptefactura = true AND total != 0" . $this->get_sql_aux();
        $data = $this->db->select($sql);
        if ($data) {
            return intval($data[0]['total']);
        }

        return 0;
    }

    private function generar_facturas()
    {
        $recargar = FALSE;
        $fecha = $this->today();
        if ($this->opciones['megafac_fecha'] == 'albaran') {
            $fecha = '';
        }

        if ($this->opciones['megafac_ventas']) {
            $total1 = 0;
            foreach ($this->albaranes_pendientes('albaranescli') as $alb) {

                /// ¿Agrupado por cliente o no?
                $albaranes = array();
                if ($this->opciones['megafac_agrupar']) {
                    $albaranes2 = $this->albaranes_pendientes('albaranescli', $alb->codcliente, '', $alb->codserie, $alb->coddivisa);
                    if ($albaranes2 && $albaranes2[0]->idalbaran == $alb->idalbaran) {
                        /**
                         * Debemos evitar el caso de avanzar demasiado en la fecha
                         * al agrupar los albaranes de un cliente que ya hemos procesado
                         * en este mismo ciclo. Obtendríamos albaranes de muy adelante en
                         * la lista.
                         */
                        $albaranes = $albaranes2;
                    }
                } else {
                    $albaranes[] = $alb;
                }

                if (empty($albaranes)) {
                    /// hemos facturado ya este albarán al agruparlo con otros, no pasa nada
                } else if ($this->fbase_facturar_albaran_cliente($albaranes, $fecha)) {
                    $total1++;
                    $recargar = TRUE;
                } else {
                    break;
                }
            }

            $this->new_message($total1 . ' ' . FS_ALBARANES . ' de cliente facturados.');
        }

        if ($this->opciones['megafac_compras']) {
            $total2 = 0;
            foreach ($this->albaranes_pendientes('albaranesprov') as $alb) {

                /// ¿Agrupado por proveedor o no?
                $albaranes = array();
                if ($this->opciones['megafac_agrupar']) {
                    $albaranes2 = $this->albaranes_pendientes('albaranesprov', '', $alb->codproveedor, $alb->codserie, $alb->coddivisa);
                    if ($albaranes2 && $albaranes2[0]->idalbaran == $alb->idalbaran) {
                        /**
                         * Debemos evitar el caso de avanzar demasiado en la fecha
                         * al agrupar los albaranes de un proveedor que ya hemos procesado
                         * en este mismo ciclo. Obtendríamos albaranes de muy adelante en
                         * la lista.
                         */
                        $albaranes = $albaranes2;
                    }
                } else {
                    $albaranes[] = $alb;
                }

                if (empty($albaranes)) {
                    /// hemos facturado ya este albarán al agruparlo con otros, no pasa nada
                } else if ($this->fbase_facturar_albaran_proveedor($albaranes, $fecha)) {
                    $total2++;
                    $recargar = TRUE;
                } else {
                    break;
                }
            }

            $this->new_message($total2 . ' ' . FS_ALBARANES . ' de proveedor facturados.');
        }

        /// ¿Recargamos?
        if (count($this->get_errors()) > 0) {
            $this->new_error_msg('Se han producido errores. Proceso detenido.');
        } else if ($recargar) {
            $this->url_recarga = $this->url() . '&procesar=TRUE';
            $this->new_message('Recargando... &nbsp; <i class="fa fa-refresh fa-spin"></i>');
        } else {
            $this->new_advice('Finalizado. <span class="glyphicon glyphicon-ok" aria-hidden="true"></span>');
            if ($this->opciones['megafac_email']) {
                $this->enviar_facturas();
            }
        }
    }

    private function enviar_facturas()
    {
        if ($this->user->have_access_to('ventas_enviar_facturas')) {
            header('Location: index.php?page=ventas_enviar_facturas&enviar=TRUE');
        } else {
            $this->new_error_msg('Opción de enviar desactivada.');
        }
    }

    private function share_extensions()
    {
        $fsext = new fs_extension();
        $fsext->name = 'megafacturar_albpro';
        $fsext->from = __CLASS__;
        $fsext->to = 'compras_albaranes';
        $fsext->type = 'button';
        $fsext->text = '<i class="fa fa-check-square-o" aria-hidden="true"></i>'
            . '<span class="hidden-xs">&nbsp; megafacturador</span>';
        $fsext->save();

        $fsext2 = new fs_extension();
        $fsext2->name = 'megafacturar_albcli';
        $fsext2->from = __CLASS__;
        $fsext2->to = 'ventas_albaranes';
        $fsext2->type = 'button';
        $fsext2->text = '<i class="fa fa-check-square-o" aria-hidden="true"></i>'
            . '<span class="hidden-xs">&nbsp; megafacturador</span>';
        $fsext2->save();
    }

    protected function fbase_generar_asiento(&$factura, $mensaje = FALSE, $forzar = FALSE, $soloasiento = FALSE)
    {
        $ok = TRUE;

        if ($this->empresa->contintegrada || $forzar) {
            $this->asiento_factura->soloasiento = $soloasiento;

            if (get_class_name($factura) == 'factura_cliente') {
                $ok = $this->asiento_factura->generar_asiento_venta($factura);
            } else if (get_class_name($factura) == 'factura_proveedor') {
                $ok = $this->asiento_factura->generar_asiento_compra($factura);
            }
        }

        return $ok;
    }

    private function generar_asientos()
    {
        $nuevos = 0;

        $data = $this->db->select_limit("SELECT * FROM facturascli WHERE idasiento IS NULL", 50, 0);
        if ($data) {
            foreach ($data as $d) {
                $factura = new factura_cliente($d);
                if (is_null($factura->idasiento)) {
                    if ($this->fbase_generar_asiento($factura, FALSE, TRUE, TRUE)) {
                        $nuevos++;
                    } else {
                        break;
                    }
                }
            }
        }
        $this->new_message($nuevos . ' asientos generados para facturas de venta.');

        $nuevos2 = 0;
        $data2 = $this->db->select_limit("SELECT * FROM facturasprov WHERE idasiento IS NULL", 50, 0);
        if ($data2) {
            foreach ($data2 as $d) {
                $factura = new factura_proveedor($d);
                if (is_null($factura->idasiento)) {
                    if ($this->fbase_generar_asiento($factura, FALSE, TRUE, TRUE)) {
                        $nuevos2++;
                    } else {
                        break;
                    }
                }
            }
        }
        $this->new_message($nuevos2 . ' asientos generados para facturas de compra.');

        /// ¿Recargamos?
        if (count($this->get_errors()) > 0) {
            $this->new_error_msg('Se han producido errores. Proceso detenido.');
        } else if ($this->num_asientos_a_generar() > 0) {
            $this->url_recarga = $this->url() . '&genasientos=TRUE';
            $this->new_message('Recargando... &nbsp; <i class="fa fa-refresh fa-spin"></i>');
        }
    }

    private function num_asientos_a_generar()
    {
        $num = 0;

        $data = $this->db->select("SELECT COUNT(idfactura) as num FROM facturascli WHERE idasiento IS NULL;");
        if ($data) {
            $num += intval($data[0]['num']);
        }

        $data2 = $this->db->select("SELECT COUNT(idfactura) as num FROM facturasprov WHERE idasiento IS NULL;");
        if ($data2) {
            $num += intval($data2[0]['num']);
        }

        return $num;
    }
}
