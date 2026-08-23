import { User, isStaffUser, getPortalView, displayRoleId } from '../api/client';
import { useLang } from '../i18n/LanguageContext';
import { roleTranslationKey } from '../i18n/translations';

/** Always-visible “who am I logged in as” chip — follows selected language */
export function IdentityBadge({ user }: { user?: User | null }) {
  const { t } = useLang();
  if (!user) return null;
  const view = getPortalView();
  const roleId = displayRoleId(user);
  const kind = user.account_kind || (isStaffUser(user) ? 'management' : 'member');
  const label = t(roleTranslationKey(roleId));
  const kindLabel = kind === 'management' || isStaffUser(user) || view
    ? t('identity_management')
    : t('identity_member');
  const district = view?.district || user.admin_district || '';

  return (
    <div className={`gugu-identity gugu-identity-${kind}`}>
      <span className="gugu-identity-kind">{kindLabel}</span>
      <span className="gugu-identity-role">
        {label}{district ? ` · ${district}` : ''}
      </span>
    </div>
  );
}
