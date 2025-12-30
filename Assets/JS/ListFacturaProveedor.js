/**
 * ListFacturaProveedor - Mass email sending functionality
 */

function megafacSendMassEmailsProveedor() {
    // Get all checked checkboxes
    var checkboxes = document.querySelectorAll('input[name="code[]"]:checked');

    if (checkboxes.length === 0) {
        alert('Por favor, selecciona al menos una factura');
        return;
    }

    if (!confirm('¿Enviar emails a ' + checkboxes.length + ' facturas seleccionadas?')) {
        return;
    }

    // Create form and submit to MegafacturadorEmail controller
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = 'MegafacturadorEmail';

    // Add action
    var actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'send-emails';
    form.appendChild(actionInput);

    // Add model type
    var modelInput = document.createElement('input');
    modelInput.type = 'hidden';
    modelInput.name = 'model';
    modelInput.value = 'FacturaProveedor';
    form.appendChild(modelInput);

    // Add return URL
    var returnInput = document.createElement('input');
    returnInput.type = 'hidden';
    returnInput.name = 'return_url';
    returnInput.value = window.location.href;
    form.appendChild(returnInput);

    // Add selected codes
    checkboxes.forEach(function(checkbox) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'code[]';
        input.value = checkbox.value;
        form.appendChild(input);
    });

    document.body.appendChild(form);
    form.submit();
}
