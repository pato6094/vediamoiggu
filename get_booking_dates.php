<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['logged'])) {
    header("HTTP/1.1 403 Forbidden");
    exit();
}

include 'connessione.php';

$date_prenotazioni = array();
$query = "SELECT DISTINCT data_prenotazione FROM prenotazioni ORDER BY data_prenotazione DESC";
$result = $conn->query($query);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        if (isset($row['data_prenotazione'])) {
            $date_prenotazioni[] = [
                'raw' => $row['data_prenotazione'],
                'formatted' => date('d/m/Y', strtotime($row['data_prenotazione']))
            ];
        }
    }
}

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'dates' => $date_prenotazioni
]);
?>