<?php
// --- CONFIGURACIÓN ---
$logFile = __DIR__ . '/hikvision_lpr.log';
$imageDir = __DIR__ . '/plate_images/'; // Directorio donde se guardan las imágenes capturadas
// WEBHOOK_LIVE_EVENTS_FILE debe apuntar a live_events.txt en el directorio raíz (donde está index.php)
// Si webhook_hikvision.php está en LPR/ y index.php está en LPR/, entonces la ruta es:
define('WEBHOOK_LIVE_EVENTS_FILE', __DIR__ . '/live_events.txt');
// --- FIN CONFIGURACIÓN ---

function write_log($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    if (!is_string($message)) { $message = print_r($message, true); }
    file_put_contents($logFile, "[$timestamp] " . $message . "\n", FILE_APPEND | LOCK_EX);
}

// Modificada para incluir la ruta de la imagen de captura
function add_webhook_event_to_live_file($deviceID, $licensePlate, $imageCapturePath = '') {
    if (empty($deviceID) && empty($licensePlate)) { // Permitir si solo uno está vacío temporalmente durante el debug
        write_log("Advertencia: Intento de añadir evento en vivo con deviceID y/o licensePlate vacíos. Device: [$deviceID], Plate: [$licensePlate]");
        // No retornar si queremos ver estos eventos "parciales" en live_events.txt para depuración.
        // Pero para producción, probablemente querrías:
        // if (empty($deviceID) || empty($licensePlate)) { return; }
    }
    $timestamp = date('Y-m-d H:i:s');
    // Nuevo formato: timestamp|deviceID|licensePlate|imageCapturePath
    $entry = $timestamp . '|' . trim($deviceID) . '|' . strtoupper(trim($licensePlate)) . '|' . trim($imageCapturePath) . PHP_EOL;
    
    $max_lines = 100;
    $current_lines = @file(WEBHOOK_LIVE_EVENTS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($current_lines === false) $current_lines = [];
    
    $new_lines_array = array_slice(array_merge([trim($entry)], $current_lines), 0, $max_lines);
    
    if (file_put_contents(WEBHOOK_LIVE_EVENTS_FILE, implode(PHP_EOL, $new_lines_array) . PHP_EOL, LOCK_EX) === false) {
        write_log("Error escribiendo en WEBHOOK_LIVE_EVENTS_FILE: " . WEBHOOK_LIVE_EVENTS_FILE . " (Permisos?)");
    } else {
        write_log("Evento LPR (".$licensePlate." desde ".$deviceID.", img: ".$imageCapturePath.") añadido a WEBHOOK_LIVE_EVENTS_FILE.");
    }
}

write_log("--- INICIO DE PETICIÓN WEBHOOK (vWithCaptureImagePath) ---");
write_log("IP Remota (Cámara): " . $_SERVER['REMOTE_ADDR']);
write_log("Método HTTP: " . $_SERVER['REQUEST_METHOD']);
write_log("URI Solicitada: " . $_SERVER['REQUEST_URI']);
write_log("Query String: " . (isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : 'N/A'));

$headers = getallheaders();
write_log("Headers Generales Recibidos:\n" . print_r($headers, true));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.0 405 Method Not Allowed'); write_log("Error: Método no permitido."); echo 'Method Not Allowed.';
    write_log("--- FIN DE PETICIÓN WEBHOOK (MÉTODO INCORRECTO) ---"); exit;
}

$channelID = $dateTime = $eventType = $licensePlate = $region = $country = $vehicleType = $vehicleColor = null;
$imageData = null; 
$imagePathOnServer = null; // Ruta completa en el servidor donde se guarda la imagen
$relativePathForWeb = '';  // Ruta relativa para usar en HTML <img>
$xmlDataString = null;

$contentTypeHeader = isset($headers['Content-Type']) ? strtolower($headers['Content-Type']) : (isset($_SERVER['CONTENT_TYPE']) ? strtolower($_SERVER['CONTENT_TYPE']) : '');

if (strpos($contentTypeHeader, 'multipart/form-data') !== false) {
    write_log("Detectado Content-Type multipart/form-data.");
    write_log("\$_POST contenido:\n" . print_r($_POST, true));
    write_log("\$_FILES contenido:\n" . print_r($_FILES, true));

    if (isset($_FILES['anpr_xml']) && $_FILES['anpr_xml']['error'] === UPLOAD_ERR_OK) {
        if (isset($_FILES['anpr_xml']['tmp_name']) && is_uploaded_file($_FILES['anpr_xml']['tmp_name'])) {
            if (strpos(strtolower($_FILES['anpr_xml']['type']), 'xml') !== false) { // Más flexible con text/xml o application/xml
                $xmlDataString = file_get_contents($_FILES['anpr_xml']['tmp_name']);
                write_log("XML encontrado y leído de \$_FILES['anpr_xml']['tmp_name']. Longitud: " . strlen($xmlDataString));
            } else { write_log("Archivo 'anpr_xml' encontrado, pero el tipo no es XML: " . $_FILES['anpr_xml']['type']); }
        } else { write_log("Error con 'anpr_xml': tmp_name no es un archivo subido válido o no existe."); }
    } else { write_log("No se encontró 'anpr_xml' en \$_FILES o hubo un error de subida (Código: " . ($_FILES['anpr_xml']['error'] ?? 'N/A') . ")."); }

    if (isset($_FILES['licensePlatePicture_jpg']) && $_FILES['licensePlatePicture_jpg']['error'] === UPLOAD_ERR_OK) {
        if (isset($_FILES['licensePlatePicture_jpg']['tmp_name']) && is_uploaded_file($_FILES['licensePlatePicture_jpg']['tmp_name'])) {
            if (strpos($_FILES['licensePlatePicture_jpg']['type'], 'image/jpeg') !== false) {
                $imageData = file_get_contents($_FILES['licensePlatePicture_jpg']['tmp_name']);
                write_log("Imagen de matrícula JPEG encontrada en \$_FILES['licensePlatePicture_jpg']. Tamaño: " . strlen($imageData) . " bytes.");
            } else { write_log("Archivo 'licensePlatePicture_jpg' encontrado, pero el tipo no es JPEG: " . $_FILES['licensePlatePicture_jpg']['type']); }
        } else { write_log("Error con 'licensePlatePicture_jpg': tmp_name no es un archivo subido válido o no existe."); }
    } else { write_log("No se encontró 'licensePlatePicture_jpg' en \$_FILES o hubo un error de subida (Código: " . ($_FILES['licensePlatePicture_jpg']['error'] ?? 'N/A') . ")."); }
    
    if ($xmlDataString) {
        write_log("Parseando XML Data String (longitud: ".strlen($xmlDataString)."): " . substr($xmlDataString, 0, 300) . "...");
        // write_log("CONTENIDO COMPLETO DE anpr.xml:\n" . $xmlDataString); // Descomentar para depuración profunda
        try {
            libxml_use_internal_errors(true); $xml = new SimpleXMLElement($xmlDataString); libxml_clear_errors();
            $channelID = isset($xml->channelID) ? (string)$xml->channelID : (isset($xml->EventNotificationAlert->channelID) ? (string)$xml->EventNotificationAlert->channelID : null);
            $dateTime = isset($xml->dateTime) ? (string)$xml->dateTime : (isset($xml->EventNotificationAlert->dateTime) ? (string)$xml->EventNotificationAlert->dateTime : null);
            $eventType = isset($xml->eventType) ? (string)$xml->eventType : (isset($xml->EventNotificationAlert->eventType) ? (string)$xml->EventNotificationAlert->eventType : null);
            $lprNode = null;
            if (isset($xml->ANPR)) { $lprNode = $xml->ANPR; }
            elseif (isset($xml->LPR)) { $lprNode = $xml->LPR; }
            elseif (isset($xml->EventNotificationAlert->ANPR)) { $lprNode = $xml->EventNotificationAlert->ANPR; }
            elseif (isset($xml->EventNotificationAlert->LPR)) { $lprNode = $xml->EventNotificationAlert->LPR; }
            elseif (isset($xml->Plate->licensePlate)) { $lprNode = $xml->Plate; } // Puede estar bajo <Plate><licensePlate>
            elseif (isset($xml->vehicleDetection->Plate->licensePlate)) { $lprNode = $xml->vehicleDetection->Plate;} // Otra estructura posible
            
            if ($lprNode) {
                $licensePlate = isset($lprNode->licensePlate) ? (string)$lprNode->licensePlate : null;
                $region = isset($lprNode->country) ? (string)$lprNode->country : (isset($lprNode->region) ? (string)$lprNode->region : null);
                $country = isset($lprNode->vehicleLicenceCountry) ? (string)$lprNode->vehicleLicenceCountry : $region;
                $vehicleType = isset($lprNode->vehicleType) ? (string)$lprNode->vehicleType : (isset($lprNode->Vehicle->type) ? (string)$lprNode->Vehicle->type : null);
                $vehicleColor = isset($lprNode->vehicleColor) ? (string)$lprNode->vehicleColor : (isset($lprNode->Vehicle->color) ? (string)$lprNode->Vehicle->color : null);
                if(empty($channelID) && isset($lprNode->channelID)) $channelID = (string)$lprNode->channelID;
            } else { 
                $licensePlate = isset($xml->licensePlate) ? (string)$xml->licensePlate : (isset($xml->EventNotificationAlert->licensePlate) ? (string)$xml->EventNotificationAlert->licensePlate : null);
            }
            if(empty($eventType) && isset($xml->EventNotificationAlert->eventType)) $eventType = (string)$xml->EventNotificationAlert->eventType;
            if(empty($eventType) && isset($xml->eventType)) $eventType = (string)$xml->eventType; // A veces el eventType está en la raíz del XML de ANPR
            write_log("Datos de Alarma extraídos del XML.");
        } catch (Exception $e) {
            write_log("Error parseando XML: " . $e->getMessage());
            write_log("XML que causó el error:\n" . $xmlDataString);
        }
    } else { write_log("FINAL (multipart): No se encontró/leyó el archivo XML 'anpr_xml' desde \$_FILES."); }
    if (!$imageData) { write_log("FINAL (multipart): No se encontró/leyó la imagen 'licensePlatePicture_jpg' desde \$_FILES."); }

} elseif (strpos($contentTypeHeader, 'application/xml') !== false || strpos($contentTypeHeader, 'text/xml') !== false) {
    write_log("Detectado Content-Type XML (directo). Procesando cuerpo del POST como XML.");
    $xmlDataString = @file_get_contents('php://input');
    if ($xmlDataString === false) { $xmlDataString = ''; write_log("Error leyendo php://input para XML directo."); }
    if (!empty($xmlDataString)) {
        // ... (Lógica de parseo XML directo, similar a la de arriba) ...
        write_log("Parseo XML directo no completamente implementado en esta rama, revisar si se llega aquí.");
    } else { write_log("Advertencia: Cuerpo del POST XML (directo) vacío."); }
} else {
    write_log("Content-Type no es XML ni multipart. Asumiendo datos de alarma en Query String y/o imagen en cuerpo POST.");
    // ... (Lógica de fallback GET/imagen directa, menos probable) ...
}

write_log(sprintf(
    "Datos Consolidados Finales: Canal=%s, FechaHora=%s, Evento=%s, Placa=%s, Imagen Recibida=%s",
    $channelID ?? 'N/A', $dateTime ?? 'N/A', $eventType ?? 'N/A', $licensePlate ?? 'N/A',
    $imageData ? 'Sí (' . strlen($imageData) . ' bytes)' : 'No'
));

// --- GUARDADO DE IMAGEN Y OBTENCIÓN DE RUTA RELATIVA ---
if ($imageData && !empty($licensePlate) && !empty($eventType) ) {
    if (!is_dir($imageDir)) { 
        if (!@mkdir($imageDir, 0755, true)) { 
            write_log("Error creando dir imágenes: ".$imageDir." (Verificar permisos del directorio padre: " . dirname($imageDir) . ")"); 
        }
    }
    if (is_dir($imageDir) && is_writable($imageDir)) {
        $timestampFile = time();
        $safePlate = preg_replace('/[^A-Za-z0-9_-]/', '', $licensePlate);
        if (empty($safePlate)) $safePlate = "UNKNOWNPLATE";
        $imageExtension = 'jpg';
        
        // $imageFileName es solo el nombre del archivo, no la ruta
        $imageFileName = $safePlate . '_' . ($channelID ?? 'CHX') . '_' . date('YmdHis', $timestampFile) . '_' . substr(md5(uniqid()),0,6) .'.' . $imageExtension;
        
        // $imagePathOnServer es la ruta completa en el servidor para guardar
        $imagePathOnServer = rtrim($imageDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $imageFileName;

        if (file_put_contents($imagePathOnServer, $imageData)) {
            write_log("Imagen guardada en servidor: " . $imagePathOnServer);
            // $relativePathForWeb es la ruta que usará el tag <img> en HTML
            // Si plate_images/ está directamente bajo la raíz del sitio accesible por web, o bajo LPR/ que es la raíz
            // Esta es la parte más dependiente de tu configuración web.
            // Si index.php está en LPR/ y plate_images está en LPR/plate_images/
            // entonces la ruta relativa desde index.php es "plate_images/nombre_archivo.jpg"
            $relativePathForWeb = 'plate_images/' . $imageFileName; 
            write_log("Ruta relativa para web calculada: " . $relativePathForWeb);
        } else {
            write_log("Error guardando imagen en: " . $imagePathOnServer . " (Verificar permisos de " . $imageDir . ")");
            $imagePathOnServer = null; // No se guardó
            $relativePathForWeb = ''; // No hay ruta
        }
    } else {
        write_log("Directorio de imágenes no existe o no tiene permisos de escritura: " . $imageDir);
        $relativePathForWeb = '';
    }
} else {
    if (!$imageData && !empty($licensePlate) && !empty($eventType)) write_log("Advertencia: No hay datos de imagen binarios para guardar, pero hay placa y evento.");
    if (empty($licensePlate) && $imageData) write_log("Advertencia: Hay imagen, pero no hay placa para asociarla.");
    if (empty($eventType) && $imageData && !empty($licensePlate)) write_log("Advertencia: Hay imagen y placa, pero no eventType.");
    $relativePathForWeb = ''; // Asegurar que esté vacía si no hay imagen o no se guarda
}

// --- LÓGICA DE NEGOCIO PRINCIPAL ---
$relevantEventTypesLPR = ['vehicleDetection', 'ANPR', 'LPR', 'trafficDetection', 'VLR', ' ذہ能识别结果'];
if (!empty($eventType) && in_array($eventType, $relevantEventTypesLPR) && !empty($licensePlate)) {
    write_log("Procesando evento LPR (" . $eventType . ") para la placa: " . $licensePlate);
    $eventDeviceIdentifier = $channelID;
    if (empty($eventDeviceIdentifier) && isset($_SERVER['REMOTE_ADDR'])) { $eventDeviceIdentifier = $_SERVER['REMOTE_ADDR']; }
    if(empty($eventDeviceIdentifier)) { $eventDeviceIdentifier = "UNKNOWN_DEVICE"; }
    
    // Pasar la ruta relativa de la imagen de captura a la función
    add_webhook_event_to_live_file($eventDeviceIdentifier, $licensePlate, $relativePathForWeb);

    // ... (Tu lógica de BD si la usas) ...
} else {
    // ... (Logs si no es un evento LPR relevante) ...
    if (!empty($eventType) && !in_array($eventType, $relevantEventTypesLPR)) { write_log("Evento no relevante para LPR: '" . $eventType . "'"); }
    // ... (otros logs de "no procesamiento")
}

http_response_code(200);
echo "OK";
write_log("Respuesta HTTP 200 enviada a la cámara.");
write_log("--- FIN DE PETICIÓN WEBHOOK (vWithCaptureImagePath) ---");
exit;
?>