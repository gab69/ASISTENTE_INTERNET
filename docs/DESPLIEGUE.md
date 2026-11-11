# Despliegue de ChatCenter en cPanel

Requisitos: PHP 8.0 o superior (cPanel → "MultiPHP Manager" / "Select PHP Version"),
con `pdo_mysql`, `curl` y `mbstring`. `intl` es opcional (fechas en español).

## 1. Crear la base de datos (cPanel)
cPanel → **Bases de datos MySQL**:
1. Crea una base de datos **vacía**.
2. Crea un usuario.
3. Agrega el usuario a la base de datos con **TODOS LOS PRIVILEGIOS**.

No hace falta importar nada en phpMyAdmin: el asistente lo hace.

## 2. Subir los archivos
1. cPanel → **Administrador de archivos** → `public_html`.
2. Sube `chatcenter_cpanel.zip` y **Extraer**.
3. Verifica que `index.php`, `api/`, `setup/`, `config/`… quedaron directamente dentro de `public_html`.
4. Las carpetas `config/`, `logs/`, `views/assets/files/` y `views/assets/ws/` deben tener permisos **755**.

## 3. Configurar desde el navegador
Abre `https://tudominio.com/` → te lleva solo a `https://tudominio.com/setup/`.
1. Escribe los datos de la base de datos y pulsa **Probar conexión**.
2. Elige **Instalación nueva** (recomendada) y escribe el correo y la contraseña del superadministrador.
3. Revisa el token del webhook de WhatsApp, el modelo de OpenAI y la zona horaria.
4. Pulsa **Instalar** → **Ir al panel** e inicia sesión.

Las llaves de seguridad (`api_key`, `jwt_secret`) se generan solas.
Todo se guarda en un único archivo: `config/config.php` (protegido, no accesible desde el navegador).

## 4. Cambiar la configuración después
En el panel, como superadministrador: menú lateral → **Configuración** (o `https://tudominio.com/setup/`).

¿Perdiste el acceso o cambiaste la contraseña de la base de datos?
Elimina `config/config.php` desde el Administrador de archivos y vuelve a abrir el sitio;
elige **Conservar la base de datos actual** para no perder datos.

## 5. WhatsApp (Meta for Developers)
- URL de devolución de llamada: `https://tudominio.com/webhook/`
- Token de verificación: el que aparece en `/setup/`.

## Problemas comunes
- **"No se pudo conectar con la API"**: si el SSL del dominio aún no está emitido, en `/setup/` →
  Avanzado → desactiva "Verificar el certificado SSL".
- **Errores**: `logs/php_error.log` (CMS) y `logs/api_error.log` (API).
  Para verlos en pantalla, activa temporalmente "Mostrar errores" en `/setup/`.

## Si aparece "500 Internal Server Error"

1. cPanel → **Métricas → Errores** muestra la causa exacta.
2. Si dice `... not allowed here` el problema es un `.htaccess`. Los incluidos
   solo usan `mod_rewrite` y `mod_headers` (probados con `AllowOverride FileInfo`).
   Asegúrate de haber reemplazado TODOS los `.htaccess` del ZIP viejo.
3. Si al extraer se perdió la línea de PHP que añade cPanel, ve a
   **MultiPHP Manager**, elige el dominio y selecciona PHP 8.1 o superior → Aplicar.
4. Permisos: carpetas 755 y archivos 644 (nunca 777, suPHP da 500).

## Asistente de IA (ChatGPT o Claude)

/setup/ → **Asistente de inteligencia artificial**:

- Proveedor: ChatGPT (OpenAI) o Claude (Anthropic), con tu propia llave (API key).
- Modelo: vacío = predeterminado (`gpt-4o-mini` / `claude-opus-5-5`). Para Claude más
  económico usa `claude-haiku-5-5`.
- Instrucciones: si se deja vacío se usa el prompt activo de la tabla *Prompts*.
- Botón **Probar IA** para verificar la llave antes de guardar.
- La IA responde solo a los contactos con el icono del robot activado en el chat.

## Mensajes que "se envían" pero no llegan

Cada mensaje del negocio muestra su estado real según WhatsApp:
✓ enviado · ✓✓ entregado · ✓✓ azul leído · ⚠ rojo = no entregado (con el motivo).

Causas más comunes que ahora aparecen escritas en el chat:
- **131047**: pasaron más de 24 h desde el último mensaje del cliente (solo plantillas).
- **131030**: modo de prueba de Meta; agrega el número en *Configuración de la API*.
- **190**: el token de WhatsApp venció; usa un token permanente (usuario del sistema).

Para recibir los estados, en Meta suscribe el webhook al campo **messages**.

## Actualizar a una versión nueva

Sube y reemplaza los archivos **sin borrar `config/`** (ahí está `config.php`).
Las columnas e índices nuevos de la base de datos se aplican solos en la primera visita.

## Novedades: Mi negocio, Agenda y acceso

Al subir esta versión, en la primera visita se crean solas las tablas `settings` y
`appointments` y aparecen en el menú **Agenda** y **Mi negocio**.

### Inicio de sesión
- **¿Olvidaste tu contraseña?** envía un enlace seguro al correo (vence en 60 min y sirve una sola vez).
  El correo sale desde `noreply@tudominio.com`; si no llega, crea esa cuenta en cPanel → Correo
  o pide al superadministrador que cambie la contraseña desde **Admins**.
- **Cambiar mi contraseña**: con el correo y la contraseña actual, sin entrar al panel.

### Mi negocio (lo lee la IA en cada respuesta)
- **Empresa**: nombre, horario, dirección, redes, preguntas frecuentes.
- **Contactos**: números por área (ventas, soporte…).
- **Pagos y QR**: sube el QR (JPG/PNG) de cada método. Cuando el cliente va a pagar, la IA
  envía el QR con el **monto exacto** en el mensaje.
- **Imágenes**: menú, catálogo, mapa… con una clave y una descripción de cuándo enviarlas.
- **Agenda**: días, horario, descanso, duración del turno, citas por turno y el
  **número que recibe las citas** por WhatsApp.

### Agenda
- La IA solo ofrece turnos libres y el servidor vuelve a verificar antes de guardar:
  nunca se agendan dos citas en el mismo turno (con "Citas por turno" = 1).
- Cada cita guarda el resumen de lo que quiere el cliente y se avisa al número configurado
  con un enlace **Agregar a Google Calendar**.
- Importante (regla de Meta): el número que recibe los avisos debe haber escrito a tu
  WhatsApp Business en las últimas 24 h. Escríbele "hola" desde ese número cada día o
  reenvía el aviso desde la Agenda con el botón **Reenviar**.
- Desde **Agenda** puedes crear, editar, marcar como atendida o cancelar citas.

## Novedades: Consultas al equipo, Bots guiados y Configuración

Al subir esta versión, en la primera visita se crea la tabla `inquiries`, la página **Bots**
pasa a ser un editor guiado y los nombres del sistema quedan en español
(Admins → Administradores, API-WS → WhatsApp, Prompts → Instrucciones IA).
Solo se cambian los nombres que no habías personalizado.

### Consultas al equipo (Mi negocio → Consultas)
- Activa la opción y escribe el **número que responde las consultas** (distinto al del negocio).
- Si un cliente pregunta algo que no está en Mi negocio, la IA no inventa: le dice que va a
  consultar y te escribe por WhatsApp con un número, por ejemplo **#12**.
- Responde **citando ese mensaje** o escribiendo **#12 tu respuesta**. Si solo hay una consulta
  pendiente, basta con escribir la respuesta.
- El cliente recibe la respuesta y queda guardada en **Respuestas aprendidas**: la IA la usa
  cuando otro cliente pregunte algo parecido. Desde ahí puedes editarlas, pausarlas o agregar nuevas.
- Misma regla de 24 h de Meta: ese número debe haber escrito al WhatsApp del negocio en las
  últimas 24 h. Si el aviso no llega, la consulta aparece en el panel con **Reenviar aviso**
  y también se puede responder desde ahí.

### Bots
- Cada tarjeta es un paso del recorrido (Bienvenida → Pedido → Datos de entrega →
  Confirmación y pago → Otros caminos) y explica **cuándo se envía** y **para qué sirve**.
- El editor muestra una **vista previa como en WhatsApp**, contadores de caracteres y
  límites de WhatsApp (3 botones, 10 opciones de lista).
- En los menús, cada opción se configura con «Al elegirla»: ver productos de una categoría,
  terminar el pedido o hablar con una persona.

### Páginas y configuración
- **Agregar página**: selector de íconos con categorías y búsqueda en español, ícono y URL
  sugeridos a partir del nombre y tarjetas para elegir el tipo de página.
- **Configuración del sistema** (`/setup/`): secciones con menú lateral, botones para copiar
  la URL y el token del webhook, y barra de guardado fija que avisa si hay cambios sin guardar.


## Novedades: Soles, Chat permanente, Órdenes, Delivery con cobertura y Google Calendar

Al subir esta versión, en la primera visita se aplica la actualización v5 de la base de datos
(columnas nuevas en `orders` y `appointments`, tabla `coverage_checks`). No se borra ningún dato.

### Configuración del sistema por módulos (`/setup/`)
- Cada módulo se ve por separado: **Base de datos**, **WhatsApp**, **Asistente de IA**,
  **General** y **Avanzado**, con su estado (conectado / falta configurar) en el menú.
- **General → Moneda**: por defecto **Soles peruanos (S/)**. Se usa en Órdenes, en el catálogo
  que ve la IA, en los mensajes de pago (QR) y en los bots. La zona horaria por defecto es
  `America/Lima` (si ya tenías otra guardada, cámbiala ahí).
- Diagnóstico (mostrar errores, registro del webhook) quedó en **Avanzado**.

### Chat
- Rediseñado tipo WhatsApp: lista con búsqueda por nombre, número **o texto de los mensajes**,
  filtros *Todos / Sin responder / Con IA*, y panel lateral con los **pedidos y citas** del cliente.
- **El historial es permanente**: se cargan los últimos mensajes y con el scroll (o el botón
  *Cargar mensajes anteriores*) se ve toda la conversación desde el inicio. Se quitaron los
  botones que borraban mensajes o contactos.
- La hora de cada mensaje ya no cambia cuando WhatsApp actualiza su estado (✓ ✓✓).
- Se quitó del menú la sección **Mensajes/Chats** (tabla cruda, repetía el Chat).
  Los mensajes siguen guardados en la base de datos.

### Órdenes
- Página nueva con los pedidos en tarjetas: productos, cantidades, subtotal, envío y **total en soles**,
  dirección con enlace al mapa, cobertura y distancia, forma de pago y notas.
- Filtros por estado (Pendiente → Confirmado → En preparación → En camino → Entregado / Cancelado),
  periodo y búsqueda. Se actualiza sola cada 20 s y avisa cuando llega un pedido.
- Opción **Avisar al cliente** por WhatsApp al cambiar el estado.
- La IA registra los pedidos con los **precios del catálogo** (Productos), nunca inventados.
  También los registra el bot de pedidos.

### Delivery y cobertura (Mi negocio → Delivery)
- Configura la ubicación del local (botón **Ubicar** o coordenadas), el **radio de reparto** en km
  y/o los **distritos con reparto**, zonas sin reparto, costo de envío, envío gratis y pedido mínimo.
- La IA (y el bot) piden **primero la dirección o la ubicación 📍** y la verifican en el mapa
  **antes** de pedir nombre, teléfono o pago. Si no hay cobertura lo dicen de inmediato y ofrecen
  recojo en tienda, sin pedir más datos.
- Mapas: OpenStreetMap (gratis) o Google Maps si pegas una llave de Geocoding API.
- **Número que recibe los pedidos**: le llega por WhatsApp el detalle de cada pedido nuevo.

### Pagos con QR (Yape, Plin…)
- Si el cliente pide "pásame el QR de Yape", ahora se envía la **imagen del QR** con el número y el
  titular (y el monto si se conoce). Funciona aunque la IA no lo indique y también si el QR
  se subió en **Imágenes** en lugar de **Pagos y QR**.
- El QR debe ser JPG o PNG y el sitio debe tener **HTTPS** (WhatsApp descarga la imagen desde tu dominio).

### Agenda y reservas
- Las reservas confirmadas por la IA **siempre** se guardan: entiende fechas y horas escritas de
  cualquier forma (20/11, mañana, 8 pm) y, si la IA olvida la etiqueta, el sistema extrae los datos
  de la conversación. También guarda el **número de personas**.
- El bot de **Reservar mesa** (sin IA) ahora registra la solicitud en la Agenda como **Pendiente**
  para que la confirmes con un clic.
- Si la agenda está desactivada aparece un aviso: actívala en **Mi negocio → Agenda**.

### Google Calendar (Agenda → botón Google Calendar)
- **Automática (recomendada)**: cada cita nueva, editada o cancelada aparece al instante en tu
  Google Calendar y en tu celular, con recordatorio. Requiere una *cuenta de servicio* de Google Cloud
  (los pasos están en la ventana): activar Google Calendar API, descargar el JSON, compartir tu
  calendario con el correo de la cuenta de servicio y pegar el ID del calendario.
- **Por enlace**: suscríbete al enlace privado `.ics` desde Google Calendar (*Otros calendarios →
  Desde URL*). No requiere Google Cloud, pero Google lo actualiza cada pocas horas.
- Requiere la extensión **OpenSSL** de PHP (activa por defecto en cPanel).

## Novedades: Sucursales e Internet/TV con instalación

Al subir esta versión se aplica la actualización v6 de la base de datos (sucursal en cobertura,
órdenes y citas; vínculo orden ↔ instalación; DNI del titular). No se borra ningún dato.

### Mi negocio → Servicio
- Elige **Internet y TV con instalación** (o *Pedidos a domicilio*).
- Los **planes y precios mensuales** se cargan en **Productos** (ej.: categoría *Planes de internet*).
- **Costo de instalación** (pago único) y **requisitos** (ej.: DNI del titular).
- **TV abierta con TV box**: precio mensual de la TV y precio del TV box. La IA pregunta si el cliente
  ya tiene TV box: si lo tiene no se cobra; si no, se suma al pago inicial (0 = no lo vendemos).

### Mi negocio → Cobertura (sucursales)
- Agrega cada sucursal con su dirección (botón **Ubicar**), radio en km y/o distritos, zonas sin
  cobertura y su **WhatsApp**.
- Con la dirección o la ubicación 📍 del cliente, el sistema mide la distancia a **todas** las sucursales
  y asigna **la más cercana que tenga cobertura**. Si ninguna llega, responde cuál es la más cercana
  y por qué no cubre, sin pedir más datos. Con **Probar una dirección** ves la comparación completa.
- **WhatsApp central de órdenes**: recibe **todas** las órdenes. La sucursal asignada también recibe
  las suyas en su número.

### Flujo de venta (IA)
1. Plan → 2. dirección y verificación de cobertura → 3. TV abierta / TV box → 4. nombre y DNI →
5. día y hora de instalación (turnos libres de la Agenda) → 6. confirmación.
Al confirmar se crean **la orden y la cita de instalación vinculadas**, y se envía un solo mensaje con
todo: plan, TV, **mensualidad**, **pago inicial**, sucursal, DNI, dirección y fecha de instalación
(con enlace para agregarla a Google Calendar).

### Agenda de instalaciones
- Usa los horarios de **Mi negocio → Agenda**. *Citas por turno* = cuántas cuadrillas de técnicos
  pueden instalar a la vez. *Duración del turno* = tiempo de una instalación (ej.: 120 min).
- La agenda es una sola para todas las sucursales (los turnos se comparten).
- Cada cita muestra la sucursal asignada (también en Google Calendar).

### Importante (regla de Meta)
WhatsApp solo deja enviar mensajes libres a números que escribieron al WhatsApp del negocio en las
últimas 24 h. El número central y los de las sucursales deben escribir "hola" al número del negocio
cada día; si no, la orden igual queda guardada en **Órdenes** y se puede reenviar con el botón de WhatsApp.

## Instalación con datos de ejemplo de internet

- **Instalación nueva**: planes de internet (Fibra 200/400/600/1000 Mbps, Dúo + TV, TV abierta, TV box,
  repetidor WiFi), bots y modo *Internet y TV con instalación*, sin datos de prueba.
- **Con datos de ejemplo**: además empresa *Fibra Demo*, 3 sucursales en Lima, pagos Yape/Plin/BCP y
  conversaciones con fechas actuales: **Carlos Mendoza** con la instalación agendada (orden #1 vinculada,
  Sede Norte), **María Torres** sin cobertura y **Rosa Quispe** esperando respuesta.
- **Reinstalar desde cero**: borra todas las tablas y deja la instalación de ejemplo (hay que escribir BORRAR).
- Al instalar solo queda el superadministrador que escribes (no se crean usuarios de prueba).
- Error `Access denied ... (using password: NO)`: falta la contraseña de la base de datos en
  `config/config.php` (`'db_pass' => '...'`). Edítalo desde el Administrador de archivos.

## Confirmar zonas de cobertura (Mi negocio → Cobertura)
- Mapa con cada sucursal (marcador arrastrable para corregir su ubicación) y su radio de cobertura.
- **Clic en cualquier punto del mapa**: dice si hay cobertura y qué sucursal atiende, con la
  configuración que está en pantalla (aunque aún no la guardes).
- **Confirmar zonas**: guarda, ubica en el mapa cada distrito escrito, muestra su distancia a la sucursal
  y marca los que no se encuentran o están lejos. Queda registrado quién y cuándo confirmó; si después
  cambias una sucursal, el aviso pide confirmar de nuevo.
- Las búsquedas en el mapa se guardan 30 días en `config/geo_cache.json` (respuestas más rápidas).

## Ubicación exacta de las sucursales (latitud y longitud)

- Cada sucursal se ubica solo con **latitud y longitud** (ya no por dirección escrita, que podía quedar a cientos de metros o desactualizada).
- **Usar mi ubicación actual**: estando en el local, toma varias lecturas del GPS durante unos segundos y se queda con la más precisa (muestra ±metros). Requiere entrar al panel con **https://** y dar permiso de ubicación. Mejor desde el celular.
- **Marcar en el mapa**: el siguiente clic en el mapa fija la sucursal (usa la vista Satélite y acerca el zoom).
- Pegar en Latitud un par `-12.04, -77.03` o un enlace de Google Maps completa ambos campos (toma el punto exacto del lugar).
- Todo cambio de ubicación (GPS, mapa, arrastre del marcador, pegado o al salir del campo) **se guarda al instante** y el mapa se mueve a ese punto.
- "Probar cobertura en mi ubicación actual" verifica el punto donde estás.

## Avisos de órdenes y citas, Inicio, límites y cierre automático (v9)

- **Avisos**: cada orden, cita, reprogramación y cancelación se envía al *WhatsApp central* (Mi negocio → Cobertura), al número de *Agenda* y al *WhatsApp de la sucursal*. Antes las citas solo iban al número de Agenda (si estaba vacío no llegaban) y la cita agendada después de la orden no se avisaba.
- **Ventana de 24 h**: WhatsApp solo entrega texto libre si ese número escribió al bot en las últimas 24 h. Solución: Configuración → WhatsApp → *Plantilla para avisos* (categoría Utilidad, cuerpo `📢 Nuevo aviso de ventas: {{1}}. Revisa el panel para ver el detalle.`). Si Meta rechaza un aviso (131047) se reenvía solo con la plantilla. Los números del equipo no reciben respuestas de la IA.
- **Órdenes**: solo Activa → Completada (sola, pasada la instalación) o Cancelada (a mano). Botones *Reprogramar/Agendar*, *Cancelar* y *Reenviar aviso*. Cada orden muestra a quién se avisó y si llegó.
- **Inicio**: consumo de tokens de IA y de la API de WhatsApp (con gasto estimado) y gráficos de mensajes recibidos/respondidos/enviados, órdenes generadas/rechazadas y conversaciones solo de consulta.
- **Configuración → Límites y consumo**: mensajes por minuto (por cliente y total), tope de tokens por día/mes, precios para estimar el gasto y cierre amable de la conversación después de una orden.
- **Cron (recomendado)**: cPanel → Trabajos de cron, cada 5 minutos, con el comando que aparece en Configuración → Límites y consumo. Sin cron las tareas corren con el tráfico (máx. 1 vez por minuto).

## Flujo de venta de Anyela, datos de la orden y menú (v10)

- **Personalidad**: la instrucción «Anyela · Asesora de Fibra Demo» queda activa en *Instrucciones IA* (la anterior se conserva desactivada). Si en Configuración → Asistente de IA hay texto en «Instrucciones», ese texto tiene prioridad: déjalo vacío.
- **Flujo**: saludo con nombre y empresa → interés → ubicación en tiempo real / enlace de Maps / dirección → planes de esa zona → datos obligatorios (nombres y apellidos, DNI, correo, dirección, referencia, uno o más teléfonos) + foto opcional del recibo de luz → turno de instalación → confirmación.
- Si falta un dato obligatorio la orden NO se registra (ni la cita) y la IA pide solo lo que falta.
- La orden guarda el enlace de ubicación que envió el cliente (pin de WhatsApp o Google Maps, también enlaces cortos) y las fotos que envió (se descargan al servidor).
- **Planes por sucursal**: Mi negocio → Cobertura → cada sucursal → «Planes y servicios disponibles en esta zona».
- **Menú**: Configuración → Menú (ordenar, renombrar, cambiar ícono, ocultar, borrar y agregar páginas). La primera página visible es la de inicio del panel.

## Pago presencial y cancelación por el cliente

- Mi negocio → Servicio: «El pago se realiza en persona el día de la instalación» (activo por defecto). La IA lo menciona y solo muestra los medios de Pagos y QR si el cliente los pide.
- «El cliente puede cancelar su orden hasta N horas antes» (4 por defecto). Dentro del plazo la IA cancela la orden y su instalación con [[CANCELAR_ORDEN|número|motivo]] y se avisa al WhatsApp central (y sucursal). Fuera del plazo no se cancela: el cliente recibe la explicación y el equipo un aviso «fuera de plazo».

## Saludo corto y números por área

- **Saludo corto:** el primer mensaje de la asistente es una sola línea con su nombre y una pregunta ("¡Hola! 👋 Soy Anyela, asesora de Fibra Demo. ¿Te interesa contratar internet o algún servicio en particular?"). En el saludo no se describe la empresa ni se listan planes. La instrucción activa se actualiza sola al subir la versión (esquema 12).
- **Mi negocio › Números por área:** un celular por área (Ventas, Soporte técnico, Pagos…) con el texto "¿Para qué sirve este número?". Para cada número se marca:
  - *La asistente lo comparte con clientes:* la IA da ese número solo cuando la consulta corresponde a esa área.
  - *Recibe órdenes nuevas / instalaciones-citas / cancelaciones:* esos avisos llegan también a ese WhatsApp, además del número central de Cobertura y del WhatsApp de la sucursal.
- Los números por área se tratan como del equipo: si escriben al bot, la IA no les responde como a un cliente.

## Nuevo diseño: Cobertura y Inicio

- **Mi negocio › Cobertura:** arriba, totales (sucursales, ubicadas, distritos, con WhatsApp propio) y el estado de las zonas. A la izquierda, las sucursales como tarjetas plegables con un resumen en chips (ubicada, radio, distritos, planes, WhatsApp). Al pulsar **Editar**, cada sucursal se edita por pestañas: *Ubicación*, *Cobertura* (radio con deslizador, distritos y zonas sin cobertura), *Planes* y *Contacto*, con **Guardar sucursal** y **Eliminar sucursal**. A la derecha, el mapa fijo (clic en un marcador o en la leyenda abre esa sucursal) y la herramienta **Probar cobertura** (dirección, enlace de Maps, mi ubicación o clic en el mapa). Abajo, los ajustes generales.
- **Inicio:** periodo con botones (7/14/30/90 días); cuatro indicadores principales (conversaciones, órdenes, tasa de respuesta, órdenes rechazadas) con mini tendencia y comparación con el periodo anterior; mensajes por día (el día de hoy va punteado porque aún no termina); reparto de las conversaciones (con orden / solo consulta / sin cobertura); órdenes por día; próximas instalaciones; y la sección de consumo y límites.

## Flujo sin interrupciones y recibo de luz o agua

- **Fotos, PDF y audios con la IA activa:** los atiende siempre la asistente (antes una foto activaba el bot de "archivos" y la conversación se cortaba). Los audios no se pueden escuchar: la asistente pide que lo escriba y sigue en el mismo paso.
- **Mensajes seguidos:** se espera unos segundos (Configuración → Límites → *Espera antes de responder*, por defecto 4) y se responde una sola vez a todo lo escrito. Si llega otro mensaje mientras la IA piensa, se responde ese último (sin respuestas cruzadas).
- **Reintentos de la IA:** ante saturación o caídas pasajeras (429, 5xx, 529, sin red) se reintenta hasta 2 veces. Si aun así falla, el cliente recibe un aviso amable (máximo uno cada 10 minutos) en vez de silencio.
- **Números del equipo:** reciben respuesta de la IA como cualquier cliente (sirve para probar con el propio celular); solo se ignora cuando responden citando un aviso de orden o cita.
- **Recibo de luz o agua:** la asistente lo pide siempre junto con los datos de contratación (opcional), lo recuerda una vez antes del resumen si no llegó y nunca detiene la orden por él. Se acepta foto o PDF y aparece en la orden.
