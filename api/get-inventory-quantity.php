<!-- api/get-inventory-quantity.php -->
<?php
// Start session if not already started
if(session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include database config
include_once '../config/database.php';

// Ensure user is logged in and has appropriate role
if(!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'owner' && $_SESSION['role'] !== 'incharge' && $_SESSION['role'] !== 'shopkeeper')) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Check if required parameters are provided
if(!isset($_GET['product_id']) || !isset($_GET['location'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing required parameters']);
    exit;
}

$product_id = $_GET['product_id'];
$location = $_GET['location'];

try {
    // Get inventory quantity
    $query = "SELECT quantity FROM inventory 
             WHERE product_id = ? AND location = ?";
    $stmt = $db->prepare($query);
    $stmt->bindParam(1, $product_id);
    $stmt->bindParam(2, $location);
    $stmt->execute();
    
    if($stmt->rowCount() > 0) {
        $inventory = $stmt->fetch(PDO::FETCH_ASSOC);
        header('Content-Type: application/json');
        echo json_encode([
            'available' => true,
            'quantity' => (int)$inventory['quantity']
        ]);
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'available' => false,
            'quantity' => 0
        ]);
    }
} catch(Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}
?>