import { useAuth, toast } from './AuthContext';
import { api, isStaffUser, roleLabel } from '../api/client';

/** Sticky bar so Management users never feel like plain Members in the app */
export function StaffControlBar() {
  const { user, isAuthed } = useAuth();
  if (!isAuthed || !isStaffUser(user)) return null;

  const openPortal = async () => {
    try {
      const portal = await api.openStaffPortal();
      window.location.href = portal.redirect || '/gugu-app/admin/dashboard.php';
    } catch (e) {
      toast((e as Error).message, 'error');
      window.location.href = '/gugu-app/admin/dashboard.php';
    }
  };

  return (
    <div className="staff-control-bar">
      <span>
        <strong>{roleLabel(user?.role_id)}</strong>
        {' · '}
        System control active
      </span>
      <button type="button" onClick={() => { void openPortal(); }}>
        Open Control Center
      </button>
    </div>
  );
}
