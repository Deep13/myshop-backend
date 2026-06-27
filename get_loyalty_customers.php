<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

// Per-phone roll-up: total cards, the current (non-redeemed) card, stamp count
// on that card, and most recent activity for ordering.
$sql = "
  WITH agg AS (
    SELECT phone,
           MAX(customer_name) AS customer_name,
           COUNT(*)           AS cards_taken,
           MAX(updated_at)    AS last_activity
    FROM loyalty_cards
    GROUP BY phone
  ),
  cur AS (
    SELECT id, phone, card_number, status,
           ROW_NUMBER() OVER (PARTITION BY phone ORDER BY card_number DESC) AS rn
    FROM loyalty_cards
    WHERE status <> 'redeemed'
  )
  SELECT a.phone,
         a.customer_name,
         a.cards_taken,
         a.last_activity,
         c.id          AS active_card_id,
         c.card_number AS active_card_number,
         c.status      AS active_status,
         COALESCE((SELECT COUNT(*) FROM loyalty_stamps s WHERE s.card_id = c.id), 0) AS active_stamps
  FROM agg a
  LEFT JOIN cur c ON c.phone = a.phone AND c.rn = 1
  ORDER BY a.last_activity DESC
";

$res = $conn->query($sql);
$rows = [];
if ($res) {
  while ($r = $res->fetch_assoc()) {
    $rows[] = [
      "phone"              => $r["phone"],
      "customer_name"      => $r["customer_name"],
      "cards_taken"        => intval($r["cards_taken"]),
      "last_activity"      => $r["last_activity"],
      "active_card_id"     => $r["active_card_id"]     ? intval($r["active_card_id"])     : null,
      "active_card_number" => $r["active_card_number"] ? intval($r["active_card_number"]) : null,
      "active_status"      => $r["active_status"]      ?: null,
      "active_stamps"      => intval($r["active_stamps"]),
    ];
  }
}

echo json_encode(["status" => "success", "data" => $rows]);
