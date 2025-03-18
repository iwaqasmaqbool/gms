<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include database config
include_once '../config/database.php';
include_once '../config/auth.php';

// Ensure user is logged in and has appropriate role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'shopkeeper') {
    header('Location: ../index.php');
    exit;
}

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Initialize auth for activity logging
$auth = new Auth($db);

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get form data
        $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : null;
        $name = trim($_POST['name']);
        $email = isset($_POST['email']) ? trim($_POST['email']) : null;
        $phone = trim($_POST['phone']);
        $address = isset($_POST['address']) ? trim($_POST['address']) : null;
        $created_by = $_SESSION['user_id']; // Use the logged-in user's ID

        // Validate data
        if (empty($name) || empty($phone)) {
            throw new Exception("Name and phone are required.");
        }

        // Validate email format if provided
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email format.");
        }

        // Validate phone number (basic validation)
        if (!preg_match('/^[0-9]{10,15}$/', $phone)) {
            throw new Exception("Phone number must be 10-15 digits.");
        }

        // Start transaction
        $db->beginTransaction();

        if ($customer_id) {
            // Update existing customer
            $query = "UPDATE customers 
                     SET name = :name, email = :email, phone = :phone, address = :address, updated_at = NOW()
                     WHERE id = :customer_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':phone', $phone);
            $stmt->bindParam(':address', $address);
            $stmt->bindParam(':customer_id', $customer_id, PDO::PARAM_INT);
            $stmt->execute();

            // Log activity
            $auth->logActivity(
                $_SESSION['user_id'],
                'update',
                'customers',
                "Updated customer: {$name}",
                $customer_id
            );

            $message = "Customer updated successfully.";
        } else {
            // Create new customer
            $query = "INSERT INTO customers (name, email, phone, address, created_by, created_at, updated_at)
                     VALUES (:name, :email, :phone, :address, :created_by, NOW(), NOW())";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':phone', $phone);
            $stmt->bindParam(':address', $address);
            $stmt->bindParam(':created_by', $created_by, PDO::PARAM_INT);
            $stmt->execute();

            $customer_id = $db->lastInsertId();

            // Log activity
            $auth->logActivity(
                $_SESSION['user_id'],
                'create',
                'customers',
                "Created new customer: {$name}",
                $customer_id
            );

            $message = "Customer created successfully.";
        }

        // Commit transaction
        $db->commit();

        // Return JSON response for AJAX
        echo json_encode([
            'success' => true,
            'message' => $message,
            'customer_id' => $customer_id
        ]);
        exit;

    } catch (Exception $e) {
        // Rollback transaction
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        // Return JSON error response for AJAX
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
} else {
    // Not a POST request
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method.'
    ]);
    exit;
}
?>