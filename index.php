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

// Schülername aus dem URL-Parameter oder dem Dateinamen ermitteln
$studentUsername = '';

// Überprüfen, ob ein Student-Parameter übergeben wurde
if (isset($_GET['student'])) {
    $studentUsername = $_GET['student'];
} else {
    // Extrahiere den Schülernamen aus der URL
    $requestUri = $_SERVER['REQUEST_URI'];
    $parts = explode('/', $requestUri);
    $filename = end($parts);
    
    // Extrahiere den Schülernamen aus dem Dateinamen (ohne .php)
    $studentUsername = basename($filename, '.php');
}

// URLs der benötigten Dateien
define('STUNDENPLAN_URL', '../../stammdaten/GPU001.TXT');
define('WEEK_URL', 'https://smg-adlersberg.de/timedex/stdplweek.php');
define('KLASSENMAIN_URL', 'https://smg-adlersberg.de/koordination/KLASSENMAIN.php');
define('VERTRETUNG_URL', 'https://smg-adlersberg.de/koordination/vertretungenklassen.php');
define('RAUMBUCHUNG_URL', 'https://smg-adlersberg.de/timedex/bookings_regular.json');
define('KLASSENARBEITEN_URL', 'https://smg-adlersberg.de/timedex/klausuren.json');

// Funktion, um die Klassenmain-Datei zu holen
function fetchKlassenmain($url) {
    $data = file_get_contents($url);
    if ($data === false) {
        die("Fehler: Klassenmain-Datei konnte nicht abgerufen werden.");
    }
    return explode("\n", $data);
}

// Funktion, um Klassenarbeiten zu holen und zu filtern
function fetchKlassenarbeiten($url, $studentClass) {
    $data = @file_get_contents($url);
    if ($data === false) {
        return [];
    }
    
    $arbeiten = json_decode($data, true);
    if ($arbeiten === null) {
        return [];
    }
    
    $relevantArbeiten = [];
    $today = new DateTime('today');
    
    // Mapping deutscher Monate auf numerische Werte
    $germanMonths = [
        'Januar' => '01', 'Februar' => '02', 'März' => '03', 'April' => '04',
        'Mai' => '05', 'Juni' => '06', 'Juli' => '07', 'August' => '08',
        'September' => '09', 'Oktober' => '10', 'November' => '11', 'Dezember' => '12'
    ];
    
    // Deutsche Wochentage
    $germanWeekdays = [
        'Monday' => 'Montag',
        'Tuesday' => 'Dienstag', 
        'Wednesday' => 'Mittwoch',
        'Thursday' => 'Donnerstag',
        'Friday' => 'Freitag',
        'Saturday' => 'Samstag',
        'Sunday' => 'Sonntag'
    ];
    
    foreach ($arbeiten as $arbeit) {
        // Prüfen, ob die Klassenarbeit für die richtige Klasse ist
        if (isset($arbeit['class']) && $arbeit['class'] === $studentClass) {
            // Datum parsen
            if (isset($arbeit['date']) && preg_match('/(\d{1,2})\. ([^\d]+)/u', $arbeit['date'], $dateMatches)) {
                $day = $dateMatches[1];
                $monthName = trim($dateMatches[2]);
                
                if (isset($germanMonths[$monthName])) {
                    $year = $today->format('Y');
                    
                    // Wenn das Datum in der Vergangenheit liegt, nehme nächstes Jahr
                    $currentMonth = (int)$today->format('m');
                    $targetMonth = (int)$germanMonths[$monthName];
                    $currentDay = (int)$today->format('d');
                    $targetDay = (int)$day;
                    
                    if ($targetMonth < $currentMonth || ($targetMonth == $currentMonth && $targetDay < $currentDay)) {
                        $year++;
                    }
                    
                    // Datum konvertieren
                    $dateString = sprintf('%02d-%02d-%d', $day, $germanMonths[$monthName], $year);
                    $date = DateTime::createFromFormat('d-m-Y', $dateString);
                    
                    // Nur zukünftige oder heutige Termine
                    if ($date >= $today) {
                        // Wochentag auf Deutsch ermitteln
                        $englishWeekday = $date->format('l');
                        $germanWeekday = $germanWeekdays[$englishWeekday] ?? $englishWeekday;
                        
                        // Formatiertes Datum mit Wochentag erstellen
                        $formattedDate = $arbeit['date'] . ' <small style="font-size: 70%; font-weight: normal;">(' . $germanWeekday . ')</small>';
                        
                        $relevantArbeiten[] = [
                            'date' => $date,
                            'date_string' => $formattedDate,
                            'subject' => $arbeit['subject'] ?? 'Unbekannt',
                            'teacher' => $arbeit['teacher'] ?? 'Unbekannt',
                            'room' => $arbeit['room'] ?? 'Unbekannt',
                            'duration' => $arbeit['duration'] ?? 'Unbekannt'
                        ];
                    }
                }
            }
        }
    }
    
    // Nach Datum sortieren
    usort($relevantArbeiten, function ($a, $b) {
        return $a['date'] <=> $b['date'];
    });
    
    return $relevantArbeiten;
}

// Funktion, um Benutzerinformationen zu finden
function findUserInfo($username, $klassenmainData) {
    foreach ($klassenmainData as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        $parts = explode(',', $line);
        if (count($parts) < 4) continue;
        
        // Erzeuge Benutzernamen aus Vorname, Nachname und Klasse
        $nachname = $parts[0];
        $vorname = $parts[1];
        $geschlecht = $parts[2];
        $geburtsdatum = $parts[3];
        
        // Klasse aus dem Benutzernamen extrahieren (z.B. "10c" aus "lxndrrth10c")
        $klasse = "";
        if (preg_match('/(\d+[a-z]+)$/', $username, $matches)) {
            $klasse = strtoupper($matches[1]);
        }
        
        // Benutzernamen erzeugen (ohne Vokale)
        $generatedUsername = preg_replace('/[aeiouäöüAEIOUÄÖÜ]/', '', strtolower($vorname . $nachname)) . strtolower($klasse);
        
        if ($generatedUsername === $username) {
            return [
                'name' => $vorname . ' ' . $nachname,
                'klasse' => $klasse,
                'wahlFaecher' => array_slice($parts, 4)
            ];
        }
    }
    
    return null;
}

// Funktion, um den Stundenplan von der URL zu holen
function fetchSchedule($url) {
    $scheduleData = file_get_contents($url);
    if ($scheduleData === false) {
        die("Fehler: Stundenplandatei konnte nicht abgerufen werden.");
    }
    return explode("\n", $scheduleData);
}

// Funktion, um die aktuelle Woche zu ermitteln
function fetchWeekType($url) {
    $weekType = file_get_contents($url);
    if ($weekType === false) {
        die("Fehler: Wocheninformation konnte nicht abgerufen werden.");
    }
    return trim($weekType);
}

// Funktion, um die Vertretungen abzurufen
function fetchSubstitutions($url) {
    $substitutionData = file_get_contents($url);
    if ($substitutionData === false) {
        die("Fehler: Vertretungsdaten konnten nicht abgerufen werden.");
    }
    
    $substitutions = [];
    $lines = explode("\n", $substitutionData);
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        // Format: PNK,3,2=vertretung,WIT,201,BI oder PNK,2,1=raumwechsel,201
        if (preg_match('/^([A-ZÄÖÜßäöü]+),(\d+),(\d+)=(.+)$/', $line, $matches)) {
            $lehrer = $matches[1];
            $tag = (int)$matches[2];
            $stunde = (int)$matches[3];
            $info = $matches[4];
            
            $parts = explode(',', $info);
            $subInfo = [
                'class' => $parts[0]  // Die td-class (z.B. vertretung, raumwechsel)
            ];
            
            // Weitere Werte je nach Vertretungstyp hinzufügen
            if (count($parts) > 1) {
                if ($parts[0] === 'vertretung') {
                    if (isset($parts[1])) $subInfo['subject'] = $parts[1];  // Neues Fach
                    if (isset($parts[2])) $subInfo['room'] = $parts[2];     // Neuer Raum
                    if (isset($parts[3])) $subInfo['teacher'] = $parts[3];  // Neue Lehrkraft/Klasse
                } elseif ($parts[0] === 'raumwechsel') {
                    if (isset($parts[1])) $subInfo['room'] = $parts[1];     // Neuer Raum bei Raumwechsel
                }
                // Weitere Fälle können hier hinzugefügt werden
            }
            
            $substitutions[$lehrer][$tag][$stunde] = $subInfo;
        }
    }
    
    return $substitutions;
}

// Funktion, um die Raumbuchungen aus der JSON-Datei zu holen
function fetchRoomBookings($url) {
    // Versuch die Datei zu laden
    $bookingData = @file_get_contents($url);
    
    // Falls die Datei nicht geladen werden kann, leeres Array zurückgeben
    if ($bookingData === false) {
        error_log("Warnung: Raumbuchungsdaten konnten nicht von $url abgerufen werden.");
        return [];
    }
    
    // Prüfen ob die Datei leer ist
    if (empty(trim($bookingData))) {
        error_log("Warnung: Raumbuchungsdatei ist leer.");
        return [];
    }
    
    // Versuche JSON zu dekodieren
    $bookings = json_decode($bookingData, true);
    
    // Prüfe auf JSON-Fehler
    if ($bookings === null && json_last_error() !== JSON_ERROR_NONE) {
        error_log("Warnung: JSON-Dekodierungsfehler in Raumbuchungsdaten: " . json_last_error_msg());
        return [];
    }
    
    // Falls $bookings kein Array ist, leeres Array zurückgeben
    if (!is_array($bookings)) {
        error_log("Warnung: Raumbuchungsdaten sind kein gültiges Array.");
        return [];
    }
    
    // Mapping von Datum zu Wochentag (1=Mo, 2=Di, 3=Mi, 4=Do, 5=Fr)
    $dateToDay = [];
    $today = new DateTime();
    
    // Aktuelle Woche berechnen (Montag bis Freitag)
    $currentDayOfWeek = (int)$today->format('N'); // 1=Montag, 7=Sonntag
    
    // Zum Montag der aktuellen Woche zurückgehen
    $monday = clone $today;
    if ($currentDayOfWeek > 1) {
        $monday->modify('-' . ($currentDayOfWeek - 1) . ' days');
    }
    
    // Mapping für die aktuelle Woche erstellen
    for ($day = 1; $day <= 5; $day++) {
        $date = clone $monday;
        if ($day > 1) {
            $date->modify('+' . ($day - 1) . ' days');
        }
        
        // Format: TT.MM.JJ (z.B. 09.10.25)
        $dateString = $date->format('d.m.y');
        $dateToDay[$dateString] = $day;
    }
    
    // Konvertierung in das Substitution-Format
    $roomSubstitutions = [];
    
    foreach ($bookings as $room => $dates) {
        if (!is_array($dates)) continue;
        
        foreach ($dates as $dateString => $hours) {
            if (!is_array($hours)) continue;
            
            // Prüfen ob dieses Datum in der aktuellen Woche liegt
            if (!isset($dateToDay[$dateString])) continue;
            
            $day = $dateToDay[$dateString];
            
            foreach ($hours as $hour => $teacher) {
                if (empty($teacher)) continue;
                
                $roomSubstitutions[$teacher][(int)$day][(int)$hour] = [
                    'class' => 'raumwechsel',
                    'room' => $room
                ];
            }
        }
    }
    
    return $roomSubstitutions;
}

// Funktion, um zu prüfen, ob ein Fach ein Wahlfach ist
function isElectiveCourse($subject) {
    $electiveCourses = ['F', 'L', 'S', 'INF', 'SPM', 'SPJ', 'ER', 'KR', 'ET'];
    // Entferne Kursindex, falls vorhanden (z.B. "ER(1)" -> "ER")
    $baseSubject = preg_replace('/\(\d+\)$/', '', $subject);
    return in_array($baseSubject, $electiveCourses);
}

// Funktion, um den Kursindex zu extrahieren (z.B. "ER(1)" -> 1)
function getCourseIndex($subject) {
    if (preg_match('/\((\d+)\)$/', $subject, $matches)) {
        return (int)$matches[1];
    }
    return 1; // Standardindex, wenn keiner angegeben ist
}

// Funktion, um zu prüfen, ob der Schüler ein bestimmtes Wahlfach gewählt hat
function hasSelectedCourse($userCourses, $subject) {
    $baseSubject = preg_replace('/\(\d+\)$/', '', $subject);
    
    foreach ($userCourses as $userCourse) {
        $userBaseSubject = preg_replace('/\(\d+\)$/', '', $userCourse);
        
        if ($userBaseSubject === $baseSubject) {
            // Wenn kein Index im Benutzerkurs, nimm den ersten Kurs
            if ($userCourse === $userBaseSubject) {
                return $subject === $baseSubject || $subject === $baseSubject."(1)";
            } else {
                // Überprüfe, ob die Indizes übereinstimmen
                $userIndex = getCourseIndex($userCourse);
                $subjectIndex = getCourseIndex($subject);
                return $userBaseSubject === $baseSubject && $userIndex === $subjectIndex;
            }
        }
    }
    
    return false;
}

// Funktion, um die Wahlfächer zu gruppieren
function groupElectiveCourses($scheduleData, $klasse) {
    $electiveGroups = [];
    
    foreach ($scheduleData as $line) {
        $parts = str_getcsv($line);
        if (count($parts) < 8) continue;

        $id = trim($parts[0], '"');
        $klassePart = trim($parts[1], '"');
        $lehrer = trim($parts[2], '"');
        $fach = trim($parts[3], '"');
        $raum = trim($parts[4], '"');
        $tag = (int)$parts[5];
        $stunde = (int)$parts[6];
        
        if ($klassePart === $klasse && isElectiveCourse($fach)) {
            $key = "{$tag},{$stunde},{$fach}";
            $baseSubject = preg_replace('/\(\d+\)$/', '', $fach);
            
            if (!isset($electiveGroups[$tag][$stunde][$baseSubject])) {
                $electiveGroups[$tag][$stunde][$baseSubject] = [];
            }
            
            $electiveGroups[$tag][$stunde][$baseSubject][] = [
                'id' => $id,
                'lehrer' => $lehrer,
                'fach' => $fach,
                'raum' => $raum
            ];
        }
    }
    
    return $electiveGroups;
}

// Funktion, um den Stundenplan für eine Klasse zu generieren
function findClassScheduleItems($klasse, $scheduleData) {
    $classScheduleItems = [];
    
    foreach ($scheduleData as $line) {
        $parts = str_getcsv($line);
        if (count($parts) < 8) continue;

        $klassePart = trim($parts[1], '"');
        $lehrer = trim($parts[2], '"');
        $fach = trim($parts[3], '"');
        $raum = trim($parts[4], '"');
        $tag = (int)$parts[5];
        $stunde = (int)$parts[6];

        if ($klassePart === $klasse) {
            $classScheduleItems[] = [
                'lehrer' => $lehrer,
                'fach' => $fach,
                'raum' => $raum,
                'tag' => $tag,
                'stunde' => $stunde,
                'isElective' => isElectiveCourse($fach)
            ];
        }
    }
    
    return $classScheduleItems;
}

// Funktion, um den Stundenplan für einen Schüler zu generieren
function generateScheduleForStudent($klasse, $scheduleData, $weekType, $userCourses, $electiveGroups, $substitutions, $roomBookings) {
    $days = ['Mo', 'Di', 'Mi', 'Do', 'Fr'];
    $schedule = [];

    // Wahlfächer aus den Nutzerpräferenzen bereinigen
    $cleanUserCourses = [];
    foreach ($userCourses as $course) {
        $course = trim($course);
        if (!empty($course)) {
            $cleanUserCourses[] = $course;
        }
    }

    // Finde alle Unterrichtsstunden der Klasse
    $classScheduleItems = findClassScheduleItems($klasse, $scheduleData);
    
    // Lehrerkürzel zu Stundenplan-ID-Mapping erstellen
    $teacherScheduleMapping = [];
    foreach ($classScheduleItems as $item) {
        $key = $item['tag'] . ',' . $item['stunde'];
        $teacherScheduleMapping[$key] = $item['lehrer'];
    }

    // Zuerst Nicht-Wahlfächer hinzufügen
    foreach ($classScheduleItems as $item) {
        if (!$item['isElective']) {
            $tag = $item['tag'];
            $stunde = $item['stunde'];
            $day = $days[$tag - 1];
            
            $schedule[$day][$stunde] = [
                'Lehrer' => $item['lehrer'],
                'Fach' => $item['fach'],
                'Raum' => $item['raum'],
                'class' => 'x' // Standard-Klasse
            ];
        }
    }

    // Dann Wahlfächer prüfen und hinzufügen
    foreach ($electiveGroups as $tag => $hours) {
        foreach ($hours as $hour => $subjects) {
            $day = $days[$tag - 1];
            
            foreach ($subjects as $baseSubject => $courses) {
                // Prüfe, ob ein passendes Wahlfach in der Nutzerliste existiert
                $matchingCourse = null;
                $matchIndex = 0;
                
                foreach ($cleanUserCourses as $userCourse) {
                    $userBaseSubject = preg_replace('/\(\d+\)$/', '', $userCourse);
                    
                    // Wenn der Schüler dieses Fach gewählt hat
                    if ($userBaseSubject === $baseSubject) {
                        // Überprüfe den Index, falls vorhanden
                        if (preg_match('/\((\d+)\)$/', $userCourse, $matches)) {
                            $index = (int)$matches[1] - 1; // Array ist 0-basiert
                            if (isset($courses[$index])) {
                                $matchingCourse = $courses[$index];
                                break;
                            }
                        } else {
                            // Kein Index, nimm den ersten Kurs
                            $matchingCourse = $courses[0];
                            break;
                        }
                    }
                }
                
                // Wenn ein passendes Wahlfach gefunden wurde, füge es zum Stundenplan hinzu
                if ($matchingCourse) {
                    $schedule[$day][$hour] = [
                        'Lehrer' => $matchingCourse['lehrer'],
                        'Fach' => $matchingCourse['fach'],
                        'Raum' => $matchingCourse['raum'],
                        'class' => 'x' // Standard-Klasse
                    ];
                }
            }
        }
    }

    // Vertretungen und Raumbuchungen für die betroffenen Lehrer berücksichtigen
    foreach ($schedule as $day => $hours) {
        foreach ($hours as $hour => $details) {
            $lehrer = $details['Lehrer'];
            $dayIndex = array_search($day, $days) + 1;
            
            // Prüfe auf Vertretungen für diesen Lehrer in dieser Stunde
            if (isset($substitutions[$lehrer][$dayIndex][$hour])) {
                $subInfo = $substitutions[$lehrer][$dayIndex][$hour];
                
                // TD-Klasse aus Vertretungsinfo übernehmen
                $schedule[$day][$hour]['class'] = $subInfo['class'];
                
                // Felder entsprechend des Vertretungstyps aktualisieren
                if (isset($subInfo['subject'])) {
                    $schedule[$day][$hour]['Fach'] = $subInfo['subject'];
                }
                if (isset($subInfo['room'])) {
                    $schedule[$day][$hour]['Raum'] = $subInfo['room'];
                }
                if (isset($subInfo['teacher'])) {
                    // Bei Lehrervertretung den Lehrer aktualisieren
                    $schedule[$day][$hour]['Lehrer'] = $subInfo['teacher'];
                }
            }
            
            // Prüfe auf Raumbuchungen für diesen Lehrer in dieser Stunde
            if (isset($roomBookings[$lehrer][$dayIndex][$hour])) {
                $bookingInfo = $roomBookings[$lehrer][$dayIndex][$hour];
                
                // Nur anwenden, wenn keine Vertretung für diese Stunde gesetzt wurde
                if ($schedule[$day][$hour]['class'] === 'x') {
                    $schedule[$day][$hour]['class'] = $bookingInfo['class'];
                    
                    if (isset($bookingInfo['room'])) {
                        $schedule[$day][$hour]['Raum'] = $bookingInfo['room'];
                    }
                }
            }
        }
    }

    // Markiere falsche Woche
    foreach ($days as $day) {
        if (!isset($schedule[$day])) continue;
        
        foreach ($schedule[$day] as $hour => $details) {
            if (($weekType === 'A' && $hour == 9) || ($weekType === 'B' && $hour == 8)) {
                $otherHour = $weekType === 'A' ? $hour - 1 : $hour + 1;
                $otherHourDetails = $schedule[$day][$otherHour] ?? null;
                if ($otherHourDetails && $details['Fach'] === $otherHourDetails['Fach'] && $details['Raum'] === $otherHourDetails['Raum'] && $details['Lehrer'] === $otherHourDetails['Lehrer']) {
                    unset($schedule[$day][$hour]['class']);
                    unset($schedule[$day][$otherHour]['class']);
                    continue;
                }
                $schedule[$day][$hour]['wrongweek'] = true;
            }
        }
    }

    return $schedule;
}

// Hauptprogramm
try {
    // Stundenplan, Wocheninformation, Vertretungen und Raumbuchungen abrufen
    $scheduleData = fetchSchedule(STUNDENPLAN_URL);
    $weekType = fetchWeekType(WEEK_URL);
    $klassenmainData = fetchKlassenmain(KLASSENMAIN_URL);
    $substitutions = fetchSubstitutions(VERTRETUNG_URL);
    $roomBookings = fetchRoomBookings(RAUMBUCHUNG_URL);
    
    // Benutzerinformationen finden
    $userInfo = findUserInfo($studentUsername, $klassenmainData);
    
    if ($userInfo) {
        $klasse = $userInfo['klasse'];
        $userCourses = $userInfo['wahlFaecher'];
        $studentName = $userInfo['name'];
        
        // Wahlfächer gruppieren
        $electiveGroups = groupElectiveCourses($scheduleData, $klasse);
        
        // Stundenplan generieren mit Vertretungsinformationen
        $studentSchedule = generateScheduleForStudent($klasse, $scheduleData, $weekType, $userCourses, $electiveGroups, $substitutions, $roomBookings);
        
        // Klassenarbeiten für reguläre Klassen (5-10) abrufen
        $klassenarbeiten = fetchKlassenarbeiten(KLASSENARBEITEN_URL, $klasse);
    } else {
        die("Fehler: Benutzer nicht gefunden.");
    }
} catch (Exception $e) {
    die("Fehler: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ADA - SMG Adlersberg</title>
  <link rel="stylesheet" href="https://smg-adlersberg.de/timedex/styles.css">
  <link rel="stylesheet" href="https://smg-adlersberg.de/timedex/styles2.css">
  <link rel="stylesheet" href="https://smg-adlersberg.de/timedex/styles3.css">
  <link rel="stylesheet" href="https://smg-adlersberg.de/timedex/styles4.css">
</head>
<body>
 <div class="header">
    <a href="https://smg-adlersberg.de/timedex/mainpage/<?= urlencode($_SESSION['username']) ?>.php">
        <img src="https://smg-adlersberg.de/vertretungsplan/design/SMG-Logo2.png" alt="SMG Logo">
    </a>
    <h1>Sophie-Mereau-Gymnasium</h1>
    <h2>Onlineplan</h2>
</div>

<div class="icon-bar">
    <div>
        <a href="https://smg-adlersberg.de/timedex/lehrer.php" style="text-decoration: none; color: inherit;">
            <img src="https://smg-adlersberg.de/neuesdesign/Lehrer.png" alt="Lehrer">
            <p>Lehrer</p>
        </a>
    </div>
    <div>
        <a href="https://smg-adlersberg.de/timedex/klassen.php" style="text-decoration: none; color: inherit;">
            <img src="https://smg-adlersberg.de/neuesdesign/Klassen.png" alt="Klassen">
            <p>Klassen</p>
        </a>
    </div>
    <div>
        <a href="https://smg-adlersberg.de/timedex/faecher.php" style="text-decoration: none; color: inherit;">
            <img src="https://smg-adlersberg.de/neuesdesign/Fächer.png" alt="Fächer">
            <p>Fächer</p>
        </a>
    </div>
    <div>
        <a href="https://smg-adlersberg.de/timedex/raueme.php" style="text-decoration: none; color: inherit;">
            <img src="https://smg-adlersberg.de/neuesdesign/Räume.png" alt="Räume">
            <p>Räume</p>
        </a>
    </div>
    <div>
        <a href="https://smg-adlersberg.de/timedex/schueler.php" style="text-decoration: none; color: inherit;">
            <img src="https://smg-adlersberg.de/neuesdesign/Schüler.png" alt="Schüler">
            <p>Schüler</p>
        </a>
    </div>
</div>

<div class="profile-section">
    <img src="https://smg-adlersberg.de/neuesdesign/ProfilSchueler.png" alt="Profilbild">
    <h3>Klasse <?php echo $klasse; ?></h3>
    <h4 style="color: gray;"><?php echo $studentName; ?></h4>
</div>

<?php
if (isset($studentUsername) && isset($studentName)) {
    $userParam = urlencode($studentUsername);
    $nameParam = urlencode($studentName);
    $externalContent = @file_get_contents("https://smg-adlersberg.de/timedex/external/nachricht-alle.php?user={$userParam}&realname={$nameParam}");
    if ($externalContent !== false) {
        echo $externalContent;
    }
}
?>

<!-- Der Stundenplan -->
<div class="stundenplan">
    <div class="timetable-header">Stundenplan für <?php echo $studentName; ?> (<?php echo $klasse; ?>)</div>
    <table class="timetable">
        <thead>
        <tr>
            <th></th>
            <th class="day-label">Mo</th>
            <th class="day-label">Di</th>
            <th class="day-label">Mi</th>
            <th class="day-label">Do</th>
            <th class="day-label">Fr</th>
        </tr>
        </thead>
        <tbody>
        <?php for ($hour = 1; $hour <= 9; $hour++): ?>
            <tr>
                <td class="time-label"><?php echo $hour; ?></td>
                <?php foreach (['Mo', 'Di', 'Mi', 'Do', 'Fr'] as $day): ?>
                    <?php 
                    $cellClass = "x"; // Standard-Klasse bleibt "x"
                    if (isset($studentSchedule[$day][$hour])) {
                        $cellClass = $studentSchedule[$day][$hour]['class'] ?? 'x';
                    }
                    ?>
                    <td class="<?php echo $cellClass; ?>">
                        <?php if (isset($studentSchedule[$day][$hour])): ?>
                            <?php $wrongweekClass = isset($studentSchedule[$day][$hour]['wrongweek']) && $studentSchedule[$day][$hour]['wrongweek'] ? ' wrongweek' : ''; ?>
                            <div class="subject<?php echo $wrongweekClass; ?>">
                                <?php echo htmlspecialchars($studentSchedule[$day][$hour]['Fach']); ?>
                            </div>
                            <div class="room<?php echo $wrongweekClass; ?>">
                                <?php echo htmlspecialchars($studentSchedule[$day][$hour]['Raum']); ?>
                            </div>
                            <div class="teacher<?php echo $wrongweekClass; ?>">
                                <?php echo htmlspecialchars($studentSchedule[$day][$hour]['Lehrer']); ?>
                            </div>
                        <?php else: ?>
                            <div class="subject">&#160;</div>
                            <div class="room">&#160;</div>
                            <div class="teacher">&#160;</div>
                        <?php endif; ?>
                    </td>
                <?php endforeach; ?>
            </tr>
        <?php endfor; ?>
        </tbody>
    </table>
</div>

<!-- Klassenarbeiten für reguläre Klassen (5-10) anzeigen -->
<div class="stundenplan">
    <div class="exam-schedule">
        <div class="timetable-header">Klassenarbeiten für <?php echo htmlspecialchars($studentName); ?> (<?php echo $klasse; ?>)</div><br>
        
        <?php if (!empty($klassenarbeiten)): ?>
            <?php foreach ($klassenarbeiten as $arbeit): ?>
                <div class="exam-entry">
                    <div class="exam-date-time">
                        <span class="exam-date"><?php echo $arbeit['date_string']; ?></span>
                    </div>
                    <div class="exam-details">
                        <p class="exam-class"><?php echo htmlspecialchars($arbeit['subject']); ?></p>
                        <p class="exam-supervision"><?php echo htmlspecialchars($arbeit['room']); ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="no-exams" style="text-align: center;">
                <p>Keine anstehenden Klassenarbeiten gefunden.</p>
            </div>
        <?php endif; ?>
        
        <!-- PDF-Download-Button für Klassenarbeiten -->
        <p style="margin-bottom: 20px;"></p>
        <div class="exam-download" style="display: flex; justify-content: center; margin-top: 20px;">  
            <a href="https://smg-adlersberg.de/service/informationen/SMG-Kursarbeitskalender.pdf">
                <button class="exam-download-button"> 
                    <img src="https://smg-adlersberg.de/timedex/PDF_file_icon.svg (2).png" alt="PDF Icon" class="exam-pdf-icon" /> 
                    Klassenarbeiten als PDF
                </button>
            </a>
        </div>
    </div>
</div>

</body>
</html>