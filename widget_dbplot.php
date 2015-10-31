<?php

// ----------------------------------------------------------
// 
// SmartVISU widget for database plots with highcharts
// (c) Tobias Geier 2015
// 
// Version: 0.1
// License: GNU General Public License v2.0
// 
// Manual: 
// 
// ----------------------------------------------------------


/* * ****************************************
 * *****************CONFIG*******************
 * **************************************** */

// Edit the dbConnect to match your DB Log settings of fhem
// For SQLite define the path to your db in your filesystem
// DB Type, use 'sqlite' or 'mysql'
$dbType = 'sqlite';

// SQLite
$dbPath = '/opt/fhem/fhem.db';

// MySQL
$host = 'localhost';
$mysql_username = '';
$mysql_password = '';
$database = '';

/* * ****************************************
  It is not needed to change any settings below if you use this widget with FHEM DbLog
 * **************************************** */

$timestampColumn = 'TIMESTAMP';
$valueColumn = 'VALUE';
$unitColumn = 'UNIT';
$readingColumn = 'READING';
$deviceColumn = 'DEVICE';
$logTable = 'history';

/* * ****************************************
 * ***************ENDCONFIG******************
 * **************************************** */

// Set the JSON header
header("content-type: application/json");

// Check if required POST values are set
if (!isset($_POST['query']) && !isset($_POST['range'])) {
    // If one of POST values is missing return Error
    if (!isset($_POST['query']) || $_POST['query'] == "") {
        returnError("Missing or empty query value in POST request");
    }
    if (!isset($_POST['range']) || $_POST['range'] == "") {
        returnError("Missing or empty range value in POST request");
    }
} else {

    // Set data from visu.js POST request
    $query = $_POST['query'];
    $range = $_POST['range'];
}

// Decode JSON for query from POST Request
$requestedDeviceReadings = json_decode($query);

// Basic validation for request query
if (!is_array($requestedDeviceReadings) && count($requestedDeviceReadings) == 0) {
    returnError("plotOptions is no array, wrong formed or empty");
}

// Create new PDO Object for DB Connection
if ($dbType == 'sqlite') {
    $db = new PDO('sqlite:' . $dbPath);
} elseif ($dbType == 'mysql') {
    $db = new PDO('mysql:host=' . $host . ';' . $database, $mysql_username, $mysql_password);
}
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Get requested series for the plot
foreach ($requestedDeviceReadings as $i => $deviceReading) {

    // Prepare plot array
    $plotArray = array();

    // Execute DB Query for device reading
    $stmt = dbQuery($deviceReading->device, $deviceReading->reading, $range, $db);


    // Loop through fetched data from db and push values to plot array
    for ($a = 0; $row = $stmt->fetch(PDO::FETCH_ASSOC); $a++) {

        // Convert datetime from timestamp column to unix timestamp or set to current time if update request 
        $timestamp = (isset($_POST['update'])) ? mktime() * 1000 : strtotime($row['TIMESTAMP']) * 1000;
        $item = array($timestamp, floatval($row['VALUE']));
        array_push($plotArray, $item);

        // Add a point with current timestamp and the last reading value so we don't get a gap in the plot
        if ($a == $stmt->rowCount()) {
            $item = array($timestamp, floatval($row['VALUE']));
            array_push($plotArray, $item);
        }
    }

    // Fill return array with Options for plot
    foreach ($deviceReading->config as $key => $value) {
        $returnArray[$i][$key] = $value;
        // Check if unit for readings is allready set
        $unitSet = ($key == 'unit') ? true : false;
    }
    // If unit is not set use unit value from db
    $returnArray[$i]['unit'] = (!$unitSet) ? $row['UNIT'] : $returnArray[$i]['unit'];

    // Reverse and set plot array 
    $returnArray[$i]['data'] = array_reverse($plotArray);
    $i++;
}

// Close DB Connection
unset($db);

// Return the array and encode to JSON
echo json_encode($returnArray);


/* * ****************************************
 * ***************FUNCTIONS******************
 * **************************************** */

// Query function to get data from FHEM dbLog Database
function dbQuery($device, $reading, $timeRange, $db) {

    global $timestampColumn;
    global $valueColumn;
    global $unitColumn;
    global $readingColumn;
    global $deviceColumn;
    global $logTable;

    $timeRange = mktime() - ($timeRange * 60);
    $timeRange = date("Y-m-d H:i:s", $timeRange);

    // Check if request is initial request or update
    if (isset($_POST['update'])) {

        // Select only the last entry to get the update data for the plot
        $maxCount = 1;
        $dbQuery = 'SELECT ' . $timestampColumn . ', ' . $valueColumn . ', ' . $unitColumn . ' FROM ' . $logTable . ' WHERE ' . $deviceColumn . '=:device AND ' . $readingColumn . '=:reading ORDER BY ' . $timestampColumn . ' DESC LIMIT 0,:count';
    } else {

        // Check if max_entry_count var is set in POST request, defaults to 300
        $maxCount = (isset($_POST['maxRows'])) ? $_POST['maxRows'] : 300;
        $dbQuery = 'SELECT ' . $timestampColumn . ', ' . $valueColumn . ', ' . $unitColumn . ' FROM ' . $logTable . ' WHERE ' . $deviceColumn . '=:device AND ' . $readingColumn . '=:reading AND ' . $timestampColumn . ' > :timeRange ORDER BY ' . $timestampColumn . ' DESC LIMIT 0,:count';
    }

    // Try to execute sql query or return error
    try {
        $stmt = $db->prepare($dbQuery);
        $stmt->bindValue(':device', $device, PDO::PARAM_STR);
        $stmt->bindValue(':reading', $reading, PDO::PARAM_STR);

        // Check if request is initial request or update
        if (!isset($_POST['update'])) {

            $stmt->bindValue(':timeRange', $timeRange);
        }

        $stmt->bindValue(':count', $maxCount);
        $stmt->execute();
    } catch (PDOException $pe) {
        returnError($pe->getMessage());
    }
    
    // If there are zero rows, return error with sql query
    if ($stmt->rowCount() == 0) {
        returnError('No data returned for query:' . $dbQuery);
    }
    
    return $stmt;
}

// Return script errors as JSON Data to display them in SmartVISU
function returnError($error) {
    $errorReturn = array('error' => $error);
    echo json_encode($errorReturn);
    exit;
}
