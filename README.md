# integracion-mdirector: Envía datos del formulario a MDirector usando usuario, contraseña y client_id (sin client_secret)

# Integración MDirector - Visualit

Este plugin personalizado de WordPress realiza dos funciones principales:

- **Panel de administración para configurar credenciales**
- Permite guardar usuario y contraseña de MDirector desde el wp-admin. Se valida haciendo una
llamada POST a: https://app.mdirector.com/oauth2
Con el cuerpo:
- grant_type: password
- client_id: webapp
- username: usuario
- password: contraseña

NOTA: No requiere client_secret para el client_id=webapp

- **Envío de datos desde Contact Form 7**
- Cuando un formulario se envía, el plugin:
- Extrae los campos del form
- Solicita un access_token
- Si es válido, construye un payload y lo envía a: https://www.mdirector.com/api_contact
- Guarda los datos y la respuesta

