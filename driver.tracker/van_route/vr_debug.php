<?php
session_start();
require_once '../config.php';

$db   = Database::getInstance();
$conn = $db->getConnection();

echo "<h2>Test 1: van_route_history mein kya hai?</h2>";
$r = $conn->query("SELECT DISTINCT driver_id, school_id FROM van_route_history LIMIT 10");
if (!$r) { echo "ERROR: " . $conn->error; } 
else {
    echo "<table border=1 cellpadding=5>";
    echo "<tr><th>driver_id</th><th>school_id</th></tr>";
    while ($row = $r->fetch_assoc()) {
        echo "<tr><td>{$row['driver_id']}</td><td>{$row['school_id']}</td></tr>";
    }
    echo "</table>";
}

echo "<h2>Test 2: fnc_user driverId kya hain?</h2>";
$r2 = $conn->query("SELECT driverId, firstName, lastName, school_id, school_name FROM fnc_user LIMIT 10");
if (!$r2) { echo "ERROR: " . $conn->error; }
else {
    echo "<table border=1 cellpadding=5>";
    echo "<tr><th>driverId</th><th>firstName</th><th>lastName</th><th>school_id</th><th>school_name</th></tr>";
    while ($row = $r2->fetch_assoc()) {
        echo "<tr><td>{$row['driverId']}</td><td>{$row['firstName']}</td><td>{$row['lastName']}</td><td>{$row['school_id']}</td><td>{$row['school_name']}</td></tr>";
    }
    echo "</table>";
}

echo "<h2>Test 3: edu_user driverId kya hain?</h2>";
$r3 = $conn->query("SELECT driverId, firstName, lastName, school_id, school_name FROM edu_user LIMIT 10");
if (!$r3) { echo "ERROR: " . $conn->error; }
else {
    echo "<table border=1 cellpadding=5>";
    echo "<tr><th>driverId</th><th>firstName</th><th>lastName</th><th>school_id</th><th>school_name</th></tr>";
    while ($row = $r3->fetch_assoc()) {
        echo "<tr><td>{$row['driverId']}</td><td>{$row['firstName']}</td><td>{$row['lastName']}</td><td>{$row['school_id']}</td><td>{$row['school_name']}</td></tr>";
    }
    echo "</table>";
}

echo "<h2>Test 4: JOIN result kya aata hai?</h2>";
$r4 = $conn->query("
    SELECT DISTINCT vrh.driver_id,
        fu.firstName AS fu_first, fu.school_name AS fu_school,
        eu.firstName AS eu_first, eu.school_name AS eu_school
    FROM van_route_history vrh
    LEFT JOIN fnc_user fu ON fu.driverId = vrh.driver_id
    LEFT JOIN edu_user eu ON eu.driverId = vrh.driver_id
    LIMIT 10
");
if (!$r4) { echo "ERROR: " . $conn->error; }
else {
    echo "<table border=1 cellpadding=5>";
    echo "<tr><th>vrh.driver_id</th><th>fnc firstName</th><th>fnc school</th><th>edu firstName</th><th>edu school</th></tr>";
    while ($row = $r4->fetch_assoc()) {
        echo "<tr>
            <td>{$row['driver_id']}</td>
            <td>" . ($row['fu_first'] ?? 'NULL') . "</td>
            <td>" . ($row['fu_school'] ?? 'NULL') . "</td>
            <td>" . ($row['eu_first'] ?? 'NULL') . "</td>
            <td>" . ($row['eu_school'] ?? 'NULL') . "</td>
        </tr>";
    }
    echo "</table>";
}
?>
