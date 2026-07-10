<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth();

$data = mysqli_query($db, "SELECT * FROM customers ORDER BY id DESC");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Customer Hub | <?= htmlspecialchars(COMPANY_NAME) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../../assets/js/sidebar.js" defer></script>
</head>
<body>

<?php include(__DIR__ . "/../../includes/sidebar.php"); ?>

<div class="main">
    <div class="dashboard-title-row">
        <h1>Customers</h1>
        <a href="add.php" class="btn">+ Add Customer</a>
    </div>
    
    <!-- Sticky Search Bar -->
    <div class="search-bar-container">
        <input type="text" class="search" placeholder="Search customers by name, company, mobile, type...">
    </div>
    
    <hr>

    <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
        <div style="padding: 12px 20px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; 
            background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;">
            Customer saved successfully!
        </div>
    <?php endif; ?>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Customer Name</th>
                    <th>Mobile</th>
                    <th>Email</th>
                    <th>Address</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (mysqli_num_rows($data) > 0): ?>
                    <?php while ($row = mysqli_fetch_assoc($data)): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row["id"]); ?></td>
                        <td style="font-weight: 600;"><?php echo htmlspecialchars($row["name"]); ?></td>
                        <td style="font-weight: 600;"><?php echo htmlspecialchars($row["mobile"] ?: '-'); ?></td>
                        <td><?php echo htmlspecialchars($row["email"] ?: '-'); ?></td>
                        <td><?php echo htmlspecialchars($row["address"] ?: '-'); ?></td>
                        <td>
                            <div class="d-flex gap-2">
                                <a href="view.php?id=<?php echo $row['id']; ?>" class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px; border-radius: 8px;">View</a>
                                <a href="edit.php?id=<?php echo $row['id']; ?>" class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px; border-radius: 8px; background: rgba(13, 40, 63, 0.05);">Edit</a>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--text-secondary); padding: 40px 0;">
                            No customers found in database. Click "Add Customer" to create one.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
