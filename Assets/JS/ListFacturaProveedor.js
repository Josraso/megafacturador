/**
 * Megafacturador - envío por email de las facturas seleccionadas en el listado.
 */
function megafacSendMassEmailsProveedor() {
    // Only the invoice table of the view that has this button: not the checkboxes of
    // other tabs (receipts, lines...) nor the ones of the filters panel.
    var button = document.querySelector('[onclick*="megafacSendMassEmailsProveedor"]');
    var scope = null;
    if (button) {
        scope = button.closest('form') || button.closest('.tab-pane');
    }
    if (!scope) {
        scope = document.querySelector('.tab-pane.active') || document;
    }

    var codes = [];
    scope.querySelectorAll('table input[type="checkbox"]:checked').forEach(function (cb) {
        if (cb.value && cb.value !== 'on' && codes.indexOf(cb.value) === -1) {
            codes.push(cb.value);
        }
    });

    if (codes.length === 0) {
        alert('Por favor, selecciona al menos una factura');
        return;
    }

    if (!confirm('¿Enviar por email ' + codes.length + ' facturas seleccionadas?')) {
        return;
    }

    var formData = new FormData();
    formData.append('action', 'megafac-send-emails-proveedor');
    codes.forEach(function (code) {
        formData.append('codes[]', code);
    });

    fetch(window.location.href, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    }).then(function () {
        // Reload page to show messages
        window.location.reload();
    }).catch(function (error) {
        alert('Error al enviar emails: ' + error);
    });
}
