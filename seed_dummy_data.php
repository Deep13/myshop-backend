<?php
/**
 * Seed Script — clears ALL data and inserts 3 months of dummy data
 * Run: php seed_dummy_data.php   (or open in browser)
 */
header("Content-Type: text/plain; charset=UTF-8");
include "db.php";

$conn->autocommit(false);

// ═══════════════════════════════════════════
// 1. CLEAR ALL TABLES
// ═══════════════════════════════════════════
$tables = [
  "invoice_payments",
  "invoice_items",
  "invoices",
  "purchase_payments",
  "purchase_bill_items",
  "purchase_bills",
  "inventory",
  "items",
  "distributors",
];
foreach ($tables as $t) {
  $conn->query("DELETE FROM $t");
  $conn->query("ALTER TABLE $t AUTO_INCREMENT = 1");
}
echo "Cleared all tables.\n";

// ═══════════════════════════════════════════
// 2. MASTER DATA — Items
// ═══════════════════════════════════════════
$itemsMaster = [
  // [name, code, hsn, mrp, sale_price, purchase_price, tax_pct, is_primary]
  ["Tata Salt 1kg",         "TATASALT1",  "25010020", 28,   28,   22,  0,  0],
  ["Aashirvaad Atta 5kg",   "ASHATTA5",   "11010000", 295,  290,  240, 5,  1],
  ["Fortune Sunflower Oil 1L","FORTUNOIL1","15121110", 165,  160,  135, 5,  1],
  ["Amul Butter 500g",      "AMULBUT500", "04051000", 280,  275,  230, 12, 1],
  ["Parle-G Biscuit 800g",  "PARLEG800",  "19053100", 100,  95,   78,  18, 1],
  ["Maggi Noodles 12pk",    "MAGGI12",    "19023010", 168,  165,  138, 18, 1],
  ["Surf Excel 1kg",        "SURFEXL1",   "34022090", 230,  225,  185, 18, 1],
  ["Vim Dishwash Bar 500g", "VIMDISH500", "34011190", 52,   50,   40,  18, 1],
  ["Dettol Soap 125g",      "DETTOL125",  "34011190", 65,   62,   48,  18, 1],
  ["Colgate MaxFresh 150g", "COLGATE150", "33061010", 120,  115,  92,  18, 1],
  ["Haldiram Namkeen 400g", "HALDNAM400", "19041090", 130,  125,  100, 12, 1],
  ["Britannia Cake 250g",   "BRITCAKE",   "19053200", 60,   58,   45,  18, 1],
  ["Clinic Plus 340ml",     "CLINPLS340", "33051010", 195,  190,  155, 18, 1],
  ["Lifebuoy Handwash 190ml","LFBHW190",  "34011190", 99,   95,   75,  18, 1],
  ["Good Day Biscuit 600g", "GOODDAY600", "19053100", 85,   82,   66,  18, 1],
  ["Red Label Tea 500g",    "REDLBL500",  "09024010", 275,  270,  225, 5,  1],
  ["Kissan Ketchup 500g",   "KISSKT500",  "21032000", 120,  115,  92,  12, 1],
  ["Madhur Sugar 5kg",      "MDHRSGR5",   "17019910", 225,  220,  190, 5,  1],
  ["Nescafe Classic 100g",  "NESCAF100",  "21011110", 350,  340,  280, 18, 1],
  ["Pepsodent 200g",        "PEPSO200",   "33061010", 108,  105,  82,  18, 1],
  ["Harpic 500ml",          "HARPIC500",  "34022090", 110,  105,  82,  18, 1],
  ["Nivea Cream 100ml",     "NIVEA100",   "33049990", 195,  190,  150, 18, 1],
  ["Amul Milk 1L",          "AMULMILK1",  "04011010", 64,   62,   52,  0,  0],
  ["Britannia Bread 400g",  "BRITBRD400", "19059040", 45,   42,   34,  5,  1],
  ["Kurkure 115g",          "KURKURE115", "19041090", 30,   28,   22,  12, 1],
  ["Lays Classic 52g",      "LAYS52",     "20052000", 20,   20,   15,  12, 1],
  ["Dabur Honey 500g",      "DABRHNY500", "04090000", 310,  299,  250, 0,  0],
  ["Everest Garam Masala 100g","EVRGM100","09109100", 85,   82,   65,  5,  1],
  ["Saffola Gold 1L",       "SAFGOLD1",   "15121110", 199,  195,  160, 5,  1],
  ["Dove Soap 100g",        "DOVE100",    "34011190", 62,   60,   46,  18, 1],
  ["Bournvita 500g",        "BRNVTA500",  "18069000", 260,  250,  205, 18, 1],
  ["Real Juice 1L",         "REALJCE1",   "20098990", 110,  105,  82,  12, 1],
  ["Whisper Ultra 8s",      "WHISP8",     "96190010", 75,   72,   56,  12, 1],
  ["Head & Shoulders 340ml","HNS340",     "33051010", 380,  370,  300, 18, 1],
  ["Close Up 150g",         "CLOSEUP150", "33061010", 98,   95,   74,  18, 1],
  ["Lizol 500ml",           "LIZOL500",   "34022090", 125,  120,  95,  18, 1],
  ["Rin Bar 250g",          "RINBAR250",  "34011190", 25,   24,   18,  18, 1],
  ["Ghadi Detergent 1kg",   "GHADI1",     "34022090", 70,   68,   54,  18, 1],
  ["Patanjali Ghee 500ml",  "PATGHEE500", "04051000", 310,  299,  250, 12, 1],
  ["Amul Cheese 200g",      "AMULCHS200", "04061000", 120,  115,  92,  12, 1],
];

$stmtItem = $conn->prepare("INSERT INTO items (name, code, hsn, mrp, sale_price, purchase_price, tax_pct, is_primary) VALUES (?,?,?,?,?,?,?,?)");
$itemIds = [];
foreach ($itemsMaster as $it) {
  $stmtItem->bind_param("sssddddi", $it[0], $it[1], $it[2], $it[3], $it[4], $it[5], $it[6], $it[7]);
  $stmtItem->execute();
  $itemIds[] = ["id" => $conn->insert_id, "name" => $it[0], "code" => $it[1], "hsn" => $it[2],
                "mrp" => $it[3], "sale" => $it[4], "purchase" => $it[5], "tax" => $it[6], "gst" => $it[7]];
}
$stmtItem->close();
echo "Inserted " . count($itemIds) . " items.\n";

// ═══════════════════════════════════════════
// 3. MASTER DATA — Distributors
// ═══════════════════════════════════════════
$distList = [
  ["Hindustan Unilever Ltd",  "27AABCH1234P1ZH", "9876543210"],
  ["ITC Limited",             "27AABCI5678Q1ZM", "9876543211"],
  ["Amul India",              "24AABCA1234R1ZP", "9876543212"],
  ["Parle Products",          "27AABCP4567S1ZK", "9876543213"],
  ["Nestle India",            "27AABCN7890T1ZJ", "9876543214"],
  ["Dabur India",             "27AABCD2345U1ZI", "9876543215"],
  ["Britannia Industries",    "27AABCB8901V1ZH", "9876543216"],
  ["Tata Consumer Products",  "27AABCT3456W1ZG", "9876543217"],
  ["Marico Limited",          "27AABCM6789X1ZF", "9876543218"],
  ["Patanjali Ayurved",       "05AABCP1234Y1ZE", "9876543219"],
];

$stmtDist = $conn->prepare("INSERT INTO distributors (name, gstin, phone) VALUES (?,?,?)");
$distIds = [];
foreach ($distList as $d) {
  $stmtDist->bind_param("sss", $d[0], $d[1], $d[2]);
  $stmtDist->execute();
  $distIds[] = ["id" => $conn->insert_id, "name" => $d[0], "gstin" => $d[1]];
}
$stmtDist->close();
echo "Inserted " . count($distIds) . " distributors.\n";

// Map items to distributors (which distributor supplies which items)
$itemDistMap = [
  0  => 7, // Tata Salt -> Tata Consumer
  1  => 7, // Atta -> Tata (Aashirvaad)
  2  => 8, // Fortune Oil -> Marico
  3  => 2, // Amul Butter -> Amul
  4  => 3, // Parle-G -> Parle
  5  => 4, // Maggi -> Nestle
  6  => 0, // Surf Excel -> HUL
  7  => 0, // Vim -> HUL
  8  => 0, // Dettol -> (using HUL for simplicity)
  9  => 0, // Colgate -> HUL
  10 => 1, // Haldiram -> ITC
  11 => 6, // Britannia Cake -> Britannia
  12 => 0, // Clinic Plus -> HUL
  13 => 0, // Lifebuoy -> HUL
  14 => 6, // Good Day -> Britannia
  15 => 7, // Red Label -> Tata Consumer
  16 => 0, // Kissan -> HUL
  17 => 7, // Sugar -> Tata
  18 => 4, // Nescafe -> Nestle
  19 => 0, // Pepsodent -> HUL
  20 => 0, // Harpic -> (HUL-like)
  21 => 0, // Nivea -> HUL
  22 => 2, // Amul Milk -> Amul
  23 => 6, // Britannia Bread -> Britannia
  24 => 1, // Kurkure -> ITC
  25 => 1, // Lays -> ITC (PepsiCo but using ITC)
  26 => 5, // Dabur Honey -> Dabur
  27 => 1, // Everest -> ITC
  28 => 8, // Saffola -> Marico
  29 => 0, // Dove -> HUL
  30 => 4, // Bournvita -> (Cadbury/Nestle)
  31 => 5, // Real Juice -> Dabur
  32 => 3, // Whisper -> (P&G using Parle slot)
  33 => 0, // Head & Shoulders -> HUL
  34 => 0, // Close Up -> HUL
  35 => 0, // Lizol -> HUL
  36 => 0, // Rin -> HUL
  37 => 0, // Ghadi -> HUL
  38 => 9, // Patanjali Ghee -> Patanjali
  39 => 2, // Amul Cheese -> Amul
];

// ═══════════════════════════════════════════
// 4. PURCHASE BILLS — 3 months
// ═══════════════════════════════════════════
$purchaseBillId = 0;
$batchCounter = 1;

// Generate purchases: ~3-5 bills per week across distributors
$startDate = "2026-01-01";
$endDate   = "2026-03-20";

$stmtPB = $conn->prepare("INSERT INTO purchase_bills (distributor_id, distributor_name, distributor_gstin, bill_no, bill_date, due_date, sub_total, tax_total, grand_total, round_off_enabled, round_off_diff, rounded_grand_total, bill_type, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)");
$stmtPBI = $conn->prepare("INSERT INTO purchase_bill_items (purchase_id, item_id, item_name, item_code, hsn, batch_no, exp_date, mrp, qty, purchase_price, sale_price, discount, tax_pct, amount, gst_flag) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
$stmtInv = $conn->prepare("INSERT INTO inventory (item_id, purchase_bill_id, batch_no, exp_date, mrp, purchase_price, sale_price, tax_pct, gst_flag, initial_qty, current_qty) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE current_qty = current_qty + VALUES(current_qty), initial_qty = initial_qty + VALUES(initial_qty), purchase_bill_id = VALUES(purchase_bill_id), mrp = VALUES(mrp), purchase_price = VALUES(purchase_price), sale_price = VALUES(sale_price)");

$purchaseBills = [];
$inventoryStock = []; // track current stock per item

$curDate = strtotime($startDate);
$endTs   = strtotime($endDate);

// Group items by distributor
$distItems = [];
foreach ($itemDistMap as $itemIdx => $distIdx) {
  $distItems[$distIdx][] = $itemIdx;
}

$billNum = 1;
while ($curDate <= $endTs) {
  // 3-5 purchase bills per week — pick random day offsets
  $weekEnd = min($curDate + 6*86400, $endTs);
  $billsThisWeek = rand(5, 8);
  $daysUsed = [];

  for ($b = 0; $b < $billsThisWeek; $b++) {
    $dayOffset = rand(0, 6);
    $billDate = min($curDate + $dayOffset * 86400, $endTs);
    $billDateStr = date("Y-m-d", $billDate);

    // Pick a random distributor
    $distIdx = array_rand($distIds);
    $dist = $distIds[$distIdx];
    $distItemsList = $distItems[$distIdx] ?? [];
    if (empty($distItemsList)) continue;

    // Pick 3-8 random items from this distributor
    $numItems = min(rand(3, 8), count($distItemsList));
    $selectedKeys = array_rand($distItemsList, $numItems);
    if (!is_array($selectedKeys)) $selectedKeys = [$selectedKeys];

    $subTotal = 0;
    $taxTotal = 0;
    $lineItems = [];

    foreach ($selectedKeys as $sk) {
      $itemIdx = $distItemsList[$sk];
      $item = $itemIds[$itemIdx];
      $qty = rand(24, 96);
      $pp = $item["purchase"];
      $amount = round($pp * $qty, 2);
      $taxPct = $item["tax"];
      $gstFlag = $item["gst"];
      $taxAmt = $gstFlag && $taxPct > 0 ? round($amount * $taxPct / 100, 2) : 0;

      $batch = "B" . str_pad($batchCounter++, 4, "0", STR_PAD_LEFT);
      $expDate = date("Y-m-d", $billDate + rand(180, 540) * 86400); // 6-18 months from bill date

      $lineItems[] = [$item, $qty, $pp, $amount, $taxPct, $gstFlag, $taxAmt, $batch, $expDate];
      $subTotal += $amount;
      $taxTotal += $taxAmt;
    }

    $grandTotal = round($subTotal + $taxTotal, 2);
    $roundedGT  = round($grandTotal);
    $roundDiff  = round($roundedGT - $grandTotal, 2);
    $billType   = "GST";
    $dueDate    = date("Y-m-d", $billDate + 30 * 86400);
    $billNoStr  = "PB-" . str_pad($billNum++, 4, "0", STR_PAD_LEFT);
    $createdAt  = $billDateStr . " " . sprintf("%02d:%02d:%02d", rand(9, 17), rand(0, 59), rand(0, 59));

    $roundEnabled = 1;
    $stmtPB->bind_param("isssssdddiddss",
      $dist["id"], $dist["name"], $dist["gstin"],
      $billNoStr, $billDateStr, $dueDate,
      $subTotal, $taxTotal, $grandTotal,
      $roundEnabled, $roundDiff, $roundedGT,
      $billType, $createdAt
    );
    $stmtPB->execute();
    $pbId = $conn->insert_id;

    foreach ($lineItems as $li) {
      [$item, $qty, $pp, $amount, $taxPct, $gstFlag, $taxAmt, $batch, $expDate] = $li;
      $discount = "";
      $stmtPBI->bind_param("iisssssddddsddi",
        $pbId, $item["id"], $item["name"], $item["code"], $item["hsn"],
        $batch, $expDate, $item["mrp"], $qty, $pp, $item["sale"],
        $discount, $taxPct, $amount, $gstFlag
      );
      $stmtPBI->execute();

      // Update inventory
      $stmtInv->bind_param("iissddddidd",
        $item["id"], $pbId, $batch, $expDate,
        $item["mrp"], $pp, $item["sale"], $taxPct, $gstFlag,
        $qty, $qty
      );
      $stmtInv->execute();

      // Track stock locally
      if (!isset($inventoryStock[$item["id"]])) $inventoryStock[$item["id"]] = 0;
      $inventoryStock[$item["id"]] += $qty;
    }

    $purchaseBills[] = [
      "id" => $pbId, "total" => $roundedGT, "paid" => 0,
      "dist_id" => $dist["id"], "date" => $billDateStr,
    ];
  }

  $curDate += 7 * 86400; // next week
}
$stmtPB->close();
$stmtPBI->close();
$stmtInv->close();
echo "Inserted " . count($purchaseBills) . " purchase bills.\n";

// ═══════════════════════════════════════════
// 5. PURCHASE PAYMENTS — pay ~70% of bills fully, rest partial/unpaid
// ═══════════════════════════════════════════
$stmtPP = $conn->prepare("INSERT INTO purchase_payments (distributor_id, purchase_id, pay_date, mode, amount, reference_no, note, created_at) VALUES (?,?,?,?,?,?,?,?)");
$modes = ["Cash", "UPI", "Bank", "Cheque"];
$ppCount = 0;

foreach ($purchaseBills as &$pb) {
  $r = rand(1, 100);
  if ($r <= 55) {
    // Fully paid
    $payAmt = $pb["total"];
  } elseif ($r <= 80) {
    // Partial pay
    $payAmt = round($pb["total"] * rand(30, 70) / 100, 2);
  } else {
    // Unpaid
    $payAmt = 0;
  }

  if ($payAmt > 0) {
    $mode = $modes[array_rand($modes)];
    $payDate = date("Y-m-d", strtotime($pb["date"]) + rand(0, 15) * 86400);
    $ref = $mode === "UPI" ? "UPI" . rand(100000, 999999) : ($mode === "Cheque" ? "CHQ" . rand(10000, 99999) : "");
    $note = "";
    $cat = $payDate . " " . sprintf("%02d:%02d:%02d", rand(9, 17), rand(0, 59), rand(0, 59));

    $stmtPP->bind_param("iissdsss",
      $pb["dist_id"], $pb["id"], $payDate, $mode, $payAmt, $ref, $note, $cat
    );
    $stmtPP->execute();
    $pb["paid"] = $payAmt;
    $ppCount++;
  }
}
unset($pb);
$stmtPP->close();
echo "Inserted $ppCount purchase payments.\n";

// ═══════════════════════════════════════════
// 6. SALES INVOICES — 3 months
// ═══════════════════════════════════════════
$customerNames = [
  "Rajesh Kumar", "Priya Sharma", "Amit Patel", "Sunita Devi", "Vikram Singh",
  "Neha Gupta", "Rohit Verma", "Anjali Mishra", "Suresh Yadav", "Kavita Joshi",
  "Deepak Tiwari", "Pooja Agarwal", "Manoj Pandey", "Rekha Sinha", "Arun Nair",
  "Geeta Chauhan", "Ramesh Dubey", "Suman Thakur", "Kiran Reddy", "Anita Saxena",
  "Rahul Mehta", "Swati Kulkarni", "Vijay Malhotra", "Meena Iyer", "Nitin Jain",
  "Cash", "Cash", "Cash", "Cash", "Cash", // ~30% cash customers
];
$phones = [];
for ($i = 0; $i < 25; $i++) {
  $phones[$i] = "98" . rand(10000000, 99999999);
}
for ($i = 25; $i < 30; $i++) {
  $phones[$i] = "";
}

$stmtSI = $conn->prepare("INSERT INTO invoices (invoice_no, invoice_date, customer_type, customer_name, phone, subtotal, bill_discount, bill_discount_value, final_total, round_off_enabled, rounded_final_total, round_off_diff, received, balance, updated_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)");
$stmtSII = $conn->prepare("INSERT INTO invoice_items (invoice_id, item_id, item_name, item_code, hsn, batch_no, exp_date, mrp, qty, price, discount, tax, amount, gst_flag) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
$stmtSP = $conn->prepare("INSERT INTO invoice_payments (invoice_id, pay_type, amount) VALUES (?,?,?)");
// Update inventory on sale
$stmtInvUpd = $conn->prepare("UPDATE inventory SET current_qty = current_qty - ? WHERE item_id = ? AND current_qty >= ? ORDER BY exp_date ASC LIMIT 1");

$invoiceNum = 1;
$invoiceCount = 0;
$paymentCount = 0;

$curDate = strtotime($startDate);
while ($curDate <= $endTs) {
  // Weekdays: 8-15 bills, weekends: 12-22 bills
  $dow = date("N", $curDate);
  $isWeekend = ($dow >= 6);
  $billsToday = $isWeekend ? rand(12, 22) : rand(8, 15);
  $dateStr = date("Y-m-d", $curDate);

  for ($b = 0; $b < $billsToday; $b++) {
    $custIdx = rand(0, count($customerNames) - 1);
    $custName = $customerNames[$custIdx];
    $custPhone = $phones[$custIdx];
    $custType = "Retail";

    // Pick 1-8 items per bill
    $numItems = rand(1, 8);
    $pickedItems = [];
    $attempts = 0;
    while (count($pickedItems) < $numItems && $attempts < 20) {
      $idx = rand(0, count($itemIds) - 1);
      $item = $itemIds[$idx];
      if (!isset($pickedItems[$item["id"]]) && ($inventoryStock[$item["id"]] ?? 0) > 0) {
        $maxQty = min(5, $inventoryStock[$item["id"]]);
        $qty = rand(1, max(1, $maxQty));
        $pickedItems[$item["id"]] = ["item" => $item, "qty" => $qty];
      }
      $attempts++;
    }
    if (empty($pickedItems)) continue;

    $subtotal = 0;
    $lineData = [];
    foreach ($pickedItems as $pi) {
      $item = $pi["item"];
      $qty = $pi["qty"];
      $price = $item["sale"];
      $amount = round($price * $qty, 2);
      $subtotal += $amount;
      $lineData[] = ["item" => $item, "qty" => $qty, "price" => $price, "amount" => $amount];
    }

    // Random small discount occasionally
    $discType = "";
    $discValue = 0;
    $finalTotal = $subtotal;
    if (rand(1, 100) <= 15) {
      $discValue = rand(1, 5) * 10; // 10-50 flat discount
      $discType = "flat";
      $finalTotal = max(0, $subtotal - $discValue);
    }

    $roundedFinal = round($finalTotal);
    $roundDiff = round($roundedFinal - $finalTotal, 2);

    // Payment: 80% fully paid, 15% partial, 5% unpaid
    $payRoll = rand(1, 100);
    if ($payRoll <= 80) {
      $received = $roundedFinal;
    } elseif ($payRoll <= 95) {
      $received = round($roundedFinal * rand(40, 80) / 100);
    } else {
      $received = 0;
    }
    $balance = round($roundedFinal - $received, 2);

    $invNo = "INV-" . str_pad($invoiceNum++, 5, "0", STR_PAD_LEFT);
    $hour = rand(8, 21);
    $minute = rand(0, 59);
    $second = rand(0, 59);
    $createdAt = $dateStr . " " . sprintf("%02d:%02d:%02d", $hour, $minute, $second);
    $roundEnabled = 1;

    $stmtSI->bind_param("ssssssdddidddds",
      $invNo, $dateStr, $custType, $custName, $custPhone,
      $subtotal, $discType, $discValue, $finalTotal,
      $roundEnabled, $roundedFinal, $roundDiff,
      $received, $balance, $createdAt
    );
    $stmtSI->execute();
    $invId = $conn->insert_id;

    // Insert line items
    foreach ($lineData as $ld) {
      $item = $ld["item"];
      $qty = $ld["qty"];
      $price = $ld["price"];
      $amount = $ld["amount"];
      $discount = "";
      $batch = ""; // sales don't always track batch
      $expDate = null;

      $stmtSII->bind_param("iisssssddsdddi",
        $invId, $item["id"], $item["name"], $item["code"], $item["hsn"],
        $batch, $batch, $item["mrp"], $qty, $price,
        $discount, $item["tax"], $amount, $item["gst"]
      );
      $stmtSII->execute();

      // Decrement inventory
      if (isset($inventoryStock[$item["id"]])) {
        $inventoryStock[$item["id"]] = max(0, $inventoryStock[$item["id"]] - $qty);
      }
      $stmtInvUpd->bind_param("dii", $qty, $item["id"], $qty);
      $stmtInvUpd->execute();
    }

    // Insert payment
    if ($received > 0) {
      $payType = ["Cash", "UPI", "Card", "Cash", "UPI"][rand(0, 4)];
      $stmtSP->bind_param("isd", $invId, $payType, $received);
      $stmtSP->execute();
      $paymentCount++;
    }

    $invoiceCount++;
  }

  $curDate += 86400; // next day
}

$stmtSI->close();
$stmtSII->close();
$stmtSP->close();
$stmtInvUpd->close();

echo "Inserted $invoiceCount sales invoices with $paymentCount payments.\n";

// ═══════════════════════════════════════════
// 7. COMMIT
// ═══════════════════════════════════════════
$conn->commit();
$conn->autocommit(true);

echo "\n✅ Seed complete! 3 months of dummy data (Jan 1 – Mar 20, 2026) inserted.\n";
echo "   Items: " . count($itemIds) . "\n";
echo "   Distributors: " . count($distIds) . "\n";
echo "   Purchase Bills: " . count($purchaseBills) . "\n";
echo "   Sales Invoices: $invoiceCount\n";
