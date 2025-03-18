<?php
// api/confirm-receipt.php
session_start();
include_once '../config/database.php';
include_once '../config/auth.php';

// Return JSON response
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not authenticated']);
    exit;
}

// Check if required parameters are provided
if (!isset($_POST['transfer_id']) || empty($_POST['transfer_id'])) {
    echo json_encode(['success' => false, 'message' => 'Transfer ID is required']);
    exit;
}

$transfer_id = $_POST['transfer_id'];
$product_id = isset($_POST['product_id']) ? $_POST['product_id'] : null;
$quantity = isset($_POST['quantity']) ? $_POST['quantity'] : null;
$notification_id = isset($_POST['notification_id']) ? $_POST['notification_id'] : null;
$notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';

try {
    // Initialize database connection
    $database = new Database();
    $db = $database->getConnection();
    
    // Start transaction
    $db->beginTransaction();
    
    // 1. Get transfer details to confirm it exists and is pending
    $transfer_query = "SELECT t.*, p.name as product_name 
                      FROM inventory_transfers t
                      JOIN products p ON t.product_id = p.id
                      WHERE t.id = ? AND t.status = 'pending'";
    $transfer_stmt = $db->prepare($transfer_query);
    $transfer_stmt->execute([$transfer_id]);
    
    if ($transfer_stmt->rowCount() === 0) {
        throw new Exception('Transfer not found or already processed');
    }
    
    $transfer = $transfer_stmt->fetch(PDO::FETCH_ASSOC);
    
    // 2. Check if inventory record exists for this product in transit
    $inventory_query = "SELECT id FROM inventory 
                       WHERE product_id = ? AND location = 'transit'";
    $inventory_stmt = $db->prepare($inventory_query);
    $inventory_stmt->execute([$transfer['product_id']]);
    
    if ($inventory_stmt->rowCount() > 0) {
        // Update existing transit record to wholesale location with shopkeeper_id
        $inventory = $inventory_stmt->fetch(PDO::FETCH_ASSOC);
        $update_query = "UPDATE inventory 
                        SET location = 'wholesale', 
                            shopkeeper_id = ?, 
                            updated_at = NOW() 
                        WHERE id = ?";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->execute([$_SESSION['user_id'], $inventory['id']]);
    } else {
        // If no transit record exists (unusual case), create a new wholesale record
        $insert_query = "INSERT INTO inventory 
                        (product_id, quantity, location, shopkeeper_id, updated_at) 
                        VALUES (?, ?, 'wholesale', ?, NOW())";
        $insert_stmt = $db->prepare($insert_query);
        $insert_stmt->execute([
            $transfer['product_id'], 
            $transfer['quantity'],
            $_SESSION['user_id']
        ]);
    }
    
    // 3. Update the transfer status
    $update_transfer_query = "UPDATE inventory_transfers 
                             SET status = 'confirmed', 
                                 confirmed_by = ?, 
                                 confirmation_date = NOW() 
                             WHERE id = ?";
    $update_transfer_stmt = $db->prepare($update_transfer_query);
    $update_transfer_stmt->execute([$_SESSION['user_id'], $transfer_id]);
    
    // 4. Mark notification as read if provided
    if ($notification_id) {
        $update_notification_query = "UPDATE notifications 
                                     SET is_read = 1 
                                     WHERE id = ? AND user_id = ?";
        $update_notification_stmt = $db->prepare($update_notification_query);
        $update_notification_stmt->execute([$notification_id, $_SESSION['user_id']]);
    }
    
    // 5. Log this activity
    $log_query = "INSERT INTO activity_logs 
                 (user_id, action_type, module, description, entity_id) 
                 VALUES (?, 'update', 'inventory', ?, ?)";
    $log_description = "Confirmed receipt of {$transfer['quantity']} units of {$transfer['product_name']}";
    if (!empty($notes)) {
        $log_description .= ". Notes: $notes";
    }
    $log_stmt = $db->prepare($log_query);
    $log_stmt->execute([$_SESSION['user_id'], $log_description, $transfer_id]);
    
    // Commit transaction
    $db->commit();
    
    // Return success response
    echo json_encode([
        'success' => true, 
        'message' => 'Inventory receipt confirmed successfully',
        'transfer_id' => $transfer_id,
        'notification_id' => $notification_id,
        'product_id' => $transfer['product_id'],
        'quantity' => $transfer['quantity']
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    // Return error response
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>