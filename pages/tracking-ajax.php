<?php
$uid = (int) $_SESSION['user_id'];
$orderId = (int) ($_GET['id'] ?? 0);

if ($orderId <= 0) {
    echo '<div style="padding:1.5rem; color:#666;">Invalid order ID.</div>';
    exit;
}

// Verify order belongs to user
$st = $app->pdo->prepare('SELECT o.*, s.name as shop_name FROM orders o JOIN shops s ON o.shop_id = s.id WHERE o.id = ? AND s.user_id = ?');
$st->execute([$orderId, $uid]);
$order = $st->fetch();

// Initial tracking data
$trackingHistory = [];
$livreur = $order['livreur'] ?? null;
$livreurTel = $order['livreur_tel'] ?? null;
$liveStatus = $order['dropfor_status'] ?: ($order['intigo_status'] ?: ($order['fiabilo_status'] ?: 'En attente'));

// Default progress state
$progressStep = 1;
$isRefused = false;

// Fetch live FIABILO tracking if applicable
$trackingToken = FiabiloHelper::resolveTrackingToken($app->pdo, $uid, $app->app['encryption_key'] ?? '');
if ($trackingToken && !empty($order['fiabilo_tracking_code'])) {
    $statusRes = FiabiloHelper::getStatus($trackingToken, $order['fiabilo_tracking_code']);
    if (isset($statusRes['etat'])) {
        $liveStatus = $statusRes['etat'];
        $trackingHistory = $statusRes['historique'] ?? [];
        $livreur = $statusRes['livreur'] ?? $livreur;
        $livreurTel = $statusRes['livreur_tel'] ?? $livreurTel;
        FiabiloHelper::applyStatusUpdate($app->pdo, $orderId, $liveStatus, $order['fiabilo_status'] ?? null);
        $order['fiabilo_status'] = $liveStatus;
    }
}

// Fetch live INTIGO tracking if applicable
if (!empty($order['intigo_tracking_code'])) {
    $stInt = $app->pdo->prepare('SELECT add_token_encrypted, tracking_token_encrypted FROM user_integrations WHERE user_id = ? AND provider = ?');
    $stInt->execute([$uid, 'intigo']);
    $intigoInt = $stInt->fetch();
    if ($intigoInt && !empty($intigoInt['add_token_encrypted']) && !empty($intigoInt['tracking_token_encrypted'])) {
        $apiKey = IntigoHelper::decrypt($intigoInt['add_token_encrypted'], $app->app['encryption_key'] ?? '');
        $merchantId = IntigoHelper::decrypt($intigoInt['tracking_token_encrypted'], $app->app['encryption_key'] ?? '');
        if ($apiKey && $merchantId) {
            $isSandbox = ($intigoInt['api_mode'] ?? 'prod') === 'sandbox';
            $statusRes = IntigoHelper::getTrackingStatus($order['intigo_tracking_code'], $apiKey, $merchantId, $isSandbox);
            if (isset($statusRes['status'])) {
                $statusNum = (int) $statusRes['status'];
                $liveStatus = IntigoHelper::getStatusLabel($statusNum);
                $trackingHistory = [['etat' => $liveStatus, 'date' => date('Y-m-d H:i:s')]]; 
                
                // Detailed progress mapping
                if ($statusNum === IntigoHelper::STATUS_DELIVERED) {
                    $progressStep = 4;
                } elseif ($statusNum === IntigoHelper::STATUS_SHIPPING) {
                    $progressStep = 3;
                } elseif (in_array($statusNum, [IntigoHelper::STATUS_IN_HUB, IntigoHelper::STATUS_IN_TRANSIT, IntigoHelper::STATUS_TRANSFER_HUB])) {
                    $progressStep = 2;
                } elseif ($statusNum === IntigoHelper::STATUS_ASSIGNED) {
                    $progressStep = 1;
                } elseif (in_array($statusNum, [IntigoHelper::STATUS_RETURN_DEFINITIVE, IntigoHelper::STATUS_CANCELLED_ADMIN, IntigoHelper::STATUS_LOST])) {
                    $isRefused = true;
                    $progressStep = 3; 
                }
            }
        }
    }
}

if (!empty($order['dropfor_tracking_code'])) {
    $token = DropforHelper::resolveToken($app->pdo, $uid, $app->app['encryption_key'] ?? '');
    if ($token !== '') {
        $statusRes = DropforHelper::getStatus($order['dropfor_tracking_code'], $token);
        if (isset($statusRes['status'])) {
            $liveStatus = $statusRes['status'];
            DropforHelper::applyStatusUpdate($app->pdo, $orderId, $liveStatus, $order['dropfor_status'] ?? null, $statusRes['payment'] ?? '');
            $order['dropfor_status'] = $liveStatus;
            $trackingHistory = [['etat' => $liveStatus, 'date' => date('Y-m-d H:i:s')]];
            $lower = mb_strtolower($liveStatus);
            if (str_contains($lower, 'livr')) {
                $progressStep = 4;
            } elseif (str_contains($lower, 'cours')) {
                $progressStep = 3;
            } elseif (str_contains($lower, 'depot') || str_contains($lower, 'dépôt')) {
                $progressStep = 2;
            } elseif (str_contains($lower, 'retour')) {
                $isRefused = true;
                $progressStep = 3;
            }
        }
    }
}

$trackingCode = !empty($order['dropfor_tracking_code']) ? $order['dropfor_tracking_code'] : (!empty($order['intigo_tracking_code']) ? $order['intigo_tracking_code'] : ($order['fiabilo_tracking_code'] ?? ''));

// Map shipping statuses to 4-step progress (FALLBACK for Fiabilo or when not overridden by Intigo logic above)
if (empty($order['intigo_tracking_code']) && empty($order['dropfor_tracking_code'])) {
    $status = $liveStatus;
    $statusLower = mb_strtolower($status);
    $deliveredStatuses = ['livré', 'livrés', 'livrer', 'delivered', 'reçu', 'livree'];
    $shippingStatuses = ['en cours', 'en cours de livraison', 'expédié', 'shipping', 'shipped', 'en livraison'];
    $warehouseStatuses = ['au magasin', 'magasin', 'entrepôt', 'depot', 'warehouse', 'aramé', 'au depot'];

    if (in_array($statusLower, $deliveredStatuses)) {
        $progressStep = 4;
    } elseif (in_array($statusLower, $shippingStatuses)) {
        $progressStep = 3;
    } elseif (in_array($statusLower, $warehouseStatuses)) {
        $progressStep = 2;
    }

    $refusedStatuses = ['Rtn definitif', 'Rtn client/agence', 'Retour Expediteur', 'Retour', 'Refusé', 'Refuse', 'A verifier', 'Rtn depot', 'Retour recu'];
    $isRefused = in_array($status, $refusedStatuses);
}

$steps = [
    ['id' => 1, 'label' => 'Pickup', 'icon' => 'hourglass', 'color' => '#F59E0B'],
    ['id' => 2, 'label' => 'Warehouse', 'icon' => 'building', 'color' => '#8B5CF6'],
    ['id' => 3, 'label' => $isRefused ? 'Refused' : 'Shipping', 'icon' => $isRefused ? 'x-circle' : 'truck', 'color' => $isRefused ? '#EF4444' : '#3B82F6'],
    ['id' => 4, 'label' => 'Delivered', 'icon' => 'package', 'color' => '#10B981']
];

if ($isRefused) {
    $progressStep = 3; // Stay at step 3 but it will be red
}

echo '<div class="tracking-modal-header" style="padding: 1.5rem 1.5rem 0.5rem; text-align: center;">
    <h3 style="margin:0; font-size:1.25rem; font-weight:800; color:#1e293b;">Order #' . htmlspecialchars($order['name']) . '</h3>
    <code style="display:inline-block; margin-top:0.25rem; background:#f1f5f9; border:none; padding:4px 10px; border-radius:6px; font-size:0.875rem; color:#64748b; font-weight:600;">' . htmlspecialchars($trackingCode) . '</code>
</div>

<div class="tracking-modal-body" style="padding: 2rem 1.5rem;">
    <!-- Visual Progress Bar -->
    <div class="tracking-progress-container" style="display: flex; justify-content: space-between; position: relative; margin-bottom: 2.5rem; padding: 0 1rem;">
        <div class="progress-line" style="position: absolute; top: 22px; left: 10%; right: 10%; height: 4px; background: #e2e8f0; z-index: 1;">
            <div class="progress-line-fill" style="height: 100%; background: ' . ($isRefused ? '#EF4444' : '#10b981') . '; transition: width 0.5s ease; width: ' . (($progressStep - 1) * 33.33) . '%;"></div>
        </div>';

foreach ($steps as $step) {
    $isActive = $progressStep >= $step['id'];
    $isCurrent = $progressStep === $step['id'];
    $iconColor = $isActive ? $step['color'] : '#94a3b8';
    $primaryColor = $isActive ? ($isRefused && $step['id'] === 3 ? '#EF4444' : '#10b981') : '#94a3b8';
    $bgColor = $isActive ? ($isCurrent ? $step['color'] : $primaryColor) : '#fff';
    $borderColor = $isActive ? ($isCurrent ? $step['color'] : $primaryColor) : '#e2e8f0';
    $textColor = $isActive ? '#1e293b' : '#94a3b8';

    // SVG Icons
    $iconSvg = '';
    if ($step['icon'] === 'hourglass') {
        $iconSvg = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 22h14"></path><path d="M5 2h14"></path><path d="M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22"></path><path d="M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2"></path></svg>';
    } elseif ($step['icon'] === 'building') {
        $iconSvg = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"></path><path d="M9 8h1"></path><path d="M9 12h1"></path><path d="M9 16h1"></path><path d="M14 8h1"></path><path d="M14 12h1"></path><path d="M14 16h1"></path><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"></path></svg>';
    } elseif ($step['icon'] === 'truck') {
        $iconSvg = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>';
    } elseif ($step['icon'] === 'x-circle') {
        $iconSvg = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>';
    } elseif ($step['icon'] === 'package') {
        $iconSvg = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 4.27 9 5.15"></path><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"></path><path d="m3.3 7 8.7 5 8.7-5"></path><path d="M12 22V12"></path></svg>';
    }

    // Checkmark logic as per user request:
    // "if its en courc add a check under the track"
    // "if its delivred add check on the package"
    $showCheck = false;
    if ($step['id'] === 1 && $progressStep >= 1) $showCheck = true;
    if ($step['id'] === 2 && $progressStep >= 2) $showCheck = true;
    if ($step['id'] === 3 && $progressStep >= 3) $showCheck = true;
    if ($step['id'] === 4 && $progressStep >= 4) $showCheck = true;

    if ($isRefused && $step['id'] === 3) {
        $checkIcon = '<div style="position:absolute; bottom:-4px; right:-4px; background:#EF4444; color:#fff; border-radius:50%; width:16px; height:16px; display:flex; align-items:center; justify-content:center; border:2px solid #fff; box-shadow:0 2px 4px rgba(0,0,0,0.1);"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></div>';
    } else {
        $checkIcon = $showCheck ? '<div style="position:absolute; bottom:-4px; right:-4px; background:#10b981; color:#fff; border-radius:50%; width:16px; height:16px; display:flex; align-items:center; justify-content:center; border:2px solid #fff; box-shadow:0 2px 4px rgba(0,0,0,0.1);"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></div>' : '';
    }

    echo '
        <div class="step-item" style="display: flex; flex-direction: column; align-items: center; gap: 0.75rem; z-index: 2; position: relative; width: 60px;">
            <div class="step-icon" style="width: 48px; height: 48px; border-radius: 50%; background: ' . $bgColor . '; border: 3px solid ' . $borderColor . '; color: ' . ($isActive ? '#fff' : '#94a3b8') . '; display: flex; align-items: center; justify-content: center; box-shadow: ' . ($isCurrent ? '0 0 0 4px ' . $step['color'] . '40' : '0 4px 6px -1px rgba(0,0,0,0.1)') . '; transition: all 0.3s ease; position: relative;">
                ' . $iconSvg . '
                ' . $checkIcon . '
            </div>
            <span class="step-label" style="font-size: 0.75rem; font-weight: 700; color: ' . $textColor . '; text-transform: uppercase; letter-spacing: 0.025em; white-space: nowrap;">' . $step['label'] . '</span>
        </div>';
}

echo '</div>';

// Status & Driver Info
if (!empty($livreur)) {
    echo '
    <div class="driver-card" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1.25rem; display: flex; align-items: center; gap: 1rem; margin-bottom: 2rem;">
        <div class="driver-avatar" style="width: 44px; height: 44px; background: #e0f2fe; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #0369a1;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
        </div>
        <div style="flex: 1;">
            <div style="font-size: 0.75rem; color: #64748b; font-weight: 600; margin-bottom: 0.125rem; text-transform: uppercase; letter-spacing: 0.05em;">Assigned Driver</div>
            <div style="font-weight: 700; color: #1e293b; font-size: 1rem;">' . htmlspecialchars($livreur) . '</div>
            <div style="display: flex; align-items: center; gap: 0.375rem; color: #0369a1; font-size: 0.875rem; font-weight: 600; margin-top: 0.25rem;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                ' . htmlspecialchars($livreurTel ?: 'N/A') . '
            </div>
        </div>
        ' . (!empty($livreurTel) ? '<a href="tel:' . htmlspecialchars($livreurTel) . '" style="width: 40px; height: 40px; border-radius: 50%; background: #0369a1; color: #fff; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 6px -1px rgba(3, 105, 161, 0.3);"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg></a>' : '') . '
    </div>';
}

// Full History Timeline (Existing logic but stylized)
if (!empty($trackingHistory)) {
    echo '<div style="font-size: 0.75rem; color: #64748b; font-weight: 700; margin-bottom: 1rem; text-transform: uppercase; letter-spacing: 0.1em;">Detailed Logs</div>
    <div class="tracking-timeline-modal" style="position: relative;">';

    foreach ($trackingHistory as $idx => $event) {
        $isFirst = $idx === 0;
        echo '
        <div class="tracking-event-modal" style="display: flex; align-items: flex-start; gap: 1rem; margin-bottom: 1.25rem; position: relative;">
            <div class="event-dot" style="width: 8px; height: 8px; min-width: 8px; border-radius: 50%; background: ' . ($isFirst ? '#10b981' : '#cbd5e1') . '; border: none; z-index: 1; margin-top: 0.375rem; box-shadow: ' . ($isFirst ? '0 0 0 3px #d1fae5' : 'none') . ';"></div>
            <div class="event-info" style="flex: 1;">
                <div class="event-status" style="font-weight: 700; font-size: 0.875rem; color: ' . ($isFirst ? '#1e293b' : '#64748b') . '; line-height: 1.2;">' . htmlspecialchars($event['etat']) . '</div>
                <div class="event-meta" style="font-size: 0.75rem; color: #94a3b8; margin-top:0.25rem; font-family: monospace;">
                    ' . date('M d, H:i', strtotime($event['date'])) . '
                </div>
            </div>
        </div>';
    }

    echo '</div>';
} else {
    echo '<div style="padding: 1.25rem; background: #fffbeb; border: 1px solid #fde68a; border-radius: 12px; color: #92400e; font-size: 0.875rem; display: flex; align-items: flex-start; gap: 0.75rem;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-top:2px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
        <div>
            <strong style="display:block; margin-bottom:0.125rem;">No detailed history yet</strong>
            The package is currently at: <strong>' . htmlspecialchars($status) . '</strong>
        </div>
    </div>';
}

echo '</div>
<div class="tracking-modal-footer" style="padding: 1.25rem 1.5rem; border-top: 1px solid #f1f5f9; background: #fff; border-radius: 0 0 12px 12px;">
    <a href="index.php?page=order-view&id=' . $orderId . '" class="btn btn-secondary" style="width:100%; display:flex; align-items:center; justify-content:center; gap:0.5rem; font-weight:600;">
        Full Order Details
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
    </a>
</div>

<style>
.tracking-timeline-modal::before {
    content: "";
    position: absolute;
    left: 4px;
    top: 15px;
    bottom: 20px;
    width: 1px;
    background: #e2e8f0;
}
</style>';
exit;
