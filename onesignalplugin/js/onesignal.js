document.addEventListener('DOMContentLoaded', function() {
    // Inicializa Select2 en el campo de categorías
    jQuery('.select2').select2({
        placeholder: "Select categories",
        allowClear: true
    });
});