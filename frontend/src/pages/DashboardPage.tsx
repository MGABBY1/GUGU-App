/**
 * Single Dashboard entry — dynamic UI by staff / member role.
 */
import { useEffect } from 'react';
import { useAuth, toast } from '../components/AuthContext';
import { useStack } from '../stack/Stackflow';
import { Header } from '../components/BottomNav';
import { ROLE, isStaffUser, roleLabel } from '../api/client';
import { useLang } from '../i18n/LanguageContext';
import SuperAdminDashboard from '../dashboards/SuperAdminDashboard';
import RegionalManagerDashboard from '../dashboards/RegionalManagerDashboard';
import ModeratorSupportDashboard from '../dashboards/ModeratorSupportDashboard';
import UserDashboard from '../dashboards/UserDashboard';

export default function DashboardPage() {
  const { user, isAuthed, refreshUser } = useAuth();
  const { push } = useStack();
  const { t } = useLang();

  useEffect(() => {
    if (!isAuthed) {
      toast(t('login_first'), 'error');
      push('auth');
      return;
    }
    refreshUser();
  }, [isAuthed]);

  if (!user) {
    return (
      <>
        <Header title={t('dashboard')} back variant="admin" />
        <div className="stack-content dash-loading">{t('login_first')}</div>
      </>
    );
  }

  const roleId = user.role_id ?? ROLE.VERIFIED_USER;
  const title =
    roleId === ROLE.SUPER_ADMIN ? t('dash_system_title') :
    roleId === ROLE.REGIONAL_ADMIN ? t('dash_district_title') :
    roleId === ROLE.SUPPORT ? t('dash_moderator_title') :
    t('dash_member_title');

  return (
    <>
      <Header title={title} back variant="admin" />
      <div className="stack-content dash-page">
        {roleId === ROLE.SUPER_ADMIN && <SuperAdminDashboard user={user} />}
        {roleId === ROLE.REGIONAL_ADMIN && <RegionalManagerDashboard user={user} />}
        {roleId === ROLE.SUPPORT && <ModeratorSupportDashboard user={user} />}
        {(roleId === ROLE.VERIFIED_USER || roleId === ROLE.GUEST || !isStaffUser(user)) &&
          roleId !== ROLE.SUPER_ADMIN &&
          roleId !== ROLE.REGIONAL_ADMIN &&
          roleId !== ROLE.SUPPORT && (
            <UserDashboard user={user} />
          )}
      </div>
    </>
  );
}
