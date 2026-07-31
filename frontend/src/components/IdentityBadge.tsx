import { User, roleLabel, isStaffUser } from '../api/client';
import { BRAND_NAME } from '../i18n/translations';

/** Always-visible “who am I logged in as” chip */
export function IdentityBadge({ user }: { user?: User | null }) {
  if (!user) return null;
  const kind = user.account_kind || (isStaffUser(user) ? 'management' : 'member');
  const label = user.role_label || roleLabel(user.role_id);
  const kindLabel = kind === 'management' ? `${BRAND_NAME} management` : 'Member';

  return (
    <div className={`gugu-identity gugu-identity-${kind}`}>
      <span className="gugu-identity-kind">{kindLabel}</span>
      <span className="gugu-identity-role">{label}</span>
    </div>
  );
}
