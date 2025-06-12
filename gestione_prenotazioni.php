<?php
session_start();
if (!isset($_SESSION['logged'])) {
    header("Location: login.php");
    exit();
}
include 'connessione.php';

// Handle form submissions for working days
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_working_days'])) {
        $giorni = ['lunedi', 'martedi', 'mercoledi', 'giovedi', 'venerdi', 'sabato', 'domenica'];
        
        foreach ($giorni as $giorno) {
            $attivo = isset($_POST[$giorno]) ? 1 : 0;
            
            $stmt = $conn->prepare("UPDATE giorni_lavorativi SET attivo = ? WHERE giorno_settimana = ?");
            $stmt->bind_param("is", $attivo, $giorno);
            $stmt->execute();
            $stmt->close();
        }
        $success_message = "Giorni lavorativi aggiornati con successo.";
    }
    
    if (isset($_POST['update_time_slots'])) {
        // First, deactivate all time slots
        $conn->query("UPDATE fasce_orarie SET attivo = 0");
        
        // Then activate selected ones
        if (isset($_POST['time_slots'])) {
            foreach ($_POST['time_slots'] as $time_slot) {
                $stmt = $conn->prepare("UPDATE fasce_orarie SET attivo = 1 WHERE orario = ?");
                $stmt->bind_param("s", $time_slot);
                $stmt->execute();
                $stmt->close();
            }
        }
        $success_message = "Fasce orarie aggiornate con successo.";
    }
    
    if (isset($_POST['add_new_time_slot'])) {
        $new_time = $_POST['new_time_slot'];
        $descrizione = $_POST['time_slot_description'] ?? '';
        
        // Check if time slot already exists
        $check_stmt = $conn->prepare("SELECT id FROM fasce_orarie WHERE orario = ?");
        $check_stmt->bind_param("s", $new_time);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            // Time slot exists, just activate it
            $update_stmt = $conn->prepare("UPDATE fasce_orarie SET attivo = 1, descrizione = ? WHERE orario = ?");
            $update_stmt->bind_param("ss", $descrizione, $new_time);
            if ($update_stmt->execute()) {
                $success_message = "Fascia oraria riattivata con successo.";
            } else {
                $error_message = "Errore nell'attivazione della fascia oraria.";
            }
            $update_stmt->close();
        } else {
            // Time slot doesn't exist, create new one
            $insert_stmt = $conn->prepare("INSERT INTO fasce_orarie (orario, attivo, descrizione) VALUES (?, 1, ?)");
            $insert_stmt->bind_param("ss", $new_time, $descrizione);
            if ($insert_stmt->execute()) {
                $success_message = "Nuova fascia oraria aggiunta con successo.";
            } else {
                $error_message = "Errore nell'aggiunta della nuova fascia oraria.";
            }
            $insert_stmt->close();
        }
        $check_stmt->close();
    }
    
    if (isset($_POST['delete_time_slot'])) {
        $time_slot_id = intval($_POST['time_slot_id']);
        
        // Check if there are any bookings for this time slot
        $check_bookings = $conn->prepare("SELECT COUNT(*) as count FROM prenotazioni WHERE orario = (SELECT orario FROM fasce_orarie WHERE id = ?)");
        $check_bookings->bind_param("i", $time_slot_id);
        $check_bookings->execute();
        $booking_result = $check_bookings->get_result();
        $booking_count = $booking_result->fetch_assoc()['count'];
        
        if ($booking_count > 0) {
            // Don't delete, just deactivate
            $deactivate_stmt = $conn->prepare("UPDATE fasce_orarie SET attivo = 0 WHERE id = ?");
            $deactivate_stmt->bind_param("i", $time_slot_id);
            if ($deactivate_stmt->execute()) {
                $success_message = "Fascia oraria disattivata (ha prenotazioni associate).";
            } else {
                $error_message = "Errore nella disattivazione della fascia oraria.";
            }
            $deactivate_stmt->close();
        } else {
            // Safe to delete
            $delete_stmt = $conn->prepare("DELETE FROM fasce_orarie WHERE id = ?");
            $delete_stmt->bind_param("i", $time_slot_id);
            if ($delete_stmt->execute()) {
                $success_message = "Fascia oraria eliminata con successo.";
            } else {
                $error_message = "Errore nell'eliminazione della fascia oraria.";
            }
            $delete_stmt->close();
        }
        $check_bookings->close();
    }
    
    if (isset($_POST['add_time_limit'])) {
        $giorno = $_POST['limit_day'];
        $orario = $_POST['limit_time'];
        $limite = intval($_POST['limit_people']);
        
        $stmt = $conn->prepare("INSERT INTO limiti_orari (giorno_settimana, orario, limite_persone) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE limite_persone = ?, attivo = 1");
        $stmt->bind_param("ssii", $giorno, $orario, $limite, $limite);
        if ($stmt->execute()) {
            $success_message = "Limite orario aggiunto con successo.";
        } else {
            $error_message = "Errore nell'aggiunta del limite orario.";
        }
        $stmt->close();
    }
    
    if (isset($_POST['add_specific_limit'])) {
        $data = $_POST['specific_date'];
        $orario = $_POST['specific_time'];
        $limite = intval($_POST['specific_people']);
        
        $stmt = $conn->prepare("INSERT INTO limiti_date_specifiche (data_specifica, orario, limite_persone) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE limite_persone = ?, attivo = 1");
        $stmt->bind_param("ssii", $data, $orario, $limite, $limite);
        if ($stmt->execute()) {
            $success_message = "Limite per data specifica aggiunto con successo.";
        } else {
            $error_message = "Errore nell'aggiunta del limite per data specifica.";
        }
        $stmt->close();
    }
    
    if (isset($_POST['remove_limit'])) {
        $limit_id = intval($_POST['limit_id']);
        $limit_type = $_POST['limit_type'];
        
        if ($limit_type === 'general') {
            $stmt = $conn->prepare("UPDATE limiti_orari SET attivo = 0 WHERE id = ?");
        } else {
            $stmt = $conn->prepare("UPDATE limiti_date_specifiche SET attivo = 0 WHERE id = ?");
        }
        $stmt->bind_param("i", $limit_id);
        if ($stmt->execute()) {
            $success_message = "Limite rimosso con successo.";
        } else {
            $error_message = "Errore nella rimozione del limite.";
        }
        $stmt->close();
    }
}

// Get current working days configuration
$working_days_query = $conn->query("SELECT * FROM giorni_lavorativi ORDER BY FIELD(giorno_settimana, 'lunedi', 'martedi', 'mercoledi', 'giovedi', 'venerdi', 'sabato', 'domenica')");

// Get current time slots
$time_slots_query = $conn->query("SELECT * FROM fasce_orarie ORDER BY orario");

// Get current limits
$general_limits_query = $conn->query("SELECT * FROM limiti_orari WHERE attivo = 1 ORDER BY giorno_settimana, orario");
$specific_limits_query = $conn->query("SELECT * FROM limiti_date_specifiche WHERE attivo = 1 ORDER BY data_specifica, orario");
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Gestione Prenotazioni - Old School Barber</title>
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
            right: -15px;
            width: 30px;
            height: 30px;
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

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.8rem 1.5rem;
            background: rgba(212, 175, 55, 0.1);
            color: #d4af37;
            text-decoration: none;
            border-radius: 12px;
            border: 1px solid rgba(212, 175, 55, 0.2);
            transition: all 0.3s ease;
        }

        .back-btn:hover {
            background: rgba(212, 175, 55, 0.2);
            transform: translateY(-2px);
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr;
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

        /* Form Styles */
        .form-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .form-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            border-radius: 16px;
            padding: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .form-card h3 {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #ffffff;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #e0e0e0;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 0.8rem 1rem;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 8px;
            color: #ffffff;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: #d4af37;
            background: rgba(255, 255, 255, 0.12);
        }

        .form-group input::placeholder, .form-group textarea::placeholder {
            color: #a0a0a0;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 60px;
        }

        /* Fixed select option styling */
        .form-group select {
            appearance: none;
            background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20width%3D%2214%22%20height%3D%2210%22%20viewBox%3D%220%200%2014%2010%22%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%3E%3Cpath%20d%3D%22M1%200l6%206%206-6%22%20stroke%3D%22%23d4af37%22%20stroke-width%3D%222%22%20fill%3D%22none%22%20fill-rule%3D%22evenodd%22/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 14px 10px;
            cursor: pointer;
        }

        .form-group select option {
            background: #1a1a2e;
            color: #ffffff;
            padding: 0.5rem;
            border: none;
        }

        /* Dark mode specific select styling */
        @media (prefers-color-scheme: dark) {
            .form-group select option {
                background: #0f0f23;
                color: #ffffff;
            }
        }

        /* Browser-specific select option fixes */
        .form-group select option:checked {
            background: #d4af37;
            color: #1a1a2e;
        }

        .form-group select option:hover {
            background: rgba(212, 175, 55, 0.2);
            color: #ffffff;
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
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: white;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        }

        .submit-btn.secondary {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
        }

        /* Table Styles */
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

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 400px;
        }

        th, td {
            padding: 1rem 0.8rem;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            white-space: nowrap;
        }

        th {
            background: rgba(255, 255, 255, 0.05);
            font-weight: 600;
            color: #d4af37;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            color: #e0e0e0;
            font-weight: 400;
            font-size: 0.9rem;
        }

        tr:hover {
            background: rgba(255, 255, 255, 0.02);
        }

        /* Working Days Table - Simplified Styling */
        .working-days-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 300px;
        }

        .working-days-table th,
        .working-days-table td {
            padding: 1rem 0.8rem;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            vertical-align: middle;
        }

        .working-days-table th {
            background: rgba(255, 255, 255, 0.05);
            font-weight: 600;
            color: #d4af37;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .working-days-table td {
            color: #e0e0e0;
            font-weight: 400;
            font-size: 0.9rem;
        }

        .working-days-table .day-name {
            font-weight: 600;
            color: #ffffff;
            text-transform: capitalize;
            min-width: 100px;
            text-align: left;
        }

        .working-days-table input[type="checkbox"] {
            width: auto;
            margin: 0;
            transform: scale(1.2);
        }

        /* Time Slots Grid */
        .time-slots-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 0.8rem;
            margin: 1rem 0;
        }

        .time-slot-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
            position: relative;
        }

        .time-slot-item:hover {
            background: rgba(255, 255, 255, 0.08);
        }

        .time-slot-item input[type="checkbox"] {
            width: auto;
            margin: 0;
        }

        .time-slot-item label {
            margin: 0;
            font-size: 0.85rem;
            color: #e0e0e0;
            cursor: pointer;
            flex: 1;
        }

        .time-slot-item .delete-btn {
            position: absolute;
            top: -5px;
            right: -5px;
            width: 20px;
            height: 20px;
            background: rgba(239, 68, 68, 0.8);
            border: none;
            border-radius: 50%;
            color: white;
            font-size: 0.7rem;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .time-slot-item:hover .delete-btn {
            display: flex;
        }

        .time-slot-item .delete-btn:hover {
            background: rgba(239, 68, 68, 1);
            transform: scale(1.1);
        }

        .time-slot-item.inactive {
            opacity: 0.5;
            background: rgba(255, 255, 255, 0.02);
        }

        .time-slot-item.inactive label {
            color: #666;
            text-decoration: line-through;
        }

        /* Add Time Slot Form */
        .add-time-slot-form {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 12px;
            padding: 1.5rem;
            margin-top: 1.5rem;
        }

        .add-time-slot-form h4 {
            color: #4ade80;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .inline-form {
            display: grid;
            grid-template-columns: 1fr 2fr auto;
            gap: 1rem;
            align-items: end;
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

        .action-btn.delete {
            background: rgba(239, 68, 68, 0.2);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        /* Messages */
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

        /* Responsive Design */
        @media (max-width: 1024px) {
            .form-section {
                grid-template-columns: 1fr;
            }
            
            .time-slots-grid {
                grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
                gap: 0.6rem;
            }
            
            .inline-form {
                grid-template-columns: 1fr;
                gap: 0.8rem;
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

            .content-card {
                padding: 1.5rem;
            }

            .form-card {
                padding: 1.5rem;
            }

            .table-container {
                margin: 0 -1rem;
                border-radius: 0;
            }
            
            .working-days-table th,
            .working-days-table td {
                padding: 0.8rem 0.5rem;
                font-size: 0.8rem;
            }
            
            .time-slots-grid {
                grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
                gap: 0.5rem;
            }
            
            .time-slot-item {
                padding: 0.4rem;
            }
            
            .time-slot-item label {
                font-size: 0.8rem;
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
            
            .working-days-table th,
            .working-days-table td {
                padding: 0.6rem 0.3rem;
                font-size: 0.75rem;
            }
            
            .time-slots-grid {
                grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
                gap: 0.4rem;
            }
            
            .time-slot-item {
                padding: 0.3rem;
                flex-direction: column;
                text-align: center;
                gap: 0.2rem;
            }
            
            .time-slot-item label {
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
            
            .working-days-table th,
            .working-days-table td {
                padding: 0.5rem 0.2rem;
                font-size: 0.7rem;
            }
        }

        /* Touch device optimizations */
        @media (hover: none) and (pointer: coarse) {
            .action-btn, .submit-btn {
                min-height: 44px;
                min-width: 44px;
            }
            
            .sidebar-toggle {
                width: 40px;
                height: 40px;
            }
            
            .mobile-menu-btn {
                padding: 1rem;
                min-height: 44px;
                min-width: 44px;
            }
            
            .working-days-table input[type="checkbox"] {
                min-width: 20px;
                min-height: 20px;
            }
            
            .time-slot-item .delete-btn {
                display: flex;
                width: 24px;
                height: 24px;
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
    </style>
</head>
<body>

<button class="mobile-menu-btn" onclick="toggleSidebar()">
    <i class="fas fa-bars"></i>
</button>

<div class="sidebar" id="sidebar">
    <button class="sidebar-toggle" onclick="toggleSidebar()">
        <i class="fas fa-chevron-right"></i>
    </button>
    
    <div class="sidebar-header">
        <i class="fas fa-cut sidebar-logo"></i>
        <span class="sidebar-title">Admin Panel</span>
    </div>
    
    <ul class="sidebar-nav">
        <li><a href="admin.php"><i class="fas fa-home"></i><span>Dashboard</span></a></li>
        <li><a href="#" class="active"><i class="fas fa-calendar-alt"></i><span>Prenotazioni</span></a></li>
        <li><a href="gestione_operatori.php"><i class="fas fa-scissors"></i><span>Operatori</span></a></li>
        <li><a href="impostazioni.php"><i class="fas fa-cog"></i><span>Impostazioni</span></a></li>
        <li><a href="index.php"><i class="fas fa-arrow-left"></i><span>Torna al sito</span></a></li>
    </ul>
</div>

<div class="main" id="main">
    <div class="header">
        <h1 class="header-title">Gestione Prenotazioni</h1>
        <a href="admin.php" class="back-btn">
            <i class="fas fa-arrow-left"></i>
            Torna al Dashboard
        </a>
    </div>

    <?php if (isset($success_message)): ?>
        <div class="success-message"><?php echo $success_message; ?></div>
    <?php endif; ?>
    
    <?php if (isset($error_message)): ?>
        <div class="error-message"><?php echo $error_message; ?></div>
    <?php endif; ?>

    <div class="content-grid">
        <div class="content-card">
            <h3><i class="fas fa-calendar-week"></i>Configura Giorni Lavorativi</h3>
            <form method="POST">
                <div class="table-container">
                    <table class="working-days-table">
                        <thead>
                            <tr>
                                <th>Giorno</th>
                                <th>Attivo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $giorni_ita = [
                                'lunedi' => 'Lunedì',
                                'martedi' => 'Martedì', 
                                'mercoledi' => 'Mercoledì',
                                'giovedi' => 'Giovedì',
                                'venerdi' => 'Venerdì',
                                'sabato' => 'Sabato',
                                'domenica' => 'Domenica'
                            ];
                            
                            if ($working_days_query && $working_days_query->num_rows > 0):
                                while ($day = $working_days_query->fetch_assoc()): 
                            ?>
                            <tr>
                                <td class="day-name"><?php echo $giorni_ita[$day['giorno_settimana']]; ?></td>
                                <td>
                                    <input type="checkbox" name="<?php echo $day['giorno_settimana']; ?>" 
                                           <?php echo $day['attivo'] ? 'checked' : ''; ?>>
                                </td>
                            </tr>
                            <?php 
                                endwhile;
                            endif; 
                            ?>
                        </tbody>
                    </table>
                </div>
                <button type="submit" name="update_working_days" class="submit-btn">
                    <i class="fas fa-save"></i> Salva Giorni Lavorativi
                </button>
            </form>
        </div>
    </div>

    <div class="content-grid">
        <div class="content-card">
            <h3><i class="fas fa-clock"></i>Configura Fasce Orarie</h3>
            <form method="POST">
                <div class="time-slots-grid">
                    <?php 
                    if ($time_slots_query && $time_slots_query->num_rows > 0):
                        while ($slot = $time_slots_query->fetch_assoc()): 
                    ?>
                    <div class="time-slot-item <?php echo !$slot['attivo'] ? 'inactive' : ''; ?>">
                        <input type="checkbox" name="time_slots[]" value="<?php echo $slot['orario']; ?>" 
                               id="slot_<?php echo str_replace(':', '', $slot['orario']); ?>"
                               <?php echo $slot['attivo'] ? 'checked' : ''; ?>>
                        <label for="slot_<?php echo str_replace(':', '', $slot['orario']); ?>">
                            <?php echo substr($slot['orario'], 0, 5); ?>
                            <?php if ($slot['descrizione']): ?>
                                <br><small><?php echo htmlspecialchars($slot['descrizione']); ?></small>
                            <?php endif; ?>
                        </label>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="time_slot_id" value="<?php echo $slot['id']; ?>">
                            <button type="submit" name="delete_time_slot" class="delete-btn" 
                                    onclick="return confirm('Eliminare questa fascia oraria? Se ci sono prenotazioni associate verrà solo disattivata.')">
                                <i class="fas fa-times"></i>
                            </button>
                        </form>
                    </div>
                    <?php 
                        endwhile;
                    endif; 
                    ?>
                </div>
                <button type="submit" name="update_time_slots" class="submit-btn">
                    <i class="fas fa-save"></i> Salva Fasce Orarie Attive
                </button>
            </form>

            <!-- Add New Time Slot Form -->
            <div class="add-time-slot-form">
                <h4><i class="fas fa-plus-circle"></i> Aggiungi Nuova Fascia Oraria</h4>
                <form method="POST">
                    <div class="inline-form">
                        <div class="form-group">
                            <label for="new_time_slot">Orario</label>
                            <input type="time" name="new_time_slot" id="new_time_slot" required>
                        </div>
                        <div class="form-group">
                            <label for="time_slot_description">Descrizione (opzionale)</label>
                            <input type="text" name="time_slot_description" id="time_slot_description" 
                                   placeholder="Es. Pausa pranzo, Orario serale...">
                        </div>
                        <button type="submit" name="add_new_time_slot" class="submit-btn secondary">
                            <i class="fas fa-plus"></i> Aggiungi
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-card">
            <h3><i class="fas fa-users"></i>Aggiungi Limite Orario Generale</h3>
            <form method="POST">
                <div class="form-group">
                    <label for="limit_day">Giorno della settimana</label>
                    <select name="limit_day" required>
                        <option value="">Seleziona giorno</option>
                        <option value="lunedi">Lunedì</option>
                        <option value="martedi">Martedì</option>
                        <option value="mercoledi">Mercoledì</option>
                        <option value="giovedi">Giovedì</option>
                        <option value="venerdi">Venerdì</option>
                        <option value="sabato">Sabato</option>
                        <option value="domenica">Domenica</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="limit_time">Orario</label>
                    <input type="time" name="limit_time" required>
                </div>
                <div class="form-group">
                    <label for="limit_people">Numero massimo persone</label>
                    <input type="number" name="limit_people" min="1" max="10" required>
                </div>
                <button type="submit" name="add_time_limit" class="submit-btn">
                    <i class="fas fa-plus"></i> Aggiungi Limite
                </button>
            </form>
        </div>

        <div class="form-card">
            <h3><i class="fas fa-calendar-day"></i>Aggiungi Limite Data Specifica</h3>
            <form method="POST">
                <div class="form-group">
                    <label for="specific_date">Data specifica</label>
                    <input type="date" name="specific_date" required>
                </div>
                <div class="form-group">
                    <label for="specific_time">Orario</label>
                    <input type="time" name="specific_time" required>
                </div>
                <div class="form-group">
                    <label for="specific_people">Numero massimo persone</label>
                    <input type="number" name="specific_people" min="1" max="10" required>
                </div>
                <button type="submit" name="add_specific_limit" class="submit-btn">
                    <i class="fas fa-plus"></i> Aggiungi Limite Specifico
                </button>
            </form>
        </div>
    </div>

    <div class="content-grid">
        <div class="content-card">
            <h3><i class="fas fa-list"></i>Limiti Orari Generali Attivi</h3>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Giorno</th>
                            <th>Orario</th>
                            <th>Limite Persone</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($general_limits_query && $general_limits_query->num_rows > 0): ?>
                            <?php while ($limit = $general_limits_query->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo ucfirst($limit['giorno_settimana']); ?></td>
                                <td><?php echo substr($limit['orario'], 0, 5); ?></td>
                                <td><?php echo $limit['limite_persone']; ?></td>
                                <td>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="limit_id" value="<?php echo $limit['id']; ?>">
                                        <input type="hidden" name="limit_type" value="general">
                                        <button type="submit" name="remove_limit" class="action-btn delete" 
                                                onclick="return confirm('Rimuovere questo limite?')">
                                            <i class="fas fa-trash"></i>Rimuovi
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" style="text-align: center; color: #a0a0a0;">
                                    Nessun limite orario generale configurato
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="content-grid">
        <div class="content-card">
            <h3><i class="fas fa-calendar-check"></i>Limiti Date Specifiche Attivi</h3>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Orario</th>
                            <th>Limite Persone</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($specific_limits_query && $specific_limits_query->num_rows > 0): ?>
                            <?php while ($limit = $specific_limits_query->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo date('d/m/Y', strtotime($limit['data_specifica'])); ?></td>
                                <td><?php echo substr($limit['orario'], 0, 5); ?></td>
                                <td><?php echo $limit['limite_persone']; ?></td>
                                <td>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="limit_id" value="<?php echo $limit['id']; ?>">
                                        <input type="hidden" name="limit_type" value="specific">
                                        <button type="submit" name="remove_limit" class="action-btn delete" 
                                                onclick="return confirm('Rimuovere questo limite?')">
                                            <i class="fas fa-trash"></i>Rimuovi
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" style="text-align: center; color: #a0a0a0;">
                                    Nessun limite per date specifiche configurato
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
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

// Close mobile sidebar when clicking outside
document.addEventListener('click', (e) => {
    const sidebar = document.getElementById('sidebar');
    const mobileBtn = document.querySelector('.mobile-menu-btn');
    
    if (window.innerWidth <= 768 && mobileOpen && 
        !sidebar.contains(e.target) && 
        !mobileBtn.contains(e.target)) {
        toggleSidebar();
    }
});

// Handle window resize
window.addEventListener('resize', () => {
    const sidebar = document.getElementById('sidebar');
    
    if (window.innerWidth > 768) {
        sidebar.classList.remove('mobile-open');
        mobileOpen = false;
    }
});

// Touch device optimizations
if ('ontouchstart' in window) {
    document.body.classList.add('touch-device');
}

// Viewport height fix for mobile browsers
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