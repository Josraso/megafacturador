/**
 * Megafacturador Email - Mass email sending functionality
 */

function megafacSendMassEmails() {
    // Get all checked checkboxes
    var checkboxes = document.querySelectorAll('input[type="checkbox"]:checked');

    // Filter out the "select all" checkbox if exists
    var selectedBoxes = [];
    checkboxes.forEach(function(cb) {
        if (cb.value && cb.value !== 'on') {
            selectedBoxes.push(cb);
        }
    });

    if (selectedBoxes.length === 0) {
        alert('Por favor, selecciona al menos una factura');
        return;
    }

    if (!confirm('¿Enviar emails a ' + selectedBoxes.length + ' facturas seleccionadas?')) {
        return;
    }

    // Get codes
    var codes = [];
    selectedBoxes.forEach(function(checkbox) {
        codes.push(checkbox.value);
    });

    // Send AJAX request to current page with action
    var formData = new FormData();
    formData.append('action', 'megafac-send-emails');
    codes.forEach(function(code) {
        formData.append('codes[]', code);
    });

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    }).then(function(response) {
        // Reload page to show messages
        window.location.reload();
    }).catch(function(error) {
        alert('Error al enviar emails: ' + error);
    });
}
