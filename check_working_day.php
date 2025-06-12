<?php
include 'connessione.php';

header('Content-Type: application/json');

if (!isset($_GET['date'])) {
    echo json_encode(['isWorkingDay' => false]);
    exit();
}

$date = $_GET['date'];
$dayOfWeek = date('N', strtotime($date)); // 1 = Monday, 7 = Sunday
$dayNames = ['', 'lunedi', 'martedi', 'mercoledi', 'giovedi', 'venerdi', 'sabato', 'domenica'];
$selectedDay = $dayNames[$dayOfWeek];

// Check if the day is configured as working day
$working_day_query = $conn->prepare("SELECT attivo FROM giorni_lavorativi WHERE giorno_settimana = ?");
$working_day_query->bind_param("s", $selectedDay);
$working_day_query->execute();
$working_day_result = $working_day_query->get_result();

$isWorkingDay = false;
if ($working_day_result->num_rows > 0) {
    $working_day = $working_day_result->fetch_assoc();
    $isWorkingDay = (bool)$working_day['attivo'];
}

echo json_encode(['isWorkingDay' => $isWorkingDay]);

$working_day_query->close();
$conn->close();
?>