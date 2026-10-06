import { Navigate, Route, Routes } from 'react-router-dom';
import { isSelfEntryPage, useAuth } from './auth';
import Layout from './components/Layout';
import Login from './pages/Login';
import ChangePassword from './pages/ChangePassword';
import Dashboard from './pages/Dashboard';
import Register from './pages/Register';
import ParticipantDetail from './pages/ParticipantDetail';
import FormPage from './pages/FormPage';
import Users from './pages/Users';
import Roles from './pages/Roles';
import Audit from './pages/Audit';
import Export from './pages/Export';
import NoAccess from './pages/NoAccess';
import SelfEntry from './pages/SelfEntry';
import Alerts from './pages/Alerts';
import Content from './pages/Content';
import Instruments from './pages/Instruments';
import Scoring from './pages/Scoring';
import Randomisation from './pages/Randomisation';

function Guard({ screen, level = 'read', children }) {
  const { can } = useAuth();
  return can(screen, level) ? children : <NoAccess />;
}

export default function App() {
  const { me, loading } = useAuth();

  // Tablet self-entry needs no staff login: the session token is the credential.
  if (isSelfEntryPage()) {
    return <Routes><Route path="/entry/:token" element={<SelfEntry />} /></Routes>;
  }

  if (loading) return <div className="boot">Loading…</div>;

  if (!me) {
    return (
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route path="*" element={<Navigate to="/login" replace />} />
      </Routes>
    );
  }

  if (me.user.must_change_password) {
    return (
      <Routes>
        <Route path="*" element={<ChangePassword forced />} />
      </Routes>
    );
  }

  return (
    <Layout>
      <Routes>
        <Route path="/" element={<Guard screen="dashboard"><Dashboard /></Guard>} />
        <Route path="/participants/new" element={<Guard screen="participants" level="write"><Register /></Guard>} />
        <Route path="/participants/:id" element={<Guard screen="participants"><ParticipantDetail /></Guard>} />
        <Route path="/participants/:id/forms/:code" element={<Guard screen="participants"><FormPage /></Guard>} />
        <Route path="/users" element={<Guard screen="users"><Users /></Guard>} />
        <Route path="/roles" element={<Guard screen="roles"><Roles /></Guard>} />
        <Route path="/audit" element={<Guard screen="audit"><Audit /></Guard>} />
        <Route path="/export" element={<Export />} />
        <Route path="/alerts" element={<Guard screen="alerts"><Alerts /></Guard>} />
        <Route path="/instruments" element={<Guard screen="instruments"><Instruments /></Guard>} />
        <Route path="/content" element={<Guard screen="content"><Content /></Guard>} />
        <Route path="/scoring" element={<Guard screen="scoring"><Scoring /></Guard>} />
        <Route path="/randomisation" element={<Guard screen="randomisation"><Randomisation /></Guard>} />
        <Route path="/change-password" element={<ChangePassword />} />
        <Route path="/login" element={<Navigate to="/" replace />} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </Layout>
  );
}
