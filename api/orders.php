<?php
/**
 * GUGU App - Orders, Escrow Wallet & Mobile Money (Member Portal)
 *
 * Uses the shared GUGUapDB portal schema: orders.status, order_events.event_type,
 * escrow_ledger for money movement and disputes.opener_id.
 */

require_once __DIR__ . '/../includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['ok' => true]);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'purchases';

switch ($action) {
    case 'purchases':
        if ($method !== 'GET') jsonError('Method not allowed', 405);
        listOrders('buyer');
        break;
    case 'sales':
        if ($method !== 'GET') jsonError('Method not allowed', 405);
        listOrders('seller');
        break;
    case 'track':
        if ($method !== 'GET') jsonError('Method not allowed', 405);
        trackOrder((int) ($_GET['id'] ?? 0));
        break;
    case 'wallet':
        if ($method !== 'GET') jsonError('Method not allowed', 405);
        getWallet();
        break;
    case 'analytics':
        if ($method !== 'GET') jsonError('Method not allowed', 405);
        sellerAnalytics();
        break;
    case 'create':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        createOrder();
        break;
    case 'pay':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        payOrder();
        break;
    case 'meetup':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        markMeetup();
        break;
    case 'confirm':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        confirmOrder();
        break;
    case 'cancel':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        cancelOrder();
        break;
    case 'dispute':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        openDispute();
        break;
    default:
        jsonError('Invalid action', 404);
}

function orderStatusLabel(string $status): string {
    return match ($status) {
        'pending' => 'Byatumijwe',
        'agreed' => 'Guhura kwateganijwe',
        'paid' => 'Amafaranga ari muri escrow',
        'completed' => 'Byarangiye',
        'cancelled' => 'Byahagaritswe',
        'refunded' => 'Amafaranga yasubijwe',
        'disputed' => 'Hari ikibazo',
        default => $status,
    };
}

function eventLabel(string $eventType): string {
    return match ($eventType) {
        'placed' => 'Itumizwa ryakozwe',
        'paid' => 'Amafaranga yashyizwe muri escrow',
        'agreed' => 'Guhura kwateganijwe',
        'completed' => 'Igicuruzwa cyakiriwe',
        'cancelled' => 'Itumizwa ryahagaritswe',
        'disputed' => 'Ikibazo cyatanzwe',
        default => $eventType,
    };
}

function decorateOrder(array $o): array {
    $o['amount_formatted'] = formatPrice((int) $o['amount']);
    $o['time_ago'] = timeAgo($o['created_at']);
    $o['status_label'] = orderStatusLabel($o['status']);
    $o['escrow_status'] = orderEscrowStatus((int) $o['id']);
    if (!empty($o['primary_image'])) {
        $o['primary_image'] = UPLOAD_URL . $o['primary_image'];
    }
    return $o;
}

function fetchOrderFor(int $orderId, array $user, string $side = 'any'): array {
    if (!$orderId) jsonError('Order ID required');
    $db = getDB();
    $stmt = $db->prepare('
        SELECT o.*, l.title as listing_title, l.district as listing_district,
               b.full_name as buyer_name, b.phone as buyer_phone,
               s.full_name as seller_name, s.phone as seller_phone,
               (SELECT image_path FROM listing_images WHERE listing_id = o.listing_id AND is_primary = 1 LIMIT 1) as primary_image
        FROM orders o
        JOIN listings l ON l.id = o.listing_id
        JOIN users b ON b.id = o.buyer_id
        JOIN users s ON s.id = o.seller_id
        WHERE o.id = ?
    ');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) jsonError('Iri tumizwa ntiribonetse', 404);

    $isBuyer = (int) $order['buyer_id'] === (int) $user['id'];
    $isSeller = (int) $order['seller_id'] === (int) $user['id'];

    if ($side === 'buyer' && !$isBuyer) jsonError('Ntabwo ari itumizwa ryawe', 403);
    if ($side === 'seller' && !$isSeller) jsonError('Ntabwo ari igicuruzwa cyawe', 403);
    if (!$isBuyer && !$isSeller) jsonError('Ntabwo ari itumizwa ryawe', 403);

    $order['is_buyer'] = $isBuyer;
    $order['is_seller'] = $isSeller;
    $order['escrow_status'] = orderEscrowStatus($orderId);
    return $order;
}

function addOrderEvent(int $orderId, ?int $actorId, string $eventType, ?string $note = null): void {
    getDB()->prepare('INSERT INTO order_events (order_id, actor_id, event_type, note) VALUES (?, ?, ?, ?)')
           ->execute([$orderId, $actorId, $eventType, $note]);
}

function listOrders(string $side): void {
    $user = requireAuth();
    $db = getDB();

    $column = $side === 'seller' ? 'o.seller_id' : 'o.buyer_id';
    $params = [$user['id']];
    $filter = '';

    if (!empty($_GET['status']) && in_array($_GET['status'], ['pending', 'agreed', 'paid', 'completed', 'cancelled', 'refunded', 'disputed'], true)) {
        $filter = ' AND o.status = ?';
        $params[] = $_GET['status'];
    }

    $stmt = $db->prepare("
        SELECT o.*, l.title as listing_title, l.is_free, l.district as listing_district,
               b.full_name as buyer_name, s.full_name as seller_name,
               (SELECT image_path FROM listing_images WHERE listing_id = o.listing_id AND is_primary = 1 LIMIT 1) as primary_image
        FROM orders o
        JOIN listings l ON l.id = o.listing_id
        JOIN users b ON b.id = o.buyer_id
        JOIN users s ON s.id = o.seller_id
        WHERE $column = ?$filter
        ORDER BY o.created_at DESC
        LIMIT 100
    ");
    $stmt->execute($params);

    $orders = array_map('decorateOrder', $stmt->fetchAll());
    jsonResponse(['success' => true, 'orders' => $orders]);
}

function trackOrder(int $orderId): void {
    $user = requireAuth();
    $order = fetchOrderFor($orderId, $user);

    $stmt = getDB()->prepare('
        SELECT e.*, u.full_name as actor_name
        FROM order_events e
        LEFT JOIN users u ON u.id = e.actor_id
        WHERE e.order_id = ?
        ORDER BY e.created_at ASC, e.id ASC
    ');
    $stmt->execute([$orderId]);
    $events = $stmt->fetchAll();
    foreach ($events as &$e) {
        $e['time_ago'] = timeAgo($e['created_at']);
        $e['status_label'] = eventLabel($e['event_type']);
    }

    jsonResponse(['success' => true, 'order' => decorateOrder($order), 'events' => $events]);
}

function getWallet(): void {
    $user = requireAuth();
    $summary = walletSummary((int) $user['id']);

    $stmt = getDB()->prepare('
        SELECT w.*, l.title as listing_title
        FROM escrow_ledger w
        LEFT JOIN orders o ON o.id = w.order_id
        LEFT JOIN listings l ON l.id = o.listing_id
        WHERE w.user_id = ?
        ORDER BY w.created_at DESC
        LIMIT 60
    ');
    $stmt->execute([$user['id']]);
    $transactions = $stmt->fetchAll();
    foreach ($transactions as &$t) {
        $t['amount_formatted'] = formatPrice((int) $t['amount']);
        $t['time_ago'] = timeAgo($t['created_at']);
        $t['type'] = $t['direction'];
        $t['flow'] = in_array($t['direction'], ['release', 'refund', 'credit'], true) ? 'in' : 'out';
    }

    foreach (array_keys($summary) as $key) {
        $summary[$key . '_formatted'] = formatPrice((int) $summary[$key]);
    }

    jsonResponse([
        'success' => true,
        'wallet' => $summary,
        'transactions' => $transactions,
        'escrow_enabled' => getSetting('escrow_enabled', '1') === '1',
    ]);
}

function sellerAnalytics(): void {
    $user = requireAuth();
    $db = getDB();

    $totals = [];

    $stmt = $db->prepare('SELECT COUNT(*) FROM listings WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $totals['listings'] = (int) $stmt->fetchColumn();

    $stmt = $db->prepare('SELECT COUNT(*) FROM listings WHERE user_id = ? AND status = "active"');
    $stmt->execute([$user['id']]);
    $totals['active'] = (int) $stmt->fetchColumn();

    $stmt = $db->prepare('SELECT COUNT(*) FROM listings WHERE user_id = ? AND status = "sold"');
    $stmt->execute([$user['id']]);
    $totals['sold'] = (int) $stmt->fetchColumn();

    $stmt = $db->prepare('SELECT COALESCE(SUM(view_count), 0), COALESCE(SUM(like_count), 0) FROM listings WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    [$views, $likes] = $stmt->fetch(PDO::FETCH_NUM);
    $totals['views'] = (int) $views;
    $totals['likes'] = (int) $likes;

    $stmt = $db->prepare('
        SELECT COALESCE(SUM(amount), 0) FROM escrow_ledger
        WHERE user_id = ? AND direction = "release" AND status = "success"
    ');
    $stmt->execute([$user['id']]);
    $totals['revenue'] = (int) $stmt->fetchColumn();
    $totals['revenue_formatted'] = formatPrice($totals['revenue']);

    $stmt = $db->prepare('SELECT COUNT(*) FROM orders WHERE seller_id = ?');
    $stmt->execute([$user['id']]);
    $totals['orders'] = (int) $stmt->fetchColumn();

    $stmt = $db->prepare('
        SELECT DATE_FORMAT(created_at, "%Y-%m") as label, COUNT(*) as value
        FROM orders WHERE seller_id = ?
        GROUP BY label ORDER BY label DESC LIMIT 6
    ');
    $stmt->execute([$user['id']]);
    $monthly = array_reverse($stmt->fetchAll());

    $stmt = $db->prepare('
        SELECT l.title as label, l.view_count as value
        FROM listings l WHERE l.user_id = ?
        ORDER BY l.view_count DESC LIMIT 5
    ');
    $stmt->execute([$user['id']]);
    $topListings = $stmt->fetchAll();

    jsonResponse([
        'success' => true,
        'totals' => $totals,
        'monthly_orders' => $monthly,
        'top_listings' => $topListings,
    ]);
}

function createOrder(): void {
    $user = requireAuth();
    $db = getDB();
    $data = getJsonInput();

    $listingId = (int) ($data['listing_id'] ?? 0);
    if (!$listingId) jsonError('Listing ID required');

    $stmt = $db->prepare('SELECT * FROM listings WHERE id = ?');
    $stmt->execute([$listingId]);
    $listing = $stmt->fetch();
    if (!$listing) jsonError('Igicuruzwa ntikibonetse', 404);

    if ((int) $listing['user_id'] === (int) $user['id']) {
        jsonError('Ntushobora kugura igicuruzwa cyawe');
    }
    if ($listing['status'] === 'sold') {
        jsonError('Iki gicuruzwa cyamaze kugurishwa');
    }

    $existing = $db->prepare('
        SELECT id FROM orders
        WHERE listing_id = ? AND buyer_id = ? AND status IN ("pending", "agreed", "paid")
    ');
    $existing->execute([$listingId, $user['id']]);
    if ($row = $existing->fetch()) {
        jsonResponse(['success' => true, 'order_id' => (int) $row['id'], 'message' => 'Usanzwe ufite itumizwa kuri iki gicuruzwa']);
    }

    $db->prepare('
        INSERT INTO orders (listing_id, buyer_id, seller_id, amount, track_code, notes)
        VALUES (?, ?, ?, ?, ?, ?)
    ')->execute([
        $listingId,
        $user['id'],
        $listing['user_id'],
        (int) $listing['price'],
        generateTrackCode(),
        trim($data['note'] ?? '') ?: null,
    ]);

    $orderId = (int) $db->lastInsertId();
    addOrderEvent($orderId, (int) $user['id'], 'placed', 'Umuguzi yatumije igicuruzwa');

    notify(
        (int) $listing['user_id'],
        'order',
        'Ufite itumizwa rishya',
        $user['full_name'] . ' yatumije: ' . $listing['title'],
        'order',
        $orderId
    );

    jsonResponse(['success' => true, 'order_id' => $orderId, 'message' => 'Itumizwa ryakozwe'], 201);
}

/**
 * Sandbox MTN MoMo / Airtel Money collection that moves funds into escrow.
 */
function payOrder(): void {
    $user = requireAuth();
    $db = getDB();
    $data = getJsonInput();

    $order = fetchOrderFor((int) ($data['order_id'] ?? 0), $user, 'buyer');

    if ($order['escrow_status'] !== 'unpaid') {
        jsonError('Iri tumizwa ryamaze kwishyurwa');
    }
    if (in_array($order['status'], ['cancelled', 'completed', 'refunded'], true)) {
        jsonError('Iri tumizwa ntirikirakenewe');
    }

    $method = in_array($data['payment_method'] ?? '', ['mtn_momo', 'airtel_money', 'cash'], true)
        ? $data['payment_method']
        : 'mtn_momo';

    $phone = formatPhone($data['phone'] ?? $user['phone']);
    if ($method !== 'cash' && !validateRwandaPhone($phone)) {
        jsonError('Nomero ya MoMo ntabwo ari yo (+2507XXXXXXXX)');
    }

    $ref = generatePaymentRef($method);
    $amount = (int) $order['amount'];

    $db->prepare('UPDATE orders SET status = "paid" WHERE id = ?')->execute([$order['id']]);

    escrowEntry(
        (int) $user['id'],
        'hold',
        $amount,
        (int) $order['id'],
        providerForMethod($method),
        $ref,
        'Escrow hold — ' . $order['listing_title']
    );

    addOrderEvent((int) $order['id'], (int) $user['id'], 'paid', 'Amafaranga yashyizwe muri escrow (' . $ref . ')');

    $db->prepare('UPDATE listings SET status = "reserved" WHERE id = ? AND status = "active"')
       ->execute([$order['listing_id']]);

    notify(
        (int) $order['seller_id'],
        'payment',
        'Amafaranga ari muri escrow',
        $order['buyer_name'] . ' yishyuye ' . formatPrice($amount) . ' — ' . $order['listing_title'],
        'order',
        (int) $order['id']
    );

    jsonResponse([
        'success' => true,
        'message' => 'Kwishyura byagenze neza — amafaranga ari muri escrow',
        'payment_ref' => $ref,
    ]);
}

function markMeetup(): void {
    $user = requireAuth();
    $data = getJsonInput();
    $order = fetchOrderFor((int) ($data['order_id'] ?? 0), $user);

    if (!in_array($order['status'], ['pending', 'paid'], true)) {
        jsonError('Ntushobora guhindura iri tumizwa');
    }

    getDB()->prepare('UPDATE orders SET status = "agreed" WHERE id = ?')->execute([$order['id']]);
    addOrderEvent((int) $order['id'], (int) $user['id'], 'agreed', trim($data['note'] ?? '') ?: 'Guhura kwateganijwe');

    $otherId = $order['is_buyer'] ? (int) $order['seller_id'] : (int) $order['buyer_id'];
    notify($otherId, 'order', 'Guhura kwateganijwe', $order['listing_title'], 'order', (int) $order['id']);

    jsonResponse(['success' => true, 'message' => 'Guhura kwanditswe']);
}

function confirmOrder(): void {
    $user = requireAuth();
    $db = getDB();
    $data = getJsonInput();

    $order = fetchOrderFor((int) ($data['order_id'] ?? 0), $user, 'buyer');

    if ($order['status'] === 'completed') {
        jsonError('Iri tumizwa ryarangiye');
    }
    if ($order['status'] === 'disputed') {
        jsonError('Iri tumizwa rifite ikibazo kitarangira');
    }

    $amount = (int) $order['amount'];
    $db->prepare('UPDATE orders SET status = "completed" WHERE id = ?')->execute([$order['id']]);

    if ($order['escrow_status'] === 'held') {
        escrowEntry(
            (int) $order['seller_id'],
            'release',
            $amount,
            (int) $order['id'],
            'sandbox',
            null,
            'Escrow released — ' . $order['listing_title']
        );
        syncWallet((int) $order['buyer_id']);
    }

    addOrderEvent((int) $order['id'], (int) $user['id'], 'completed', 'Umuguzi yemeje ko yakiriye igicuruzwa');
    $db->prepare('UPDATE listings SET status = "sold" WHERE id = ?')->execute([$order['listing_id']]);

    notify(
        (int) $order['seller_id'],
        'payment',
        'Amafaranga yasohotse muri escrow',
        formatPrice($amount) . ' — ' . $order['listing_title'],
        'order',
        (int) $order['id']
    );

    jsonResponse(['success' => true, 'message' => 'Murakoze! Itumizwa ryarangiye']);
}

function cancelOrder(): void {
    $user = requireAuth();
    $db = getDB();
    $data = getJsonInput();

    $order = fetchOrderFor((int) ($data['order_id'] ?? 0), $user);

    if (in_array($order['status'], ['completed', 'cancelled', 'refunded'], true)) {
        jsonError('Iri tumizwa ntirishobora guhagarikwa');
    }
    if ($order['status'] === 'disputed') {
        jsonError('Iri tumizwa rifite ikibazo — tegereza umuyobozi');
    }

    $refunded = $order['escrow_status'] === 'held';

    $db->prepare('UPDATE orders SET status = ? WHERE id = ?')
       ->execute([$refunded ? 'refunded' : 'cancelled', $order['id']]);

    if ($refunded) {
        escrowEntry(
            (int) $order['buyer_id'],
            'refund',
            (int) $order['amount'],
            (int) $order['id'],
            'sandbox',
            null,
            'Escrow refund — ' . $order['listing_title']
        );
    }

    addOrderEvent((int) $order['id'], (int) $user['id'], 'cancelled', trim($data['reason'] ?? '') ?: 'Itumizwa ryahagaritswe');
    $db->prepare('UPDATE listings SET status = "active" WHERE id = ? AND status = "reserved"')
       ->execute([$order['listing_id']]);

    $otherId = $order['is_buyer'] ? (int) $order['seller_id'] : (int) $order['buyer_id'];
    notify($otherId, 'order', 'Itumizwa ryahagaritswe', $order['listing_title'], 'order', (int) $order['id']);

    jsonResponse(['success' => true, 'message' => $refunded ? 'Byahagaritswe — amafaranga yasubijwe' : 'Itumizwa ryahagaritswe']);
}

function openDispute(): void {
    $user = requireAuth();
    $db = getDB();
    $data = getJsonInput();

    $order = fetchOrderFor((int) ($data['order_id'] ?? 0), $user);
    $reason = trim($data['reason'] ?? '');
    if ($reason === '') jsonError('Sobanura impamvu y\'ikibazo');

    if (in_array($order['status'], ['cancelled', 'refunded'], true)) {
        jsonError('Iri tumizwa ryahagaritswe');
    }

    $existing = $db->prepare('SELECT id FROM disputes WHERE order_id = ? AND status IN ("open", "in_review")');
    $existing->execute([$order['id']]);
    if ($existing->fetch()) {
        jsonError('Iri tumizwa risanzwe rifite ikibazo cyatanzwe');
    }

    $db->prepare('INSERT INTO disputes (order_id, opener_id, reason, details) VALUES (?, ?, ?, ?)')
       ->execute([$order['id'], $user['id'], $reason, trim($data['description'] ?? '') ?: null]);

    $db->prepare('UPDATE orders SET status = "disputed" WHERE id = ?')->execute([$order['id']]);
    addOrderEvent((int) $order['id'], (int) $user['id'], 'disputed', $reason);

    $againstId = $order['is_buyer'] ? (int) $order['seller_id'] : (int) $order['buyer_id'];
    notify($againstId, 'dispute', 'Hari ikibazo kuri itumizwa', $order['listing_title'], 'order', (int) $order['id']);

    jsonResponse(['success' => true, 'message' => 'Ikibazo cyoherejwe ku bayobozi ba GUGU'], 201);
}
