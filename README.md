# Panel de Control LPR (Reconocimiento de Matrículas) en PHP

# PHP LPR Control Panel - Sistema de Gestión de Parking y Matrículas

Un panel de control LPR simple pero funcional escrito en PHP, diseñado para recibir eventos de cámaras de reconocimiento de matrículas y proporcionar una interfaz para su gestión.

![image](https://github.com/user-attachments/assets/2568973d-d611-4c8a-9e81-d8578ffd33f5)

## Funcionalidades

*   Visualización de eventos LPR en tiempo real.
*   Gestión de Lista Blanca (con datos de propietario y última lectura).
*   CRUD completo para Propietarios y Dispositivos (cámaras).
*   Asignación de matrículas a propietarios y propietarios a lotes de parking.
*   Historial de sesiones de parking (entradas/salidas).
*   Búsqueda avanzada en el historial de eventos con paginación.
*   Obtención de snapshots de cámaras Hikvision (vía ISAPI).
*   Exportación de datos a CSV e importación de matrículas.
*   Interfaz de usuario limpia y responsiva.
*   Todo basado en archivos de texto para una fácil configuración y sin dependencias de bases de datos complejas.

## Configuración

1.  **Servidor Web:** Asegúrate de tener un servidor web con PHP habilitado (ej. Apache, Nginx).
2.  **Permisos:** El servidor web necesita permisos de escritura para los archivos `.txt` en el directorio de la aplicación y para el directorio `uploads/` (y sus subdirectorios `owner_photos/`, `maps/`, `plate_images/`).
3.  **Webhook de Cámara:**
    *   Copia `webhook_hikvision.php` a un directorio accesible por tu cámara en tu servidor web.
    *   Configura tu cámara LPR para enviar notificaciones HTTP POST (eventos de detección de vehículos) a la URL de `webhook_hikvision.php`. Asegúrate de que la cámara envíe los datos en formato `multipart/form-data` incluyendo un XML con los detalles del evento y la imagen de captura.
    *   Ajusta la constante `WEBHOOK_LIVE_EVENTS_FILE` en `webhook_hikvision.php` si es necesario (aunque por defecto debería funcionar si está en el mismo directorio que `index.php`).
4.  **Archivos de Datos:** Los archivos `.txt` necesarios se crearán automáticamente si no existen.
5.  **Acceso:** Abre `index.php` en tu navegador.

## Estructura de Archivos

*   `index.php`: Lógica principal del panel de control y la interfaz de usuario.
*   `webhook_hikvision.php`: Receptor de eventos de las cámaras.
*   `live_events.txt`: Almacena los eventos LPR más recientes.
*   `whitelist.txt`, `devices.txt`, `owners.txt`, etc.: Archivos de datos.
*   `uploads/`: Directorio para imágenes de mapas y fotos de propietarios.
*   `plate_images/`: Directorio para las imágenes de captura de matrículas (creado por el webhook).

## Contribuciones

¡Las contribuciones son bienvenidas! Por favor, abre un issue o un pull request. Áreas de posible mejora:
*   Migración a SQLite para mejor rendimiento.
*   Autenticación de usuarios para el panel.
*   Integración ISAPI más profunda (gestión de listas en la cámara).
*   Pruebas unitarias.

---



![image](https://github.com/user-attachments/assets/e210619b-ecc7-47d6-85af-8c0f95d6e866)

![image](https://github.com/user-attachments/assets/7783a466-44d6-414e-bfaa-4debcbbd1bfb)
