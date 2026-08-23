import { useAuth, toast } from './AuthContext';
import {
  api,
  displayRoleId,
  getPortalView,
  isStaffUser,
  portalReturnUrl,
} from '../api/client';
import { useLang } from '../i18n/LanguageContext';
import { roleTranslationKey, workspaceTranslationKey } from '../i18n/translations';

/** Sticky bar so Management users never feel like plain Members in the app */
export function StaffControlBar() {
  const { user, isAuthed } = useAuth();
  const { t } = useLang();
  if (!isAuthed || !isStaffUser(user)) return null;

  const portalView = getPortalView();
  const displayRole = displayRoleId(user);
  const district = portalView?.district || user?.admin_district || '';
  const asPortal = !!portalView || !!user?.portal_view_active;

  const openPortal = async () => {
    if (asPortal && (portalView || displayRole === 2 || displayRole === 3)) {
      window.location.href = portalReturnUrl(
        portalView || { role: displayRole as 2 | 3, district },
      );
      return;
    }
    try {
      const portal = await api.openStaffPortal();
      window.location.href = portal.redirect || '/gugu-app/admin/dashboard.php';
    } catch (e) {
      toast((e as Error).message, 'error');
      window.location.href = '/gugu-app/admin/dashboard.php';
    }
  };

  const roleName = t(roleTranslationKey(displayRole));
  const workspace = t(workspaceTranslationKey(displayRole));

  return (
    <div className="staff-control-bar">
      <span>
        <strong>{roleName}{district ? ` · ${district}` : ''}</strong>
        {' · '}
        {workspace}
      </span>
      <button type="button" onClick={() => { void openPortal(); }}>
        {asPortal
          ? (displayRole === 3 ? t('staff_back_moderator') : t('staff_back_district'))
          : t('staff_open_control')}
      </button>
    </div>
  );
}
