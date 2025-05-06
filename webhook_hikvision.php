<?php
// --- CONFIGURACIÓN ---
$logFile = __DIR__ . '/hikvision_lpr.log';
$imageDir = __DIR__ . '/plate_images/';
define('WEBHOOK_LIVE_EVENTS_FILE', __DIR__ . '/live_events.txt'); // RUTA CORREGIDA
// --- FIN CONFIGURACIÓN ---

function write_log($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    if (!is_string($message)) { $message = print_r($message, true); }
    file_put_contents($logFile, "[$timestamp] " . $message . "\n", FILE_APPEND | LOCK_EX);
}

function add_webhook_event_to_live_file($deviceID, $licensePlate) {
    if (empty($deviceID) || empty($licensePlate)) { write_log("Error interno: Intento de añadir evento en vivo con deviceID o licensePlate vacíos."); return; }
    $timestamp = date('Y-m-d H:i:s'); $entry = $timestamp . '|' . trim($deviceID) . '|' . strtoupper(trim($licensePlate)) . PHP_EOL;
    $max_lines = 100; $current_lines = @file(WEBHOOK_LIVE_EVENTS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($current_lines === false) $current_lines = [];
    $new_lines_array = array_slice(array_merge([trim($entry)], $current_lines), 0, $max_lines);
    if (file_put_contents(WEBHOOK_LIVE_EVENTS_FILE, implode(PHP_EOL, $new_lines_array) . PHP_EOL, LOCK_EX) === false) {
        write_log("Error escribiendo en WEBHOOK_LIVE_EVENTS_FILE: " . WEBHOOK_LIVE_EVENTS_FILE);
    } else { write_log("Evento LPR (".$licensePlate." desde ".$deviceID.") añadido a WEBHOOK_LIVE_EVENTS_FILE."); }
}

write_log("--- INICIO DE PETICIÓN WEBHOOK (MULTIPART - PROCESANDO \$_FILES vCorrectedPath) ---");
write_log("IP Remota (Cámara): " . $_SERVER['REMOTE_ADDR']);
write_log("Método HTTP: " . $_SERVER['REQUEST_METHOD']);
// ... (resto del logging inicial sin cambios)
$headers = getallheaders();
write_log("Headers Generales Recibidos:\n" . print_r($headers, true));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.0 405 Method Not Allowed'); write_log("Error: Método no permitido."); echo 'Method Not Allowed.';
    write_log("--- FIN DE PETICIÓN WEBHOOK (MÉTODO INCORRECTO) ---"); exit;
}

$channelID = $dateTime = $eventType = $licensePlate = $region = $country = $vehicleType = $vehicleColor = null;
$imageData = null; $imagePath = null; $xmlDataString = null;

$contentTypeHeader = isset($headers['Content-Type']) ? strtolower($headers['Content-Type']) : (isset($_SERVER['CONTENT_TYPE']) ? strtolower($_SERVER['CONTENT_TYPE']) : '');

if (strpos($contentTypeHeader, 'multipart/form-data') !== false) {
    write_log("Detectado Content-Type multipart/form-data.");
    write_log("\$_POST contenido:\n" . print_r($_POST, true));
    write_log("\$_FILES contenido:\n" . print_r($_FILES, true));

    if (isset($_FILES['anpr_xml']) && $_FILES['anpr_xml']['error'] === UPLOAD_ERR_OK) {
        if (isset($_FILES['anpr_xml']['tmp_name']) && is_uploaded_file($_FILES['anpr_xml']['tmp_name'])) {
            if ($_FILES['anpr_xml']['type'] === 'text/xml' || $_FILES['anpr_xml']['type'] === 'application/xml') {
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
        // Descomentar para depuración profunda del XML si es necesario:
        // write_log("CONTENIDO COMPLETO DE anpr.xml:\n" . $xmlDataString); 
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
            elseif (isset($xml->Plate->licensePlate)) { $lprNode = $xml->Plate; }
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
            if(empty($eventType) && isset($xml->eventType)) $eventType = (string)$xml->eventType;
            write_log("Datos de Alarma extraídos del XML.");
        } catch (Exception $e) {
            write_log("Error parseando XML: " . $e->getMessage());
            write_log("XML que causó el error:\n" . $xmlDataString);
        }
    } else { write_log("FINAL (multipart): No se encontró/leyó el archivo XML 'anpr_xml' desde \$_FILES."); }
    if (!$imageData) { write_log("FINAL (multipart): No se encontró/leyó la imagen 'licensePlatePicture_jpg' desde \$_FILES."); }

} elseif (strpos($contentTypeHeader, 'application/xml') !== false || strpos($contentTypeHeader, 'text/xml') !== false) {
    // ... (Lógica para XML directo) ...
    write_log("Detectado Content-Type XML (directo)..."); // Acortado para brevedad
    // Tu lógica de parseo de XML directo aquí...
} else {
    // ... (Lógica de fallback) ...
    write_log("Content-Type no es XML ni multipart..."); // Acortado
}

write_log(sprintf(
    "Datos Consolidados Finales: Canal=%s, FechaHora=%s, Evento=%s, Placa=%s, Imagen Recibida=%s",
    $channelID ?? 'N/A', $dateTime ?? 'N/A', $eventType ?? 'N/A', $licensePlate ?? 'N/A',
    $imageData ? 'Sí (' . strlen($imageData) . ' bytes)' : 'No'
));

if ($imageData && !empty($licensePlate) && !empty($eventType) ) {
    if (!is_dir($imageDir)) { if (!@mkdir($imageDir, 0755, true)) { write_log("Error creando dir imágenes: ".$imageDir); } }
    if (is_dir($imageDir) && is_writable($imageDir)) {
        $timestampFile = time();
        $safePlate = preg_replace('/[^A-Za-z0-9_-]/', '', $licensePlate);
        if (empty($safePlate)) $safePlate = "UNKNOWNPLATE";
        $imageExtension = 'jpg';
        $imageFileName = $safePlate . '_' . ($channelID ?? 'CHX') . '_' . date('YmdHis', $timestampFile) . '_' . substr(md5(uniqid()),0,6) .'.' . $imageExtension;
        $imagePath = $imageDir . $imageFileName;
        if (file_put_contents($imagePath, $imageData)) { write_log("Imagen guardada: " . $imagePath); }
        else { write_log("Error guardando imagen: " . $imagePath); $imagePath = null; }
    } else { write_log("Dir imágenes no existe o no escribible: " . $imageDir); }
} else { /* ... logs de advertencia ... */ }

$relevantEventTypesLPR = ['vehicleDetection', 'ANPR', 'LPR', 'trafficDetection', 'VLR', ' ذہ能识别结果'];
if (!empty($eventType) && in_array($eventType, $relevantEventTypesLPR) && !empty($licensePlate)) {
    write_log("Procesando evento LPR (" . $eventType . ") para la placa: " . $licensePlate);
    $eventDeviceIdentifier = $channelID;
    if (empty($eventDeviceIdentifier) && isset($_SERVER['REMOTE_ADDR'])) { $eventDeviceIdentifier = $_SERVER['REMOTE_ADDR']; }
    if(empty($eventDeviceIdentifier)) { $eventDeviceIdentifier = "UNKNOWN_DEVICE"; }
    add_webhook_event_to_live_file($eventDeviceIdentifier, $licensePlate);
} else { /* ... logs de no procesamiento ... */ }

http_response_code(200);
echo "OK";
write_log("Respuesta HTTP 200 enviada a la cámara.");
write_log("--- FIN DE PETICIÓN WEBHOOK (MULTIPART - PROCESADO \$_FILES vCorrectedPath) ---");
exit;
?>