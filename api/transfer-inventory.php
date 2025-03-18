<!-- api/transfer-inventory.php -->
<?php
// Start session if not already started
if(session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include database config
include_once '../config/database.php';
include_once '../config/auth.php';

// Ensure user is logged in and has appropriate role
if(!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'owner' && $_SESSION['role'] !== 'incharge')) {
    header('Location: ../index.php');
    exit;
}

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Initialize auth for activity logging
$auth = new Auth($db);

// Check if form was submitted
if($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get form data
        $product_id = $_POST['product_id'];
        $from_location = $_POST['from_location'];
        $to_location = $_POST['to_location'];
        $quantity = $_POST['quantity'];
        $notes = $_POST['notes'] ?? null;
        $initiated_by = $_SESSION['user_id'];
        
        // Validate data
        if(empty($product_id) || empty($from_location) || empty($to_location) || empty($quantity)) {
            throw new Exception("Missing required fields.");
        }
        
        if(!is_numeric($quantity) || $quantity <= 0) {
            throw new Exception("Quantity must be a positive number.");
        }
        
        if($from_location === $to_location) {
            throw new Exception("Source and destination locations cannot be the same.");
        }
        
        // Start transaction
        $db->beginTransaction();
        
        // Check if product exists in source location with sufficient quantity
        $check_query = "SELECT id, quantity FROM inventory 
                       WHERE product_id = ? AND location = ?";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(1, $product_id);
        $check_stmt->bindParam(2, $from_location);
        $check_stmt->execute();
        
        if($check_stmt->rowCount() === 0) {
            throw new Exception("Product not found in the source location.");
        }
        
        $source_inventory = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if($source_inventory['quantity'] < $quantity) {
            throw new Exception("Insufficient quantity in source location. Available: {$source_inventory['quantity']}");
        }
        
        // Deduct from source location
        $update_source_query = "UPDATE inventory 
                              SET quantity = quantity - ?, updated_at = NOW() 
                              WHERE id = ?";
        $update_source_stmt = $db->prepare($update_source_query);
        $update_source_stmt->bindParam(1, $quantity);
        $update_source_stmt->bindParam(2, $source_inventory['id']);
        $update_source_stmt->execute();
        
        // Check if product exists in destination location
        $check_dest_query = "SELECT id FROM inventory 
                           WHERE product_id = ? AND location = ?";
        $check_dest_stmt = $db->prepare($check_dest_query);
        $check_dest_stmt->bindParam(1, $product_id);
        $check_dest_stmt->bindParam(2, $to_location);
        $check_dest_stmt->execute();
        
        if($check_dest_stmt->rowCount() > 0) {
            // Update existing destination inventory
            $dest_inventory = $check_dest_stmt->fetch(PDO::FETCH_ASSOC);
            
            $update_dest_query = "UPDATE inventory 
                                SET quantity = quantity + ?, updated_at = NOW() 
                                WHERE id = ?";
            $update_dest_stmt = $db->prepare($update_dest_query);
            $update_dest_stmt->bindParam(1, $quantity);
            $update_dest_stmt->bindParam(2, $dest_inventory['id']);
            $update_dest_stmt->execute();
        } else {
            // Create new inventory record in destination
            $insert_dest_query = "INSERT INTO inventory 
                                (product_id, location, quantity) 
                                VALUES (?, ?, ?)";
            $insert_dest_stmt = $db->prepare($insert_dest_query);
            $insert_dest_stmt->bindParam(1, $product_id);
            $insert_dest_stmt->bindParam(2, $to_location);
            $insert_dest_stmt->bindParam(3, $quantity);
            $insert_dest_stmt->execute();
        }
        
        // Record the transfer
        $transfer_query = "INSERT INTO inventory_transfers 
                          (product_id, from_location, to_location, quantity, transfer_date, 
                           status, notes, initiated_by) 
                          VALUES (?, ?, ?, ?, NOW(), 'completed', ?, ?)";
        $transfer_stmt = $db->prepare($transfer_query);
        $transfer_stmt->bindParam(1, $product_id);
        $transfer_stmt->bindParam(2, $from_location);
        $transfer_stmt->bindParam(3, $to_location);
        $transfer_stmt->bindParam(4, $quantity);
        $transfer_stmt->bindParam(5, $notes);
        $transfer_stmt->bindParam(6, $initiated_by);
        $transfer_stmt->execute();
        
        $transfer_id = $db->lastInsertId();
        
        // Get product name for activity log
        $product_query = "SELECT name FROM products WHERE id = ?";
        $product_stmt = $db->prepare($product_query);
        $product_stmt->bindParam(1, $product_id);
        $product_stmt->execute();
        $product_name = $product_stmt->fetch(PDO::FETCH_ASSOC)['name'];
        
        // Log activity
        $auth->logActivity(
            $_SESSION['user_id'], 
            'create', 
            'inventory_transfers', 
            "Transferred {$quantity} units of {$product_name} from {$from_location} to {$to_location}", 
            $transfer_id
        );
        
        // Commit transaction
        $db->commit();
        
        // Determine which page to redirect to based on user role
        $redirect_page = $_SESSION['role'] === 'owner' ? 'owner' : 'incharge';
        
        // Redirect back to inventory page
        header("Location: ../{$redirect_page}/inventory.php?success=transfer");
        exit;
        
    } catch(Exception $e) {
        // Rollback transaction
        if($db->inTransaction()) {
            $db->rollBack();
        }
        
        // Determine which page to redirect to based on user role
        $redirect_page = $_SESSION['role'] === 'owner' ? 'owner' : 'incharge';
        
        // Redirect back with error
        header("Location: ../{$redirect_page}/inventory.php?error=" . urlencode($e->getMessage()));
        exit;
    }
} else {
    // Not a POST request
    header('Location: ../index.php');
    exit;
}
?>