<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['logged'])) {
    header("Location: login.php");
    exit();
}
include 'connessione.php';

// Create operators table if it doesn't exist
$conn->query("CREATE TABLE IF NOT EXISTS operatori (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    cognome VARCHAR(255) NOT NULL,
    telefono VARCHAR(20),
    email VARCHAR(255),
    specialita TEXT,
    attivo TINYINT(1) DEFAULT 1,
    data_inserimento TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Add operatore_id column to prenotazioni table if it doesn't exist
$result = $conn->query("SHOW COLUMNS FROM prenotazioni LIKE 'operatore_id'");
if ($result->num_rows == 0) {
    $conn->query("ALTER TABLE prenotazioni ADD COLUMN operatore_id INT, ADD FOREIGN KEY (operatore_id) REFERENCES operatori(id)");
}

// In admin.php, nella sezione di inizializzazione
$result = $conn->query("SHOW COLUMNS FROM prenotazioni LIKE 'last_updated'");
if ($result->num_rows == 0) {
    $conn->query("ALTER TABLE prenotazioni ADD COLUMN last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
}

// Create admin settings table if it doesn't exist
$conn->query("CREATE TABLE IF NOT EXISTS admin_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_name VARCHAR(255) UNIQUE NOT NULL,
    setting_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Handle toggle revenue visibility
if (isset($_POST['toggle_revenue_visibility'])) {
    $current_setting = $conn->query("SELECT setting_value FROM admin_settings WHERE setting_name = 'hide_revenue'");
    if ($current_setting->num_rows > 0) {
        $row = $current_setting->fetch_assoc();
        $new_value = $row['setting_value'] == '1' ? '0' : '1';
        $conn->query("UPDATE admin_settings SET setting_value = '$new_value' WHERE setting_name = 'hide_revenue'");
    } else {
        $conn->query("INSERT INTO admin_settings (setting_name, setting_value) VALUES ('hide_revenue', '1')");
    }
    header("Location: admin.php");
    exit();
}

// Get revenue visibility setting
$revenue_hidden = false;
$revenue_setting = $conn->query("SELECT setting_value FROM admin_settings WHERE setting_name = 'hide_revenue'");
if ($revenue_setting->num_rows > 0) {
    $row = $revenue_setting->fetch_assoc();
    $revenue_hidden = $row['setting_value'] == '1';
}

// Handle booking actions
// Verifica se la colonna 'stato' esiste (solo una volta per richiesta)
$result = $conn->query("SHOW COLUMNS FROM prenotazioni LIKE 'stato'");
$stato_exists = ($result && $result->num_rows > 0);

// Se non esiste, la aggiungiamo (potresti volerlo fare una volta sola altrove)
if (!$stato_exists) {
    $conn->query("ALTER TABLE prenotazioni ADD COLUMN stato VARCHAR(50) DEFAULT 'In attesa'");
}

// Verifica se la colonna 'last_updated' esiste
$result = $conn->query("SHOW COLUMNS FROM prenotazioni LIKE 'last_updated'");
$last_updated_exists = ($result && $result->num_rows > 0);

if (!$last_updated_exists) {
    // Aggiungi la colonna last_updated con default CURRENT_TIMESTAMP aggiornato ad ogni modifica
    $conn->query("ALTER TABLE prenotazioni ADD COLUMN last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
}

// Gestione azioni
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    if ($_GET['action'] === 'confirm') {
        $conn->query("UPDATE prenotazioni SET stato = 'Confermata'" . 
                     ($last_updated_exists ? ", last_updated = CURRENT_TIMESTAMP" : "") . 
                     " WHERE id = $id");
    } elseif ($_GET['action'] === 'cancel') {
        $conn->query("UPDATE prenotazioni SET stato = 'Cancellata'" . 
                     ($last_updated_exists ? ", last_updated = CURRENT_TIMESTAMP" : "") . 
                     " WHERE id = $id");
    }
    header("Location: admin.php");
    exit();
}


// Handle add service
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_service'])) {
    $nome = $conn->real_escape_string(trim($_POST['nome_servizio']));
    $prezzo = floatval($_POST['prezzo_servizio']);
    if ($nome !== '' && $prezzo > 0) {
        // Check if servizi table exists and has the right columns
        $result = $conn->query("SHOW TABLES LIKE 'servizi'");
        if ($result->num_rows == 0) {
            // Create servizi table if it doesn't exist
            $conn->query("CREATE TABLE servizi (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nome VARCHAR(255) NOT NULL,
                prezzo DECIMAL(10,2) NOT NULL
            )");
        }
        $conn->query("INSERT INTO servizi (nome, prezzo) VALUES ('$nome', $prezzo)");
        $add_success_message = "Servizio aggiunto con successo."; // Messaggio specifico per aggiunta
        header("Location: admin.php");
        exit();
    } else {
        $add_error_message = "Inserisci un nome valido e un prezzo maggiore di 0."; // Messaggio specifico per aggiunta
    }
}

// Handle remove service
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_service'])) {
    $servizio_id = intval($_POST['servizio_id']);
    if ($servizio_id > 0) {
        // Check if service exists and get its name
        $check_service = $conn->query("SELECT nome FROM servizi WHERE id = $servizio_id");
        if ($check_service && $check_service->num_rows > 0) {
            $service_row = $check_service->fetch_assoc();
            $service_name = $service_row['nome'];
            
            // Check if there are any bookings with this service
            $check_bookings = $conn->query("SELECT COUNT(*) as count FROM prenotazioni WHERE servizio = '" . $conn->real_escape_string($service_name) . "'");
            if ($check_bookings) {
                $booking_count = $check_bookings->fetch_assoc()['count'];
                if ($booking_count > 0) {
                    $remove_error_message = "Impossibile eliminare il servizio '$service_name' perché è presente in $booking_count prenotazione/i."; // Messaggio specifico per rimozione
                } else {
                    // Safe to delete
                    $conn->query("DELETE FROM servizi WHERE id = $servizio_id");
                    $remove_success_message = "Servizio eliminato con successo."; // Messaggio specifico per rimozione
                }
            }
        } else {
            $remove_error_message = "Servizio non trovato."; // Messaggio specifico per rimozione
        }
    }
}

// Handle clear revenues - FIXED: Now properly clears all revenue data
if (isset($_POST['clear_ricavi'])) {
    // Check if storico_ricavi table exists
    $result = $conn->query("SHOW TABLES LIKE 'storico_ricavi'");
    if ($result->num_rows > 0) {
        // Truncate the storico_ricavi table
        $conn->query("TRUNCATE TABLE storico_ricavi");
    }
    
    // Check if escludi_ricavi column exists, if not create it
    $result_check = $conn->query("SHOW COLUMNS FROM prenotazioni LIKE 'escludi_ricavi'");
    if ($result_check->num_rows == 0) {
        $conn->query("ALTER TABLE prenotazioni ADD COLUMN escludi_ricavi TINYINT(1) DEFAULT 0");
    }
    
    // Mark ALL bookings as excluded from revenue calculation (not just confirmed ones)
    $conn->query("UPDATE prenotazioni SET escludi_ricavi = 1");
    
    $success_message = "Tutti i ricavi sono stati eliminati. Le prenotazioni rimangono visibili ma non vengono più conteggiate nei ricavi.";
    header("Location: admin.php");
    exit();
}

// Handle clear bookings
if (isset($_POST['clear_prenotazioni'])) {
    // Check if storico_ricavi table exists
    $result = $conn->query("SHOW TABLES LIKE 'storico_ricavi'");
    if ($result->num_rows == 0) {
        $conn->query("CREATE TABLE storico_ricavi (
            id INT AUTO_INCREMENT PRIMARY KEY,
            data DATE NOT NULL UNIQUE,
            ricavo DECIMAL(10,2) NOT NULL DEFAULT 0
        )");
    }

    // Check if we have the necessary columns and tables
    $servizi_exists = $conn->query("SHOW TABLES LIKE 'servizi'")->num_rows > 0;
    $stato_exists = $conn->query("SHOW COLUMNS FROM prenotazioni LIKE 'stato'")->num_rows > 0;
    $escludi_exists = $conn->query("SHOW COLUMNS FROM prenotazioni LIKE 'escludi_ricavi'")->num_rows > 0;
    
    if ($servizi_exists && $stato_exists) {
        // Only save revenues from bookings that are not excluded
        $where_condition = "p.stato = 'Confermata'";
        if ($escludi_exists) {
            $where_condition .= " AND (p.escludi_ricavi = 0 OR p.escludi_ricavi IS NULL)";
        }
        
        $ricavi_query = $conn->query("
            SELECT p.data_prenotazione, SUM(s.prezzo) as totale
            FROM prenotazioni p 
            JOIN servizi s ON p.servizio = s.nome
            WHERE $where_condition
            GROUP BY p.data_prenotazione
        ");

        if ($ricavi_query) {
            while ($row = $ricavi_query->fetch_assoc()) {
                $data = $conn->real_escape_string($row['data_prenotazione']);
                $totale = floatval($row['totale']);
                $conn->query("
                    INSERT INTO storico_ricavi (data, ricavo)
                    VALUES ('$data', $totale)
                    ON DUPLICATE KEY UPDATE ricavo = ricavo + VALUES(ricavo)
                ");
            }
        }
    }

    $conn->query("DELETE FROM prenotazioni");

    header("Location: admin.php");
    exit();
}

// Get statistics - with error handling
$statistiche = ['Confermata' => 0, 'In attesa' => 0, 'Cancellata' => 0];

// Check if stato column exists
$stato_exists = $conn->query("SHOW COLUMNS FROM prenotazioni LIKE 'stato'")->num_rows > 0;

if ($stato_exists) {
    $totali = $conn->query("SELECT stato, COUNT(*) as totale FROM prenotazioni WHERE stato != 'Cancellata' GROUP BY stato");
    if ($totali) {
        while ($row = $totali->fetch_assoc()) {
            $statistiche[$row['stato']] = $row['totale'];
        }
    }
    // Aggiungi il conteggio delle cancellate separatamente
    $cancellate = $conn->query("SELECT COUNT(*) as totale FROM prenotazioni WHERE stato = 'Cancellata'");
    if ($cancellate) {
        $cancellate_row = $cancellate->fetch_assoc();
        $statistiche['Cancellata'] = $cancellate_row['totale'];
    }
} else {
    // If no stato column, just count total bookings
    $total_result = $conn->query("SELECT COUNT(*) as totale FROM prenotazioni");
    if ($total_result) {
        $total_row = $total_result->fetch_assoc();
        $statistiche['In attesa'] = $total_row['totale'];
    }
}

// Get recent bookings with operator info
$prenotazioni_query = "SELECT p.*, CONCAT(o.nome, ' ', o.cognome) as operatore_nome 
                      FROM prenotazioni p 
                      LEFT JOIN operatori o ON p.operatore_id = o.id 
                      WHERE p.stato != 'Cancellata' 
                      ORDER BY p.data_prenotazione DESC, p.id DESC";
// Check if we have data_prenotazione column
$data_col_exists = $conn->query("SHOW COLUMNS FROM prenotazioni LIKE 'data_prenotazione'")->num_rows > 0;
if ($data_col_exists) {
    $prenotazioni = $conn->query($prenotazioni_query);
}


$prenotazioni = $conn->query($prenotazioni_query);

// Calculate total revenue - FIXED: Now properly excludes marked revenues
$totale_ricavi = 0;
$servizi_exists = $conn->query("SHOW TABLES LIKE 'servizi'")->num_rows > 0;
$escludi_exists = $conn->query("SHOW COLUMNS FROM prenotazioni LIKE 'escludi_ricavi'")->num_rows > 0;

if ($servizi_exists && $stato_exists) {
    $where_condition = "p.stato = 'Confermata'";
    if ($escludi_exists) {
        $where_condition .= " AND (p.escludi_ricavi = 0 OR p.escludi_ricavi IS NULL)";
    }
    
    $entrate = $conn->query("SELECT SUM(s.prezzo) as totale FROM prenotazioni p JOIN servizi s ON p.servizio = s.nome WHERE $where_condition");
    if ($entrate) {
        $entrate_row = $entrate->fetch_assoc();
        $totale_ricavi = $entrate_row['totale'] ?? 0;
    }
}

// Get revenue data - FIXED: Now properly excludes marked revenues
$ricavi_tutti_giorni = [];

// Check if storico_ricavi exists
$storico_exists = $conn->query("SHOW TABLES LIKE 'storico_ricavi'")->num_rows > 0;
if ($storico_exists) {
    $result_storico = $conn->query("SELECT data, ricavo FROM storico_ricavi");
    if ($result_storico) {
        while ($row = $result_storico->fetch_assoc()) {
            $ricavi_tutti_giorni[$row['data']] = floatval($row['ricavo']);
        }
    }
}

if ($servizi_exists && $stato_exists && $data_col_exists) {
    // Check if escludi_ricavi column exists
    $escludi_exists = $conn->query("SHOW COLUMNS FROM prenotazioni LIKE 'escludi_ricavi'")->num_rows > 0;
    
    $where_condition = "p.stato = 'Confermata'";
    if ($escludi_exists) {
        $where_condition .= " AND (p.escludi_ricavi = 0 OR p.escludi_ricavi IS NULL)";
    }
    
    $result_ricavi = $conn->query("
        SELECT p.data_prenotazione, SUM(s.prezzo) as totale
        FROM prenotazioni p 
        JOIN servizi s ON p.servizio = s.nome
        WHERE $where_condition
        GROUP BY p.data_prenotazione
    ");
    if ($result_ricavi) {
        while ($row = $result_ricavi->fetch_assoc()) {
            $data = $row['data_prenotazione'];
            $totale = floatval($row['totale']);
            if (isset($ricavi_tutti_giorni[$data])) {
                $ricavi_tutti_giorni[$data] += $totale;
            } else {
                $ricavi_tutti_giorni[$data] = $totale;
            }
        }
    }
}

krsort($ricavi_tutti_giorni);

// Get unique dates for prenotazioni filter
$date_prenotazioni = array();
if ($prenotazioni && $data_col_exists) {
    mysqli_data_seek($prenotazioni, 0);
    while ($row = $prenotazioni->fetch_assoc()) {
        if (isset($row['data_prenotazione'])) {
            $date_prenotazioni[$row['data_prenotazione']] = date('d/m/Y', strtotime($row['data_prenotazione']));
        }
    }
    mysqli_data_seek($prenotazioni, 0);
}

// Get all services for removal dropdown
$servizi_list = [];
if ($servizi_exists) {
    $servizi_query = $conn->query("SELECT id, nome, prezzo FROM servizi ORDER BY nome");
    if ($servizi_query) {
        while ($row = $servizi_query->fetch_assoc()) {
            $servizi_list[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Admin Dashboard - Old School Barber</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
       * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    background: linear-gradient(135deg, #0f0f23 0%, #1a1a2e 50%, #16213e 100%);
    color: #ffffff;
    min-height: 100vh;
}

/* Mobile Menu Button */
.mobile-menu-btn {
    display: none;
    position: fixed;
    top: 1rem;
    left: 1rem;
    z-index: 1001;
    background: #d4af37;
    border: none;
    border-radius: 8px;
    color: #1a1a2e;
    padding: 0.8rem;
    font-size: 1.2rem;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
    transition: all 0.3s ease;
}

.mobile-menu-btn:hover {
    background: #ffd700;
    transform: scale(1.05);
}

/* Sidebar */
.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: 80px;
    height: 100vh;
    background: rgba(255, 255, 255, 0.05);
    backdrop-filter: blur(20px);
    border-right: 1px solid rgba(255, 255, 255, 0.1);
    padding: 2rem 0;
    z-index: 1000;
    transition: all 0.3s ease;
    
}

.sidebar.expanded {
    width: 280px;
}

.sidebar-header {
    padding: 0 1.5rem;
    margin-bottom: 3rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    justify-content: center;
}

.sidebar.expanded .sidebar-header {
    padding: 0 2rem;
    justify-content: flex-start;
}

.sidebar-logo {
    font-size: 2rem;
    color: #d4af37;
}

.sidebar-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: #ffffff;
    transition: opacity 0.3s ease;
    opacity: 0;
    width: 0;
    overflow: hidden;
}

.sidebar.expanded .sidebar-title {
    opacity: 1;
    width: auto;
}

.sidebar-toggle {
    position: absolute;
    top: 1rem;
    right: -29px;
    width: 40px;
    height: 40px;
    background: #d4af37;
    border: none;
    border-radius: 50%;
    color: #1a1a2e;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8rem;
    transition: all 0.3s ease;
}

.sidebar-toggle:hover {
    background: #ffd700;
    transform: scale(1.1);
}

.sidebar-nav {
    list-style: none;
    padding: 0 1rem;
}

.sidebar-nav li {
    margin-bottom: 0.5rem;
}

.sidebar-nav a {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem 0.5rem;
    color: #a0a0a0;
    text-decoration: none;
    border-radius: 12px;
    transition: all 0.3s ease;
    font-weight: 500;
    justify-content: center;
}

.sidebar.expanded .sidebar-nav a {
    padding: 1rem;
    justify-content: flex-start;
}

.sidebar-nav a:hover,
.sidebar-nav a.active {
    background: rgba(212, 175, 55, 0.1);
    color: #d4af37;
    transform: translateX(5px);
}

.sidebar-nav i {
    font-size: 1.2rem;
    width: 20px;
    text-align: center;
}

.sidebar-nav span {
    display: none;
}

.sidebar.expanded .sidebar-nav span {
    display: inline;
}

/* Main Content */
.main {
    margin-left: 80px;
    padding: 2rem;
    transition: margin-left 0.3s ease;
    min-height: 100vh;
}

.main.expanded {
    margin-left: 280px;
}

/* Header */
.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
    padding: 1.5rem 2rem;
    background: rgba(255, 255, 255, 0.05);
    backdrop-filter: blur(20px);
    border-radius: 16px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    flex-wrap: wrap;
    gap: 1rem;
}

.header-title {
    font-size: 1.8rem;
    font-weight: 700;
    color: #ffffff;
}

.header-user {
    display: flex;
    align-items: center;
    gap: 1rem;
    background: rgba(212, 175, 55, 0.1);
    padding: 0.8rem 1.5rem;
    border-radius: 12px;
    border: 1px solid rgba(212, 175, 55, 0.2);
}

.header-user i {
    color: #d4af37;
}

/* Stats Cards */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: rgba(255, 255, 255, 0.05);
    backdrop-filter: blur(20px);
    border-radius: 16px;
    padding: 2rem;
    border: 1px solid rgba(255, 255, 255, 0.1);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, #d4af37, #ffd700);
}

.stat-card:hover {
    transform: translateY(-5px);
    background: rgba(255, 255, 255, 0.08);
}

.stat-card h3 {
    font-size: 0.9rem;
    font-weight: 600;
    color: #a0a0a0;
    margin-bottom: 1rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.stat-card .value {
    font-size: 2.5rem;
    font-weight: 800;
    color: #ffffff;
    margin-bottom: 0.5rem;
}

.stat-card .change {
    font-size: 0.85rem;
    color: #4ade80;
    font-weight: 500;
}

/* Revenue Toggle Switch */
.revenue-toggle {
    position: relative;
    display: inline-block;
    width: 40px;
    height: 20px;
}

.revenue-toggle input {
    opacity: 0;
    width: 0;
    height: 0;
}

.revenue-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .4s;
    border-radius: 20px;
}

.revenue-slider:before {
    position: absolute;
    content: "";
    height: 16px;
    width: 16px;
    left: 2px;
    bottom: 2px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
}

input:checked + .revenue-slider {
    background-color: #d4af37;
}

input:checked + .revenue-slider:before {
    transform: translateX(20px);
}

.revenue-hidden {
    filter: blur(8px);
    user-select: none;
    pointer-events: none;
}


/* Content Grid */
.content-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 2rem;
    margin-bottom: 2rem;
}

.content-card {
    background: rgba(255, 255, 255, 0.05);
    backdrop-filter: blur(20px);
    border-radius: 16px;
    padding: 2rem;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.content-card h3 {
    font-size: 1.3rem;
    font-weight: 700;
    color: #ffffff;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.content-card h3 i {
    color: #d4af37;
}

/* Fixed width for revenue report */
.revenue-card {
    width: 100%; /* Cambiato da 80vw */
    max-width: 100%; /* Cambiato da 700px */
    min-width: 0; /* Cambiato da 300px */
}

/* Table */
.table-container {
    overflow-x: auto;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.02);
    scrollbar-width: thin;
    scrollbar-color: #d4af37 rgba(255, 255, 255, 0.1);
}

.table-container::-webkit-scrollbar {
    height: 8px;
}

.table-container::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 4px;
}

.table-container::-webkit-scrollbar-thumb {
    background: #d4af37;
    border-radius: 4px;
}

.table-container::-webkit-scrollbar-thumb:hover {
    background: #ffd700;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 800px;
}

/* Revenue table specific styling */
.revenue-table {
table-layout: fixed; /* Forza larghezze fisse delle colonne */
    width: 100%;
}

th, td {
    padding: 1rem 0.8rem;
    text-align: left;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    white-space: nowrap;
}
/* AGGIUNGI QUI LE NUOVE REGOLE */
.revenue-table th,
.revenue-table td {
    width: 50%; /* Distribuisce equamente lo spazio tra le due colonne */
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Modifica la classe table-container quando usata con revenue-table */
.table-container .revenue-table {
    min-width: 0 !important; /* Sovrascrive il min-width precedente */
}

th {
    background: rgba(255, 255, 255, 0.05);
    font-weight: 600;
    color: #d4af37;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    position: sticky;
    top: 0;
    z-index: 10;
}

td {
    color: #e0e0e0;
    font-weight: 400;
    font-size: 0.9rem;
}

tr:hover {
    background: rgba(255, 255, 255, 0.02);
}

/* Status Badges */
.status {
    padding: 0.4rem 0.8rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: inline-block;
    min-width: 80px;
    text-align: center;
    white-space: nowrap;
}

.status.confermata {
    background: rgba(34, 197, 94, 0.2);
    color: #4ade80;
    border: 1px solid rgba(34, 197, 94, 0.3);
}

.status.in-attesa {
    background: rgba(251, 191, 36, 0.25);
    color: #fbbf24;
    border: 1px solid rgba(251, 191, 36, 0.4);
    font-weight: 700;
}

.status.cancellata {
    background: rgba(239, 68, 68, 0.2);
    color: #f87171;
    border: 1px solid rgba(239, 68, 68, 0.3);
}

/* Action Buttons */
.action-btn {
    padding: 0.5rem 1rem;
    border: none;
    border-radius: 8px;
    font-size: 0.8rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    margin: 0 0.2rem;
    white-space: nowrap;
}

.action-btn.confirm {
    background: rgba(34, 197, 94, 0.2);
    color: #4ade80;
    border: 1px solid rgba(34, 197, 94, 0.3);
}

.action-btn.cancel {
    background: rgba(239, 68, 68, 0.2);
    color: #f87171;
    border: 1px solid rgba(239, 68, 68, 0.3);
}

.action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
}

/* Forms */
.form-section {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 2rem;
    margin-top: 2rem;
}

.form-card {
    background: rgba(255, 255, 255, 0.05);
    backdrop-filter: blur(20px);
    border-radius: 16px;
    padding: 2rem;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.form-card.success {
    border-color: rgba(34, 197, 94, 0.3);
    background: rgba(34, 197, 94, 0.05);
}

.form-card.danger {
    border-color: rgba(239, 68, 68, 0.3);
    background: rgba(239, 68, 68, 0.05);
}

.form-card.warning {
    border-color: rgba(251, 191, 36, 0.3);
    background: rgba(251, 191, 36, 0.05);
}

.form-card h3 {
    font-size: 1.2rem;
    font-weight: 700;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.form-card.success h3 {
    color: #4ade80;
}

.form-card.danger h3 {
    color: #f87171;
}

.form-card.warning h3 {
    color: #fbbf24;
}

.form-group {
    margin-bottom: 1rem;
}

.form-group input, .form-group select {
    width: 100%;
    padding: 0.8rem 1rem;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 8px;
    color: #ffffff; /* Fire red color for form text */
    font-size: 0.9rem;
    transition: all 0.3s ease;
}

.form-group input:focus, .form-group select:focus {
    outline: none;
    border-color: #d4af37;
    background: rgba(255, 255, 255, 0.12);
}

.form-group input::placeholder {
    color: #a0a0a0;
}

.form-group select {
    background: rgba(255, 255, 255, 0.08);
    color: #ffffff !important; /* Fire red color for form text */
}

.form-group select option {
    background: #1a1a2e !important;
    color: #ffffff !important; /* Fire red color for form text */
    padding: 0.5rem;
}

.submit-btn {
    width: 100%;
    padding: 1rem;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 0.9rem;
    margin-bottom: 0.5rem;
}

.submit-btn.success {
    background: linear-gradient(135deg, #22c55e, #16a34a);
    color: white;
}

.submit-btn.danger {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
}

.submit-btn.warning {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: white;
}

.submit-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
}

/* Message Styles */
.error-message, .success-message {
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
    font-size: 0.9rem;
    text-align: center;
}

.error-message {
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    color: #f87171;
}

.success-message {
    background: rgba(34, 197, 94, 0.1);
    border: 1px solid rgba(34, 197, 94, 0.3);
    color: #4ade80;
}

/* Real-time update indicator */
.update-indicator {
    position: fixed;
    top: 1rem;
    right: 1rem;
    background: rgba(34, 197, 94, 0.1);
    border: 1px solid rgba(34, 197, 94, 0.3);
    color: #4ade80;
    padding: 0.5rem 1rem;
    border-radius: 8px;
    font-size: 0.8rem;
    font-weight: 600;
    z-index: 1000;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.update-indicator.show {
    opacity: 1;
}

/* Responsive Design */
@media (max-width: 1200px) {
    .content-grid {
        grid-template-columns: 1fr;
    }
    
    .revenue-card {
        max-width: none;
        width: 100%;
    }
    
    .stats-grid {
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    }
}

@media (max-width: 1024px) {
    .form-section {
        grid-template-columns: 1fr;
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .stat-card .value {
        font-size: 2rem;
    }
}

@media (max-width: 768px) {
    .mobile-menu-btn {
        display: block;
    }
    
    .sidebar {
        transform: translateX(-100%);
        width: 280px;
    }

    .sidebar.mobile-open {
        transform: translateX(0);
    }

    .main {
        margin-left: 0;
        padding: 1rem;
        padding-top: 4rem;
    }

    .main.expanded {
        margin-left: 0;
    }

    .header {
        padding: 1rem;
        flex-direction: column;
        text-align: center;
    }

    .header-title {
        font-size: 1.4rem;
    }

    .stats-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    
    .stat-card {
        padding: 1.5rem;
    }

    .content-card {
        padding: 1.5rem;
    }

    .table-container {
        margin: 0 -1rem;
        border-radius: 0;
    }
    
    th, td {
        padding: 0.8rem 0.5rem;
        font-size: 0.8rem;
    }
    
    .action-btn {
        padding: 0.3rem 0.6rem;
        font-size: 0.7rem;
        margin: 0.1rem;
    }
    
    .form-section {
        gap: 1rem;
    }
    
    .form-card {
        padding: 1.5rem;
    }

    .update-indicator {
        top: 4.5rem;
        right: 0.5rem;
        font-size: 0.7rem;
        padding: 0.4rem 0.8rem;
    }
}

@media (max-width: 480px) {
    .main {
        padding: 0.5rem;
        padding-top: 3.5rem;
    }
    
    .header {
        padding: 0.8rem;
        margin-bottom: 1rem;
    }
    
    .header-title {
        font-size: 1.2rem;
    }
    
    .stat-card {
        padding: 1rem;
    }
    
    .stat-card .value {
        font-size: 1.8rem;
    }
    
    .content-card {
        padding: 1rem;
    }
    
    .content-card h3 {
        font-size: 1.1rem;
    }
    
    .form-card {
        padding: 1rem;
    }
    
    .form-card h3 {
        font-size: 1rem;
    }
    
    .action-btn {
        display: block;
        margin: 0.2rem 0;
        text-align: center;
        width: 100%;
    }
    
    th, td {
        padding: 0.6rem 0.3rem;
        font-size: 0.75rem;
    }
}

@media (max-width: 360px) {
    .main {
        padding: 0.3rem;
        padding-top: 3rem;
    }
    
    .header-title {
        font-size: 1.1rem;
    }
    
    .stat-card .value {
        font-size: 1.5rem;
    }
    
    .form-group input, .form-group select {
        padding: 0.6rem 0.8rem;
        font-size: 0.85rem;
    }
    
    .submit-btn {
        padding: 0.8rem;
        font-size: 0.85rem;
    }
}

/* Landscape orientation adjustments */
@media (max-height: 600px) and (orientation: landscape) {
    .sidebar {
        padding: 1rem 0;
    }
    
    .sidebar-header {
        margin-bottom: 1.5rem;
    }
    
    .main {
        padding: 1rem;
    }
    
    .header {
        padding: 1rem;
        margin-bottom: 1rem;
    }
    
    .stat-card {
        padding: 1rem;
    }
    
    .stat-card .value {
        font-size: 1.8rem;
    }
}

/* Touch device optimizations */
@media (hover: none) and (pointer: coarse) {
    .action-btn, .submit-btn, .sidebar-nav a {
        min-height: 44px;
        min-width: 44px;
    }
    
    .sidebar-toggle {
        width: 44px;
        height: 44px;
    }
    
    .mobile-menu-btn {
        padding: 1rem;
        min-height: 44px;
        min-width: 44px;
    }
}

/* High DPI displays */
@media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 192dpi) {
    .sidebar-logo {
        filter: drop-shadow(0 0 5px rgba(212, 175, 55, 0.3));
    }
}

/* Accessibility improvements */
@media (prefers-reduced-motion: reduce) {
    * {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
}

/* Print styles */
@media print {
    .sidebar, .mobile-menu-btn {
        display: none;
    }
    
    .main {
        margin-left: 0;
        padding: 0;
    }
    
    .header, .form-section {
        display: none;
    }
    
    .content-card {
        background: white;
        color: black;
        border: 1px solid #ccc;
    }
    
    .table-container {
        overflow: visible;
    }
    
    table {
        min-width: auto;
    }
}

    </style>
</head>
<body>

<div class="update-indicator" id="updateIndicator">
    <i class="fas fa-sync-alt"></i>
    Aggiornamento in tempo reale attivo
</div>

<div class="sidebar" id="sidebar">
    <button class="sidebar-toggle" onclick="toggleSidebar()">
        <i class="fas fa-chevron-right"></i>
    </button>
    
    <div class="sidebar-header">
        <i class="fas fa-cut sidebar-logo"></i>
        <span class="sidebar-title">Admin Panel</span>
    </div>
    
    <ul class="sidebar-nav">
        <li><a href="#" class="active"><i class="fas fa-home"></i><span>Dashboard</span></a></li>
        <li><a href="gestione_prenotazioni.php"><i class="fas fa-calendar-alt"></i><span>Prenotazioni</span></a></li>
        <li><a href="gestione_operatori.php"><i class="fas fa-scissors"></i><span>Operatori</span></a></li>
    <li><a href="impostazioni.php"><i class="fas fa-cog"></i><span>Impostazioni</span></a></li>
        <li><a href="index.php"><i class="fas fa-arrow-left"></i><span>Torna al sito</span></a></li>
    </ul>
</div>

<div class="main" id="main">
    <div class="header">
        <h1 class="header-title">Dashboard Amministratore</h1>
        <div class="header-user">
            <i class="fas fa-user-shield"></i>
            <span>Admin</span>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <h3>Prenotazioni Confermate</h3>
            <div class="value" id="stat-confermata"><?php echo $statistiche['Confermata']; ?></div>
            <div class="change"></div>
        </div>
        <div class="stat-card">
            <h3>In Attesa</h3>
            <div class="value" id="stat-attesa"><?php echo $statistiche['In attesa']; ?></div>
            <div class="change">Richiedono attenzione</div>
        </div>
        <div class="stat-card">
            <h3>Cancellate</h3>
            <div class="value" id="stat-cancellata"><?php echo $statistiche['Cancellata']; ?></div>
            <div class="change"></div>
        </div>
        <div class="stat-card">
            <h3>
                Ricavi Totali
<form method="POST" style="display: inline;">
    <label class="revenue-toggle">
        <input type="checkbox" name="toggle_revenue" <?php echo $revenue_hidden ? '' : 'checked'; ?> onchange="this.form.submit()">
        <span class="revenue-slider"></span>
        <input type="hidden" name="toggle_revenue_visibility" value="1">
    </label>
</form>
            </h3>
            <div class="value <?php echo $revenue_hidden ? 'revenue-hidden' : ''; ?>" id="stat-ricavi">€<?php echo number_format($totale_ricavi, 2); ?></div>
            <div class="change"></div>
        </div>
    </div>

    <div class="content-grid">
        <div class="content-card">
            <h3><i class="fas fa-calendar-check"></i>Ultime Prenotazioni</h3>
            
            <!-- Filtro per data prenotazioni -->
            <div class="form-group" style="margin-bottom: 1rem;">
                <select id="data_prenotazioni" 
                        onchange="filtraPrenotazioni(this.value)" 
                        class="dropdown-filter">
                    <option value="">Tutte le date</option>
                    <?php foreach (array_unique($date_prenotazioni) as $data => $data_formattata): ?>
                        <option value="<?php echo htmlspecialchars($data); ?>">
                            <?php echo htmlspecialchars($data_formattata); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Cliente</th>
                            <th>Telefono</th>
                            <?php if ($data_col_exists): ?>
                            <th>Data</th>
                            <?php endif; ?>
                            <th>Ora</th>
                            <th>Servizio</th>
                            <th>Operatore</th>
                            <?php if ($stato_exists): ?>
                            <th>Stato</th>
                            <th>Azioni</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody id="bookings-table-body">
                        <?php if ($prenotazioni && $prenotazioni->num_rows > 0): ?>
                            <?php $i = 1; while ($row = $prenotazioni->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $i++; ?></td>
                                <td><?php echo htmlspecialchars($row['nome'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($row['telefono'] ?? 'N/A'); ?></td>
                                <?php if ($data_col_exists): ?>
                                <td>
                                    <?php 
                                    if (isset($row['data_prenotazione']) && $row['data_prenotazione']) {
                                        $data = date_create($row['data_prenotazione']);
                                        echo $data ? date_format($data, 'd/m/Y') : 'N/A';
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </td>
                                <?php endif; ?>
                                <td><?php echo isset($row['orario']) ? date('H:i', strtotime($row['orario'])) : 'N/A'; ?></td>
                                <td><?php echo htmlspecialchars($row['servizio'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($row['operatore_nome'] ?? 'Non assegnato'); ?></td>
                                <?php if ($stato_exists): ?>
                                <td>
                                    <span class="status <?php 
                                        $stato = $row['stato'] ?? 'In attesa';
                                        if ($stato === 'Confermata') echo 'confermata'; 
                                        elseif ($stato === 'In attesa') echo 'in-attesa'; 
                                        elseif ($stato === 'Cancellata') echo 'cancellata'; 
                                    ?>">
                                        <?php echo htmlspecialchars($stato); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="?action=confirm&id=<?php echo $row['id']; ?>" 
                                       class="action-btn confirm" 
                                       onclick="return confirm('Confermare questa prenotazione?')">
                                        <i class="fas fa-check"></i>Conferma
                                    </a>
                                    <a href="?action=cancel&id=<?php echo $row['id']; ?>" 
                                       class="action-btn cancel" 
                                       onclick="return confirm('Cancellare questa prenotazione?')">
                                        <i class="fas fa-times"></i>Cancella
                                    </a>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" style="text-align: center; color: #a0a0a0;">
                                    Nessuna prenotazione trovata
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="content-card revenue-card">
            <h3><i class="fas fa-chart-line"></i>Report Ricavi</h3>
            <!-- Filtro per data ricavi -->
            <div class="form-group" style="margin-bottom: 1rem;">
                <select id="data_ricavi" 
                        onchange="filtraRicavi(this.value)" 
                        class="dropdown-filter">
                    <option value="">Tutte le date</option>
                    <?php foreach ($ricavi_tutti_giorni as $data => $ricavo): ?>
                        <option value="<?php echo htmlspecialchars($data); ?>">
                            <?php echo date('d/m/Y', strtotime($data)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!empty($ricavi_tutti_giorni)): ?>
                <div class="table-container <?php echo $revenue_hidden ? 'revenue-hidden' : ''; ?>">
                    <table id="tabella_ricavi" class="revenue-table">
                        <thead>
                            <tr><th>Data</th><th>Ricavi</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ricavi_tutti_giorni as $data => $ricavo): ?>
                            <tr>
                                <td><?php echo date('d/m/Y', strtotime($data)); ?></td>
                                <td>€<?php echo number_format($ricavo, 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p style="color: #a0a0a0; text-align: center; padding: 2rem;">
                    Nessun dato sui ricavi disponibile
                </p>
            <?php endif; ?>
        </div>
    </div>

    <div class="form-section">
        <div class="form-card success">
            <h3><i class="fas fa-plus-circle"></i>Aggiungi Servizio</h3>
            <?php if (!empty($error_message)): ?>
                <div class="error-message"><?php echo $error_message; ?></div>
            <?php endif; ?>
            <?php if (!empty($success_message)): ?>
                <div class="success-message"><?php echo $success_message; ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <input type="text" name="nome_servizio" placeholder="Nome servizio" required />
                </div>
                <div class="form-group">
                    <input type="number" step="0.01" name="prezzo_servizio" placeholder="Prezzo (€)" required />
                </div>
                <button type="submit" name="add_service" class="submit-btn success">
                    <i class="fas fa-plus"></i> Aggiungi Servizio
                </button>
            </form>
        </div>

        <div class="form-card warning">
            <h3><i class="fas fa-minus-circle"></i>Rimuovi Servizio</h3>
            <?php if (empty($servizi_list)): ?>
                <p style="color: #a0a0a0; text-align: center; padding: 1rem;">
                    Nessun servizio disponibile da rimuovere
                </p>
            <?php else: ?>
                <form method="POST">
                    <div class="form-group">
                        <select name="servizio_id" required>
                            <option value="" disabled selected>Seleziona servizio da rimuovere</option>
                            <?php foreach ($servizi_list as $servizio): ?>
                                <option value="<?php echo $servizio['id']; ?>">
                                    <?php echo htmlspecialchars($servizio['nome']); ?> - €<?php echo number_format($servizio['prezzo'], 2); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="remove_service" class="submit-btn warning" 
                            onclick="return confirm('Sei sicuro di voler eliminare questo servizio? Questa azione non può essere annullata.')">
                        <i class="fas fa-minus"></i> Rimuovi Servizio
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <div class="form-card danger">
            <h3><i class="fas fa-trash-alt"></i>Gestione Prenotazioni</h3>
            <p style="color: #f87171; margin-bottom: 1rem; font-size: 0.9rem;">
                Attenzione: questa azione cancellerà tutte le prenotazioni e salverà i ricavi nello storico.
            </p>
            <form method="POST" style="margin-bottom: 1rem;">
                <button type="submit" name="clear_prenotazioni" class="submit-btn danger" 
                        onclick="return confirm('Vuoi davvero svuotare tutte le prenotazioni? I ricavi verranno salvati nello storico.')">
                    <i class="fas fa-trash"></i> Svuota Prenotazioni
                </button>
            </form>
            
            <p style="color: #f87171; margin-bottom: 1rem; font-size: 0.9rem;">
                Attenzione: questa azione eliminerà tutti i ricavi presenti nello storico.
            </p>
            <form method="POST">
                <button type="submit" name="clear_ricavi" class="submit-btn danger" 
                        onclick="return confirm('Vuoi davvero eliminare tutti i ricavi dallo storico? Questa azione non può essere annullata.')">
                    <i class="fas fa-trash"></i> Svuota Ricavi
                </button>
            </form>
        </div>
    </div>
</div>

<script>
// Real-time update system
let lastBookingId = 0;
let updateInterval;
let isUpdating = false;
let currentDateFilter = '';
let lastRenderedTableHTML = '';

// Initialize real-time updates
function initRealTimeUpdates() {
    getLastBookingId();
    updateInterval = setInterval(checkForUpdates, 2000);
    showUpdateIndicator();
}

// Get the last booking ID from the server
function getLastBookingId() {
    fetch('get_last_booking_id.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                lastBookingId = data.lastId;
            }
        })
        .catch(error => {
            console.error('Error getting last booking ID:', error);
        });
}

// Check for new bookings or cancellations
// Check for new bookings or cancellations
function checkForUpdates() {
    if (isUpdating) return;
    
    isUpdating = true;

    fetch(`check_new_bookings.php?lastId=${lastBookingId}`)
        .then(response => response.json())
        .then(data => {
            if (data.hasNewBookings) {
                updateBookingsTable();
                updateStats();
                updateDateFilterDropdown(); // Aggiungi questa linea
                lastBookingId = data.newLastId;
                showUpdateNotification('Nuova prenotazione ricevuta!');
            } else {
                checkStatusChanges();
            }
        })
        .catch(error => {
            console.error('Error checking for updates:', error);
        })
        .finally(() => {
            isUpdating = false;
        });
}

// Aggiungi questa nuova funzione per aggiornare il dropdown delle date
function updateDateFilterDropdown() {
    fetch('get_booking_dates.php')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.dates) {
                const dateSelect = document.getElementById('data_prenotazioni');
                const currentValue = dateSelect.value;
                
                // Salva l'opzione selezionata
                const selectedOption = dateSelect.options[dateSelect.selectedIndex];
                
                // Pulisci il dropdown mantenendo solo la prima opzione "Tutte le date"
                dateSelect.innerHTML = '<option value="">Tutte le date</option>';
                
                // Aggiungi le nuove date
                data.dates.forEach(date => {
                    const option = document.createElement('option');
                    option.value = date.raw;
                    option.textContent = date.formatted;
                    dateSelect.appendChild(option);
                });
                
                // Ripristina la selezione precedente se esiste ancora
                if (currentValue) {
                    const optionToSelect = Array.from(dateSelect.options).find(
                        opt => opt.value === currentValue
                    );
                    if (optionToSelect) {
                        optionToSelect.selected = true;
                    }
                }
                
                // Se c'era un filtro attivo, riapplica il filtro
                if (currentDateFilter) {
                    filtraPrenotazioni(currentDateFilter);
                }
            }
        })
        .catch(error => {
            console.error('Error updating date filter:', error);
        });
}

// Controlla se la tabella prenotazioni è cambiata (es. per modifiche di stato)
function checkStatusChanges() {
    fetch('get_bookings_table.php?exclude_cancelled=1')
        .then(response => response.text())
        .then(html => {
            if (html !== lastRenderedTableHTML) {
                document.getElementById('bookings-table-body').innerHTML = html;
                lastRenderedTableHTML = html;
                updateStats();
                showUpdateNotification('Stato prenotazione modificato!');
                if (currentDateFilter) {
                    filtraPrenotazioni(currentDateFilter);
                }
            }
        })
        .catch(error => {
            console.error('Error checking status changes:', error);
        });
}


// Aggiorna la tabella prenotazioni
// Aggiorna la tabella prenotazioni
function updateBookingsTable() {
    fetch('get_bookings_table.php?exclude_cancelled=1')
        .then(response => response.text())
        .then(html => {
            document.getElementById('bookings-table-body').innerHTML = html;
            lastRenderedTableHTML = html;
            if (currentDateFilter) {
                filtraPrenotazioni(currentDateFilter);
            }
        })
        .catch(error => {
            console.error('Error updating bookings table:', error);
        });
}


// Aggiorna le statistiche
function updateStats() {
    fetch('get_stats.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('stat-confermata').textContent = data.stats.Confermata || 0;
                document.getElementById('stat-attesa').textContent = data.stats['In attesa'] || 0;
                document.getElementById('stat-cancellata').textContent = data.stats.Cancellata || 0;
                document.getElementById('stat-ricavi').textContent = '€' + parseFloat(data.totalRevenue || 0).toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }
        })
        .catch(error => {
            console.error('Error updating stats:', error);
        });
}

// Mostra notifica di aggiornamento personalizzata
function showUpdateNotification(message) {
    const indicator = document.getElementById('updateIndicator');
    indicator.innerHTML = `<i class="fas fa-sync-alt"></i> ${message}`;
    indicator.classList.add('show');
    
    setTimeout(() => {
        indicator.innerHTML = '<i class="fas fa-sync-alt"></i> Aggiornamento in tempo reale attivo';
        indicator.classList.remove('show');
    }, 3000);
}

// Mostra indicatore di aggiornamento iniziale
function showUpdateIndicator() {
    const indicator = document.getElementById('updateIndicator');
    indicator.classList.add('show');
    
    setTimeout(() => {
        indicator.classList.remove('show');
    }, 5000);
}

// Inizializza quando la pagina è caricata
document.addEventListener('DOMContentLoaded', function () {
    initRealTimeUpdates();
});

// Interrompe il polling in uscita
window.addEventListener('beforeunload', function () {
    if (updateInterval) {
        clearInterval(updateInterval);
    }
});

// Filtra i ricavi per data
function filtraRicavi(data) {
    const rows = document.querySelectorAll('#tabella_ricavi tbody tr');
    rows.forEach(row => {
        if (data === '') {
            row.style.display = '';
            return;
        }
        const dataCell = row.querySelector('td:first-child');
        const dataFormattata = new Date(data).toLocaleDateString('it-IT');
        if (dataCell.textContent.includes(dataFormattata)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

// Filtra le prenotazioni per data
function filtraPrenotazioni(data) {
    currentDateFilter = data;
    const rows = document.querySelectorAll('.table-container table tbody tr');
    rows.forEach(row => {
        if (!data) {
            row.style.display = '';
            return;
        }
        const dataCell = row.querySelector('td:nth-child(4)');
        if (dataCell) {
            const dataRow = new Date(data).toLocaleDateString('it-IT');
            if (dataCell.textContent.includes(dataRow)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        }
    });
}

// Gestione sidebar
let sidebarCollapsed = true;
let mobileOpen = false;

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const main = document.getElementById('main');
    const toggleIcon = document.querySelector('.sidebar-toggle i');
    const isMobile = window.innerWidth <= 768;

    if (isMobile) {
        mobileOpen = !mobileOpen;
        sidebar.classList.toggle('mobile-open');
    } else {
        sidebarCollapsed = !sidebarCollapsed;
        sidebar.classList.toggle('expanded');
        main.classList.toggle('expanded');
        toggleIcon.className = sidebarCollapsed ? 'fas fa-chevron-right' : 'fas fa-chevron-left';
    }
}

// Chiude sidebar mobile al clic esterno
document.addEventListener('click', (e) => {
    const sidebar = document.getElementById('sidebar');
    const mobileBtn = document.querySelector('.mobile-menu-btn');

    if (window.innerWidth <= 768 && mobileOpen &&
        !sidebar.contains(e.target) &&
        !mobileBtn.contains(e.target)) {
        toggleSidebar();
    }
});

// Resize: sistema la sidebar
window.addEventListener('resize', () => {
    const sidebar = document.getElementById('sidebar');
    const main = document.getElementById('main');

    if (window.innerWidth > 768) {
        sidebar.classList.remove('mobile-open');
        mobileOpen = false;
    }
});

// Ottimizzazioni per dispositivi touch
if ('ontouchstart' in window) {
    document.body.classList.add('touch-device');
}

// Fix viewport per mobile
function setViewportHeight() {
    const vh = window.innerHeight * 0.01;
    document.documentElement.style.setProperty('--vh', `${vh}px`);
}

setViewportHeight();
window.addEventListener('resize', setViewportHeight);
window.addEventListener('orientationchange', () => {
    setTimeout(setViewportHeight, 100);
});
</script>
</body>
</html>
```