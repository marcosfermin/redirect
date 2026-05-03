<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $conn->real_escape_string($_POST['username']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = $_POST['role'];

    $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $username, $password, $role);
    if ($stmt->execute()) {
        echo "User registered. <a href='index.php'>Login here</a>";
    } else {
        echo "Registration failed: " . $conn->error;
    }
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>Register Admin</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center vh-100">
  <form method="POST" class="bg-white p-4 rounded shadow" style="min-width:300px">
    <h4 class="mb-3 text-center">Register Admin</h4>
    <div class="mb-3">
      <input type="text" name="username" class="form-control" placeholder="Username" required>
    </div>
    <div class="mb-3">
      <input type="password" name="password" class="form-control" placeholder="Password" required>
    </div>
    <input type="hidden" name="role" value="admin">
    <button class="btn btn-primary w-100">Register</button>
  </form>
</body>
</html>