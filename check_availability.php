<?php
include 'connessione.php';

if (!isset($_GET['date']) || !isset($_GET['time'])) {
    echo json_encode(['available' => false, 'message' => 'Parametri mancanti']);
    exit();
}

$date = $_GET['date'];
$time = $_GET['time'];

// Get day of week in Italian
$dayOfWeek = date('N', strtotime($date)); // 1 = Monday, 7 = Sunday
$dayNames = ['', 'lunedi', 'martedi', 'mercoledi', 'giovedi', 'venerdi', 'sabato', 'domenica'];

// Check if the day is configured as working day
$selectedDay = $dayNames[$dayOfWeek];
$working_day_query = $conn->prepare("SELECT attivo FROM giorni_lavorativi WHERE giorno_settimana = ?");
$working_day_query->bind_param("s", $selectedDay);
$working_day_query->execute();
$working_day_result = $working_day_query->get_result();

if ($working_day_result->num_rows > 0) {
    $working_day = $working_day_result->fetch_assoc();
    if (!$working_day['attivo']) {
        echo json_encode(['available' => false, 'message' => 'Giorno non lavorativo']);
        $working_day_query->close();
        exit();
    }
} else {
    // If no configuration found, block the day
    echo json_encode(['available' => false, 'message' => 'Giorno non configurato']);
    $working_day_query->close();
    exit();
}
$working_day_query->close();

// Check if the time slot is active - this is the ONLY time validation needed
$time_slot_query = $conn->prepare("SELECT attivo FROM fasce_orarie WHERE orario = ? AND attivo = 1");
$time_slot_query->bind_param("s", $time);
$time_slot_query->execute();
$time_slot_result = $time_slot_query->get_result();

if ($time_slot_result->num_rows == 0) {
    echo json_encode(['available' => false, 'message' => 'Fascia oraria non disponibile']);
    $time_slot_query->close();
    exit();
}
$time_slot_query->close();

// First check if there's a specific date limit (has priority)
$specific_limit_query = $conn->prepare("SELECT limite_persone FROM limiti_date_specifiche WHERE data_specifica = ? AND orario = ? AND attivo = 1");
$specific_limit_query->bind_param("ss", $date, $time);
$specific_limit_query->execute();
$specific_limit_result = $specific_limit_query->get_result();

$limit = null; // Default unlimited (null means no limit)
$limit_type = "unlimited";

if ($specific_limit_result->num_rows > 0) {
    // Use specific date limit
    $specific_limit_row = $specific_limit_result->fetch_assoc();
    $limit = $specific_limit_row['limite_persone'];
    $limit_type = "specific";
} else {
    // Check for general day limit
    $general_limit_query = $conn->prepare("SELECT limite_persone FROM limiti_orari WHERE giorno_settimana = ? AND orario = ? AND attivo = 1");
    $general_limit_query->bind_param("ss", $selectedDay, $time);
    $general_limit_query->execute();
    $general_limit_result = $general_limit_query->get_result();
    
    if ($general_limit_result->num_rows > 0) {
        $general_limit_row = $general_limit_result->fetch_assoc();
        $limit = $general_limit_row['limite_persone'];
        $limit_type = "general";
    }
    $general_limit_query->close();
}

// Count existing bookings for this date and time (exclude cancelled bookings)
$booking_query = $conn->prepare("SELECT COUNT(*) as count FROM prenotazioni WHERE data_prenotazione = ? AND orario = ? AND (stato IS NULL OR stato != 'Cancellata')");
$booking_query->bind_param("ss", $date, $time);
$booking_query->execute();
$booking_result = $booking_query->get_result();
$booking_row = $booking_result->fetch_assoc();
$current_bookings = $booking_row['count'];

// If no limit is set, always available
if ($limit === null) {
    echo json_encode([
        'available' => true,
        'limit' => 'unlimited',
        'current_bookings' => $current_bookings,
        'remaining_spots' => 'unlimited',
        'limit_type' => $limit_type,
        'message' => "Disponibile"
    ]);
} else {
    $available = $current_bookings < $limit;
    $remaining_spots = $limit - $current_bookings;

    echo json_encode([
        'available' => $available,
        'limit' => $limit,
        'current_bookings' => $current_bookings,
        'remaining_spots' => $remaining_spots,
        'limit_type' => $limit_type,
        'message' => $available ? ($remaining_spots > 1 ? "Disponibile" : "Disponibile (ultimo posto)") : "Slot completo ($current_bookings/$limit)"
    ]);
}

$specific_limit_query->close();
$booking_query->close();
$conn->close();
?>