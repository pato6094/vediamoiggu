<?php
include 'connessione.php';

$excludeCancelled = isset($_GET['exclude_cancelled']) && $_GET['exclude_cancelled'] == '1';

$query = "SELECT p.*, CONCAT(o.nome, ' ', o.cognome) as operatore_nome 
          FROM prenotazioni p 
          LEFT JOIN operatori o ON p.operatore_id = o.id";
          
if ($excludeCancelled) {
    $query .= " WHERE p.stato != 'Cancellata'";
}

$query .= " ORDER BY p.data_prenotazione DESC, p.id DESC";

$prenotazioni = $conn->query($query);

if ($prenotazioni && $prenotazioni->num_rows > 0) {
    $i = 1;
    while ($row = $prenotazioni->fetch_assoc()) {
        echo '<tr>';
        echo '<td>'.$i++.'</td>';
        echo '<td>'.htmlspecialchars($row['nome'] ?? 'N/A').'</td>';
        echo '<td>'.htmlspecialchars($row['telefono'] ?? 'N/A').'</td>';
        
        if (isset($row['data_prenotazione']) && $row['data_prenotazione']) {
            $data = date_create($row['data_prenotazione']);
            echo '<td>'.($data ? date_format($data, 'd/m/Y') : 'N/A').'</td>';
        } else {
            echo '<td>N/A</td>';
        }
        
        echo '<td>'.(isset($row['orario']) ? date('H:i', strtotime($row['orario'])) : 'N/A').'</td>';
        echo '<td>'.htmlspecialchars($row['servizio'] ?? 'N/A').'</td>';
        echo '<td>'.htmlspecialchars($row['operatore_nome'] ?? 'Non assegnato').'</td>';
        
        if (isset($row['stato'])) {
            $stato = $row['stato'] ?? 'In attesa';
            $statusClass = '';
            if ($stato === 'Confermata') $statusClass = 'confermata';
            elseif ($stato === 'In attesa') $statusClass = 'in-attesa';
            elseif ($stato === 'Cancellata') $statusClass = 'cancellata';
            
            echo '<td><span class="status '.$statusClass.'">'.htmlspecialchars($stato).'</span></td>';
            
            echo '<td>';
            if ($stato !== 'Cancellata') {
                echo '<a href="?action=confirm&id='.$row['id'].'" class="action-btn confirm" onclick="return confirm(\'Confermare questa prenotazione?\')">';
                echo '<i class="fas fa-check"></i>Conferma</a>';
                
                echo '<a href="?action=cancel&id='.$row['id'].'" class="action-btn cancel" onclick="return confirm(\'Cancellare questa prenotazione?\')">';
                echo '<i class="fas fa-times"></i>Cancella</a>';
            } else {
                echo 'Nessuna azione';
            }
            echo '</td>';
        }
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="9" style="text-align: center; color: #a0a0a0;">Nessuna prenotazione trovata</td></tr>';
}
?>