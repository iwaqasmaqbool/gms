<!-- incharge/purchases.php -->
<?php
session_start();
$page_title = "Material Purchases";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Set up pagination
$records_per_page = 15;
$page = isset($_GET['page']) ? $_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Get total records for pagination
$count_query = "SELECT COUNT(*) as total FROM purchases";
$count_stmt = $db->prepare($count_query);
$count_stmt->execute();
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get purchases with pagination
$query = "SELECT p.id, m.name as material_name, p.quantity, m.unit, p.unit_price, 
          p.total_amount, p.vendor_name, p.invoice_number, p.purchase_date, u.username as purchased_by
          FROM purchases p 
          JOIN raw_materials m ON p.material_id = m.id 
          JOIN users u ON p.purchased_by = u.id
          ORDER BY p.purchase_date DESC 
          LIMIT :offset, :records_per_page";

$stmt = $db->prepare($query);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':records_per_page', $records_per_page, PDO::PARAM_INT);
$stmt->execute();
?>

<div class="page-actions">
    <a href="add-purchase.php" class="button">Add New Purchase</a>
</div>

<div class="dashboard-card full-width">
    <div class="card-header">
        <h2>Material Purchases</h2>
        <div class="pagination-info">
            Showing <?php echo min(($page - 1) * $records_per_page + 1, $total_records); ?> to 
            <?php echo min($page * $records_per_page, $total_records); ?> of 
            <?php echo $total_records; ?> records
        </div>
    </div>
    <div class="card-content">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Purchase Date</th>
                    <th>Material</th>
                    <th>Quantity</th>
                    <th>Unit Price</th>
                    <th>Total Amount</th>
                    <th>Vendor</th>
                    <th>Invoice #</th>
                    <th>Purchased By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $stmt->fetch(PDO::FETCH_ASSOC)): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['purchase_date']); ?></td>
                    <td><?php echo htmlspecialchars($row['material_name']); ?></td>
                    <td><?php echo number_format($row['quantity'], 2) . ' ' . htmlspecialchars($row['unit']); ?></td>
                    <td><?php echo number_format($row['unit_price'], 2); ?></td>
                    <td><?php echo number_format($row['total_amount'], 2); ?></td>
                    <td><?php echo htmlspecialchars($row['vendor_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['invoice_number']); ?></td>
                    <td><?php echo htmlspecialchars($row['purchased_by']); ?></td>
                    <td>
                        <div class="action-buttons">
                            <a href="view-purchase.php?id=<?php echo $row['id']; ?>" class="button small">View</a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
                
                <?php if($stmt->rowCount() === 0): ?>
                <tr>
                    <td colspan="9" class="no-records">No purchases found. Click "Add New Purchase" to add one.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Pagination -->
        <?php if($total_pages > 1): ?>
        <div class="pagination">
            <?php if($page > 1): ?>
                <a href="?page=1" class="pagination-link">&laquo; First</a>
                <a href="?page=<?php echo $page - 1; ?>" class="pagination-link">&laquo; Previous</a>
            <?php endif; ?>
            
            <?php
            // Determine the range of page numbers to display
            $range = 2; // Number of pages to show on either side of the current page
            $start_page = max(1, $page - $range);
            $end_page = min($total_pages, $page + $range);
            
            // Always show first page button
            if($start_page > 1) {
                echo '<a href="?page=1" class="pagination-link">1</a>';
                if($start_page > 2) {
                    echo '<span class="pagination-ellipsis">...</span>';
                }
            }
            
            // Display the range of pages
            for($i = $start_page; $i <= $end_page; $i++) {
                if($i == $page) {
                    echo '<span class="pagination-link current">' . $i . '</span>';
                } else {
                    echo '<a href="?page=' . $i . '" class="pagination-link">' . $i . '</a>';
                }
            }
            
            // Always show last page button
            if($end_page < $total_pages) {
                if($end_page < $total_pages - 1) {
                    echo '<span class="pagination-ellipsis">...</span>';
                }
                echo '<a href="?page=' . $total_pages . '" class="pagination-link">' . $total_pages . '</a>';
            }
            ?>
            
            <?php if($page < $total_pages): ?>
                <a href="?page=<?php echo $page + 1; ?>" class="pagination-link">Next &raquo;</a>
                <a href="?page=<?php echo $total_pages; ?>" class="pagination-link">Last &raquo;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

<?php include_once '../includes/footer.php'; ?>