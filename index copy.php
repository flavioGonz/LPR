<?php
// --- CONFIGURACIÓN DE ARCHIVOS DE DATOS ---
define('WHITELIST_FILE', __DIR__ . '/whitelist.txt');
define('DEVICES_FILE', __DIR__ . '/devices.txt');
define('LIVE_EVENTS_FILE', __DIR__ . '/live_events.txt');
define('OWNERS_FILE', __DIR__ . '/owners.txt');
define('PLATE_OWNER_FILE', __DIR__ . '/plate_to_owner.txt');
define('UPLOADS_DIR', __DIR__ . '/uploads');
define('OWNER_PHOTOS_DIR', UPLOADS_DIR . '/owner_photos');
define('MAP_IMAGE_DIR', UPLOADS_DIR . '/maps');

// --- ASEGURAR QUE LOS ARCHIVOS Y DIRECTORIOS EXISTAN ---
foreach ([WHITELIST_FILE, DEVICES_FILE, LIVE_EVENTS_FILE, OWNERS_FILE, PLATE_OWNER_FILE] as $file) {
    if (!file_exists($file)) { @file_put_contents($file, ''); }
}
if (!is_dir(UPLOADS_DIR)) { @mkdir(UPLOADS_DIR, 0755, true); }
if (!is_dir(OWNER_PHOTOS_DIR)) { @mkdir(OWNER_PHOTOS_DIR, 0755, true); }
if (!is_dir(MAP_IMAGE_DIR)) { @mkdir(MAP_IMAGE_DIR, 0755, true); }

// --- FUNCIONES DE MANEJO DE DATOS ---

// WHITELIST
function get_whitelist_plates() {
    $plates_content = @file(WHITELIST_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($plates_content === false) {
        return []; // Devolver array vacío si falla la lectura
    }
    return array_map('trim', array_map('strtoupper', $plates_content));
}
function add_to_whitelist_file($license_plate) {
    $plate = strtoupper(trim($license_plate));
    if (empty($plate)) return false;
    $current_plates = get_whitelist_plates(); // Asegura que $current_plates siempre sea un array
    if (in_array($plate, $current_plates)) return true; // Ya existe, considerado éxito
    return file_put_contents(WHITELIST_FILE, $plate . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
}
function remove_from_whitelist_file($license_plate_to_remove) {
    $plate_to_remove = strtoupper(trim($license_plate_to_remove));
    if (empty($plate_to_remove)) return false;
    $lines = @file(WHITELIST_FILE, FILE_IGNORE_NEW_LINES);
    if ($lines === false) return false; // No se pudo leer el archivo
    $new_content = ''; $found = false;
    foreach ($lines as $line) {
        $current_plate = trim($line);
        if (strtoupper($current_plate) === $plate_to_remove) { // Comparación en mayúsculas
            $found = true; continue; 
        }
        if (!empty($current_plate)) { $new_content .= $current_plate . PHP_EOL; }
    }
    if ($found) return file_put_contents(WHITELIST_FILE, $new_content, LOCK_EX) !== false;
    return false; // No se encontró para eliminar, o error de escritura
}

// DEVICES
function get_devices() {
    $devices_data = [];
    $lines = @file(DEVICES_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return [];
    foreach ($lines as $line) {
        $parts = explode(':', $line, 3);
        if (count($parts) === 3) {
            $devices_data[] = ['id' => trim($parts[0]), 'name' => trim($parts[1]), 'ip' => trim($parts[2])];
        }
    }
    return $devices_data;
}
function add_device_to_file($id, $name, $ip) {
    $id = trim($id); $name = trim($name); $ip = trim($ip);
    if (empty($id) || empty($name)) return false; // IP puede ser opcional para referencia
    $devices = get_devices();
    foreach ($devices as $device) { if ($device['id'] === $id) return false; }
    $entry = $id . ':' . $name . ':' . $ip . PHP_EOL;
    return file_put_contents(DEVICES_FILE, $entry, FILE_APPEND | LOCK_EX) !== false;
}
function remove_device_from_file($device_id_to_remove) {
    $id_to_remove = trim($device_id_to_remove);
    if (empty($id_to_remove)) return false;
    $lines = @file(DEVICES_FILE, FILE_IGNORE_NEW_LINES);
    if ($lines === false) return false;
    $new_content = ''; $found = false;
    foreach ($lines as $line) {
        $parts = explode(':', $line, 3);
        if (count($parts) >= 1 && trim($parts[0]) === $id_to_remove) { $found = true; continue; } // Chequear solo el ID
        if (!empty(trim($line))) { $new_content .= trim($line) . PHP_EOL; }
    }
    if ($found) return file_put_contents(DEVICES_FILE, $new_content, LOCK_EX) !== false;
    return false;
}
function get_devices_map() {
    $devices_map = []; $devices = get_devices();
    foreach ($devices as $device) {
        $devices_map[$device['id']] = $device['name'];
        if (!empty($device['ip']) && !isset($devices_map[$device['ip']])) { $devices_map[$device['ip']] = $device['name'];}
    }
    return $devices_map;
}

// OWNERS
function get_owners($owner_id_filter = null) {
    $owners_data = [];
    $lines = @file(OWNERS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return [];
    foreach ($lines as $line) {
        $parts = explode(':', $line, 6);
        if (count($parts) >= 2) {
            $owner_id = trim($parts[0]);
            if ($owner_id_filter && $owner_id_filter !== $owner_id) { continue; }
            $owner_entry = [
                'id' => $owner_id,
                'name' => trim($parts[1]),
                'email' => isset($parts[2]) ? trim($parts[2]) : '',
                'whatsapp' => isset($parts[3]) ? trim($parts[3]) : '',
                'address' => isset($parts[4]) ? trim($parts[4]) : '',
                'photo_url' => isset($parts[5]) ? trim($parts[5]) : ''
            ];
            if ($owner_id_filter) return $owner_entry; // Devolver solo uno si se filtra
            $owners_data[] = $owner_entry;
        }
    }
    return $owner_id_filter ? null : $owners_data; // Devolver null si se filtró y no se encontró
}
function add_owner_to_file($id, $name, $email, $whatsapp, $address, $photo_filename = '') {
    $id = trim($id); $name = trim($name); $email = trim($email);
    $whatsapp = preg_replace('/[^0-9]/', '', trim($whatsapp));
    $address = trim($address);
    if (empty($id) || empty($name)) return false;
    if (get_owners($id) !== null) return false; // Chequeo de ID duplicado
    $entry = implode(':', [$id, $name, $email, $whatsapp, $address, $photo_filename]) . PHP_EOL;
    return file_put_contents(OWNERS_FILE, $entry, FILE_APPEND | LOCK_EX) !== false;
}
function get_owners_map() {
    $map = []; $owners = get_owners();
    if(is_array($owners)){
        foreach($owners as $owner) { $map[$owner['id']] = $owner; }
    }
    return $map;
}

// PLATE_TO_OWNER
function get_plate_owner_map() {
    $map = [];
    $lines = @file(PLATE_OWNER_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return [];
    foreach ($lines as $line) {
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $map[strtoupper(trim($parts[0]))] = trim($parts[1]);
        }
    }
    return $map;
}
function assign_plate_to_owner($plate, $owner_id) {
    $plate = strtoupper(trim($plate)); $owner_id = trim($owner_id);
    if (empty($plate) || empty($owner_id)) return false;
    remove_plate_assignment($plate); // Asegura que una placa solo tenga un dueño
    $entry = $plate . ':' . $owner_id . PHP_EOL;
    return file_put_contents(PLATE_OWNER_FILE, $entry, FILE_APPEND | LOCK_EX) !== false;
}
function remove_plate_assignment($plate_to_remove) {
    $plate_to_remove = strtoupper(trim($plate_to_remove));
    if (empty($plate_to_remove)) return false;
    $lines = @file(PLATE_OWNER_FILE, FILE_IGNORE_NEW_LINES);
    if ($lines === false) return false;
    $new_content = ''; $found = false;
    foreach ($lines as $line) {
        $parts = explode(':', $line, 2);
        if (count($parts) === 2 && strtoupper(trim($parts[0])) === $plate_to_remove) { $found = true; continue; }
        if (!empty(trim($line))) { $new_content .= trim($line) . PHP_EOL; }
    }
    // Solo escribir si se encontró y eliminó algo, o si el archivo original existía.
    // Si el archivo no existía o no se encontró nada, no es necesario reescribir un archivo vacío.
    if ($found) return file_put_contents(PLATE_OWNER_FILE, $new_content, LOCK_EX) !== false;
    return true; // No es error si no se encontró para eliminar
}

// LIVE EVENTS
function get_live_events_data($count = 10, $search_term = '') {
    $events_data = [];
    $lines = @file(LIVE_EVENTS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) { return []; }

    $whitelist = get_whitelist_plates(); // Esto ahora siempre devuelve un array
    $devices_map = get_devices_map();
    $plate_owner_map = get_plate_owner_map();
    $owners_full_map = get_owners_map();
    $search_term = strtoupper(trim($search_term));

    $matched_count = 0;
    foreach ($lines as $line) {
        if ($matched_count >= $count && !empty($search_term) && $count > 0) break;
        
        $parts = explode('|', $line, 3);
        if (count($parts) === 3) {
            $plate = strtoupper(trim($parts[2]));
            if (!empty($search_term) && strpos($plate, $search_term) === false) {
                continue;
            }

            $deviceID_or_IP = trim($parts[1]);
            $deviceName = $devices_map[$deviceID_or_IP] ?? $deviceID_or_IP;
            $owner_details = null;
            $owner_id = $plate_owner_map[$plate] ?? null;
            if ($owner_id && isset($owners_full_map[$owner_id])) {
                $owner_details = $owners_full_map[$owner_id];
            }

            $events_data[] = [
                'timestamp' => trim($parts[0]),
                'device' => htmlspecialchars($deviceName),
                'plate' => htmlspecialchars($plate),
                'is_whitelisted' => in_array($plate, $whitelist),
                'owner' => $owner_details
            ];
            $matched_count++;
        }
         if ($matched_count >= $count && empty($search_term) && $count > 0) break;
    }
    return empty($search_term) && $count > 0 ? array_slice($events_data, 0, $count) : $events_data;
}

// MAPS
function get_map_image_path() {
    $maps = @glob(MAP_IMAGE_DIR . '/*.{jpg,jpeg,png,gif}', GLOB_BRACE);
    return ($maps && count($maps) > 0) ? $maps[0] : null;
}

// --- PROCESAMIENTO DE ACCIONES POST ---
$action_message = '';
$action_status_is_error = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    // $view = $_GET['view'] ?? 'live'; // No necesitamos $view aquí si no redirigimos

    if ($action === 'add_device') {
        if (add_device_to_file($_POST['device_id'] ?? '', $_POST['device_name'] ?? '', $_POST['device_ip'] ?? '')) {
            $action_message = "Dispositivo añadido.";
        } else { $action_message = "Error añadiendo dispositivo o ID ya existe."; $action_status_is_error = true;}
    } elseif ($action === 'remove_device') {
        if (remove_device_from_file($_POST['device_id_to_remove'] ?? '')) {
            $action_message = "Dispositivo eliminado.";
        } else { $action_message = "Error eliminando dispositivo."; $action_status_is_error = true;}
    } elseif ($action === 'add_whitelist') {
        if (add_to_whitelist_file($_POST['license_plate'] ?? '')) {
            $action_message = "Matrícula añadida a lista blanca.";
        } else { $action_message = "Error añadiendo a lista blanca o ya existe."; $action_status_is_error = true;}
    } elseif ($action === 'remove_whitelist') {
         if (remove_from_whitelist_file($_POST['plate_to_remove'] ?? '')) {
            $action_message = "Matrícula eliminada de lista blanca.";
        } else { $action_message = "Error eliminando de lista blanca o no encontrada."; $action_status_is_error = true;}
    } elseif ($action === 'add_owner') {
        $new_owner_id = 'owner' . substr(uniqid(), -6);
        $photo_filename = '';
        if (isset($_FILES['owner_photo']) && $_FILES['owner_photo']['error'] === UPLOAD_ERR_OK) {
            $photo_tmp_name = $_FILES['owner_photo']['tmp_name'];
            $photo_ext = strtolower(pathinfo($_FILES['owner_photo']['name'], PATHINFO_EXTENSION));
            if (in_array($photo_ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                $photo_filename = $new_owner_id . '.' . $photo_ext;
                if (!move_uploaded_file($photo_tmp_name, OWNER_PHOTOS_DIR . '/' . $photo_filename)) {
                    $action_message = "Error al subir la foto del propietario."; $action_status_is_error = true; $photo_filename = '';
                }
            } else { $action_message = "Formato de foto no válido."; $action_status_is_error = true; }
        }
        if (!$action_status_is_error) {
            if (add_owner_to_file($new_owner_id, $_POST['owner_name'] ?? '', $_POST['owner_email'] ?? '', $_POST['owner_whatsapp'] ?? '', $_POST['owner_address'] ?? '', $photo_filename)) {
                $action_message = "Propietario añadido (ID: $new_owner_id).";
            } else { $action_message = "Error añadiendo propietario o ID ya existe."; $action_status_is_error = true; }
        }
    } elseif ($action === 'assign_plate_owner') {
        if (assign_plate_to_owner($_POST['license_plate_to_assign'] ?? '', $_POST['owner_id_assign'] ?? '')) {
            $action_message = "Matrícula asignada a propietario.";
        } else { $action_message = "Error asignando matrícula."; $action_status_is_error = true; }
    } elseif ($action === 'upload_map') {
        if (isset($_FILES['map_image']) && $_FILES['map_image']['error'] === UPLOAD_ERR_OK) {
            $existing_maps = @glob(MAP_IMAGE_DIR . '/*');
            if ($existing_maps) { foreach($existing_maps as $file){ if(is_file($file)) @unlink($file); }}
            $map_tmp_name = $_FILES['map_image']['tmp_name'];
            $map_ext = strtolower(pathinfo($_FILES['map_image']['name'], PATHINFO_EXTENSION));
            if (in_array($map_ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                $map_filename = 'parking_map.' . $map_ext;
                if (move_uploaded_file($map_tmp_name, MAP_IMAGE_DIR . '/' . $map_filename)) {
                    $action_message = "Mapa del parking subido correctamente.";
                } else { $action_message = "Error al subir el mapa."; $action_status_is_error = true; }
            } else { $action_message = "Formato de imagen de mapa no válido."; $action_status_is_error = true; }
        } else { $action_message = "No se seleccionó archivo de mapa o hubo un error (Cod: ".($_FILES['map_image']['error']??'N/A').")"; $action_status_is_error = true; }
    }
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_live_events') {
    header('Content-Type: application/json');
    $search = $_GET['search'] ?? '';
    $events = get_live_events_data(20, $search);
    echo json_encode($events);
    exit;
}

$current_view = $_GET['view'] ?? 'live';
$initial_search_term = $_GET['search'] ?? '';
$initial_events = get_live_events_data(7, $initial_search_term);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel LPR - <?php echo ucfirst(str_replace('_', ' ', $current_view)); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #3B71CA; --primary-color-rgb: 59,113,202;
            --secondary-color: #332D2D; --accent-color: #FFC107;
            --background-color: #f0f2f5; --surface-color: #ffffff;
            --text-color: #212529; --text-secondary-color: #6c757d;
            --green-ok: #198754; --red-not-ok: #dc3545;
            --border-color: #dee2e6; --shadow-sm: 0 1px 3px rgba(0,0,0,0.07);
            --shadow-md: 0 3px 8px rgba(0,0,0,0.1); --border-radius: 0.375rem;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background-color: var(--background-color); color: var(--text-color); line-height: 1.6; display: flex; flex-direction: column; min-height: 100vh; font-size: 15px; }
        .app-wrapper { display: flex; min-height: 100vh; }
        .sidebar { width: 260px; background-color: var(--secondary-color); color: #fff; padding: 20px 0; display: flex; flex-direction: column; box-shadow: var(--shadow-md); transition: width 0.3s ease; }
        .sidebar-header { text-align: center; padding: 0 20px 20px 20px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header h1 { font-size: 1.6em; font-weight: 600; margin: 0; letter-spacing: 0.5px; }
        .sidebar-header h1 i { color: var(--accent-color); }
        .sidebar-nav { flex-grow: 1; margin-top: 20px; }
        .sidebar-nav ul { list-style: none; }
        .sidebar-nav li a { display: flex; align-items: center; padding: 12px 25px; color: #bdc3c7; text-decoration: none; font-weight: 500; transition: background-color 0.2s ease, color 0.2s ease; border-left: 4px solid transparent; }
        .sidebar-nav li a i { margin-right: 12px; width: 20px; text-align: center; opacity: 0.8; }
        .sidebar-nav li a:hover { background-color: rgba(255,255,255,0.05); color: #fff; border-left-color: var(--accent-color); }
        .sidebar-nav li a.active { background-color: var(--primary-color); color: #fff; font-weight:600; border-left-color: var(--accent-color); }
        .sidebar-nav li a.active i { opacity: 1; }
        .main-content { flex-grow: 1; padding: 25px 30px; overflow-y: auto; }
        .page-header { margin-bottom: 25px; display: flex; align-items: center; }
        .page-header h2 { font-size: 1.8em; font-weight: 600; color: var(--secondary-color); margin: 0; }
        .page-header i { font-size: 1.5em; margin-right: 12px; color: var(--primary-color); }
        .card { background-color: var(--surface-color); padding: 20px 25px; border-radius: var(--border-radius); box-shadow: var(--shadow-sm); margin-bottom: 25px; }
        .card h3 { font-size: 1.2em; font-weight: 600; color: var(--primary-color); margin-bottom: 15px; border-bottom: 1px solid var(--border-color); padding-bottom: 10px; display:flex; align-items:center;}
        .card h3 i { margin-right: 8px; }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-weight: 500; margin-bottom: 6px; font-size: 0.95em; }
        .form-group input[type="text"], .form-group input[type="email"], .form-group input[type="file"], .form-group select { width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 0.95em; transition: border-color 0.2s ease, box-shadow 0.2s ease; }
        .form-group input[type="text"]:focus, .form-group input[type="email"]:focus, .form-group select:focus { border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(var(--primary-color-rgb), 0.25); outline: none; }
        .btn { padding: 10px 18px; border: none; border-radius: 6px; font-weight: 500; cursor: pointer; transition: background-color 0.2s ease, transform 0.1s ease; font-size: 0.95em; display: inline-flex; align-items: center; justify-content: center; }
        .btn i { margin-right: 6px; }
        .btn-sm { padding: 7px 12px; font-size: 0.85em;}
        .btn-primary { background-color: var(--primary-color); color: white; }
        .btn-primary:hover { background-color: var(--secondary-color); }
        .btn-danger { background-color: var(--red-not-ok); color: white; }
        .btn-danger:hover { background-color: #a52834; }
        .btn:active { transform: translateY(1px); }
        .action-message { padding: 12px 18px; border-radius: var(--border-radius); margin-bottom: 20px; font-weight: 500; display:flex; align-items:center;}
        .action-message i { margin-right: 8px; font-size:1.2em; }
        .action-message.success { background-color: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; }
        .action-message.error { background-color: #f8d7da; color: #842029; border: 1px solid #f5c2c7; }
        .live-panel-controls { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .search-input-container { position: relative; display: flex; align-items: center; }
        #searchInput { width: 320px; padding: 10px 15px 10px 40px; border: 1px solid var(--border-color); border-radius: var(--border-radius); font-size: 0.95em; }
        .search-input-container .search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-secondary-color); font-size: 1.1em; }
        .loading-indicator { font-size: 0.9em; color: var(--text-secondary-color); opacity: 0; transition: opacity 0.3s ease; }
        .loading-indicator.visible { opacity: 1; }
        .event-list { list-style-type: none; max-height: calc(100vh - 250px); overflow-y: auto; padding-right: 8px; }
        .event-item { background-color: var(--surface-color); border: 1px solid var(--border-color); border-left-width: 5px; padding: 12px 18px; margin-bottom: 10px; border-radius: var(--border-radius); display: flex; align-items: flex-start; opacity: 0; transform: scale(0.95); animation: 등장 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards; box-shadow: var(--shadow-sm); }
        @keyframes 등장 { to { opacity: 1; transform: scale(1); } }
        .event-item-main { display: flex; align-items: center; flex-grow: 1; }
        .event-item-icon-col { margin-right: 15px; flex-shrink: 0; }
        .event-item-icon-col .car-svg-icon { width: 36px; height: 36px; color: var(--text-secondary-color); }
        .event-item.status-ok .event-item-icon-col .car-svg-icon { color: var(--green-ok); }
        .event-item.status-not-ok .event-item-icon-col .car-svg-icon { color: var(--red-not-ok); }
        .event-item-icon-col .owner-photo { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border-color); }
        .event-details { flex-grow: 1; }
        .event-plate { font-size: 1.3em; font-weight: 600; color: var(--text-color); }
        .event-owner-details { font-size: 0.9em; color: var(--text-secondary-color); margin-top: 4px; }
        .event-owner-details strong { color: var(--text-color); font-weight: 500; }
        .event-owner-details .whatsapp-link { color: var(--green-ok); text-decoration: none; font-weight: 500; }
        .event-owner-details .whatsapp-link i { margin-right: 4px; }
        .event-owner-details .whatsapp-link:hover { text-decoration: underline; }
        .event-meta { font-size: 0.8em; color: var(--text-secondary-color); margin-top: 4px; }
        .event-status { padding: 6px 12px; border-radius: 50px; font-weight: 600; font-size: 0.75em; text-transform: uppercase; letter-spacing: 0.5px; min-width: 120px; text-align: center; margin-left:15px; flex-shrink:0;}
        .event-status i { margin-right: 5px;}
        .event-status.status-ok { background-color: var(--green-ok); color: white; }
        .event-status.status-not-ok { background-color: var(--red-not-ok); color: white; }
        .no-events-message { text-align:center; padding: 30px; color: var(--text-secondary-color); font-size: 1em; border: 1px dashed var(--border-color); border-radius: var(--border-radius); }
        .data-table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 0.95em; }
        .data-table th, .data-table td { padding: 10px 12px; border-bottom: 1px solid var(--border-color); text-align: left; }
        .data-table th { background-color: #f8f9fa; font-weight: 600; font-size: 0.85em; text-transform: uppercase; letter-spacing: 0.5px; }
        .data-table tr:last-child td { border-bottom: none; }
        .data-table tr:hover td { background-color: #fdfdfe; }
        .action-buttons form { display: inline; }
        .action-buttons .btn { margin-left: 5px; }
        .map-container { width: 100%; max-width: 800px; margin: 20px auto; border: 1px solid var(--border-color); padding: 10px; box-shadow: var(--shadow-sm); position: relative; }
        .map-container img { display: block; max-width: 100%; height: auto; border-radius: calc(var(--border-radius) - 4px); }
        .no-map-message { text-align: center; padding: 20px; color: var(--text-secondary-color); }
        ::-webkit-scrollbar { width: 7px; height: 7px; }
        ::-webkit-scrollbar-track { background: rgba(0,0,0,0.05); border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: #c5c9d2; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #a9aeb9; }
    </style>
</head>
<body>
    <div class="app-wrapper">
        <aside class="sidebar">
            <div class="sidebar-header"><h1><i class="fas fa-parking"></i>LPR Panel</h1></div>
            <nav class="sidebar-nav">
                <ul>
                    <li><a href="?view=live" class="<?php echo $current_view === 'live' ? 'active' : ''; ?>"><i class="fas fa-stream"></i> En Vivo</a></li>
                    <li><a href="?view=devices" class="<?php echo $current_view === 'devices' ? 'active' : ''; ?>"><i class="fas fa-camera-retro"></i> Dispositivos</a></li>
                    <li><a href="?view=whitelist" class="<?php echo $current_view === 'whitelist' ? 'active' : ''; ?>"><i class="fas fa-check-circle"></i> Lista Blanca</a></li>
                    <li><a href="?view=owners" class="<?php echo $current_view === 'owners' ? 'active' : ''; ?>"><i class="fas fa-users"></i> Propietarios</a></li>
                    <li><a href="?view=maps" class="<?php echo $current_view === 'maps' ? 'active' : ''; ?>"><i class="fas fa-map-marked-alt"></i> Mapa Parking</a></li>
                </ul>
            </nav>
        </aside>

        <div class="main-content">
            <?php if ($action_message): ?>
                <div class="action-message <?php echo $action_status_is_error ? 'error' : 'success'; ?>">
                    <i class="fas <?php echo $action_status_is_error ? 'fa-times-circle' : 'fa-check-circle'; ?>"></i>
                    <?php echo htmlspecialchars($action_message); ?>
                </div>
            <?php endif; ?>

            <?php if ($current_view === 'live'): ?>
            <div class="page-header"><h2><i class="fas fa-broadcast-tower"></i>Panel de Eventos en Vivo</h2></div>
            <section class="card">
                <div class="live-panel-controls">
                    <div class="search-input-container">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="searchInput" placeholder="Buscar matrícula..." value="<?php echo htmlspecialchars($initial_search_term); ?>">
                    </div>
                    <span class="loading-indicator" id="loadingIndicator">Actualizando... <i class="fas fa-spinner fa-spin"></i></span>
                </div>
                <ul class="event-list" id="eventList"></ul>
            </section>

            <?php elseif ($current_view === 'devices'): $devices_list = get_devices(); ?>
            <div class="page-header"><h2><i class="fas fa-video"></i>Gestión de Dispositivos</h2></div>
            <section class="card">
                <h3><i class="fas fa-plus-circle"></i>Añadir Nuevo Dispositivo</h3>
                <form action="?view=devices" method="POST">
                    <input type="hidden" name="action" value="add_device">
                    <div class="form-group"><label for="device_id">ID Dispositivo (ej. CAM01, IP):</label><input type="text" id="device_id" name="device_id" required></div>
                    <div class="form-group"><label for="device_name">Nombre Descriptivo:</label><input type="text" id="device_name" name="device_name" required></div>
                    <div class="form-group"><label for="device_ip">IP (opcional):</label><input type="text" id="device_ip" name="device_ip" pattern="\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}" title="Formato IP: xxx.xxx.xxx.xxx"></div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i>Guardar Dispositivo</button>
                </form>
            </section>
            <section class="card">
                <h3><i class="fas fa-list-ul"></i>Dispositivos Registrados</h3>
                <?php if (!empty($devices_list)): ?>
                    <table class="data-table">
                        <thead><tr><th>ID</th><th>Nombre</th><th>IP</th><th style="width:100px;">Acción</th></tr></thead>
                        <tbody>
                        <?php foreach ($devices_list as $d): ?><tr><td><?php echo htmlspecialchars($d['id']); ?></td><td><?php echo htmlspecialchars($d['name']); ?></td><td><?php echo htmlspecialchars($d['ip']); ?></td><td class="action-buttons"><form action="?view=devices" method="POST"><input type="hidden" name="action" value="remove_device"><input type="hidden" name="device_id_to_remove" value="<?php echo htmlspecialchars($d['id']); ?>"><button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('¿Eliminar?');"><i class="fas fa-trash-alt"></i></button></form></td></tr><?php endforeach; ?>
                        </tbody></table>
                <?php else: ?><p>No hay dispositivos.</p><?php endif; ?>
            </section>

            <?php elseif ($current_view === 'whitelist'): $whitelist_plates_list = get_whitelist_plates(); ?>
            <div class="page-header"><h2><i class="fas fa-clipboard-check"></i>Gestión de Lista Blanca</h2></div>
            <section class="card">
                <h3><i class="fas fa-plus-circle"></i>Añadir Matrícula</h3>
                 <form action="?view=whitelist" method="POST">
                    <input type="hidden" name="action" value="add_whitelist">
                    <div class="form-group"><label for="wl_plate">Matrícula:</label><input type="text" id="wl_plate" name="license_plate" required></div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i>Añadir</button>
                </form></section>
            <section class="card">
                <h3><i class="fas fa-list-ul"></i>Matrículas en Lista Blanca</h3>
                <?php if (!empty($whitelist_plates_list)): ?>
                    <table class="data-table"><thead><tr><th>Matrícula</th><th style="width:100px;">Acción</th></tr></thead><tbody>
                        <?php foreach ($whitelist_plates_list as $p): ?><tr><td><?php echo htmlspecialchars($p); ?></td><td class="action-buttons"><form action="?view=whitelist" method="POST"><input type="hidden" name="action" value="remove_whitelist"><input type="hidden" name="plate_to_remove" value="<?php echo htmlspecialchars($p); ?>"><button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('¿Eliminar?');"><i class="fas fa-trash-alt"></i></button></form></td></tr><?php endforeach; ?>
                    </tbody></table>
                <?php else: ?><p>Lista blanca vacía.</p><?php endif; ?>
            </section>

            <?php elseif ($current_view === 'owners'): $owners_list = get_owners(); $plate_owner_map = get_plate_owner_map();?>
            <div class="page-header"><h2><i class="fas fa-id-card"></i>Gestión de Propietarios</h2></div>
            <section class="card">
                <h3><i class="fas fa-user-plus"></i>Añadir Nuevo Propietario</h3>
                 <form action="?view=owners" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add_owner">
                    <div class="form-group"><label for="owner_name">Nombre Completo:</label><input type="text" id="owner_name" name="owner_name" required></div>
                    <div class="form-group"><label for="owner_email">Email:</label><input type="email" id="owner_email" name="owner_email"></div>
                    <div class="form-group"><label for="owner_whatsapp">N° WhatsApp (ej: 54911...):</label><input type="text" id="owner_whatsapp" name="owner_whatsapp" placeholder="Solo números, con cód. país y área"></div>
                    <div class="form-group"><label for="owner_address">Domicilio:</label><input type="text" id="owner_address" name="owner_address"></div>
                    <div class="form-group"><label for="owner_photo">Foto (opcional):</label><input type="file" id="owner_photo" name="owner_photo" accept="image/jpeg,image/png,image/gif"></div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus"></i>Añadir Propietario</button>
                </form></section>
             <section class="card">
                <h3><i class="fas fa-link"></i>Asignar Matrícula a Propietario</h3>
                 <form action="?view=owners" method="POST">
                    <input type="hidden" name="action" value="assign_plate_owner">
                    <div class="form-group"><label for="lp_assign">Matrícula:</label><input type="text" id="lp_assign" name="license_plate_to_assign" required></div>
                    <div class="form-group"><label for="owner_assign">Propietario:</label><select id="owner_assign" name="owner_id_assign" required><option value="">Seleccionar...</option><?php foreach($owners_list as $o): ?><option value="<?php echo htmlspecialchars($o['id']); ?>"><?php echo htmlspecialchars($o['name']); ?> (ID: <?php echo htmlspecialchars($o['id']); ?>)</option><?php endforeach; ?></select></div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-link"></i>Asignar</button>
                </form></section>
            <section class="card">
                <h3><i class="fas fa-address-book"></i>Listado de Propietarios</h3>
                <?php if (!empty($owners_list)): ?>
                    <table class="data-table"><thead><tr><th>Foto</th><th>ID</th><th>Nombre</th><th>Contacto</th><th>Domicilio</th><th>Matrículas</th></tr></thead><tbody>
                    <?php foreach ($owners_list as $o): ?><tr>
                        <td><?php if (!empty($o['photo_url']) && file_exists(OWNER_PHOTOS_DIR . '/' . $o['photo_url'])): ?><img src="uploads/owner_photos/<?php echo htmlspecialchars($o['photo_url']); ?>?t=<?php echo time();?>" alt="Foto" style="width:40px; height:40px; border-radius:50%; object-fit:cover;"><?php else: ?><div style="width:40px; height:40px; background-color:#e9ecef; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:1.2em; color:#adb5bd;"><i class="fas fa-user-alt"></i></div><?php endif; ?></td>
                        <td><?php echo htmlspecialchars($o['id']); ?></td><td><?php echo htmlspecialchars($o['name']); ?></td>
                        <td><?php echo htmlspecialchars($o['email']); ?><?php if(!empty($o['whatsapp'])): ?><br><a href="https://wa.me/<?php echo htmlspecialchars($o['whatsapp']); ?>" target="_blank" style="color:var(--green-ok); text-decoration:none; font-weight:500;"><i class="fab fa-whatsapp"></i> <?php echo htmlspecialchars($o['whatsapp']); ?></a><?php endif; ?></td>
                        <td><?php echo htmlspecialchars($o['address']); ?></td>
                        <td><?php $aplts = []; foreach ($plate_owner_map as $pm => $om) { if ($om === $o['id']) { $aplts[] = htmlspecialchars($pm); }} echo !empty($aplts) ? implode(', ', $aplts) : 'Ninguna'; ?></td>
                    </tr><?php endforeach; ?></tbody></table>
                <?php else: ?><p>No hay propietarios.</p><?php endif; ?>
            </section>

            <?php elseif ($current_view === 'maps'): $current_map_path = get_map_image_path(); ?>
            <div class="page-header"><h2><i class="fas fa-draw-polygon"></i>Mapa del Parking y Lotes</h2></div>
            <section class="card">
                <h3><i class="fas fa-upload"></i>Subir/Actualizar Imagen del Mapa</h3>
                <form action="?view=maps" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_map">
                    <div class="form-group"><label for="map_image">Seleccionar archivo (JPG, PNG, GIF):</label><input type="file" id="map_image" name="map_image" accept="image/jpeg,image/png,image/gif" required></div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-cloud-upload-alt"></i>Subir Mapa</button>
                </form></section>
            <section class="card">
                <h3><i class="fas fa-map"></i>Visualización del Mapa</h3>
                <div class="map-container">
                <?php if ($current_map_path && file_exists($current_map_path)): ?>
                    <img src="uploads/maps/<?php echo basename($current_map_path); ?>?t=<?php echo time(); ?>" alt="Mapa del Parking">
                    <p style="text-align:center; margin-top:10px; font-style:italic; color:var(--text-secondary-color);">La funcionalidad de dibujar lotes sobre el mapa es un desarrollo futuro.</p>
                <?php else: ?><p class="no-map-message">Aún no se ha subido un mapa del parking.</p><?php endif; ?>
                </div></section>
            <?php endif; ?>
        </div>
    </div>
    <script>
        const eventList = document.getElementById('eventList');
        const searchInput = document.getElementById('searchInput');
        const loadingIndicator = document.getElementById('loadingIndicator');
        const initialEventsData = <?php echo json_encode($initial_events); ?>;
        let displayedEventKeys = new Set();
        let noEventsMessageElement = null;
        let searchTimeout = null;

        const carIconSvg = `<svg class="car-svg-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M18.92,6.08A1,1,0,0,0,18,5H6A1,1,0,0,0,5,6.08L3,12v5a1,1,0,0,0,1,1H5a1,1,0,0,0,1-1V16h.09a2.49,2.49,0,0,0,4.82,0H13.1a2.49,2.49,0,0,0,4.82,0H19v2a1,1,0,0,0,1,1h1a1,1,0,0,0,1-1V12ZM7.5,14A1.5,1.5,0,1,1,9,12.5,1.5,1.5,0,0,1,7.5,14Zm9,0A1.5,1.5,0,1,1,18,12.5,1.5,1.5,0,0,1,16.5,14ZM6,11l1.5-4.5h9L18,11Z"/></svg>`;
        const defaultOwnerPhotoPlaceholder = `<div class="car-icon-placeholder"><i class="fas fa-user"></i></div>`; // Icono de usuario por defecto

        function createEventKey(event) { return event.timestamp + "_" + event.plate + "_" + (event.device || 'unknown_dev'); }

        function createEventElement(event) {
            const item = document.createElement('li');
            item.classList.add('event-item');
            item.classList.add(event.is_whitelisted ? 'status-ok' : 'status-not-ok');
            item.dataset.key = createEventKey(event);

            let ownerPhotoHtml = '';
            if (event.owner && event.owner.photo_url && event.owner.photo_url !== '') {
                ownerPhotoHtml = `<img src="uploads/owner_photos/${event.owner.photo_url}?t=${new Date().getTime()}" alt="Foto" class="owner-photo">`;
            } else {
                ownerPhotoHtml = carIconSvg; // Usar icono de auto si no hay foto de propietario
            }
            
            let ownerDetailsHtml = '<p class="event-owner-details"><strong>Propietario:</strong> No Asignado</p>';
            if (event.owner) {
                ownerDetailsHtml = `
                    <p class="event-owner-details">
                        <strong>Propietario:</strong> ${event.owner.name || 'N/D'} <br>
                        <strong>Domicilio:</strong> ${event.owner.address || 'N/D'}
                        ${event.owner.whatsapp ? 
                            `<br><a href="https://wa.me/${event.owner.whatsapp.replace(/[^0-9]/g, '')}" target="_blank" class="whatsapp-link"><i class="fab fa-whatsapp"></i> ${event.owner.whatsapp}</a>`
                            : ''}
                    </p>`;
            }

            item.innerHTML = `
                <div class="event-item-main">
                    <div class="event-item-icon-col">
                        ${ownerPhotoHtml}
                    </div>
                    <div class="event-details">
                        <p class="event-plate">${event.plate}</p>
                        ${ownerDetailsHtml}
                        <p class="event-meta">
                            <span class="device-name">${event.device || 'Dispositivo Desconocido'}</span> - ${event.timestamp}
                        </p>
                    </div>
                </div>
                <div class="event-status ${event.is_whitelisted ? 'status-ok' : 'status-not-ok'}">
                    ${event.is_whitelisted ? '<i class="fas fa-check"></i> Permitido' : '<i class="fas fa-times"></i> Denegado'}
                </div>`;
            return item;
        }
        
        function showNoEventsMessage(message = 'Esperando eventos...') {
            if (eventList && !noEventsMessageElement && eventList.children.length === 0) {
                noEventsMessageElement = document.createElement('li');
                noEventsMessageElement.classList.add('no-events-message');
                noEventsMessageElement.textContent = message;
                eventList.appendChild(noEventsMessageElement);
            } else if (noEventsMessageElement) {
                noEventsMessageElement.textContent = message;
            }
        }
        function removeNoEventsMessage() {
            if (noEventsMessageElement && eventList) {
                try { eventList.removeChild(noEventsMessageElement); } catch(e) {}
                noEventsMessageElement = null;
            }
        }
        function renderEvents(events, prepend = false, isSearchResult = false) {
            if (!eventList) return;
            if (!isSearchResult) removeNoEventsMessage();
            const newEvents = events.filter(event => !displayedEventKeys.has(createEventKey(event)));
            if (newEvents.length === 0 && prepend && !isSearchResult) return;
            if (isSearchResult) { eventList.innerHTML = ''; displayedEventKeys.clear(); }
            else if (newEvents.length === 0 && !prepend && eventList.children.length === 0) { showNoEventsMessage(); return; }

            newEvents.forEach(event => {
                const eventElement = createEventElement(event);
                if (prepend && !isSearchResult) { eventList.insertBefore(eventElement, eventList.firstChild); }
                else { eventList.appendChild(eventElement); }
                displayedEventKeys.add(createEventKey(event));
            });
            if (!isSearchResult) {
                const maxItems = 30; 
                while (eventList.children.length > maxItems) {
                    if (eventList.lastChild && eventList.lastChild.dataset && eventList.lastChild.dataset.key) {
                        displayedEventKeys.delete(eventList.lastChild.dataset.key);
                    }
                    eventList.removeChild(eventList.lastChild);
                }
            }
            if (eventList.children.length === 0) { showNoEventsMessage(isSearchResult ? 'No se encontraron matrículas.' : 'Esperando eventos...'); }
        }
        async function fetchEvents(searchTerm = '') {
            if(loadingIndicator) loadingIndicator.classList.add('visible');
            try {
                const searchParam = searchTerm ? '&search=' + encodeURIComponent(searchTerm) : '';
                const response = await fetch(`?ajax=get_live_events&t=${new Date().getTime()}${searchParam}`);
                if (!response.ok) { console.error('Error fetching events:', response.status, response.statusText); if (eventList && eventList.children.length === 0) showNoEventsMessage('Error al cargar eventos.'); return []; }
                return await response.json();
            } catch (error) { console.error('Error en fetchEvents:', error); if (eventList && eventList.children.length === 0) showNoEventsMessage('Error de conexión.'); return []; }
            finally { if(loadingIndicator) { setTimeout(() => { loadingIndicator.classList.remove('visible'); }, 300);}}
        }
        async function updateLiveView(isSearch = false) { const searchTerm = searchInput ? searchInput.value : ''; const events = await fetchEvents(searchTerm); renderEvents(events, !isSearch, isSearch); }

        if (document.getElementById('eventList')) { // Solo ejecutar si estamos en la vista en vivo
             if (searchInput) { searchInput.addEventListener('input', () => { clearTimeout(searchTimeout); searchTimeout = setTimeout(() => { updateLiveView(true); }, 500); }); }
            renderEvents(initialEventsData, false, !!"<?php echo $initial_search_term; ?>");
            setInterval(() => { if (searchInput && searchInput.value === '') { updateLiveView(false); } }, 7000);
        }
    </script>
</body>
</html>