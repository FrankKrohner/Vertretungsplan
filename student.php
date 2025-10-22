<?php
session_start();

// AJAX Handler für Auto-Update (direkt nach session_start() einfügen)
if (isset($_POST['ajax_update']) && $_POST['ajax_update'] == '1') {
    header('Content-Type: application/json');
    
    try {
        // Gleiche Sicherheitsprüfungen wie im Hauptcode
        if (!isset($_SESSION['username'])) {
            throw new Exception('Nicht eingeloggt');
        }
        
        if (isset($_SESSION['user_agent']) && $_SESSION['user_agent'] !== $_SERVER['HTTP_USER_AGENT']) {
            throw new Exception('Sicherheitsprüfung fehlgeschlagen');
        }
        
       // ÄNDERUNG: Schülername aus URL-Parameter statt aus Session
$studentUsername = isset($_GET['student']) ? $_GET['student'] : '';

// Validierung des URL-Parameters
if (empty($studentUsername)) {
    die("Fehler: Kein Schüler in der URL angegeben. Bitte verwenden Sie: ?student=schuelername");
}

// Zusätzliche Sicherheitsvalidierung für den Schülernamen
if (!preg_match('/^[a-z0-9]+$/', $studentUsername)) {
    die("Fehler: Ungültiger Schülername. Nur Kleinbuchstaben und Zahlen erlaubt.");
}
        
        // Daten neu laden (gleiche URLs wie im Hauptcode)
        $scheduleData = fetchSchedule(STUNDENPLAN_URL);
        $weekType = fetchWeekType(WEEK_URL);
        $klassenmainData = fetchKlassenmain(KLASSENMAIN_URL);
        $substitutions = fetchSubstitutions(VERTRETUNG_URL);
        $roomBookings = fetchRoomBookings(RAUMBUCHUNG_URL);
        $oberstufenData = fetchOberstufenKurse(OBERSTUFE_KURSE_URL);
        
        // Benutzerinformationen neu ermitteln
        $userInfo = findUserInfo($studentUsername, $klassenmainData, $oberstufenData);
        
        if (!$userInfo) {
            throw new Exception('Benutzer nicht gefunden');
        }
        
        $klasse = $userInfo['klasse'];
        
        // Stundenplan neu generieren
        if ($userInfo['isOberstufe']) {
            $studentSchedule = generateOberstufenSchedule($klasse, $scheduleData, $weekType, $userInfo['oberstufenKurse'], $substitutions, $roomBookings);
        } else {
            $userCourses = $userInfo['wahlFaecher'];
            $electiveGroups = groupElectiveCourses($scheduleData, $klasse);
            $studentSchedule = generateScheduleForStudent($klasse, $scheduleData, $weekType, $userCourses, $electiveGroups, $substitutions, $roomBookings);
        }
        
        // JSON-Response senden
        echo json_encode([
            'success' => true,
            'schedule' => $studentSchedule,
            'timestamp' => date('H:i:s')
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    
    exit(); // Wichtig: Verhindert weitere HTML-Ausgabe
}

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

// Benutzernamen normalisieren und prüfen
$normalizedUsername = strtoupper(trim($_SESSION['username']));
$isAllowed = false;

foreach ($allowedUsers as $user) {
    if (strtoupper(trim($user)) === $normalizedUsername) {
        $isAllowed = true;
        break;
    }
}

// Umleitung, wenn der Benutzer nicht berechtigt ist
if (!$isAllowed) {
    header('Location: https://smg-adlersberg.de/timedex/unauthorized.php');
    exit();
}

// Benutzername aus URL-Parameter statt Session
$studentUsername = isset($_GET['student']) ? $_GET['student'] : '';
if (empty($studentUsername)) {
    throw new Exception('Kein Schüler angegeben');
}

// URLs der benötigten Dateien
define('STUNDENPLAN_URL', '../stammdaten/GPU001.TXT');
define('WEEK_URL', 'https://smg-adlersberg.de/timedex/stdplweek.php');
define('KLASSENMAIN_URL', 'https://smg-adlersberg.de/koordination/KLASSENMAIN.php');
define('VERTRETUNG_URL', 'https://smg-adlersberg.de/koordination/vertretungenklassen.php');
define('RAUMBUCHUNG_URL', 'https://smg-adlersberg.de/timedex/bookings_regular.json');
define('OBERSTUFE_KURSE_URL', 'https://smg-adlersberg.de/timedex/stammdaten/GPU015.txt');

// Funktion, um die Klassenmain-Datei zu holen
function fetchKlassenmain($url) {
    $data = file_get_contents($url);
    if ($data === false) {
        die("Fehler: Klassenmain-Datei konnte nicht abgerufen werden.");
    }
    return explode("\n", $data);
}

// Funktion, um die Oberstufen-Kurse zu holen
function fetchOberstufenKurse($url) {
    $data = file_get_contents($url);
    if ($data === false) {
        die("Fehler: Oberstufen-Kurse-Datei konnte nicht abgerufen werden.");
    }
    return explode("\n", $data);
}

// Funktion, um zu prüfen, ob es sich um eine Oberstufenklasse handelt
function isOberstufenKlasse($klasse) {
    return in_array($klasse, ['11', '12', '13']);
}

// Funktion, um Oberstufen-Kurse für einen Schüler zu ermitteln
function getOberstufenKurseForStudent($username, $oberstufenData) {
    $studentCourses = [];
    
    foreach ($oberstufenData as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        // Parsen der CSV-Zeile
        $parts = str_getcsv($line);
        if (count($parts) < 3) continue;
        
        $student = trim($parts[0], '"');
        $courseId = trim($parts[1], '"');
        $courseName = trim($parts[2], '"');
        
        if ($student === $username) {
            $studentCourses[] = $courseName;
        }
    }
    
    return $studentCourses;
}

// Funktion, um Fächernamen zu bereinigen (Zahlen entfernen)
function cleanSubjectName($subject) {
    // Entferne alle Zahlen aus dem Fächernamen
    return preg_replace('/[0-9]/', '', $subject);
}

// Funktion, um Benutzerinformationen zu finden (erweitert für Oberstufe)
function findUserInfo($username, $klassenmainData, $oberstufenData = null) {
    // Prüfe zuerst, ob es ein Oberstufenschüler ist
    if ($oberstufenData) {
        foreach ($oberstufenData as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            $parts = str_getcsv($line);
            if (count($parts) < 5) continue;
            
            $student = trim($parts[0], '"');
            $klasse = trim($parts[4], '"');
            
            if ($student === $username && isOberstufenKlasse($klasse)) {
                // Hole alle Kurse für diesen Schüler
                $courses = getOberstufenKurseForStudent($username, $oberstufenData);
                
                // Extrahiere Namen aus Klassenmain (falls vorhanden)
                $name = $username; // Fallback
                foreach ($klassenmainData as $klassenmainLine) {
                    $klassenmainLine = trim($klassenmainLine);
                    if (empty($klassenmainLine)) continue;
                    
                    $klassenmainParts = explode(',', $klassenmainLine);
                    if (count($klassenmainParts) < 4) continue;
                    
                    $nachname = $klassenmainParts[0];
                    $vorname = $klassenmainParts[1];
                    $klassenmainKlasse = "";
                    
                    if (preg_match('/(\d+[a-z]*)+$/', $username, $matches)) {
                        $klassenmainKlasse = strtoupper($matches[1]);
                    }
                    
                    $generatedUsername = preg_replace('/[aeiouäöüAEIOUÄÖÜ]/', '', strtolower($vorname . $nachname)) . strtolower($klassenmainKlasse);
                    
                    if ($generatedUsername === $username) {
                        $name = $vorname . ' ' . $nachname;
                        break;
                    }
                }
                
                return [
                    'name' => $name,
                    'klasse' => $klasse,
                    'isOberstufe' => true,
                    'oberstufenKurse' => $courses
                ];
            }
        }
    }
    
    // Fallback: Normale Schüler (Klassen 5-10)
    foreach ($klassenmainData as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        $parts = explode(',', $line);
        if (count($parts) < 4) continue;
        
        $nachname = $parts[0];
        $vorname = $parts[1];
        $geschlecht = $parts[2];
        $geburtsdatum = $parts[3];
        
        $klasse = "";
        if (preg_match('/(\d+[a-z]+)$/', $username, $matches)) {
            $klasse = strtoupper($matches[1]);
        }
        
        $generatedUsername = preg_replace('/[aeiouäöüAEIOUÄÖÜ]/', '', strtolower($vorname . $nachname)) . strtolower($klasse);
        
        if ($generatedUsername === $username) {
            return [
                'name' => $vorname . ' ' . $nachname,
                'klasse' => $klasse,
                'isOberstufe' => false,
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
        
        if (preg_match('/^([A-ZÄÖÜßäöü]+),(\d+),(\d+)=(.+)$/', $line, $matches)) {
            $lehrer = $matches[1];
            $tag = (int)$matches[2];
            $stunde = (int)$matches[3];
            $info = $matches[4];
            
            $parts = explode(',', $info);
            $subInfo = [
                'class' => $parts[0]
            ];
            
            if (count($parts) > 1) {
                if ($parts[0] === 'vertretung') {
                    if (isset($parts[1])) $subInfo['subject'] = $parts[1];
                    if (isset($parts[2])) $subInfo['room'] = $parts[2];
                    if (isset($parts[3])) $subInfo['teacher'] = $parts[3];
                } elseif ($parts[0] === 'raumwechsel') {
                    if (isset($parts[1])) $subInfo['room'] = $parts[1];
                }
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

// Funktion, um den Stundenplan für Oberstufenschüler zu generieren
function generateOberstufenSchedule($klasse, $scheduleData, $weekType, $studentCourses, $substitutions, $roomBookings) {
    $days = ['Mo', 'Di', 'Mi', 'Do', 'Fr'];
    $schedule = [];
    
    // Finde alle Unterrichtsstunden für die Oberstufenklasse
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

        // Prüfen ob es die richtige Klasse ist und der Schüler diesen Kurs besucht
        if ($klassePart === $klasse && in_array($fach, $studentCourses)) {
            $day = $days[$tag - 1];
            
            $schedule[$day][$stunde] = [
                'Lehrer' => $lehrer,
                'Fach' => cleanSubjectName($fach), // Zahlen aus Fächernamen entfernen
                'OriginalFach' => $fach, // Original für Vertretungsabgleich behalten
                'Raum' => $raum,
                'class' => 'x'
            ];
        }
    }

    // Vertretungen und Raumbuchungen anwenden
    foreach ($schedule as $day => $hours) {
        foreach ($hours as $hour => $details) {
            $lehrer = $details['Lehrer'];
            $dayIndex = array_search($day, $days) + 1;
            
            // Prüfe auf Vertretungen
            if (isset($substitutions[$lehrer][$dayIndex][$hour])) {
                $subInfo = $substitutions[$lehrer][$dayIndex][$hour];
                
                $schedule[$day][$hour]['class'] = $subInfo['class'];
                
                if (isset($subInfo['subject'])) {
                    $schedule[$day][$hour]['Fach'] = cleanSubjectName($subInfo['subject']);
                }
                if (isset($subInfo['room'])) {
                    $schedule[$day][$hour]['Raum'] = $subInfo['room'];
                }
                if (isset($subInfo['teacher'])) {
                    $schedule[$day][$hour]['Lehrer'] = $subInfo['teacher'];
                }
            }
            
            // Prüfe auf Raumbuchungen
            if (isset($roomBookings[$lehrer][$dayIndex][$hour])) {
                $bookingInfo = $roomBookings[$lehrer][$dayIndex][$hour];
                
                if ($schedule[$day][$hour]['class'] === 'x') {
                    $schedule[$day][$hour]['class'] = $bookingInfo['class'];
                    
                    if (isset($bookingInfo['room'])) {
                        $schedule[$day][$hour]['Raum'] = $bookingInfo['room'];
                    }
                }
            }
        }
    }

foreach ($days as $day) {
    if (!isset($schedule[$day])) continue;
    
    foreach ($schedule[$day] as $hour => $details) {
        if (($weekType === 'A' && $hour == 9) || ($weekType === 'B' && $hour == 8)) {
            $otherHour = $weekType === 'A' ? $hour - 1 : $hour + 1;
            $otherHourDetails = $schedule[$day][$otherHour] ?? null;
            if ($otherHourDetails && 
                $details['OriginalFach'] === $otherHourDetails['OriginalFach'] && 
                $details['Raum'] === $otherHourDetails['Raum'] && 
                $details['Lehrer'] === $otherHourDetails['Lehrer']) {
                
                // Nur 'class' entfernen, wenn es der Standard-Wert 'x' ist
                // Vertretungsinformationen (entfall, raumwechsel, etc.) beibehalten
                if (isset($schedule[$day][$hour]['class']) && $schedule[$day][$hour]['class'] === 'x') {
                    unset($schedule[$day][$hour]['class']);
                }
                if (isset($schedule[$day][$otherHour]['class']) && $schedule[$day][$otherHour]['class'] === 'x') {
                    unset($schedule[$day][$otherHour]['class']);
                }
                continue;
            }
            $schedule[$day][$hour]['wrongweek'] = true;
        }
    }
}

    return $schedule;
}

// Funktionen für reguläre Schüler (5-10)
function isElectiveCourse($subject) {
    $electiveCourses = ['F', 'L', 'S', 'INF', 'SPM', 'SPJ', 'ER', 'KR', 'ET'];
    $baseSubject = preg_replace('/\(\d+\)$/', '', $subject);
    return in_array($baseSubject, $electiveCourses);
}

function getCourseIndex($subject) {
    if (preg_match('/\((\d+)\)$/', $subject, $matches)) {
        return (int)$matches[1];
    }
    return 1;
}

function hasSelectedCourse($userCourses, $subject) {
    $baseSubject = preg_replace('/\(\d+\)$/', '', $subject);
    
    foreach ($userCourses as $userCourse) {
        $userBaseSubject = preg_replace('/\(\d+\)$/', '', $userCourse);
        
        if ($userBaseSubject === $baseSubject) {
            if ($userCourse === $userBaseSubject) {
                return $subject === $baseSubject || $subject === $baseSubject."(1)";
            } else {
                $userIndex = getCourseIndex($userCourse);
                $subjectIndex = getCourseIndex($subject);
                return $userBaseSubject === $baseSubject && $userIndex === $subjectIndex;
            }
        }
    }
    
    return false;
}

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

function generateScheduleForStudent($klasse, $scheduleData, $weekType, $userCourses, $electiveGroups, $substitutions, $roomBookings) {
    $days = ['Mo', 'Di', 'Mi', 'Do', 'Fr'];
    $schedule = [];

    $cleanUserCourses = [];
    foreach ($userCourses as $course) {
        $course = trim($course);
        if (!empty($course)) {
            $cleanUserCourses[] = $course;
        }
    }

    $classScheduleItems = findClassScheduleItems($klasse, $scheduleData);
    
    $teacherScheduleMapping = [];
    foreach ($classScheduleItems as $item) {
        $key = $item['tag'] . ',' . $item['stunde'];
        $teacherScheduleMapping[$key] = $item['lehrer'];
    }

    foreach ($classScheduleItems as $item) {
        if (!$item['isElective']) {
            $tag = $item['tag'];
            $stunde = $item['stunde'];
            $day = $days[$tag - 1];
            
            $schedule[$day][$stunde] = [
                'Lehrer' => $item['lehrer'],
                'Fach' => $item['fach'],
                'Raum' => $item['raum'],
                'class' => 'x'
            ];
        }
    }

    foreach ($electiveGroups as $tag => $hours) {
        foreach ($hours as $hour => $subjects) {
            $day = $days[$tag - 1];
            
            foreach ($subjects as $baseSubject => $courses) {
                $matchingCourse = null;
                $matchIndex = 0;
                
                foreach ($cleanUserCourses as $userCourse) {
                    $userBaseSubject = preg_replace('/\(\d+\)$/', '', $userCourse);
                    
                    if ($userBaseSubject === $baseSubject) {
                        if (preg_match('/\((\d+)\)$/', $userCourse, $matches)) {
                            $index = (int)$matches[1] - 1;
                            if (isset($courses[$index])) {
                                $matchingCourse = $courses[$index];
                                break;
                            }
                        } else {
                            $matchingCourse = $courses[0];
                            break;
                        }
                    }
                }
                
                if ($matchingCourse) {
                    $schedule[$day][$hour] = [
                        'Lehrer' => $matchingCourse['lehrer'],
                        'Fach' => $matchingCourse['fach'],
                        'Raum' => $matchingCourse['raum'],
                        'class' => 'x'
                    ];
                }
            }
        }
    }

    foreach ($schedule as $day => $hours) {
        foreach ($hours as $hour => $details) {
            $lehrer = $details['Lehrer'];
            $dayIndex = array_search($day, $days) + 1;
            
            if (isset($substitutions[$lehrer][$dayIndex][$hour])) {
                $subInfo = $substitutions[$lehrer][$dayIndex][$hour];
                
                $schedule[$day][$hour]['class'] = $subInfo['class'];
                
                if (isset($subInfo['subject'])) {
                    $schedule[$day][$hour]['Fach'] = $subInfo['subject'];
                }
                if (isset($subInfo['room'])) {
                    $schedule[$day][$hour]['Raum'] = $subInfo['room'];
                }
                if (isset($subInfo['teacher'])) {
                    $schedule[$day][$hour]['Lehrer'] = $subInfo['teacher'];
                }
            }
            
            if (isset($roomBookings[$lehrer][$dayIndex][$hour])) {
                $bookingInfo = $roomBookings[$lehrer][$dayIndex][$hour];
                
                if ($schedule[$day][$hour]['class'] === 'x') {
                    $schedule[$day][$hour]['class'] = $bookingInfo['class'];
                    
                    if (isset($bookingInfo['room'])) {
                        $schedule[$day][$hour]['Raum'] = $bookingInfo['room'];
                    }
                }
            }
        }
    }

  foreach ($days as $day) {
    if (!isset($schedule[$day])) continue;
    
    foreach ($schedule[$day] as $hour => $details) {
        if (($weekType === 'A' && $hour == 9) || ($weekType === 'B' && $hour == 8)) {
            $otherHour = $weekType === 'A' ? $hour - 1 : $hour + 1;
            $otherHourDetails = $schedule[$day][$otherHour] ?? null;
            if ($otherHourDetails && $details['Fach'] === $otherHourDetails['Fach'] && $details['Raum'] === $otherHourDetails['Raum'] && $details['Lehrer'] === $otherHourDetails['Lehrer']) {
                
                // Nur 'class' entfernen, wenn es der Standard-Wert 'x' ist
                // Vertretungsinformationen (entfall, raumwechsel, etc.) beibehalten
                if (isset($schedule[$day][$hour]['class']) && $schedule[$day][$hour]['class'] === 'x') {
                    unset($schedule[$day][$hour]['class']);
                }
                if (isset($schedule[$day][$otherHour]['class']) && $schedule[$day][$otherHour]['class'] === 'x') {
                    unset($schedule[$day][$otherHour]['class']);
                }
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
    // Daten abrufen
    $scheduleData = fetchSchedule(STUNDENPLAN_URL);
    $weekType = fetchWeekType(WEEK_URL);
    $klassenmainData = fetchKlassenmain(KLASSENMAIN_URL);
    $substitutions = fetchSubstitutions(VERTRETUNG_URL);
    $roomBookings = fetchRoomBookings(RAUMBUCHUNG_URL);
    $oberstufenData = fetchOberstufenKurse(OBERSTUFE_KURSE_URL);
    
    // Benutzerinformationen finden
    $userInfo = findUserInfo($studentUsername, $klassenmainData, $oberstufenData);
    
    if ($userInfo) {
        $klasse = $userInfo['klasse'];
        $studentName = $userInfo['name'];
        
        if ($userInfo['isOberstufe']) {
            // Oberstufenschüler
            $studentSchedule = generateOberstufenSchedule($klasse, $scheduleData, $weekType, $userInfo['oberstufenKurse'], $substitutions, $roomBookings);
        } else {
            // Reguläre Schüler (5-10)
            $userCourses = $userInfo['wahlFaecher'];
            $electiveGroups = groupElectiveCourses($scheduleData, $klasse);
            $studentSchedule = generateScheduleForStudent($klasse, $scheduleData, $weekType, $userCourses, $electiveGroups, $substitutions, $roomBookings);
        }
    } else {
        die("Fehler: Benutzer nicht gefunden.");
    }
} catch (Exception $e) {
    die("Fehler: " . $e->getMessage());
}

// Klausuren-Daten laden (für Oberstufenschüler)
$klausuren = [];
if ($userInfo && $userInfo['isOberstufe']) {
    // URLs definieren
    $klausurenUrl = 'https://smg-adlersberg.de/koordination/klausuren.php';
    $fachzuordnungenUrl = 'https://smg-adlersberg.de/timedex/fachzuordnungen.php';
    
    // Funktion zum Abrufen der Fachzuordnungen
    function fetchFachzuordnungen($url) {
        $data = @file_get_contents($url);
        if ($data === false) {
            return [];
        }
        
        $lines = explode("\n", trim($data));
        $zuordnungen = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            if (preg_match('/^(.+?)\s*=\s*(.+)$/', $line, $matches)) {
                $kurs = trim($matches[1]);
                $fachname = trim($matches[2]);
                $zuordnungen[$kurs] = $fachname;
            }
        }
        
        return $zuordnungen;
    }
    
    // Funktion zum Abrufen und Filtern der Klausuren
    function getKlausurenForStudent($klausurenUrl, $fachzuordnungen, $studentKlasse, $studentKurse) {
        $data = @file_get_contents($klausurenUrl);
        if ($data === false) {
            return [];
        }
        
        $lines = explode("\n", trim($data));
        $relevantKlausuren = [];
        
        $today = new DateTime('today');
        
        $germanMonths = [
            'Januar' => '01', 'Februar' => '02', 'März' => '03', 'April' => '04',
            'Mai' => '05', 'Juni' => '06', 'Juli' => '07', 'August' => '08',
            'September' => '09', 'Oktober' => '10', 'November' => '11', 'Dezember' => '12'
        ];
        
        $germanWeekdays = [
            'Monday' => 'Montag',
            'Tuesday' => 'Dienstag', 
            'Wednesday' => 'Mittwoch',
            'Thursday' => 'Donnerstag',
            'Friday' => 'Freitag',
            'Saturday' => 'Samstag',
            'Sunday' => 'Sonntag'
        ];
        
        foreach ($lines as $line) {
            $parts = array_map('trim', explode(',', $line));
            if (count($parts) < 6) continue;
            
            $lehrer = $parts[0];
            $datum = $parts[1];
            $fachInfo = $parts[2];
            $raum = $parts[3];
            $raumKurz = $parts[4];
            $stunden = $parts[5];
            
           // Extrahiere Start- und Endstunde für die Anzeige
$stundenRange = '';
// Finde alle Zahlen im Stunden-String
if (preg_match_all('/\d+/', $stunden, $stundenMatches)) {
    $stundenNumbers = $stundenMatches[0];
    if (count($stundenNumbers) == 1) {
        // Einzelstunde: "3"
        $stundenRange = $stundenNumbers[0];
    } elseif (count($stundenNumbers) == 2) {
        // Doppelstunde: "3-4"
        $stundenRange = $stundenNumbers[0] . '-' . $stundenNumbers[1];
    } else {
        // Mehrere Stunden: nimm erste und letzte
        $stundenRange = $stundenNumbers[0] . '-' . end($stundenNumbers);
    }
}
            
            $isExpl = str_starts_with($lehrer, 'EXPL!');
            if ($isExpl) {
                $lehrer = substr($lehrer, 5);
            }
            
            // GEÄNDERT: Unterstütze sowohl "13 Fachname" als auch "13 Abitur Fachname"
if (preg_match('/^(\d{2})\s+(?:Abitur\s+)?(.+)$/', $fachInfo, $matches)) {
    $klausurenKlasse = $matches[1];
    $fachname = $matches[2];
    
    if ($klausurenKlasse !== $studentKlasse) {
        continue;
    }
                
                $foundKurs = null;
                foreach ($fachzuordnungen as $kurs => $zugeordneterName) {
                    if ($zugeordneterName === $fachname) {
                        $foundKurs = $kurs;
                        break;
                    }
                }
                
                if (!$foundKurs || !in_array($foundKurs, $studentKurse)) {
                    continue;
                }
                
                if (preg_match('/(\d{1,2})\. ([^\d]+)/u', $datum, $dateMatches)) {
                    $day = $dateMatches[1];
                    $monthName = $dateMatches[2];
                    
                    if (isset($germanMonths[$monthName])) {
                        $year = $today->format('Y');
                        
                        if ($isExpl) {
                            $year++;
                        }
                        
                        $dateString = sprintf('%02d-%02d-%d', $day, $germanMonths[$monthName], $year);
                        $date = DateTime::createFromFormat('d-m-Y', $dateString);
                        
                        if ($isExpl || $date >= $today) {
                            $englishWeekday = $date->format('l');
                            $germanWeekday = $germanWeekdays[$englishWeekday] ?? $englishWeekday;
                            
                            $formattedDate = $datum . ' <small style="font-size: 70%; font-weight: normal;">(' . $germanWeekday . ')</small>';
                            
                            // GEÄNDERT: Prüfe ob es sich um eine Abiturklausur handelt
$isAbitur = (strpos($fachInfo, 'Abitur') !== false);

$relevantKlausuren[] = [
    'date' => $date,
    'date_string' => $formattedDate,
    'fach' => $fachname,
    'lehrer' => $lehrer,
    'raum' => $raum,
    'stunden' => $stunden,
    'stundenRange' => $stundenRange,
    'isExpl' => $isExpl,
    'isAbitur' => $isAbitur  // NEU: Markierung für Abiturklausuren
];
                        }
                    }
                }
            }
        }
        
        usort($relevantKlausuren, function ($a, $b) {
            return $a['date'] <=> $b['date'];
        });
        
        return $relevantKlausuren;
    }
    
    // Fachzuordnungen laden
    $fachzuordnungen = fetchFachzuordnungen($fachzuordnungenUrl);
    
    // Klausuren für den Schüler ermitteln
    $klausuren = getKlausurenForStudent($klausurenUrl, $fachzuordnungen, $klasse, $userInfo['oberstufenKurse']);
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SMG Startseite</title>
  <link rel="icon" type="image/x-icon" href="https://smg-adlersberg.de/neuesdesign/SMG-cropped-for-ico2_1.ico">
  <link rel="stylesheet" href="styles.css">
  <link rel="stylesheet" href="styles2.css">
  <link rel="stylesheet" href="styles3.css">
  <link rel="stylesheet" href="styles4.css">
  <link rel="stylesheet" href="styles5.css">
  <link rel="stylesheet" href="https://smg-adlersberg.de/neuesdesign/klassenbuchstudent.css">
  <link rel="stylesheet" href="https://smg-adlersberg.de/neuesdesign/klassenbuch.css">
  <style>
  
  
  /* Zusätzliche Stile für die klickbaren Menüpunkte */
    .menu-item a {
      display: flex;
      align-items: center;
      text-decoration: none;
      color: inherit;
      width: 100%;
      justify-content: space-between;
    }
    
    .menu-item a .item-content {
      display: flex;
      align-items: center;
    }
    
    .menu-item a .item-content img {
      margin-right: 5px;
    }
  
  
  .today-schedule {
    margin: 20px 0;
    padding: 0 20px;
}

.today-title {
    font-size: 32px;
    font-weight: bold;
    color: #1e293b;
    margin-bottom: 20px;
}

.today-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 16px;
    max-width: 100%;
}

/* Desktop: 4 Spalten */
@media (min-width: 1200px) {
    .today-cards {
        grid-template-columns: repeat(4, 1fr);
        max-width: 1200px;
    }
}

/* Tablet: 3 Spalten */
@media (min-width: 768px) and (max-width: 1199px) {
    .today-cards {
        grid-template-columns: repeat(3, 1fr);
        max-width: 900px;
    }
}

/* Mobile: 2 Spalten */
@media (max-width: 767px) {
    .today-cards {
        grid-template-columns: repeat(2, 1fr);
        max-width: 600px;
    }
}

.today-card {
    border-radius: 16px;
    padding: 20px;
    color: white;
    min-height: 120px;
    display: flex;
    flex-direction: column;
    cursor: pointer;
    transition: transform 0.2s ease;
}

.today-card:hover {
    transform: translateY(-2px);
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 16px;
}

.card-icons {
    display: flex;
    gap: 12px;
}

.card-icon {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 8px;
    cursor: pointer;
    transition: background 0.2s ease;
}

.card-icon:hover {
    background: rgba(255, 255, 255, 0.3);
}

.card-menu {
    opacity: 0.7;
    cursor: pointer;
}

.card-menu:hover {
    opacity: 1;
}

.card-labels {
    display: flex;
    gap: 12px;
    margin-bottom: 8px;
}

.card-label {
    font-size: 14px;
    font-weight: 500;
    opacity: 0.9;
}

.card-title {
    font-size: 18px;
    font-weight: 600;
    margin-top: auto;
    line-height: 1.2;
}

.empty-card .card-title {
    opacity: 0.7;
    font-style: italic;
}

@media (max-width: 768px) {
    .today-cards {
        grid-template-columns: 1fr;
    }
    
    .today-title {
        font-size: 28px;
    }


  </style>
</head>
<body>
 <div class="header">
    <a href="https://smg-adlersberg.de/timedex/login.php">
        <img src="https://smg-adlersberg.de/vertretungsplan/design/SMG-Logo2.png" alt="SMG Logo">
    </a>
    <h1>Sophie-Mereau-Gymnasium</h1>
    <h2>Onlineplan</h2>
</div>


<div class="profile-section">
    <img src="https://smg-adlersberg.de/neuesdesign/ProfilSchueler.png" alt="Profilbild">
    <h3><?php echo htmlspecialchars($studentName); ?></h3>
    <h4 style="color: gray;">
        <?php if ($userInfo['isOberstufe']): ?>
            MSS <?php echo htmlspecialchars($klasse); ?>
        <?php else: ?>
            Klasse <?php echo htmlspecialchars($klasse); ?>
        <?php endif; ?>
    </h4>
    <?php
    $externalContent = @file_get_contents('https://smg-adlersberg.de/timedex/external/aktualisiert.php');
    if ($externalContent !== false) {
        echo $externalContent;
    }
    ?>
</div>

  <div class="menu">
    <div class="menu-item">
      <a href="https://smg-adlersberg.de/timedex/message">
        <div class="item-content">
          <img src="https://smg-adlersberg.de/neuesdesign/Stundenplan.png" alt="Stundenplan"> Nachrichten
        </div>
        <span class="dots">...</span>
      </a>
    </div>
    <div class="menu-item">
      <a href="https://smg-adlersberg.de/timedex/vertretungen">
        <div class="item-content">
          <img src="https://smg-adlersberg.de/neuesdesign/Vertretungsp.png" alt="Fehlstunden"> Vertretungsplan
        </div>
        <span class="dots">...</span>
      </a>
    </div>
    <div class="menu-item">
      <a href="https://smg-adlersberg.de/timedex/krankmelden">
        <div class="item-content">
          <img src="https://smg-adlersberg.de/neuesdesign/Fehlstunden.png" alt="Kursarbeiten"> Krankmelden
        </div>
        <span class="dots">...</span>
      </a>
    </div>
    <div class="menu-item">
      <a href="https://smg-adlersberg.de/timedex/klassenbuch/schueler">
        <div class="item-content">
          <img src="https://smg-adlersberg.de/neuesdesign/Klassenbuch.png" alt="Klassenbuch"> Klassenbuch
        </div>
        <span class="dots">...</span>
      </a>
    </div>
    <div class="menu-item">
      <a href="https://smg-adlersberg.de/timedex/nutzernamen">
        <div class="item-content">
          <img src="https://smg-adlersberg.de/neuesdesign/Kennwort.png" alt="Homepage"> Benutzernamen / Kennwörter
        </div>
        <span class="dots">...</span>
      </a>
    </div>
  </div>

<!-- Heute anstehend - Nächste Stunden -->
<?php
// Funktion zum Abrufen der Fachzuordnungen
function fetchFachzuordnungenForToday($url) {
    $data = @file_get_contents($url);
    if ($data === false) {
        return [];
    }
    
    $lines = explode("\n", trim($data));
    $zuordnungen = [];
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        // Format: "2d1 = Deutsch G1"
        if (preg_match('/^(.+?)\s*=\s*(.+)$/', $line, $matches)) {
            $kurs = trim($matches[1]);
            $fachname = trim($matches[2]);
            $zuordnungen[$kurs] = $fachname;
        }
    }
    
    return $zuordnungen;
}

// Funktion um die nächsten 4 Stunden für heute zu ermitteln
function getTodaysRemainingLessons($studentSchedule, $fachzuordnungen) {
    $days = ['Mo', 'Di', 'Mi', 'Do', 'Fr'];
    
    // Berlin-Zeit verwenden
    $berlin = new DateTimeZone('Europe/Berlin');
    $now = new DateTime('now', $berlin);
    $today = (int)$now->format('w'); // 0=Sonntag, 1=Montag, etc.
    
    // Konvertiere zu unserem Array-Index (Mo=0, Di=1, etc.)
    if ($today == 0) $today = 7; // Sonntag
    $todayIndex = $today - 1;
    
    // Falls Wochenende, zeige Montag
    if ($todayIndex < 0 || $todayIndex > 4) {
        $todayIndex = 0; // Montag
    }
    
    $todayName = $days[$todayIndex];
    
    // Stundenzeiten (Ende der jeweiligen Stunde)
    $lessonEndTimes = [
        1 => '08:25',
        2 => '09:15', 
        3 => '10:15',
        4 => '11:05',
        5 => '12:05',
        6 => '12:55',
        7 => '13:45',
        8 => '15:00',
        9 => '15:45'
    ];
    
    $currentTime = $now->format('H:i');
    $lessons = [];
    
    // Sammle alle Stunden für heute
    if (isset($studentSchedule[$todayName])) {
        for ($hour = 1; $hour <= 9; $hour++) {
            if (isset($studentSchedule[$todayName][$hour])) {
                $lesson = $studentSchedule[$todayName][$hour];
                
                // Prüfe ob diese Stunde bereits vorbei ist
                $lessonEndTime = $lessonEndTimes[$hour];
                $isFinished = ($currentTime > $lessonEndTime);
                
                if (!$isFinished) {
                    // Konvertiere Fachname mit Fachzuordnungen
                    $displayFach = $lesson['Fach'];
                    if (isset($lesson['OriginalFach'])) {
                        $originalFach = $lesson['OriginalFach'];
                        if (isset($fachzuordnungen[$originalFach])) {
                            $displayFach = $fachzuordnungen[$originalFach];
                        }
                    } else {
                        // Für reguläre Schüler - versuche Zuordnung zu finden
                        foreach ($fachzuordnungen as $kurs => $fachname) {
                            if (stripos($fachname, $lesson['Fach']) !== false || stripos($lesson['Fach'], $fachname) !== false) {
                                $displayFach = $fachname;
                                break;
                            }
                        }
                    }
                    
                    $lessons[] = [
                        'fach' => $displayFach,
                        'raum' => $lesson['Raum'],
                        'lehrer' => $lesson['Lehrer'],
                        'hour' => $hour,
                        'class' => $lesson['class'] ?? 'x'
                    ];
                }
            }
        }
    }
    
    // Entferne aufeinanderfolgende identische Stunden (Doppelstunden)
    $filteredLessons = [];
    $lastLesson = null;
    
    foreach ($lessons as $lesson) {
        if ($lastLesson === null || 
            $lastLesson['fach'] !== $lesson['fach'] || 
            $lastLesson['raum'] !== $lesson['raum'] || 
            $lastLesson['lehrer'] !== $lesson['lehrer']) {
            $filteredLessons[] = $lesson;
            $lastLesson = $lesson;
        }
    }
    
    // KEINE Begrenzung mehr - gebe alle Stunden zurück
    return $filteredLessons;
}

// Funktion um bevorstehende Klausuren zu ermitteln (7 Tage im Voraus)
function getUpcomingExams($klausuren) {
    $upcomingExams = [];
    
    // Berlin-Zeit verwenden
    $berlin = new DateTimeZone('Europe/Berlin');
    $now = new DateTime('now', $berlin);
    $today = clone $now;
    $today->setTime(0, 0, 0);
    
    foreach ($klausuren as $klausur) {
        $examDate = $klausur['date'];
        
        // Berechne Tage bis zur Klausur
        $interval = $today->diff($examDate);
        $daysUntil = $interval->days;
        
        // Prüfe ob die Klausur bereits vorbei ist (inkl. Uhrzeit)
        if ($examDate < $today) {
            continue; // Klausur ist vorbei
        }
        
        // Wenn heute: Prüfe ob die Klausur schon geschrieben wurde
        if ($examDate->format('Y-m-d') === $today->format('Y-m-d')) {
            // Extrahiere die letzte Stunde der Klausur
            $stundenStr = $klausur['stunden'];
            $lastHour = 0;
            
            // Finde die höchste Stundenzahl
            if (preg_match_all('/(\d+)/', $stundenStr, $matches)) {
                $lastHour = max($matches[1]);
            }
            
            // Stundenendzeiten
            $lessonEndTimes = [
                1 => '08:25', 2 => '09:15', 3 => '10:15', 4 => '11:05',
                5 => '12:05', 6 => '12:55', 7 => '13:45', 8 => '15:00', 9 => '15:45'
            ];
            
            if ($lastHour > 0 && isset($lessonEndTimes[$lastHour])) {
                $currentTime = $now->format('H:i');
                if ($currentTime > $lessonEndTimes[$lastHour]) {
                    continue; // Klausur ist heute schon vorbei
                }
            }
        }
        
        // Nur Klausuren innerhalb der nächsten 7 Tage
        if ($daysUntil <= 7 && !$interval->invert) {
            $upcomingExams[] = [
                'fach' => $klausur['fach'],
                'date' => $examDate->format('d.m.Y'),
                'stundenRange' => $klausur['stundenRange'],
                'daysUntil' => $daysUntil,
                'isAbitur' => $klausur['isAbitur'] ?? false  // NEU: Abitur-Flag weitergeben
            ];
        }
    }
    
    // Nach Datum sortieren (nächste zuerst)
    usort($upcomingExams, function($a, $b) {
        return $a['daysUntil'] - $b['daysUntil'];
    });
    
    return $upcomingExams;
}

// Fachzuordnungen für heute laden
$fachzuordnungenUrl = 'https://smg-adlersberg.de/timedex/fachzuordnungen.php';
$fachzuordnungen = fetchFachzuordnungenForToday($fachzuordnungenUrl);

// Alle verbleibenden Stunden ermitteln
$todaysLessons = getTodaysRemainingLessons($studentSchedule, $fachzuordnungen);

// Farben für die Karten
$cardColors = [
    '#3a5e9c', // Blau/Lila
    '#3a5e9c', // Rot
    '#3a5e9c', // Orange
    '#3a5e9c'  // Pink
];
?>

<br>
<?php
// Bevorstehende Klausuren ermitteln (nur für Oberstufenschüler)
$upcomingExams = [];
if ($userInfo['isOberstufe'] && isset($klausuren)) {
    $upcomingExams = getUpcomingExams($klausuren);
}
?>

<br>
<div class="today-schedule">
    <h2 class="today-title">Heute anstehend</h2>
    <div class="today-cards">
    
    <?php 
    // Zuerst die Klausur-Warnkarten
foreach ($upcomingExams as $exam): 
    // GEÄNDERT: Unterschiedliche Farbe für Abiturklausuren
    $cardColor = isset($exam['isAbitur']) && $exam['isAbitur'] ? '#4B0082' : '#8B0000';
    $prefix = isset($exam['isAbitur']) && $exam['isAbitur'] ? 'Abitur ' : 'Klausur ';
?>
    <div class="today-card" style="background-color: <?php echo $cardColor; ?>;">
        <div class="card-header">
            <div class="card-icons">
                <div class="card-icon" style="border: 2px solid white; background: transparent;">
                    <span style="font-size: 20px; font-weight: bold;">!</span>
                </div>
            </div>
                <div class="card-menu">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="1"/>
                        <circle cx="12" cy="5" r="1"/>
                        <circle cx="12" cy="19" r="1"/>
                    </svg>
                </div>
            </div>
            <div class="card-labels">
                <span class="card-label"><?php echo htmlspecialchars($exam['date']); ?></span>
                <?php if (!empty($exam['stundenRange'])): ?>
                    <span class="card-label">Stunde <?php echo htmlspecialchars($exam['stundenRange']); ?></span>
                <?php endif; ?>
            </div>
            <div class="card-title"><?php echo $prefix . htmlspecialchars($exam['fach']); ?></div>
        </div>
    <?php endforeach; ?>
    
    <?php 
    // Dann die normalen Stunden-Karten
    $lessonCount = count($todaysLessons);
    $cardColors = [
        '#3a5e9c', '#3a5e9c', '#3a5e9c', '#3a5e9c'
    ];
    
    for ($i = 0; $i < $lessonCount; $i++): 
        $lesson = $todaysLessons[$i];
        $colorIndex = $i % 4;
    ?>
        <div class="today-card" style="background-color: <?php echo $cardColors[$colorIndex]; ?>;">
            <div class="card-header">
                <div class="card-icons">
                    <div class="card-icon chat-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                        </svg>
                    </div>
                    <div class="card-icon klassenbuch-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 1 2 2h12a2 2 0 0 1 2-2V8z"/>
                            <polyline points="14,2 14,8 20,8"/>
                        </svg>
                    </div>
                </div>
                <div class="card-menu">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="1"/>
                        <circle cx="12" cy="5" r="1"/>
                        <circle cx="12" cy="19" r="1"/>
                    </svg>
                </div>
            </div>
            <div class="card-labels">
                <span class="card-label">Chat</span>
                <span class="card-label">Klassenbuch</span>
            </div>
            <div class="card-title"><?php echo htmlspecialchars($lesson['fach']); ?></div>
        </div>
    <?php endfor; ?>
    
    <?php if ($lessonCount === 0 && count($upcomingExams) === 0): ?>
        <div class="today-card empty-card" style="background-color: #3a5e9c; opacity: 0.3;">
            <div class="card-header">
                <div class="card-icons">
                    <div class="card-icon chat-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                        </svg>
                    </div>
                    <div class="card-icon klassenbuch-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 1 2 2h12a2 2 0 0 1 2-2V8z"/>
                            <polyline points="14,2 14,8 20,8"/>
                        </svg>
                    </div>
                </div>
                <div class="card-menu">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="1"/>
                        <circle cx="12" cy="5" r="1"/>
                        <circle cx="12" cy="19" r="1"/>
                    </svg>
                </div>
            </div>
            <div class="card-labels">
                <span class="card-label">Chat</span>
                <span class="card-label">Klassenbuch</span>
            </div>
            <div class="card-title">Keine weiteren Stunden heute</div>
        </div>
    <?php endif; ?>
    </div>
</div>




<?php
// Nachrichten für Schüler einbinden
if (isset($studentUsername) && isset($studentName)) {
    // Verwende sowohl den Benutzernamen als auch den echten Namen für die Nachrichtenprüfung
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
    <div class="timetable-header">Stundenplan für <?php echo htmlspecialchars($studentName); ?></div>
    <table class="timetable">
    <!-- Spaltenbreitengruppen für feste Layouts -->
    <colgroup>
        <col> <!-- Zeit-Spalte -->
        <col> <!-- Montag -->
        <col> <!-- Dienstag -->
        <col> <!-- Mittwoch -->
        <col> <!-- Donnerstag -->
        <col> <!-- Freitag -->
    </colgroup>
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

<?php
// Klausuren für Oberstufenschüler anzeigen
if ($userInfo['isOberstufe'] && !empty($klausuren)) {
    // HTML-Ausgabe für Klausuren
    echo '<div class="stundenplan">';
    echo '<div class="exam-schedule">';
    echo '<div class="timetable-header">Klausuren für ' . htmlspecialchars($studentName) . ' </div><br>';
    
    if (!empty($klausuren)) {
        foreach ($klausuren as $klausur) {
    // GEÄNDERT: Prefix für Abiturklausuren
    $examPrefix = (isset($klausur['isAbitur']) && $klausur['isAbitur']) ? 'Abitur: ' : '';
    
    echo '<div class="exam-entry">';
    echo '<div class="exam-date-time">';
    echo '<span class="exam-date">' . $klausur['date_string'] . '</span>';
    echo '</div>';
    echo '<div class="exam-details">';
    echo '<p class="exam-class">' . $examPrefix . htmlspecialchars($klausur['fach']) . '</p>';
            echo '<p class="exam-supervision">' . htmlspecialchars($klausur['raum']) . '</p>';
            echo '</div>';
            echo '</div>';
        }
    } else {
        echo '<div class="no-exams" style="text-align: center;">';
        echo '<p>Keine anstehenden Klausuren gefunden.</p>';
        echo '</div>';
    }
    
    echo '<p style="margin-bottom: 20px;"></p>';
    echo '<div class="exam-download" style="display: flex; justify-content: center; margin-top: 20px;">';  
    echo '<a href="https://smg-adlersberg.de/service/informationen/SMG-Kursarbeitskalender.pdf">';
    echo '<button class="exam-download-button">'; 
    echo '<img src="https://smg-adlersberg.de/timedex/PDF_file_icon.svg (2).png" alt="PDF Icon" class="exam-pdf-icon" />'; 
    echo 'Klausuren als PDF';
    echo '</button>';
    echo '</a>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
}
?>

<?php
// Fügen Sie diesen Code nach der Klausuren-Sektion in der Hauptdatei ein

if ($userInfo) {
    // Funktion zum Abrufen der Klassenbucheinträge für einen Lehrer
    function fetchTeacherClassbookData($teacherId) {
        $url = "https://smg-adlersberg.de/timedex/classbook_data/{$teacherId}.json";
        $data = @file_get_contents($url);
        if ($data === false) {
            return [];
        }
        
        $jsonData = json_decode($data, true);
        if ($jsonData === null) {
            return [];
        }
        
        return $jsonData;
    }
    
    // Funktion zum Ermitteln der aktuellen Woche (Montag-Freitag) in Berlin-Zeit
    function getCurrentWeekRange() {
        // Berlin Timezone verwenden
        $berlin = new DateTimeZone('Europe/Berlin');
        $today = new DateTime('now', $berlin);
        $dayOfWeek = $today->format('N'); // 1 = Montag, 7 = Sonntag
        
        // Berechne Montag der aktuellen Woche
        $monday = clone $today;
        $monday->sub(new DateInterval('P' . ($dayOfWeek - 1) . 'D'));
        $monday->setTime(0, 0, 0); // Setze auf Mitternacht
        
        return $monday;
    }
    
    // Funktion zum Sammeln aller Klassenbucheinträge für den Schüler
    function getStudentClassbookEntries($studentSchedule, $weekStartDate) {
        $days = ['Mo', 'Di', 'Mi', 'Do', 'Fr'];
        $allEntries = [];
        $teacherDataCache = [];
        
        foreach ($days as $dayIndex => $dayName) {
            if (!isset($studentSchedule[$dayName])) continue;
            
            foreach ($studentSchedule[$dayName] as $hour => $lesson) {
                $teacher = $lesson['Lehrer'];
                
                // Cache für Lehrerdaten verwenden
                if (!isset($teacherDataCache[$teacher])) {
                    $teacherDataCache[$teacher] = fetchTeacherClassbookData($teacher);
                }
                
                $teacherData = $teacherDataCache[$teacher];
                
                // Berechne das tatsächliche Datum für diesen Tag in der aktuellen Woche
                $currentDate = clone $weekStartDate;
                $currentDate->add(new DateInterval('P' . $dayIndex . 'D'));
                
                // Suche nach passendem Eintrag im Lehrerdatensatz
                $matchingEntry = null;
                foreach ($teacherData as $entryKey => $entryData) {
                    // Parse den Schlüssel: YYYY-MM-DD-DAYOFFSET-HOUR
                    if (preg_match('/^(\d{4}-\d{2}-\d{2})-(\d+)-(\d+)$/', $entryKey, $matches)) {
                        $entryBaseDate = $matches[1]; // Montag der JSON-Woche
                        $dayOffset = (int)$matches[2]; // 1=Mo, 2=Di, 3=Mi, 4=Do, 5=Fr
                        $entryHour = (int)$matches[3];
                        
                        // Berechne das tatsächliche Datum des Eintrags
                        $baseDate = new DateTime($entryBaseDate);
                        $actualEntryDate = clone $baseDate;
                        $actualEntryDate->add(new DateInterval('P' . ($dayOffset - 1) . 'D')); // dayOffset-1 da 1=Montag
                        
                        // Prüfe, ob Datum und Stunde übereinstimmen
                        if ($actualEntryDate->format('Y-m-d') === $currentDate->format('Y-m-d') && $entryHour === $hour) {
                            $matchingEntry = $entryData;
                            break;
                        }
                    }
                }
                
                $allEntries[] = [
                    'day' => $dayName,
                    'dayNumber' => $dayIndex + 1,
                    'hour' => $hour,
                    'date' => $currentDate->format('Y-m-d'),
                    'subject' => isset($lesson['OriginalFach']) ? cleanSubjectName($lesson['OriginalFach']) : cleanSubjectName($lesson['Fach']),
                    'teacher' => $teacher,
                    'room' => $lesson['Raum'],
                    'content' => $matchingEntry['content'] ?? '',
                    'homework' => $matchingEntry['homework'] ?? '',
                    'cancelled' => $matchingEntry['cancelled'] ?? false,
                    'hasEntry' => $matchingEntry !== null && (!empty($matchingEntry['content']) || !empty($matchingEntry['homework']) || ($matchingEntry['cancelled'] ?? false))
                ];
            }
        }
        
        // Nach Tag und Stunde sortieren
        usort($allEntries, function($a, $b) {
            if ($a['dayNumber'] !== $b['dayNumber']) {
                return $a['dayNumber'] - $b['dayNumber'];
            }
            return $a['hour'] - $b['hour'];
        });
        
        return $allEntries;
    }
    
    // Funktion für Fortschrittsberechnung mit Berlin-Zeit
    function getStudentLessonProgress($dayNumber, $hour, $cancelled, $weekStartDate) {
        // Stundenzeiten
        $lessonTimes = [
            1 => ['start' => '7:40', 'end' => '8:25'],
            2 => ['start' => '8:30', 'end' => '9:15'],
            3 => ['start' => '9:30', 'end' => '10:15'],
            4 => ['start' => '10:20', 'end' => '11:05'],
            5 => ['start' => '11:20', 'end' => '12:05'],
            6 => ['start' => '12:10', 'end' => '12:55'],
            7 => ['start' => '13:00', 'end' => '13:45'],
            8 => ['start' => '14:15', 'end' => '15:00'],
            9 => ['start' => '15:00', 'end' => '15:45']
        ];
        
        // Ausgefallene Stunden
        if ($cancelled) {
            return ['color' => 'red', 'width' => 100];
        }
        
        // Berlin Timezone verwenden
        $berlin = new DateTimeZone('Europe/Berlin');
        $now = new DateTime('now', $berlin);
        
        // Berechne das Datum der Stunde
        $lessonDate = clone $weekStartDate;
        $lessonDate->add(new DateInterval('P' . ($dayNumber - 1) . 'D'));
        $lessonDate->setTimezone($berlin);
        
        // Prüfe, ob die Stunde in der Zukunft liegt
        if ($lessonDate->format('Y-m-d') > $now->format('Y-m-d')) {
            return ['color' => 'gray', 'width' => 0];
        }
        
        // Prüfe, ob die Stunde in der Vergangenheit liegt (ganzer Tag)
        if ($lessonDate->format('Y-m-d') < $now->format('Y-m-d')) {
            return ['color' => 'green', 'width' => 100];
        }
        
        // Heutiger Tag - prüfe die Zeit
        if ($lessonDate->format('Y-m-d') === $now->format('Y-m-d')) {
            if (!isset($lessonTimes[$hour])) {
                return ['color' => 'gray', 'width' => 0];
            }
            
            $startTime = $lessonTimes[$hour]['start'];
            $endTime = $lessonTimes[$hour]['end'];
            
            list($startHour, $startMin) = explode(':', $startTime);
            list($endHour, $endMin) = explode(':', $endTime);
            
            $currentTime = (int)$now->format('H') * 60 + (int)$now->format('i');
            $lessonStartTime = (int)$startHour * 60 + (int)$startMin;
            $lessonEndTime = (int)$endHour * 60 + (int)$endMin;
            
            if ($currentTime < $lessonStartTime) {
                // Stunde hat noch nicht begonnen
                return ['color' => 'gray', 'width' => 0];
            } elseif ($currentTime >= $lessonEndTime) {
                // Stunde ist vorbei
                return ['color' => 'green', 'width' => 100];
            } else {
                // Stunde läuft gerade
                $progress = floor(($currentTime - $lessonStartTime) / ($lessonEndTime - $lessonStartTime) * 100);
                return ['color' => 'yellow', 'width' => $progress];
            }
        }
        
        // Fallback
        return ['color' => 'gray', 'width' => 0];
    }
    
    // Deutsche Tagesnamen für die Anzeige
    $germanDays = [
        'Mo' => 'Montag',
        'Di' => 'Dienstag', 
        'Mi' => 'Mittwoch',
        'Do' => 'Donnerstag',
        'Fr' => 'Freitag'
    ];
    
    // Aktueller Tag ermitteln (Berlin-Zeit)
    $berlin = new DateTimeZone('Europe/Berlin');
    $today = new DateTime('now', $berlin);
    $currentDayOfWeek = (int)$today->format('N'); // 1=Montag, 7=Sonntag
    
    // Aktuelle Woche ermitteln und Klassenbucheinträge sammeln
    $currentWeekStart = getCurrentWeekRange();
    $classbookEntries = getStudentClassbookEntries($studentSchedule, $currentWeekStart);
    
    // Formatiere das Datum für die Anzeige
    $weekEndDate = clone $currentWeekStart;
    $weekEndDate->add(new DateInterval('P4D')); // +4 Tage für Freitag
    
    $weekDisplayText = $currentWeekStart->format('d.m.y') . ' - ' . $weekEndDate->format('d.m.y');
    
    // Gruppiere Einträge nach Tagen
    $entriesByDay = [];
    foreach ($classbookEntries as $entry) {
        $entriesByDay[$entry['day']][] = $entry;
    }
    
    // Bestimme den aktiven Tag (heute oder Montag als Fallback)
    $activeDay = 'Mo'; // Fallback
    $activeDayIndex = 0;
    
    if ($currentDayOfWeek >= 1 && $currentDayOfWeek <= 5) {
        // Wochentag - zeige aktuellen Tag
        $days = ['Mo', 'Di', 'Mi', 'Do', 'Fr'];
        $activeDay = $days[$currentDayOfWeek - 1];
        $activeDayIndex = $currentDayOfWeek - 1;
    }
    
    // HTML-Ausgabe für das Klassenbuch (in stundenplan Box)
    echo '<div class="stundenplan">';
    echo '<div class="timetable-header">Klassenbuch für ' . htmlspecialchars($studentName) . '</div>';
    echo '<div class="classbook-week-info" style="text-align: center; color: #666; margin-bottom: 15px;">Woche: ' . $weekDisplayText . '</div>';
    
    // Tagesauswahl
    echo '<div class="day-selector" style="display: flex; margin-bottom: 20px; background-color: white; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); overflow: hidden;">';
    $days = ['Mo', 'Di', 'Mi', 'Do', 'Fr'];
    foreach ($days as $index => $day) {
        $activeClass = ($day === $activeDay) ? ' active' : '';
        $buttonStyle = 'flex: 1; padding: 12px 0; text-align: center; background-color: white; color: #223E6D; border: none; cursor: pointer; font-weight: 500;';
        if ($activeClass) {
            $buttonStyle = 'flex: 1; padding: 12px 0; text-align: center; background-color: #223E6D; color: white; border: none; cursor: pointer; font-weight: 500;';
        }
        echo '<div class="student-day-button' . $activeClass . '" data-day="' . ($index + 1) . '" style="' . $buttonStyle . '">' . $day . '</div>';
    }
    echo '</div>';
    
    // Unterrichtsstunden-Container
    echo '<div class="student-lessons-container">';
    
    if (!empty($classbookEntries)) {
        foreach ($days as $dayIndex => $dayName) {
            $dayEntries = $entriesByDay[$dayName] ?? [];
            $displayStyle = ($dayName === $activeDay) ? 'block' : 'none';
            
            echo '<div class="student-day-content" data-day="' . ($dayIndex + 1) . '" style="display: ' . $displayStyle . ';">';
            
            if (!empty($dayEntries)) {
                foreach ($dayEntries as $entry) {
                    // Fortschrittsbalken mit korrekter Logik
                    $progressData = getStudentLessonProgress($entry['dayNumber'], $entry['hour'], $entry['cancelled'], $currentWeekStart);
                    
                    echo '<div class="lesson-item">';
                    echo '<div class="lesson-header">';
                    echo '<div>';
                    echo '<div class="lesson-class">' . htmlspecialchars(substr($entry['subject'], 0, 3)) . '</div>';
                    echo '<div class="lesson-hour">STD. ' . $entry['hour'] . '</div>';
                    echo '<div class="lesson-subject">' . htmlspecialchars($entry['teacher']) . '</div>'; // Lehrerkürzel statt Fach
                    echo '</div>';
                    
                    echo '<div class="lesson-content">';
                    echo '<div class="lesson-title">';
                    
                    if ($entry['cancelled']) {
                        echo 'Stunde ausgefallen.';
                    } elseif (!empty($entry['content'])) {
                        echo htmlspecialchars($entry['content']);
                    } else {
                        echo 'Kein Lehrstoff.';
                    }
                    
                    echo '</div>';
                    
                    echo '<div class="lesson-homework">';
                    if (!empty($entry['homework'])) {
                        echo htmlspecialchars($entry['homework']);
                    } else {
                        echo 'Keine Hausaufgaben.';
                    }
                    echo '</div>';
                    
                    echo '<div class="progress-bar">';
                    echo '<div class="progress ' . $progressData['color'] . '" style="width: ' . $progressData['width'] . '%"></div>';
                    echo '</div>';
                    
                    echo '</div>'; // Ende lesson-content
                    echo '</div>'; // Ende lesson-header
                    echo '</div>'; // Ende lesson-item
                }
            } else {
                echo '<p>Keine Unterrichtsstunden für diesen Tag.</p>';
            }
            
            echo '</div>'; // Ende student-day-content
        }
    } else {
        echo '<p>Keine Klassenbucheinträge für diese Woche gefunden.</p>';
    }
    
    echo '</div>'; // Ende student-lessons-container
    echo '</div>'; // Ende stundenplan
}
?>

<script>
// Einfache aber zuverlässige Auto-Refresh Lösung für Vertretungen
document.addEventListener('DOMContentLoaded', function() {
    let progressUpdateInterval;
    let scheduleRefreshInterval;
    
    // ===== INTELLIGENTER STUNDENPLAN AUTO-REFRESH =====

let lastUpdateContent = '';
let isInitialized = false;

async function checkForUpdates() {
    try {
        const response = await fetch('https://smg-adlersberg.de/timedex/external/aktualisiert.php', {
            method: 'GET',
            cache: 'no-cache',
            headers: {
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache',
                'Expires': '0'
            }
        });
        
        if (!response.ok) {
            console.warn('Konnte aktualisiert.php nicht abrufen:', response.status);
            return;
        }
        
        const currentContent = await response.text();
        const trimmedContent = currentContent.trim();
        
        // Beim ersten Aufruf nur den Inhalt speichern, nicht neu laden
        if (!isInitialized) {
            lastUpdateContent = trimmedContent;
            isInitialized = true;
            console.log('Initiale Aktualisierungszeit gespeichert:', trimmedContent);
            return;
        }
        
        // Prüfen, ob sich der Inhalt geändert hat
        if (lastUpdateContent !== trimmedContent) {
            console.log('Neue Vertretungen gefunden!');
            console.log('Alt:', lastUpdateContent);
            console.log('Neu:', trimmedContent);
            
            refreshScheduleForSubstitutions();
        } else {
            console.log('Keine Änderungen gefunden:', new Date().toLocaleTimeString());
        }
        
    } catch (error) {
        console.warn('Fehler beim Überprüfen der Updates:', error);
    }
}

function refreshScheduleForSubstitutions() {
    console.log('Lade Seite für neue Vertretungen neu...', new Date().toLocaleTimeString());
    
    // Kurze Benachrichtigung vor Reload
    showRefreshNotification();
    
    // Nach kurzer Verzögerung neu laden
    setTimeout(() => {
        location.reload();
    }, 500);
}
    
    function showRefreshNotification() {
        // Entferne vorherige Benachrichtigungen
        const existingNotification = document.querySelector('.refresh-notification');
        if (existingNotification) {
            existingNotification.remove();
        }
        
        // Erstelle neue Benachrichtigung
        const notification = document.createElement('div');
        notification.className = 'refresh-notification';
        notification.innerHTML = 'Vertretungsplan wird aktualisiert...';
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background-color: #2196F3;
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            font-size: 14px;
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s ease;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        `;
        
        document.body.appendChild(notification);
        
        // Fade in
        setTimeout(() => notification.style.opacity = '1', 100);
    }
    
    // ===== KLASSENBUCH FORTSCHRITTSBALKEN (alle 5 Sekunden) =====
    
    function updateProgressBars() {
        // Hole aktuelle Berlin-Zeit
        const now = new Date();
        const berlinTime = new Date(now.toLocaleString("en-US", {timeZone: "Europe/Berlin"}));
        
        const currentHour = berlinTime.getHours();
        const currentMinute = berlinTime.getMinutes();
        const currentTime = currentHour * 60 + currentMinute;
        
        // Stundenzeiten
        const lessonTimes = {
            1: {start: 7*60 + 40, end: 8*60 + 25},
            2: {start: 8*60 + 30, end: 9*60 + 15},
            3: {start: 9*60 + 30, end: 10*60 + 15},
            4: {start: 10*60 + 20, end: 11*60 + 5},
            5: {start: 11*60 + 20, end: 12*60 + 5},
            6: {start: 12*60 + 10, end: 12*60 + 55},
            7: {start: 13*60 + 0, end: 13*60 + 45},
            8: {start: 14*60 + 15, end: 15*60 + 0},
            9: {start: 15*60 + 0, end: 15*60 + 45}
        };
        
        // Aktuelle Wochentag ermitteln (1=Montag, 7=Sonntag)
        const dayOfWeek = berlinTime.getDay();
        const currentDayOfWeek = dayOfWeek === 0 ? 7 : dayOfWeek; // Sonntag = 7
        
        // Alle Fortschrittsbalken aktualisieren
        document.querySelectorAll('.lesson-item').forEach(function(lessonItem) {
            const lessonHeader = lessonItem.querySelector('.lesson-header');
            const hourElement = lessonHeader?.querySelector('.lesson-hour');
            if (!hourElement) return;
            
            const hourText = hourElement.textContent;
            const hour = parseInt(hourText.replace('STD. ', ''));
            
            // Bestimme den Tag dieser Stunde
            const dayContent = lessonItem.closest('.student-day-content');
            if (!dayContent) return;
            
            const dayNumber = parseInt(dayContent.dataset.day);
            const progressBar = lessonItem.querySelector('.progress');
            if (!progressBar) return;
            
            // Prüfe auf ausgefallene Stunden (rot)
            if (progressBar.classList.contains('red')) {
                return; // Bleibt rot
            }
            
            if (lessonTimes[hour]) {
                const startTime = lessonTimes[hour].start;
                const endTime = lessonTimes[hour].end;
                
                // Prüfe ob es heute ist
                if (dayNumber === currentDayOfWeek) {
                    if (currentTime < startTime) {
                        // Stunde hat noch nicht begonnen - grau
                        progressBar.className = 'progress gray';
                        progressBar.style.width = '0%';
                    } else if (currentTime >= endTime) {
                        // Stunde ist vorbei - grün
                        progressBar.className = 'progress green';
                        progressBar.style.width = '100%';
                    } else {
                        // Stunde läuft gerade - gelb mit Fortschritt
                        const progress = Math.floor(((currentTime - startTime) / (endTime - startTime)) * 100);
                        progressBar.className = 'progress yellow';
                        progressBar.style.width = progress + '%';
                    }
                } else if (dayNumber < currentDayOfWeek) {
                    // Vergangener Tag - grün
                    progressBar.className = 'progress green';
                    progressBar.style.width = '100%';
                } else {
                    // Zukünftiger Tag - grau
                    progressBar.className = 'progress gray';
                    progressBar.style.width = '0%';
                }
            }
        });
    }
    
    // ===== KLASSENBUCH TAGESAUSWAHL =====
    
    function initializeDaySelection() {
        const studentDayButtons = document.querySelectorAll('.student-day-button');
        const studentDayContents = document.querySelectorAll('.student-day-content');
        
        studentDayButtons.forEach(button => {
            button.addEventListener('click', function() {
                const selectedDay = this.getAttribute('data-day');
                
                // Alle Buttons deaktivieren und Styling zurücksetzen
                studentDayButtons.forEach(btn => {
                    btn.classList.remove('active');
                    btn.style.backgroundColor = 'white';
                    btn.style.color = '#223E6D';
                });
                
                // Aktuellen Button aktivieren
                this.classList.add('active');
                this.style.backgroundColor = '#223E6D';
                this.style.color = 'white';
                
                // Alle Tagesinhalte ausblenden
                studentDayContents.forEach(content => {
                    content.style.display = 'none';
                });
                
                // Gewählten Tagesinhalt anzeigen
                const selectedContent = document.querySelector(`[data-day="${selectedDay}"].student-day-content`);
                if (selectedContent) {
                    selectedContent.style.display = 'block';
                }
            });
        });
    }
    
    // ===== INITIALISIERUNG =====
    
   function startAutoRefresh() {
    console.log('Auto-Refresh gestartet: Intelligente Überwachung alle 30 Sekunden, Fortschritt alle 5 Sekunden');
    
    // Intelligente Stundenplan-Überwachung alle 30 Sekunden
    scheduleRefreshInterval = setInterval(checkForUpdates, 30000);
    
    // Fortschrittsbalken alle 5 Sekunden
    progressUpdateInterval = setInterval(updateProgressBars, 5000);
    
    // Sofortige erste Prüfung (nach kurzer Verzögerung)
    setTimeout(checkForUpdates, 2000);
}
    
    function stopAutoRefresh() {
        if (scheduleRefreshInterval) {
            clearInterval(scheduleRefreshInterval);
            console.log('Stundenplan Auto-Refresh gestoppt');
        }
        if (progressUpdateInterval) {
            clearInterval(progressUpdateInterval);
        }
    }
    
    // Bei Tab-Wechsel pausieren/fortsetzen
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            stopAutoRefresh();
        } else {
            startAutoRefresh();
        }
    });
    
    // Initialisierung mit Verzögerung für DOM-Bereitschaft
    setTimeout(() => {
        initializeDaySelection();
        updateProgressBars(); // Initiale Aktualisierung
        startAutoRefresh(); // Starte Auto-Refresh
    }, 500);
    
    // Für manuellen Zugriff
    window.scheduleRefresh = {
        refreshNow: refreshScheduleForSubstitutions,
        updateProgress: updateProgressBars,
        start: startAutoRefresh,
        stop: stopAutoRefresh
    };
    
    console.log('Intelligentes Stundenplan Auto-Refresh System geladen');
});
</script>
</body>
</html>