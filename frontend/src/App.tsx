import { StackProvider, StackNavigator, readSavedStackScreen, clearLoginQuery } from './stack/Stackflow';
import { AuthProvider, ToastProvider } from './components/AuthContext';
import { LanguageProvider } from './i18n/LanguageContext';
import HomePage from './pages/HomePage';
import AuthPage, { RegisterPage } from './pages/AuthPage';
import SellPage from './pages/SellPage';
import DetailPage, { ProfilePage, NeighborhoodPage, ChatPage, ChatRoomPage, FavoritesPage } from './pages/DetailPage';
import { ServicesHubPage, RecentlyViewedPage, BenefitsPage } from './pages/ServicePages';
import { JobsPage, PostJobPage } from './pages/JobsPage';
import DashboardPage from './pages/DashboardPage';
import { StaffControlBar } from './components/StaffControlBar';
import { ReactNode } from 'react';

const screens: Record<string, (params?: Record<string, unknown>) => ReactNode> = {
  items: () => <HomePage />,
  home: () => <HomePage />,
  auth: () => <AuthPage />,
  register: () => <RegisterPage />,
  sell: () => <SellPage />,
  detail: (p) => <DetailPage id={p?.id as number} />,
  profile: () => <ProfilePage />,
  dashboard: () => <DashboardPage />,
  neighborhood: () => <NeighborhoodPage />,
  chat: () => <ChatPage />,
  'chat-room': (p) => <ChatRoomPage roomId={p?.roomId as number} />,
  favorites: () => <FavoritesPage />,
  services: () => <ServicesHubPage />,
  recent: () => <RecentlyViewedPage />,
  benefits: () => <BenefitsPage />,
  jobs: () => <JobsPage />,
  'post-job': () => <PostJobPage />,
};

function resolveInitial(): { name: string; params?: Record<string, unknown> } {
  const token = (() => {
    try { return localStorage.getItem('gugu_token'); } catch { return null; }
  })();
  const saved = readSavedStackScreen();

  try {
    const q = new URLSearchParams(window.location.search);
    const wantsLogin = q.get('login') === '1' || q.has('auth');

    // Already logged in → never force login screen on refresh
    if (token) {
      clearLoginQuery();
      if (saved) return saved;
      return { name: 'items' };
    }

    // Guest explicitly opening login
    if (wantsLogin) return { name: 'auth' };
  } catch { /* ignore */ }

  if (saved) return saved;
  return { name: 'items' };
}

export default function App() {
  const start = resolveInitial();
  return (
    <LanguageProvider>
      <AuthProvider>
        <ToastProvider>
          <StackProvider initial={start.name} initialParams={start.params}>
            <div className="app-frame">
              <StaffControlBar />
              <StackNavigator screens={screens} />
            </div>
          </StackProvider>
        </ToastProvider>
      </AuthProvider>
    </LanguageProvider>
  );
}
