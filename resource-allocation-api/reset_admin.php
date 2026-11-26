<?php
$newPassword = 'Admin123!';
$newHash = password_hash($newPassword, PASSWORD_DEFAULT);
echo "Hash: " . $newHash . "<br>";
echo "Password to use: " . $newPassword;

// Update directly
$conn = new mysqli('localhost', 'root', '', 'school_resource_db');
$stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE email = 'admin@school.ac.ug'");
$stmt->bind_param("s", $newHash);
if ($stmt->execute()) {
    echo "<br>Admin password updated successfully! You can now log in.";
} else {
    echo "<br>Error updating admin.";
}
