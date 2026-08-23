import { useEffect } from 'react';
import { useAuth } from './AuthContext';
import { useStack } from '../stack/Stackflow';
import { isStaffUser, needsIdUpload } from '../api/client';

/**
 * Forces members who have not uploaded (or were rejected) to complete ID verification.
 */
export function MemberIdGate() {
  const { user, isAuthed } = useAuth();
  const { current, resetTo } = useStack();

  useEffect(() => {
    if (!isAuthed || !user || isStaffUser(user)) return;
    if (!needsIdUpload(user)) return;
    if (current.name === 'auth' || current.name === 'register') return;
    sessionStorage.setItem('gugu_auth_step', 'id');
    resetTo('auth');
  }, [isAuthed, user, current.name, resetTo]);

  return null;
}
