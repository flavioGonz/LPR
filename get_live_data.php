<?php
// --- CONFIGURACIÓN ---
$logFile = __DIR__ . '/hikvision_lpr.log'; // El mismo archivo de log que usa tu webhook
$linesToReturn = 50; // Número de últimas líneas a devolver. Ajusta según necesites.
                     // Si quieres todo el archivo y es pequeño, puedes poner un número muy grande
                     // o modificar la lógica para leer el archivo completo.
// --- FIN CONFIGURACIÓN ---

header('Content-Type: text/plain; charset=utf-8'); // Devolver como texto plano
header('Cache-Control: no-cache, must-revalidate'); // Evitar caché del navegador
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT'); // Fecha pasada para evitar caché

if (file_exists($logFile)) {
    // Leer el archivo en un array, cada elemento es una línea
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    if ($lines === false) {
        echo "Error: No se pudo leer el archivo de log.";
        exit;
    }

    // Obtener solo las últimas N líneas
    if (count($lines) > $linesToReturn) {
        $outputLines = array_slice($lines, -$linesToReturn);
    } else {
        $outputLines = $lines;
    }

    // Imprimir las líneas
    if (!empty($outputLines)) {
        echo implode("\n", $outputLines);
    } else {
        echo "Log vacío o sin entradas recientes...";
    }

} else {
    echo "Archivo de log no encontrado: " . htmlspecialchars($logFile);
}
?>