<?php
/**
 * GUGU MANAGEMENT SYSTEM USERS — official role matrix
 * Role IDs stay 1–3 for management · 4 = Member (marketplace)
 */
function guguManagementRoles(): array {
    return [
        1 => [
            'role' => 'System Administrator (Super Admin)',
            'workspace' => 'System Control Center',
            'kicker' => 'Super Admin · Global (platform owner)',
            'responsibilities' => [
                'System Controls — Mobile Money gateway, announce fee, SMS',
                'Item & Job Approvals — Mark paid then Approve nationwide posts',
                'Permission Controls — create District Managers, disable Moderators',
                'Global Financial Analytics — total platform revenue',
                'Open any District or Moderator dashboard while keeping Super Admin power',
            ],
        ],
        2 => [
            'role' => 'District Manager',
            'workspace' => 'District Operations Hub',
            'kicker' => 'Role 2 · District Manager',
            'responsibilities' => [
                'Manage regional marketplace performance in your Akarere (Gasabo, Huye, etc.)',
                'Verify local sellers in your district',
                'Approve or reject local listings / Gurisha posts',
                'Handle local reports · activate or suspend local members',
            ],
        ],
        3 => [
            'role' => 'Moderator / Support',
            'workspace' => 'Trust & Safety Desk',
            'kicker' => 'Role 3 · Moderator / Support',
            'responsibilities' => [
                'Review flagged listings (Gurisha) in the national queue',
                'Handle user support tickets and community reports',
                'Ban or suspend fraudulent member accounts',
                'Approve, flag, or reject spam / fake posts',
            ],
        ],
    ];
}

function guguManagementRoleOptions(): array {
    return [
        1 => 'System Administrator',
        2 => 'District Manager',
        3 => 'Moderator / Support',
        4 => 'Member',
    ];
}

/**
 * Render the management users matrix table (for System Control Center).
 */
function guguRenderManagementMatrix(): void {
    $roles = guguManagementRoles();
    ?>
    <section class="panel" id="management-system">
      <h3>GUGU Management System Users</h3>
      <p class="muted" style="margin:0 0 12px">Staff accounts (roles 1–3) open portals. Members (role 4) use the marketplace only.</p>
      <div class="table-wrap">
        <table class="mgmt-matrix">
          <thead>
            <tr>
              <th>Role</th>
              <th>Name</th>
              <th>Workspace Title</th>
              <th>Key Responsibilities</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($roles as $id => $r): ?>
              <tr>
                <td><strong><?= (int) $id ?></strong></td>
                <td><?= htmlspecialchars($r['role']) ?></td>
                <td><?= htmlspecialchars($r['workspace']) ?></td>
                <td>
                  <ul class="portal-duties" style="margin:0;padding-left:16px">
                    <?php foreach ($r['responsibilities'] as $item): ?>
                      <li><?= htmlspecialchars($item) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
    <?php
}
