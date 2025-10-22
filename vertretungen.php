<?php
session_start();

// Überprüfung, ob der Benutzer eingeloggt ist
if (!isset($_SESSION['username'])) {
    header('Location: https://smg-adlersberg.de/timedex/login.php');
    exit();
}

// Sicherheitsprüfung des User-Agents
if (isset($_SESSION['user_agent']) && $_SESSION['user_agent'] !== $_SERVER['HTTP_USER_AGENT']) {
    session_destroy();
    header('Location: https://smg-adlersberg.de/timedex/login.php');
    exit();
}

// Überprüfung, ob der Benutzername in der Zugangsliste vorhanden ist
$secureList = file_get_contents('https://smg-adlersberg.de/timedex/secure.php');
$allowedUsers = explode(',', $secureList);

// Benutzernamen normalisieren und prüfen (UTF-8-sicher für Umlaute)
$normalizedUsername = mb_strtoupper(trim($_SESSION['username']), 'UTF-8');
$isAllowed = false;

foreach ($allowedUsers as $user) {
    if (mb_strtoupper(trim($user), 'UTF-8') === $normalizedUsername) {
        $isAllowed = true;
        break;
    }
}

// Umleitung, wenn der Benutzer nicht berechtigt ist
if (!$isAllowed) {
    header('Location: https://smg-adlersberg.de/timedex/unauthorized.php');
    exit();
}

$username = htmlspecialchars($_SESSION['username']);
$teacherId = strtoupper($username);

// MCP Admin Panel - Lehrer-Override
$isMCP = (strtoupper($username) === 'MCP');
$displayTeacherId = $teacherId;

if ($isMCP && isset($_GET['teacher']) && !empty($_GET['teacher'])) {
    $displayTeacherId = strtoupper(trim($_GET['teacher']));
    $debugInfo[] = "MCP Override: Anzeige für Lehrer '$displayTeacherId' statt '$teacherId'";
}

// NEUE Funktion: Nächsten Werktag ermitteln (Mo-Fr)
function getNextWorkday($date) {
    $dayOfWeek = $date->format('N'); // 1=Montag, 7=Sonntag
    
    if ($dayOfWeek >= 6) { // Samstag (6) oder Sonntag (7)
        // Zum nächsten Montag springen
        $daysToAdd = 8 - $dayOfWeek; // Samstag: 8-6=2, Sonntag: 8-7=1
        $date->add(new DateInterval('P' . $daysToAdd . 'D'));
    }
    
    return $date;
}

// Aktuelles Datum verarbeiten (aus GET-Parameter oder heute)
if (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) {
    $currentDate = new DateTime($_GET['date']);
    
    // Prüfen ob gewähltes Datum ein Wochenende ist - wenn ja, zum nächsten Montag springen
    $currentDate = getNextWorkday($currentDate);
} else {
    $currentDate = new DateTime();
    
    // Automatischer Sprung zum nächsten Montag ab Samstag 0 Uhr
    $currentDate = getNextWorkday($currentDate);
}

// Wochentag ermitteln (1=Montag, 7=Sonntag)
$dayOfWeek = $currentDate->format('N');

// Debug-Ausgabe für Raumbuchungen (entfernen Sie diese später)
$debugInfo = [];

// NEUE Funktion: A/B-Woche laden
function loadCurrentWeekType() {
    global $debugInfo;
    
    $debugInfo[] = "=== A/B-WOCHE LADEN START ===";
    
    $context = stream_context_create([
        "http" => [
            "method" => "GET",
            "header" => "User-Agent: Mozilla/5.0\r\n",
            "timeout" => 10
        ]
    ]);
    
    $content = @file_get_contents('https://smg-adlersberg.de/timedex/stdplweek.php', false, $context);
    if ($content === false) {
        $debugInfo[] = "FEHLER: Konnte stdplweek.php nicht laden";
        return 'A'; // Fallback auf A-Woche
    }
    
    $weekType = trim($content);
    $debugInfo[] = "Aktuelle Woche (von stdplweek.php): '$weekType'";
    $debugInfo[] = "=== A/B-WOCHE LADEN ENDE ===";
    
    return $weekType;
}

// NEUE Funktion: A/B-Woche für bestimmtes Datum berechnen
function getWeekTypeForDate($targetDate, $currentWeekType) {
    global $debugInfo;
    
    $debugInfo[] = "=== A/B-WOCHE BERECHNUNG START ===";
    $debugInfo[] = "Zieldatum: " . $targetDate->format('Y-m-d');
    $debugInfo[] = "Aktuelle Woche: $currentWeekType";
    
    $today = new DateTime();
    
    // Aktuelle Woche ermitteln (Samstag bis Freitag)
    $currentWeekStart = clone $today;
    $dayOfWeek = intval($today->format('N')); // 1=Montag, 7=Sonntag
    
    if ($dayOfWeek == 7) { // Sonntag
        // Sonntag gehört zur aktuellen Woche (Sa-Fr), also einen Tag zurück zum Samstag
        $currentWeekStart->sub(new DateInterval('P1D'));
    } elseif ($dayOfWeek >= 1 && $dayOfWeek <= 5) { // Montag bis Freitag
        // Gehe zum letzten Samstag
        $daysBack = $dayOfWeek + 1; // Montag=2, Dienstag=3, ..., Freitag=6
        $currentWeekStart->sub(new DateInterval('P' . $daysBack . 'D'));
    } elseif ($dayOfWeek == 6) { // Samstag
        // Heute ist Samstag, das ist der Wochenstart
        // Nichts zu tun
    }
    
    // Auf Samstag 00:00 setzen
    $currentWeekStart->setTime(0, 0, 0);
    
    $debugInfo[] = "Aktuelle Woche startet: " . $currentWeekStart->format('Y-m-d H:i:s');
    
    // Zielwoche ermitteln (Samstag bis Freitag)
    $targetWeekStart = clone $targetDate;
    $targetDayOfWeek = intval($targetDate->format('N')); // 1=Montag, 7=Sonntag
    
    if ($targetDayOfWeek == 7) { // Sonntag
        // Sonntag gehört zur aktuellen Woche, also einen Tag zurück zum Samstag
        $targetWeekStart->sub(new DateInterval('P1D'));
    } elseif ($targetDayOfWeek >= 1 && $targetDayOfWeek <= 5) { // Montag bis Freitag
        // Gehe zum letzten Samstag
        $daysBack = $targetDayOfWeek + 1;
        $targetWeekStart->sub(new DateInterval('P' . $daysBack . 'D'));
    } elseif ($targetDayOfWeek == 6) { // Samstag
        // Das Zieldatum ist ein Samstag, das ist der Wochenstart
        // Nichts zu tun
    }
    
    // Auf Samstag 00:00 setzen
    $targetWeekStart->setTime(0, 0, 0);
    
    $debugInfo[] = "Zielwoche startet: " . $targetWeekStart->format('Y-m-d H:i:s');
    
    // Wochendifferenz berechnen
    $interval = $currentWeekStart->diff($targetWeekStart);
    $weekDiff = intval($interval->days / 7);
    
    // Vorzeichen bestimmen
    if ($targetWeekStart < $currentWeekStart) {
        $weekDiff = -$weekDiff;
    }
    
    $debugInfo[] = "Wochendifferenz: $weekDiff";
    
    // A/B-Woche berechnen
    // Gerade Differenz = gleiche Woche, ungerade = andere Woche
    if ($weekDiff % 2 == 0) {
        // Gleiche Woche wie aktuell
        $resultWeekType = $currentWeekType;
        $debugInfo[] = "Gleiche Woche (Differenz $weekDiff ist gerade): $resultWeekType";
    } else {
        // Andere Woche
        $resultWeekType = ($currentWeekType === 'A') ? 'B' : 'A';
        $debugInfo[] = "Andere Woche (Differenz $weekDiff ist ungerade): $resultWeekType";
    }
    
    $debugInfo[] = "=== A/B-WOCHE BERECHNUNG ENDE ===";
    
    return $resultWeekType;
}



// KORRIGIERTE Funktion: Raumbuchungen laden mit verbesserter Debug-Ausgabe
// KORRIGIERTE Funktion: Raumbuchungen laden mit datumsbasierter JSON
function loadRaumbuchungen($teacherId, $dayOfWeek, $date) {
    global $debugInfo;
    
    $url = "https://smg-adlersberg.de/timedex/bookings_regular.json";
    
    $debugInfo[] = "=== RAUMBUCHUNGEN LADEN START ===";
    $debugInfo[] = "URL: $url";
    $debugInfo[] = "Teacher ID: '$teacherId', Day of Week: $dayOfWeek";
    $debugInfo[] = "Datum: " . $date->format('Y-m-d') . " (Woche " . $date->format('W') . ")";
    
    // HTTPS-Context für file_get_contents
    $context = stream_context_create([
        "http" => [
            "method" => "GET",
            "header" => "User-Agent: Mozilla/5.0\r\n",
            "timeout" => 10
        ]
    ]);
    
    $content = @file_get_contents($url, false, $context);
    if ($content === false) {
        $debugInfo[] = "FEHLER: Konnte Datei $url nicht laden";
        return [];
    }
    
    $debugInfo[] = "Datei erfolgreich geladen. Länge: " . strlen($content) . " Bytes";
    $debugInfo[] = "Inhalt (erste 300 Zeichen): " . substr($content, 0, 300);
    
    $raumbuchungen = json_decode($content, true);
    if ($raumbuchungen === null) {
        $debugInfo[] = "FEHLER: JSON konnte nicht dekodiert werden. JSON-Fehler: " . json_last_error_msg();
        $debugInfo[] = "Roher Inhalt: " . $content;
        return [];
    }
    
    $debugInfo[] = "JSON erfolgreich dekodiert. Anzahl Räume: " . count($raumbuchungen);
    $debugInfo[] = "Verfügbare Räume: " . implode(', ', array_keys($raumbuchungen));
    
    // Datum in das Format der JSON-Datei konvertieren (TT.MM.JJ)
    $dateString = $date->format('d.m.y');
    $debugInfo[] = "Suche nach Datum: $dateString";
    
    $teacherBookings = [];
    
    // Durch alle Räume iterieren
    foreach ($raumbuchungen as $room => $dates) {
        $debugInfo[] = "Prüfe Raum: '$room'";
        $debugInfo[] = "  Verfügbare Daten in diesem Raum: " . implode(', ', array_keys($dates));
        
        // Prüfen ob das gewünschte Datum vorhanden ist
        if (isset($dates[$dateString])) {
            $dateBookings = $dates[$dateString];
            $debugInfo[] = "  Datum $dateString gefunden in Raum $room";
            $debugInfo[] = "  Buchungen für dieses Datum: " . json_encode($dateBookings);
            
            // Durch alle Stunden des Datums iterieren
            foreach ($dateBookings as $hour => $bookedTeacher) {
                $debugInfo[] = "    Prüfe Stunde $hour: gebucht von '$bookedTeacher'";
                $debugInfo[] = "    Vergleiche: strtoupper('$bookedTeacher') === strtoupper('$teacherId')";
                $debugInfo[] = "    Ergebnis: '" . strtoupper(trim($bookedTeacher)) . "' === '" . strtoupper(trim($teacherId)) . "'";
                
                // String-Vergleich
                if (strtoupper(trim($bookedTeacher)) === strtoupper(trim($teacherId))) {
                    $booking = [
                        'room' => $room,
                        'hour' => intval($hour),
                        'day' => $dayOfWeek
                    ];
                    $teacherBookings[] = $booking;
                    $debugInfo[] = "    ✓ MATCH! Raumbuchung gefunden: " . json_encode($booking);
                } else {
                    $debugInfo[] = "    ✗ Kein Match";
                }
            }
        } else {
            $debugInfo[] = "  Datum $dateString NICHT gefunden in Raum $room";
        }
    }
    
    $debugInfo[] = "=== RAUMBUCHUNGEN LADEN ENDE ===";
    $debugInfo[] = "Gesamt gefundene Raumbuchungen: " . count($teacherBookings);
    
    return $teacherBookings;
}

// KORRIGIERTE Funktion: Stundenplan laden mit komplett überarbeiteter Logik
function loadStundenplan($teacherId, $dayOfWeek, $hour) {
    global $debugInfo;
    
    $debugInfo[] = "=== STUNDENPLAN LADEN START ===";
    $debugInfo[] = "Suche nach: Teacher='$teacherId', Tag=$dayOfWeek, Stunde=$hour";
    
    $content = @file_get_contents('stammdaten/GPU001.TXT');
    if ($content === false) {
        $debugInfo[] = "FEHLER: Konnte Stundenplan nicht laden";
        return null;
    }
    
    $lines = explode("\n", $content);
    $debugInfo[] = "Stundenplan geladen, Anzahl Zeilen: " . count($lines);
    
    $relevantLines = [];
    $foundMatch = null;
    
    foreach ($lines as $lineNum => $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        // Format: ID,"Klasse","Lehrer","Fach","Raum",Tag,Stunde,,
        // Beispiel: 190,"9A","AWE","SK","209",4,1,,
        
        // CSV-Parsing mit Anführungszeichen-Unterstützung
        $parts = str_getcsv($line);
        
        if (count($parts) >= 7) {
            // Alle Felder trimmen und Anführungszeichen entfernen
            $id = isset($parts[0]) ? trim($parts[0], '"') : '';
            $klasse = isset($parts[1]) ? trim($parts[1], '"') : '';
            $lehrer = isset($parts[2]) ? trim($parts[2], '"') : '';
            $fach = isset($parts[3]) ? trim($parts[3], '"') : '';
            $raum = isset($parts[4]) ? trim($parts[4], '"') : '';
            $tag = isset($parts[5]) ? intval(trim($parts[5], '"')) : 0;
            $stunde = isset($parts[6]) ? intval(trim($parts[6], '"')) : 0;
            
            $debugInfo[] = "Zeile $lineNum: ID=$id, Klasse='$klasse', Lehrer='$lehrer', Fach='$fach', Raum='$raum', Tag=$tag, Stunde=$stunde";
            
            // Sammle alle relevanten Zeilen für Debug (alle Einträge des Lehrers)
            if (strtoupper($lehrer) === strtoupper($teacherId)) {
                $relevantLines[] = "Zeile $lineNum: Lehrer=$lehrer, Tag=$tag, Stunde=$stunde, Klasse=$klasse, Fach=$fach, Raum=$raum";
                
                // Prüfen ob es der richtige Lehrer, Tag und Stunde ist
                if ($tag == $dayOfWeek && $stunde == $hour) {
                    $foundMatch = [
                        'class' => $klasse,
                        'subject' => $fach,
                        'originalRoom' => $raum,
                        'id' => $id
                    ];
                    $debugInfo[] = "✓ STUNDENPLAN MATCH gefunden in Zeile $lineNum: " . json_encode($foundMatch);
                }
            }
        } else {
            $debugInfo[] = "Zeile $lineNum: Unvollständige Daten (" . count($parts) . " Teile): $line";
        }
    }
    
    $debugInfo[] = "Alle relevanten Stundenplan-Einträge für $teacherId:";
    if (empty($relevantLines)) {
        $debugInfo[] = "  (keine gefunden)";
    } else {
        foreach ($relevantLines as $line) {
            $debugInfo[] = "  $line";
        }
    }
    
    if ($foundMatch) {
        $debugInfo[] = "✓ Passender Stundenplan-Eintrag gefunden für Tag=$dayOfWeek, Stunde=$hour";
        $debugInfo[] = "=== STUNDENPLAN LADEN ENDE ===";
        return $foundMatch;
    } else {
        $debugInfo[] = "✗ Kein passender Stundenplan-Eintrag gefunden für Tag=$dayOfWeek, Stunde=$hour";
        $debugInfo[] = "=== STUNDENPLAN LADEN ENDE ===";
        return null;
    }
}

// KORRIGIERTE Funktion: Raumbuchungen verarbeiten mit verbesserter Debug-Ausgabe
function processRaumbuchungen($teacherId, $dayOfWeek, $date) {
    global $debugInfo;
    
    $debugInfo[] = "=== VERARBEITUNG RAUMBUCHUNGEN START ===";
    $debugInfo[] = "Parameter: teacherId='$teacherId', dayOfWeek=$dayOfWeek, date=" . $date->format('Y-m-d');
    
    $bookings = loadRaumbuchungen($teacherId, $dayOfWeek, $date);
    $processed = [];
    
    $debugInfo[] = "Gefundene Raumbuchungen: " . count($bookings);
    
    foreach ($bookings as $index => $booking) {
        $debugInfo[] = "Verarbeite Buchung #$index: " . json_encode($booking);
        
        $stundenplanInfo = loadStundenplan($teacherId, $dayOfWeek, $booking['hour']);
        
        if ($stundenplanInfo) {
            // Raumbuchung mit Stundenplan-Info
            $processedBooking = [
                'type' => 'room-booking',
                'hour' => $booking['hour'],
                'class' => $stundenplanInfo['class'],
                'subject' => $stundenplanInfo['subject'],
                'originalRoom' => $stundenplanInfo['originalRoom'],
                'bookedRoom' => $booking['room'],
                'room' => $booking['room'], // Für die Anzeige
                'hasClassInfo' => true
            ];
            
            $processed[] = $processedBooking;
            $debugInfo[] = "✓ Buchung mit Stundenplan-Info verarbeitet: " . json_encode($processedBooking);
        } else {
            // Raumbuchung ohne Stundenplan-Info (Lehrer unterrichtet keine Klasse)
            $processedBooking = [
                'type' => 'room-booking',
                'hour' => $booking['hour'],
                'bookedRoom' => $booking['room'],
                'room' => $booking['room'], // Für die Anzeige
                'hasClassInfo' => false
            ];
            
            $processed[] = $processedBooking;
            $debugInfo[] = "✓ Buchung ohne Stundenplan-Info verarbeitet (freie Stunde): " . json_encode($processedBooking);
        }
    }
    
    $debugInfo[] = "Erfolgreich verarbeitete Raumbuchungen: " . count($processed);
    $debugInfo[] = "=== VERARBEITUNG RAUMBUCHUNGEN ENDE ===";
    
    return $processed;
}

// NEUE Funktion: GPU014.TXT laden und parsen
function loadGPU014($teacherId, $date) {
    global $debugInfo;
    
    $debugInfo[] = "=== GPU014 LADEN START ===";
    $debugInfo[] = "Datum: " . $date->format('Y-m-d') . " (Woche " . $date->format('W') . ")";
    $debugInfo[] = "Teacher ID: '$teacherId'";
    
    // GPU014.TXT laden
    $url = 'https://smg-adlersberg.de/koordination/GPU014.TXT';
    $context = stream_context_create([
        "http" => [
            "method" => "GET",
            "header" => "User-Agent: Mozilla/5.0\r\n",
            "timeout" => 10
        ]
    ]);
    
    $content = @file_get_contents($url, false, $context);
    if ($content === false) {
        $debugInfo[] = "FEHLER: Konnte GPU014.TXT nicht laden";
        return [];
    }
    
    $debugInfo[] = "GPU014.TXT erfolgreich geladen. Länge: " . strlen($content) . " Bytes";
    
    $vertretungen = [];
    $lines = explode("\n", $content);
    $targetDateString = $date->format('Ymd'); // Format: 20250929
    
    $debugInfo[] = "Suche nach Datum: $targetDateString";
    $debugInfo[] = "Anzahl Zeilen in GPU014.TXT: " . count($lines);
    
    $relevantLines = 0;
    
    foreach ($lines as $lineNum => $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        // CSV-Parsing für GPU014-Format
        $parts = str_getcsv($line);
        
        if (count($parts) >= 20) {
            $datumInFile = trim($parts[1], '"'); // Position 1: Datum
            $stundeInFile = trim($parts[2], '"'); // Position 2: Stunde
            $alterLehrer = trim($parts[5], '"'); // Position 5: Alter Lehrer
            $neuerLehrer = trim($parts[6], '"'); // Position 6: Neuer Lehrer (bei Vertretung)
            
            // Prüfen ob das Datum übereinstimmt
            if ($datumInFile === $targetDateString) {
                // Prüfen ob der Lehrer betroffen ist (entweder als alter oder neuer Lehrer)
                $istBetroffen = (strtoupper($alterLehrer) === strtoupper($teacherId)) || 
                               (strtoupper($neuerLehrer) === strtoupper($teacherId));
                
                if ($istBetroffen) {
                    $vertretungen[] = $line;
                    $relevantLines++;
                    $debugInfo[] = "Relevante Zeile $lineNum gefunden: " . substr($line, 0, 100) . "...";
                }
            }
        }
    }
    
    $debugInfo[] = "Relevante GPU014-Zeilen für $teacherId am $targetDateString gefunden: $relevantLines";
    $debugInfo[] = "=== GPU014 LADEN ENDE ===";
    
    return $vertretungen;
}

// NEUE Funktion: GPU014-Zeile parsen mit A/B-Wochen-Logik
function parseGPU014Line($line, $teacherId, $weekType) {
    global $debugInfo;
    
    $parts = str_getcsv($line);
    if (count($parts) < 20) {
        $debugInfo[] = "GPU014-Zeile unvollständig: " . count($parts) . " Teile";
        return null;
    }
    
    // Daten extrahieren
    $datum = trim($parts[1], '"'); // Position 1
    $stunde = intval(trim($parts[2], '"')); // Position 2
    $alterLehrer = trim($parts[5], '"'); // Position 5
    $neuerLehrer = trim($parts[6], '"'); // Position 6
    $fach = trim($parts[7], '"'); // Position 7
    $neuesFach = trim($parts[9], '"'); // Position 9 (neues Fach bei Fachwechsel)
    $alterRaum = trim($parts[11], '"'); // Position 11
    $neuerRaum = trim($parts[12], '"'); // Position 12
    $klasse = trim($parts[14], '"'); // Position 14
    $ort = trim($parts[16], '"'); // Position 16 (für Pausenaufsichten)
    $typ = trim($parts[19], '"'); // Position 19
    
    $debugInfo[] = "GPU014 Parse: Lehrer=$alterLehrer->$neuerLehrer, Stunde=$stunde, Fach=$fach->$neuesFach, Raum=$alterRaum->$neuerRaum, Klasse=$klasse, Typ=$typ, Woche=$weekType";
    
    // A/B-Wochen-Logik anwenden
    if ($weekType === 'A') {
        // A-Woche: Ignoriere alle 9. Stunden, mache aus 8. Stunde -> 8-9
        if ($stunde === 9) {
            $debugInfo[] = "A-Woche: Ignoriere Stunde 9";
            return null;
        }
        if ($stunde === 8) {
            $stunde = '8-9';
            $debugInfo[] = "A-Woche: Stunde 8 wird zu 8-9";
        }
    } elseif ($weekType === 'B') {
        // B-Woche: Ignoriere alle 8. Stunden, mache aus 9. Stunde -> 8-9
        if ($stunde === 8) {
            $debugInfo[] = "B-Woche: Ignoriere Stunde 8";
            return null;
        }
        if ($stunde === 9) {
            $stunde = '8-9';
            $debugInfo[] = "B-Woche: Stunde 9 wird zu 8-9";
        }
    }
    
    // Klasse umwandeln (5A~5B~5D -> 5ABD)
    if (strpos($klasse, '~') !== false) {
        $klassenTeile = explode('~', $klasse);
        $ersteKlasse = $klassenTeile[0];
        $prefix = preg_replace('/[A-Z]$/', '', $ersteKlasse); // Entferne letzten Buchstaben
        $buchstaben = '';
        foreach ($klassenTeile as $teil) {
            $buchstaben .= substr($teil, -1); // Letzter Buchstabe
        }
        $klasse = $prefix . $buchstaben;
    }
    
    // Typ bestimmen
    if ($typ === 'B') {
        // Pausenaufsicht
        // Berechne Pausenzeit - aber nur wenn es eine reguläre Stunde ist
        if (is_numeric($stunde)) {
            $pausenStunde = ($stunde - 1) . '/' . $stunde;
        } else {
            $pausenStunde = $stunde; // Für 8-9 etc. beibehalten
        }
        
        $istVertretung = (strtoupper($neuerLehrer) === strtoupper($teacherId));
        
        return [
            'type' => 'break-supervision',
            'hour' => $pausenStunde,
            'location' => $ort,
            'originalTeacher' => $alterLehrer,
            'substituteTeacher' => $neuerLehrer,
            'isSubstituting' => $istVertretung
        ];
    } elseif (empty($neuerLehrer)) {
        // Entfall (kein neuer Lehrer)
        return [
            'type' => 'cancellation',
            'hour' => $stunde,
            'class' => $klasse,
            'subject' => $fach,
            'room' => $alterRaum,
            'originalTeacher' => $alterLehrer
        ];
    } elseif ($alterLehrer === $neuerLehrer && $fach === $neuesFach && $alterRaum !== $neuerRaum) {
        // Raumwechsel (gleicher Lehrer und Fach, aber anderer Raum)
        return [
            'type' => 'room-change',
            'hour' => $stunde,
            'class' => $klasse,
            'subject' => $fach,
            'originalRoom' => $alterRaum,
            'newRoom' => $neuerRaum,
            'room' => $neuerRaum,
            'originalTeacher' => $alterLehrer
        ];
    } else {
        // Vertretung oder Verlegung
        $istVerlegung = ($fach !== $neuesFach && !empty($neuesFach));
        $istBeingSubstituted = (strtoupper($alterLehrer) === strtoupper($teacherId));
        
        $result = [
            'type' => 'substitution',
            'hour' => $stunde,
            'class' => $klasse,
            'subject' => $istBeingSubstituted ? $fach : ($neuesFach ?: $fach),
            'room' => $neuerRaum ?: $alterRaum,
            'originalTeacher' => $alterLehrer,
            'substituteTeacher' => $neuerLehrer,
            'originalSubject' => $fach,
            'newSubject' => $neuesFach ?: $fach,
            'originalRoom' => $alterRaum,
            'newRoom' => $neuerRaum ?: $alterRaum,
            'isBeingSubstituted' => $istBeingSubstituted
        ];
        
        if ($istVerlegung) {
            $result['isRelocation'] = true;
        }
        
        return $result;
    }
}

// Funktion zum Laden der Klausurdaten
function loadKlausuren($teacherId, $datum) {
    $content = @file_get_contents('https://smg-adlersberg.de/koordination/klausuren.php');
    if ($content === false) {
        return [];
    }
    
    // Fachzuordnungen laden
    $fachzuordnungen = loadFachzuordnungen();
    
    $klausuren = [];
    $lines = explode("\n", $content);
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        $parts = explode(',', $line);
        if (count($parts) >= 6) {
            $lehrer = trim($parts[0]);
            $klausurDatum = trim($parts[1]);
            
            // Datum parsen (verschiedene Formate unterstützen)
            $parsedDate = null;
            if (preg_match('/(\d{1,2})\.\s*(\w+)/', $klausurDatum, $matches)) {
                $day = intval($matches[1]);
                $monthName = $matches[2];
                $months = [
                    'Januar' => 1, 'Februar' => 2, 'März' => 3, 'April' => 4,
                    'Mai' => 5, 'Juni' => 6, 'Juli' => 7, 'August' => 8,
                    'September' => 9, 'Oktober' => 10, 'November' => 11, 'Dezember' => 12
                ];
                if (isset($months[$monthName])) {
                    $month = $months[$monthName];
                    $year = date('Y');
                    $parsedDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
                }
            } elseif (preg_match('/(\d{1,2})\.\s*(\d{1,2})\.(\d{4})?/', $klausurDatum, $matches)) {
                // Format: "10.06" oder "10.06.2025"
                $day = intval($matches[1]);
                $month = intval($matches[2]);
                $year = isset($matches[3]) && !empty($matches[3]) ? intval($matches[3]) : date('Y');
                $parsedDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
            
            // Datum prüfen
            if ($parsedDate == $datum) {
                $aufsichten = isset($parts[6]) ? trim($parts[6]) : '';
                
                // Prüfen ob der Lehrer beteiligt ist (eigene Klausur oder Aufsicht)
                $istEigeneKlausur = ($lehrer == $teacherId);
                $istAufsichtsbeteiligt = (!empty($aufsichten) && strpos($aufsichten, $teacherId) !== false);
                $istBeteiligt = $istEigeneKlausur || $istAufsichtsbeteiligt;

                if ($istBeteiligt) {
                    // Klasse und Fach extrahieren
                    preg_match('/^(\d+)\s+(.+)$/', trim($parts[2]), $matches);
                    if ($matches) {
                        $klasse = $matches[1];
                        $fachLang = $matches[2];
                        
                        // Fach in Kurzform umwandeln
                        $fachKurz = isset($fachzuordnungen[$fachLang]) ? $fachzuordnungen[$fachLang] : $fachLang;
                        
                        $raum = trim($parts[4]);
                        $stundenBereich = trim($parts[5]);
                        
                        $klausur = [
                            'lehrer' => $lehrer,
                            'klasse' => $klasse,
                            'fach' => $fachKurz,
                            'raum' => $raum,
                            'stunden' => $stundenBereich,
                            'aufsichten' => $aufsichten,
                            'istEigeneKlausur' => $istEigeneKlausur
                        ];
                        
                        $klausuren[] = $klausur;
                    }
                }
            }
        }
    }
    
    return $klausuren;
}

// Funktion zum Laden der Fachzuordnungen
function loadFachzuordnungen() {
    $content = @file_get_contents('https://smg-adlersberg.de/timedex/fachzuordnungen.php');
    if ($content === false) {
        return [];
    }
    
    $zuordnungen = [];
    $lines = explode("\n", $content);
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        if (strpos($line, '=') !== false) {
            $parts = explode('=', $line, 2);
            if (count($parts) == 2) {
                $kurz = trim($parts[0]);
                $lang = trim($parts[1]);
                $zuordnungen[$lang] = $kurz;
            }
        }
    }
    
    return $zuordnungen;
}

// Funktion zum Formatieren der Klausurdaten
function formatKlausur($klausur, $teacherId) {
    $stundenBereich = $klausur['stunden'];
    
    // Stunden-Array für die Verarbeitung
    $originalStundenArray = explode('-', $klausur['stunden']);
    
    // Stunden-Format für Anzeige normalisieren (z.B. "3-4-5" zu "3-5")
    $displayStundenBereich = $stundenBereich;
    if (count($originalStundenArray) > 2) {
        $displayStundenBereich = $originalStundenArray[0] . '-' . end($originalStundenArray);
    }
    
    $supervisions = [];
    
    // Aufsichten parsen - KORRIGIERT
    if (!empty($klausur['aufsichten'])) {
        $aufsichtenProStunde = explode('-', $klausur['aufsichten']);
        
        // Sicherstellen, dass wir für jede Stunde eine Aufsicht haben
        for ($i = 0; $i < count($originalStundenArray); $i++) {
            $stundenNr = intval($originalStundenArray[$i]);
            
            if (isset($aufsichtenProStunde[$i]) && !empty(trim($aufsichtenProStunde[$i]))) {
                $aufsicht = trim($aufsichtenProStunde[$i]);
                $lehrerInStunde = array_map('trim', explode('+', $aufsicht));
            } else {
                // Fallback: leere Aufsicht oder letzte verfügbare verwenden
                $lastIndex = count($aufsichtenProStunde) - 1;
                if ($lastIndex >= 0 && !empty(trim($aufsichtenProStunde[$lastIndex]))) {
                    $aufsicht = trim($aufsichtenProStunde[$lastIndex]);
                    $lehrerInStunde = array_map('trim', explode('+', $aufsicht));
                } else {
                    $lehrerInStunde = [];
                }
            }
            
            if (!empty($lehrerInStunde)) {
                $supervisions[] = [
                    'hour' => $stundenNr,
                    'teachers' => $lehrerInStunde
                ];
            }
        }
    }
    
    return [
        'type' => $klausur['istEigeneKlausur'] ? 'exam' : 'exam-supervision',
        'class' => $klausur['klasse'],
        'hour' => $displayStundenBereich,
        'subject' => $klausur['fach'],
        'room' => $klausur['raum'],
        'supervisions' => $supervisions
    ];
}

// Funktion zum Abrufen der Stundenzeiten
function getStundenzeit($stunde) {
    $zeiten = [
        '1' => '7:40 - 8:25',
        '2' => '8:30 - 9:15',
        '3' => '9:30 - 10:15',
        '4' => '10:20 - 11:05',
        '5' => '11:20 - 12:05',
        '6' => '12:10 - 12:55',
        '7' => '13:00 - 13:45',
        '8' => '14:15 - 15:00',
        '9' => '15:00 - 15:45',
        '1-2' => '7:40 - 9:15',
        '2-3' => '8:30 - 10:15',
        '3-4' => '9:30 - 11:05',
        '4-5' => '10:20 - 12:05',
        '5-6' => '11:20 - 12:55',
        '6-7' => '12:10 - 13:45',
        '7-8' => '13:00 - 15:00',
        '8-9' => '14:15 - 15:45',
        '1-3' => '7:40 - 10:15',
        '2-4' => '8:30 - 11:05',
        '3-5' => '9:30 - 12:05',
        '4-6' => '10:20 - 12:55',
        '5-7' => '11:20 - 13:45',
        '6-8' => '12:10 - 15:00',
        '7-9' => '13:00 - 15:45',
        '1-4' => '7:40 - 11:05',
        '2-5' => '8:30 - 12:05',
        '3-6' => '9:30 - 12:55',
        '4-7' => '10:20 - 13:45',
        '5-8' => '11:20 - 15:00',
        '6-9' => '12:10 - 15:45',
        '1-5' => '7:40 - 12:05',
        '2-6' => '8:30 - 12:55',
        '3-7' => '9:30 - 13:45',
        '4-8' => '10:20 - 15:00',
        '5-9' => '11:20 - 15:45',
        '1-6' => '7:40 - 12:55',
        '2-7' => '8:30 - 13:45',
        '3-8' => '9:30 - 15:00',
        '4-9' => '10:20 - 15:45',
        '1-7' => '7:40 - 13:45',
        '2-8' => '8:30 - 15:00',
        '3-9' => '9:30 - 15:45',
        '1-8' => '7:40 - 15:00',
        '2-9' => '8:30 - 15:45',
        '1-9' => '7:40 - 15:45',
        '0/1' => '7:30 - 7:40',
        '2/3' => '9:15 - 9:30',
        '4/5' => '11:05 - 11:20'
    ];
    
    return isset($zeiten[$stunde]) ? $zeiten[$stunde] : $stunde;
}

// Neue Funktion: Prüft ob für eine bestimmte Stunde eine Raumbuchung existiert
function hasRoomBookingForHour($roomBookings, $hour) {
    foreach ($roomBookings as $booking) {
        if ($booking['hour'] == $hour) {
            return true;
        }
    }
    return false;
}

// Funktion zum Gruppieren aufeinanderfolgender Stunden
function groupConsecutiveEntries($vertretungen) {
    $grouped = [];
    $processed = [];
    
    for ($i = 0; $i < count($vertretungen); $i++) {
        if (in_array($i, $processed)) continue;
        
        $current = $vertretungen[$i];
        $consecutiveHours = [$current['hour']];
        $processed[] = $i;
        
        // Suche nach aufeinanderfolgenden Stunden mit identischen Daten
        for ($j = $i + 1; $j < count($vertretungen); $j++) {
            if (in_array($j, $processed)) continue;
            
            $next = $vertretungen[$j];
            
            // Prüfe ob alle relevanten Felder identisch sind (außer hour und id)
            $fieldsToCompare = ['type', 'class', 'subject', 'room', 'originalTeacher', 'substituteTeacher', 'originalRoom', 'bookedRoom', 'hasClassInfo'];
            $identical = true;
            
            foreach ($fieldsToCompare as $field) {
                if (isset($current[$field]) !== isset($next[$field]) || 
                    (isset($current[$field]) && $current[$field] !== $next[$field])) {
                    $identical = false;
                    break;
                }
            }
            
            // Spezialbehandlung für Raumbuchungen ohne Klasseninfo (freie Stunden)
            if ($current['type'] === 'room-booking' && isset($current['hasClassInfo']) && !$current['hasClassInfo'] &&
                $next['type'] === 'room-booking' && isset($next['hasClassInfo']) && !$next['hasClassInfo'] &&
                $current['bookedRoom'] === $next['bookedRoom']) {
                $identical = true;
            }
            
            if ($identical && is_numeric($current['hour']) && is_numeric($next['hour']) && 
                abs(intval($next['hour']) - intval($current['hour'])) <= 1) {
                $consecutiveHours[] = $next['hour'];
                $processed[] = $j;
            }
        }
        
        // Gruppiere die Stunden wenn mehr als eine vorhanden
        if (count($consecutiveHours) > 1) {
            sort($consecutiveHours);
            $current['hour'] = $consecutiveHours[0] . '-' . end($consecutiveHours);
            $current['time'] = getStundenzeit($current['hour']);
        }
        
        $grouped[] = $current;
    }
    
    return $grouped;
}

// A/B-Woche laden
$currentWeekType = loadCurrentWeekType();
$dateWeekType = getWeekTypeForDate($currentDate, $currentWeekType);

// GPU014-Vertretungsdaten laden
$alleVertretungen = loadGPU014($displayTeacherId, $currentDate);

// Klausuren für das gewählte Datum laden
$selectedDateString = $currentDate->format('Y-m-d');
$klausuren = loadKlausuren($displayTeacherId, $selectedDateString);

// WICHTIG: Raumbuchungen für das gewählte Datum laden - ZUERST!
$debugInfo[] = "=== HAUPTPROGRAMM START ===";
$debugInfo[] = "Aktuelle A/B-Woche (heute): " . $currentWeekType;
$debugInfo[] = "A/B-Woche für gewähltes Datum: " . $dateWeekType;
$debugInfo[] = "Lade Raumbuchungen für Lehrer: $displayTeacherId, Tag: $dayOfWeek, Datum: $selectedDateString";

$raumbuchungen = processRaumbuchungen($displayTeacherId, $dayOfWeek, $currentDate);

$debugInfo[] = "Raumbuchungen geladen: " . count($raumbuchungen);
if (!empty($raumbuchungen)) {
    foreach ($raumbuchungen as $booking) {
        $debugInfo[] = "  - " . json_encode($booking);
    }
}

// GPU014-Vertretungen verarbeiten
$tagesVertretungen = [];
$entfallStunden = []; // Array zum Tracking von Entfall-Stunden

foreach ($alleVertretungen as $vertretungLine) {
    $vertretung = parseGPU014Line($vertretungLine, $displayTeacherId, $dateWeekType);
    if ($vertretung) {
        // Track Entfall-Stunden
        if ($vertretung['type'] === 'cancellation') {
            $entfallStunden[] = $vertretung['hour'];
        }
        
        // KORRIGIERT: Prüfen ob für diese Stunde eine Raumbuchung existiert
        // Wenn ja, Raumwechsel ignorieren und nur Raumbuchung anzeigen
        if ($vertretung['type'] === 'room-change') {
            $hasBooking = false;
            foreach ($raumbuchungen as $booking) {
                if ($booking['hour'] == $vertretung['hour']) {
                    $hasBooking = true;
                    $debugInfo[] = "Raumwechsel für Stunde {$vertretung['hour']} ignoriert - Raumbuchung existiert";
                    break;
                }
            }
            if ($hasBooking) {
                continue; // Raumwechsel überspringen, da Raumbuchung existiert
            }
        }
        
        $vertretung['time'] = getStundenzeit($vertretung['hour']);
        $vertretung['id'] = count($tagesVertretungen) + 1;
        $tagesVertretungen[] = $vertretung;
    }
}

// KORRIGIERT: Raumbuchungen hinzufügen, aber nur wenn die Stunde nicht entfällt
$debugInfo[] = "Füge Raumbuchungen zu tagesVertretungen hinzu...";
$debugInfo[] = "Entfall-Stunden: " . implode(', ', $entfallStunden);

foreach ($raumbuchungen as $raumbuchung) {
    // Prüfen ob die Stunde entfällt
    if (in_array($raumbuchung['hour'], $entfallStunden)) {
        $debugInfo[] = "Raumbuchung für Stunde {$raumbuchung['hour']} ignoriert - Stunde entfällt";
        continue;
    }
    
    $raumbuchung['time'] = getStundenzeit($raumbuchung['hour']);
    $raumbuchung['id'] = count($tagesVertretungen) + count($raumbuchungen) + 1;
    $tagesVertretungen[] = $raumbuchung;
    $debugInfo[] = "Raumbuchung hinzugefügt: Stunde {$raumbuchung['hour']}, Raum {$raumbuchung['bookedRoom']}";
}

$debugInfo[] = "Gesamt tagesVertretungen vor Gruppierung: " . count($tagesVertretungen);

// Aufeinanderfolgende Stunden gruppieren
$tagesVertretungen = groupConsecutiveEntries($tagesVertretungen);

// Klausuren hinzufügen
foreach ($klausuren as $klausur) {
    $formattedKlausur = formatKlausur($klausur, $displayTeacherId);
    $formattedKlausur['time'] = getStundenzeit($formattedKlausur['hour']);
    $formattedKlausur['id'] = count($tagesVertretungen) + count($klausuren) + 1;
    $tagesVertretungen[] = $formattedKlausur;
}

// Nach Stunden sortieren
function getSortPosition($hour, $type) {
    // Für Pausenaufsichten: Position genau zwischen den Stunden platzieren
    if ($type === 'break-supervision') {
        if (preg_match('/^(\d+)\/(\d+)$/', $hour, $matches)) {
            $ersteStunde = intval($matches[1]);
            return ($ersteStunde * 10) + 5; // 4/5 wird zu 45
        }
        if (preg_match('/^(\d+)/', $hour, $matches)) {
            return (intval($matches[1]) * 10) + 5;
        }
        return 9999;
    }
    
    // Für normale Stunden
    if (is_numeric($hour)) {
        return intval($hour) * 10; // 4 wird zu 40, 5 wird zu 50
    }
    
    // Für Stundenbereiche
    if (strpos($hour, '-') !== false) {
        $parts = explode('-', $hour);
        return intval($parts[0]) * 10;
    }
    
    // Für Doppelstunden
    if (strpos($hour, '/') !== false) {
        $parts = explode('/', $hour);
        return intval($parts[0]) * 10;
    }
    
    return 9999;
}

// Nach Stunden sortieren (ersetzt die ursprüngliche usort-Funktion)
usort($tagesVertretungen, function($a, $b) {
    $positionA = getSortPosition($a['hour'], $a['type']);
    $positionB = getSortPosition($b['hour'], $b['type']);
    
    // Primäre Sortierung nach Position
    if ($positionA != $positionB) {
        return $positionA - $positionB;
    }
    
    // Sekundäre Sortierung: Normale Stunden vor Pausenaufsichten bei gleicher Position
    $typeOrderA = ($a['type'] === 'break-supervision') ? 1 : 0;
    $typeOrderB = ($b['type'] === 'break-supervision') ? 1 : 0;
    
    return $typeOrderA - $typeOrderB;
});

$debugInfo[] = "Finale tagesVertretungen nach Sortierung: " . count($tagesVertretungen);
$debugInfo[] = "=== HAUPTPROGRAMM ENDE ===";

// Deutsche Wochentage für die Anzeige
function getGermanDayName($date) {
    $days = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
    return $days[$date->format('w')];
}

// NEUE Funktion: Navigationslogik für Werktage
function getNextWorkdayDate($date, $direction = 1) {
    $newDate = clone $date;
    
    if ($direction > 0) { // Vorwärts
        $newDate->add(new DateInterval('P1D'));
        $dayOfWeek = $newDate->format('N');
        
        // Wochenende überspringen
        if ($dayOfWeek == 6) { // Samstag -> Montag
            $newDate->add(new DateInterval('P2D'));
        } elseif ($dayOfWeek == 7) { // Sonntag -> Montag
            $newDate->add(new DateInterval('P1D'));
        }
    } else { // Rückwärts
        $newDate->sub(new DateInterval('P1D'));
        $dayOfWeek = $newDate->format('N');
        
        // Wochenende überspringen
        if ($dayOfWeek == 7) { // Sonntag -> Freitag
            $newDate->sub(new DateInterval('P2D'));
        } elseif ($dayOfWeek == 6) { // Samstag -> Freitag
            $newDate->sub(new DateInterval('P1D'));
        }
    }
    
    return $newDate;
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SMG Vertretungsplan</title>
  <link rel="icon" type="image/x-icon" href="https://smg-adlersberg.de/neuesdesign/SMG-cropped-for-ico2_1.ico">
  <link rel="stylesheet" href="https://smg-adlersberg.de/neuesdesign/vertretungen.css">
</head>
<body>
  <div class="header">
    <a href="https://smg-adlersberg.de/timedex/mainpage/lehrer.php?k=<?= urlencode($_SESSION['username']) ?>">
      <img src="https://smg-adlersberg.de/vertretungsplan/design/SMG-Logo2.png" alt="SMG Logo">
    </a>
    <h1>Sophie-Mereau-Gymnasium</h1>
    <h2>Onlineplan</h2>
  </div>

  <div class="icon-bar">
    <div>
        <a href="https://smg-adlersberg.de/timedex/lehrer" style="text-decoration: none; color: inherit;">
            <img src="https://smg-adlersberg.de/neuesdesign/Lehrer.png" alt="Lehrer">
            <p>Lehrer</p>
        </a>
    </div>
    <div>
        <a href="https://smg-adlersberg.de/timedex/klassen" style="text-decoration: none; color: inherit;">
            <img src="https://smg-adlersberg.de/neuesdesign/Klassen.png" alt="Klassen">
            <p>Klassen</p>
        </a>
    </div>
    <div>
        <a href="https://smg-adlersberg.de/timedex/faecher" style="text-decoration: none; color: inherit;">
            <img src="https://smg-adlersberg.de/neuesdesign/Fächer.png" alt="Fächer">
            <p>Fächer</p>
        </a>
    </div>
    <div>
        <a href="https://smg-adlersberg.de/timedex/raueme" style="text-decoration: none; color: inherit;">
            <img src="https://smg-adlersberg.de/neuesdesign/Räume.png" alt="Räume">
            <p>Räume</p>
        </a>
    </div>
    <div>
        <a href="https://smg-adlersberg.de/timedex/schueler" style="text-decoration: none; color: inherit;">
            <img src="https://smg-adlersberg.de/neuesdesign/Schüler.png" alt="Schüler">
            <p>Schüler</p>
        </a>
    </div>
</div>

  <div class="container">
    <div class="page-header">
      <h1>Vertretungsplan</h1>
    
      <div class="last-updated" id="last-updated">wird geladen...</div>
    </div>

    <div class="date-navigation">
      <button class="nav-button" id="prev-day">← Vorheriger Tag</button>
      <div class="current-date" id="current-date"><?= getGermanDayName($currentDate) . ', ' . $currentDate->format('d.m.Y') ?></div>
      <button class="nav-button" id="next-day">Nächster Tag →</button>
    </div>


    <!-- DEBUG-SEKTION (nur anzeigen wenn ?debug=1 im URL steht) -->
    <?php if (isset($_GET['debug']) && $_GET['debug'] == '1' && !empty($debugInfo)): ?>
      <div style="background: #f0f0f0; padding: 15px; margin: 20px 0; border: 1px solid #ccc;">
        <h3>Debug-Informationen (URL: ?debug=0 zum Ausblenden):</h3>
        <div style="font-size: 12px; max-height: 400px; overflow-y: auto; white-space: pre-wrap; font-family: monospace;">
        <?php
        echo "=== SYSTEM-INFO ===\n";
        echo "Aktuelles Datum: " . $currentDate->format('Y-m-d') . "\n";
        echo "Wochentag: " . $dayOfWeek . "\n";
        echo "A/B-Woche (heute): " . $currentWeekType . "\n";
        echo "A/B-Woche (gewähltes Datum): " . $dateWeekType . "\n";
        echo "Teacher ID: '" . $displayTeacherId . "'\n";
        echo "Session Username: '" . ($_SESSION['username'] ?? 'NICHT GESETZT') . "'\n";
        if ($isMCP) {
            echo "MCP Admin Mode: " . ($displayTeacherId !== $teacherId ? "Anzeige für $displayTeacherId" : "Normale Anzeige") . "\n";
        }
        echo "Gewählte Wochendatei: " . getWeekFile($currentDate) . "\n";
        echo "GPU014.TXT verwendet" . "\n";
        echo "\n=== DEBUG-LOG ===\n";
        
        foreach ($debugInfo as $info) {
            echo htmlspecialchars($info) . "\n";
        }
        
        echo "\n=== FINALE RESULTATE ===\n";
        echo "Anzahl Raumbuchungen: " . count($raumbuchungen) . "\n";
        if (!empty($raumbuchungen)) {
            foreach ($raumbuchungen as $i => $booking) {
                echo "Raumbuchung $i: " . json_encode($booking, JSON_UNESCAPED_UNICODE) . "\n";
            }
        }
        
        echo "\nAnzahl tagesVertretungen: " . count($tagesVertretungen) . "\n";
        foreach ($tagesVertretungen as $i => $vert) {
            echo "Vertretung $i (" . $vert['type'] . "): Stunde " . $vert['hour'] . "\n";
        }
        ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- MCP Admin Panel -->
    <?php if ($isMCP): ?>
      <div class="mcp-admin-panel" style="background: #e8f5e8; border: 2px solid #4CAF50; padding: 15px; margin: 20px 0; border-radius: 8px;">
        <h3 style="color: #2E7D32; margin-top: 0;">🔧 MCP Admin Panel</h3>
        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
          <label style="font-weight: bold;">Als Lehrer anzeigen:</label>
          <input type="text" id="teacher-input" placeholder="Lehrerkürzel (z.B. HAR)" 
                 value="<?= isset($_GET['teacher']) ? htmlspecialchars($_GET['teacher']) : '' ?>"
                 style="padding: 8px; border: 1px solid #ccc; border-radius: 4px; width: 120px; text-transform: uppercase;">
          <button onclick="switchTeacher()" style="padding: 8px 15px; background: #4CAF50; color: white; border: none; border-radius: 4px; cursor: pointer;">
            Anzeigen
          </button>
          <button onclick="resetTeacher()" style="padding: 8px 15px; background: #f44336; color: white; border: none; border-radius: 4px; cursor: pointer;">
            Reset (MCP)
          </button>
          <span style="color: #666; font-size: 14px;">
            Aktuell: <strong><?= htmlspecialchars($displayTeacherId) ?></strong>
            <?= $displayTeacherId !== $teacherId ? ' (Override aktiv)' : ' (Normal)' ?>
          </span>
        </div>
      </div>
    <?php endif; ?>

    <div class="substitutions-container" id="substitutions-container">
      <?php if (empty($tagesVertretungen)): ?>
        <div class="no-substitutions">
          <div class="no-substitutions-icon">✓</div>
          <h3>Keine Vertretungen</h3>
          <p>Für diesen Tag sind keine Änderungen in Ihrem Stundenplan verzeichnet.</p>
        </div>
      <?php else: ?>
        <?php foreach ($tagesVertretungen as $vertretung): ?>
          <div class="substitution-item">
            <div class="substitution-header">
              <?php if ($vertretung['type'] == 'break-supervision'): ?>
                <div class="lesson-info">
                  <div class="break-supervision-info">
                    <div class="break-badge">PAUSE <?= htmlspecialchars($vertretung['hour']) ?></div>
                    <div class="break-details">
                      <div class="break-time"><?= htmlspecialchars($vertretung['time']) ?></div>
                      <div class="break-location"><?= htmlspecialchars($vertretung['location']) ?></div>
                    </div>
                  </div>
                </div>
              <?php else: ?>
                <div class="lesson-info">
                  <div class="hour-badge"><?= htmlspecialchars($vertretung['hour']) ?></div>
                 <div class="lesson-details">
  <div class="class-time">
    <?php 
    // Prüfen ob es eine Verlegung ist
    $istVerlegung = ($vertretung['type'] == 'substitution' && 
                    isset($vertretung['originalSubject']) && isset($vertretung['newSubject']) && 
                    $vertretung['originalSubject'] !== $vertretung['newSubject'] && 
                    !empty($vertretung['originalSubject']) && !empty($vertretung['newSubject']));
    
    if ($istVerlegung): 
      // VERLEGUNG: Zeige Klasse an der Position wo normalerweise der neue Raum steht
      ?>
      <?= htmlspecialchars($vertretung['class']) ?> • <?= htmlspecialchars($vertretung['time']) ?>
    <?php else: 
      // NORMALE VERTRETUNG: Bisherige Logik
      ?>
      <?php if (isset($vertretung['hasClassInfo']) && $vertretung['hasClassInfo'] && isset($vertretung['class']) && !empty($vertretung['class'])): ?>
        <?= htmlspecialchars($vertretung['class']) ?> • 
      <?php elseif (!isset($vertretung['hasClassInfo']) || $vertretung['hasClassInfo']): ?>
        <?php if (isset($vertretung['class']) && !empty($vertretung['class'])): ?>
          <?= htmlspecialchars($vertretung['class']) ?> • 
        <?php endif; ?>
      <?php endif; ?>
      <?= htmlspecialchars($vertretung['time']) ?>
    <?php endif; ?>
  </div>
                    <?php if (isset($vertretung['hasClassInfo']) && $vertretung['hasClassInfo'] && isset($vertretung['subject']) && !empty($vertretung['subject'])): ?>
                      <div class="subject-info">
                        <span class="subject-badge"><?= htmlspecialchars($vertretung['subject']) ?></span>
                        <span class="room-info">Raum <?= htmlspecialchars($vertretung['room']) ?></span>
                      </div>
                    <?php elseif ((!isset($vertretung['hasClassInfo']) || $vertretung['hasClassInfo']) && isset($vertretung['subject']) && !empty($vertretung['subject'])): ?>
                      <div class="subject-info">
                        <span class="subject-badge"><?= htmlspecialchars($vertretung['subject']) ?></span>
                        <span class="room-info">Raum <?= htmlspecialchars($vertretung['room']) ?></span>
                      </div>
                    <?php elseif (isset($vertretung['hasClassInfo']) && !$vertretung['hasClassInfo']): ?>
                      <div class="subject-info">
                        <span class="room-info">Raum <?= htmlspecialchars($vertretung['room']) ?></span>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endif; ?>
              
              <div class="substitution-type type-<?= htmlspecialchars($vertretung['type']) ?>">
                <?php
$typeTexts = [
    'cancellation' => 'Entfall',
    'substitution' => 'Vertretung',
    'break-supervision' => 'Pausenaufsicht',
    'room-change' => 'Raumänderung',
    'room-booking' => 'Raumbuchung',
    'exam' => 'Klausur',
    'exam-supervision' => 'Aufsicht'
];

// Spezialbehandlung für Verlegungen
if ($vertretung['type'] === 'substitution' && isset($vertretung['isRelocation']) && $vertretung['isRelocation']) {
    echo 'Verlegung';
} else {
    echo htmlspecialchars($typeTexts[$vertretung['type']] ?? $vertretung['type']);
}
?>
              </div>
            </div>
            
            <?php if ($vertretung['type'] == 'substitution'): ?>
              <div class="substitution-details">
                <?php if (isset($vertretung['isBeingSubstituted']) && $vertretung['isBeingSubstituted']): ?>
                  <div class="detail-row">
                    <span class="detail-label">Vertreten von:</span>
                    <div class="teacher-change">
                      <span class="teacher-badge original"><?= htmlspecialchars($vertretung['originalTeacher']) ?></span>
                      <span class="change-arrow">→</span>
                      <span class="teacher-badge substitute"><?= htmlspecialchars($vertretung['substituteTeacher']) ?></span>
                    </div>
                  </div>
                  <?php if (isset($vertretung['newSubject']) && $vertretung['originalSubject'] != $vertretung['newSubject']): ?>
                    <div class="detail-row">
                      <span class="detail-label">Fach:</span>
                      <div class="subject-change">
                        <span class="subject-change-badge original"><?= htmlspecialchars($vertretung['originalSubject']) ?></span>
                        <span class="change-arrow">→</span>
                        <span class="subject-change-badge new"><?= htmlspecialchars($vertretung['newSubject']) ?></span>
                      </div>
                    </div>
                  <?php endif; ?>
                  <?php if (isset($vertretung['newRoom']) && isset($vertretung['originalRoom']) && $vertretung['originalRoom'] != $vertretung['newRoom']): ?>
                    <div class="detail-row">
                      <span class="detail-label">Raum:</span>
                      <div class="room-change">
                        <span class="room-badge original"><?= htmlspecialchars($vertretung['originalRoom']) ?></span>
                        <span class="change-arrow">→</span>
                        <span class="room-badge new"><?= htmlspecialchars($vertretung['newRoom']) ?></span>
                      </div>
                    </div>
                  <?php endif; ?>
                <?php else: ?>
                  <div class="detail-row">
                    <span class="detail-label">Sie vertreten:</span>
                    <div class="teacher-change">
                      <span class="teacher-badge original"><?= htmlspecialchars($vertretung['originalTeacher']) ?></span>
                      <span class="change-arrow">→</span>
                      <span class="teacher-badge substitute"><?= htmlspecialchars($vertretung['substituteTeacher']) ?></span>
                    </div>
                  </div>
                  <?php if (isset($vertretung['originalSubject']) && $vertretung['originalSubject'] != $vertretung['newSubject']): ?>
                    <div class="detail-row">
                      <span class="detail-label">Fach:</span>
                      <div class="subject-change">
                        <span class="subject-change-badge original"><?= htmlspecialchars($vertretung['originalSubject']) ?></span>
                        <span class="change-arrow">→</span>
                        <span class="subject-change-badge new"><?= htmlspecialchars($vertretung['newSubject']) ?></span>
                      </div>
                    </div>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            <?php elseif ($vertretung['type'] == 'break-supervision'): ?>
              <div class="substitution-details">
                <div class="detail-row">
                  <span class="detail-label">
                    <?php if (isset($vertretung['isSubstituting']) && $vertretung['isSubstituting']): ?>
                      Sie übernehmen:
                    <?php else: ?>
                      Vertreten von:
                    <?php endif; ?>
                  </span>
                  <div class="teacher-change">
                    <span class="teacher-badge original"><?= htmlspecialchars($vertretung['originalTeacher']) ?></span>
                    <span class="change-arrow">→</span>
                    <span class="teacher-badge substitute"><?= htmlspecialchars($vertretung['substituteTeacher']) ?></span>
                  </div>
                </div>
                <div class="detail-row">
                  <span class="detail-label">Ort:</span>
                  <span class="room-info"><?= htmlspecialchars($vertretung['location']) ?></span>
                </div>
              </div>
            <?php elseif ($vertretung['type'] == 'room-change'): ?>
              <div class="substitution-details">
                <div class="detail-row">
                  <span class="detail-label">Raumwechsel:</span>
                  <div class="room-change">
                    <span class="room-badge original"><?= htmlspecialchars($vertretung['originalRoom']) ?></span>
                    <span class="change-arrow">→</span>
                    <span class="room-badge new"><?= htmlspecialchars($vertretung['newRoom']) ?></span>
                  </div>
                </div>
              </div>
            <?php elseif ($vertretung['type'] == 'room-booking'): ?>
              <div class="substitution-details">
                <?php if (isset($vertretung['hasClassInfo']) && $vertretung['hasClassInfo']): ?>
                  <!-- Raumbuchung mit Klasseninformation -->
                  <div class="detail-row">
                    <span class="detail-label">Raumbuchung:</span>
                    <div class="room-change">
                      <span class="room-badge original"><?= htmlspecialchars($vertretung['originalRoom']) ?></span>
                      <span class="change-arrow">→</span>
                      <span class="room-badge new"><?= htmlspecialchars($vertretung['bookedRoom']) ?></span>
                    </div>
                  </div>
                  
                <?php else: ?>
                  <!-- Raumbuchung ohne Klasseninformation (freie Stunde) -->
                  <div class="detail-row">
                    <span class="detail-label">Raumbuchung:</span>
                    <div class="room-info-single">
                      <span class="room-badge new"><?= htmlspecialchars($vertretung['bookedRoom']) ?></span>
                    </div>
                  </div>
                  
                <?php endif; ?>
              </div>
            <?php elseif (in_array($vertretung['type'], ['exam', 'exam-supervision']) && isset($vertretung['supervisions'])): ?>
              <div class="substitution-details">
                <div class="detail-row">
                  <span class="detail-label">Aufsichten:</span>
                  <div class="supervision-list">
                    <?php foreach ($vertretung['supervisions'] as $supervision): ?>
                      <div class="supervision-hour">
                        <span class="hour-label">Std. <?= htmlspecialchars($supervision['hour']) ?></span>
                        <div class="supervision-teachers">
                          <?php foreach ($supervision['teachers'] as $teacher): ?>
                            <span class="supervision-badge <?= ($teacher == $displayTeacherId) ? 'self' : '' ?>"><?= htmlspecialchars($teacher) ?></span>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>


<div class="container">
    <div class="day-selector" id="day-selector">
      <?php
      // Wochenstart (Montag) ermitteln - nur für die aktuelle Woche des gewählten Datums
      $monday = clone $currentDate;
      $monday->setISODate($currentDate->format('Y'), $currentDate->format('W'), 1);
      
      $tage = ['MO', 'DI', 'MI', 'DO', 'FR'];
      for ($i = 0; $i < 5; $i++) {
        $tagDatum = clone $monday;
        $tagDatum->add(new DateInterval('P' . $i . 'D'));
        $istAktuellerTag = $tagDatum->format('Y-m-d') === $currentDate->format('Y-m-d');
        $istHeute = $tagDatum->format('Y-m-d') === (new DateTime())->format('Y-m-d');
        ?>
        <button class="day-button <?= $istAktuellerTag ? 'active' : '' ?> <?= $istHeute ? 'today' : '' ?>" 
                data-day="<?= $i + 1 ?>" data-date="<?= $tagDatum->format('Y-m-d') ?>">
          <div><?= $tage[$i] ?></div>
          <div class="day-info"><?= $tagDatum->format('d.m.') ?></div>
        </button>
        <?php
      }
      ?>
    </div>
  </div>

  <button class="refresh-button" id="refresh-button" title="Aktualisieren">
    ↻
  </button>

  <script>
    const teacherId = "<?= $displayTeacherId ?>";
    const currentDateString = "<?= $currentDate->format('Y-m-d') ?>";
    const isMCP = <?= $isMCP ? 'true' : 'false' ?>;
    
    // MCP Admin Panel Funktionen
    function switchTeacher() {
      const teacherInput = document.getElementById('teacher-input');
      const teacher = teacherInput.value.trim().toUpperCase();
      
      if (!teacher) {
        alert('Bitte geben Sie ein Lehrerkürzel ein!');
        return;
      }
      
      // URL mit teacher Parameter aufrufen
      const currentUrl = new URL(window.location);
      currentUrl.searchParams.set('teacher', teacher);
      if (currentUrl.searchParams.has('date')) {
        // Datum beibehalten wenn gesetzt
      } else {
        currentUrl.searchParams.set('date', currentDateString);
      }
      
      window.location.href = currentUrl.toString();
    }
    
    function resetTeacher() {
      // URL ohne teacher Parameter aufrufen
      const currentUrl = new URL(window.location);
      currentUrl.searchParams.delete('teacher');
      if (currentUrl.searchParams.has('date')) {
        // Datum beibehalten wenn gesetzt
      } else {
        currentUrl.searchParams.set('date', currentDateString);
      }
      
      window.location.href = currentUrl.toString();
    }
    
    // Enter-Taste im Input-Feld
    if (isMCP) {
      document.addEventListener('DOMContentLoaded', function() {
        const teacherInput = document.getElementById('teacher-input');
        if (teacherInput) {
          teacherInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
              switchTeacher();
            }
          });
          
          // Automatisches Großschreiben
          teacherInput.addEventListener('input', function(e) {
            this.value = this.value.toUpperCase();
          });
        }
      });
    }
    
    // Scroll-Position speichern und wiederherstellen
    function saveScrollPosition() {
      sessionStorage.setItem('scrollPosition', window.scrollY);
    }
    
    function restoreScrollPosition() {
      const savedPosition = sessionStorage.getItem('scrollPosition');
      if (savedPosition !== null) {
        window.scrollTo(0, parseInt(savedPosition));
        // Position nach Wiederherstellung löschen, damit sie nicht bei anderen Aktionen stört
        sessionStorage.removeItem('scrollPosition');
      }
    }
    
    // Letzte Aktualisierung laden
    async function loadLastUpdated() {
      try {
        const response = await fetch('https://smg-adlersberg.de/timedex/external/aktualisiert.php');
        if (response.ok) {
          const text = await response.text();
          const match = text.match(/<p>(.*?)<\/p>/);
          if (match && match[1]) {
            document.getElementById('last-updated').textContent = match[1];
          } else {
            document.getElementById('last-updated').textContent = 'Aktualisierungszeit nicht verfügbar';
          }
        } else {
          throw new Error('Fehler beim Laden');
        }
      } catch (error) {
        console.error('Fehler beim Laden der Aktualisierungszeit:', error);
        document.getElementById('last-updated').textContent = 'aktualisiert: wird ermittelt...';
      }
    }
    
    // Datum Navigation - ERWEITERT für MCP Teacher Override
    function navigateToDate(dateString) {
      saveScrollPosition();
      
      const currentUrl = new URL(window.location);
      currentUrl.searchParams.set('date', dateString);
      
      // MCP Teacher Override beibehalten
      if (isMCP && currentUrl.searchParams.has('teacher')) {
        // Teacher Parameter bleibt erhalten
      }
      
      window.location.href = currentUrl.toString();
    }
    
    // NEUE Funktion: Wochenende überspringen (nur Werktage Mo-Fr)
    function getNextWorkday(dateString, direction) {
      const date = new Date(dateString);
      
      if (direction > 0) { // Vorwärts
        date.setDate(date.getDate() + 1);
        let dayOfWeek = date.getDay(); // 0=Sonntag, 1=Montag, ..., 6=Samstag
        
        // Wochenende überspringen
        if (dayOfWeek === 6) { // Samstag -> Montag
          date.setDate(date.getDate() + 2);
        } else if (dayOfWeek === 0) { // Sonntag -> Montag
          date.setDate(date.getDate() + 1);
        }
      } else { // Rückwärts
        date.setDate(date.getDate() - 1);
        let dayOfWeek = date.getDay();
        
        // Wochenende überspringen
        if (dayOfWeek === 0) { // Sonntag -> Freitag
          date.setDate(date.getDate() - 2);
        } else if (dayOfWeek === 6) { // Samstag -> Freitag
          date.setDate(date.getDate() - 1);
        }
      }
      
      return date.toISOString().split('T')[0];
    }
    
    // Event-Listener für Tag-Buttons
    document.querySelectorAll('.day-button').forEach(button => {
      button.addEventListener('click', function() {
        const selectedDate = this.getAttribute('data-date');
        navigateToDate(selectedDate);
      });
    });
    
    // Navigation Buttons - AKTUALISIERT für Werktage
    document.getElementById('prev-day').addEventListener('click', function() {
      const prevDate = getNextWorkday(currentDateString, -1);
      navigateToDate(prevDate);
    });
    
    document.getElementById('next-day').addEventListener('click', function() {
      const nextDate = getNextWorkday(currentDateString, 1);
      navigateToDate(nextDate);
    });
    
    // Refresh Button
    document.getElementById('refresh-button').addEventListener('click', function() {
      location.reload();
    });
    
    // Keyboard Navigation
    document.addEventListener('keydown', function(e) {
      if (e.key === 'ArrowLeft') {
        document.getElementById('prev-day').click();
      } else if (e.key === 'ArrowRight') {
        document.getElementById('next-day').click();
      } else if (e.key === 'F5' || (e.ctrlKey && e.key === 'r')) {
        e.preventDefault();
        location.reload();
      }
    });
    
    // Initialisierung
    document.addEventListener('DOMContentLoaded', function() {
      // Scroll-Position wiederherstellen falls vorhanden
      restoreScrollPosition();
      
      // Letzte Aktualisierung laden
      loadLastUpdated();
      
      // Automatischer Sprung zum nächsten Montag ab Samstag 0 Uhr
      const now = new Date();
      const dayOfWeek = now.getDay(); // 0=Sonntag, 6=Samstag
      
      // Wenn heute Samstag (6) oder Sonntag (0) ist und wir auf heute schauen
      if ((dayOfWeek === 6 || dayOfWeek === 0) && 
          currentDateString === now.toISOString().split('T')[0]) {
        const nextMonday = getNextWorkday(currentDateString, 1);
        if (nextMonday !== currentDateString) {
          navigateToDate(nextMonday);
        }
      }
    });
    
    // Auto-Refresh alle 5 Minuten
    setInterval(loadLastUpdated, 300000);
  </script>
</body>
</html>