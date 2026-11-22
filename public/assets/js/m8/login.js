/*
 * RoutePulse - m8/login.js
 * Envía el formulario de acceso a POST auth/login.php y redirige a la página de inicio del rol.
 */
(function () {
    'use strict';

    var formulario = document.getElementById('form-login');
    if (!formulario) { return; }

    formulario.addEventListener('submit', async function (evento) {
        evento.preventDefault();

        var boton = formulario.querySelector('button[type="submit"]');
        var email = formulario.elements.email.value.trim();
        var password = formulario.elements.password.value;   // la contraseña NO se recorta

        if (email === '' || password === '') {
            window.ui.toast('Escribe tu correo y tu contraseña.', 'warning');
            return;
        }

        window.ui.bloquear(boton, true, 'Entrando…');
        try {
            var datos = await window.api.post('auth/login.php', { email: email, password: password }, { formulario: formulario });
            window.location.href = datos.redirigir_a;
        } catch (error) {
            // api() ya mostró el aviso; se limpia la contraseña para reintentar.
            formulario.elements.password.value = '';
            formulario.elements.password.focus();
            window.ui.bloquear(boton, false);
        }
    });
})();
