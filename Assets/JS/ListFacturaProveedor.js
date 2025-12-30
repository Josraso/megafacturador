/**
 * ListFacturaProveedor - Mass email sending functionality
 */

console.log('MEGAFAC: ListFacturaProveedor.js LOADED');

function megafacSendMassEmailsProveedor() {
    console.log('MEGAFAC: megafacSendMassEmailsProveedor called');

    // Try different selectors to find checkboxes
    var checkboxes = document.querySelectorAll('input[type="checkbox"]:checked');
    console.log('MEGAFAC: Found checkboxes:', checkboxes.length);

    // Log checkbox names to debug
    checkboxes.forEach(function(cb) {
        console.log('MEGAFAC: Checkbox name:', cb.name, 'value:', cb.value);
    });

    // Filter out the "select all" checkbox if exists
    var selectedBoxes = [];
    checkboxes.forEach(function(cb) {
        if (cb.value && cb.value !== 'on') {
            selectedBoxes.push(cb);
        }
    });

    console.log('MEGAFAC: Selected boxes (filtered):', selectedBoxes.length);

    if (selectedBoxes.length === 0) {
        alert('Por favor, selecciona al menos una factura');
        return;
    }

    if (!confirm('¿Enviar emails a ' + selectedBoxes.length + ' facturas seleccionadas?')) {
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
    selectedBoxes.forEach(function(checkbox) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'code[]';
        input.value = checkbox.value;
        form.appendChild(input);
    });

    document.body.appendChild(form);
    form.submit();
}
