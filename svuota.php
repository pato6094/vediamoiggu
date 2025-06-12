<?php
session_start();
if (!isset($_SESSION['logged'])) {
    header("Location: login.php");
    exit();
}
include 'connessione.php';

// First delete from cancellation_tokens (child table)
$conn->query("DELETE FROM cancellation_tokens");

// Then delete from prenotazioni (parent table)
$conn->query("DELETE FROM prenotazioni");

$conn->close();
header("Location: admin.php");
?>