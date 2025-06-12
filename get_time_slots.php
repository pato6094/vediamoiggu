<?php
include 'connessione.php';

header('Content-Type: application/json');

// Get active time slots from database
$query = $conn->query("SELECT orario FROM fasce_orarie WHERE attivo = 1 ORDER BY orario");

$time_slots = [];
if ($query && $query->num_rows > 0) {
    while ($row = $query->fetch_assoc()) {
        $time_slots[] = $row;
    }
}

echo json_encode($time_slots);
$conn->close();
?>