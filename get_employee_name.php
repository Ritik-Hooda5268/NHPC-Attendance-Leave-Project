<?php
$conn = new mysqli("localhost", "root", "Ritik@8685", "nhpc_db");
if ($conn->connect_error) die("Connection failed");

$empId = $_GET['employeeId'] ?? '';
$name = '';

if ($empId !== '') {
  $stmt = $conn->prepare("SELECT name FROM employees WHERE employee_id = ?");
  $stmt->bind_param("s", $empId);
  $stmt->execute();
  $result = $stmt->get_result();
  if ($row = $result->fetch_assoc()) {
    $name = $row['name'];
  }
  $stmt->close();
}
$conn->close();
echo $name;
