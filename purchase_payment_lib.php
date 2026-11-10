<?php
// Shared pieces for paying purchase bills: add, edit, delete and list.

const PURCHASE_PAY_MODES = ["Cash","UPI","Card","Bank","Cheque","Other"];

function pp_json($code, $arr) {
  http_response_code($code);
  echo json_encode($arr);
  exit;
}

function pp_num($v) {
  if ($v === null) return 0;
  if (is_numeric($v)) return floatval($v);
  $s = str_replace([",","₹","Rs.","INR"], "", trim((string)$v));
  $s = preg_replace('/[^0-9.]/', '', $s);
  return is_numeric($s) ? floatval($s) : 0;
}

// Who recorded or last changed a payment. ADD COLUMN commits implicitly, so
// this runs before any transaction opens.
function ensure_purchase_payment_audit($conn) {
  $res = $conn->query("SHOW COLUMNS FROM purchase_payments LIKE 'updated_at'");
  if ($res && $res->num_rows > 0) return;
  $conn->query("ALTER TABLE purchase_payments
    ADD COLUMN IF NOT EXISTS created_by INT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS updated_by INT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT NULL");
}

// One payment line from the request, checked. Returns [line, error].
function pp_clean_line($p) {
  $date = trim((string)($p["payDate"] ?? ""));
  $mode = trim((string)($p["mode"] ?? $p["type"] ?? "Cash"));
  $amt  = round(pp_num($p["amount"] ?? 0), 2);
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return [null, "Payment date is required"];
  if ($date > date("Y-m-d")) return [null, "Payment date can't be in the future"];
  if (!in_array($mode, PURCHASE_PAY_MODES, true)) return [null, "Invalid payment mode"];
  if ($amt <= 0) return [null, "Amount must be more than 0"];
  return [[
    "payDate" => $date,
    "mode" => $mode,
    "amount" => $amt,
    "referenceNo" => mb_substr(trim((string)($p["referenceNo"] ?? "")), 0, 100),
    "note" => mb_substr(trim((string)($p["note"] ?? "")), 0, 255),
  ], null];
}

// The bill's payable total, what is paid against it and what is left.
function pp_bill_summary($conn, $purchaseId) {
  $stmt = $conn->prepare("
    SELECT pb.id, pb.bill_no, pb.bill_date, pb.due_date, pb.distributor_id, pb.distributor_name,
           CASE WHEN pb.round_off_enabled = 1 THEN pb.rounded_grand_total ELSE pb.grand_total END AS total,
           COALESCE((SELECT SUM(amount) FROM purchase_payments WHERE purchase_id = pb.id), 0) AS paid
    FROM purchase_bills pb WHERE pb.id = ? LIMIT 1
  ");
  $stmt->bind_param("i", $purchaseId);
  $stmt->execute();
  $b = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$b) return null;
  $b["total"] = round(floatval($b["total"]), 2);
  $b["paid"] = round(floatval($b["paid"]), 2);
  $b["balance"] = round($b["total"] - $b["paid"], 2);
  $b["status"] = ($b["paid"] >= $b["total"] && $b["total"] > 0) ? "Paid" : ($b["paid"] > 0 ? "Partial" : "Unpaid");
  return $b;
}
