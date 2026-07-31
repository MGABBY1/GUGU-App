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
                'Approve items & jobs nationwide — Mark paid → Approve so posts go live',
                'Confirm MoMo announce fees and reject spam / fake posts',
                'System Controls — MoMo gateway, announce fee, SMS',
                'Permissions — create District Managers & Moderators; suspend staff',
                'Financials — track nationwide announce-fee revenue',
                'Reports & IDs — resolve reports; approve member ID documents',
                'Open District or Moderator dashboards while keeping Super Admin power',
            ],
        ],
        2 => [
            'role' => 'District Manager',
            'workspace' => 'District Operations Hub',
            'kicker' => 'Role 2 · District Manager',
            'responsibilities' => [
                'Manage marketplace performance in your Akarere only (Gasabo, Huye, etc.)',
                'Approve or reject local items & job announcements',
                'Verify local sellers and confirm local MoMo announce fees',
                'Handle local reports · activate or suspend local members',
            ],
        ],
        3 => [
            'role' => 'Moderator / Support',
            'workspace' => 'Trust & Safety Desk',
            'kicker' => 'Role 3 · Moderator / Support',
            'responsibilities' => [
                'Review flagged items & jobs; approve, flag, or reject spam / fakes',
                'Handle support tickets and community reports',
                'Review member ID documents (Approve / Reject)',
                'Ban or suspend fraudulent member accounts',
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
