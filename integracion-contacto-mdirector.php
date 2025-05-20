<?php
/*
Plugin Name: Integración Contacto MDirector
Description: Envía datos del formulario a MDirector usando usuario, contraseña y client_id (sin client_secret)
Version: 2.5
Author: Visualit
*/

// Añade una nueva página al menú de administración para configurar los datos de acceso a MDirector
add_action('admin_menu', function () {
    add_menu_page(
        'MDirector', 
        'MDirector', 
        'manage_options', 
        'mdirector-ajustes', 
        'visualit_mdirector_opciones_page', 
        'dashicons-email-alt' 
    );
});

// Registra las opciones de usuario y contraseña para que puedan guardarse en la base de datos
add_action('admin_init', function () {
    register_setting('mdirector_opciones', 'mdirector_username');
    register_setting('mdirector_opciones', 'mdirector_password');
});

// Función que renderiza la página de ajustes del plugin
function visualit_mdirector_opciones_page() {
    $mensaje = '';
    $clase = '';
     // Si se pulsa el botón para probar la conexión, se intenta obtener el token de MDirector
    if (isset($_POST['visualit_test_mdirector_token']) && check_admin_referer('visualit_test_token')) {
        $username = get_option('mdirector_username');
        $password = get_option('mdirector_password');

        $response = wp_remote_post('https://app.mdirector.com/oauth2', [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type' => 'password',
                'client_id'  => 'webapp',
                'username'   => $username,
                'password'   => $password,
            ],
            'timeout' => 15,
        ]);
        // Muestra el resultado de la prueba de conexión
        if (is_wp_error($response)) {
            $mensaje = '❌ Error de conexión: ' . $response->get_error_message();
            $clase = 'error';
        } else {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($body['access_token'])) {
                $mensaje = '✅ Conexión correcta. Token válido: <code>' . esc_html($body['access_token']) . '</code>';
                $clase = 'updated';
            } else {
                $mensaje = '❌ Error al obtener token: ' . esc_html($body['error_description'] ?? 'Respuesta no válida');
                $clase = 'error';
            }
        }
    }
    // HTML de la página de ajustes
    ?>
    <div class="wrap">
        <h1>Ajustes de conexión MDirector</h1>
        <?php if ($mensaje): ?>
            <div class="<?php echo esc_attr($clase); ?> notice is-dismissible">
                <p><?php echo $mensaje; ?></p>
            </div>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields('mdirector_opciones'); ?>
            <?php do_settings_sections('mdirector_opciones'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Usuario</th>
                    <td><input type="text" name="mdirector_username" value="<?php echo esc_attr(get_option('mdirector_username')); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row">Contraseña</th>
                    <td><input type="password" name="mdirector_password" value="<?php echo esc_attr(get_option('mdirector_password')); ?>" class="regular-text" /></td>
                </tr>
            </table>
            <?php submit_button('Guardar cambios'); ?>
        </form>

        <hr>

        <form method="post">
            <?php wp_nonce_field('visualit_test_token'); ?>
            <input type="submit" name="visualit_test_mdirector_token" class="button button-secondary" value="Probar conexión con MDirector">
        </form>
    </div>
    <?php
}
// Hook que se dispara cuando se envía un formulario de Contact Form 7
add_action('wpcf7_mail_sent', 'visualit_enviar_a_mdirector_contacto');

function visualit_enviar_a_mdirector_contacto($contact_form) {
    $form_id_objetivo = 1159; // ID del formulario específico que debe activar el envío a MDirector
    $log_file = WP_CONTENT_DIR . '/mdirector_log.txt'; // Ruta del archivo de log para debug

    if ($contact_form->id() != $form_id_objetivo) return;

    $submission = WPCF7_Submission::get_instance();
    if (!$submission) return;

    $data = $submission->get_posted_data();
    if (empty($data)) return;
    // Recoge y limpia los datos del formulario
    $email     = sanitize_email($data['your-email'] ?? '');
    $nombre    = sanitize_text_field($data['your-name'] ?? '');
    $apellidos = sanitize_text_field($data['last-name'] ?? '');
    $telefono  = sanitize_text_field($data['your-phone'] ?? '');
    $empresa   = sanitize_text_field($data['company'] ?? '');
    $mensaje   = sanitize_textarea_field($data['message'] ?? '');

    if (empty($email)) return;
    // Recupera credenciales guardadas en el panel de ajustes
    $username = get_option('mdirector_username');
    $password = get_option('mdirector_password');
    // Solicita el token de acceso
    $token_response = wp_remote_post('https://app.mdirector.com/oauth2', [
        'headers' => [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        'body' => http_build_query([
            'grant_type' => 'password',
            'client_id'  => 'webapp',
            'username'   => $username,
            'password'   => $password,
        ]),
        'timeout' => 15,
    ]);
     // Si hay error al obtener el token, registra el error
    if (is_wp_error($token_response)) {
        error_log('[ERROR TOKEN] Error WP: ' . $token_response->get_error_message());
        return;
    }

    $token_data = json_decode(wp_remote_retrieve_body($token_response), true);
    $access_token = $token_data['access_token'] ?? null;

    if (!$access_token) {
        error_log('[ERROR TOKEN] Respuesta inválida: ' . wp_remote_retrieve_body($token_response));
        return;
    }
     // Construye los datos para enviar a MDirector
    $datos_enviados = [
        "email" => $email,
        "list" => "01_Solicitud_Info_Web", // Nombre de la lista en MDirector
        "fields" => [
            "name"     => $nombre,
            "surname"  => $apellidos,
            "phone"    => $telefono,
            "company"  => $empresa,
            "comments" => $mensaje
        ]
    ];
    // Envía los datos a la API de MDirector
    $response = wp_remote_post('https://www.mdirector.com/api_contact', [
        'headers' => [
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type'  => 'application/json',
        ],
        'body' => json_encode($datos_enviados),
        'timeout' => 15,
    ]);
    // Registra en el log los datos enviados y la respuesta de MDirector
    $log = "[MDirector] [" . date('Y-m-d H:i:s') . "]\n";
    $log .= "Token usado: $access_token\n";
    $log .= "Datos enviados:\n" . print_r($datos_enviados, true) . "\n";
    $log .= "Respuesta:\n" . print_r($response, true) . "\n";

    file_put_contents($log_file, $log . "\n", FILE_APPEND);
}
