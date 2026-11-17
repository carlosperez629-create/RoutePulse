/*
 * RoutePulse - m8/cambiar_password.js
 * Cambia la contraseña propia con POST auth/cambiar_password.php.
 */
(function () {
    'use strict';

    var formulario = document.getElementById('form-password');
    if (!formulario) { return; }

    formulario.addEventListener('submit', async function (evento) {
        evento.preventDefault();

        var boton = formulario.querySelector('button[type="submit"]');
        var actual = formulario.elements.password_actual.value;
        var nueva = formulario.elements.password_nueva.value;
        var confirmar = formulario.elements.password_confirmar.value;

        window.ui.limpiarErrores(formulario);
        if (actual === '' || nueva === '' || confirmar === '') {
            window.ui.toast('Completa los tres campos.', 'warning');
            return;
        }
        if (nueva !== confirmar) {
            window.ui.marcarErrores(formulario, { password_confirmar: 'Las contraseñas no coinciden.' });
            return;
        }

        window.ui.bloquear(boton, true, 'Guardando…');
        try {
            var datos = await window.api.post(
                'auth/cambiar_password.php',
                { password_actual: actual, password_nueva: nueva },
                { formulario: formulario }
            );
            window.ui.toast('Contraseña actualizada.', 'success');
            window.setTimeout(function () { window.location.href = datos.redirigir_a; }, 800);
        } catch (error) {
            window.ui.bloquear(boton, false);
        }
    });
})();
