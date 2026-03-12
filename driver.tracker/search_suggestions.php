<?php
session_start();

// Include configuration file
require_once 'config.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

// Check if this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

// Check if action is provided
if (!isset($_POST['action'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit;
}

try {
    // Get database connection
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    // Handle search drivers action
    if ($_POST['action'] === 'search_drivers') {
        // Get the search query
        $query = isset($_POST['query']) ? trim($_POST['query']) : '';
        
        if (empty($query) || strlen($query) < 2) {
            echo json_encode(['success' => false, 'error' => 'Query too short']);
            exit;
        }
        
        // Prepare the search query
        $searchTerm = '%' . $query . '%';
        
        $sql = "SELECT driverId, firstName, lastName, username 
                FROM user_login 
                WHERE (firstName LIKE ? OR lastName LIKE ? OR CONCAT(firstName, ' ', lastName) LIKE ? OR driverId LIKE ?) 
                AND driverId IS NOT NULL 
                ORDER BY 
                    CASE 
                        WHEN firstName LIKE ? THEN 1
                        WHEN lastName LIKE ? THEN 2
                        WHEN CONCAT(firstName, ' ', lastName) LIKE ? THEN 3
                        ELSE 4
                    END,
                    firstName ASC, lastName ASC
                LIMIT 10";
        
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        // For prioritizing exact matches at the beginning
        $exactSearchTerm = $query . '%';
        
        $stmt->bind_param("sssssss", 
            $searchTerm, $searchTerm, $searchTerm, $searchTerm,
            $exactSearchTerm, $exactSearchTerm, $exactSearchTerm
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        $drivers = [];
        
        while ($row = $result->fetch_assoc()) {
            $drivers[] = [
                'driverId' => $row['driverId'],
                'firstName' => $row['firstName'],
                'lastName' => $row['lastName'],
                'username' => $row['username']
            ];
        }
        
        $stmt->close();
        
        // Return the results
        echo json_encode([
            'success' => true,
            'drivers' => $drivers,
            'query' => $query
        ]);
    }
    
    // Handle get driver location action - UPDATED FOR 'locations' TABLE
    elseif ($_POST['action'] === 'get_driver_location') {
        $driverId = isset($_POST['driverId']) ? trim($_POST['driverId']) : '';
        
        if (empty($driverId)) {
            echo json_encode(['success' => false, 'error' => 'Driver ID required']);
            exit;
        }
        
        // Get the latest location from 'locations' table (changed from tracking_data)
        $sql = "SELECT latitude, longitude, updated_at, order_id ,orderStatus=0
                FROM locations 
                WHERE driverId = ? and orderStatus=0
                ORDER BY updated_at DESC 
                LIMIT 1";
        
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("s", $driverId);
        
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $location = $result->fetch_assoc();
            
            // Check if location data is valid
            if (!empty($location['latitude']) && !empty($location['longitude'])) {
                $lat = floatval($location['latitude']);
                $lng = floatval($location['longitude']);
                
                // Additional validation: Check if coordinates are not (0,0)
                if ($lat != 0 && $lng != 0) {
                    echo json_encode([
                        'success' => true,
                        'location' => [
                            'latitude' => $lat,
                            'longitude' => $lng,
                            'updated_at' => $location['updated_at'],
                            'order_id' => $location['order_id']
                        ]
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'error' => 'Invalid GPS coordinates (0,0) - Driver location not available'
                    ]);
                }
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'No valid location data found for this driver'
                ]);
            }
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'No location data found for this driver'
            ]);
        }
        
        $stmt->close();
    }
    
    else {
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    
    $db->close();
    
} catch (Exception $e) {
    // Log the error
    if (function_exists('logAppError')) {
        logAppError("Search suggestions error: " . $e->getMessage());
    }
    
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred',
        'debug' => $e->getMessage() // Remove this in production
    ]);
}
?>