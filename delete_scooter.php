<?php
require_once 'includes/operations.php';
require_role(['manager','staff']);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once 'config/db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Use the delete button on the scooter inventory.');
}
require_csrf();
$id = integer_field(field('id', $_POST), 1, 2147483647);
$conn->begin_transaction();
try {
    lock_rental_operations($conn);
    $row = sql_row($conn, 'SELECT scooter_code, status FROM tbl_scooters WHERE scooter_id = ? FOR UPDATE', 'i', $id);
    if (!$row || $row['status'] === 'rented'
        || sql_row($conn, 'SELECT rental_id FROM tbl_rentals WHERE scooter_id = ? LIMIT 1', 'i', $id)
        || sql_row($conn, "SELECT booking_id FROM tbl_bookings WHERE (scooter_id = ? OR preferred_stall =
            (SELECT stall_location FROM tbl_scooters WHERE scooter_id = ?))
            AND booking_status IN ('pending','confirmed') AND TIMESTAMPADD(MINUTE,30,reservation_date) > UTC_TIMESTAMP()
            LIMIT 1", 'ii', $id, $id)) {
        throw new DomainException('The scooter is unavailable for deletion. Keep scooters with rental history or open stall reservations in inventory.');
    }
    sql_run($conn, 'DELETE FROM tbl_scooters WHERE scooter_id = ?', 'i', $id);
    audit_action($conn, 'Deleted scooter ' . $row['scooter_code']);
    $conn->commit();
    redirect_to('manager_scooter_inventory.php', 'Scooter deleted.');
} catch (DomainException $e) {
    $conn->rollback();
    redirect_to('manager_scooter_inventory.php', $e->getMessage());
} catch (mysqli_sql_exception $e) {
    $conn->rollback();
    error_log($e->getMessage());
    redirect_to('manager_scooter_inventory.php', 'The scooter could not be deleted.');
}
