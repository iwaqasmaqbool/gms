<!-- api/save-payment.php -->
<?php
// Start session if not already started
if(session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include database config
include_once '../config/database.php';
include_once '../config/auth.php';

// Ensure user is logged in and is a shopkeeper
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'shopkeeper') {
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
        // Start transaction
        $db->beginTransaction();
        
        // Get form data
        $sale_id = $_POST['sale_id'];
        $amount = $_POST['amount'];
        $payment_date = $_POST['payment_date'];
        $payment_method = $_POST['payment_method'];
        $reference_number = $_POST['reference_number'] ?? null;
        $notes = $_POST['notes'] ?? null;
        $recorded_by = $_SESSION['user_id'];
        
        // Validate sale exists and get current details
        $sale_query = "SELECT net_amount, payment_status FROM sales WHERE id = ?";
        $sale_stmt = $db->prepare($sale_query);
        $sale_stmt->bindParam(1, $sale_id);
        $sale_stmt->execute();
        
        if($sale_stmt->rowCount() === 0) {
            throw new Exception("Sale not found.");
        }
        
        $sale = $sale_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get total payments already made
        $payments_query = "SELECT COALESCE(SUM(amount), 0) as total_paid FROM payments WHERE sale_id = ?";
        $payments_stmt = $db->prepare($payments_query);
        $payments_stmt->bindParam(1, $sale_id);
        $payments_stmt->execute();
        $total_paid = $payments_stmt->fetch(PDO::FETCH_ASSOC)['total_paid'];
        
        // Calculate remaining balance
        $balance = $sale['net_amount'] - $total_paid;
        
        // Validate payment amount
        if($amount <= 0 || $amount > $balance) {
            throw new Exception("Invalid payment amount. Amount must be greater than 0 and not exceed the remaining balance.");
        }
        
        // Insert payment record
        $payment_query = "INSERT INTO payments (sale_id, amount, payment_date, payment_method, reference_number, notes, recorded_by) 
                         VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $payment_stmt = $db->prepare($payment_query);
        $payment_stmt->bindParam(1, $sale_id);
        $payment_stmt->bindParam(2, $amount, PDO::PARAM_STR);
        $payment_stmt->bindParam(3, $payment_date);
        $payment_stmt->bindParam(4, $payment_method);
        $payment_stmt->bindParam(5, $reference_number);
        $payment_stmt->bindParam(6, $notes);
        $payment_stmt->bindParam(7, $recorded_by);
        
        $payment_stmt->execute();
        
        // Update sale payment status
        $new_total_paid = $total_paid + $amount;
        $new_payment_status = ($new_total_paid >= $sale['net_amount']) ? 'paid' : 'partial';
        
        $update_sale_query = "UPDATE sales SET payment_status = ? WHERE id = ?";
        $update_sale_stmt = $db->prepare($update_sale_query);
        $update_sale_stmt->bindParam(1, $new_payment_status);
        $update_sale_stmt->bindParam(2, $sale_id);
        $update_sale_stmt->execute();
        
        // Log activity
        $auth->logActivity(
            $_SESSION['user_id'], 
            'create', 
            'payments', 
            "Recorded payment of $amount for sale #$sale_id", 
            $sale_id
        );
        
        // Commit transaction
        $db->commit();
        
        // Redirect to sale view page
        header('Location: ../shopkeeper/view-sale.php?id=' . $sale_id . '&success=2');
        exit;
        
    } catch(Exception $e) {
        // Rollback transaction on error
        $db->rollBack();
        
        // Redirect back with error
        header('Location: ../shopkeeper/add-payment.php?sale_id=' . $_POST['sale_id'] . '&error=' . urlencode($e->getMessage()));
        exit;
    }
} else {
    // Not a POST request
    header('Location: ../shopkeeper/sales.php');
    exit;
}
?>